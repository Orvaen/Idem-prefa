<?php

/**
 * Bouton de prise en charge d'une demande validée, pour la colonne Actions.
 *
 * L'administrateur désigne un chef d'atelier dans une fenêtre ; le chef d'atelier
 * se désigne lui-même d'un clic. Rendu à la fois par la liste des demandes et par
 * la page Plans / ISO : les deux sont des points d'entrée légitimes.
 *
 * @var int $requestId
 * @var array<string, mixed> $row
 * @var array<int, array<string, mixed>> $chiefs liste vide hors administrateur
 * @var bool $isAdmin
 */
?>
<?php if ($isAdmin): ?>
    <button type="button" class="prefa-toggle atelier-open-take" data-dialog="atelier-take-dialog-<?= $requestId ?>" <?= $chiefs ? '' : 'disabled' ?>>Affecter un chef d’atelier</button>
    <dialog id="atelier-take-dialog-<?= $requestId ?>" class="atelier-plan-dialog" aria-labelledby="atelier-take-title-<?= $requestId ?>">
        <form class="atelier-action prefa-form" data-endpoint="save_atelier.php">
            <div class="atelier-plan-dialog-heading">
                <h2 id="atelier-take-title-<?= $requestId ?>">Affecter un chef d’atelier</h2>
                <button type="button" class="atelier-close-plan-edit modal-close" aria-label="Fermer"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg></button>
            </div>
            <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>"><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="operation" value="take">
            <p class="atelier-muted"><?= prefaEscape(prefaReferenceComplete($requestId)) ?></p>
            <div><label for="atelier-chief-<?= $requestId ?>">Chef d’atelier responsable</label><select id="atelier-chief-<?= $requestId ?>" name="chef" required>
                    <option value="">Sélectionner un chef d’atelier</option>
                    <?php foreach ($chiefs as $chief): ?><option value="<?= (int) $chief['id'] ?>"><?= prefaEscape($chief['nom']) ?></option><?php endforeach; ?>
                </select></div>
            <span class="atelier-message" role="status" aria-live="polite"></span>
            <div class="atelier-plan-dialog-footer"><button type="button" class="atelier-close-plan-edit">Annuler</button><button type="submit">Affecter</button></div>
        </form>
    </dialog>
<?php else: ?>
    <form class="atelier-action prefa-form plans-iso-take" data-endpoint="save_atelier.php">
        <input type="hidden" name="csrf" value="<?= prefaEscape($_SESSION['prefa_csrf']) ?>"><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="operation" value="take">
        <button type="submit" class="prefa-toggle">Prendre en charge</button>
        <span class="atelier-message" role="status" aria-live="polite"></span>
    </form>
<?php endif; ?>
