<?php
// L'écran de connexion est la page d'accueil du site : http://localhost:8080 y mène, sans
// adresse à retenir ni page intermédiaire. Ce qui l'accompagne reste dans auth/ — l'action
// qui vérifie le mot de passe, celle qui ferme la session.
//
// Déjà connecté, on ne repasse pas par ici : la session est lue plus bas et le tableau de
// bord prend la place.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/modeles/autoload.php';

Session::demarrer();

// En développement, l'écran de connexion propose les comptes existants : un clic suffit à
// entrer, sans mot de passe. C'est la seule façon d'essayer les quatre profils sans
// connaître quatre mots de passe.
//
// En production, APP_ENV ne vaut pas « dev », cette requête n'est pas faite et rien de
// tout cela n'apparaît.
$comptesDev = [];
if (Environnement::estDev()) {
    $db = new MyPDO(__DIR__ . '/my_setting.ini');
    $comptesDev = $db->query("SELECT u.identifiant, u.name, u.prenom, u.id_role, r.name AS role_name
        FROM Utilisateur u JOIN role r ON r.id = u.id_role
        ORDER BY u.id_role, u.prenom, u.name")->fetchAll(PDO::FETCH_ASSOC);
}

if (Session::connectee()) {
    header('Location: dashboard/dashboard.php');
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
    <script src="js/theme.js?v=<?= filemtime(__DIR__ . '/js/theme.js') ?>"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/axios/1.20.0/axios.min.js" integrity="sha512-OVq+Gfa9PIjbTC0jWOccSFSp/vzkBQ5pMcbHQrNZLl+izmdFmusmAMlX4LT550ivvSnMXMEeG7CxEtjIz6Dn2Q==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

    <link href="css/index.css?v=<?= filemtime(__DIR__ . '/css/index.css') ?>" rel="stylesheet" />
    <link href="css/floating-fields.css?v=<?= filemtime(__DIR__ . '/css/floating-fields.css') ?>" rel="stylesheet" />

    <script src="js/floating-fields.js?v=<?= filemtime(__DIR__ . '/js/floating-fields.js') ?>" defer></script>
    <script src="js/mot-de-passe.js?v=<?= filemtime(__DIR__ . '/js/mot-de-passe.js') ?>" defer></script>
    <script src="js/login.js?v=<?= filemtime(__DIR__ . '/js/login.js') ?>" defer></script>
</head>

<body>
    <main class="connexion-panneau">
        <div class="connexion-marque">
            <img src="assets/logo-idem.png" alt="IDEM">
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

        <?php if ($comptesDev): ?>
            <section class="connexion-dev" data-csrf="<?= htmlspecialchars(Session::jeton(), ENT_QUOTES, 'UTF-8') ?>">
                <p class="connexion-dev-titre">
                    Développement
                    <span class="connexion-dev-marque">dev</span>
                </p>
                <p class="connexion-dev-mot">Entrer directement, sans mot de passe.</p>

                <ul class="connexion-dev-liste">
                    <?php foreach ($comptesDev as $compte):
                        $nom = trim($compte['prenom'] . ' ' . $compte['name']);
                        $metier = match ((int) $compte['id_role']) {
                            1 => 'Chargé de production',
                            2 => "Chef d'atelier",
                            3 => 'Personnel atelier',
                            5 => "Chargé d'affaire",
                            default => '',
                        };
                    ?>
                        <li>
                            <button type="button" class="connexion-dev-compte"
                                    data-compte="<?= htmlspecialchars($compte['identifiant'], ENT_QUOTES, 'UTF-8') ?>">
                                <span class="connexion-dev-nom"><?= htmlspecialchars($nom, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="connexion-dev-role"><?= htmlspecialchars($compte['role_name'] . ($metier !== '' ? ' · ' . $metier : ''), ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <footer class="connexion-pied">
            <p>Pas encore de compte ? Le chargé de production en crée un.</p>
        </footer>
    </main>

    <!-- Décoratif : rien à annoncer aux lecteurs d'écran. -->
    <div class="connexion-visuel" aria-hidden="true"></div>
</body>

</html>
