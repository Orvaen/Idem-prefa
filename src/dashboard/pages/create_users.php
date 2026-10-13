<?php

// Formulaire de création d'utilisateur pour l'administrateur.
//
// Le commentaire est en PHP et non en HTML au-dessus de la balise : tout ce qui précède
// <?php part au navigateur, et la page ne peut alors plus ni ouvrir la session ni poser
// d'en-tête — « headers already sent ».

require_once __DIR__ . '/../includes/prefa_context.php';

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

            <div class="form-group role-group">
                <label for="role-search">Rôle</label>

                <div class="role-select">
                    <input
                        type="text"
                        id="role-search"
                        placeholder="Rechercher un rôle..."
                        autocomplete="off"
                        aria-autocomplete="list"
                        aria-controls="role-list"
                        aria-expanded="false">

                    <input type="hidden" id="role" name="role" required>

                    <div class="role-list" id="role-list">
                        <div class="role-option" data-value="1">
                            Administrateur
                        </div>

                        <div class="role-option" data-value="2">
                            Gestionnaire
                        </div>

                        <div class="role-option" data-value="3">
                            Utilisateur
                        </div>

                        <div class="role-option" data-value="5">
                            Demandeur
                        </div>
                    </div>
                </div>
            </div>

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
