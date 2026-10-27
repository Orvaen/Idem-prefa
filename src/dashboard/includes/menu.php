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
            'icone' => '<path d="M4 10.5 12 4l8 6.5V19a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 19z"/><path d="M9.5 20.5V15a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v5.5"/>',
            'styles' => [],
            'scripts' => [],
        ],
        [
            'id' => 'create-user',
            'rayon' => 'administration',
            'libelle' => 'Créer un utilisateur',
            'page' => 'pages/create_users.php',
            'pour' => [Role::ChargeDeProduction],
            'icone' => '<circle cx="10" cy="8" r="3.5"/><path d="M3.5 20c.6-3.4 3.2-5.5 6.5-5.5 1.6 0 3 .5 4.1 1.3"/><path d="M18 14v6M15 17h6"/>',
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
            'icone' => '<circle cx="9.5" cy="8" r="3.5"/><path d="M3 20c.6-3.4 3.2-5.5 6.5-5.5S15.4 16.6 16 20"/><path d="M15.5 4.8a3.5 3.5 0 0 1 0 6.4M18.5 15c1.5.8 2.4 2.5 2.6 5"/>',
            'styles' => [],
            'scripts' => [],
        ],
        [
            'id' => 'settings',
            'rayon' => 'administration',
            'libelle' => 'Paramètres',
            'page' => 'pages/settings.php',
            'pour' => [Role::ChargeDeProduction],
            'icone' => '<path d="M4 7.5h9M17 7.5h3M4 16.5h3M11 16.5h9"/><circle cx="15" cy="7.5" r="2"/><circle cx="9" cy="16.5" r="2"/>',
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
            'icone' => '<path d="M3.5 7A1.5 1.5 0 0 1 5 5.5h4l2 2.5h8A1.5 1.5 0 0 1 20.5 9.5V18a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 18z"/><path d="M3.5 11h17"/>',
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
            'icone' => '<path d="M13.5 3.5H7A1.5 1.5 0 0 0 5.5 5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V8.5z"/><path d="M13.5 3.5v5h5"/><path d="M12 11.5v6M9 14.5h6"/>',
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
                // « Mes plans » pour l'atelier : ce qu'il ouvre ici, ce sont les plans
                // qu'on lui a confiés. L'entrée s'est appelée « Mes affectations », puis
                // « Mes affaires » — le premier est le mot du logiciel, le second celui du
                // chargé d'affaire, qui désigne ses chantiers. Celui-ci est le sien.
                $utilisateur->est(Role::PersonnelAtelier) => 'Mes plans',
                // Et pour lui, ici, ce sont ses demandes, rangées sous le nom du chantier :
                // une affaire est une entité à part depuis, et deux choses différentes ne
                // peuvent pas porter le même mot.
                default => 'Mes demandes',
            },
            'page' => 'pages/lists_prefa.php',
            'pour' => [],   // vide : tout le monde
            'icone' => '<path d="M8.5 7.5h10A1.5 1.5 0 0 1 20 9v10a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 7 19V9a1.5 1.5 0 0 1 1.5-1.5z"/><path d="M4 16V5a1.5 1.5 0 0 1 1.5-1.5H15"/><path d="m10.5 14 2 2 3.5-3.5"/>',
            'styles' => [],
            'scripts' => [],
        ],
        [
            // Pour le personnel d'atelier, « Mes plans » rend en réalité atelier.php :
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
            'icone' => '<path d="M3.5 16c1.4 0 1.4-2 2.8-2s1.4 2 2.8 2 1.4-2 2.8-2 1.4 2 2.8 2 1.4-2 2.8-2 1.4 2 2.5 2"/><path d="M3.5 20h17"/><path d="M8 9.5 6.5 6M12 8.5V4.5M16 9.5 17.5 6"/>',
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
            'icone' => '<path d="M3.5 16c1.4 0 1.4-2 2.8-2s1.4 2 2.8 2 1.4-2 2.8-2 1.4 2 2.8 2 1.4-2 2.8-2 1.4 2 2.5 2"/><path d="M3.5 20h17"/><path d="m9 7.5 2 2 4-4"/>',
            'styles' => ['dashboard/atelier.css'],
            'scripts' => ['atelier.js', 'suivi-soudures.js'],
        ],
        [
            'id' => 'plans-iso',
            'rayon' => 'atelier',
            'libelle' => 'Plans / ISO & Affectations',
            'page' => 'pages/plans_iso.php',
            'pour' => [Role::ChargeDeProduction, Role::ChefDAtelier],
            'icone' => '<path d="M3.5 8.5H10a6.5 6.5 0 0 1 6.5 6.5v5.5"/><path d="M3.5 13H10a2 2 0 0 1 2 2v5.5"/><path d="M3.5 7v7.5M10.5 20.5h7.5"/>',
            'styles' => $ressourcesIso['styles'],
            'scripts' => [...$ressourcesIso['scripts'], 'iso.js'],
        ],
        [
            'id' => 'planning',
            'rayon' => 'atelier',
            'libelle' => 'Planning',
            'page' => 'pages/planning.php',
            'pour' => [Role::ChargeDeProduction, Role::ChefDAtelier],
            'icone' => '<path d="M5 6h14a1.5 1.5 0 0 1 1.5 1.5V19a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 19V7.5A1.5 1.5 0 0 1 5 6z"/><path d="M3.5 10.5h17M8 3.5V7M16 3.5V7"/><path d="M7.5 14.5h4M7.5 17.5h7"/>',
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
            'icone' => '<path d="m12 3.5 8 4v9L12 20.5l-8-4v-9z"/><path d="m4 7.5 8 4 8-4M12 11.5v9"/><path d="m8 5.5 8 4"/>',
            'styles' => ['dashboard/commande-materiel.css'],
            'scripts' => ['commande-materiel.js'],
        ],
        [
            'id' => 'pv',
            'rayon' => 'atelier',
            'libelle' => 'Avancement préfabrication',
            'page' => 'pages/pv.php',
            'pour' => [Role::ChargeDeProduction, Role::ChefDAtelier, Role::ChargeDAffaire],
            'icone' => '<path d="M9 4.5H6.5A1.5 1.5 0 0 0 5 6v13a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 19V6a1.5 1.5 0 0 0-1.5-1.5H15"/><rect x="9" y="3" width="6" height="3" rx="1"/><path d="M8.5 16.5h2l1.5-3 1.5 4 1.5-2.5h.5"/>',
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
            'icone' => '<path d="M12.5 3.5 5.5 13h6l-1 7.5 7-9.5h-6z"/>',
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
 * L'écran que l'adresse demande — ?vue=list-prefa — ou l'accueil.
 *
 * Le serveur rendait toujours l'accueil et laissait le navigateur recharger l'écran voulu
 * juste après : on voyait l'accueil clignoter, et la page se construisait deux fois.
 *
 * @return array<string, mixed> l'entrée de menu, telle que dashboardMenu() la décrit
 */
function dashboardVueDemandee(Utilisateur $utilisateur, ?string $vue): array
{
    $entrees = dashboardMenuVisible($utilisateur);

    foreach ($entrees as $entree) {
        if ($entree['id'] === $vue) return $entree;
    }

    // Une adresse qui nomme un écran inconnu, ou auquel ce rôle n'a pas droit, ouvre
    // l'accueil sans rien dire. On ne répond pas 404 : l'adresse reste celle d'un tableau
    // de bord qui existe, c'est seulement l'onglet demandé qui n'est pas pour cette
    // personne, et le menu à côté montre ce qu'elle peut ouvrir.
    foreach ($entrees as $entree) {
        if ($entree['defaut'] ?? false) return $entree;
    }

    return $entrees[0];
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
        // La page « Nouvelle demande » : comme ses scripts, elle est dans le socle, puisqu'on
        // l'ouvre aussi depuis la liste pour modifier une demande. Elle apporte ses polices.
        'css/dashboard/nouvelle-demande.css',
        // Le champ date : onze champs dans l'application, et un seul calendrier. Après la
        // nouvelle demande, dont la règle de base des champs porte quatre « :not() ».
        'css/dashboard/champ-date.css',
        // La palette Ctrl+K. Elle habille une boîte modale posée sur le corps de la page,
        // donc rien ne dépend de l'ordre ici.
        'css/dashboard/palette.css',
        // La barre latérale en carte, avec ses compteurs : après les feuilles qui la
        // dessinaient jusqu'ici, pour passer devant elles.
        'css/dashboard/barre-laterale.css',
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
        // Le champ date, pour les mêmes raisons que sa feuille : le planning, les PV, les
        // soudures et les bons de livraison en ont tous, et il s'habille tout seul.
        'js/champ-date.js',
        // La palette Ctrl+K : elle lit les entrées dans la barre latérale, donc elle vaut
        // pour tous les rôles sans rien savoir d'eux.
        'js/palette.js',
        // Les toasts : ce que l'application répond. Tous les écrans y ont droit, et le
        // composant ne coûte rien tant que personne ne l'appelle.
        'js/toast.js',
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
