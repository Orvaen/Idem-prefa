<?php

/**
 * GET    /api/demandes              la liste, limitée à ce que la personne a le droit de voir
 * GET    /api/demandes/26-DP-0042   une demande
 * PATCH  /api/demandes/26-DP-0042   la valider ou la refuser   { "statut": 2 | 3, "motif": "…" }
 * DELETE /api/demandes/26-DP-0042   la supprimer, avec ses pièces jointes
 *
 * Les filtres de la liste, tous facultatifs et combinables :
 *
 *   statut=2        ou plusieurs, séparés par des virgules : statut=1,2
 *   urgent=1        les urgentes ; urgent=0 les normales ; absent, les deux
 *   demandeur=7     ou plusieurs : demandeur=7,9
 *   q=pont          cherché dans la référence, le chantier, le statut, la passivation
 *   page=2&par_page=50
 *
 * Une demande s'adresse par sa référence et non par son identifiant : c'est elle que
 * l'atelier lit et recopie, et c'est déjà elle qui circule dans les adresses du tableau de
 * bord — actions/download_*.php?ref=26-DP-0042. L'identifiant technique ne sert qu'aux
 * liens entre tables et ne sort jamais de la base.
 *
 * Déposer une demande n'en fait pas partie : le formulaire valide quatre cents lignes de
 * champs et reçoit des fichiers, ce qui ne se déplace pas à la légère. Tout le reste du
 * cycle est ici, et les écrans décident par les mêmes méthodes du modèle.
 *
 * Ce fichier ne contient aucune requête SQL. Il lit l'adresse, refuse ce qui ne va pas,
 * appelle le modèle, et met le résultat en JSON.
 */
final class Demandes
{
    public static function repondre(Requete $requete, PDO $db, Utilisateur $utilisateur): Reponse
    {
        // La liste n'est qu'en lecture : on ne décide pas de vingt demandes d'un coup.
        if ($requete->segment(1) === null) {
            return $requete->methode === 'GET'
                ? self::liste($requete, $db, $utilisateur)
                : Reponse::methodeRefusee(['GET']);
        }

        return match ($requete->methode) {
            'GET' => self::une($requete, $db, $utilisateur),
            'PATCH' => self::trancher($requete, $db, $utilisateur),
            'DELETE' => self::effacer($requete, $db, $utilisateur),
            default => Reponse::methodeRefusee(['GET', 'PATCH', 'DELETE']),
        };
    }

    /**
     * Valider ou refuser.
     *
     * PATCH et non POST sur une adresse en « /valider » : l'adresse nomme la demande, et
     * ce qu'on envoie est l'état dans lequel on veut la mettre. Il n'y a pas de verbe dans
     * une adresse, et l'API n'a donc pas une entrée par décision possible.
     */
    private static function trancher(Requete $requete, PDO $db, Utilisateur $utilisateur): Reponse
    {
        $demande = self::demandeDeLAdresse($requete, $db, $utilisateur);
        if ($demande instanceof Reponse) return $demande;

        // Un nombre en JSON, une chaîne quand c'est un formulaire qui poste : les deux
        // disent la même chose.
        $recu = $requete->champ('statut');
        $statut = is_int($recu) || (is_string($recu) && ctype_digit($recu)) ? (int) $recu : null;
        if (!in_array($statut, [Demande::VALIDEE, Demande::REFUSEE], true)) {
            return Reponse::demandeInvalide('« statut » vaut ' . Demande::VALIDEE . ' pour valider, '
                . Demande::REFUSEE . ' pour refuser.');
        }
        $motif = trim((string) ($requete->champ('motif') ?? ''));
        if (mb_strlen($motif) > 1000) return Reponse::demandeInvalide('Le motif tient en 1 000 caractères.');

        try {
            $tranchee = $statut === Demande::VALIDEE
                ? $demande->valider($db, $utilisateur, $motif)
                : $demande->refuser($db, $utilisateur, $motif);
        } catch (DomainException $refus) {
            // Le motif manquant est une demande mal formée ; le reste est un droit absent.
            return str_contains($refus->getMessage(), 'motif')
                ? Reponse::demandeInvalide($refus->getMessage())
                : Reponse::interdit($refus->getMessage());
        } catch (InvalidArgumentException $horsLimite) {
            // planningPeriod ne sait pas poser une durée absurde. Ce n'est pas une panne :
            // c'est un chiffre à corriger sur la demande, ou une cadence à revoir.
            return Reponse::erreur(409, 'duree_hors_limite',
                $horsLimite->getMessage() . ' La cadence se règle dans Paramètres › Jours ISO.');
        }

        if (!$tranchee) {
            return Reponse::erreur(409, 'deja_tranchee',
                'Cette demande a déjà été traitée, ou n’existe plus. Rechargez la liste.');
        }

        // On relit : la décision a posé un statut, une date et des dates de planning.
        $apres = Demande::parReference($db, $utilisateur, $demande->reference);
        return Reponse::succes($apres ? self::enJson($apres) : null);
    }

    private static function effacer(Requete $requete, PDO $db, Utilisateur $utilisateur): Reponse
    {
        $demande = self::demandeDeLAdresse($requete, $db, $utilisateur);
        if ($demande instanceof Reponse) return $demande;

        try {
            $effacee = $demande->supprimer($db, $utilisateur);
        } catch (DomainException $refus) {
            return Reponse::interdit($refus->getMessage());
        }

        if (!$effacee) {
            return Reponse::erreur(409, 'suppression_impossible',
                'Seule une demande en attente se supprime. Celle-ci est déjà partie à l’atelier.');
        }

        return Reponse::succes(null);
    }

    /**
     * La demande que l'adresse nomme, ou la réponse à renvoyer quand il n'y en a pas.
     *
     * Une demande qu'on n'a pas le droit de voir est introuvable, et non interdite :
     * répondre 403 confirmerait son existence à qui n'a rien à en savoir.
     */
    private static function demandeDeLAdresse(Requete $requete, PDO $db, Utilisateur $utilisateur): Demande|Reponse
    {
        $demande = Demande::parReference($db, $utilisateur, (string) $requete->segment(1));

        return $demande ?? Reponse::introuvable('Demande introuvable.');
    }

    private static function une(Requete $requete, PDO $db, Utilisateur $utilisateur): Reponse
    {
        $demande = self::demandeDeLAdresse($requete, $db, $utilisateur);

        return $demande instanceof Reponse ? $demande : Reponse::succes(self::enJson($demande));
    }

    private static function liste(Requete $requete, PDO $db, Utilisateur $utilisateur): Reponse
    {
        $filtre = self::filtreLu($requete);
        if ($filtre instanceof Reponse) return $filtre; // un filtre illisible : 400

        return Reponse::succes([
            'demandes' => array_map(self::enJson(...), Demande::visiblesPar($db, $utilisateur, $filtre)),
            'page' => [
                'numero' => $filtre->page,
                'par_page' => $filtre->parPage,
                'total' => Demande::combien($db, $utilisateur, $filtre),
            ],
        ]);
    }

    /**
     * Les filtres de la chaîne de requête, vérifiés.
     *
     * Rend le filtre, ou la réponse 400 qui dit lequel ne va pas : c'est ici qu'on se
     * méfie de ce qui vient du dehors, pour que le modèle ne reçoive que des entiers.
     */
    private static function filtreLu(Requete $requete): FiltreDemandes|Reponse
    {
        $statuts = self::nombres($requete->parametre('statut'));
        if ($statuts === null) return Reponse::demandeInvalide('« statut » attend un nombre, ou plusieurs séparés par des virgules.');

        $demandeurs = self::nombres($requete->parametre('demandeur'));
        if ($demandeurs === null) return Reponse::demandeInvalide('« demandeur » attend un nombre, ou plusieurs séparés par des virgules.');

        $urgent = $requete->parametre('urgent');
        if (!in_array($urgent, ['', '0', '1'], true)) return Reponse::demandeInvalide('« urgent » vaut 0 ou 1.');

        $page = $requete->parametre('page', '1');
        $parPage = $requete->parametre('par_page', '50');
        if (!ctype_digit($page) || !ctype_digit($parPage)) return Reponse::demandeInvalide('« page » et « par_page » attendent des nombres.');

        return new FiltreDemandes(
            statuts: $statuts,
            urgentes: $urgent === '' ? null : $urgent === '1',
            demandeurs: $demandeurs,
            recherche: trim($requete->parametre('q')),
            page: (int) $page,
            parPage: (int) $parPage,
        );
    }

    /**
     * « 1,2 » devient [1, 2]. Vide donne une liste vide — le filtre ne s'applique pas.
     *
     * Null quand ce n'est pas une liste de nombres : à l'appelant de répondre 400 en
     * nommant le paramètre fautif, ce que cette fonction ne connaît pas.
     *
     * @return list<int>|null
     */
    private static function nombres(string $valeur): ?array
    {
        if (trim($valeur) === '') return [];

        $morceaux = array_map('trim', explode(',', $valeur));
        foreach ($morceaux as $morceau) {
            if (!ctype_digit($morceau)) return null;
        }

        return array_values(array_unique(array_map('intval', $morceaux)));
    }

    /**
     * L'objet du modèle devient du JSON ici, et nulle part ailleurs.
     *
     * L'identifiant technique n'y figure pas : la référence est la clé publique, celle
     * qu'on remet dans l'adresse pour redemander la demande.
     *
     * @return array<string, mixed>
     */
    private static function enJson(Demande $demande): array
    {
        return [
            'reference' => $demande->reference,
            'affaire' => ['id' => $demande->idAffaire, 'nom' => $demande->affaire],
            'statut' => ['id' => $demande->idStatut, 'libelle' => $demande->statut],
            'urgente' => $demande->urgente,
            'demandeur' => $demande->demandeur,
            'pris_en_charge_par' => $demande->prisEnChargePar,
            'passivation' => $demande->passivation,
            'matiere' => $demande->matiere,
            'pouces_total_iso' => $demande->poucesTotalIso,
            'heures_chiffrees' => $demande->heuresChiffrees,
            'date_creation' => $demande->dateCreation,
            'date_livraison_prevue' => $demande->dateLivraisonPrevue,
        ];
    }
}
