<?php
/**
 * Une ligne du tableau des types de passivation, en lecture ou en saisie.
 *
 * Le même balisage sert à trois endroits — l'écran des passivations, celui des paramètres,
 * et la réponse de l'action qui en modifie une — et il doit être identique aux trois, sans
 * quoi la ligne change d'allure selon le chemin par lequel on l'a obtenue.
 */

function passivationRow(array $type, bool $editing = false, string $error = ''): void
{
    $id = (int) $type['id'];
    $url = 'actions/edit_passivation.php?id=' . $id;
    ?>
    <tr data-id="<?= $id ?>">
        <td><?= $id ?></td>
        <td class="passivation-name">
        <?php if ($editing): ?>
            <form id="edit-passivation-<?= $id ?>" hx-post="<?= $url ?>" hx-target="closest tr" hx-swap="outerHTML">
                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input name="libelle" value="<?= prefaEscape($type['libelle']) ?>" maxlength="100" required aria-label="Nom du type de passivation" autofocus>
                <p class="prefa-message error" role="alert"><?= prefaEscape($error) ?></p>
            </form>
        <?php else: ?><?= prefaEscape($type['libelle']) ?><?php endif; ?>
        </td>
        <td>
        <?php if ($editing): ?>
            <button type="submit" form="edit-passivation-<?= $id ?>" class="edit-passivation">Enregistrer</button>
            <button type="button" class="edit-passivation" hx-get="<?= $url ?>&view=1" hx-target="closest tr" hx-swap="outerHTML">Annuler</button>
        <?php else: ?>
            <button type="button" class="edit-passivation" hx-get="<?= $url ?>" hx-target="closest tr" hx-swap="outerHTML">Modifier</button>
        <?php endif; ?>
        </td>
    </tr>
    <?php
}
