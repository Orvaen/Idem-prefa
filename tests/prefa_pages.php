<?php
// Read-only rendering checks in the local PHP container.
// The container sends warnings to the log instead of the response body: journal.php
// installs an error handler and display_errors is off. This check looks for them in the
// rendered HTML, so it puts PHP's own handling back for its own run.
restore_error_handler();
ini_set('display_errors', '1');
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
    // L'écran suit la liste des demandes : sa recherche, ses onglets, son menu d'actions.
    if (!str_contains($html, 'class="filtres-recherche"')) throw new RuntimeException('Search field is not the house one');
    if (!str_contains($html, 'class="prefa-onglets-barre"') && !str_contains($html, 'Aucune demande validée')) throw new RuntimeException('Status tabs missing');
    if (str_contains($html, '>Normale<')) throw new RuntimeException('Old priority column still present');
}
if ($page === 'soudures') {
    // Un bloc par plan, replié sur ses feuilles ; la feuille se choisit dans un tableau et
    // non plus dans une liste déroulante.
    if (str_contains($html, 'id="soudures-feuille"')) throw new RuntimeException('Old sheet dropdown still present');
    // La page porte la coque des autres listes, et ses utilitaires : on ne doit pas
    // pouvoir lui réinventer un gabarit à elle sans que ce contrôle le dise.
    if (!str_contains($html, '<section class="page prefa-page prefa-list atelier-page soudures"')) throw new RuntimeException('Screen is not on the house list shell');
    if (!str_contains($html, '<header class="prefa-heading prefa-list-heading">')) throw new RuntimeException('House heading missing');
    if (!str_contains($html, 'id="soudures-recherche"')) throw new RuntimeException('Search field missing');
    if (!str_contains($html, 'data-filtre="etat"')) throw new RuntimeException('State filter missing');
    if (!str_contains($html, 'class="filtres-recherche"')) throw new RuntimeException('Search field is not the house one');
    if (!str_contains($html, 'class="prefa-filters soudures-filtres" role="search" hidden')) throw new RuntimeException('Search bar should wait for a plan to be opened');
    if (!preg_match('/class="soudures-feuille"[^>]*data-repere="\d+:\d+:\d+"/', $html)) throw new RuntimeException('Sheet table missing');
    // On entre par le tableau des plans, et les feuilles de celui qu'on ouvre paraissent
    // dans leur propre section : à plat, on ne savait plus laquelle était à quel plan.
    if (!str_contains($html, 'class="prefa-table soudures-plans-table"')) throw new RuntimeException('Plan table missing');
    if (!preg_match('/<tr class="soudures-plan-ligne[^"]*"\s+data-element="\d+"/', $html)) throw new RuntimeException('Plan rows missing');
    if (!preg_match('/<section class="soudures-feuilles-section" data-element="\d+"/', $html)) throw new RuntimeException('Sheet sections missing');
    if (!str_contains($html, 'class="soudures-plan-outils"')) throw new RuntimeException('Sheet toolbar missing');
    // Un plan ouvert doit pouvoir se refermer : le bouton porte son état.
    if (!str_contains($html, 'class="soudures-ouvrir-plan-feuilles" aria-expanded="false"')) throw new RuntimeException('Plan toggle does not carry its state');
    // L'avancement ne se déclare pas d'ici : il se déclare dans « Mes affectations ».
    if (str_contains($html, 'soudures-validation') || str_contains($html, 'soudures-demande')) throw new RuntimeException('Validation form is back on this screen');
    // Rien n'est choisi à l'arrivée : la saisie attend, et aucune lecture de PDF n'est
    // déclenchée pour une feuille que personne n'a demandée.
    // Rien n'est choisi à l'arrivée : ni plan, ni feuille. L'écran attend, il ne devine
    // pas — c'est à l'atelier de dire où il en est.
    if (str_contains($html, 'est-choisie')) throw new RuntimeException('Something is selected by default');
    if (preg_match('/<tr class="soudures-(plan-ligne|feuille)[^>]*aria-current/', $html)) throw new RuntimeException('A row is marked current by default');
    if (!str_contains($html, 'class="soudures-saisie atelier-detail-content" aria-labelledby="soudures-saisie-titre" data-vide>')) throw new RuntimeException('Entry block does not wait for a choice');
}
if ($page === 'create_prefa') {
    if (!str_contains($html, 'name="id_affaire"')) throw new RuntimeException('Affair selector missing');
    // QMOS et DMOS sont obligatoires, la fiche matériel et les CCPU ne le sont pas : les
    // quatre sont rangés dans le même groupe de lignes, et c'est la marque sur le titre de
    // la ligne qui les distingue. Le groupe ne peut donc plus s'annoncer facultatif en
    // entier — actions/create_prefa.php refuse la demande qui part sans QMOS ni DMOS.
    foreach (['QMOS' => true, 'DMOS' => true, 'Fiche matériel' => false, 'CCPU' => false] as $titre => $obligatoire) {
        $marquee = str_contains($html, '<span class="nd-ligne-titre">' . $titre . ' <span class="required-mark"');
        $nue = str_contains($html, '<span class="nd-ligne-titre">' . $titre . '</span>');
        if ($obligatoire && !$marquee) throw new RuntimeException($titre . ' documents are not marked as required');
        if (!$obligatoire && !$nue) throw new RuntimeException($titre . ' documents should not be marked as required');
    }
    if (str_contains($html, 'Facultatifs · PDF')) throw new RuntimeException('The whole document group still announces itself as optional');
    // La liste des documents déjà en base porte un identifiant : c'est là que la
    // vérification d'envoi compte ce qui reste quand on en retire un, et sans lui une
    // modification se refusait alors que le document était bien là. La liste ne paraît
    // qu'en modification, et seulement si la demande en porte : on ne l'exige donc que
    // lorsque la page la montre.
    foreach (['qmos', 'dmos'] as $cle) {
        if (str_contains($html, 'class="prefa-remove-' . $cle . '-saved"')
            && !str_contains($html, 'id="' . $cle . '-saved-files"')) {
            throw new RuntimeException('The saved ' . strtoupper($cle) . ' list carries no identifier');
        }
    }
}
echo $page . ": OK\n";
