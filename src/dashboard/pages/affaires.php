<?php

// Les affaires, vues par le chargé de production : il ouvre le chantier, désigne qui en a
// la charge, et voit combien de demandes y sont déposées.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/vue/liste_filtrable.php';
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
 * Le choix du chargé d'affaire sur une carte : le même champ filtrable que dans la fenêtre
 * d'ouverture, dans son mode sans étiquette — l'intitulé part en aria-label, et la carte
 * n'a pas de colonne pour le porter.
 *
 * Le préfixe porte l'identifiant de l'affaire : le composant en tire les siens, et deux
 * cartes ne peuvent pas partager les mêmes.
 */
$choixCharge = static function (array $options, int $idAffaire, ?int $choisi): string {
    return listeFiltrableRendu('charge-' . $idAffaire, 'Chargé d’affaire', $options, $choisi, [
        'vide' => 'Aucun chargé d’affaire',
        'aucun' => 'Aucun chargé d’affaire à ce nom.',
        // L'invite dit le rôle qu'on cherche : « Personne pour l'instant » ne disait pas
        // de qui il s'agissait, ni quel compte il fallait créer pour remplir le champ.
        'chercher' => 'Choisir un chargé d’affaire',
        'tableau' => true,
    ]);
};

/**
 * Quand aucun compte « Chargé d'affaire » n'existe, le champ n'aurait rien à proposer : il
 * est remplacé par ce qui manque, et le bouton qui y mène — la fenêtre « Nouvel
 * utilisateur », rôle déjà choisi.
 */
$sansCharge = static fn (): string => '<p class="affaire-sans-charge">Aucun compte avec le rôle '
    . '<strong>Chargé d’affaire</strong>. '
    . '<button type="button" class="affaires-creer-charge">Créer ce compte</button></p>';
?>
<section class="users-page affaires-page" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
    <div class="header-list affaires-entete">
        <div class="affaires-titre">
            <h1 class="list-h1">Affaires</h1>
        </div>
        <!-- Ouvrir une affaire est l'action de l'écran, pas une ligne de plus dans la page.
             Le formulaire tenait le haut de l'écran en permanence pour un geste qu'on fait
             une fois par chantier ; il tient maintenant dans un bouton, et la saisie dans
             la fenêtre qu'il ouvre. -->
        <button type="button" id="affaire-nouvelle" class="affaire-nouvelle">
            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 5v14M5 12h14" />
            </svg>
            Nouvelle affaire
        </button>
    </div>

    <!-- La recherche de l'écran. C'est le composant partagé des filtres — même loupe, même
         hauteur, même cadre que celle de la liste des demandes : deux recherches qui ne se
         ressemblent pas dans la même application, c'est deux choses différentes pour l'œil.

         Elle filtre dans le navigateur : les affaires tiennent dans une page, et aller
         demander au serveur de cacher trois cartes serait un aller-retour pour rien. -->
    <div class="affaires-filtres">
        <div class="filtres-recherche">
            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="1.75" stroke-linecap="round">
                <circle cx="11" cy="11" r="7" />
                <path d="m20 20-3.5-3.5" />
            </svg>
            <input id="affaires-search" type="search" autocomplete="off"
                aria-label="Rechercher une affaire"
                placeholder="Nom d’affaire ou chargé d’affaire…">
        </div>
    </div>

    <?php if (!$chargesDAffaire): ?>
        <!-- Ce qu'il faut savoir en arrivant : une affaire se confie à un compte qui a le
             rôle « Chargé d'affaire » — c'est lui qui déposera les demandes. -->
        <div class="affaires-note">
            <p>Une affaire se confie à un <strong>chargé d’affaire</strong> : c’est lui qui y déposera les demandes de préfabrication. Aucun compte n’a encore ce rôle.</p>
            <button type="button" class="affaires-creer-charge">Créer un compte chargé d’affaire</button>
        </div>
    <?php endif; ?>

    <!-- Une carte par affaire, et non une ligne de tableau. Un tableau range des
         enregistrements qu'on compare colonne par colonne ; une affaire n'a que trois
         choses à dire — son nom, qui la porte, combien de demandes y sont déposées — et
         on ne les compare pas, on les ouvre. La carte donne au nom la place d'un titre,
         ce qu'une cellule ne fait pas.

         Le nom et le chargé d'affaire restent modifiables sur place : c'est ce que
         l'écran savait déjà faire, et c'est ce pour quoi on y revient. -->
    <div class="affaires-grille" id="affaires-rows">
        <?php foreach ($affaires as $affaire): ?>
            <?php
            // Le monogramme tient la place du visuel de couverture des outils de suivi :
            // nous n'avons pas d'image à poser sur une affaire, et une image prise au
            // hasard n'en dirait rien. Deux lettres suffisent à distinguer une carte d'une
            // autre dans une grille qu'on balaie.
            $monogramme = mb_strtoupper(mb_substr(trim($affaire->nom), 0, 2));
            ?>
            <article class="affaire-carte" data-affaire="<?= $affaire->id ?>">
                <div class="affaire-carte-haut">
                    <span class="affaire-monogramme" aria-hidden="true"><?= prefaEscape($monogramme) ?></span>

                    <!-- La suppression ne paraît qu'au survol, comme les commandes d'une
                         ligne de la liste des demandes. Éteinte quand des demandes sont
                         rattachées : son infobulle dit pourquoi. -->
                    <button type="button" class="affaire-supprimer" aria-label="Supprimer l’affaire <?= prefaEscape($affaire->nom) ?>"<?= $affaire->demandes > 0 ? ' disabled title="Des demandes y sont rattachées."' : ' title="Supprimer l’affaire"' ?>>
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6" />
                        </svg>
                    </button>
                </div>

                <input class="affaire-nom" value="<?= prefaEscape($affaire->nom) ?>" maxlength="255" aria-label="Nom de l’affaire">

                <div class="affaire-champ">
                    <span class="affaire-champ-libelle">Chargé d’affaire</span>
                    <?= $optionsCharge ? $choixCharge($optionsCharge, $affaire->id, $affaire->idChargeAffaire) : $sansCharge() ?>
                </div>

                <footer class="affaire-carte-pied">
                    <?php
                    // Le nombre dit aussi pourquoi la suppression est refusée : une affaire
                    // qui porte des demandes ne se ferme pas d'un clic.
                    //
                    // Quand il y en a, il mène à ces demandes — c'est le chemin annoncé de
                    // la carte, et le seul qui soit atteignable au clavier. À zéro, il n'y
                    // a rien à ouvrir : ce n'est plus qu'un état.
                    ?>
                    <?php if ($affaire->demandes > 0): ?>
                        <button type="button" class="affaire-demandes" title="Voir les demandes de cette affaire">
                            <?= $affaire->demandes ?> demande<?= $affaire->demandes > 1 ? 's' : '' ?>
                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 6 6 6-6 6" />
                            </svg>
                        </button>
                    <?php else: ?>
                        <span class="affaire-demandes est-vide">Aucune demande</span>
                    <?php endif; ?>
                    <button type="button" class="affaire-enregistrer">Enregistrer</button>
                </footer>
            </article>
        <?php endforeach; ?>

        <?php if (!$affaires): ?>
            <p class="affaires-vide">Aucune affaire ouverte. « Nouvelle affaire » ouvre la première.</p>
        <?php endif; ?>

        <!-- La réponse de la recherche quand elle ne trouve rien. Elle vit dans la grille,
             pas ailleurs : c'est là que le regard attend les cartes. -->
        <p class="affaires-vide" id="affaires-aucune" hidden>Aucune affaire ne porte ce nom.</p>
    </div>

    <!--
        La fenêtre d'ouverture. Un <dialog> modal, comme la palette et l'ajout de compte :
        le piège à focus, le fond et la touche Échap viennent avec, et la boîte passe
        au-dessus de tout sans course au z-index.

        Elle vit dans la page et non dans dashboard.php : l'écran arrive par AJAX dans
        #content et repart entier à chaque rechargement — la fenêtre suit, avec la liste des
        chargés d'affaire que le PHP vient d'écrire.
    -->
    <dialog id="affaire-dialog" class="affaire-dialog fenetre" aria-labelledby="affaire-dialog-titre">
        <form id="affaire-form" class="affaire-form" method="post">
            <div class="affaire-dialog-entete fenetre-entete">
                <h2 id="affaire-dialog-titre">Nouvelle affaire</h2>
                <button type="button" class="affaire-dialog-fermer modal-close" aria-label="Fermer">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="m6 6 12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>

            <!-- La saisie dans le registre des outils de suivi : pas d'étiquette au-dessus des
                 champs, chacun porte son intitulé à l'intérieur. Une étiquette flottante par
                 champ faisait lire deux fois le même mot, une fois en l'air et une fois dans
                 le champ. Les deux champs sont taillés pareil : voir la feuille. -->
            <div class="affaire-dialog-corps fenetre-corps">
                <input id="affaire-nom" class="affaire-nom-champ fenetre-champ" name="nom" maxlength="255" required
                    autocomplete="off" aria-label="Nom de l’affaire" placeholder="Nom de l’affaire">

                <?php
                // Le même champ que « Matière » : on tape pour chercher dans la liste au lieu
                // de dérouler. Quinze comptes tiennent dans un menu, cinquante n'y tiennent
                // plus, et c'est le genre de liste qui ne fait que grandir.
                //
                // Pas d'issue « Autre » : un chargé d'affaire est un compte, il se crée dans
                // l'administration, pas en le tapant ici.
                //
                // « tableau » n'est pas réservé aux tableaux : c'est le mode sans étiquette du
                // composant — l'intitulé part en aria-label et le champ tient sur une ligne.
                // C'est ce qu'il faut ici : les deux champs se ressemblent trait pour trait,
                // une étiquette au-dessus du second aurait déparé.
                echo $optionsCharge
                    ? listeFiltrableRendu('charge', 'Chargé d’affaire', $optionsCharge, null, [
                        'vide' => 'Aucun chargé d’affaire',
                        'aucun' => 'Aucun chargé d’affaire à ce nom.',
                        'chercher' => 'Chargé d’affaire (facultatif)',
                        'tableau' => true,
                    ])
                    : $sansCharge();
                ?>

                <p class="prefa-message fenetre-message" role="status" aria-live="polite"></p>
            </div>

            <div class="affaire-dialog-pied fenetre-pied">
                <button type="button" class="affaire-dialog-fermer fenetre-annuler">Annuler</button>
                <button type="submit" class="affaire-ouvrir fenetre-valider">Ouvrir l’affaire</button>
            </div>
        </form>
    </dialog>
</section>
