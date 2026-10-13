<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/passivation_row.php';
if (!$isAdmin) prefaError(403, 'Accès réservé aux administrateurs.');
$types = $db->query('SELECT id, libelle FROM type_passivation ORDER BY libelle')->fetchAll();
?>
<section class="users-page">
    <div class="header-list"><h1 class="list-h1">Types de passivation</h1></div>
    <form id="passivation-form" class="passivation-form">
        <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
        <div class="form-group"><label for="passivation-libelle">Nouveau type</label><input id="passivation-libelle" name="libelle" maxlength="100" placeholder="Nom du type de passivation" required></div>
        <button type="submit">Ajouter</button>
        <p class="prefa-message" role="status" aria-live="polite"></p>
    </form>
    <div class="passivation-table-scroll">
        <table><thead><tr><th scope="col">ID</th><th scope="col">Type de passivation</th><th scope="col">Actions</th></tr></thead>
        <tbody id="passivation-rows">
            <?php foreach ($types as $type): ?>
            <?php passivationRow($type); ?>
            <?php endforeach; ?>
            <?php if (!$types): ?><tr class="passivation-empty"><td colspan="3">Aucun type de passivation. Ajoutez le premier ci-dessus.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</section>
