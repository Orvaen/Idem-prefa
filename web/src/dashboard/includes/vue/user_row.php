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
require_once __DIR__ . '/../atelier/signature.php';

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

/**
 * Où en est le mot de passe d'un compte, quand il y a quelque chose à en dire : à choisir —
 * compte neuf, ou réinitialisé —, ou un nouveau demandé — oublié, ou à changer —, en attente
 * que l'administrateur réinitialise.
 *
 * @return array{cle:string, libelle:string, detail:string}|null
 */
function userEtatMotDePasse(array $user): ?array
{
    if (($user['password'] ?? null) === '') {
        return ['cle' => 'a-choisir', 'libelle' => 'Mot de passe à choisir (première connexion)',
            'detail' => 'La personne choisira son mot de passe à sa prochaine connexion.'];
    }
    if (!empty($user['reinitialisation_demandee_le'])) {
        return ['cle' => 'demande', 'libelle' => 'Nouveau mot de passe demandé',
            'detail' => 'Demandé le ' . prefaFormatDate($user['reinitialisation_demandee_le'], true)
                . ' : réinitialisez-le, la personne choisira le nouveau à sa prochaine connexion.'];
    }
    return null;
}

function userRow(array $user, int $moi): void
{
    $id = (int) $user['id'];
    $nom = trim($user['prenom'] . ' ' . $user['name']);
    $cestMoi = $id === $moi;
    $motDePasse = userEtatMotDePasse($user);
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
                <?php if ($motDePasse): ?>
                    <span class="utilisateur-mdp est-<?= $motDePasse['cle'] ?>" title="<?= prefaEscape($motDePasse['detail']) ?>"><?= prefaEscape($motDePasse['libelle']) ?></span>
                <?php endif; ?>
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
                <?php // Réinitialiser : le mot de passe se vide, la personne en choisit un
                      // nouveau à sa prochaine connexion. Pas sur son propre compte — le sien
                      // se réinitialise dans Paramètres → Compte, qui ferme aussitôt la
                      // session —, ni sur un compte qui attend déjà. ?>
                <?php if ($cestMoi): ?>
                    <button type="button" role="menuitem" disabled
                            title="Le vôtre se réinitialise dans Paramètres → Compte.">Réinitialiser le mot de passe</button>
                <?php elseif (($motDePasse['cle'] ?? '') === 'a-choisir'): ?>
                    <button type="button" role="menuitem" disabled
                            title="La personne choisira son mot de passe à sa prochaine connexion.">Réinitialiser le mot de passe</button>
                <?php else: ?>
                    <button type="button" role="menuitem"
                            hx-post="/dashboard/actions/reinitialiser_mot_de_passe.php"
                            hx-vals='<?= prefaEscape(json_encode(['id' => $id, 'csrf' => Session::jeton()])) ?>'
                            hx-confirm="Réinitialiser le mot de passe de <?= prefaEscape($nom) ?> ?"
                            data-confirmer-texte="Son mot de passe actuel ne marchera plus : il en choisira un nouveau à sa prochaine connexion."
                            data-confirmer-action="Réinitialiser"
                            hx-target="closest tr" hx-swap="outerHTML">Réinitialiser le mot de passe</button>
                <?php endif; ?>
                <?php // La signature : validée une fois par la personne, elle ne se change plus
                      // que d'ici (includes/atelier/signature.php). Réinitialisée, elle reste
                      // sur les soudures qu'elle a visées, et la personne en trace une nouvelle.
                      // Pas la sienne — elle se refait dans Paramètres —, ni sans signature.
                      // « signature_le » vient de la lecture du compte (signatureColonneCompte()). ?>
                <?php if ($cestMoi): ?>
                    <button type="button" role="menuitem" disabled
                            title="La vôtre se refait dans Paramètres → Compte.">Réinitialiser la signature</button>
                <?php elseif (empty($user['signature_le'])): ?>
                    <button type="button" role="menuitem" disabled
                            title="Pas de signature : la personne la trace dans ses paramètres.">Réinitialiser la signature</button>
                <?php else: ?>
                    <button type="button" role="menuitem"
                            hx-post="/dashboard/actions/reinitialiser_signature.php"
                            hx-vals='<?= prefaEscape(json_encode(['id' => $id, 'csrf' => Session::jeton()])) ?>'
                            hx-confirm="Réinitialiser la signature de <?= prefaEscape($nom) ?> ?"
                            data-confirmer-texte="Elle reste sur les soudures qu’elle a déjà visées. La personne en tracera une nouvelle dans ses paramètres, et la validera."
                            data-confirmer-action="Réinitialiser"
                            hx-target="closest tr" hx-swap="outerHTML">Réinitialiser la signature</button>
                <?php endif; ?>
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
