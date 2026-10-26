<?php

/**
 * Procès-verbaux d'une demande de préfabrication : libellés, stockage des documents
 * et lecture groupée.
 */

/** Les quatre PV d'une demande, dans l'ordre de la fabrication. */
function pvTypes(): array
{
    return [
        'reception_materiel' => 'Réception matériel',
        'fin_fabrication' => 'Fin de fabrication',
        'conformite' => 'Conformité',
        'passivation' => 'Passivation',
    ];
}

/** États d'un PV. « Sans objet » couvre le « si besoin » de la réception matériel. */
function pvStatuts(): array
{
    return [
        'a_faire' => 'À faire',
        'fait' => 'Fait',
        'sans_objet' => 'Sans objet',
    ];
}

/** Dossier des documents d'un PV, à côté des pièces jointes des demandes. */
function pvDocumentDirectory(int $idPv): string
{
    // Deux crans : voir prefaAttachmentDirectory().
    return dirname(__DIR__, 2) . '/uploads/pv/' . $idPv;
}

/**
 * PV d'une demande, indexés par type, chacun avec ses documents.
 *
 * Les lignes sont créées à la première écriture : une demande qui n'a jamais été
 * touchée renvoie donc quatre PV « à faire » sans identifiant.
 *
 * @return array<string, array<string, mixed>>
 */
function pvParDemande(PDO $db, int $idDemande): array
{
    $stmt = $db->prepare('SELECT p.*, u.prenom AS auteur_prenom, u.name AS auteur_nom
        FROM pv_prefabrication p
        LEFT JOIN Utilisateur u ON u.id = p.maj_par
        WHERE p.id_demande = ?');
    $stmt->execute([$idDemande]);

    $pvs = [];
    foreach (pvTypes() as $type => $libelle) {
        $pvs[$type] = [
            'id' => null, 'type' => $type, 'libelle' => $libelle, 'statut' => 'a_faire',
            'date_pv' => null, 'commentaire' => null, 'revision' => 0,
            'auteur_prenom' => null, 'auteur_nom' => null, 'date_maj' => null,
            'documents' => [],
        ];
    }

    $ids = [];
    foreach ($stmt->fetchAll() as $ligne) {
        $pvs[$ligne['type']] = $ligne + ['libelle' => pvTypes()[$ligne['type']], 'documents' => []];
        $ids[(int) $ligne['id']] = $ligne['type'];
    }

    if ($ids) {
        $places = implode(',', array_fill(0, count($ids), '?'));
        $docs = $db->prepare("SELECT * FROM pv_document WHERE id_pv IN ($places) ORDER BY date_depot, id");
        $docs->execute(array_keys($ids));
        foreach ($docs->fetchAll() as $document) {
            $pvs[$ids[(int) $document['id_pv']]]['documents'][] = $document;
        }
    }

    return $pvs;
}

/** Résumé d'une demande : combien de PV faits, combien restent à faire. */
function pvAvancement(array $pvs): array
{
    $faits = 0;
    $restants = 0;
    foreach ($pvs as $pv) {
        if ($pv['statut'] === 'fait') $faits++;
        elseif ($pv['statut'] === 'a_faire') $restants++;
    }
    return ['faits' => $faits, 'restants' => $restants, 'total' => count($pvs)];
}
