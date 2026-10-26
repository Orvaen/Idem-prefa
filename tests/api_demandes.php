<?php
// GET /api/demandes: role visibility, filters, pagination and addressing by reference.
// Run from stdin in the PHP container. All fixture changes are rolled back.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/api/autoload.php';
$db = new MyPDO($root . '/my_setting.ini');

function checkApi(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** A user of the given role, created for this test only. */
function addUser(PDO $db, string $identifiant, int $role): Utilisateur
{
    $db->prepare('INSERT INTO Utilisateur (name, prenom, identifiant, password, id_role) VALUES (?, ?, ?, ?, ?)')
       ->execute(['Essai', ucfirst($identifiant), $identifiant, password_hash('x', PASSWORD_BCRYPT), $role]);
    return Utilisateur::parIdentifiant($db, $identifiant)
        ?? throw new RuntimeException('Fixture user ' . $identifiant . ' was not created');
}

/** A request owned by $owner, plus its reference. */
function addDemande(PDO $db, int $owner, string $affaire, int $statut, bool $urgent, string $reference): int
{
    $db->prepare('INSERT INTO demande_prefabrication
        (idUsers, nom_affaire, DateUpdate, date_creation, pouces_total_iso, heures_chiffrees, CDN, ` QMOS`, urgent, id_statut)
        VALUES (?, ?, NOW(), NOW(), 10, 2.5, 0, \'\', ?, ?)')
       ->execute([$owner, $affaire, $urgent ? 1 : 0, $statut]);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO prefa_reference (id_demande, annee, numero, reference) VALUES (?, 9997, ?, ?)')
       ->execute([$id, $id, $reference]);
    return $id;
}

/**
 * Calls the resource the way api/index.php does, without sending anything.
 *
 * The session token is not checked here: index.php demands it before any write reaches a
 * resource, so this helper stands where the token has already been accepted.
 */
function appel(string $adresse, PDO $db, Utilisateur $utilisateur, string $methode = 'GET', array $corps = []): Reponse
{
    $_SERVER['REQUEST_METHOD'] = $methode;
    $_SERVER['REQUEST_URI'] = $adresse;
    unset($_SERVER['CONTENT_TYPE']); // read as a posted form, which Requete also accepts
    $_GET = [];
    parse_str((string) parse_url($adresse, PHP_URL_QUERY), $_GET);
    $_POST = $corps;
    return Demandes::repondre(Requete::courante(), $db, $utilisateur);
}

/** @return list<string> the references the answer carries, in order. */
function references(Reponse $reponse): array
{
    return array_map(static fn (array $d): string => $d['reference'], $reponse->corps()['donnees']['demandes']);
}

$avant = (int) $db->query('SELECT COUNT(*) FROM demande_prefabrication')->fetchColumn();
$db->beginTransaction();
try {
    $production = addUser($db, 'essai.production', 1);
    $chef = addUser($db, 'essai.chef', 2);
    $atelier = addUser($db, 'essai.atelier', 3);
    $affaire = addUser($db, 'essai.affaire', 5);

    // Four requests: one per situation the visibility rule distinguishes.
    addDemande($db, $affaire->id, 'Pont roulant', 1, false, '97-DP-0001');   // waiting, filed by the affair manager
    addDemande($db, $affaire->id, 'Pont roulant', 2, true, '97-DP-0002');    // validated and urgent
    addDemande($db, $production->id, 'Réseau vapeur', 2, false, '97-DP-0003');
    addDemande($db, $chef->id, 'Réseau vapeur', 1, false, '97-DP-0004');     // the chief's own, still waiting
    addDemande($db, $atelier->id, 'Réseau vapeur', 2, false, '97-DP-0005');  // validated, under the welder's name

    $toutes = static fn (Utilisateur $u): array => references(appel('/api/demandes?par_page=200', $db, $u));
    $miennes = static fn (array $vues): array => array_values(array_intersect($vues,
        ['97-DP-0001', '97-DP-0002', '97-DP-0003', '97-DP-0004']));

    // Who sees what.
    checkApi($miennes($toutes($production)) === ['97-DP-0002', '97-DP-0004', '97-DP-0003', '97-DP-0001'],
        'The production manager sees every request, urgent ones first');
    checkApi($miennes($toutes($affaire)) === ['97-DP-0002', '97-DP-0001'],
        'The affair manager only sees the requests he filed');
    checkApi($miennes($toutes($atelier)) === [],
        'Workshop staff see none of the four: not theirs, or not validated');
    checkApi(in_array('97-DP-0005', $toutes($atelier), true),
        'Workshop staff do see a validated request filed under their own name');
    checkApi($miennes($toutes($chef)) === ['97-DP-0002', '97-DP-0004', '97-DP-0003'],
        'The workshop chief sees validated requests plus his own');

    // Filters.
    $filtre = static fn (string $query): array => $miennes(references(appel('/api/demandes?par_page=200&' . $query, $db, $production)));
    checkApi($filtre('statut=1') === ['97-DP-0004', '97-DP-0001'], 'Status filter');
    checkApi($filtre('statut=1,2') === ['97-DP-0002', '97-DP-0004', '97-DP-0003', '97-DP-0001'], 'Several statuses');
    checkApi($filtre('urgent=1') === ['97-DP-0002'], 'Urgent filter');
    checkApi($filtre('urgent=0') === ['97-DP-0004', '97-DP-0003', '97-DP-0001'], 'Non-urgent filter');
    checkApi($filtre('demandeur=' . $chef->id) === ['97-DP-0004'], 'Requester filter');
    checkApi($filtre('q=vapeur') === ['97-DP-0004', '97-DP-0003'], 'Search looks into the affair name');
    checkApi($filtre('q=97-DP-0002') === ['97-DP-0002'], 'Search looks into the reference');
    // The label is read back instead of being written here: this database holds its
    // statuses double-encoded ("ValidÃ©e"), and the search must be checked against what
    // the rows actually contain, not against what they should contain.
    $valide = (string) $db->query('SELECT libelle FROM statut_demande WHERE id = 2')->fetchColumn();
    checkApi($filtre('q=' . rawurlencode($valide)) === ['97-DP-0002', '97-DP-0003'], 'Search looks into the status label');

    // Pagination. The total counts every visible request, not just the page.
    $page = appel('/api/demandes?statut=1,2&q=97-DP-000&page=1&par_page=2', $db, $production);
    checkApi(references($page) === ['97-DP-0002', '97-DP-0005'], 'First page: the urgent one, then the most recent');
    checkApi($page->corps()['donnees']['page'] === ['numero' => 1, 'par_page' => 2, 'total' => 5], 'Page count');
    $page = appel('/api/demandes?statut=1,2&q=97-DP-000&page=2&par_page=2', $db, $production);
    checkApi(references($page) === ['97-DP-0004', '97-DP-0003'], 'Second page');
    checkApi(count(references(appel('/api/demandes?par_page=0', $db, $production))) === 1, 'par_page=0 still returns a row');

    // One request, by its reference.
    $une = appel('/api/demandes/97-DP-0002', $db, $production);
    checkApi($une->statut() === 200, 'A visible request answers 200');
    checkApi($une->corps()['donnees']['reference'] === '97-DP-0002', 'The reference is the public key');
    checkApi(!array_key_exists('id', $une->corps()['donnees']), 'The internal id never leaves the database');
    checkApi($une->corps()['donnees']['statut'] === ['id' => 2, 'libelle' => $valide], 'The status comes with its label');
    checkApi($une->corps()['donnees']['urgente'] === true, 'Urgency is a boolean, not a string');

    // A request someone may not see is missing, not forbidden: 403 would confirm it exists.
    checkApi(appel('/api/demandes/97-DP-0001', $db, $atelier)->statut() === 404, 'A request out of reach answers 404');
    checkApi(appel('/api/demandes/97-DP-9999', $db, $production)->statut() === 404, 'An unknown reference answers 404');

    // Refused requests.
    checkApi(appel('/api/demandes?statut=deux', $db, $production)->statut() === 400, 'A non-numeric status is refused');
    checkApi(appel('/api/demandes?urgent=oui', $db, $production)->statut() === 400, 'urgent only takes 0 or 1');
    checkApi(appel('/api/demandes?page=-1', $db, $production)->statut() === 400, 'A negative page is refused');
    // Filing a request still goes through the form, with its four hundred lines of checks
    // and its uploads: the resource does not pretend otherwise.
    checkApi(appel('/api/demandes', $db, $production, 'POST', ['nom_affaire' => 'Pont'])->statut() === 405,
        'Filing a request is not offered by the API');

    // ----------------------------------------------------------------- the decisions

    // Who may decide. The affair manager files requests, the chief runs the workshop;
    // neither commits it to a job.
    checkApi(appel('/api/demandes/97-DP-0001', $db, $affaire, 'PATCH', ['statut' => 2])->statut() === 403,
        'The affair manager may not validate');
    checkApi(appel('/api/demandes/97-DP-0004', $db, $chef, 'PATCH', ['statut' => 2])->statut() === 403,
        'The workshop chief may not validate');

    // What a decision has to say.
    checkApi(appel('/api/demandes/97-DP-0001', $db, $production, 'PATCH', ['statut' => 9])->statut() === 400,
        'A status that is neither validated nor refused is refused');
    checkApi(appel('/api/demandes/97-DP-0001', $db, $production, 'PATCH', [])->statut() === 400,
        'A decision without a status is refused');
    checkApi(appel('/api/demandes/97-DP-0001', $db, $production, 'PATCH',
        ['statut' => 3, 'motif' => ''])->statut() === 400, 'A refusal without a reason is refused');
    checkApi(appel('/api/demandes/97-DP-0001', $db, $production, 'PATCH',
        ['statut' => 3, 'motif' => str_repeat('x', 1001)])->statut() === 400, 'A reason longer than 1 000 characters is refused');

    // Validating: the status changes, and the request takes its place in the planning.
    $validee = appel('/api/demandes/97-DP-0001', $db, $production, 'PATCH', ['statut' => 2]);
    checkApi($validee->statut() === 200, 'A pending request can be validated');
    checkApi($validee->corps()['donnees']['statut']['id'] === Demande::VALIDEE, 'The answer carries the new status');
    $pose = $db->prepare('SELECT date_debut_planifiee, date_fin_planifiee, valide_par FROM demande_prefabrication
        WHERE id = (SELECT id_demande FROM prefa_reference WHERE reference = ?)');
    $pose->execute(['97-DP-0001']);
    $planifiee = $pose->fetch(PDO::FETCH_ASSOC);
    checkApi($planifiee['date_debut_planifiee'] !== null && $planifiee['date_fin_planifiee'] !== null,
        'Validating places the request in the planning');
    checkApi((int) $planifiee['valide_par'] === $production->id, 'The decision is signed');

    // And it only happens once.
    checkApi(appel('/api/demandes/97-DP-0001', $db, $production, 'PATCH', ['statut' => 3, 'motif' => 'trop tard'])->statut() === 409,
        'A request already decided cannot be decided again');

    // Refusing keeps the reason, where the requester will read it.
    $refusee = appel('/api/demandes/97-DP-0004', $db, $production, 'PATCH', ['statut' => 3, 'motif' => 'Plans manquants']);
    checkApi($refusee->statut() === 200, 'A pending request can be refused');
    checkApi($refusee->corps()['donnees']['statut']['id'] === Demande::REFUSEE, 'The answer carries the refusal');
    $motif = $db->prepare('SELECT commentaire_validation FROM demande_prefabrication
        WHERE id = (SELECT id_demande FROM prefa_reference WHERE reference = ?)');
    $motif->execute(['97-DP-0004']);
    checkApi($motif->fetchColumn() === 'Plans manquants', 'The reason is kept');

    // Both decisions are announced to the open screens.
    $annonces = $db->query("SELECT type FROM evenement WHERE type IN ('demande.validee', 'demande.refusee')
        ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    checkApi(count($annonces) === 2, 'Each decision writes its event');

    // ---------------------------------------------------------------- the deletions

    checkApi(appel('/api/demandes/97-DP-0005', $db, $atelier, 'DELETE')->statut() === 403,
        'Workshop staff delete nothing, even their own request');
    checkApi(appel('/api/demandes/97-DP-0003', $db, $affaire, 'DELETE')->statut() === 404,
        'The affair manager cannot delete what he cannot see');
    checkApi(appel('/api/demandes/97-DP-0002', $db, $affaire, 'DELETE')->statut() === 409,
        'A validated request is already at the workshop: its owner cannot delete it');

    $aEffacer = addDemande($db, $affaire->id, 'Pont roulant', 1, false, '97-DP-0006');
    checkApi(appel('/api/demandes/97-DP-0006', $db, $affaire, 'DELETE')->statut() === 200,
        'The owner deletes his own pending request');
    $reste = $db->prepare('SELECT COUNT(*) FROM demande_prefabrication WHERE id = ?');
    $reste->execute([$aEffacer]);
    checkApi((int) $reste->fetchColumn() === 0, 'And it is really gone');

    // The production manager is not held by the status: he cleans up after everyone.
    checkApi(appel('/api/demandes/97-DP-0002', $db, $production, 'DELETE')->statut() === 200,
        'The production manager deletes a validated request');

    // Methods the resource does not take.
    checkApi(appel('/api/demandes', $db, $production, 'PATCH', ['statut' => 2])->statut() === 405,
        'A whole list cannot be decided in one call');
    checkApi(appel('/api/demandes/97-DP-0003', $db, $production, 'PUT')->statut() === 405,
        'PUT is not one of the methods');

    // The rule the screens use must be the one the model carries.
    require_once $root . '/dashboard/includes/demande/prefa_visibilite.php';
    ['condition' => $condition] = Demande::clauseVisible($atelier);
    checkApi(prefaDemandesVisibles($atelier)['where'] === ' WHERE ' . $condition, 'Screens and model share one rule');
} finally {
    $db->rollBack();
}
checkApi($avant === (int) $db->query('SELECT COUNT(*) FROM demande_prefabrication')->fetchColumn(),
    'Fixtures must leave no request behind');
echo "Visibility, filters, pagination, decisions, deletions and rollback: OK\n";
