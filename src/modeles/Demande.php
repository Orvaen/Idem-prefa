<?php

// Trois fonctions pures, sans état ni session, que la validation emploie : la place dans
// le planning, la cadence d'heures par pouce, et les pièces jointes qu'une suppression
// emporte. Elles vivent encore sous dashboard/ parce que les écrans les appellent aussi ;
// elles descendront ici quand ce sera leur tour.
require_once __DIR__ . '/../dashboard/includes/atelier/planning.php';
require_once __DIR__ . '/../dashboard/includes/demande/prefa_hours.php';
require_once __DIR__ . '/../dashboard/includes/demande/prefa_attachments.php';

/**
 * Une demande de préfabrication, telle qu'on la lit.
 *
 * Soixante-dix-huit endroits du code interrogeaient demande_prefabrication, chacun avec sa
 * propre liste de jointures et sa propre idée de qui a le droit de voir quoi. Ce qu'une
 * demande est se décide ici, une fois : les écrans, les actions et l'API le demandent au
 * lieu de l'écrire.
 *
 * Le modèle ne sait pas ce qu'est du JSON, ni une session, ni une requête HTTP. Il prend
 * une connexion et une personne, et rend des objets. Un script peut l'appeler.
 *
 * Il ne porte pour l'instant que la lecture. Déposer une demande passe encore par
 * actions/create_prefa.php, dont la validation fait quatre cents lignes et ne se déplace
 * pas à la légère.
 */
final class Demande
{
    private function __construct(
        /** L'identifiant technique. Il relie les tables entre elles et ne sort pas d'ici. */
        public readonly int $id,
        /** « 26-DP-0042 » : c'est elle qui circule dans les adresses, et que l'atelier recopie. */
        public readonly string $reference,
        public readonly ?int $idAffaire,
        public readonly string $affaire,
        public readonly int $idStatut,
        public readonly string $statut,
        public readonly bool $urgente,
        /** Qui l'a déposée, null quand le compte a été supprimé depuis. */
        public readonly ?string $demandeur,
        /** Qui l'a prise en charge à l'atelier, null tant que personne ne l'a fait. */
        public readonly ?string $prisEnChargePar,
        public readonly ?string $passivation,
        public readonly ?string $matiere,
        public readonly int $poucesTotalIso,
        public readonly float $heuresChiffrees,
        public readonly ?string $dateCreation,
        public readonly ?string $dateLivraisonPrevue,
    ) {}

    // ------------------------------------------------------------------ les lectures

    /** Une demande, si cette personne a le droit de la voir. Null sinon — on ne dit pas pourquoi. */
    public static function visible(PDO $db, Utilisateur $utilisateur, int $id): ?self
    {
        return self::premiere($db, $utilisateur, 'd.id = :cle', ['cle' => $id]);
    }

    /** La même, cherchée par la référence que porte l'adresse : « 26-DP-0042 ». */
    public static function parReference(PDO $db, Utilisateur $utilisateur, string $reference): ?self
    {
        $reference = trim($reference);
        if ($reference === '') return null;
        return self::premiere($db, $utilisateur, 'ref.reference = :cle', ['cle' => $reference]);
    }

    /**
     * Les demandes qu'elle a le droit de voir, filtrées, dans l'ordre de l'écran : les
     * urgentes d'abord, puis la plus récente.
     *
     * @return list<self>
     */
    public static function visiblesPar(PDO $db, Utilisateur $utilisateur, FiltreDemandes $filtre): array
    {
        ['where' => $where, 'params' => $params] = self::conditions($utilisateur, $filtre);

        // LIMIT et OFFSET ne se lient pas comme des valeurs sur toutes les versions de
        // MySQL. Ils sont donc écrits dans la requête — mais à partir de deux entiers que
        // FiltreDemandes a déjà bornés, jamais d'une chaîne reçue telle quelle.
        $lecture = $db->prepare(self::LECTURE . $where
            . ' ORDER BY d.urgent DESC, d.date_creation DESC, d.id DESC'
            . ' LIMIT ' . $filtre->parPage . ' OFFSET ' . $filtre->decalage());
        self::lier($lecture, $params);
        $lecture->execute();

        return array_map(self::depuisLaBase(...), $lecture->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Combien il y en a en tout, filtres compris : l'écran doit savoir s'il reste des pages. */
    public static function combien(PDO $db, Utilisateur $utilisateur, FiltreDemandes $filtre): int
    {
        ['where' => $where, 'params' => $params] = self::conditions($utilisateur, $filtre);

        $compte = $db->prepare('SELECT COUNT(*)' . self::JOINTURES . $where);
        self::lier($compte, $params);
        $compte->execute();

        return (int) $compte->fetchColumn();
    }

    // ---------------------------------------------------------------- les décisions

    /** Les statuts, tels que la table statut_demande les numérote. */
    public const EN_ATTENTE = 1;
    public const VALIDEE = 2;
    public const REFUSEE = 3;

    /**
     * Valide la demande, et lui pose sa place dans le planning.
     *
     * La durée est celle chiffrée sur la demande ; à défaut, elle se recalcule à la
     * cadence du moment, et à défaut encore on prend une journée — une demande sans durée
     * ne doit pas bloquer la décision, elle se replanifie ensuite.
     *
     * @throws DomainException        cette personne n'a pas à décider
     * @throws InvalidArgumentException la durée ne tient pas dans le planning
     * @return bool false si la demande n'était plus en attente : déjà tranchée, ou effacée
     */
    public function valider(PDO $db, Utilisateur $utilisateur, string $commentaire = ''): bool
    {
        $this->seulLeDecideur($utilisateur);

        $periode = planningPeriod(new DateTimeImmutable('today', new DateTimeZone('Europe/Paris')),
            $this->heuresRetenues($db), true);

        $maj = $db->prepare('UPDATE demande_prefabrication
            SET id_statut = ' . self::VALIDEE . ', commentaire_validation = ?, valide_par = ?,
                date_validation = NOW(), date_debut_planifiee = ?, date_fin_planifiee = ?,
                planning_jours_ouvres = 1, DateUpdate = NOW()
            WHERE id = ? AND id_statut = ' . self::EN_ATTENTE);
        $maj->execute([$commentaire ?: null, $utilisateur->id, $periode['start'], $periode['end'], $this->id]);

        return $this->tranchee($db, $maj, $utilisateur, Evenement::DEMANDE_VALIDEE, $commentaire);
    }

    /**
     * Refuse la demande. Le motif est obligatoire : le demandeur doit savoir quoi corriger.
     *
     * @throws DomainException cette personne n'a pas à décider, ou le motif manque
     * @return bool false si la demande n'était plus en attente
     */
    public function refuser(PDO $db, Utilisateur $utilisateur, string $motif): bool
    {
        $this->seulLeDecideur($utilisateur);
        if (trim($motif) === '') throw new DomainException('Indiquez un motif de refus.');

        $maj = $db->prepare('UPDATE demande_prefabrication
            SET id_statut = ' . self::REFUSEE . ', commentaire_validation = ?, valide_par = ?,
                date_validation = NOW(), DateUpdate = NOW()
            WHERE id = ? AND id_statut = ' . self::EN_ATTENTE);
        $maj->execute([$motif, $utilisateur->id, $this->id]);

        return $this->tranchee($db, $maj, $utilisateur, Evenement::DEMANDE_REFUSEE, $motif);
    }

    /**
     * Supprime la demande, et les fichiers déposés avec elle.
     *
     * Le chargé de production efface n'importe laquelle ; le chargé d'affaire seulement
     * les siennes, et seulement tant qu'elles attendent — une demande validée est déjà
     * partie à l'atelier, qui a commencé à travailler dessus.
     *
     * @throws DomainException cette personne n'a pas à supprimer celle-ci
     * @return bool false si elle n'était déjà plus là, ou plus en attente
     */
    public function supprimer(PDO $db, Utilisateur $utilisateur): bool
    {
        if (!$utilisateur->deposeDesDemandes()) {
            throw new DomainException('La suppression est réservée aux demandeurs.');
        }

        $sienne = $db->prepare('SELECT idUsers FROM demande_prefabrication WHERE id = ?');
        $sienne->execute([$this->id]);
        $proprietaire = (int) $sienne->fetchColumn();

        $tout = $utilisateur->est(Role::ChargeDeProduction);
        if (!$tout) {
            if ($proprietaire !== $utilisateur->id) {
                throw new DomainException('Vous ne pouvez pas supprimer cette demande.');
            }
            if ($this->idStatut !== self::EN_ATTENTE) {
                return false;
            }
        }

        // Une seule instruction, donc pas de transaction à ouvrir : si elle n'efface rien,
        // rien n'a changé, et il n'y a rien à annuler. Les tables liées partent avec, par
        // leurs clés étrangères.
        $effacer = $db->prepare('DELETE FROM demande_prefabrication WHERE id = ?'
            . ($tout ? '' : ' AND idUsers = ? AND id_statut = ' . self::EN_ATTENTE));
        $effacer->execute($tout ? [$this->id] : [$this->id, $utilisateur->id]);
        if ($effacer->rowCount() !== 1) return false;

        // Les fichiers en dernier : effacés avant, une suppression qui échoue laisserait
        // une demande sans ses pièces.
        $dossier = prefaAttachmentDirectory($this->id);
        foreach (prefaAttachments($this->id) as $piece) {
            $chemin = $dossier . '/' . $piece['key'];
            if (is_file($chemin)) unlink($chemin);
        }
        if (is_dir($dossier)) rmdir($dossier);

        return true;
    }

    /**
     * La durée sur laquelle on planifie.
     *
     * Celle chiffrée sur la demande ; à défaut, recalculée à la cadence du moment ; à
     * défaut encore, une journée — une demande sans durée ne doit pas bloquer la décision,
     * elle se replanifie ensuite. L'écran la redemande pour expliquer un refus de planning.
     */
    public function heuresRetenues(PDO $db): float
    {
        if ($this->heuresChiffrees > 0) return $this->heuresChiffrees;

        if ($this->poucesTotalIso > 0) {
            $calculees = (float) prefaCalculatedHours($this->poucesTotalIso, prefaHoursPerInch($db));
            if ($calculees > 0) return $calculees;
        }

        $cadence = (float) prefaHoursPerInch($db);
        return $cadence > 0 ? $cadence : 24.0;
    }

    private function seulLeDecideur(Utilisateur $utilisateur): void
    {
        if (!$utilisateur->valideLesDemandes()) {
            throw new DomainException('Seul un chargé de production décide d\'une demande.');
        }
    }

    /** Ce qui suit une décision : l'annoncer aux écrans, ou dire que rien n'a bougé. */
    private function tranchee(PDO $db, PDOStatement $maj, Utilisateur $utilisateur, string $type, string $motif): bool
    {
        if ($maj->rowCount() !== 1) return false;

        Evenement::noter($db, $type, $this->id, $utilisateur->id,
            ['affaire' => $this->affaire, 'motif' => $motif]);

        return true;
    }

    // ------------------------------------------------------------------- les droits

    /**
     * Le fragment SQL qui limite une requête à ce que cette personne a le droit de voir.
     *
     * La règle vivait dans le partial qui dessine la liste, et l'API devait la reprendre :
     * deux copies d'une règle d'accès finissent toujours par diverger — l'une se corrige,
     * l'autre laisse passer. Elle est écrite ici, et tout le monde l'appelle.
     *
     * Le fragment se colle derrière les jointures de demande_prefabrication, aliasée d, et
     * ses marqueurs sont préfixés « vis_ » pour ne jamais heurter ceux des filtres.
     *
     * @return array{condition: string, params: array<string, mixed>}
     */
    public static function clauseVisible(Utilisateur $utilisateur): array
    {
        $params = [];

        if ($utilisateur->voitToutesLesDemandes()) {
            $condition = '1=1';
        } else {
            // Chargé d'affaire et personnel d'atelier : seulement les demandes à leur nom.
            $condition = 'd.idUsers = :vis_demandeur';
            $params['vis_demandeur'] = $utilisateur->id;
        }

        // L'atelier ne travaille que sur du validé : une demande encore en attente peut
        // changer, et ce qu'on aurait commencé dessus serait à refaire.
        if ($utilisateur->est(Role::PersonnelAtelier)) {
            $condition .= ' AND d.id_statut = 2';
        }

        // Le chef d'atelier voit tout ce qui est validé, plus ce qu'il a lui-même déposé —
        // sans quoi sa propre demande disparaîtrait de sa vue tant qu'elle attend validation.
        if ($utilisateur->est(Role::ChefDAtelier)) {
            $condition .= ' AND (d.id_statut = 2 OR d.idUsers = :vis_chef)';
            $params['vis_chef'] = $utilisateur->id;
        }

        return ['condition' => $condition, 'params' => $params];
    }

    // ------------------------------------------------------------------ la mécanique

    /** Les jointures, écrites une fois : la lecture et le compte doivent voir les mêmes lignes. */
    private const JOINTURES = ' FROM demande_prefabrication d
        JOIN statut_demande s ON s.id = d.id_statut
        LEFT JOIN prefa_reference ref ON ref.id_demande = d.id
        LEFT JOIN Utilisateur u ON u.id = d.idUsers
        LEFT JOIN Utilisateur t ON t.id = d.pris_en_charge_par
        LEFT JOIN type_passivation p ON p.id = d.id_passivation
        LEFT JOIN type_matiere m ON m.id = d.id_matiere';

    /** Le nom du demandeur, écrit pareil dans la lecture et dans la recherche. */
    private const DEMANDEUR = "TRIM(CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.name, '')))";

    private const LECTURE = "SELECT d.id, ref.reference, d.id_affaire, d.nom_affaire,
            d.id_statut, s.libelle AS statut, d.urgent,
            " . self::DEMANDEUR . " AS demandeur,
            TRIM(CONCAT(COALESCE(t.prenom, ''), ' ', COALESCE(t.name, ''))) AS pris_en_charge_par,
            p.libelle AS passivation, m.libelle AS matiere,
            d.pouces_total_iso, d.heures_chiffrees, d.date_creation, d.date_livraison_prevue"
        . self::JOINTURES;

    /** @param array<string, mixed> $params */
    private static function premiere(PDO $db, Utilisateur $utilisateur, string $condition, array $params): ?self
    {
        ['condition' => $visible, 'params' => $visibles] = self::clauseVisible($utilisateur);

        $lecture = $db->prepare(self::LECTURE . ' WHERE ' . $visible . ' AND ' . $condition . ' LIMIT 1');
        self::lier($lecture, $visibles + $params);
        $lecture->execute();

        $ligne = $lecture->fetch(PDO::FETCH_ASSOC);
        return $ligne ? self::depuisLaBase($ligne) : null;
    }

    /**
     * Le WHERE complet : les droits d'abord, les filtres ensuite.
     *
     * Les marqueurs des filtres sont numérotés — :statut_0, :statut_1 — parce qu'un même
     * marqueur répété ne se lie plus le jour où l'émulation des requêtes préparées est
     * coupée, et que c'est une bonne chose à pouvoir faire sans rien réécrire ici.
     *
     * @return array{where: string, params: array<string, mixed>}
     */
    private static function conditions(Utilisateur $utilisateur, FiltreDemandes $filtre): array
    {
        ['condition' => $where, 'params' => $params] = self::clauseVisible($utilisateur);

        $listes = ['statut' => ['d.id_statut', $filtre->statuts],
                   'demandeur' => ['d.idUsers', $filtre->demandeurs]];
        foreach ($listes as $nom => [$colonne, $valeurs]) {
            if (!$valeurs) continue;
            $marqueurs = [];
            foreach (array_values($valeurs) as $rang => $valeur) {
                $marqueurs[] = ':' . $nom . '_' . $rang;
                $params[$nom . '_' . $rang] = $valeur;
            }
            $where .= ' AND ' . $colonne . ' IN (' . implode(', ', $marqueurs) . ')';
        }

        if ($filtre->urgentes !== null) {
            $where .= ' AND d.urgent = :urgente';
            $params['urgente'] = $filtre->urgentes ? 1 : 0;
        }

        if ($filtre->recherche !== '') {
            $colonnes = ['ref.reference', 'd.nom_affaire', 's.libelle', 'p.libelle', self::DEMANDEUR];
            $morceaux = [];
            foreach ($colonnes as $rang => $colonne) {
                $morceaux[] = $colonne . ' LIKE :q' . $rang;
                $params['q' . $rang] = '%' . $filtre->recherche . '%';
            }
            $where .= ' AND (' . implode(' OR ', $morceaux) . ')';
        }

        return ['where' => ' WHERE ' . $where, 'params' => $params];
    }

    /** @param array<string, mixed> $params */
    private static function lier(PDOStatement $requete, array $params): void
    {
        foreach ($params as $nom => $valeur) $requete->bindValue(':' . $nom, $valeur);
    }

    /** @param array<string, mixed> $ligne */
    private static function depuisLaBase(array $ligne): self
    {
        // Une demande sans référence, c'est une base sur laquelle la migration n'est pas
        // passée. On le dit, plutôt que de rendre une demande sans adresse.
        if ($ligne['reference'] === null) {
            throw new RuntimeException('Référence de demande manquante. Appliquez la migration des références.');
        }

        return new self(
            id: (int) $ligne['id'],
            reference: (string) $ligne['reference'],
            idAffaire: $ligne['id_affaire'] !== null ? (int) $ligne['id_affaire'] : null,
            affaire: (string) ($ligne['nom_affaire'] ?? ''),
            idStatut: (int) $ligne['id_statut'],
            statut: (string) $ligne['statut'],
            urgente: (bool) $ligne['urgent'],
            demandeur: trim((string) $ligne['demandeur']) ?: null,
            prisEnChargePar: trim((string) $ligne['pris_en_charge_par']) ?: null,
            passivation: $ligne['passivation'],
            matiere: $ligne['matiere'],
            poucesTotalIso: (int) $ligne['pouces_total_iso'],
            heuresChiffrees: (float) $ligne['heures_chiffrees'],
            dateCreation: $ligne['date_creation'],
            dateLivraisonPrevue: $ligne['date_livraison_prevue'],
        );
    }
}
