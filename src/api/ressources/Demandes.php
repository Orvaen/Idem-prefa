<?php

require_once __DIR__ . '/../../dashboard/includes/demande/prefa_visibilite.php';
require_once __DIR__ . '/../../dashboard/includes/demande/prefa_references.php';

/**
 * GET /api/demandes        la liste, filtrée par ce que la personne a le droit de voir
 * GET /api/demandes/{id}   une demande
 *
 * Lecture seule pour l'instant : déposer une demande passe encore par le formulaire, dont
 * la validation fait quatre cents lignes et ne se déplace pas à la légère.
 */
final class Demandes
{
    /** Au-delà, une liste ne se lit plus : elle se pagine. */
    private const PAR_PAGE_MAX = 200;
    private const PAR_PAGE_DEFAUT = 50;

    public static function repondre(Requete $requete, PDO $db, Utilisateur $utilisateur): Reponse
    {
        if ($requete->methode !== 'GET') return Reponse::methodeRefusee(['GET']);

        $id = $requete->segment(1);
        if ($id === null) return self::liste($requete, $db, $utilisateur);
        return self::une($requete->identifiant(1), $db, $utilisateur);
    }

    private static function une(?int $id, PDO $db, Utilisateur $utilisateur): Reponse
    {
        if ($id === null) return Reponse::demandeInvalide('Identifiant de demande invalide.');

        $visible = prefaDemandesVisibles($utilisateur);
        $lecture = $db->prepare(self::SELECTION . $visible['where'] . ' AND d.id = :id LIMIT 1');
        foreach ($visible['params'] + ['id' => $id] as $nom => $valeur) {
            $lecture->bindValue(':' . $nom, $valeur);
        }
        $lecture->execute();
        $ligne = $lecture->fetch(PDO::FETCH_ASSOC);

        // Une demande qu'on n'a pas le droit de voir est introuvable, et non interdite :
        // répondre 403 confirmerait son existence à qui n'a rien à en savoir.
        if (!$ligne) return Reponse::introuvable('Demande introuvable.');

        return Reponse::succes(self::enJson($ligne));
    }

    private static function liste(Requete $requete, PDO $db, Utilisateur $utilisateur): Reponse
    {
        $visible = prefaDemandesVisibles($utilisateur);
        $where = $visible['where'];
        $params = $visible['params'];

        // Les filtres de l'écran, repris tels quels : un statut, une urgence, une recherche.
        $statut = $requete->parametre('statut');
        if ($statut !== '') {
            if (!ctype_digit($statut)) return Reponse::demandeInvalide('Le statut doit être un nombre.');
            $where .= ' AND d.id_statut = :statut';
            $params['statut'] = (int) $statut;
        }

        $urgence = $requete->parametre('urgent');
        if ($urgence !== '') {
            if (!in_array($urgence, ['0', '1'], true)) return Reponse::demandeInvalide('« urgent » vaut 0 ou 1.');
            $where .= ' AND d.urgent = :urgent';
            $params['urgent'] = (int) $urgence;
        }

        $recherche = trim($requete->parametre('q'));
        if ($recherche !== '') {
            $where .= ' AND (d.nom_affaire LIKE :q OR CAST(d.id AS CHAR) LIKE :q)';
            $params['q'] = '%' . $recherche . '%';
        }

        $parPage = $requete->parametre('par_page');
        $parPage = ctype_digit($parPage) ? min((int) $parPage, self::PAR_PAGE_MAX) : self::PAR_PAGE_DEFAUT;
        $parPage = max(1, $parPage);
        $page = $requete->parametre('page');
        $page = ctype_digit($page) ? max(1, (int) $page) : 1;

        $total = $db->prepare('SELECT COUNT(*) FROM demande_prefabrication d' . $where);
        foreach ($params as $nom => $valeur) $total->bindValue(':' . $nom, $valeur);
        $total->execute();
        $combien = (int) $total->fetchColumn();

        // LIMIT et OFFSET ne se lient pas comme des valeurs sur toutes les versions : ils
        // sont donc fabriqués à partir d'entiers, jamais d'une chaîne reçue telle quelle.
        $lecture = $db->prepare(self::SELECTION . $where
            . ' ORDER BY d.urgent DESC, d.id DESC LIMIT ' . $parPage . ' OFFSET ' . (($page - 1) * $parPage));
        foreach ($params as $nom => $valeur) $lecture->bindValue(':' . $nom, $valeur);
        $lecture->execute();

        return Reponse::succes([
            'demandes' => array_map(self::enJson(...), $lecture->fetchAll(PDO::FETCH_ASSOC)),
            'page' => ['numero' => $page, 'par_page' => $parPage, 'total' => $combien],
        ]);
    }

    /** Le WHERE se colle derrière : la clause de visibilité commence toujours par WHERE. */
    private const SELECTION = 'SELECT d.id, d.nom_affaire, d.id_affaire, d.urgent, d.id_statut,
            d.date_creation, d.date_livraison_prevue, d.pouces_total_iso, d.heures_chiffrees,
            s.libelle AS statut_libelle,
            TRIM(CONCAT(u.prenom, \' \', u.name)) AS demandeur,
            TRIM(CONCAT(taker.prenom, \' \', taker.name)) AS pris_en_charge_par,
            p.libelle AS passivation, m.libelle AS matiere
        FROM demande_prefabrication d
        JOIN statut_demande s ON s.id = d.id_statut
        LEFT JOIN Utilisateur u ON u.id = d.idUsers
        LEFT JOIN Utilisateur taker ON taker.id = d.pris_en_charge_par
        LEFT JOIN type_passivation p ON p.id = d.id_passivation
        LEFT JOIN type_matiere m ON m.id = d.id_matiere';

    /** Une ligne de base devient un objet JSON : des types justes, pas des chaînes partout. */
    private static function enJson(array $ligne): array
    {
        return [
            'id' => (int) $ligne['id'],
            'reference' => prefaReference((int) $ligne['id']),
            'affaire' => ['id' => $ligne['id_affaire'] !== null ? (int) $ligne['id_affaire'] : null,
                          'nom' => (string) $ligne['nom_affaire']],
            'statut' => ['id' => (int) $ligne['id_statut'], 'libelle' => (string) $ligne['statut_libelle']],
            'urgent' => (bool) $ligne['urgent'],
            'demandeur' => trim((string) $ligne['demandeur']) ?: null,
            'pris_en_charge_par' => trim((string) $ligne['pris_en_charge_par']) ?: null,
            'passivation' => $ligne['passivation'],
            'matiere' => $ligne['matiere'],
            'pouces_total_iso' => $ligne['pouces_total_iso'] !== null ? (float) $ligne['pouces_total_iso'] : null,
            'heures_chiffrees' => $ligne['heures_chiffrees'] !== null ? (float) $ligne['heures_chiffrees'] : null,
            'date_creation' => $ligne['date_creation'],
            'date_livraison_prevue' => $ligne['date_livraison_prevue'],
        ];
    }
}
