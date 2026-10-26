<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/vue/passivation_row.php';
if (!$isAdmin) prefaError(403, 'Acc?s r?serv? aux administrateurs.');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) prefaPost();
elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') prefaError(405, 'M?thode non autoris?e.');
$id = filter_var(($post ? $_POST['id'] ?? '' : $_GET['id'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) prefaError(400, 'Type invalide.');
$stmt = $db->prepare('SELECT id, libelle FROM type_passivation WHERE id = ?');
$stmt->execute([$id]);
$type = $stmt->fetch();
if (!$type) prefaError(404, 'Type introuvable.');
$error = '';
if ($post) {
    $type['libelle'] = trim((string) ($_POST['libelle'] ?? ''));
    if ($type['libelle'] === '' || mb_strlen($type['libelle']) > 100) $error = 'Saisissez un nom de 1 ? 100 caract?res.';
    else {
        try {
            $stmt = $db->prepare('SELECT id FROM type_passivation WHERE libelle = ? AND id <> ? LIMIT 1');
            $stmt->execute([$type['libelle'], $id]);
            if ($stmt->fetchColumn() !== false) $error = 'Ce type existe d?j?.';
            else {
                $stmt = $db->prepare('UPDATE type_passivation SET libelle = ? WHERE id = ?');
                $stmt->execute([$type['libelle'], $id]);
            }
        } catch (PDOException $e) { $error = 'Enregistrement impossible. R?essayez.'; }
    }
}
header('Content-Type: text/html; charset=utf-8');
passivationRow($type, $post ? $error !== '' : !isset($_GET['view']), $error);
