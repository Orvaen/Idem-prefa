<?php

// Les affaires, vues par le chargé de production : il ouvre le chantier, désigne qui en a
// la charge, et voit combien de demandes y sont déposées.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/liste_filtrable.php';
if (!$isAdmin) prefaError(403, 'Seul le chargé de production gère les affaires.');

$affaires = Affaire::toutes($db);
$chargesDAffaire = $db->prepare('SELECT id, TRIM(CONCAT(prenom, \' \', name)) AS nom
    FROM Utilisateur WHERE id_role = ? ORDER BY prenom, name');
$chargesDAffaire->execute([Role::ChargeDAffaire->value]);
$chargesDAffaire = $chargesDAffaire->fetchAll(PDO::FETCH_ASSOC);

// Le composant attend des options { id, libelle } ; la requête nomme la colonne « nom ».
$optionsCharge = array_map(
    static fn (array $compte): array => ['id' => (int) $compte['id'], 'libelle' => $compte['nom']],
    $chargesDAffaire
);

/**
 * Le choix du chargé d'affaire sur une ligne : le même champ filtrable que dans le
 * formulaire au-dessus, en version de tableau. C'était une liste déroulante du système,
 * seul vestige de l'ancien écran — et la seule de la page à ne pas ressembler aux autres.
 *
 * Le préfixe porte l'identifiant de l'affaire : le composant en tire les siens, et deux
 * lignes ne peuvent pas partager les mêmes.
 */
$choixCharge = static function (array $options, int $idAffaire, ?int $choisi): string {
    return listeFiltrableRendu('charge-' . $idAffaire, 'Chargé d’affaire', $options, $choisi, [
        'vide' => 'Personne pour l’instant',
        'aucun' => 'Aucun chargé d’affaire à ce nom.',
        'chercher' => 'Personne pour l’instant',
        'tableau' => true,
    ]);
};
?>
<section class="users-page affaires-page" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
    <div class="header-list">
        <h1 class="list-h1">Affaires</h1>
        <p class="affaires-intro">Un chantier, un chargé d’affaire. C’est ici qu’une affaire s’ouvre ;
            le chargé d’affaire y dépose ensuite ses demandes de préfabrication.</p>
    </div>

    <form id="affaire-form" class="affaire-form">
        <div class="form-group">
            <label for="affaire-nom">Nouvelle affaire</label>
            <input id="affaire-nom" name="nom" maxlength="255" required placeholder="Ex. : Réseau vapeur — bâtiment A">
        </div>
        <?php
        // Le même champ que « Matière » : on tape pour chercher dans la liste au lieu de
        // dérouler. Quinze comptes tiennent dans un menu, cinquante n'y tiennent plus, et
        // c'est le genre de liste qui ne fait que grandir.
        //
        // Pas d'issue « Autre » : un chargé d'affaire est un compte, il se crée dans
        // l'administration, pas en le tapant ici.
        echo listeFiltrableRendu('charge', 'Chargé d’affaire', $optionsCharge, null, [
            'vide' => 'Personne pour l’instant',
            'aucun' => 'Aucun chargé d’affaire à ce nom.',
            'chercher' => 'Rechercher un chargé d’affaire…',
        ]);
        ?>
        <button type="submit">Ouvrir l’affaire</button>
        <p class="prefa-message" role="status" aria-live="polite"></p>
    </form>

    <?php if (!$chargesDAffaire): ?>
        <p class="affaires-note">Aucun compte de chargé d’affaire n’existe encore : créez-en un pour
            pouvoir leur confier des affaires.</p>
    <?php endif; ?>

    <div class="affaires-table-scroll">
        <table class="affaires-table">
            <thead>
                <tr>
                    <th scope="col">Affaire</th>
                    <th scope="col">Chargé d’affaire</th>
                    <th scope="col">Demandes</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody id="affaires-rows">
                <?php foreach ($affaires as $affaire): ?>
                    <tr data-affaire="<?= $affaire->id ?>">
                        <td><input class="affaire-nom" value="<?= prefaEscape($affaire->nom) ?>" maxlength="255" aria-label="Nom de l’affaire"></td>
                        <td><?= $choixCharge($optionsCharge, $affaire->id, $affaire->idChargeAffaire) ?></td>
                        <!-- Le nombre dit pourquoi la suppression est refusée : une affaire qui
                             porte des demandes ne se ferme pas d'un clic. -->
                        <td class="affaire-demandes"><?= $affaire->demandes ?></td>
                        <td class="affaire-actions">
                            <button type="button" class="affaire-enregistrer">Enregistrer</button>
                            <button type="button" class="affaire-supprimer"<?= $affaire->demandes > 0 ? ' disabled title="Des demandes y sont rattachées."' : '' ?>>Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$affaires): ?>
                    <tr class="affaires-vide"><td colspan="4">Aucune affaire ouverte. Ouvrez la première ci-dessus.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
