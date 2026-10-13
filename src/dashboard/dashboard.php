<?php

// Démarrer la session et empêcher la mise en cache du contenu
session_start();
header('Cache-Control: no-store');

// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['identifiant'])) {
    header('Location: ../auth/login.php');
    exit;
}

$name = $_SESSION['name'] ?? '';
$prenom = $_SESSION['prenom'] ?? '';
$initial = mb_strtoupper(mb_substr(trim($name), 0, 1));
$urgentPending = 0;

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/comptes.php';
require_once __DIR__ . '/includes/user_display.php';
$db = new MyPDO(__DIR__ . '/../my_setting.ini');
$_SESSION['prefa_csrf'] ??= bin2hex(random_bytes(32));

// Le rôle est relu en base : son identifiant ne bouge pas, son libellé peut être renommé.
$compte = $db->prepare('SELECT u.id_role, r.name AS role_name FROM Utilisateur u JOIN role r ON r.id = u.id_role WHERE u.identifiant = ?');
$compte->execute([$_SESSION['identifiant']]);
$actor = $compte->fetch();

if (!$actor) {
    header('Location: ../auth/logout.php');
    exit;
}

$roleId = (int) $actor['id_role'];
$role = $_SESSION['role_name'] = $actor['role_name'];

// Déterminer les rôles spécifiques pour l'affichage conditionnel
$isAdmin = $roleId === 1;
$isWorkshopChief = $roleId === 2;
$isWorkshopPersonnel = $roleId === 3;
$isRequester = $roleId === 5;

// Comptes connectés sur cet appareil, pour le menu de bascule du pied de la barre latérale.
$comptes = comptesList($db);

// Nombre de demandes urgentes en attente, affiché à l'administrateur
if ($isAdmin) {
    $urgentPending = (int) $db->query('SELECT COUNT(*) FROM demande_prefabrication WHERE urgent = 1 AND id_statut = 1')->fetchColumn();
}

// PV restant à renseigner : quatre par demande validée, moins ceux déjà tranchés.
// Une demande jamais touchée n'a aucune ligne, d'où le calcul par soustraction.
$pvAFaire = 0;
if ($isAdmin || $isWorkshopChief) {
    $pvAFaire = (int) $db->query("SELECT
            (SELECT COUNT(*) FROM demande_prefabrication WHERE id_statut = 2) * 4
          - (SELECT COUNT(*) FROM pv_prefabrication p JOIN demande_prefabrication d ON d.id = p.id_demande
             WHERE d.id_statut = 2 AND p.statut <> 'a_faire')")->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="icon" href="data:,">
    <script>
        (function() {
            var savedTheme = localStorage.getItem('app_theme');
            if (savedTheme === 'pink' || savedTheme === 'light') {
                document.documentElement.setAttribute('data-theme', savedTheme);
                document.addEventListener('DOMContentLoaded', function() {
                    if (document.body) document.body.setAttribute('data-theme', savedTheme);
                });
            }
        })();
    </script>

    <!-- Stylesheets -->
    <link href="../css/dashboard/settings.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/settings.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/dashboard-base.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/dashboard-base.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/dashboard-forms.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/dashboard-forms.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/prefa-form.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/prefa-form.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/prefa-list.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/prefa-list.css') ?>" rel="stylesheet" />

    <link href="../css/dashboard/planning.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/planning.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/atelier.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/atelier.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/nomenclature.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/nomenclature.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/plan-viewer.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/plan-viewer.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/suggestions.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/suggestions.css') ?>" rel="stylesheet" />
    <link href="../css/floating-fields.css?v=<?= filemtime(__DIR__ . '/../css/floating-fields.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/pink-theme.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/pink-theme.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/light-theme.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/light-theme.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/accounts.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/accounts.css') ?>" rel="stylesheet" />
    <link href="../css/dashboard/pv.css?v=<?= filemtime(__DIR__ . '/../css/dashboard/pv.css') ?>" rel="stylesheet" />
    <script src="../js/floating-fields.js?v=<?= filemtime(__DIR__ . '/../js/floating-fields.js') ?>" defer></script>
</head>

<body>
    <div id="contenaire">
        <!-- Navigation de gauche -->
        <nav id="left-panel">
            <div class="sidebar-mobile-header">
                <strong>Navigation</strong>
                <button id="close-sidebar" type="button" aria-label="Fermer le menu">×</button>
            </div>
            <div class="nav-header">
                <?php if ($isAdmin): ?>
                    <button id="btn-create-user" class="nav-btn" type="button">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                            <circle cx="9" cy="7" r="4" />
                            <path d="M19 8v6M22 11h-6" />
                        </svg>
                        <span>Créer un utilisateur</span>
                    </button>

                    <button id="btn-list-users" class="nav-btn" type="button">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                            <circle cx="9" cy="7" r="4" />
                            <path d="M19 8v6M22 11h-6" />
                        </svg>
                        <span>Liste des utilisateurs</span>
                    </button>
                    <button id="btn-settings" class="nav-btn" type="button">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1" />
                            <circle cx="12" cy="12" r="3.5" />
                        </svg>
                        <span>Paramètres</span>
                    </button>
                <?php endif ?>

                <?php if ($isRequester): ?>
                    <button id="btn-prefa" class="nav-btn" type="button">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m10 3 7 4-7 4-7-4 7-4Z" />
                            <path d="M3 7v9l7 4v-9M17 7v5M10 20l3-1.7" />
                            <path d="M18 15v6M15 18h6" />
                        </svg>
                        <span>Créer une affaire</span>
                    </button>
                <?php endif; ?>

                <button id="btn-list-prefa" class="nav-btn" type="button">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="5" y="4" width="14" height="17" rx="2" />
                        <path d="M9 4V2h6v2M9 10l2 2 4-4M9 16h6" />
                    </svg>
                    <span><?= $isAdmin ? 'Valider les demandes' : ($isWorkshopChief ? 'Suivi des demandes' : ($isWorkshopPersonnel ? 'Mes affectations' : 'Mes affaires')) ?></span>
                    <?php if ($isAdmin): ?>
                        <span id="urgent-pending-count" class="nav-urgent-badge" aria-live="polite" aria-label="<?= $urgentPending ?> demandes urgentes en attente" title="Demandes urgentes en attente" <?= $urgentPending === 0 ? 'hidden' : '' ?>><?= $urgentPending ?></span>
                    <?php endif; ?>
                </button>

                <?php if ($isWorkshopPersonnel): ?>
                    <button id="btn-pointage" class="nav-btn" type="button">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M12 7v5l3 2" />
                        </svg>
                        <span>Pointage</span>
                    </button>
                <?php endif; ?>

                <!-- Plans / ISO & Affectations Sidebar -->
                <?php if ($isAdmin || $isWorkshopChief): ?>
                    <button id="btn-plans-iso" class="nav-btn" type="button">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="12 2 2 7 12 12 22 7 12 2" />
                            <polyline points="2 17 12 22 22 17" />
                            <polyline points="2 12 12 17 22 12" />
                        </svg>
                        <span>Plans / ISO & Affectations</span>
                    </button>

                    <button id="btn-planning" class="nav-btn" type="button">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="5" width="18" height="16" rx="2" />
                            <path d="M16 3v4M8 3v4M3 11h18M7 15h3M14 15h3M7 18h3" />
                        </svg>
                        <span>Planning</span>
                    </button>

                <?php endif; ?>

                <?php if ($isAdmin || $isWorkshopChief || $isRequester): ?>
                    <button id="btn-pv" class="nav-btn" type="button">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" />
                            <path d="M14 3v5h5" />
                            <path d="m9 14 2 2 4-4" />
                        </svg>
                        <span>Avancement préfabrication</span>
                        <?php if ($pvAFaire): ?><span class="nav-urgent-badge" aria-label="<?= $pvAFaire ?> PV à renseigner" title="PV encore à renseigner"><?= $pvAFaire ?></span><?php endif; ?>
                    </button>
                <?php endif; ?>


                <?php if ($isAdmin): ?>
                    <button id="btn-urgent-prefa" class="nav-btn" type="button">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3 2.8 19h18.4L12 3Z" />
                            <path d="M12 9v4M12 16h.01" />
                        </svg>
                        <span>Demandes urgentes</span>
                        <span class="nav-urgent-badge" aria-hidden="true" <?= $urgentPending === 0 ? 'hidden' : '' ?>><?= $urgentPending ?></span>
                    </button>
                <?php endif; ?>
            </div>

            <div class="nav-footer">
                <button type="button" id="account-menu-button" class="account-trigger" aria-haspopup="menu" aria-expanded="false" aria-controls="account-menu">
                    <span class="user-avatar"><?= htmlspecialchars($initial) ?></span>

                    <span class="nav-subfooter">
                        <span class="title"><?= htmlspecialchars($name . ' ' . $prenom) ?></span>
                        <span class="subtitle"><?= htmlspecialchars($role) ?></span>
                        <?php if ($complement = roleComplement($roleId)): ?>
                            <span class="subtitle subtitle-metier"><?= htmlspecialchars($complement) ?></span>
                        <?php endif; ?>
                    </span>

                    <svg class="icon account-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </button>

                <button type="button" id="theme-menu-button" class="nav-theme" aria-haspopup="menu" aria-expanded="false" aria-controls="theme-menu" title="Thème" aria-label="Changer de thème">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="13.5" cy="6.5" r=".75" fill="currentColor" />
                        <circle cx="17.5" cy="10.5" r=".75" fill="currentColor" />
                        <circle cx="8.5" cy="7.5" r=".75" fill="currentColor" />
                        <circle cx="6.5" cy="12.5" r=".75" fill="currentColor" />
                        <path d="M12 2a10 10 0 0 0 0 20 2 2 0 0 0 2-2v-1a2 2 0 0 1 2-2h2a4 4 0 0 0 4-4 10 10 0 0 0-10-11Z" />
                    </svg>
                </button>

                <a class="nav-logout" href="../auth/logout.php" title="Se déconnecter" aria-label="Se déconnecter">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <path d="M16 17l5-5-5-5M21 12H9" />
                    </svg>
                </a>
            </div>
        </nav>

        <!-- Utilitaires -->
        <button id="sidebar-backdrop" type="button" aria-label="Fermer le menu" tabindex="-1" hidden></button>
        <div id="drag"></div>

        <!-- Navigation de droite -->
        <div id="right-panel">
            <div class="dashboard-toolbar">
                <button id="btn-hide" type="button" aria-label="Afficher ou masquer le menu" aria-controls="left-panel" aria-expanded="true">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M9 3v18" />
                    </svg>
                    <span>Menu</span>
                </button>
            </div>

            <div id="content">
                <section class="dashboard-welcome" aria-labelledby="welcome-title">
                    <div class="welcome-card">
                        <svg class="welcome-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <path d="M9 3v18M9 9h12" />
                        </svg>
                        <h1 id="welcome-title">Bienvenue dans votre espace</h1>
                        <?php if ($isAdmin): ?>
                            <p>Sélectionnez une rubrique dans le menu pour commencer.</p>
                        <?php else: ?>
                            <p>Vous êtes connecté à votre tableau de bord.</p>
                        <?php endif ?>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- Comptes connectés sur cet appareil : bascule sans ressaisir le mot de passe -->
    <div id="account-menu" class="account-menu" role="menu" aria-labelledby="account-menu-button" data-csrf="<?= htmlspecialchars($_SESSION['prefa_csrf']) ?>" hidden>
        <p class="account-menu-title">Comptes connectés</p>

        <ul class="account-list">
            <?php foreach ($comptes as $compte):
                $estActif = $compte['identifiant'] === $_SESSION['identifiant'];
                $nomComplet = trim($compte['name'] . ' ' . $compte['prenom']);
            ?>
                <li class="account-row">
                    <button type="button" class="account-item<?= $estActif ? ' is-current' : '' ?>" role="menuitemradio" aria-checked="<?= $estActif ? 'true' : 'false' ?>" data-compte="<?= htmlspecialchars($compte['identifiant']) ?>">
                        <span class="account-avatar">
                            <span class="user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($compte['name']), 0, 1))) ?></span>
                            <span class="account-presence" aria-hidden="true"></span>
                        </span>
                        <span class="account-identity">
                            <strong><?= htmlspecialchars($nomComplet) ?></strong>
                            <span><?= htmlspecialchars($compte['role_name']) ?><?php if ($metier = roleComplement((int) $compte['id_role'])): ?> · <?= htmlspecialchars($metier) ?><?php endif; ?></span>
                        </span>
                        <?php if ($estActif): ?>
                            <svg class="icon account-check" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m5 13 4 4 10-10" />
                            </svg>
                        <?php endif; ?>
                    </button>
                    <button type="button" class="account-remove" data-compte="<?= htmlspecialchars($compte['identifiant']) ?>" data-nom="<?= htmlspecialchars($nomComplet) ?>" aria-label="Retirer le compte <?= htmlspecialchars($nomComplet) ?>">Retirer</button>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="account-menu-separator"></div>

        <button type="button" class="account-add" role="menuitem">
            <span class="account-action-icon" aria-hidden="true">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </span>
            Ajouter un compte
        </button>

        <button type="button" class="account-manage" role="menuitem" aria-pressed="false">
            <span class="account-action-icon" aria-hidden="true">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1" />
                    <circle cx="12" cy="12" r="3.5" />
                </svg>
            </span>
            <span class="account-manage-label">Gérer les comptes</span>
        </button>

        <div class="account-menu-separator"></div>

        <a class="account-logout" role="menuitem" href="../auth/logout.php">
            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                <path d="M16 17l5-5-5-5M21 12H9" />
            </svg>
            Se déconnecter
        </a>
    </div>

    <!-- Choix du thème : la valeur active est relue de localStorage au chargement -->
    <div id="theme-menu" class="account-menu theme-menu" role="menu" aria-labelledby="theme-menu-button" hidden>
        <p class="account-menu-title">Thème</p>

        <button type="button" class="account-item theme-option" role="menuitemradio" aria-checked="false" data-theme-val="default">
            <span class="theme-swatch theme-swatch-default" aria-hidden="true"></span>
            <span class="account-identity"><strong>Sombre</strong></span>
            <svg class="icon account-check" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" hidden>
                <path d="m5 13 4 4 10-10" />
            </svg>
        </button>

        <button type="button" class="account-item theme-option" role="menuitemradio" aria-checked="false" data-theme-val="light">
            <span class="theme-swatch theme-swatch-light" aria-hidden="true"></span>
            <span class="account-identity"><strong>Clair</strong></span>
            <svg class="icon account-check" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" hidden>
                <path d="m5 13 4 4 10-10" />
            </svg>
        </button>

        <button type="button" class="account-item theme-option" role="menuitemradio" aria-checked="false" data-theme-val="pink">
            <span class="theme-swatch theme-swatch-pink" aria-hidden="true"></span>
            <span class="account-identity"><strong>Rose</strong></span>
            <svg class="icon account-check" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" hidden>
                <path d="m5 13 4 4 10-10" />
            </svg>
        </button>
    </div>

    <dialog id="account-dialog" class="account-dialog" aria-labelledby="account-dialog-title">
        <form id="account-form" method="post">
            <div class="account-dialog-heading">
                <h2 id="account-dialog-title">Ajouter un compte</h2>
                <button type="button" class="account-dialog-close modal-close" aria-label="Fermer"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg></button>
            </div>

            <p class="account-dialog-intro">Connectez-vous à un autre compte : vous passerez ensuite de l’un à l’autre sans ressaisir de mot de passe.</p>

            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['prefa_csrf']) ?>">
            <input type="hidden" name="operation" value="add">

            <div class="form-group">
                <label for="account-login">Identifiant</label>
                <input id="account-login" name="login" type="text" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="account-password">Mot de passe</label>
                <input id="account-password" name="password" type="password" required autocomplete="current-password">
            </div>

            <p class="account-message" role="status" aria-live="polite"></p>

            <div class="account-dialog-footer">
                <button type="button" class="account-dialog-close">Annuler</button>
                <button type="submit" class="account-submit">Se connecter</button>
            </div>
        </form>
    </dialog>

    <!-- All scripts js -->
    <script src="https://cdn.jsdelivr.net/npm/htmx.org@2.0.11/dist/htmx.js" integrity="sha384-gmJEF2eAKY4e+FDN+qtKIivWyb6ANwDB7JUdUybKgQspPKyEX/pIRZG/0uaRoW2C" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-4.0.0.min.js" integrity="sha256-OaVG6prZf4v69dPg6PhVattBXkcOWQB62pdZ3ORyrao=" crossorigin="anonymous"></script>
    <!-- En premier : sa touche Échap ferme la liste ouverte avant que le volet latéral ou
         une fenêtre ne la prennent pour eux. -->
    <script src="../js/suggestions.js?v=<?= hash_file('sha256', __DIR__ . '/../js/suggestions.js') ?>"></script>
    <script src="../js/dashboard-navigation.js?v=<?= hash_file('sha256', __DIR__ . '/../js/dashboard-navigation.js') ?>"></script>
    <script src="../js/dashboard-forms.js?v=<?= hash_file('sha256', __DIR__ . '/../js/dashboard-forms.js') ?>"></script>
    <script src="../js/prefa-interactions.js?v=<?= hash_file('sha256', __DIR__ . '/../js/prefa-interactions.js') ?>"></script>
    <script src="../js/planning.js?v=<?= hash_file('sha256', __DIR__ . '/../js/planning.js') ?>"></script>
    <script src="../js/atelier.js?v=<?= hash_file('sha256', __DIR__ . '/../js/atelier.js') ?>"></script>
    <script src="../js/tableaux-plan.js?v=<?= hash_file('sha256', __DIR__ . '/../js/tableaux-plan.js') ?>"></script>
    <script src="../js/plan-viewer.js?v=<?= hash_file('sha256', __DIR__ . '/../js/plan-viewer.js') ?>"></script>
    <script src="../js/dashboard-sidebar.js?v=<?= hash_file('sha256', __DIR__ . '/../js/dashboard-sidebar.js') ?>"></script>
    <script src="../js/dashboard-accounts.js?v=<?= hash_file('sha256', __DIR__ . '/../js/dashboard-accounts.js') ?>"></script>
    <script src="../js/liste-filtrable.js?v=<?= hash_file('sha256', __DIR__ . '/../js/liste-filtrable.js') ?>"></script>
    <script src="../js/create-users.js?v=<?= hash_file('sha256', __DIR__ . '/../js/create-users.js') ?>"></script>
    <script src="../js/iso.js?v=<?= hash_file('sha256', __DIR__ . '/../js/iso.js') ?>"></script>
    <script src="../js/pv.js?v=<?= hash_file('sha256', __DIR__ . '/../js/pv.js') ?>"></script>
</body>

</html>
