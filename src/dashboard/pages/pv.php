<?php
// Les PV de préfabrication : un écran sur le modèle des autres listes — en-tête, recherche,
// onglets comptés, groupes, et un volet par demande où se renseignent ses trois PV.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/pv.php';
require_once __DIR__ . '/../includes/materiel/commandes.php';
if (!$canViewPv) prefaError(403, 'Cette page est réservée à l’atelier et aux demandeurs.');

// Le chargé d'affaire suit ses PV sans les renseigner : la saisie appartient au chef
// d'atelier (et à l'administrateur, qui a accès à tout).
$pvLectureSeule = !$canFillPv;

// Le demandeur ne voit que ses propres affaires ; l'atelier voit tout le parc.
$sql = 'SELECT d.id, d.nom_affaire, d.urgent, d.id_passivation, tp.libelle AS passivation_libelle,
        d.date_validation, u.prenom AS demandeur_prenom, u.name AS demandeur_nom,
        v.prenom AS valideur_prenom, v.name AS valideur_nom
    FROM demande_prefabrication d
    LEFT JOIN Utilisateur u ON u.id = d.idUsers
    LEFT JOIN Utilisateur v ON v.id = d.valide_par
    LEFT JOIN type_passivation tp ON tp.id = d.id_passivation
    WHERE d.id_statut = 2';
$parametres = [];
if ($pvLimiteAuDemandeur) {
    $sql .= ' AND d.idUsers = ?';
    $parametres[] = $actor['id'];
}
$stmt = $db->prepare($sql . ' ORDER BY d.urgent DESC, d.id DESC');
$stmt->execute($parametres);
$demandes = $stmt->fetchAll();

$types = pvTypes();
$descriptions = pvDescriptions();
$statuts = pvStatuts();
// L'en-tête de colonne dit le PV en un mot ou deux : le libellé entier vit dans le volet.
$enTetes = ['reception_materiel' => 'Réception', 'fin_fabrication' => 'Fin de fab. et conformité', 'passivation' => 'Passivation'];

// Le suivi de production de chaque demande, en deux requêtes pour toute la page : le PV
// de fin de fabrication se renseigne en sachant où en sont les plans et les soudures.
$production = [];
if ($demandes) {
    $ids = array_map(static fn (array $d): int => (int) $d['id'], $demandes);
    $places = implode(',', array_fill(0, count($ids), '?'));
    $plans = $db->prepare("SELECT id_demande, COUNT(*) AS n FROM element_atelier WHERE id_demande IN ($places) GROUP BY id_demande");
    $plans->execute($ids);
    foreach ($plans->fetchAll() as $ligne) $production[(int) $ligne['id_demande']]['plans'] = (int) $ligne['n'];
    $soudures = $db->prepare("SELECT e.id_demande, COUNT(*) AS n FROM plan_soudure s
        JOIN element_atelier e ON e.id = s.id_element
        WHERE e.id_demande IN ($places) AND (TRIM(s.type_soudure) <> '' OR s.date_soudure IS NOT NULL)
        GROUP BY e.id_demande");
    $soudures->execute($ids);
    foreach ($soudures->fetchAll() as $ligne) $production[(int) $ligne['id_demande']]['soudures'] = (int) $ligne['n'];
}

// Un seul passage par demande, et le rangement en deux groupes : ce qui reste à
// renseigner d'abord, ce qui est complet ensuite.
$groupes = [
    'a-renseigner' => ['libelle' => 'À renseigner', 'aide' => 'au moins un PV à faire', 'demandes' => []],
    'completes' => ['libelle' => 'Complètes', 'aide' => 'tous les PV tranchés', 'demandes' => []],
];
$pvParDemande = [];
$resteAFaire = 0;
foreach ($demandes as $demande) {
    $id = (int) $demande['id'];
    $pvs = pvParDemande($db, $id, $demande['id_passivation'] !== null);
    $pvParDemande[$id] = $pvs;
    $restants = pvAvancement($pvs)['restants'];
    $resteAFaire += $restants;
    $groupes[$restants ? 'a-renseigner' : 'completes']['demandes'][] = $demande;
}

/** Le signe d'un état : pointillé qui attend, coche quand c'est fait, tiret quand ça ne s'applique pas. */
$iconeEtat = static function (string $statut): string {
    return match ($statut) {
        'fait' => '<svg class="pv-etat-icone" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="7" fill="currentColor" />'
            . '<path d="m5 8.2 2 2 4-4.2" fill="none" stroke="var(--surface)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>',
        'sans_objet' => '<svg class="pv-etat-icone" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" stroke-width="1.6" />'
            . '<path d="M5.5 8h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /></svg>',
        default => '<svg class="pv-etat-icone" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">'
            . '<circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-dasharray="2.6 2.1" /></svg>',
    };
};

$signature = static function (array $pv): string {
    if (!$pv['date_maj']) return '';
    return 'Renseigné par ' . trim(($pv['auteur_prenom'] ?? '') . ' ' . ($pv['auteur_nom'] ?? ''))
        . ' le ' . prefaFormatDate($pv['date_maj'], true);
};
?>
<section class="page prefa-page prefa-list atelier-page" id="pv-page" aria-labelledby="pv-page-title" data-densite="confortable">
    <div class="prefa-shell">

        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="pv-page-title">PV de préfabrication</h1>
                <p><?= $pvLimiteAuDemandeur
                    ? 'Réception matière, fin de fabrication et conformité, passivation : où en sont vos affaires.'
                    : 'Réception matière, fin de fabrication et conformité, passivation : demande par demande.' ?></p>
            </div>
            <div class="prefa-vue-outils">
                <div class="prefa-densite" role="group" aria-label="Densité du tableau">
                    <button type="button" class="prefa-densite-choix" data-densite="confortable"
                            aria-pressed="true" aria-label="Lignes confortables" title="Lignes confortables">
                        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                            <rect x="2" y="3" width="12" height="2.6" rx="1" />
                            <rect x="2" y="6.7" width="12" height="2.6" rx="1" />
                            <rect x="2" y="10.4" width="12" height="2.6" rx="1" />
                        </svg>
                    </button>
                    <button type="button" class="prefa-densite-choix" data-densite="compact"
                            aria-pressed="false" aria-label="Lignes compactes" title="Lignes compactes">
                        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                            <rect x="2" y="2.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="5.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="8.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="11.6" width="12" height="1.6" rx=".8" />
                        </svg>
                    </button>
                </div>
                <button type="button" class="pv-refresh pv-outil">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 3M4 16l2 3a7 7 0 0 1 12-2" />
                    </svg>
                    Actualiser
                </button>
            </div>
        </header>

        <div class="prefa-filters" role="search">
            <div class="filtres-recherche">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input id="pv-recherche" type="search" aria-label="Rechercher une demande"
                       placeholder="N° de demande, affaire, demandeur…">
            </div>
        </div>

        <?php if (!$demandes): ?>
            <p class="prefa-table-empty pv-vide"><?= $pvLimiteAuDemandeur
                ? 'Aucune de vos demandes n’est validée : il n’y a pas encore de PV.'
                : 'Aucune demande validée : il n’y a pas encore de PV à renseigner.' ?></p>
        <?php else: ?>

            <div class="prefa-onglets-barre">
                <div class="prefa-onglets" role="group" aria-label="Avancement des PV">
                    <button type="button" class="prefa-onglet pv-onglet" data-groupe="" aria-pressed="true">
                        Toutes <span class="prefa-onglet-compte"><?= count($demandes) ?></span>
                    </button>
                    <?php foreach ($groupes as $cle => $groupe): ?>
                        <button type="button" class="prefa-onglet pv-onglet" data-groupe="<?= $cle ?>" aria-pressed="false">
                            <?= prefaEscape($groupe['libelle']) ?>
                            <span class="prefa-onglet-compte"><?= count($groupe['demandes']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="prefa-list-summary">
                    <span class="<?= $resteAFaire > 0 && !$pvLectureSeule ? 'est-a-traiter' : '' ?>">
                        <strong><?= (int) $resteAFaire ?></strong> PV à faire
                    </span>
                </p>
            </div>

            <div class="prefa-table-scroll" role="region" aria-label="PV par demande" tabindex="0">
                <table class="prefa-table pv-table">
                    <thead><tr>
                        <th scope="col">Demande n°</th>
                        <th scope="col" class="prefa-affaire">Affaire</th>
                        <th scope="col">Demandeur</th>
                        <?php foreach ($enTetes as $libelle): ?><th scope="col"><?= prefaEscape($libelle) ?></th><?php endforeach; ?>
                        <th scope="col">Actions</th>
                    </tr></thead>

                <?php foreach ($groupes as $cleGroupe => $groupe): ?>
                    <?php if (!$groupe['demandes']) continue; ?>
                    <tbody class="prefa-groupe pv-groupe" data-groupe="<?= $cleGroupe ?>">
                        <tr>
                            <th scope="colgroup" colspan="7">
                                <span class="prefa-groupe-nom prefa-groupe-statut">
                                    <?= $iconeEtat($cleGroupe === 'completes' ? 'fait' : 'a_faire') ?>
                                    <?= prefaEscape($groupe['libelle']) ?>
                                </span>
                                <span class="prefa-groupe-compte"><?= count($groupe['demandes']) ?></span>
                                <span class="prefa-groupe-aide"><?= prefaEscape($groupe['aide']) ?></span>
                            </th>
                        </tr>
                    </tbody>

                <?php foreach ($groupe['demandes'] as $demande):
                    $id = (int) $demande['id'];
                    $pvs = $pvParDemande[$id];
                    $avancement = pvAvancement($pvs);
                    $prenom = trim((string) ($demande['demandeur_prenom'] ?? ''));
                    $nomFamille = trim((string) ($demande['demandeur_nom'] ?? ''));
                    $demandeurNom = trim($prenom . ' ' . $nomFamille);
                    $initiales = mb_strtoupper(mb_substr($prenom, 0, 1) . mb_substr($nomFamille, 0, 1));
                    $recherche = mb_strtolower(prefaReference($id) . ' ' . $id . ' ' . ($demande['nom_affaire'] ?? '') . ' ' . $demandeurNom);
                    $prod = $production[$id] ?? [];
                ?>
                    <tbody class="prefa-request pv-ligne<?= !empty($demande['urgent']) ? ' is-urgent-pending' : '' ?>"
                        data-groupe="<?= $cleGroupe ?>"
                        data-recherche="<?= prefaEscape($recherche) ?>">
                        <tr>
                            <td class="prefa-reference">
                                <span class="prefa-urgence">
                                    <?php if (!empty($demande['urgent'])): ?>
                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                                            stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Urgente">
                                            <title>Demande urgente</title>
                                            <path d="M13 3 4.5 13.5H11l-1 7.5 8.5-10.5H12z" />
                                        </svg>
                                    <?php endif; ?>
                                </span><?= prefaEscape(prefaReference($id)) ?>
                            </td>
                            <td class="prefa-affaire"><?= prefaEscape($demande['nom_affaire'] ?: '—') ?></td>
                            <td class="prefa-secondaire">
                                <span class="prefa-personne">
                                    <span class="prefa-avatar" aria-hidden="true"><?= prefaEscape($initiales) ?></span>
                                    <?= prefaEscape($demandeurNom ?: '—') ?>
                                </span>
                            </td>
                            <?php foreach ($types as $type => $libelle): $pv = $pvs[$type];
                                $titre = $libelle . ' : ' . mb_strtolower($statuts[$pv['statut']]) . ($pv['auto'] ? ' (aucune passivation demandée)' : '');
                                $modifiableEnLigne = !$pvLectureSeule && !$pv['auto']; ?>
                                <?php // L'état se change sur la ligne, comme un statut sur Linear : un clic,
                                      // un menu, c'est fait. Le volet ne sert plus qu'aux documents, aux
                                      // photos et au commentaire. La cellule porte « prefa-actions » pour
                                      // que le menu commun s'y ouvre et s'y referme comme sur les autres
                                      // lignes. ?>
                                <td class="pv-cellule<?= $modifiableEnLigne ? ' prefa-actions' : '' ?>">
                                    <?php if ($modifiableEnLigne): ?>
                                        <button type="button" class="prefa-actions-declencheur pv-etat-declencheur pv-etat pv-etat-<?= prefaEscape($pv['statut']) ?>"
                                                aria-haspopup="menu" aria-expanded="false"
                                                aria-label="<?= prefaEscape($titre) ?>. Changer l’état" title="Changer l’état">
                                            <?= $iconeEtat($pv['statut']) ?><?= prefaEscape($statuts[$pv['statut']]) ?>
                                            <svg class="pv-etat-chevron" aria-hidden="true" viewBox="0 0 16 16" width="10" height="10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m4 6 4 4 4-4" /></svg>
                                        </button>
                                        <div class="prefa-actions-menu pv-etat-menu" role="menu" hidden>
                                            <?php foreach ($statuts as $valeur => $texte): ?>
                                                <button type="button" role="menuitemradio" class="pv-etat-choix pv-etat-<?= $valeur ?>"
                                                        aria-checked="<?= $pv['statut'] === $valeur ? 'true' : 'false' ?>"
                                                        data-demande="<?= $id ?>" data-type="<?= prefaEscape($type) ?>"
                                                        data-statut="<?= $valeur ?>" data-revision="<?= (int) $pv['revision'] ?>"
                                                        data-csrf="<?= prefaEscape(Session::jeton()) ?>">
                                                    <?= $iconeEtat($valeur) ?><?= prefaEscape($texte) ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="pv-etat pv-etat-<?= prefaEscape($pv['statut']) ?>" title="<?= prefaEscape($titre) ?>">
                                            <?= $iconeEtat($pv['statut']) ?><?= prefaEscape($statuts[$pv['statut']]) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php $fichiers = count($pv['documents']) + count($pv['photos']); ?>
                                    <?php if ($fichiers): ?>
                                        <span class="pv-trombone" title="<?= $fichiers ?> fichier<?= $fichiers > 1 ? 's' : '' ?>">
                                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m21 11-8.6 8.6a5 5 0 0 1-7-7L14 4a3.3 3.3 0 0 1 4.7 4.7l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4L15.4 7" /></svg><?= $fichiers ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="prefa-actions">
                                <span class="prefa-decision">
                                    <button type="button" class="prefa-ligne-contour pv-ouvrir" aria-expanded="false" aria-controls="pv-detail-<?= $id ?>">
                                        Détails
                                    </button>
                                </span>
                            </td>
                        </tr>

                        <?php require __DIR__ . '/../partials/pv_volet.php'; ?>
                    </tbody>
                <?php endforeach; ?>
                <?php endforeach; ?>

                    <tbody class="pv-aucune" hidden>
                        <tr><td class="prefa-table-empty" colspan="7">Aucune demande ne correspond à cette recherche.</td></tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
