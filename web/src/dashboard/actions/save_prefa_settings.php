<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/prefa_hours.php';
prefaPost();
if (!$isAdmin) prefaError(403, 'Accès réservé aux administrateurs.');

// Keep the existing storage in hours; the UI accepts calendar days.
$rawDays = $_POST['duree_jours'] ?? null;
$days = is_scalar($rawDays)
    ? filter_var(str_replace(',', '.', trim((string) $rawDays)), FILTER_VALIDATE_FLOAT)
    : false;
if ($days === false || $days < 0.0001 || $days > 100) {
    prefaError(400, 'Saisissez une durée entre 0,0001 et 100 jours par pouce ISO.');
}

/*
 * La borne ci-dessus laisse passer des cadences que le planning ne sait pas poser : à
 * 80 jours par pouce — une valeur acceptée — une demande de cent pouces occupe huit mille
 * jours, et sa validation échouait au moment de la valider, loin de l'écran où le réglage
 * avait été saisi.
 *
 * On confronte donc la cadence aux demandes qui existent vraiment. Un plafond fixe aurait
 * été une invention de plus ; là, le refus s'explique avec les chiffres de l'atelier.
 */
$plusGrosse = (int) $db->query('SELECT COALESCE(MAX(pouces_total_iso), 0) FROM demande_prefabrication
                                WHERE id_statut = 1')->fetchColumn();
if ($plusGrosse > 0) {
    $jours = (int) ceil($plusGrosse * $days);
    if ($jours > 3650) {
        prefaError(409, 'À cette cadence, la plus grosse demande en attente (' . $plusGrosse
            . ' pouces) occuperait ' . number_format($jours, 0, ',', ' ')
            . ' jours — le planning n’en pose pas plus de 3 650. Réduisez la durée par pouce.');
    }
}

$rate = round($days * 24, 4);
$hours = (int) floor($rate);
$minutes = (int) round(($rate - $hours) * 60);
if ($minutes === 60) {
    $hours++;
    $minutes = 0;
}

try {
    try {
        $stmt = $db->prepare('INSERT INTO parametrage_prefabrication (id, heures_par_pouce, duree_heures, duree_minutes)
            VALUES (1, :rate, :hours, :minutes)
            ON DUPLICATE KEY UPDATE
                heures_par_pouce = VALUES(heures_par_pouce),
                duree_heures = VALUES(duree_heures),
                duree_minutes = VALUES(duree_minutes)');
        $stmt->execute([
            ':rate' => number_format($rate, 4, '.', ''),
            ':hours' => $hours,
            ':minutes' => $minutes,
        ]);
    } catch (PDOException) {
        $stmt = $db->prepare('INSERT INTO parametrage_prefabrication (id, heures_par_pouce)
            VALUES (1, :rate)
            ON DUPLICATE KEY UPDATE
                heures_par_pouce = VALUES(heures_par_pouce)');
        $stmt->execute([
            ':rate' => number_format($rate, 4, '.', ''),
        ]);
    }

    $formatted = prefaFormatDays($days);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'Temps par pouce ISO enregistré (' . $formatted . ').',
        'days' => $days,
        'formatted' => $formatted,
        'rate' => number_format($rate, 4, '.', ''),
    ]);
} catch (PDOException $e) {
    prefaError(500, 'Impossible d’enregistrer le temps par pouce.');
}

