<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../modeles/autoload.php';
require_once __DIR__ . '/includes/comptes.php';
require_once __DIR__ . '/includes/vue/user_display.php';
require_once __DIR__ . '/includes/menu.php';
require_once __DIR__ . '/includes/demande/accueil.php';
require_once __DIR__ . '/includes/demande/affaires_nouvelles.php';
require_once __DIR__ . '/includes/vue/parametres.php';

Session::demarrer();
header('Cache-Control: no-store');

$db = new MyPDO(__DIR__ . '/../my_setting.ini');

// Tout est relu en base à chaque affichage : un compte renommé, changé de rôle ou
// supprimé doit se voir ici, pas à la prochaine connexion.
$utilisateur = Session::utilisateur($db);
if (!$utilisateur) {
    header('Location: ' . (Session::connectee() ? '../auth/logout.php' : '../'));
    exit;
}
Session::jeton();

$name = $utilisateur->nom;
$prenom = $utilisateur->prenom;
$initial = mb_strtoupper(mb_substr(trim($name), 0, 1));
$urgentPending = 0;

$actor = ['id' => $utilisateur->id, 'id_role' => $utilisateur->idRole, 'role_name' => $utilisateur->roleEnBase];
$roleId = $utilisateur->idRole;
$role = $utilisateur->roleEnBase;

$isAdmin = $utilisateur->est(Role::ChargeDeProduction);
$isWorkshopChief = $utilisateur->est(Role::ChefDAtelier);
$isWorkshopPersonnel = $utilisateur->est(Role::PersonnelAtelier);
$isRequester = $utilisateur->est(Role::ChargeDAffaire);

// Comptes connectés sur cet appareil, pour le menu de bascule du pied de la barre latérale.
$comptes = comptesList($db);

// En développement seulement : la liste de tous les comptes, pour prendre la place de l'un
// d'eux sans son mot de passe et essayer les quatre profils. Depuis n'importe quel compte
// et non plus du seul administrateur — sinon, passé sur un compte d'atelier, la liste
// disparaissait et il fallait repasser par l'administrateur pour sauter ailleurs.
//
// En production, APP_ENV vaut « prod » et cette requête n'est même pas faite.
$comptesDev = [];
if (Environnement::estDev()) {
    $comptesDev = $db->query("SELECT u.identifiant, u.name, u.prenom, u.id_role, r.name AS role_name
        FROM Utilisateur u JOIN role r ON r.id = u.id_role
        ORDER BY u.id_role, u.prenom, u.name")->fetchAll(PDO::FETCH_ASSOC);
}

// Ce que cette personne peut ouvrir, et donc ce qu'il faut lui envoyer.
$entrees = dashboardMenuRange($utilisateur);
$ressources = dashboardRessources($utilisateur);

// L'écran demandé par l'adresse, rendu tout de suite. Les styles et les scripts de tous
// les écrans de cette personne sont déjà dans la page : il n'y a rien à charger en plus.
$vue = dashboardVueDemandee($utilisateur, isset($_GET['vue']) ? (string) $_GET['vue'] : null);

// Une entrée de menu peut porter un filtre dans son adresse — « Demandes urgentes », c'est
// la liste des demandes resserrée sur les urgentes. Le navigateur passe cette adresse
// telle quelle à $.load() ; ici il faut séparer le fichier de son filtre, et poser le
// filtre dans $_GET comme l'aurait fait une vraie requête. Le filtre du menu l'emporte :
// c'est lui qui définit l'écran, l'adresse n'a pas à le contredire.
[$vueFichier, $vueFiltre] = array_pad(explode('?', $vue['page'], 2), 2, '');
if ($vueFiltre !== '') {
    parse_str($vueFiltre, $vueParametres);
    $_GET = $vueParametres + $_GET;
}

// L'accueil : les chiffres du métier de cette personne, et ce qui a bougé aujourd'hui.
// Deux requêtes qu'on ne fait que si c'est bien lui qu'on affiche — elles tournaient
// jusqu'ici même quand l'adresse demandait un autre écran.
$tuiles = $activite = $affairesNouvelles = [];
// Les affaires confiées et pas encore vues : la pastille « +N » de l'entrée Accueil.
// L'accueil les montre, et les avoir montrées vaut lecture : la pastille repart à zéro.
$affairesNouvellesCompte = affairesNouvellesCompte($db, $utilisateur->id);
if ($vue['id'] === 'accueil') {
    $tuiles = accueilTuiles($db, $utilisateur);
    $activite = accueilActivite($db, $utilisateur);
    $affairesNouvelles = affairesNouvelles($db, $utilisateur->id);
    affairesNouvellesMarquerVues($db, $utilisateur->id, $affairesNouvelles);
    $affairesNouvellesCompte = 0;
}

// Nombre de demandes urgentes en attente, affiché à l'administrateur
if ($isAdmin) {
    $urgentPending = (int) $db->query('SELECT COUNT(*) FROM demande_prefabrication WHERE urgent = 1 AND id_statut = 1')->fetchColumn();
}

// PV restant à renseigner : la même règle que l'écran des PV et que l'accueil.
$pvAFaire = ($isAdmin || $isWorkshopChief) ? pvAFaireTotal($db) : 0;

// Les manques de matériel signalés par l'atelier et que personne n'a encore regardés.
require_once __DIR__ . '/includes/materiel/manques.php';
$manquesNouveaux = ($isAdmin || $isWorkshopChief) ? manquesNouveauxCompte($db) : 0;
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="icon" href="data:,">
    <!-- Le thème est posé avant le premier rendu, sinon l'écran clignote le temps que la
         feuille s'applique. Il ne peut donc pas attendre le chargement différé du socle.
         theme.js décide : le choix mémorisé s'il y en a un, celui du système sinon. -->
    <script src="../js/composants/theme.js?v=<?= filemtime(__DIR__ . '/../js/composants/theme.js') ?>"></script>

    <!-- Les feuilles de style des écrans que ce rôle peut ouvrir, et elles seules : la
         liste est déclarée avec le menu, dans includes/menu.php. -->
    <?php foreach ($ressources['styles'] as $feuille): ?>
        <link rel="stylesheet" href="../<?= $feuille ?>?v=<?= filemtime(__DIR__ . '/../' . $feuille) ?>" />
    <?php endforeach; ?>
    <script src="../js/composants/floating-fields.js?v=<?= filemtime(__DIR__ . '/../js/composants/floating-fields.js') ?>" defer></script>
</head>

<body>
    <div id="contenaire">
        <!-- La barre du haut, posée sur le cadre et non dans la vue : elle tient toute la
             largeur de l'écran, au-dessus de la carte de travail. C'est la forme des outils
             de suivi modernes, et elle a une raison — la marque, le compte et le thème ne
             dépendent pas de l'écran ouvert, ils ne doivent donc pas vivre dans le panneau
             qui, lui, change à chaque navigation. -->
        <header class="barre-haut">
            <div class="barre-haut-zone barre-haut-gauche">
                <button id="btn-hide" type="button" aria-label="Afficher ou masquer le menu" aria-controls="left-panel" aria-expanded="true">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M9 3v18" />
                    </svg>
                    <span>Menu</span>
                </button>

                <?php
                // La marque, en tête de barre. Le fichier est cherché sur le disque : déposez
                // logo-idem.svg (ou .png, .webp) dans src/assets/ et il remplace le mot, sans
                // qu'il y ait une ligne à changer ici. Tant qu'il n'y est pas, le nom s'écrit
                // en toutes lettres — mieux qu'un cadre vide ou qu'un logo approximatif.
                $logo = null;
                foreach (['svg', 'png', 'webp'] as $extension) {
                    if (is_file(__DIR__ . '/../assets/logo-idem.' . $extension)) {
                        $logo = '../assets/logo-idem.' . $extension;
                        break;
                    }
                }
                ?>
                <div class="nav-marque">
                    <?php if ($logo):
                        // Une version pour le thème sombre, à côté de l'autre : le dessin est fait
                        // pour du papier blanc, et ses contours noirs disparaissent sur fond sombre.
                        $logoSombre = preg_replace('/(\.[a-z]+)$/', '-sombre$1', $logo);
                        $aLogoSombre = is_file(__DIR__ . '/../assets/' . basename($logoSombre)); ?>
                        <img class="nav-marque-logo<?= $aLogoSombre ? ' nav-marque-logo-clair' : '' ?>" src="<?= htmlspecialchars($logo) ?>?v=<?= filemtime(__DIR__ . '/../assets/' . basename($logo)) ?>" alt="IDEM">
                        <?php if ($aLogoSombre): ?>
                            <img class="nav-marque-logo nav-marque-logo-sombre" src="<?= htmlspecialchars($logoSombre) ?>?v=<?= filemtime(__DIR__ . '/../assets/' . basename($logoSombre)) ?>" alt="" aria-hidden="true">
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="nav-marque-mot">IDEM</span>
                    <?php endif; ?>
                    <?php // Un repère permanent : on ne doit jamais croire qu'on est en production
                    // alors qu'on ne l'est pas. Il ne paraît qu'en développement. 
                    ?>
                    <?php if (Environnement::estDev()): ?>
                        <span class="nav-marque-dev" title="APP_ENV=dev — les commodités de développement sont ouvertes">dev</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="barre-haut-zone barre-haut-centre">
            <!-- La recherche, au centre, comme chez les outils de suivi. Ce n'est pas un
                 champ : c'est le déclencheur de la palette, qui existait déjà et ne
                 s'ouvrait qu'au clavier — un raccourci que personne ne devine. Il en porte
                 la forme pour qu'on sache où cliquer, et la touche pour qu'on l'apprenne. -->
            <button type="button" id="barre-recherche" class="barre-recherche" aria-label="Rechercher un écran">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.6-3.6" />
                </svg>
                <span class="barre-recherche-texte">Rechercher un écran…</span>
                <kbd class="barre-recherche-touche">Ctrl K</kbd>
            </button>
            </div>

            <div class="barre-haut-zone barre-haut-droite">
                <!-- Le compte, le thème et la déconnexion : en haut à droite, là où on les
                     cherche, et non au pied de la barre de navigation où ils se confondaient
                     avec les rubriques. -->
                <div class="toolbar-compte">
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
            </div>
        </header>

        <!-- La vue : une seule carte, qui tient la navigation et le travail. Les deux
             étaient deux cartes séparées par une gouttière ; elles n'ont jamais été deux
             objets, mais un seul, séparé par un filet. -->
        <div class="vue">
            <!-- Navigation de gauche -->
            <nav id="left-panel">
                <div class="sidebar-mobile-header">
                    <strong>Navigation</strong>
                    <button id="close-sidebar" type="button" aria-label="Fermer le menu">×</button>
                </div>
                <div class="nav-header">
                    <!-- Le menu est déclaré dans includes/menu.php : qui voit quoi, quelle page
                         chaque entrée ouvre, de quoi cet écran a besoin, et sous quel rayon il se
                         range. La barre n'a plus qu'à le parcourir — pas un if de rôle ici.

                         data-page porte l'adresse : la navigation la lit ici même, au lieu de
                         tenir sa propre table d'identifiants dans un autre fichier. -->

                    <!-- L'action qu'on vient faire en arrivant, au-dessus des rayons. -->
                    <?php foreach ($entrees['avant'] as $entree): ?>
                        <?php $libelle = is_callable($entree['libelle']) ? ($entree['libelle'])($utilisateur) : $entree['libelle']; ?>
                        <button id="btn-<?= $entree['id'] ?>" class="nav-btn nav-btn-avant<?= $entree['id'] === $vue['id'] ? ' is-active' : '' ?>" type="button" data-page="<?= htmlspecialchars($entree['page'], ENT_QUOTES, 'UTF-8') ?>" <?= $entree['id'] === $vue['id'] ? ' aria-current="page"' : '' ?>>
                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><?= $entree['icone'] ?></svg>
                            <span><?= htmlspecialchars($libelle, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($entree['id'] === 'accueil'): ?>
                                <!-- « +1 » : une affaire vient d'être confiée et n'a pas encore été vue.
                                     flux.js la pose en direct, l'accueil la remet à zéro. -->
                                <span class="nav-urgent-badge" data-compteur="affaires" title="Nouvelles affaires confiées"
                                      aria-label="<?= (int) $affairesNouvellesCompte ?> nouvelle(s) affaire(s)"<?= $affairesNouvellesCompte ? '' : ' hidden' ?>>+<?= (int) $affairesNouvellesCompte ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>

                    <?php foreach ($entrees['rayons'] as $cle => $rayon): ?>
                        <section class="nav-rayon" data-rayon="<?= $cle ?>">
                            <!-- L'intitulé est le bouton de repli : toute la ligne se clique, et
                                 le chevron dit seulement dans quel sens ça va. -->
                            <button type="button" class="nav-rayon-titre" aria-expanded="true" aria-controls="rayon-<?= $cle ?>">
                                <span><?= htmlspecialchars($rayon['libelle'], ENT_QUOTES, 'UTF-8') ?></span>
                                <svg class="nav-rayon-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <div class="nav-rayon-corps" id="rayon-<?= $cle ?>">
                                <?php foreach ($rayon['entrees'] as $entree): ?>
                                    <?php $libelle = is_callable($entree['libelle']) ? ($entree['libelle'])($utilisateur) : $entree['libelle']; ?>
                                    <button id="btn-<?= $entree['id'] ?>" class="nav-btn<?= $entree['id'] === $vue['id'] ? ' is-active' : '' ?>" type="button" data-page="<?= htmlspecialchars($entree['page'], ENT_QUOTES, 'UTF-8') ?>" <?= $entree['id'] === $vue['id'] ? ' aria-current="page"' : '' ?>>
                                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><?= $entree['icone'] ?></svg>
                                        <span><?= htmlspecialchars($libelle, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?= dashboardBadge($entree['id'], $utilisateur, $urgentPending, $pvAFaire, $manquesNouveaux) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <!-- Les paramètres, au pied de la barre et pour tout le monde : chacun a les
                     siens. Ce n'est pas un écran, c'est une fenêtre (includes/vue/parametres.php) :
                     le bouton ne porte donc pas de data-page, mais la palette le trouve quand même. -->
                <div class="nav-pied">
                    <button id="btn-parametres" class="nav-btn" type="button" data-ouvre-parametres>
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3" />
                            <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z" />
                        </svg>
                        <span>Paramètres</span>
                    </button>
                </div>

            </nav>

            <!-- Utilitaires -->
            <button id="sidebar-backdrop" type="button" aria-label="Fermer le menu" tabindex="-1" hidden></button>
            <div id="drag"></div>

            <!-- Navigation de droite -->
            <div id="right-panel">
                <!-- data-vue dit au navigateur quel écran est déjà là : il ne le recharge pas
                     par-dessus. L'accueil passe par sa vue et non par pages/accueil.php, parce
                     que ses chiffres sont déjà lus plus haut. -->
                <div id="content" data-vue="<?= htmlspecialchars($vue['id'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php require __DIR__ . '/' . ($vue['id'] === 'accueil' ? 'includes/vue/accueil_vue.php' : $vueFichier); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Comptes connectés sur cet appareil : bascule sans ressaisir le mot de passe -->
    <div id="account-menu" class="account-menu" role="menu" aria-labelledby="account-menu-button" data-csrf="<?= htmlspecialchars(Session::jeton()) ?>" hidden>
        <div class="account-menu-group">
            <p class="account-menu-title">Comptes</p>

            <ul class="account-list">
                <?php foreach ($comptes as $compte):
                    $estActif = $compte['identifiant'] === $utilisateur->identifiant;
                    $nomComplet = trim($compte['name'] . ' ' . $compte['prenom']);
                ?>
                    <li class="account-row">
                        <button type="button" class="account-item<?= $estActif ? ' is-current' : '' ?>" role="menuitemradio" aria-checked="<?= $estActif ? 'true' : 'false' ?>" data-compte="<?= htmlspecialchars($compte['identifiant']) ?>">
                            <span class="account-avatar">
                                <span class="user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($compte['name']), 0, 1))) ?></span>
                            </span>
                            <span class="account-identity">
                                <strong><?= htmlspecialchars($nomComplet) ?></strong>
                                <?php $roleTexte = $compte['role_name'] . (($metier = roleComplement((int) $compte['id_role'])) ? ' · ' . $metier : ''); ?>
                                <span title="<?= htmlspecialchars($roleTexte) ?>"><?= htmlspecialchars($roleTexte) ?></span>
                            </span>
                            <svg class="icon account-check" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"<?= $estActif ? '' : ' hidden' ?>><path d="m5 13 4 4 10-10" /></svg>
                        </button>
                        <button type="button" class="account-remove" data-compte="<?= htmlspecialchars($compte['identifiant']) ?>" data-nom="<?= htmlspecialchars($nomComplet) ?>" aria-label="Retirer le compte <?= htmlspecialchars($nomComplet) ?>">Retirer</button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="account-menu-separator"></div>


        <?php if ($comptesDev): ?>
            <div class="account-menu-group account-menu-dev">
                <p class="account-menu-title">
                    Développement
                    <span class="account-dev-marque">dev</span>
                </p>
                <p class="account-dev-mot">Prendre la place d’un compte, sans son mot de passe.</p>

                <ul class="account-list">
                    <?php foreach ($comptesDev as $autre):
                        if ($autre['identifiant'] === $utilisateur->identifiant) continue;
                        $nomAutre = trim($autre['prenom'] . ' ' . $autre['name']);
                    ?>
                        <li class="account-row">
                            <button type="button" class="account-item account-devenir" role="menuitem"
                                data-compte="<?= htmlspecialchars($autre['identifiant']) ?>">
                                <span class="account-avatar">
                                    <span class="user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($autre['name']), 0, 1))) ?></span>
                                </span>
                                <span class="account-identity">
                                    <strong><?= htmlspecialchars($nomAutre) ?></strong>
                                    <span><?= htmlspecialchars($autre['role_name']) ?><?php if ($metier = roleComplement((int) $autre['id_role'])): ?> · <?= htmlspecialchars($metier) ?><?php endif; ?></span>
                                </span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="account-menu-separator"></div>

        <div class="account-menu-group">
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
        </div>

        <div class="account-menu-separator"></div>

        <div class="account-menu-group">
            <!-- Les paramètres, là où Claude les range : dans le menu du compte. -->
            <button type="button" class="account-parametres" role="menuitem">
                <span class="account-action-icon" aria-hidden="true">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z" />
                    </svg>
                </span>
                Paramètres
                <kbd class="account-raccourci">Ctrl ,</kbd>
            </button>

            <a class="account-logout" role="menuitem" href="../auth/logout.php">
                <!-- Le même gabarit que « Ajouter un compte » et « Gérer les comptes » : sans lui,
                 son icône s'étirait sur 32 px au lieu de tenir dans le rail, et la ligne ne
                 s'alignait avec aucune des deux autres. -->
                <span class="account-action-icon" aria-hidden="true">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <path d="M16 17l5-5-5-5M21 12H9" />
                    </svg>
                </span>
                Se déconnecter
            </a>
        </div>
    </div>

    <!-- Choix du thème : la valeur active est relue de localStorage au chargement -->
    <div id="theme-menu" class="account-menu theme-menu" role="menu" aria-labelledby="theme-menu-button" hidden>
        <div class="account-menu-group">
            <p class="account-menu-title">Thème</p>

            <!-- « Système » d'abord : c'est l'état de départ, et le seul moyen d'y revenir
             une fois qu'on a tranché. -->
            <button type="button" class="account-item theme-option" role="menuitemradio" aria-checked="false" data-theme-val="auto">
                <span class="theme-swatch theme-swatch-auto" aria-hidden="true"></span>
                <span class="account-identity"><strong>Système</strong></span>
                <svg class="icon account-check" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" hidden>
                    <path d="m5 13 4 4 10-10" />
                </svg>
            </button>

            <button type="button" class="account-item theme-option" role="menuitemradio" aria-checked="false" data-theme-val="dark">
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
        </div>
    </div>

    <dialog id="account-dialog" class="account-dialog" aria-labelledby="account-dialog-title">
        <form id="account-form" method="post">
            <div class="account-dialog-heading">
                <h2 id="account-dialog-title">Ajouter un compte</h2>
                <button type="button" class="account-dialog-close modal-close" aria-label="Fermer"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="m6 6 12 12M18 6 6 18" />
                    </svg></button>
            </div>

            <p class="account-dialog-intro">Connectez-vous à un autre compte : vous passerez ensuite de l'un à l'autre sans ressaisir de mot de passe.</p>

            <input type="hidden" name="csrf" value="<?= htmlspecialchars(Session::jeton()) ?>">
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

    <?php parametresRendu($db, $utilisateur); ?>

    <!-- All scripts js -->
    <script src="https://cdn.jsdelivr.net/npm/htmx.org@2.0.11/dist/htmx.js" integrity="sha384-gmJEF2eAKY4e+FDN+qtKIivWyb6ANwDB7JUdUybKgQspPKyEX/pIRZG/0uaRoW2C" crossorigin="anonymous"></script>

    <?php foreach ($ressources['scripts'] as $script): ?>
        <script src="../<?= $script ?>?v=<?= hash_file('sha256', __DIR__ . '/../' . $script) ?>"></script>
    <?php endforeach; ?>
</body>

</html>