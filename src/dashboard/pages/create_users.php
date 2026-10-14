<?php

// Formulaire de création d'utilisateur pour l'administrateur.
//
// Le commentaire est en PHP et non en HTML au-dessus de la balise : tout ce qui précède
// <?php part au navigateur, et la page ne peut alors plus ni ouvrir la session ni poser
// d'en-tête — « headers already sent ».

require_once __DIR__ . '/../includes/prefa_context.php';
// La page arrive seule dans #content : elle demande elle-même le composant du champ
// filtrable et le libellé métier des rôles, que le squelette du tableau de bord charge
// de son côté mais qui ne lui sont pas transmis.
require_once __DIR__ . '/../includes/liste_filtrable.php';
require_once __DIR__ . '/../includes/user_display.php';

if (!$isAdmin) prefaError(403, 'Seul un administrateur peut créer un compte.');

?>
<section class="page">
    <div class="form-card">

        <div class="form-header">
            <h1>Créer un utilisateur</h1>
            <p>Ajoutez un nouvel utilisateur à votre application.</p>
        </div>

        <form id="create-user-form">
            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
            <div class="form-group">
                <label for="name">Nom</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    placeholder="Dupont"
                    autocomplete="family-name"
                    required>
            </div>

            <div class="form-group">
                <label for="prenom">Prénom</label>
                <input
                    type="text"
                    id="prenom"
                    name="prenom"
                    placeholder="Marie"
                    autocomplete="given-name"
                    aria-describedby="identifiant-message"
                    required>
            </div>

            <div id="identifiant-message" class="form-message" role="status" aria-live="polite">Saisissez le nom et le prénom.</div>

            <div class="form-group">
                <label for="email">Adresse email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="exemple@email.com"
                    autocomplete="email">
            </div>

            <?php
            // Le même champ que la matière et l'affaire du formulaire de demande, rendu par
            // le même composant. Cette page en avait un seul à elle, avec son balisage, son
            // script et ses styles — et les quatre rôles écrits en dur dans le HTML, qui ne
            // suivaient donc pas un renommage en base.
            //
            // Le libellé dit le métier à côté du nom en base : « Gestionnaire » ne dit pas
            // qu'on crée un chef d'atelier.
            $optionsRole = [];
            foreach ($db->query('SELECT id, name FROM role ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $metier = roleComplement((int) $r['id']);
                $optionsRole[] = [
                    'id' => (int) $r['id'],
                    'libelle' => $r['name'] . ($metier !== '' ? ' · ' . $metier : ''),
                ];
            }
            echo listeFiltrableRendu('role', 'Rôle', $optionsRole, null, [
                'obligatoire' => true,
                'chercher' => 'Rechercher un rôle…',
                'aucun' => 'Aucun rôle ne correspond.',
                // L'action lit « role » : c'est elle qui commande, pas le composant.
                'nom' => 'role',
            ]);
            ?>

            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="••••••••"
                    autocomplete="new-password"
                    required>
            </div>

            <div id="form-message" class="form-message"></div>

            <button type="submit" disabled>
                Créer l'utilisateur
            </button>

        </form>
    </div>
</section>
