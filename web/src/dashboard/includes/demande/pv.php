<?php

/**
 * Procès-verbaux d'une demande de préfabrication : libellés, stockage des documents
 * et lecture groupée.
 *
 * Trois PV, d'après le cahier des charges : la réception matière (commentaire si besoin),
 * la fin de fabrication et conformité de la préfa — un seul PV, avec ses photos — et la
 * passivation. « Conformité » était un quatrième PV ; la migration 2026-10-27_pv_refonte
 * l'a repris dans la fin de fabrication.
 */

/** Les trois PV d'une demande, dans l'ordre de la fabrication. */
function pvTypes(): array
{
    return [
        'reception_materiel' => 'Réception matière',
        'fin_fabrication' => 'Fin de fabrication et conformité',
        'passivation' => 'Passivation',
    ];
}

/** Ce que chaque PV atteste, en une ligne : la phrase sous son titre. */
function pvDescriptions(): array
{
    return [
        'reception_materiel' => 'Le matériel est arrivé et correspond à la commande. Un commentaire si besoin.',
        'fin_fabrication' => 'La préfabrication est terminée et conforme, photos à l’appui.',
        'passivation' => 'La passivation demandée est faite.',
    ];
}

/** États d'un PV. « Sans objet » couvre ce qui ne s'applique pas à cette demande. */
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
 * PV d'une demande, indexés par type, chacun avec ses documents et ses photos.
 *
 * Les lignes sont créées à la première écriture : une demande qui n'a jamais été touchée
 * renvoie trois PV « à faire » sans identifiant.
 *
 * Une demande sans passivation n'a pas de PV de passivation à faire : il est « sans
 * objet » d'office, et marqué « auto » pour qu'aucun écran ne propose de le renseigner.
 * Le calcul se fait à la lecture et non en base : si la demande gagne une passivation
 * plus tard, le PV redevient à faire sans qu'on ait rien à rattraper.
 *
 * @return array<string, array<string, mixed>>
 */
function pvParDemande(PDO $db, int $idDemande, bool $avecPassivation = true): array
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
            'documents' => [], 'photos' => [], 'auto' => false,
        ];
    }

    $ids = [];
    foreach ($stmt->fetchAll() as $ligne) {
        // Un PV « conformite » qui aurait échappé à la migration n'a plus de place ici.
        if (!isset($pvs[$ligne['type']])) continue;
        $pvs[$ligne['type']] = $ligne + ['libelle' => pvTypes()[$ligne['type']], 'documents' => [], 'photos' => [], 'auto' => false];
        $ids[(int) $ligne['id']] = $ligne['type'];
    }

    if ($ids) {
        $places = implode(',', array_fill(0, count($ids), '?'));
        // Le nom de qui a déposé : l'historique du volet le raconte.
        $docs = $db->prepare("SELECT d.*, u.prenom AS depose_prenom, u.name AS depose_nom
            FROM pv_document d LEFT JOIN Utilisateur u ON u.id = d.depose_par
            WHERE d.id_pv IN ($places) ORDER BY d.date_depot, d.id");
        $docs->execute(array_keys($ids));
        foreach ($docs->fetchAll() as $document) {
            $rangement = ($document['categorie'] ?? 'document') === 'photo' ? 'photos' : 'documents';
            $pvs[$ids[(int) $document['id_pv']]][$rangement][] = $document;
        }
    }

    // Un PV déjà renseigné à la main garde ce qu'on y a mis : seul celui qui attendait
    // encore passe « sans objet ».
    if (!$avecPassivation && $pvs['passivation']['statut'] === 'a_faire') {
        $pvs['passivation']['statut'] = 'sans_objet';
        $pvs['passivation']['auto'] = true;
    }

    return $pvs;
}

/** Résumé d'une demande : combien de PV faits, combien restent à faire. */
function pvAvancement(array $pvs): array
{
    $faits = 0;
    $restants = 0;
    $applicables = 0;
    foreach ($pvs as $pv) {
        if ($pv['statut'] === 'fait') $faits++;
        elseif ($pv['statut'] === 'a_faire') $restants++;
        if (!$pv['auto']) $applicables++;
    }
    return ['faits' => $faits, 'restants' => $restants, 'total' => count($pvs), 'applicables' => $applicables];
}

/**
 * Les PV encore à faire sur toutes les demandes validées — la pastille du menu et la
 * tuile de l'accueil.
 *
 * Trois PV par demande, moins ceux tranchés, moins la passivation d'une demande qui n'en
 * a pas : la même règle que l'écran, sans quoi la pastille annoncerait un PV que l'écran
 * ne propose pas de faire.
 */
function pvAFaireTotal(PDO $db): int
{
    return (int) $db->query("SELECT COUNT(*)
        FROM demande_prefabrication d
        CROSS JOIN (SELECT 'reception_materiel' AS type UNION ALL SELECT 'fin_fabrication' UNION ALL SELECT 'passivation') t
        LEFT JOIN pv_prefabrication p ON p.id_demande = d.id AND p.type = t.type
        WHERE d.id_statut = 2
          AND COALESCE(p.statut, 'a_faire') = 'a_faire'
          AND NOT (t.type = 'passivation' AND d.id_passivation IS NULL)")->fetchColumn();
}

/** La taille d'un fichier, comme on la dit : « 4 Mo », « 320 Ko ». */
function pvTaille(int $octets): string
{
    if ($octets >= 1024 * 1024) return number_format($octets / 1024 / 1024, $octets >= 10 * 1024 * 1024 ? 0 : 1, ',', ' ') . ' Mo';
    return max(1, (int) round($octets / 1024)) . ' Ko';
}

/**
 * L'historique d'une demande côté PV, du plus récent au plus ancien : la validation de la
 * demande, la dernière saisie de chaque PV, chaque fichier déposé. La table ne garde que la
 * dernière saisie d'un PV — l'historique dit donc où l'on en est, pas chaque retouche.
 *
 * @return list<array{quand:string, titre:string, texte:string, genre:string}>
 */
function pvHistorique(array $demande, array $pvs): array
{
    $nom = static fn (?string $prenom, ?string $famille): string => trim((string) $prenom . ' ' . (string) $famille) ?: 'quelqu’un';
    $evenements = [];

    foreach ($pvs as $pv) {
        if ($pv['date_maj']) {
            $evenements[] = [
                'quand' => (string) $pv['date_maj'],
                'titre' => $pv['libelle'] . ' : ' . mb_strtolower(pvStatuts()[$pv['statut']]),
                'texte' => 'Renseigné par ' . $nom($pv['auteur_prenom'], $pv['auteur_nom']) . '.',
                'genre' => $pv['statut'],
            ];
        }
        foreach (array_merge($pv['documents'], $pv['photos']) as $fichier) {
            $photo = ($fichier['categorie'] ?? '') === 'photo';
            $evenements[] = [
                'quand' => (string) $fichier['date_depot'],
                'titre' => ($photo ? 'Photo ajoutée' : 'Document ajouté') . ' · ' . $pv['libelle'],
                'texte' => $fichier['nom'] . ', par ' . $nom($fichier['depose_prenom'] ?? null, $fichier['depose_nom'] ?? null) . '.',
                'genre' => 'fichier',
            ];
        }
    }

    if (!empty($demande['date_validation'])) {
        $evenements[] = [
            'quand' => (string) $demande['date_validation'],
            'titre' => 'Demande validée',
            'texte' => 'Par ' . $nom($demande['valideur_prenom'] ?? null, $demande['valideur_nom'] ?? null) . ' : les PV sont ouverts.',
            'genre' => 'validation',
        ];
    }

    usort($evenements, static fn (array $a, array $b): int => strcmp($b['quand'], $a['quand']));
    return array_slice($evenements, 0, 10);
}
