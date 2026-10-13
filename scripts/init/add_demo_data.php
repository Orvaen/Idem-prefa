<?php
// Run from stdin in the local PHP container. Existing demo records are left intact.
if (PHP_SAPI !== 'cli') exit(1);
chdir('/var/www/html');
require_once 'db.php';
require_once 'dashboard/includes/planning.php';
require_once 'dashboard/includes/prefa_attachments.php';
require_once 'dashboard/includes/prefa_references.php';
$db = new MyPDO();
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
$monday = $today->modify('monday this week');
$created = ['users' => 0, 'requests' => 0, 'planned' => 0];
$files = [];

function demoPdf(string $title): string
{
    $stream = "BT /F1 18 Tf 50 780 Td (" . $title . ") Tj 0 -30 Td /F1 12 Tf (Document fictif - test uniquement) Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
}

$db->beginTransaction();
try {
    $users = [];
    $names = [['Martin', 'Emma'], ['Bernard', 'Louis'], ['Dubois', 'Jade'], ['Petit', 'Gabriel'], ['Moreau', 'Camille']];
    $findUser = $db->prepare('SELECT id, id_role FROM Utilisateur WHERE identifiant = ?');
    $insertUser = $db->prepare('INSERT INTO Utilisateur (name, prenom, identifiant, email, id_role, password) VALUES (?, ?, ?, ?, ?, ?)');
    // Les deux sont des demandeurs : « Demandeur » (rôle 5) est le chargé d'affaire de la
    // fiche métier. Le rôle 4 employé ici n'existe pas dans la table role — les comptes
    // créés ainsi n'étaient reconnus par aucun écran.
    foreach (['charger' => [5, 5], 'demandeur' => [5, 4]] as $kind => [$role, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $login = sprintf('demo.%s.%02d', $kind, $i + 1);
            $findUser->execute([$login]);
            $existing = $findUser->fetch();
            if ($existing && (int) $existing['id_role'] !== $role) throw new RuntimeException('Demo account role changed: ' . $login);
            if (!$existing) {
                $insertUser->execute([$names[$i][0], $names[$i][1] . ' Test', $login, $login . '@example.test', $role, password_hash('DemoISO2026!', PASSWORD_BCRYPT)]);
                $existing = ['id' => $db->lastInsertId()];
                $created['users']++;
            }
            $users[$kind][] = (int) $existing['id'];
        }
    }
    $admin = $db->query('SELECT id FROM Utilisateur WHERE id_role = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: null;
    $materials = $db->query('SELECT id FROM type_matiere ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $passivations = $db->query('SELECT id FROM type_passivation ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    // Combien de demandes d'essai existent déjà. Elles n'ont pas de clé métier : une
    // affaire porte plusieurs demandes, et deux demandes du même chantier portent donc le
    // même nom. C'est le compte qui dit ce qu'il reste à créer.
    $dejaCreees = (int) $db->query("SELECT COUNT(*) FROM demande_prefabrication WHERE nom_affaire LIKE '[TEST]%'")->fetchColumn();
    $projects = ['Reseau vapeur', 'Collecteur inox', 'Circuit refroidissement', 'Skid filtration', 'Ligne air comprime', 'Station pompage'];
    // Combien de demandes au total. Le script saute celles qui existent déjà : relancer
    // avec un nombre plus grand n'ajoute que ce qui manque, sans toucher au reste.
    $combien = max(1, (int) ($argv[1] ?? 24));
    for ($i = $dejaCreees; $i < $combien; $i++) {
        $owner = $users['demandeur'][$i % 4];
        // Le nom EST celui de l'affaire : avec six chantiers pour vingt-quatre demandes,
        // chaque affaire en porte plusieurs — c'est ce que fait le métier, et ce que les
        // écrans doivent savoir montrer.
        $name = sprintf('[TEST] %s', $projects[$i % 6]);
        // Les mêmes proportions quel que soit le nombre demandé : deux tiers de validées
        // — c'est là qu'il y a du travail d'atelier —, puis des demandes à valider, et
        // une ou deux refusées pour voir cet état. À vingt-quatre, on retrouve la
        // répartition d'origine : 16, 6, 2.
        $status = match (true) {
            $i < (int) ceil($combien * 2 / 3) => 2,
            $i < (int) ceil($combien * 11 / 12) => 1,
            default => 3,
        };
        $hours = (1 + $i % 5) * 24;
        $planned = $i < (int) ceil($combien / 2);
        $period = $planned ? planningPeriod($monday->modify('+' . ($i % 4 * 2) . ' days'), $hours, true) : null;
        $data = [
            'idUsers' => $owner, 'DateUpdate' => $today->format('Y-m-d H:i:s'),
            // La première demande est la plus ancienne : « -24 jours » quand il y en a
            // vingt-quatre. Sans le plancher, une demande au-delà du compte reculait d'un
            // nombre négatif de jours, et la date ne se construisait plus.
            'date_creation' => $today->modify('-' . max(0, $combien - $i) . ' days')->format('Y-m-d H:i:s'),
            'date_fin_prevue' => $monday->modify('+' . (3 + $i % 12) . ' days')->format('Y-m-d'),
            'date_livraison_prevue' => $monday->modify('+' . (7 + $i % 12) . ' days')->format('Y-m-d'),
            'nom_affaire' => $name, 'plan_bpe_iso' => 'Plan de demonstration',
            'pouces_total_iso' => 10 + $i * 5, 'heures_chiffrees' => $hours,
            'CDN' => 1, ' QMOS' => '', 'urgent' => (int) ($i % 4 === 0),
            'id_statut' => $status, 'controles_cdn' => 10 + $i % 4 * 10,
            'RT' => (int) ($i % 2 === 0), 'controles_rt' => $i % 2 === 0 ? 25 : null,
            'PT' => (int) ($i % 3 === 0), 'controles_pt' => $i % 3 === 0 ? 100 : null,
            'revetement' => (int) ($i % 2 === 1), 'commentaire_revetement' => $i % 2 === 1 ? 'Peinture anticorrosion - test' : null,
            'id_matiere' => $materials ? $materials[$i % count($materials)] : null,
            'matiere_disponibilite' => $materials ? ($i % 2 ? 'commande' : 'stock') : null,
            'id_passivation' => $passivations && $i % 2 === 0 ? $passivations[$i % count($passivations)] : null,
            'id_charger_affaire' => $status === 2 ? $users['charger'][$i % 5] : null,
            'valide_par' => $status !== 1 ? $admin : null,
            'date_validation' => $status !== 1 ? $today->format('Y-m-d H:i:s') : null,
            'commentaire_validation' => $status === 3 ? 'Test : documents techniques a completer.' : null,
            'date_debut_planifiee' => $period['start'] ?? null, 'date_fin_planifiee' => $period['end'] ?? null,
            'planning_jours_ouvres' => 1, 'planning_revision' => $planned ? 1 : 0,
        ];
        $columns = implode(', ', array_map(fn($key) => '`' . $key . '`', array_keys($data)));
        $stmt = $db->prepare('INSERT INTO demande_prefabrication (' . $columns . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')');
        $stmt->execute(array_values($data));
        $id = (int) $db->lastInsertId();
        $creationDate = new DateTimeImmutable($data['date_creation'], new DateTimeZone('UTC'));
        prefaAssignReference($db, $id, (int) $creationDate->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y'));
        $directory = prefaAttachmentDirectory($id);
        if (!is_dir($directory) && !mkdir($directory, 0770, true)) throw new RuntimeException('Cannot create demo attachment directory.');
        if (posix_geteuid() === 0 && (!chown($directory, 'www-data') || !chgrp($directory, 'www-data'))) {
            throw new RuntimeException('Cannot set demo directory ownership.');
        }
        $path = $directory . '/' . bin2hex(random_bytes(16)) . '-Plan-ISO-TEST.pdf';
        $files[] = $path;
        if (file_put_contents($path, demoPdf(sprintf('Plan ISO TEST %02d', $i + 1))) === false) throw new RuntimeException('Cannot write demo PDF.');
        if (!chmod($path, 0660)) throw new RuntimeException('Cannot set demo PDF permissions.');
        if (posix_geteuid() === 0 && (!chown($path, 'www-data') || !chgrp($path, 'www-data'))) {
            throw new RuntimeException('Cannot set demo PDF ownership.');
        }
        $created['requests']++;
        if ($planned) $created['planned']++;
    }
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    foreach ($files as $path) if (is_file($path)) unlink($path);
    throw $error;
}
echo json_encode($created, JSON_PRETTY_PRINT) . PHP_EOL;
