<?php

require_once __DIR__ . './../db.php';
require_once __DIR__ . '/../modeles/autoload.php';

Session::demarrer();

if (Session::connectee()) {
    header('Location: ../dashboard/dashboard.php');
    exit;
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <!-- Métadonnées -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Connexion — IDEM Préfabrication</title>

    <!-- Le thème est posé avant le premier rendu, sinon l'écran clignote le temps que la
         feuille s'applique. theme.js décide : le choix mémorisé s'il y en a un, celui du
         système sinon. -->
    <script src="../js/theme.js?v=<?= filemtime(__DIR__ . '/../js/theme.js') ?>"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/axios/1.20.0/axios.min.js" integrity="sha512-OVq+Gfa9PIjbTC0jWOccSFSp/vzkBQ5pMcbHQrNZLl+izmdFmusmAMlX4LT550ivvSnMXMEeG7CxEtjIz6Dn2Q==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

    <link href="../css/index.css?v=<?= filemtime(__DIR__ . '/../css/index.css') ?>" rel="stylesheet" />
    <link href="../css/floating-fields.css?v=<?= filemtime(__DIR__ . '/../css/floating-fields.css') ?>" rel="stylesheet" />

    <script src="../js/floating-fields.js?v=<?= filemtime(__DIR__ . '/../js/floating-fields.js') ?>" defer></script>
    <script src="../js/mot-de-passe.js?v=<?= filemtime(__DIR__ . '/../js/mot-de-passe.js') ?>" defer></script>
    <script src="../js/login.js?v=<?= filemtime(__DIR__ . '/../js/login.js') ?>" defer></script>
</head>

<body>
    <main class="connexion-panneau">
        <div class="connexion-marque">
            <img src="../assets/logo-idem.png" alt="IDEM">
        </div>

        <div class="connexion-corps">
            <p class="connexion-surtitre">Atelier de préfabrication</p>
            <h1>Connexion</h1>

            <form id="login-form" method="post">
                <label for="login">Identifiant</label>
                <input
                    name="login"
                    id="login"
                    type="text"
                    required
                    autocomplete="username">

                <label for="password">Mot de passe</label>
                <input
                    name="password"
                    id="password"
                    type="password"
                    required
                    autocomplete="current-password">

                <p id="form-message" role="status" aria-live="polite"></p>

                <button type="submit">Se connecter</button>
            </form>
        </div>

        <footer class="connexion-pied">
            <p>Pas encore de compte ? Le chargé de production en crée un.</p>
        </footer>
    </main>

    <!-- Décoratif : rien à annoncer aux lecteurs d'écran. -->
    <div class="connexion-visuel" aria-hidden="true"></div>
</body>

</html>
