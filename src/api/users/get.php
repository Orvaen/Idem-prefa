<?php
require_once __DIR__ . '/../../dashboard/includes/prefa_context.php';
require_once __DIR__ . '/../../dashboard/includes/vue/user_row.php';

if (!$isAdmin) prefaError(403, 'Seul un administrateur peut consulter les comptes.');

$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = filter_var($_GET['role'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 10;
$where = '';
$params = [];
$conditions = [];
if ($q !== '') {
    $conditions[] = '(u.identifiant LIKE :identifiant OR u.name LIKE :name OR u.prenom LIKE :prenom)';
    $params = array_fill_keys(['identifiant', 'name', 'prenom'], '%' . $q . '%');
}
// Le compte de chaque onglet de rôle : pris sur la recherche, mais avant le filtre de rôle,
// pour que l'onglet « Personnel atelier » dise combien il en montrerait.
$whereRecherche = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$parRole = $db->prepare('SELECT u.id_role, COUNT(*) AS n FROM Utilisateur u' . $whereRecherche . ' GROUP BY u.id_role');
$parRole->execute($params);
$comptesParRole = [];
foreach ($parRole->fetchAll(PDO::FETCH_ASSOC) as $ligne) $comptesParRole[(int) $ligne['id_role']] = (int) $ligne['n'];
$roles = $db->query('SELECT id, name FROM role ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

if ($roleFilter) {
    $conditions[] = 'u.id_role = :role';
    $params['role'] = $roleFilter;
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$count = $db->prepare('SELECT COUNT(*) FROM Utilisateur u' . $where);
$count->execute($params);
$total = (int) $count->fetchColumn();
$totalPages = max(1, (int) ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$stmt = $db->prepare('SELECT u.*, r.name AS role_name FROM Utilisateur u LEFT JOIN role r ON r.id = u.id_role' . $where . ' ORDER BY u.prenom, u.name, u.id LIMIT :limit OFFSET :offset');
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value, $key === 'role' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageUrl = static function ($number) use ($q, $roleFilter) {
    return htmlspecialchars('/api/users/get.php?' . http_build_query(['page' => $number, 'q' => $q, 'role' => $roleFilter]), ENT_QUOTES, 'UTF-8');
};
$premier = $total ? $offset + 1 : 0;
$dernier = min($offset + $limit, $total);
?>
<div id="utilisateurs-resultats"
     hx-get="<?= $pageUrl($page) ?>"
     hx-trigger="usersChanged from:body"
     hx-target="this" hx-swap="outerHTML">

    <?php // Les rôles en onglets comptés, comme les statuts sur la liste des demandes. Ils
          // vivent dans les résultats pour que leurs comptes suivent la recherche. ?>
    <div class="prefa-onglets-barre">
        <div class="prefa-onglets" role="group" aria-label="Rôle">
            <button type="button" class="prefa-onglet utilisateurs-onglet" data-role="" aria-pressed="<?= $roleFilter ? 'false' : 'true' ?>">
                Tous <span class="prefa-onglet-compte"><?= array_sum($comptesParRole) ?></span>
            </button>
            <?php foreach ($roles as $role): ?>
                <button type="button" class="prefa-onglet utilisateurs-onglet" data-role="<?= (int) $role['id'] ?>"
                        aria-pressed="<?= $roleFilter === (int) $role['id'] ? 'true' : 'false' ?>">
                    <?= prefaEscape($role['name']) ?>
                    <span class="prefa-onglet-compte"><?= $comptesParRole[(int) $role['id']] ?? 0 ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="prefa-table-scroll" role="region" aria-label="Liste des utilisateurs" tabindex="0">
        <?php // data-selection suffit : le script reconnaît la table, ses lignes à leur
              // data-id, et sait quoi appeler pour l'action en lot. ?>
        <table class="prefa-table utilisateurs-table"
               data-selection
               data-selection-action="/dashboard/actions/delete_user.php"
               data-selection-libelle="compte"
               data-csrf="<?= prefaEscape(Session::jeton()) ?>">
            <thead>
                <tr>
                    <th scope="col">Utilisateur</th>
                    <th scope="col">Rôle</th>
                    <th scope="col">Créé le</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <?php userRow($user, $utilisateur->id); ?>
            <?php endforeach; ?>
            <?php if ($total === 0): ?>
                <tr><td class="prefa-table-empty" colspan="4">Aucun utilisateur ne correspond.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="utilisateurs-pagination" aria-label="Pagination des utilisateurs">
            <span aria-live="polite"><?= $premier ?>–<?= $dernier ?> sur <?= $total ?></span>
            <button type="button" aria-label="Page précédente" hx-get="<?= $pageUrl($page - 1) ?>" hx-target="#utilisateurs-resultats" hx-swap="outerHTML"
                    <?= $page <= 1 ? 'disabled' : '' ?>>
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
            </button>
            <button type="button" aria-label="Page suivante" hx-get="<?= $pageUrl($page + 1) ?>" hx-target="#utilisateurs-resultats" hx-swap="outerHTML"
                    <?= $page >= $totalPages ? 'disabled' : '' ?>>
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
            </button>
        </nav>
    <?php endif; ?>
</div>
