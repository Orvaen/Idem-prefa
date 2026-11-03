<?php

/**
 * Les documents déposés sur un PV, en puces : un trombone, le nom, la taille.
 *
 * Une puce s'ouvre d'un clic — un PDF dans le visualiseur de l'application, une image dans
 * un nouvel onglet — et, en saisie, une croix la retire. Le même balisage sert à la lecture
 * et à la saisie : seule la croix les distingue.
 *
 * Chaque puce garde « pv-document » et ses data- : la lecture d'un bon de commande, dans
 * commandes.js, y cherche les PDF du PV de réception.
 *
 * @var array<string, mixed> $pv
 * @var int $id         identifiant de la demande
 * @var string $type    type du PV
 * @var bool $modifiable
 */
$modifiable = $modifiable ?? false;
?>
<ul class="pv-documents">
    <?php foreach ($pv['documents'] as $document):
        $extension = strtolower(pathinfo((string) $document['nom'], PATHINFO_EXTENSION));
        $estPdf = $extension === 'pdf';
        // L'adresse reste nue : le visualiseur y ajoute « preview » de son côté, et garde
        // l'adresse telle quelle pour le bouton « Télécharger ».
        $adresse = 'actions/download_pv_document.php?id=' . (int) $document['id'];
        ?>
        <li class="pv-document" data-document="<?= (int) $document['id'] ?>" data-nom="<?= prefaEscape($document['nom']) ?>" data-pdf="<?= $estPdf ? '1' : '0' ?>">
            <a class="prefa-document-link<?= $estPdf ? ' prefa-pdf-link' : '' ?>"
               href="<?= prefaEscape($estPdf ? $adresse : $adresse . '&preview=1') ?>"
               data-name="<?= prefaEscape($document['nom']) ?>"
               <?= $estPdf ? 'data-sommaire="1"' : '' ?>
               <?= $estPdf ? 'aria-haspopup="dialog"' : 'target="_blank" rel="noopener"' ?>>
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m21 11-8.6 8.6a5 5 0 0 1-7-7L14 4a3.3 3.3 0 0 1 4.7 4.7l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4L15.4 7" /></svg>
                <span class="pv-document-nom"><?= prefaEscape($document['nom']) ?></span>
                <span class="pv-document-taille"><?= prefaEscape(pvTaille((int) $document['taille'])) ?></span>
            </a>
            <?php if ($modifiable): ?>
                <button type="button" class="pv-retirer-document" data-demande="<?= $id ?>" data-type="<?= prefaEscape($type) ?>"
                        data-document="<?= (int) $document['id'] ?>" data-nom="<?= prefaEscape($document['nom']) ?>"
                        aria-label="Retirer <?= prefaEscape($document['nom']) ?>" title="Retirer">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
