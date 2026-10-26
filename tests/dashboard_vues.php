<?php
// Every menu entry must point at something dashboard.php can include on first paint: the
// file has to exist, and the filter an entry may carry in its address ("?view=urgent") has
// to parse. A broken entry used to show as a page cut in half, with a 200 status.
// Run from stdin in the PHP container. Reads the menu only, touches nothing.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/modeles/autoload.php';
require_once $root . '/dashboard/includes/menu.php';

function checkVue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$defauts = 0;
$ids = [];
foreach (dashboardMenu() as $entree) {
    $id = $entree['id'];
    checkVue(!in_array($id, $ids, true), 'Duplicate menu id: ' . $id);
    $ids[] = $id;
    if ($entree['defaut'] ?? false) $defauts++;

    [$fichier, $filtre] = array_pad(explode('?', $entree['page'], 2), 2, '');
    checkVue(is_file($root . '/dashboard/' . $fichier), $id . ' points at a missing page: ' . $fichier);

    if ($filtre !== '') {
        parse_str($filtre, $parametres);
        checkVue($parametres !== [], $id . ' carries a filter that parses to nothing: ' . $filtre);
    }
}

// dashboardVueDemandee() falls back on the entry marked as the default. Without exactly
// one, an unknown address would open whatever comes first, or nothing at all.
checkVue($defauts === 1, 'Exactly one menu entry must be the default, found ' . $defauts);

// And the fallback has to work for a real account, whatever its role.
$db = new MyPDO($root . '/my_setting.ini');
$identifiant = (string) $db->query('SELECT identifiant FROM Utilisateur LIMIT 1')->fetchColumn();
if ($identifiant !== '') {
    $utilisateur = Utilisateur::parIdentifiant($db, $identifiant);
    checkVue($utilisateur !== null, 'The account read back from the database is unknown');
    checkVue(dashboardVueDemandee($utilisateur, 'ecran-qui-nexiste-pas')['defaut'] ?? false,
        'An unknown view must fall back on the default entry');
    checkVue(dashboardVueDemandee($utilisateur, null)['defaut'] ?? false,
        'No view at all must fall back on the default entry');
    $sienne = dashboardMenuVisible($utilisateur)[0]['id'];
    checkVue(dashboardVueDemandee($utilisateur, $sienne)['id'] === $sienne,
        'A view this role may open must resolve to itself');
}

echo 'Menu entries, their pages, their filters and the fallback: OK' . PHP_EOL;
