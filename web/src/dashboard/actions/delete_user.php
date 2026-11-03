<?php
require_once __DIR__ . '/../includes/prefa_context.php';

prefaPost();
if (!$isAdmin) prefaError(403, 'Seul un administrateur peut supprimer un compte.');

/**
 * Un compte, ou plusieurs.
 *
 * La liste permet de sélectionner des lignes et de les supprimer d'un coup : « ids » porte
 * alors la sélection. Les deux chemins passent par les mêmes garde-fous, écrits une fois —
 * une règle d'accès recopiée finit toujours par diverger d'un côté.
 *
 * Ils ne répondent pas pareil : le bouton d'une ligne est posé par htmx, qui remplace la
 * ligne par la réponse — elle doit donc rester vide. La suppression en lot est appelée en
 * fetch et veut savoir ce qui est passé et ce qui a résisté.
 */
$lot = isset($_POST['ids']);
$ids = $lot ? (array) $_POST['ids'] : [$_POST['id'] ?? null];
$ids = array_values(array_unique(array_filter(array_map(
    static fn ($valeur): int => (int) filter_var($valeur, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
    $ids
))));
if (!$ids) prefaError(400, 'Compte invalide.');
// Au-delà, c'est une erreur de manipulation ou une requête forgée : on refuse plutôt que
// de vider la table sur un glissement de souris malheureux.
if (count($ids) > 100) prefaError(400, 'Trop de comptes à la fois.');

// Garde-fou : un administrateur seul qui supprime son propre compte se ferme la porte,
// et plus personne ne peut créer de compte.
$moi = (int) $actor['id'];
if (in_array($moi, $ids, true)) {
    if (!$lot) prefaError(409, 'Vous ne pouvez pas supprimer votre propre compte.');
    // En lot, on écarte sa propre ligne et on fait le reste : refuser toute la sélection
    // parce qu'elle contient sa propre ligne obligerait à recommencer le geste.
    $ids = array_values(array_diff($ids, [$moi]));
    if (!$ids) prefaError(409, 'Vous ne pouvez pas supprimer votre propre compte.');
}

/**
 * Les demandes qu'un compte a déposées.
 *
 * demande_prefabrication.idUsers n'a pas de clé étrangère : supprimer l'auteur n'échoue
 * donc pas, il laisse ses demandes sans personne. Le refus annoncé par le message reposait
 * sur une exception qui ne pouvait pas se produire — il faut compter avant d'effacer.
 *
 * Les autres liens — affectations, pointages, prises en charge — sont en NO ACTION et
 * lèvent bien une exception : celle-ci reste attrapée plus bas.
 */
$deposees = $db->prepare('SELECT COUNT(*) FROM demande_prefabrication WHERE idUsers = :id');

$supprimes = 0;
$resistants = [];
$effacer = $db->prepare('DELETE FROM Utilisateur WHERE id = :id');
foreach ($ids as $id) {
    $deposees->execute(['id' => $id]);
    $combien = (int) $deposees->fetchColumn();
    if ($combien > 0) {
        if (!$lot) {
            prefaError(409, $combien === 1
                ? 'Ce compte a déposé une demande : il ne peut plus être supprimé.'
                : 'Ce compte a déposé ' . $combien . ' demandes : il ne peut plus être supprimé.');
        }
        $resistants[] = $id;
        continue;
    }

    try {
        $effacer->execute(['id' => $id]);
        $supprimes += $effacer->rowCount();
    } catch (PDOException $erreur) {
        // Affectations, pointages, prises en charge : ces liens-là sont tenus par la base.
        if (!$lot) prefaError(409, 'Ce compte a travaillé en atelier : il ne peut plus être supprimé.');
        $resistants[] = $id;
    }
}

if ($lot) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'supprimes' => $supprimes,
        'resistants' => count($resistants),
        'moiEcarte' => in_array($moi, array_map('intval', (array) $_POST['ids']), true),
    ]);
    exit;
}

header('HX-Trigger-After-Swap: usersChanged');
