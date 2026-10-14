<?php

/**
 * Les documents déposés sur un PV.
 *
 * Le même balisage sert à la lecture et à la saisie : seule la croix « Retirer » distingue
 * les deux, et les écrire séparément les faisait déjà diverger — la liste en lecture et
 * celle du formulaire n'ouvraient pas le document de la même façon.
 *
 * Un bon de commande déposé ici se recopie dans le tableau juste au-dessus. Le lire
 * supposait d'ouvrir un onglet : chaque document porte donc son aperçu, déplié sur place.
 * Le document n'est chargé qu'au dépli — sinon cinq PDF de trois mégaoctets partiraient
 * avec la page.
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
        $estImage = in_array($extension, ['png', 'jpg', 'jpeg'], true);
        // L'adresse reste nue : le visualiseur y ajoute « preview » de son côté, et garde
        // l'adresse telle quelle pour le bouton « Télécharger ».
        $adresse = 'actions/download_pv_document.php?id=' . (int) $document['id'];
        ?>
        <li class="pv-document" data-document="<?= (int) $document['id'] ?>" data-nom="<?= prefaEscape($document['nom']) ?>" data-pdf="<?= $estPdf ? '1' : '0' ?>">
            <div class="pv-document-rang">
                <a class="prefa-document-link<?= $estPdf ? ' prefa-pdf-link' : '' ?>"
                   href="<?= prefaEscape($adresse) ?>"
                   data-name="<?= prefaEscape($document['nom']) ?>"
                   <?= $estPdf ? 'aria-haspopup="dialog"' : 'target="_blank" rel="noopener"' ?>>
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path d="M14 3v5h5" />
                    </svg>
                    <?= prefaEscape($document['nom']) ?>
                </a>

                <?php if ($estPdf || $estImage): ?>
                    <button type="button" class="pv-apercu-bascule bouton-discret"
                            aria-expanded="false"
                            data-src="<?= prefaEscape($adresse . '&preview=1') ?>"
                            data-image="<?= $estImage ? '1' : '0' ?>"
                            data-nom="<?= prefaEscape($document['nom']) ?>">Aperçu</button>
                <?php endif; ?>

                <?php if ($modifiable): ?>
                    <button type="button" class="pv-retirer-document bouton-discret" data-demande="<?= $id ?>" data-type="<?= prefaEscape($type) ?>" data-document="<?= (int) $document['id'] ?>" data-nom="<?= prefaEscape($document['nom']) ?>" aria-label="Retirer <?= prefaEscape($document['nom']) ?>">Retirer</button>
                <?php endif; ?>
            </div>

            <?php if ($estPdf || $estImage): ?>
                <div class="pv-apercu" hidden></div>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
