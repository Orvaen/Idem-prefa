<?php

require_once __DIR__ . '/../../modeles/autoload.php';

/**
 * Le menu du tableau de bord, déclaré une fois.
 *
 * Chaque entrée était écrite à trois endroits : le bouton et son icône dans dashboard.php,
 * entouré d'un if sur le rôle ; l'adresse de la page dans dashboard-navigation.js ; et les
 * feuilles de style et scripts de l'écran dans l'en-tête, envoyés à tout le monde. Rien ne
 * reliait les trois, et on ne pouvait pas savoir, en lisant l'un, ce que faisaient les
 * autres.
 *
 * Tout est ici : qui voit l'entrée, quelle page elle ouvre, et de quoi cet écran a besoin
 * pour s'afficher. La barre se dessine par une boucle, la navigation lit la même table, et
 * l'en-tête n'envoie que les ressources des écrans que ce rôle peut ouvrir.
 */

/**
 * « defaut » marque l'écran déjà affiché au chargement : son entrée arrive active, sans
 * que la barre ait à deviner lequel c'est.
 *
 * @return list<array{id:string, libelle:string|callable, page:string, pour:list<Role>,
 *                    icone:string, styles:list<string>, scripts:list<string>,
 *                    defaut?:bool}>
 *         dans l'ordre d'affichage
 */
function dashboardMenu(): array
{
    // Les écrans du plan ISO partagent leur outillage : le visualiseur, les tableaux
    // qu'on y lit, la liste des affectations. C'est le plus lourd du tableau de bord.
    $ressourcesIso = [
        'styles' => ['dashboard/atelier.css', 'dashboard/plan-viewer.css'],
        'scripts' => ['atelier.js', 'plan-viewer.js', 'tableaux-plan.js'],
    ];

    return [
        [
            // Le retour à l'accueil. Il n'y avait aucun moyen d'y revenir sans recharger
            // tout le tableau de bord : une fois sur un écran, on y restait.
            'id' => 'accueil',
            'rayon' => 'avant',
            'defaut' => true,
            'libelle' => 'Accueil',
            'page' => 'pages/accueil.php',
            'pour' => [],   // vide : tout le monde
            'icone' => '<path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2h-4v-6H9v6H5a2 2 0 0 1-2-2v-9Z" />',
            'styles' => [],
            'scripts' => [],
        ],
        [
            'id' => 'create-user',
            'rayon' => 'administration',
            'libelle' => 'Créer un utilisateur',
            'page' => 'pages/create_users.php',
            'pour' => [Role::ChargeDeProduction],
            'icone' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M19 8v6M22 11h-6" />',
            'styles' => [],
            // Plus de script propre à cet écran : son champ « Rôle » est le composant
            // filtrable commun, et liste-filtrable.js est dans le socle.
            'scripts' => [],
        ],
        [
            'id' => 'list-users',
            'rayon' => 'administration',
            'libelle' => 'Liste des utilisateurs',
            'page' => 'pages/lists_users.php',
            'pour' => [Role::ChargeDeProduction],
            'icone' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M19 8v6M22 11h-6" />',
            'styles' => [],
            'scripts' => [],
        ],
        [
            'id' => 'settings',
            'rayon' => 'administration',
            'libelle' => 'Paramètres',
            'page' => 'pages/settings.php',
            'pour' => [Role::ChargeDeProduction],
            'icone' => '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1" /><circle cx="12" cy="12" r="3.5" />',
            'styles' => [],
            'scripts' => [],
        ],
        [
            // C'est le chargé de production qui ouvre un chantier et désigne qui le suit.
            'id' => 'affaires',
            'rayon' => 'affaires',
            'libelle' => 'Affaires',
            'page' => 'pages/affaires.php',
            'pour' => [Role::ChargeDeProduction],
            'icone' => '<path d="M3 7h18v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" /><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" /><path d="M3 12h18" />',
            'styles' => ['dashboard/affaires.css'],
            'scripts' => ['affaires.js'],
        ],
        [
            // « Nouvelle demande » et non « Créer une affaire » : l'affaire est ouverte par
            // le chargé de production, et une affaire porte plusieurs demandes.
            'id' => 'prefa',
            'rayon' => 'avant',
            'libelle' => 'Nouvelle demande',
            'page' => 'pages/create_prefa.php',
            'pour' => [Role::ChargeDAffaire],
            'icone' => '<path d="m10 3 7 4-7 4-7-4 7-4Z" /><path d="M3 7v9l7 4v-9M17 7v5M10 20l3-1.7" /><path d="M18 15v6M15 18h6" />',
            'styles' => [],
            // Le formulaire s'ouvre aussi depuis la liste, pour modifier une demande — et
            // le chargé de production, qui n'en dépose pas, en modifie. Ses scripts sont
            // donc dans le socle et non ici.
            'scripts' => [],
        ],
        [
            // La seule entrée que tout le monde voit, et la seule dont le nom change : on
            // ne valide pas, on suit, on pointe ou on consulte selon le métier.
            'id' => 'list-prefa',
            'rayon' => 'affaires',
            'libelle' => static fn (Utilisateur $utilisateur): string => match (true) {
                $utilisateur->est(Role::ChargeDeProduction) => 'Valider les demandes',
                $utilisateur->est(Role::ChefDAtelier) => 'Suivi des demandes',
                // « Mes affaires » pour l'atelier : ce qu'il ouvre ici, ce sont les plans
                // qu'on lui a confiés, et c'est le mot qu'il emploie. Le chargé d'affaire
                // a le même sur sa tuile d'accueil, pour ses chantiers — les deux ne se
                // croisent jamais, chacun ne voit que son propre menu.
                $utilisateur->est(Role::PersonnelAtelier) => 'Mes affaires',
                // Et pour lui, ici, ce sont ses demandes, rangées sous le nom du chantier :
                // une affaire est une entité à part depuis, et deux choses différentes ne
                // peuvent pas porter le même mot.
                default => 'Mes demandes',
            },
            'page' => 'pages/lists_prefa.php',
            'pour' => [],   // vide : tout le monde
            'icone' => '<rect x="5" y="4" width="14" height="17" rx="2" /><path d="M9 4V2h6v2M9 10l2 2 4-4M9 16h6" />',
            'styles' => [],
            'scripts' => [],
        ],
        [
            // Pour le personnel d'atelier, « Mes affaires » rend en réalité atelier.php :
            // c'est donc par cette entrée-là qu'il reçoit l'outillage du plan ISO.
            //
            // L'entrée porte le nom de ce qu'on vient y faire. Elle s'est appelée « Mes
            // affaires » le temps d'un essai : le mot ne dit pas ce qu'il y a derrière, et
            // un soudeur qui ouvre la barre cherche ses soudures, pas ses affaires.
            //
            // Le suivi matériel suit les soudures : c'est le même plan qu'on y travaille,
            // et la barre le dit plutôt que d'ouvrir une deuxième entrée pour le même
            // écran.
            'id' => 'soudures',
            'rayon' => 'atelier',
            'libelle' => 'Mes soudures & Suivi matériel',
            'page' => 'pages/soudures.php',
            'pour' => [Role::PersonnelAtelier],
            // Un cordon, et son repère.
            'icone' => '<path d="M4 15c2-3 4-3 6 0s4 3 6 0 2-3 4-1" /><path d="M8 5h8M12 5v4" />',
            'styles' => [...$ressourcesIso['styles'], 'dashboard/soudures.css'],
            'scripts' => [...$ressourcesIso['scripts'], 'soudures.js'],
        ],
        [
            // Le pendant de « Mes soudures » côté encadrement : l'atelier entier d'un coup,
            // au lieu d'ouvrir une demande après l'autre pour savoir où en sont les cordons.
            'id' => 'suivi-soudures',
            'rayon' => 'atelier',
            'libelle' => 'Soudures',
            'page' => 'pages/suivi_soudures.php',
            'pour' => [Role::ChargeDeProduction, Role::ChefDAtelier],
            'icone' => '<path d="M4 15c2-3 4-3 6 0s4 3 6 0 2-3 4-1" /><path d="M8 5h8M12 5v4" />',
            'styles' => ['dashboard/atelier.css'],
            'scripts' => ['atelier.js', 'suivi-soudures.js'],
        ],
        [
            'id' => 'plans-iso',
            'rayon' => 'atelier',
            'libelle' => 'Plans / ISO & Affectations',
            'page' => 'pages/plans_iso.php',
            'pour' => [Role::ChargeDeProduction, Role::ChefDAtelier],
            'icone' => '<polygon points="12 2 2 7 12 12 22 7 12 2" /><polyline points="2 17 12 22 22 17" /><polyline points="2 12 12 17 22 12" />',
            'styles' => $ressourcesIso['styles'],
            'scripts' => [...$ressourcesIso['scripts'], 'iso.js'],
        ],
        [
            'id' => 'planning',
            'rayon' => 'atelier',
            'libelle' => 'Planning',
            'page' => 'pages/planning.php',
            'pour' => [Role::ChargeDeProduction, Role::ChefDAtelier],
            'icone' => '<rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 11h18M7 15h3M14 15h3M7 18h3" />',
            'styles' => ['dashboard/planning.css'],
            'scripts' => ['planning.js'],
        ],
        [
            // Le bon de commande est déposé sur la demande, mais il ne se lit pas dans sa
            // fiche : quarante articles entre les documents et la décision, et on ne la
            // lisait plus. Il a son écran, où l'encadrement fait lire les bons et corrige
            // ce que la reconnaissance a mal compris.
            'id' => 'commande-materiel',
            'rayon' => 'atelier',
            'libelle' => 'Commande matériel',
            'page' => 'pages/commande_materiel.php',
            'pour' => [Role::ChargeDeProduction, Role::ChefDAtelier],
            'icone' => '<path d="M3 6h18l-1.5 10.5a2 2 0 0 1-2 1.5H6.5a2 2 0 0 1-2-1.5L3 6Z" /><path d="M8 6V4.5A2.5 2.5 0 0 1 10.5 2h3A2.5 2.5 0 0 1 16 4.5V6" /><path d="m9.5 11.5 2 2 3.5-3.5" />',
            'styles' => ['dashboard/commande-materiel.css'],
            'scripts' => ['commande-materiel.js'],
        ],
        [
            'id' => 'pv',
            'rayon' => 'atelier',
            'libelle' => 'Avancement préfabrication',
            'page' => 'pages/pv.php',
            'pour' => [Role::ChargeDeProduction, Role::ChefDAtelier, Role::ChargeDAffaire],
            'icone' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path d="M14 3v5h5" /><path d="m9 14 2 2 4-4" />',
            // Le tableau des commandes fournisseur vit dans le PV « Réception matériel ».
            'styles' => ['dashboard/pv.css', 'dashboard/commandes.css'],
            'scripts' => ['pv.js', 'commandes.js'],
        ],
        [
            'id' => 'urgent-prefa',
            'rayon' => 'affaires',
            'libelle' => 'Demandes urgentes',
            'page' => 'pages/lists_prefa.php?view=urgent',
            'pour' => [Role::ChargeDeProduction],
            'icone' => '<path d="M12 3 2.8 19h18.4L12 3Z" /><path d="M12 9v4M12 16h.01" />',
            'styles' => [],
            'scripts' => [],
        ],
    ];
}

/**
 * La pastille d'une entrée, quand elle en porte une.
 *
 * Trois entrées en ont, et pas la même : celle de la liste des demandes est relue par le
 * JavaScript, qui la cherche par son identifiant ; celle des PV n'est là que s'il en reste
 * à faire ; celle des demandes urgentes double la première et n'a donc rien à annoncer aux
 * lecteurs d'écran. Les trois sont écrites ici plutôt que dispersées dans le balisage.
 *
 * data-compteur dit ce que la pastille compte. Les trois portaient la seule classe
 * nav-urgent-badge, et la mise à jour des urgentes les repeignait toutes : les PV restant
 * à renseigner tombaient au nombre de demandes urgentes à chaque rafraîchissement.
 */
function dashboardBadge(string $id, Utilisateur $utilisateur, int $urgentes, int $pvAFaire): string
{
    $pastille = static fn (string $quoi, string $attributs, int $compte): string =>
        '<span class="nav-urgent-badge" data-compteur="' . $quoi . '" ' . $attributs
        . ($compte === 0 ? ' hidden' : '') . '>' . $compte . '</span>';

    return match ($id) {
        'list-prefa' => $utilisateur->est(Role::ChargeDeProduction)
            ? $pastille('urgentes', 'id="urgent-pending-count" aria-live="polite"'
                . ' aria-label="' . $urgentes . ' demandes urgentes en attente"'
                . ' title="Demandes urgentes en attente"', $urgentes)
            : '',
        'pv' => $pvAFaire === 0 ? '' : $pastille('pv', 'aria-label="' . $pvAFaire . ' PV à renseigner"'
            . ' title="PV encore à renseigner"', $pvAFaire),
        'urgent-prefa' => $pastille('urgentes', 'aria-hidden="true"', $urgentes),
        default => '',
    };
}

/**
 * Les rayons de la barre, dans l'ordre, et ce qu'ils annoncent.
 *
 * Onze entrées à la file, toutes de la même taille, ne disaient pas ce qui allait avec
 * quoi : on relisait la liste entière pour retrouver le planning. Elles se rangent
 * maintenant sous trois intitulés, et chaque rayon se replie.
 *
 * « avant » n'en est pas un : c'est ce qui passe au-dessus de tout, l'action qu'on vient
 * faire en arrivant.
 */
function dashboardRayons(): array
{
    return [
        'affaires' => 'Affaires & demandes',
        'atelier' => 'Atelier',
        'administration' => 'Administration',
    ];
}

/**
 * Les entrées visibles, rangées par rayon et dans l'ordre des rayons.
 *
 * @return array{avant: list<array>, rayons: array<string, array{libelle: string, entrees: list<array>}>}
 */
function dashboardMenuRange(Utilisateur $utilisateur): array
{
    $avant = [];
    $rayons = [];
    foreach (dashboardRayons() as $cle => $libelle) $rayons[$cle] = ['libelle' => $libelle, 'entrees' => []];

    foreach (dashboardMenuVisible($utilisateur) as $entree) {
        $rayon = $entree['rayon'] ?? 'affaires';
        if ($rayon === 'avant') { $avant[] = $entree; continue; }
        $rayons[$rayon]['entrees'][] = $entree;
    }

    // Un rayon vide ne s'annonce pas : le chargé d'affaire n'a rien dans « Atelier ».
    return ['avant' => $avant, 'rayons' => array_filter($rayons, static fn (array $r): bool => (bool) $r['entrees'])];
}

/** Les entrées que cette personne voit. Une liste « pour » vide veut dire : tout le monde. */
function dashboardMenuVisible(Utilisateur $utilisateur): array
{
    return array_values(array_filter(
        dashboardMenu(),
        static fn (array $entree): bool => !$entree['pour'] || $utilisateur->est(...$entree['pour'])
    ));
}

/**
 * Les feuilles de style et les scripts dont ce rôle peut avoir besoin, et eux seuls.
 *
 * Le socle est ce qui habille le tableau de bord lui-même : la barre, les thèmes, les
 * formulaires, le menu des comptes. Le reste arrive par les écrans. Un chargé d'affaire
 * recevait ainsi le visualiseur de plans — soixante-dix kilo-octets — pour un écran que le
 * serveur lui refuse en 403.
 *
 * @return array{styles: list<string>, scripts: list<string>} chemins relatifs à src/
 */
function dashboardRessources(Utilisateur $utilisateur): array
{
    $styles = [
        'css/dashboard/settings.css',
        'css/dashboard/dashboard-base.css',
        'css/dashboard/dashboard-forms.css',
        'css/dashboard/prefa-form.css',
        'css/dashboard/prefa-list.css',
        // La nomenclature et les suggestions habillent des tableaux et des champs qu'on
        // retrouve dans plusieurs écrans, pour sept kilo-octets à eux deux.
        'css/dashboard/nomenclature.css',
        'css/dashboard/suggestions.css',
        'css/floating-fields.css',
        'css/dashboard/accounts.css',
        'css/dashboard/flux.css',
        'css/dashboard/selection.css',
        'css/dashboard/filtres.css',
    ];
    $scripts = [
        'js/suggestions.js',
        'js/dashboard-navigation.js',
        'js/dashboard-forms.js',
        'js/prefa-interactions.js',
        // Le formulaire de demande s'ouvre depuis deux entrées — « Créer une affaire » et
        // la modification depuis la liste — et par deux rôles différents. Dix kilo-octets
        // pour tout le monde plutôt qu'un écran dont les listes déroulantes ne répondent
        // pas selon par où on y est entré.
        'js/liste-filtrable.js',
        'js/dashboard-sidebar.js',
        'js/dashboard-accounts.js',
        // L'œil des mots de passe : la fenêtre d'ajout de compte en a un, l'écran de
        // création d'utilisateur aussi.
        'js/mot-de-passe.js',
        // La sélection de lignes : une table marquée data-selection suffit à l'activer.
        'js/liste-selection.js',
        // La barre de filtres à pastilles.
        'js/filtres.js',
        // Le flux d'événements : la page écoute ce que le serveur annonce. Chacun n'y
        // reçoit que ce qu'il a le droit de voir — c'est le serveur qui filtre, avec la
        // règle de prefa_visibilite.php, la même que la liste des demandes.
        'js/flux.js',
    ];

    foreach (dashboardMenuVisible($utilisateur) as $entree) {
        foreach ($entree['styles'] as $feuille) $styles[] = 'css/' . $feuille;
        foreach ($entree['scripts'] as $script) $scripts[] = 'js/' . $script;
    }

    // Le thème clair en dernier : il redéfinit ce que les autres ont posé.
    $styles[] = 'css/dashboard/light-theme.css';

    return ['styles' => array_values(array_unique($styles)), 'scripts' => array_values(array_unique($scripts))];
}
