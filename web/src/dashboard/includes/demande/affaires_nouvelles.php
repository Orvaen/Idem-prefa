<?php

/**
 * Les affaires qu'on vient de confier à quelqu'un, et qu'il n'a pas encore vues.
 *
 * L'annonce en direct (le flux) ne suffit pas : on n'est pas toujours devant l'écran quand
 * le chargé de production ouvre l'affaire. Ce qui n'a pas été vu attend donc sur l'entrée
 * « Accueil » — une pastille « +1 » — et l'accueil en donne la liste. L'avoir ouvert vaut
 * lecture.
 *
 * La source est le journal des événements (affaire.confiee, adressé à une personne). Ce qui
 * a été vu tient en un nombre : Utilisateur.dernier_evenement_vu, l'identifiant du dernier
 * événement lu. Pas de table de lectures : les événements se suivent, un curseur suffit.
 *
 * Avant la migration 2026-10-28_affaires_vues, la colonne n'existe pas : tout rend zéro et
 * rien ne casse.
 */

/**
 * Les affaires confiées à cette personne et pas encore vues, la plus récente en tête.
 *
 * @return list<array{id:int, affaire:string, par:string, quand:string}>
 */
function affairesNouvelles(PDO $db, int $idUtilisateur): array
{
    try {
        $lecture = $db->prepare("SELECT e.id, e.donnees, e.cree_le, TRIM(CONCAT(a.prenom, ' ', a.name)) AS par
            FROM evenement e
            JOIN Utilisateur moi ON moi.id = e.id_destinataire
            LEFT JOIN Utilisateur a ON a.id = e.id_acteur
            WHERE e.type = 'affaire.confiee' AND e.id_destinataire = ? AND e.id > moi.dernier_evenement_vu
            ORDER BY e.id DESC LIMIT 20");
        $lecture->execute([$idUtilisateur]);
    } catch (PDOException) {
        return [];
    }
    $affaires = [];
    foreach ($lecture->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
        $donnees = json_decode((string) $ligne['donnees'], true) ?: [];
        $affaires[] = [
            'id' => (int) $ligne['id'],
            'affaire' => (string) ($donnees['affaire'] ?? 'Affaire'),
            'par' => (string) ($ligne['par'] ?: ''),
            'quand' => (string) $ligne['cree_le'],
        ];
    }
    return $affaires;
}

/** Combien d'affaires attendent d'être vues : le chiffre de la pastille. */
function affairesNouvellesCompte(PDO $db, int $idUtilisateur): int
{
    try {
        $lecture = $db->prepare("SELECT COUNT(*) FROM evenement e
            JOIN Utilisateur moi ON moi.id = e.id_destinataire
            WHERE e.type = 'affaire.confiee' AND e.id_destinataire = ? AND e.id > moi.dernier_evenement_vu");
        $lecture->execute([$idUtilisateur]);
        return (int) $lecture->fetchColumn();
    } catch (PDOException) {
        return 0;
    }
}

/** L'accueil vient d'être affiché : tout ce qu'il a montré est lu. */
function affairesNouvellesMarquerVues(PDO $db, int $idUtilisateur, array $affaires): void
{
    if (!$affaires) return;
    $dernier = max(array_column($affaires, 'id'));
    try {
        // GREATEST : deux onglets ne font jamais reculer le curseur.
        $db->prepare('UPDATE Utilisateur SET dernier_evenement_vu = GREATEST(dernier_evenement_vu, ?) WHERE id = ?')
           ->execute([$dernier, $idUtilisateur]);
    } catch (PDOException) {
    }
}
