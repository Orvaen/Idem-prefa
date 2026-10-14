<?php

/** @var array<string, mixed> $row */
/** @var bool $canViewAllPrefa */
/** @var bool $isAdmin */
/** @var array<int, array<int, array<string, mixed>>> $attachmentsByRequest */
/** @var array<int, array<int, array<string, mixed>>> $qmosByRequest */
/** @var array<int, array<int, array<string, mixed>>> $dmosByRequest */
/** @var array<int, array<int, array<string, mixed>>> $materielByRequest */
/** @var array<int, array<int, array<string, mixed>>> $ccpuByRequest */
/** @var array<int, list<array<string, mixed>>> $commandesByRequest */

$requestId = (int) $row['id'];

// Les cinq familles sont déclarées dans prefa_documents.php : la fiche les affiche,
// l'action de dépôt y écrit, et aucune des deux n'a sa propre idée de ce qui existe.
$documentsParFamille = [
    'plan' => $attachmentsByRequest[$requestId] ?? [],
    'qmos' => $qmosByRequest[$requestId] ?? [],
    'dmos' => $dmosByRequest[$requestId] ?? [],
    'materiel' => $materielByRequest[$requestId] ?? [],
    'ccpu' => $ccpuByRequest[$requestId] ?? [],
];

// Ce qui reste des demandes d'avant les tables de documents : un nom de fichier, écrit
// dans une colonne de la demande. La colonne s'appelle bien « espace QMOS » — l'espace
// fait partie du nom en base, et sans lui la clé n'existe pas.
$documentsAnciens = [
    'plan' => $row['plan_bpe_iso'] ?? '',
    'qmos' => $row[' QMOS'] ?? '',
];

$documentGroups = [];
foreach (prefaDocumentFamilles() as $famille => $declaration) {
    $documentGroups[] = $declaration + [
        'cle' => $famille,
        'files' => $documentsParFamille[$famille],
        'legacy' => $documentsAnciens[$famille] ?? '',
    ];
}

// Déposer depuis la fiche plutôt que par « Modifier » : même droit, mais la demande ne
// repart pas en attente de validation pour un certificat arrivé après coup.
$peutDeposer = prefaPeutDeposer($row, $canEditPrefa, $isAdmin, (int) $actor['id']);

$statut = (int) $row['id_statut'];
$estValidee = $statut === 2;

// Ce que l'atelier déclare et que personne n'a encore tranché. La fiche est l'écran où l'on
// arrive en suivant une affaire : on y apprenait tout d'une demande, sauf qu'un soudeur
// attendait. La décision se prend donc aussi d'ici, et c'est la même qu'ailleurs.
$avancementsAValider = [];
if ($estValidee && $canManageWorkshop) {
    require_once __DIR__ . '/../includes/avancement.php';
    require_once __DIR__ . '/../includes/atelier.php';
    $attente = $db->prepare("SELECT h.*, a.id AS id_affectation, a.type_affectation, e.reference,
            TRIM(CONCAT(u.prenom, ' ', u.name)) AS auteur
        FROM avancement_atelier h
        JOIN affectation_atelier a ON a.id = h.id_affectation
        JOIN element_atelier e ON e.id = a.id_element
        JOIN Utilisateur u ON u.id = h.id_utilisateur
        WHERE e.id_demande = ? AND h.etat = 'propose' AND a.actif = 1
        ORDER BY e.reference, a.type_affectation");
    $attente->execute([$requestId]);
    $avancementsAValider = $attente->fetchAll();
}
$isRefused = $statut === 3;

$oui = static fn (string $champ, string $pourcentage): string =>
    !empty($row[$champ]) ? (string) $row[$pourcentage] . ' %' : 'Non';

// Ce qu'on vient chercher en premier, et qu'on doit lire sans chercher : en grand, en haut.
//
// Chacun porte son explication. « Livraison préfa » ne disait pas de quelle livraison il
// s'agissait, ni si le chiffre à côté en venait ; et la durée tombait du ciel. Le taux est
// relu sur la demande elle-même — heures ÷ pouces — et non dans le paramétrage : celui-ci
// a pu changer depuis, et c'est le calcul de cette demande-là qu'on explique.
$pouces = (int) $row['pouces_total_iso'];
$heures = (float) $row['heures_chiffrees'];
$taux = $pouces > 0 ? $heures / $pouces : 0.0;

$nombre = static fn (float $valeur): string => rtrim(rtrim(number_format($valeur, 2, ',', ' '), '0'), ',');

$chiffres = [
    [
        'libelle' => 'Pouces ISO',
        'valeur' => (string) $pouces,
        'aide' => 'Total des pouces relevés sur l’ISO, saisi dans la demande. C’est lui qui donne la durée estimée.',
    ],
    [
        'libelle' => 'Durée estimée',
        'valeur' => $nombre($heures / 24) . ' j',
        'aide' => $pouces > 0
            ? $pouces . ' pouce' . ($pouces > 1 ? 's' : '') . ' × ' . $nombre($taux) . ' h par pouce = '
                . $nombre($heures) . ' h de fabrication, soit ' . $nombre($heures / 24)
                . ' j à 24 h par jour. Le temps par pouce est réglé dans les paramètres.'
            : 'Aucun pouce saisi : la durée ne peut pas être estimée.',
    ],
    [
        'libelle' => 'Livraison préfa',
        'valeur' => prefaFormatDate($row['date_livraison_prevue']),
        'aide' => 'Date limite à laquelle la préfabrication doit être livrée. Elle est demandée par le chargé d’affaire, elle ne se calcule pas : c’est la durée estimée qui dit si elle est tenable.',
    ],
];

// Le reste, rangé par ce qu'il décrit. Seize valeurs en une grille de quatre, c'était une
// grille à lire ; trois rubriques nommées, c'est une fiche à parcourir.
$rubriques = [
    'Fabrication' => [
        'Matière' => $row['matiere_libelle'] ?? 'Non précisée',
        'Passivation' => $row['passivation_libelle'] ?? 'Aucune',
        'Revêtement' => !empty($row['revetement']) ? ($row['commentaire_revetement'] ?: 'Oui') : 'Non',
    ],
    'Contrôles' => [
        'CND' => $oui('CDN', 'controles_cdn'),
        'Radiographie RT' => $oui('RT', 'controles_rt'),
        'Ressuage PT' => $oui('PT', 'controles_pt'),
    ],
    'Suivi' => [
        'Création' => prefaFormatDate($row['date_creation'], true),
        'Mise à jour' => prefaFormatDate($row['DateUpdate'], true),
    ],
];

// Une demande validée a un planning d'atelier, les autres non : la rubrique ne l'annonce
// que lorsqu'il peut exister.
if ($estValidee) {
    $rubriques['Suivi']['Début planifié'] = !empty($row['date_debut_planifiee']) ? prefaFormatDate($row['date_debut_planifiee']) : 'Non planifié';
    $rubriques['Suivi']['Fin planifiée'] = !empty($row['date_fin_planifiee']) ? prefaFormatDate($row['date_fin_planifiee']) : 'Non planifiée';
}

// Qui l'a déposée, et qui a tranché : une phrase sous le titre, pas quatre cases.
$deposant = $canViewAllPrefa ? trim(($row['prenom'] ?? '') . ' ' . ($row['name'] ?? '')) : '';
$decideur = trim(($row['valideur_prenom'] ?? '') . ' ' . ($row['valideur_name'] ?? ''));
$parcours = [];
$parcours[] = ($deposant !== '' ? 'Déposée par ' . $deposant : 'Déposée')
    . ($row['date_creation'] ? ' le ' . prefaFormatDate($row['date_creation']) : '');
if ($decideur !== '' && in_array($statut, [2, 3], true)) {
    $parcours[] = ($estValidee ? 'validée' : 'refusée') . ' par ' . $decideur
        . (!empty($row['date_validation']) ? ' le ' . prefaFormatDate($row['date_validation']) : '');
}

$decisionComment = trim((string) ($row['commentaire_validation'] ?? ''));

?>

<!-- En-tête : ce qui identifie la demande, et où elle en est -->
<header class="prefa-detail-heading fiche-entete">
    <div class="fiche-identite">
        <p class="fiche-surtitre">Demande de préfabrication</p>
        <h2 class="fiche-titre">
            <?= prefaEscape(prefaReference($requestId)) ?>
            <?php if ($row['nom_affaire']): ?><span class="fiche-affaire"><?= prefaEscape($row['nom_affaire']) ?></span><?php endif; ?>
        </h2>
        <p class="fiche-parcours"><?= prefaEscape(implode(' · ', $parcours)) ?></p>
    </div>

    <div class="prefa-detail-actions">
        <span class="prefa-status status-<?= $statut ?>"><?= prefaEscape($row['libelle']) ?></span>
        <?php if ($estValidee && $canManageWorkshop): ?>
            <button type="button" class="btn-goto-plans-iso" data-request-id="<?= $requestId ?>">
                Voir les affectations ↗
            </button>
        <?php endif; ?>
        <button type="button" class="prefa-detail-close" data-close-target="prefa-detail-<?= $requestId ?>" aria-label="Fermer le volet">✕</button>
    </div>
</header>

<?php if ($avancementsAValider): ?>
    <?php // Au-dessus des chiffres : c'est ce qui attend quelqu'un, et le reste de la fiche
          // ne fait que raconter. Les travaux d'un même plan se suivent, un bloc chacun. ?>
    <section class="fiche-a-valider" aria-label="Avancements déclarés par l’atelier">
        <?php foreach ($avancementsAValider as $declaration): ?>
            <div class="atelier-declaration">
                <p class="atelier-declaration-titre">Avancement déclaré, à valider</p>
                <p class="atelier-declaration-chiffre"><?= prefaEscape(avancementPhrase($declaration)) ?></p>
                <p class="atelier-muted">
                    <?= prefaEscape($declaration['reference']) ?> ·
                    <?= prefaEscape($declaration['auteur']) ?> ·
                    <?= prefaEscape(atelierLocalDateTime($declaration['date_saisie'])) ?>
                </p>
                <?php if ($declaration['commentaire']): ?><p class="atelier-note"><?= prefaEscape($declaration['commentaire']) ?></p><?php endif; ?>
                <form class="atelier-action atelier-declaration-actions" data-endpoint="update_atelier.php">
                    <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                    <input type="hidden" name="id" value="<?= $requestId ?>">
                    <input type="hidden" name="operation" value="valider">
                    <input type="hidden" name="affectation" value="<?= (int) $declaration['id_affectation'] ?>">
                    <input type="hidden" name="declaration" value="<?= (int) $declaration['id'] ?>">
                    <button type="submit" name="decision" value="valider" title="L’avancement du plan prendra ce chiffre.">Valider</button>
                    <button type="submit" name="decision" value="refuser" class="bouton-discret" title="L’avancement ne change pas ; la personne affectée pourra déclarer autre chose.">Refuser</button>
                    <span class="atelier-message" role="status" aria-live="polite"></span>
                </form>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<div class="fiche-corps">

<div class="fiche-colonne fiche-colonne-infos">

<!-- Les trois chiffres -->
<dl class="fiche-chiffres">
    <?php foreach ($chiffres as $chiffre): ?>
        <div>
            <dt>
                <?= prefaEscape($chiffre['libelle']) ?>
                <span class="fiche-info" tabindex="0" role="img"
                      title="<?= prefaEscape($chiffre['aide']) ?>"
                      aria-label="<?= prefaEscape($chiffre['libelle'] . ' : ' . $chiffre['aide']) ?>">i</span>
            </dt>
            <dd><?= prefaEscape($chiffre['valeur']) ?></dd>
        </div>
    <?php endforeach; ?>
</dl>

<!-- Les rubriques -->
<?php foreach ($rubriques as $titre => $faits): ?>
    <section class="fiche-rubrique">
        <h3 class="fiche-eyebrow"><?= prefaEscape($titre) ?></h3>
        <dl class="fiche-faits">
            <?php foreach ($faits as $libelle => $valeur): ?>
                <div>
                    <dt><?= prefaEscape($libelle) ?></dt>
                    <dd<?= in_array($valeur, ['Non', 'Aucune', 'Non précisée', 'Non planifié', 'Non planifiée', 'Non renseignée'], true) ? ' class="est-vide"' : '' ?>><?= prefaEscape($valeur) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endforeach; ?>

</div><!-- /colonne infos -->

<div class="fiche-colonne fiche-colonne-documents">

<!-- Documents -->
<section class="fiche-rubrique fiche-documents">
    <h3 class="fiche-eyebrow">Documents</h3>

    <?php foreach ($documentGroups as $group):
        $famille = $group['cle'];
        // Une famille chargée s'ouvre repliée : cinq certificats dépliés repoussent les
        // quatre familles suivantes hors de l'écran, et c'est précisément ce qu'on vient
        // d'éviter en passant la fiche en pleine page.
        $ouverte = count($group['files']) <= FICHE_DOCUMENTS_DEPLIES;
    ?>
        <?php // La classe prefa-document-group reste : c'est elle que le dépôt cherche pour
              // savoir dans quelle liste ranger ce qui vient d'arriver. ?>
        <details class="prefa-document-group fiche-famille<?= $famille === 'ccpu' ? ' prefa-document-group-ccpu' : '' ?>"<?= $ouverte ? ' open' : '' ?>>

            <summary class="fiche-famille-titre">
                <span class="fiche-famille-nom"><?= prefaEscape($group['titre']) ?></span>
                <span class="prefa-document-compte"><?= count($group['files']) ?></span>
            </summary>

            <?php if ($peutDeposer): $champ = 'depot-' . $requestId . '-' . $famille; ?>
                <div class="prefa-depot-document">
                    <span class="prefa-depot-mot" role="status" aria-live="polite"></span>
                    <input type="file" id="<?= prefaEscape($champ) ?>" class="prefa-depot-champ"
                           accept="application/pdf,.pdf" multiple
                           data-demande="<?= $requestId ?>"
                           data-type="<?= prefaEscape($famille) ?>"
                           data-csrf="<?= prefaEscape(Session::jeton()) ?>">
                    <label class="prefa-depot-bouton" for="<?= prefaEscape($champ) ?>">
                        <span aria-hidden="true">+</span> Déposer
                    </label>
                </div>
            <?php endif; ?>

            <ul class="prefa-document-list"<?= $group['files'] ? '' : ' hidden' ?>>
                <?php foreach ($group['files'] as $document) {
                    echo prefaDocumentLigne($requestId, $group, $document, $canViewAllPrefa);
                } ?>
            </ul>

            <p class="prefa-no-document"<?= $group['files'] ? ' hidden' : '' ?>>
                <?= prefaEscape($group['legacy'] ?: 'Aucun document') ?>
            </p>

        </details>
    <?php endforeach; ?>

    <?php
    // Le matériel reçu, à la suite des documents : il se relève sur le bon du fournisseur,
    // qui est déposé juste au-dessus. Réservé à ceux qui tiennent les PV, puisque c'est
    // là que ces lignes sont saisies ; le chargé d'affaire suit ses réceptions sur sa
    // page de PV.
    if ($canFillPv && !empty($commandesByRequest[$requestId])) {
        $commandes = $commandesByRequest[$requestId];
        require __DIR__ . '/materiel_commande.php';
    }
    ?>
</section>


</div><!-- /colonne documents -->

</div><!-- /corps -->

<!-- Décision -->
<?php if ($decisionComment !== ''): ?>

    <div
        class="prefa-decision-comment<?= $isRefused ? ' is-refusal' : '' ?>"
    >

        <strong>
            <?= $isRefused
                ? 'Justification du refus'
                : 'Justification / Commentaire de décision'
            ?>
        </strong>

        <p>
            <?= prefaEscape($decisionComment) ?>
        </p>

    </div>

<?php endif; ?>
