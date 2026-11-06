<?php

// Les affaires, vues par le chargé de production : il ouvre le chantier, désigne qui en a
// la charge, et voit combien de demandes y sont déposées.
//
// Le chargé d'affaire y voit les siennes — « Mes affaires » —, en lecture : la carte ouvre
// ses demandes sur ce chantier, ou en dépose une. Il n'ouvre, ne renomme ni ne confie
// rien, et actions/affaires.php le lui refuse de toute façon.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/vue/liste_filtrable.php';
require_once __DIR__ . '/../includes/demande/prefa_visibilite.php';
$enLecture = $utilisateur->est(Role::ChargeDAffaire);
if (!$isAdmin && !$enLecture) prefaError(403, 'Seul le chargé de production gère les affaires.');

$affaires = Affaire::pour($db, $utilisateur);

// Les demandes de chaque affaire, pour sa fiche : un clic sur la carte l'ouvre. La règle de
// visibilité est celle de la liste des demandes — la fiche ne montre que ce que la liste
// montrerait, et chaque ligne y mène.
$demandesParAffaire = [];
if ($affaires) {
    ['where' => $visibles, 'params' => $parametres] = prefaDemandesVisibles($utilisateur);
    $lecture = $db->prepare('SELECT d.id, d.id_affaire, d.id_statut, s.libelle, d.date_fin_prevue, d.urgent
        FROM demande_prefabrication d JOIN statut_demande s ON s.id = d.id_statut'
        . $visibles . ' AND d.id_affaire IS NOT NULL
        ORDER BY d.date_creation DESC, d.id DESC');
    foreach ($parametres as $cle => $valeur) $lecture->bindValue(':' . $cle, $valeur);
    $lecture->execute();
    foreach ($lecture->fetchAll(PDO::FETCH_ASSOC) as $demande) {
        $demandesParAffaire[(int) $demande['id_affaire']][] = $demande;
    }
}
$optionsCharge = [];
if (!$enLecture) {
    $chargesDAffaire = $db->prepare('SELECT id, TRIM(CONCAT(prenom, \' \', name)) AS nom
        FROM Utilisateur WHERE id_role = ? ORDER BY prenom, name');
    $chargesDAffaire->execute([Role::ChargeDAffaire->value]);

    // Le composant attend des options { id, libelle } ; la requête nomme la colonne « nom ».
    $optionsCharge = array_map(
        static fn (array $compte): array => ['id' => (int) $compte['id'], 'libelle' => $compte['nom']],
        $chargesDAffaire->fetchAll(PDO::FETCH_ASSOC)
    );
}

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
        // La ligne dit déjà « Chargé d'affaire » : l'invite n'a plus qu'à inviter.
        'chercher' => 'Choisir…',
        'tableau' => true,
    ]);
};

/**
 * Quand aucun compte « Chargé d'affaire » n'existe, le champ n'aurait rien à proposer : il
 * est remplacé par ce qui manque, et le bouton qui y mène — la fenêtre « Nouvel
 * utilisateur », rôle déjà choisi.
 */
$sansCharge = static fn (): string => '<span class="affaire-sans-charge" title="Aucun compte n’a le rôle Chargé d’affaire.">'
    . 'Aucun compte · <button type="button" class="affaires-creer-charge">Créer</button></span>';

/**
 * Le chargé d'affaire déjà désigné : son nom, sans champ, puisqu'il ne se change plus
 * (Affaire::modifier() le refuse). L'identifiant reste sur la balise : l'enregistrement d'un
 * renommage le renvoie tel quel. L'infobulle redonne le nom entier, que la carte coupe.
 */
$chargeDesigne = static fn (Affaire $affaire): string => '<span class="affaire-charge" data-charge="' . $affaire->idChargeAffaire . '"'
    . ' title="' . prefaEscape($affaire->chargeAffaire) . ' — désigné, il ne se change plus.">'
    . '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">'
    . '<rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>'
    . '<span class="affaire-charge-nom">' . prefaEscape($affaire->chargeAffaire) . '</span></span>';

/**
 * L'état d'une affaire, comme celui d'une demande : un point de couleur et un mot. Les mêmes
 * couleurs que les statuts des demandes (status-1, 2, 3) — l'affaire n'a pas d'état à elle,
 * elle prend celui de ses demandes (Affaire::etat). L'infobulle dit ce qu'il implique.
 */
$etatRendu = static function (Affaire $affaire): string {
    $pluriel = static fn (int $n, string $mot): string => $n . ' ' . $mot . ($n > 1 ? 's' : '');
    [$libelle, $classe, $aide] = match ($affaire->etat()) {
        Affaire::VALIDEE => ['Validée', 'status-2',
            $pluriel($affaire->validees, 'demande') . ' validée' . ($affaire->validees > 1 ? 's' : '')
            . ' : l’affaire ne se renomme plus et ne se supprime plus.'],
        Affaire::EN_ATTENTE => ['En attente', 'status-1',
            $pluriel($affaire->enAttente, 'demande') . ' en attente de validation.'],
        Affaire::REFUSEE => ['Refusée', 'status-3', 'Ses demandes ont été refusées.'],
        default => ['Sans demande', 'est-sans-demande', 'Aucune demande n’y est encore déposée.'],
    };
    return '<span class="affaire-etat prefa-status ' . $classe . '" title="' . prefaEscape($aide) . '">'
        . prefaEscape($libelle) . '</span>';
};

/**
 * La fiche d'une affaire : ce qu'un clic sur sa carte ouvre. Le chargé d'affaire, les
 * demandes rangées par statut en quatre chiffres, puis chaque demande — un clic l'ouvre
 * dans la liste des demandes, sa fiche déjà dépliée.
 *
 * Elle est rendue dans un <template> de la carte : rien ne s'en affiche ni ne s'en cherche
 * tant que la fenêtre ne l'a pas recopiée (affaires.js). Une seule fenêtre pour toutes.
 *
 * @param list<array<string, mixed>> $demandes
 */
$fiche = static function (Affaire $affaire, array $demandes, string $monogramme, bool $enLecture) use ($etatRendu): string {
    $parStatut = array_count_values(array_map(static fn (array $d): int => (int) $d['id_statut'], $demandes));
    $charge = $enLecture
        ? 'Confiée à vous'
        : ($affaire->chargeAffaire !== '' ? 'Chargé d’affaire : ' . $affaire->chargeAffaire : 'Aucun chargé d’affaire désigné');
    $chiffres = [
        'Demandes' => count($demandes),
        'En attente' => $parStatut[1] ?? 0,
        'Validées' => $parStatut[2] ?? 0,
        'Refusées' => $parStatut[3] ?? 0,
    ];

    ob_start(); ?>
    <div class="affaire-fiche-boite">
        <div class="fenetre-entete affaire-fiche-entete">
            <div class="affaire-fiche-identite">
                <span class="affaire-monogramme" aria-hidden="true"><?= prefaEscape($monogramme) ?></span>
                <div class="affaire-fiche-nom">
                    <h2 id="affaire-fiche-titre"><?= prefaEscape($affaire->nom) ?></h2>
                    <p><?= $etatRendu($affaire) ?><span aria-hidden="true"> · </span><?= prefaEscape($charge) ?></p>
                </div>
            </div>
            <button type="button" class="affaire-fiche-fermer modal-close" aria-label="Fermer">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
            </button>
        </div>

        <div class="fenetre-corps affaire-fiche-corps">
            <dl class="affaire-fiche-chiffres">
                <?php foreach ($chiffres as $libelle => $nombre): ?>
                    <div><dt><?= prefaEscape($libelle) ?></dt><dd><?= (int) $nombre ?></dd></div>
                <?php endforeach; ?>
            </dl>

            <?php if ($demandes): ?>
                <ul class="affaire-fiche-liste" aria-label="Demandes de l’affaire">
                    <?php foreach ($demandes as $demande): ?>
                        <?php $reference = prefaReference((int) $demande['id']); ?>
                        <li>
                            <button type="button" class="affaire-fiche-demande" data-id="<?= (int) $demande['id'] ?>" data-reference="<?= prefaEscape($reference) ?>"
                                title="Ouvrir la demande <?= prefaEscape($reference) ?>">
                                <span class="prefa-point status-<?= (int) $demande['id_statut'] ?>" aria-hidden="true"></span>
                                <span class="affaire-fiche-reference"><?= prefaEscape($reference) ?></span>
                                <?php if (!empty($demande['urgent'])): ?>
                                    <span class="prefa-urgent">Urgente</span>
                                <?php endif; ?>
                                <span class="affaire-fiche-statut"><?= prefaEscape($demande['libelle']) ?></span>
                                <span class="affaire-fiche-date"><?= $demande['date_fin_prevue'] ? 'Fin prévue ' . prefaEscape(prefaFormatDate($demande['date_fin_prevue'])) : 'Sans date de fin' ?></span>
                                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6" /></svg>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="affaire-fiche-vide">Aucune demande sur cette affaire pour l’instant.<?= $enLecture ? ' « Déposer une demande » en crée une.' : '' ?></p>
            <?php endif; ?>
        </div>

        <div class="fenetre-pied">
            <button type="button" class="affaire-fiche-fermer fenetre-annuler">Fermer</button>
            <?php if ($demandes): ?>
                <button type="button" class="affaire-fiche-toutes fenetre-annuler">Voir dans la liste des demandes</button>
            <?php endif; ?>
            <?php if ($enLecture): ?>
                <button type="button" class="affaire-fiche-deposer fenetre-valider" data-nouvelle-demande data-affaire="<?= $affaire->id ?>">Déposer une demande</button>
            <?php endif; ?>
        </div>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<section class="users-page affaires-page" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
    <div class="header-list affaires-entete">
        <div class="affaires-titre">
            <h1 class="list-h1"><?= $enLecture ? 'Mes affaires' : 'Affaires' ?></h1>
        </div>
        <?php if (!$enLecture): ?>
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
        <?php endif; ?>
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
                placeholder="<?= $enLecture ? 'Nom d’affaire…' : 'Nom d’affaire ou chargé d’affaire…' ?>">
        </div>
    </div>


    <!-- Une carte par affaire, et non une ligne de tableau. Un tableau range des
         enregistrements qu'on compare colonne par colonne ; une affaire n'a que trois
         choses à dire — son nom, qui la porte, combien de demandes y sont déposées — et
         on ne les compare pas, on les ouvre. La carte donne au nom la place d'un titre,
         ce qu'une cellule ne fait pas.

         Le nom reste modifiable sur place : c'est ce que l'écran savait déjà faire, et
         c'est ce pour quoi on y revient. Le chargé d'affaire ne l'est que tant qu'il n'est
         pas désigné. -->
    <div class="affaires-grille" id="affaires-rows">
        <?php foreach ($affaires as $affaire): ?>
            <?php
            // Le monogramme tient la place du visuel de couverture des outils de suivi :
            // nous n'avons pas d'image à poser sur une affaire, et une image prise au
            // hasard n'en dirait rien. Deux lettres suffisent à distinguer une carte d'une
            // autre dans une grille qu'on balaie.
            // Lettres et chiffres seulement : « [TEST] Réseau » donnait « [T ».
            $lettres = preg_replace('/[^\p{L}\p{N}]+/u', '', $affaire->nom);
            $monogramme = mb_strtoupper(mb_substr($lettres !== '' && $lettres !== null ? $lettres : trim($affaire->nom), 0, 2));
            ?>
            <article class="affaire-carte" data-affaire="<?= $affaire->id ?>">
                <!-- L'en-tête : le monogramme, le nom (modifiable sur place) et la corbeille,
                     qui ne paraît qu'au survol. -->
                <div class="affaire-carte-haut">
                    <span class="affaire-monogramme" aria-hidden="true"><?= prefaEscape($monogramme) ?></span>
                    <?php if ($enLecture): ?>
                        <!-- En lecture, le nom n'est qu'un titre. Il garde la classe du champ :
                             c'est par elle que la recherche et le lien vers les demandes le
                             lisent. -->
                        <h2 class="affaire-nom" title="<?= prefaEscape($affaire->nom) ?>"><?= prefaEscape($affaire->nom) ?></h2>
                    <?php else: ?>
                        <!-- En lecture seule au repos : un clic sur le nom ouvre les demandes,
                             comme partout ailleurs sur la carte. Il était toujours modifiable, et
                             cliquer sur le nom — l'endroit qu'on vise — y posait le curseur au
                             lieu d'ouvrir l'affaire. Le crayon le rend modifiable. -->
                        <input class="affaire-nom" value="<?= prefaEscape($affaire->nom) ?>" maxlength="255" aria-label="Nom de l’affaire" readonly tabindex="-1">
                        <?php // Une affaire dont une demande est validée par le chargé de
                              // production ne se renomme plus ni ne se supprime
                              // (Affaire::modifier, Affaire::fermer) : la carte n'en offre plus
                              // les commandes. Grisées, elles restaient en vue — en permanence
                              // sur une tablette, qui n'a pas de survol pour les cacher. ?>
                        <?php if ($affaire->validees === 0): ?>
                            <button type="button" class="affaire-renommer" aria-label="Renommer l’affaire <?= prefaEscape($affaire->nom) ?>" title="Renommer">
                                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-4-4L4 16v4Z" />
                                    <path d="m13.5 6.5 4 4" />
                                </svg>
                            </button>
                            <!-- Éteinte quand des demandes sont rattachées : son infobulle dit pourquoi. -->
                            <button type="button" class="affaire-supprimer" aria-label="Supprimer l’affaire <?= prefaEscape($affaire->nom) ?>"<?= $affaire->demandes > 0 ? ' disabled title="Des demandes y sont rattachées."' : ' title="Supprimer l’affaire"' ?>>
                                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6" />
                                </svg>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Les propriétés, comme dans les volets : icône et libellé à gauche, valeur
                     à droite. La carte se lit d'un coup d'œil sans être un formulaire.

                     Le chargé d'affaire n'a pas la ligne qui le nomme : sur toutes ses
                     cartes, ce serait lui. -->
                <dl class="affaire-proprietes">
                    <?php // L'état d'abord : c'est ce qu'on cherche en balayant la grille — et
                          // ce qui dit pourquoi une carte n'a plus ni crayon ni corbeille. ?>
                    <div>
                        <dt><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5" /><path d="m8.5 12 2.5 2.5 4.5-5" /></svg>État</dt>
                        <dd><?= $etatRendu($affaire) ?></dd>
                    </div>
                    <?php if (!$enLecture): ?>
                        <div>
                            <dt><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5" /><path d="M5 20c.7-3.6 3.5-5.5 7-5.5s6.3 1.9 7 5.5" /></svg>Chargé d’affaire</dt>
                            <dd><?= match (true) {
                                $affaire->idChargeAffaire !== null => $chargeDesigne($affaire),
                                (bool) $optionsCharge => $choixCharge($optionsCharge, $affaire->id, null),
                                default => $sansCharge(),
                            } ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt><svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path d="M14 3v5h5" /></svg>Demandes</dt>
                        <dd>
                            <?php
                            // Le nombre dit aussi pourquoi la suppression est refusée. Il ouvre la
                            // fiche de l'affaire, comme la carte entière — c'est le chemin qu'on
                            // atteint au clavier. À zéro aussi : la fiche le dit, et propose de
                            // déposer la première.
                            ?>
                            <button type="button" class="affaire-demandes<?= $affaire->demandes > 0 ? '' : ' est-vide' ?>" title="Voir le détail de l’affaire">
                                <?= $affaire->demandes > 0 ? $affaire->demandes . ' demande' . ($affaire->demandes > 1 ? 's' : '') : 'Aucune' ?>
                                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6" /></svg>
                            </button>
                        </dd>
                    </div>
                </dl>

                <template class="affaire-fiche-modele"><?= $fiche($affaire, $demandesParAffaire[$affaire->id] ?? [], $monogramme, $enLecture) ?></template>

                <?php if ($enLecture): ?>
                    <!-- Déposer sur ce chantier : le formulaire s'ouvre l'affaire déjà choisie.
                         Ce n'est pas un choix fait à sa place — c'est sur cette carte qu'on
                         vient de cliquer. -->
                    <footer class="affaire-carte-actions">
                        <button type="button" class="affaire-deposer" data-nouvelle-demande data-affaire="<?= $affaire->id ?>">
                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                            Déposer une demande
                        </button>
                    </footer>
                <?php else: ?>
                    <!-- Le pied n'existe que s'il y a quelque chose à enregistrer. -->
                    <footer class="affaire-carte-pied">
                        <span class="affaire-pied-texte">Modifications non enregistrées</span>
                        <button type="button" class="affaire-annuler">Annuler</button>
                        <button type="button" class="affaire-enregistrer">Enregistrer</button>
                    </footer>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if (!$affaires && $enLecture): ?>
            <p class="affaires-vide">Aucune affaire ne vous est confiée pour l’instant. Demandez au chargé
                de production d’en ouvrir une à votre nom.</p>
        <?php elseif (!$affaires): ?>
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
        chargés d'affaire que le PHP vient d'écrire. Le chargé d'affaire n'ouvre pas
        d'affaire : il ne la reçoit pas.
    -->
    <!-- La fiche d'une affaire : une seule fenêtre, remplie depuis le modèle de la carte
         cliquée (affaires.js). -->
    <dialog id="affaire-fiche" class="affaire-fiche fenetre" aria-labelledby="affaire-fiche-titre"></dialog>

    <?php if (!$enLecture): ?>
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
                //
                // Le champ est toujours écrit, caché tant qu'il n'a personne à proposer : le
                // compte créé sur place (« Créer ce compte ») s'y ajoute et s'y choisit, sans
                // recharger ni refermer la fenêtre (affaires.js).
                echo '<div class="affaire-dialog-charge"' . ($optionsCharge ? '' : ' hidden') . '>'
                    . listeFiltrableRendu('charge', 'Chargé d’affaire', $optionsCharge, null, [
                        'vide' => 'Aucun chargé d’affaire',
                        'aucun' => 'Aucun chargé d’affaire à ce nom.',
                        'chercher' => 'Chargé d’affaire (facultatif)',
                        'tableau' => true,
                    ])
                    . '</div>';
                if (!$optionsCharge) {
                    echo '<p class="affaire-dialog-sans-charge">Aucun compte n’a encore le rôle <strong>Chargé d’affaire</strong> : l’affaire s’ouvrira sans lui. '
                        . '<button type="button" class="affaires-creer-charge">Créer ce compte</button></p>';
                }
                ?>

                <p class="prefa-message fenetre-message" role="status" aria-live="polite"></p>
            </div>

            <div class="affaire-dialog-pied fenetre-pied">
                <button type="button" class="affaire-dialog-fermer fenetre-annuler">Annuler</button>
                <button type="submit" class="affaire-ouvrir fenetre-valider">Ouvrir l’affaire</button>
            </div>
        </form>
    </dialog>

    <?php // « Créer ce compte », quand aucun chargé d'affaire n'existe : sur place, par-dessus
          // ce qu'on était en train de faire. Le bouton menait à « Utilisateurs » : la fenêtre
          // d'ouverture se refermait sur ce qu'on avait tapé, et rien ne ramenait à l'affaire
          // (6 novembre 2026). Le compte se crée comme dans « Utilisateurs »
          // (actions/create_users.php), rôle Chargé d'affaire ; son code d'accès s'affiche, puis
          // on revient où l'on était, le nouveau compte choisi (affaires.js). ?>
    <dialog id="charge-dialog" class="fenetre charge-dialog" aria-labelledby="charge-dialog-titre">
        <form id="charge-form" method="post">
            <input type="hidden" name="role" value="<?= Role::ChargeDAffaire->value ?>">
            <div class="fenetre-entete">
                <h2 id="charge-dialog-titre">Nouveau chargé d’affaire</h2>
                <button type="button" class="modal-close" data-fermer-charge aria-label="Fermer">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="m6 6 12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>
            <p class="fenetre-sous-titre">Son compte se crée ici, sans quitter les affaires. Un code d’accès s’affichera ensuite : donnez-le-lui, il choisira son mot de passe avec.</p>
            <div class="fenetre-corps">
                <input class="fenetre-champ" name="prenom" required maxlength="100" autocomplete="off" aria-label="Prénom" placeholder="Prénom">
                <input class="fenetre-champ" name="name" required maxlength="100" autocomplete="off" aria-label="Nom" placeholder="Nom">
                <input class="fenetre-champ" name="email" type="email" maxlength="255" autocomplete="off" aria-label="Courriel (facultatif)" placeholder="Courriel (facultatif)">
                <p class="prefa-message fenetre-message" role="status" aria-live="polite"></p>
            </div>
            <div class="fenetre-pied">
                <button type="button" class="fenetre-annuler" data-fermer-charge>Annuler</button>
                <button type="submit" class="fenetre-valider">Créer le compte</button>
            </div>
        </form>
    </dialog>
    <?php endif; ?>
</section>
