<?php

/** @var array<string, mixed> $row */
/** @var bool $canViewAllPrefa */
/** @var bool $isAdmin */
/** @var array<int, array<int, array<string, mixed>>> $attachmentsByRequest */
/** @var array<int, array<int, array<string, mixed>>> $qmosByRequest */
/** @var array<int, array<int, array<string, mixed>>> $dmosByRequest */
/** @var array<int, array<int, array<string, mixed>>> $materielByRequest */
/** @var array<int, array<int, array<string, mixed>>> $ccpuByRequest */

$requestId = (int) $row['id'];

$documentGroups = [
    [
        'title' => 'Plans BPE / ISO',
        'files' => $attachmentsByRequest[$requestId] ?? [],
        'action' => 'download_prefa_attachment.php',
        'legacy' => $row['plan_bpe_iso'] ?? '',
    ],
    [
        'title' => 'QMOS',
        'files' => $qmosByRequest[$requestId] ?? [],
        'action' => 'download_qmos.php',
        // La colonne s'appelle bien « espace QMOS » : l'espace fait partie du nom en base.
        // Sans lui la clé n'existe pas, et l'ancienne référence disparaît sans un mot.
        'legacy' => $row[' QMOS'] ?? '',
    ],
    [
        'title' => 'DMOS',
        'files' => $dmosByRequest[$requestId] ?? [],
        'action' => 'download_dmos.php',
        'legacy' => '',
    ],
    [
        'title' => 'Commande matériel',
        'files' => $materielByRequest[$requestId] ?? [],
        'action' => 'download_materiel.php',
        'legacy' => '',
    ],
    [
        'title' => 'CCPU',
        'files' => $ccpuByRequest[$requestId] ?? [],
        'action' => 'download_ccpu.php',
        'legacy' => '',
        // Sous chaque certificat, les coulées que la lecture automatique y a trouvées.
        'ccpu' => true,
    ],
];

$facts = [
    'Nom de la demande' => $row['nom_affaire'] ?: 'Non renseigné',
    'Statut' => $row['libelle'],
    'Pouces ISO' => $row['pouces_total_iso'],

    'Durée estimée (jours)' =>
        number_format((float) $row['heures_chiffrees'] / 24, 2, ',', ' ') . ' j',

    'Date limite de livraison de la préfabrication' =>
        prefaFormatDate($row['date_livraison_prevue']),

    'Matière' =>
        $row['matiere_libelle'] ?? 'Non précisée',

    'Passivation' =>
        $row['passivation_libelle'] ?? 'Aucune',

    'CND' =>
        !empty($row['CDN'])
            ? $row['controles_cdn'] . ' %'
            : 'Non',

    'Radiographie RT' =>
        !empty($row['RT'])
            ? $row['controles_rt'] . ' %'
            : 'Non',

    'Ressuage PT' =>
        !empty($row['PT'])
            ? $row['controles_pt'] . ' %'
            : 'Non',

    'Revêtement' =>
        !empty($row['revetement'])
            ? ($row['commentaire_revetement'] ?: 'Oui')
            : 'Non',

    'Création' =>
        prefaFormatDate($row['date_creation'], true),

    'Mise à jour' =>
        prefaFormatDate($row['DateUpdate'], true),
];

// Qui s'occupe de la demande : visible dans la fiche depuis que la colonne du tableau
// a ete retiree. Seules les demandes validees passent par l'atelier.
if ((int) $row['id_statut'] === 2) {
    $facts['Prise en charge'] = !empty($row['prise_en_charge_nom'])
        ? $row['prise_en_charge_nom']
        : 'À prendre en charge';
}

if ($canViewAllPrefa) {
    $facts['Demandeur'] = trim(
        ($row['prenom'] ?? '') . ' ' .
        ($row['name'] ?? '')
    );
}

// « Décision » restait flou : on nomme le sens de la décision, qui dépend du statut.
// Validée et refusée alimentent les mêmes colonnes, d'où l'aiguillage.
[$auteurLabel, $dateLabel] = match ((int) $row['id_statut']) {
    2 => ['Validée par', 'Validée le'],
    3 => ['Refusée par', 'Refusée le'],
    default => ['Décision par', 'Décision du'],
};

if (!empty($row['valideur_prenom']) || !empty($row['valideur_name'])) {
    $facts[$auteurLabel] = trim(
        ($row['valideur_prenom'] ?? '') . ' ' .
        ($row['valideur_name'] ?? '')
    );
}

if (!empty($row['date_validation'])) {
    $facts[$dateLabel] = prefaFormatDate(
        $row['date_validation'],
        true
    );
}

$planningFacts = [
    'Début planifié' =>
        !empty($row['date_debut_planifiee'])
            ? prefaFormatDate($row['date_debut_planifiee'])
            : 'Non planifié',

    'Fin planifiée' =>
        !empty($row['date_fin_planifiee'])
            ? prefaFormatDate($row['date_fin_planifiee'])
            : 'Non planifiée',

];

$isRefused = (int) $row['id_statut'] === 3;
$decisionComment = trim((string) ($row['commentaire_validation'] ?? ''));

?>

<!-- En-tête -->
<div class="prefa-detail-heading">
    <div>
        <h2>
            Détails de la demande
            <span><?= prefaEscape(prefaReferenceComplete($requestId)) ?></span>
        </h2>

        <p>
            Informations techniques et documents associés
        </p>
    </div>

    <div class="prefa-detail-actions">
        <?php if ((int) $row['id_statut'] === 2 && $canManageWorkshop): ?>
            <button type="button" class="btn-goto-plans-iso" data-request-id="<?= $requestId ?>">
                Voir les affectations ↗
            </button>
        <?php endif; ?>
        <button type="button" class="prefa-detail-close" data-close-target="prefa-detail-<?= $requestId ?>" aria-label="Fermer le volet">✕</button>
    </div>
</div>


<!-- Informations générales -->
<dl class="prefa-facts">

    <?php foreach ($facts as $label => $value): ?>

        <div>
            <dt><?= prefaEscape($label) ?></dt>
            <dd><?= prefaEscape($value) ?></dd>
        </div>

    <?php endforeach; ?>

</dl>


<!-- Documents -->
<div class="prefa-document-groups">

    <?php foreach ($documentGroups as $group): ?>

        <section class="prefa-document-group<?= !empty($group['ccpu']) ? ' prefa-document-group-ccpu' : '' ?>">

            <h3>
                <?= prefaEscape($group['title']) ?>
                <span><?= count($group['files']) ?></span>
            </h3>


            <?php if (!empty($group['files'])): ?>

                <ul class="prefa-document-list">

                    <?php foreach ($group['files'] as $document): ?>

                        <?php
                        $documentName =
                            $document['name']
                            ?? $document['nom']
                            ?? 'Document';

                        $documentKey =
                            $document['key']
                            ?? $document['id'];

                        $isPdf =
                            strtolower(
                                pathinfo(
                                    $documentName,
                                    PATHINFO_EXTENSION
                                )
                            ) === 'pdf';

                        $documentUrl =
                            'actions/'
                            . $group['action']
                            . '?id='
                            . $requestId
                            . '&file='
                            . rawurlencode((string) $documentKey);
                        ?>

                        <li>

                            <a
                                class="prefa-document-link<?= $isPdf ? ' prefa-pdf-link' : '' ?>"
                                href="<?= prefaEscape($documentUrl) ?>"
                                data-name="<?= prefaEscape($documentName) ?>"
                                <?= $isPdf ? 'aria-haspopup="dialog"' : '' ?>
                            >

                                <span
                                    class="prefa-document-type"
                                    aria-hidden="true"
                                >
                                    <?= $isPdf ? 'PDF' : 'DOC' ?>
                                </span>

                                <span class="prefa-document-text">

                                    <strong>
                                        <?= prefaEscape($documentName) ?>
                                    </strong>

                                    <small>
                                        <?= $isPdf
                                            ? 'Ouvrir l’aperçu'
                                            : 'Télécharger le document'
                                        ?>
                                    </small>

                                </span>

                                <span
                                    class="prefa-document-arrow"
                                    aria-hidden="true"
                                >
                                    ↗
                                </span>

                            </a>

                            <?php if (!empty($group['ccpu'])) echo ccpuRendu($document, $_SESSION['prefa_csrf']); ?>

                        </li>

                    <?php endforeach; ?>

                </ul>

            <?php else: ?>

                <p class="prefa-no-document">
                    <?= prefaEscape(
                        $group['legacy'] ?: 'Aucun document'
                    ) ?>
                </p>

            <?php endif; ?>

        </section>

    <?php endforeach; ?>

</div>


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
