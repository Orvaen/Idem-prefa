<?php

/**
 * Les photos de la préfa, en vignettes.
 *
 * Le cahier des charges les met au rang des PV : on photographie la préfabrication finie.
 * Dans une liste de documents, une photo n'était qu'un nom de fichier à déplier ; ici on la
 * voit, et un clic l'ouvre en grand dans un nouvel onglet. Les vignettes se chargent à
 * l'approche (« loading=lazy ») : un volet fermé ne télécharge rien.
 *
 * @var array<string, mixed> $pv
 * @var int $id         identifiant de la demande
 * @var string $type    type du PV
 * @var bool $modifiable
 */
$modifiable = $modifiable ?? false;
?>
<ul class="pv-photos">
    <?php foreach ($pv['photos'] as $photo):
        $adresse = 'actions/download_pv_document.php?id=' . (int) $photo['id'] . '&preview=1'; ?>
        <li class="pv-photo">
            <a href="<?= prefaEscape($adresse) ?>" target="_blank" rel="noopener" title="<?= prefaEscape($photo['nom']) ?>">
                <img src="<?= prefaEscape($adresse) ?>" alt="<?= prefaEscape($photo['nom']) ?>" loading="lazy">
            </a>
            <?php if ($modifiable): ?>
                <button type="button" class="pv-retirer-document pv-photo-retirer"
                        data-demande="<?= $id ?>" data-type="<?= prefaEscape($type) ?>"
                        data-document="<?= (int) $photo['id'] ?>" data-nom="<?= prefaEscape($photo['nom']) ?>"
                        aria-label="Retirer la photo <?= prefaEscape($photo['nom']) ?>" title="Retirer">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
