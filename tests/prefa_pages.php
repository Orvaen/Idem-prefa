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
if (!in_array($page, ['plans_iso', 'lists_prefa', 'planning', 'create_prefa', 'atelier', 'soudures', 'suivi_soudures'], true)) exit(1);
$_SERVER['REQUEST_METHOD'] = 'GET';
if ($page === 'create_prefa') {
    $_GET['id'] = $connection->query('SELECT id FROM demande_prefabrication ORDER BY id LIMIT 1')->fetchColumn();
}
ob_start();
require 'dashboard/pages/' . $page . '.php';
$html = ob_get_clean();
if (preg_match('/(?:Warning|Fatal error|Parse error):/', $html)) throw new RuntimeException('Rendering error');
if (!in_array($page, ['atelier', 'soudures', 'suivi_soudures'], true) && !preg_match('/\d{2}-DP-\d{3,}/', $html)) throw new RuntimeException('Request reference missing');
if ($page === 'plans_iso' && str_contains($html, 'Demande #')) throw new RuntimeException('Old fallback still visible');
if ($page === 'plans_iso' && (!str_contains($html, 'class="prefa-detail-row"') || !str_contains($html, 'aria-controls="prefa-detail-'))) throw new RuntimeException('Request details unavailable');
if ($page === 'plans_iso') {
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($document);
    $plans = $xpath->query('//article[@class="atelier-plan-block"]');
    foreach ($plans as $plan) {
        if ($xpath->query('.//article', $plan)->length !== 0) throw new RuntimeException('Nested plan blocks');
        // La grille des trois travaux est dans sa section du volet, sous son titre
        // « Affectations » ; celle des métiers porte une classe de plus et ne compte pas.
        if ($xpath->query('.//div[@class="atelier-plan-work-grid"]/section', $plan)->length !== 3) throw new RuntimeException('Plan assignment sections missing');
        // « Résumé du plan » : ce qui manque au plan, pour tout l'encadrement.
        if ($xpath->query('.//button[contains(@class,"plan-resume-ouvrir")][@data-element]', $plan)->length !== 1) throw new RuntimeException('Plan summary button missing');
        if ($xpath->query('./div[@class="atelier-editor-row"]/div[contains(@class,"atelier-manage-panel")]', $plan)->length !== 1) throw new RuntimeException('Assignment editor incorrectly nested');
    }
    if ($plans->length && substr_count($html, 'id="plan-resume"') !== 1) throw new RuntimeException('One summary window for the whole page');
    if (str_contains($html, 'class="atelier-plans-table"')) throw new RuntimeException('Old plan table still present');
    // L'écran suit la liste des demandes : sa recherche, ses onglets, son menu d'actions.
    if (!str_contains($html, 'class="filtres-recherche"')) throw new RuntimeException('Search field is not the house one');
    if (!str_contains($html, 'class="prefa-onglets-barre"') && !str_contains($html, 'Aucune demande validée')) throw new RuntimeException('Status tabs missing');
    if (str_contains($html, '>Normale<')) throw new RuntimeException('Old priority column still present');
}
if ($page === 'soudures') {
    // La feuille se choisit dans un tableau, pas dans une liste déroulante.
    if (str_contains($html, 'id="soudures-feuille"')) throw new RuntimeException('Old sheet dropdown still present');
    // La page porte la coque des autres listes, et ses utilitaires : on ne doit pas
    // pouvoir lui réinventer un gabarit à elle sans que ce contrôle le dise.
    if (!str_contains($html, '<section class="page prefa-page prefa-list atelier-page soudures"')) throw new RuntimeException('Screen is not on the house list shell');
    if (!str_contains($html, '<header class="prefa-heading prefa-list-heading">')) throw new RuntimeException('House heading missing');
    // Les outils de vue des autres listes, au même endroit : la densité et « Actualiser ».
    if (!str_contains($html, 'class="prefa-densite"') || !str_contains($html, 'soudures-actualiser')) throw new RuntimeException('View tools missing from the heading');
    // Une seule barre, la recherche : le plan ne s'y choisit plus. Chaque plan est en tête de
    // ses feuilles, sur la ligne de groupe des autres listes.
    if (!str_contains($html, 'class="prefa-filters soudures-filtres" role="search"')) throw new RuntimeException('Filter bar missing');
    if (str_contains($html, 'data-choix-plan')) throw new RuntimeException('The plan chooser is back');
    if (!preg_match('/<tbody class="prefa-groupe soudures-plan" data-element="\d+">/', $html)) throw new RuntimeException('Sheets are not under their plan');
    // Les plans arrivent repliés : on déroule celui qu'on travaille, l'écran ne le devine pas.
    if (!str_contains($html, '<button type="button" class="soudures-plan-bascule" aria-expanded="false">')) throw new RuntimeException('A plan does not fold');
    if (preg_match('/<tbody class="prefa-request soudures-feuille"(?![^>]*\shidden>)[^>]*>/', $html)) throw new RuntimeException('A plan arrives unfolded');
    if (!str_contains($html, 'id="soudures-recherche"')) throw new RuntimeException('Search field missing');
    if (!str_contains($html, 'class="filtres-recherche"')) throw new RuntimeException('Search field is not the house one');
    // L'état se choisit dans les onglets, dont les points sont la légende de ceux des lignes.
    if (str_contains($html, 'data-filtre="etat"')) throw new RuntimeException('The state filter pill is back');
    if (str_contains($html, 'class="prefa-onglets-barre"') && !str_contains($html, 'class="prefa-onglet soudures-onglet"')) throw new RuntimeException('State tabs missing');
    if (str_contains($html, 'soudures-voir-dessin')) throw new RuntimeException('The extra drawing button is back');
    if (!preg_match('/class="prefa-request soudures-feuille"[^>]*data-repere="\d+:\d+:\d+"/', $html)) throw new RuntimeException('Sheet table missing');
    if (!preg_match('/class="prefa-point soudures-point est-[a-z_]+" role="img" data-etat-point/', $html)) throw new RuntimeException('A sheet row does not open on its state dot');
    // Une feuille s'ouvre sur son dessin, et c'est là qu'on la remplit : on clique une ligne
    // de sa liste soudure. La liste de cordons du volet n'existe plus.
    if (!preg_match('/class="prefa-ligne-contour soudures-ouvrir-plan" data-repere="\d+:\d+:\d+"/', $html)) throw new RuntimeException('A sheet does not open its drawing');
    // La fenêtre du plan n'est pas construite avec la liste : elle arrive à la première feuille
    // qu'on ouvre de ce plan (actions/soudures_lecteur.php), avec son jeton.
    if (!str_contains($html, 'data-lecteurs')) throw new RuntimeException('No place for the drawing viewer');
    if (str_contains($html, 'class="soudures-lecteur"')) throw new RuntimeException('Drawing viewers are built with the list again');
    preg_match('/data-repere="(\d+):\d+:\d+"/', $html, $repere);
    require_once 'dashboard/includes/vue/soudures_lecteur.php';
    if (!preg_match('/class="soudures-lecteur" data-element="\d+" data-csrf="[0-9a-f]+"/', souduresLecteurRendu($connection, (int) $repere[1]))) throw new RuntimeException('Drawing viewer missing, or without its token');
    // Le cordon se renseigne dans la fenêtre de la maison, et son procédé dans la liste
    // filtrable — pas dans la liste déroulante du navigateur.
    if (!str_contains($html, '<dialog class="fenetre soudures-cordon" id="soudures-cordon"')) throw new RuntimeException('Weld window missing');
    if (!str_contains($html, 'data-liste-filtrable="cordon-procede"')) throw new RuntimeException('Process list missing');
    if (str_contains($html, '<datalist')) throw new RuntimeException('A browser datalist is back on this screen');
    if (str_contains($html, 'id="soudures-volet"') || str_contains($html, 'id="soudures-corps"')) throw new RuntimeException('The old list of welds is back');
    // L'avancement ne se déclare pas d'ici : il se déclare dans « Mes affectations ».
    if (str_contains($html, 'soudures-validation') || str_contains($html, 'soudures-demande')) throw new RuntimeException('Validation form is back on this screen');
    // Rien n'est choisi à l'arrivée : aucune feuille, et aucune lecture de PDF n'est
    // déclenchée pour une feuille que personne n'a demandée. L'écran attend, il ne devine
    // pas — c'est à l'atelier de dire où il en est.
    if (str_contains($html, 'est-choisie')) throw new RuntimeException('Something is selected by default');
    if (preg_match('/<tbody class="prefa-request soudures-feuille"[^>]*aria-current/', $html)) throw new RuntimeException('A row is marked current by default');
}
if ($page === 'suivi_soudures') {
    // L'écran de l'encadrement porte l'ossature des autres listes : la densité et
    // « Actualiser » en tête, la recherche seule, un onglet par état — l'état et l'affaire
    // ne reviennent pas en listes déroulantes —, et chaque ligne s'ouvre sur son point.
    if (!str_contains($html, '<section class="page prefa-page prefa-list atelier-page" id="suivi-soudures-page"')) throw new RuntimeException('Screen is not on the house list shell');
    if (!str_contains($html, 'class="prefa-densite"') || !str_contains($html, 'suivi-soudures-actualiser')) throw new RuntimeException('View tools missing from the heading');
    if (str_contains($html, 'id="suivi-soudures-etat"') || str_contains($html, 'id="suivi-soudures-affaire"')) throw new RuntimeException('A state or affair dropdown is back');
    if (!str_contains($html, 'class="prefa-onglet suivi-soudures-onglet"')) throw new RuntimeException('State tabs missing');
    if (str_contains($html, 'suivi-soudures-plan"') && !preg_match('/class="prefa-point suivi-soudures-point est-[a-z_]+" role="img"/', $html)) throw new RuntimeException('A plan row does not open on its state dot');
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
