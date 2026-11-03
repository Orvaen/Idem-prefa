<?php
require_once __DIR__ . '/../includes/prefa_context.php';
prefaPost();

$label = trim((string) ($_POST['libelle'] ?? ''));
if ($label === '' || mb_strlen($label) > 100) {
    prefaError(400, 'Saisissez un nom de 1 à 100 caractères.');
}

try {
    $stmt = $db->prepare('SELECT id, libelle FROM type_passivation WHERE LOWER(libelle) = LOWER(?) LIMIT 1');
    $stmt->execute([$label]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'id' => (int) $existing['id'],
            'libelle' => $existing['libelle'],
            'already_exists' => true,
            'message' => 'Type de passivation déjà existant sélectionné.',
        ]);
        exit;
    }

    $stmt = $db->prepare('INSERT INTO type_passivation (libelle) VALUES (?)');
    $stmt->execute([$label]);
    $newId = (int) $db->lastInsertId();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'id' => $newId,
        'libelle' => $label,
        'already_exists' => false,
        'message' => 'Type de passivation ajouté avec succès.',
    ]);
} catch (PDOException $e) {
    prefaError(500, 'Impossible d’ajouter ce type. Réessayez.');
}

