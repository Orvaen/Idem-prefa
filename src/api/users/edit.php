<?php
require_once __DIR__ . '/../../dashboard/includes/prefa_context.php';
require_once __DIR__ . '/../../dashboard/includes/vue/user_display.php';

if (!$isAdmin) prefaError(403, 'Seul un administrateur peut modifier un compte.');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) prefaError(400, 'Compte invalide.');

$sql = "SELECT u.*, r.name AS role_name FROM Utilisateur u LEFT JOIN role r ON r.id = u.id_role WHERE u.id = :id";
$stmt = $db->prepare($sql);
$stmt->execute([
    ':id' => $id
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) prefaError(404, 'Compte introuvable.');

?>

<tr>
    <td>
        <?= (int) $user['id'] ?>
        <input type="hidden" name="id"
            value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
    </td>
    <td>
        <input
            name="name"
            value="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>">
    </td>
    <td>
        <input
            name="prenom"
            value="<?= htmlspecialchars($user['prenom'], ENT_QUOTES, 'UTF-8') ?>">

    </td>
    <td>
        <?= htmlspecialchars($user['identifiant'], ENT_QUOTES, 'UTF-8') ?>
    </td>
    <td><?= htmlspecialchars(userRoleLabel($user), ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars(userCreationLabel($user), ENT_QUOTES, 'UTF-8') ?></td>
    <td>
        <button
            hx-post="/dashboard/actions/edit_user.php"
            hx-include="closest tr"
            hx-target="closest tr"
            hx-swap="outerHTML">
            Enregistrer
        </button>

        <button
            hx-post="/dashboard/actions/delete_user.php"
            hx-vals='<?= prefaEscape(json_encode(['id' => (int) $user['id'], 'csrf' => Session::jeton()])) ?>'
            hx-confirm="Supprimer cet utilisateur ?"
            hx-target="closest tr"
            hx-swap="outerHTML">
            Supprimer
        </button>
    </td>
</tr>
