<?php
// Les utilisateurs : la liste et la création, sur un seul écran.
//
// Il y en avait deux — « Liste des utilisateurs » et « Créer un utilisateur » — qu'on
// parcourait l'un après l'autre : on créait un compte sans voir ceux qui existaient, puis
// on changeait d'écran pour vérifier qu'il y était. La création est maintenant une fenêtre
// posée sur la liste, ouverte par « Nouvel utilisateur », comme sur GitHub ou Linear.
//
// Le commentaire est en PHP et non en HTML : tout ce qui précède <?php part au navigateur,
// et la page ne pourrait plus ni ouvrir la session ni poser d'en-tête.

require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/vue/liste_filtrable.php';
require_once __DIR__ . '/../includes/vue/user_display.php';

if (!$isAdmin) prefaError(403, 'Seul un administrateur peut consulter les comptes.');

// Le rôle du nouveau compte : le composant filtrable commun, et le métier à côté du nom en
// base — « Gestionnaire » ne dit pas qu'on crée un chef d'atelier.
$optionsRole = [];
foreach ($db->query('SELECT id, name FROM role ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $metier = roleComplement((int) $r['id']);
    $optionsRole[] = [
        'id' => (int) $r['id'],
        'libelle' => $r['name'] . ($metier !== '' ? ' · ' . $metier : ''),
    ];
}
?>
<section class="page prefa-page prefa-list utilisateurs-page" data-densite="confortable">
    <div class="prefa-shell">

        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1>Utilisateurs</h1>
                <p>Les comptes de l’application : créez-les, modifiez-les, retirez ceux qui ne servent plus.</p>
            </div>
            <?php // Le sélecteur de densité des autres listes. Sans lui, l'écran héritait de la
                  // densité retenue ailleurs — « compacte » sur la liste des demandes — sans
                  // qu'on puisse la changer ici : un tableau tassé, et aucun moyen d'en sortir. ?>
            <div class="prefa-vue-outils">
                <div class="prefa-densite" role="group" aria-label="Densité du tableau">
                    <button type="button" class="prefa-densite-choix" data-densite="confortable"
                            aria-pressed="true" aria-label="Lignes confortables" title="Lignes confortables">
                        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                            <rect x="2" y="3" width="12" height="2.6" rx="1" />
                            <rect x="2" y="6.7" width="12" height="2.6" rx="1" />
                            <rect x="2" y="10.4" width="12" height="2.6" rx="1" />
                        </svg>
                    </button>
                    <button type="button" class="prefa-densite-choix" data-densite="compact"
                            aria-pressed="false" aria-label="Lignes compactes" title="Lignes compactes">
                        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
                            <rect x="2" y="2.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="5.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="8.6" width="12" height="1.6" rx=".8" />
                            <rect x="2" y="11.6" width="12" height="1.6" rx=".8" />
                        </svg>
                    </button>
                </div>
                <button type="button" class="utilisateurs-nouveau" data-dialog="utilisateur-dialogue">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    Nouvel utilisateur
                </button>
            </div>
        </header>

        <?php // La recherche des autres listes. Le rôle se choisit dans les onglets, au-dessus
              // du tableau ; il reste un champ du formulaire pour que htmx l'emporte. ?>
        <form id="users-filters" class="prefa-filters" role="search"
              hx-get="/api/users/get.php"
              hx-trigger="load, submit, input changed delay:350ms from:#utilisateurs-recherche"
              hx-target="#utilisateurs-resultats" hx-swap="outerHTML" hx-sync="this:replace">
            <div class="filtres-recherche">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input id="utilisateurs-recherche" type="search" name="q"
                       aria-label="Rechercher un utilisateur"
                       placeholder="Nom, prénom, identifiant…">
            </div>
            <input type="hidden" name="role" id="utilisateurs-role" value="">
        </form>

        <div id="utilisateurs-resultats"></div>

        <dialog id="utilisateur-dialogue" class="utilisateur-dialogue" aria-labelledby="utilisateur-dialogue-titre">
            <form id="create-user-form">
                <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">

                <div class="utilisateur-dialogue-tete">
                    <h2 id="utilisateur-dialogue-titre">Nouvel utilisateur</h2>
                    <button type="button" class="utilisateur-dialogue-fermer" aria-label="Fermer">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </div>

                <div class="utilisateur-dialogue-corps">
                    <div class="utilisateur-dialogue-ligne">
                        <div class="form-group">
                            <label for="prenom">Prénom</label>
                            <input type="text" id="prenom" name="prenom" placeholder="Marie"
                                   autocomplete="given-name" aria-describedby="identifiant-message" required>
                        </div>
                        <div class="form-group">
                            <label for="name">Nom</label>
                            <input type="text" id="name" name="name" placeholder="Dupont"
                                   autocomplete="family-name" aria-describedby="identifiant-message" required>
                        </div>
                    </div>

                    <?php // L'identifiant se fabrique à partir du nom et du prénom ; la ligne
                          // dit tout de suite s'il est libre. ?>
                    <p id="identifiant-message" class="form-message utilisateur-aide" role="status" aria-live="polite">Saisissez le nom et le prénom.</p>

                    <div class="form-group">
                        <label for="email">Adresse e-mail <span class="utilisateur-facultatif">facultative</span></label>
                        <input type="email" id="email" name="email" placeholder="marie.dupont@exemple.fr" autocomplete="email">
                    </div>

                    <?= listeFiltrableRendu('role', 'Rôle', $optionsRole, null, [
                        'obligatoire' => true,
                        'chercher' => 'Rechercher un rôle…',
                        'aucun' => 'Aucun rôle ne correspond.',
                        // L'action lit « role » : c'est elle qui commande, pas le composant.
                        'nom' => 'role',
                    ]) ?>

                    <div class="form-group">
                        <label for="password">Mot de passe</label>
                        <input type="password" id="password" name="password" placeholder="••••••••"
                               autocomplete="new-password" required>
                    </div>

                    <p id="form-message" class="form-message" role="status" aria-live="polite"></p>
                </div>

                <div class="utilisateur-dialogue-pied">
                    <button type="button" class="prefa-ligne-contour utilisateur-dialogue-fermer">Annuler</button>
                    <button type="submit" class="prefa-ligne-valider" disabled>Créer l’utilisateur</button>
                </div>
            </form>
        </dialog>

    </div>
</section>
