<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../../api/User.php';
require_once __DIR__ . '/../includes/vue/user_row.php';

prefaPost();
if (!$isAdmin) prefaError(403, 'Seul un administrateur peut modifier un compte.');

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$prenom = trim((string) ($_POST['prenom'] ?? ''));
$name = trim((string) ($_POST['name'] ?? ''));
if (!$id || $name === '' || $prenom === '') prefaError(400, 'Le nom et le prénom sont obligatoires.');

// Renommer refabrique l'identifiant de connexion : deux comptes ne peuvent pas se retrouver
// avec le même, sans quoi l'un des deux ne peut plus se connecter.
$identifiant = User::buildIdentifiant($name, $prenom);
$conflit = $db->prepare('SELECT 1 FROM Utilisateur WHERE identifiant = ? AND id <> ?');
$conflit->execute([$identifiant, $id]);
if ($conflit->fetchColumn()) prefaError(409, 'L’identifiant ' . $identifiant . ' est déjà utilisé.');

$stmt = $db->prepare("
    UPDATE Utilisateur
    SET name= :name,
        prenom = :prenom,
        identifiant = :identifiant
    WHERE id = :id
");

$stmt->execute([
    'name' => $name,
    'prenom' => $prenom,
    'identifiant' => $identifiant,
    'id' => $id
]);
header('HX-Trigger-After-Swap: usersChanged');
$details = $db->prepare('SELECT u.*, r.name AS role_name FROM Utilisateur u LEFT JOIN role r ON r.id = u.id_role WHERE u.id = ?');
$details->execute([$id]);
$user = $details->fetch();
if (!$user) prefaError(404, 'Compte introuvable.');

userRow($user, $utilisateur->id);
