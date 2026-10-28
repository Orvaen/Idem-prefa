<?php
/**
 * Une ligne du tableau des utilisateurs, en lecture ou en modification.
 *
 * Le même balisage sert à trois endroits — la liste, la ligne qu'on ouvre pour la modifier
 * et la réponse de l'action qui l'enregistre — et il doit être identique aux trois : sans
 * quoi la ligne change de colonnes selon le chemin par lequel on l'a obtenue. C'était le
 * cas : la liste et la réponse d'enregistrement avaient chacune leur copie.
 */

require_once __DIR__ . '/user_display.php';

/** Les deux lettres du monogramme, comme sur la liste des demandes. */
function userInitiales(array $user): string
{
    return mb_strtoupper(mb_substr(trim((string) $user['prenom']), 0, 1) . mb_substr(trim((string) $user['name']), 0, 1));
}

/** La date de création, sans l'heure : on cherche quand le compte est arrivé, pas à quelle minute. */
function userCreationCourte(array $user): string
{
    if (empty($user['date_creation'])) return '—';
    $date = new DateTimeImmutable($user['date_creation'], new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y');
}

function userRow(array $user, int $moi): void
{
    $id = (int) $user['id'];
    $nom = trim($user['prenom'] . ' ' . $user['name']);
    $cestMoi = $id === $moi;
    ?>
    <tr data-id="<?= $id ?>"
        data-nom="<?= prefaEscape($nom) ?>"
        <?= $cestMoi
            ? 'data-protege title="Votre propre compte : il ne peut être ni sélectionné ni supprimé."'
            : 'aria-selected="false"' ?>>
        <td class="utilisateur-cellule">
            <span class="prefa-personne">
                <span class="prefa-avatar" aria-hidden="true"><?= prefaEscape(userInitiales($user)) ?></span>
                <span class="utilisateur-identite">
                    <span class="utilisateur-nom"><?= prefaEscape($nom) ?><?php if ($cestMoi): ?> <span class="utilisateur-moi">vous</span><?php endif; ?></span>
                    <span class="utilisateur-identifiant"><?= prefaEscape($user['identifiant']) ?></span>
                </span>
            </span>
        </td>
        <?php // Le rôle, et dessous le métier qu'il recouvre : « Demandeur » seul ne dit pas
              // qu'on parle du chargé d'affaire. ?>
        <td class="prefa-secondaire utilisateur-role">
            <span class="utilisateur-role-nom"><?= prefaEscape(userRoleLabel($user)) ?></span>
            <?php if ($metier = roleComplement((int) ($user['id_role'] ?? 0))): ?><span class="utilisateur-role-metier"><?= prefaEscape($metier) ?></span><?php endif; ?>
        </td>
        <td class="prefa-secondaire utilisateur-date" title="<?= prefaEscape(userCreationLabel($user)) ?>"><?= prefaEscape(userCreationCourte($user)) ?></td>
        <?php // Une cellule d'actions à déclencheur unique, comme sur la liste des demandes :
              // « Modifier » et « Supprimer » répétés sur chaque ligne pesaient plus lourd
              // que les noms. ?>
        <td class="prefa-actions">
            <button type="button" class="prefa-actions-declencheur" aria-haspopup="menu" aria-expanded="false"
                    aria-label="Actions sur le compte de <?= prefaEscape($nom) ?>">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="currentColor">
                    <circle cx="5" cy="12" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="19" cy="12" r="1.6" />
                </svg>
            </button>
            <div class="prefa-actions-menu" role="menu" hidden>
                <button type="button" role="menuitem"
                        hx-get="/api/users/edit.php?id=<?= $id ?>"
                        hx-target="closest tr" hx-swap="outerHTML">Modifier</button>
                <?php if ($cestMoi): ?>
                    <?php // Le bouton reste, éteint, avec sa raison : l'action le refuserait
                          // de toute façon, autant le dire avant le clic. ?>
                    <button type="button" role="menuitem" class="est-destructrice" disabled
                            title="Vous ne pouvez pas supprimer votre propre compte.">Supprimer</button>
                <?php else: ?>
                    <button type="button" role="menuitem" class="est-destructrice"
                            hx-post="/dashboard/actions/delete_user.php"
                            hx-vals='<?= prefaEscape(json_encode(['id' => $id, 'csrf' => Session::jeton()])) ?>'
                            hx-confirm="Supprimer <?= prefaEscape($nom) ?> ?"
                            data-confirmer-texte="Cette action est définitive."
                            data-confirmer-action="Supprimer"
                            hx-target="closest tr" hx-swap="outerHTML">Supprimer</button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php
}

function userRowEdition(array $user): void
{
    $id = (int) $user['id'];
    ?>
    <tr class="utilisateur-en-edition">
        <td class="utilisateur-cellule">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
            <span class="utilisateur-edition-champs">
                <input name="prenom" value="<?= prefaEscape($user['prenom']) ?>" aria-label="Prénom" required>
                <input name="name" value="<?= prefaEscape($user['name']) ?>" aria-label="Nom" required>
            </span>
        </td>
        <?php // Le rôle, et dessous le métier qu'il recouvre : « Demandeur » seul ne dit pas
              // qu'on parle du chargé d'affaire. ?>
        <td class="prefa-secondaire utilisateur-role">
            <span class="utilisateur-role-nom"><?= prefaEscape(userRoleLabel($user)) ?></span>
            <?php if ($metier = roleComplement((int) ($user['id_role'] ?? 0))): ?><span class="utilisateur-role-metier"><?= prefaEscape($metier) ?></span><?php endif; ?>
        </td>
        <td class="prefa-secondaire utilisateur-date"><?= prefaEscape(userCreationCourte($user)) ?></td>
        <td class="prefa-actions">
            <span class="prefa-decision">
                <button type="button" class="prefa-ligne-contour"
                        hx-get="/api/users/edit.php?id=<?= $id ?>&amp;view=1"
                        hx-target="closest tr" hx-swap="outerHTML">Annuler</button>
                <button type="button" class="prefa-ligne-valider"
                        hx-post="/dashboard/actions/edit_user.php"
                        hx-include="closest tr"
                        hx-target="closest tr" hx-swap="outerHTML">Enregistrer</button>
            </span>
        </td>
    </tr>
    <?php
}
