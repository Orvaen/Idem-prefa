<?php
// Read-only rendering checks in the local PHP container.
chdir('/var/www/html');
require_once 'db.php';
$connection = new MyPDO();
$page = $argv[1] ?? 'plans_iso';
$admin = in_array($page, ['atelier', 'soudures'], true)
    ? $connection->query("SELECT u.identifiant FROM Utilisateur u JOIN role r ON r.id = u.id_role WHERE r.name IN ('Utilisateur', 'Personnel atelier') LIMIT 1")->fetchColumn()
    : $connection->query('SELECT identifiant FROM Utilisateur WHERE id_role = 1 LIMIT 1')->fetchColumn();
if (($argv[2] ?? '') === 'chief') {
    $admin = $connection->query('SELECT identifiant FROM Utilisateur WHERE id_role = 2 LIMIT 1')->fetchColumn();
}
if (!$admin) throw new RuntimeException('No administrator available for the rendering check');
session_start();
$_SESSION['identifiant'] = $admin;
session_write_close();
if (!in_array($page, ['plans_iso', 'lists_prefa', 'planning', 'create_prefa', 'atelier', 'soudures'], true)) exit(1);
$_SERVER['REQUEST_METHOD'] = 'GET';
if ($page === 'create_prefa') {
    $_GET['id'] = $connection->query('SELECT id FROM demande_prefabrication ORDER BY id LIMIT 1')->fetchColumn();
}
ob_start();
require 'dashboard/pages/' . $page . '.php';
$html = ob_get_clean();
if (preg_match('/(?:Warning|Fatal error|Parse error):/', $html)) throw new RuntimeException('Rendering error');
if (!in_array($page, ['atelier', 'soudures'], true) && !preg_match('/\d{2}-DP-\d{3,}/', $html)) throw new RuntimeException('Request reference missing');
if ($page === 'plans_iso' && str_contains($html, 'Demande #')) throw new RuntimeException('Old fallback still visible');
if ($page === 'plans_iso' && (!str_contains($html, 'class="prefa-detail-row"') || !str_contains($html, 'aria-controls="prefa-detail-'))) throw new RuntimeException('Request details unavailable');
if ($page === 'plans_iso') {
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($document);
    $plans = $xpath->query('//article[@class="atelier-plan-block"]');
    foreach ($plans as $plan) {
        if ($xpath->query('.//article', $plan)->length !== 0) throw new RuntimeException('Nested plan blocks');
        if ($xpath->query('./div[@class="atelier-plan-work-grid"]/section', $plan)->length !== 3) throw new RuntimeException('Plan assignment sections missing');
        if ($xpath->query('./div[@class="atelier-editor-row"]/div[contains(@class,"atelier-manage-panel")]', $plan)->length !== 1) throw new RuntimeException('Assignment editor incorrectly nested');
    }
    if (str_contains($html, 'class="atelier-plans-table"')) throw new RuntimeException('Old plan table still present');
}
if ($page === 'soudures') {
    // Un bloc par plan, replié sur ses feuilles ; la feuille se choisit dans un tableau et
    // non plus dans une liste déroulante.
    if (str_contains($html, 'id="soudures-feuille"')) throw new RuntimeException('Old sheet dropdown still present');
    if (!str_contains($html, '<li class="soudures-plan est-repliee">')) throw new RuntimeException('Plan blocks are not folded on arrival');
    if (!str_contains($html, 'class="soudures-plan-bascule"')) throw new RuntimeException('Plan fold button missing');
    if (!preg_match('/class="soudures-feuille"[^>]*data-repere="\d+:\d+:\d+"/', $html)) throw new RuntimeException('Sheet table missing');
    // L'avancement ne se déclare pas d'ici : il se déclare dans « Mes affectations ».
    if (str_contains($html, 'soudures-validation') || str_contains($html, 'soudures-demande')) throw new RuntimeException('Validation form is back on this screen');
    // Rien n'est choisi à l'arrivée : la saisie attend, et aucune lecture de PDF n'est
    // déclenchée pour une feuille que personne n'a demandée.
    if (str_contains($html, 'est-choisie')) throw new RuntimeException('A sheet is selected by default');
    if (!str_contains($html, '<section class="soudures-saisie" aria-labelledby="soudures-saisie-titre" data-vide>')) throw new RuntimeException('Entry block does not wait for a choice');
}
if ($page === 'create_prefa' && !str_contains($html, 'name="nom_affaire"')) throw new RuntimeException('Request name input missing');
echo $page . ": OK\n";
