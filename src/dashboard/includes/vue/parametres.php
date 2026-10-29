<?php
/**
 * La fenêtre des paramètres, sur le modèle de celle de Claude.
 *
 * Une colonne de rubriques à gauche, coiffée d'une recherche ; à droite, des sections dont
 * chaque ligne dit ce que c'est et porte la commande. Un réglage s'applique dès qu'on le
 * touche : il n'y a pas de bouton « Enregistrer » à aller chercher en bas.
 *
 * Elle remplace l'écran « Paramètres », que seul le chargé de production ouvrait. Chacun a
 * maintenant les siens — son thème, son compte —, et les réglages de l'atelier ne paraissent
 * qu'à celui qui peut les écrire. Les actions le revérifient de toute façon.
 *
 * Rendue avec le tableau de bord, et non chargée à l'ouverture : elle s'ouvre sans attendre
 * le réseau, et ce qu'elle lit en base tient en quelques petites requêtes.
 */

require_once __DIR__ . '/../demande/prefa_hours.php';
require_once __DIR__ . '/../materiel/unites.php';
require_once __DIR__ . '/passivation_row.php';
require_once __DIR__ . '/user_display.php';

/**
 * prefaEscape() et la ligne de passivation vivent avec le contexte des pages. Il ne peut pas
 * être chargé en tête de dashboard.php : il répond 401 à une session vide, là où le tableau
 * de bord renvoie vers l'écran de connexion. Chargé ici, la personne est déjà connue — et
 * dans une fonction à part, pour que ses variables ne remplacent pas celles de l'appelant.
 */
function parametresContexte(): void
{
    require_once __DIR__ . '/../prefa_context.php';
}

/** Une icône au trait de la barre latérale. */
function parametresIcone(string $trace): string
{
    return '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $trace . '</svg>';
}

/**
 * Les rubriques de cette personne, rangées par groupe, dans l'ordre de la colonne.
 *
 * @return array<string, array<string, array{libelle: string, icone: string}>>
 */
function parametresRubriques(Utilisateur $utilisateur): array
{
    $groupes = [
        'Préférences' => [
            'general' => ['libelle' => 'Général', 'icone' => '<path d="M4 7.5h9M17 7.5h3M4 16.5h3M11 16.5h9"/><circle cx="15" cy="7.5" r="2"/><circle cx="9" cy="16.5" r="2"/>'],
            'compte' => ['libelle' => 'Compte', 'icone' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.7-3.6 3.5-6 7-6s6.3 2.4 7 6"/>'],
        ],
    ];

    // Les réglages de l'atelier valent pour tout le monde : seul le chargé de production
    // les écrit, lui seul les voit.
    if ($utilisateur->est(Role::ChargeDeProduction)) {
        $groupes['Administration'] = [
            'fabrication' => ['libelle' => 'Fabrication', 'icone' => '<circle cx="12" cy="13.5" r="7"/><path d="M12 10v3.5l2.5 1.5M10 3h4M12 3v3.5"/>'],
            'passivation' => ['libelle' => 'Passivation', 'icone' => '<path d="M12 3.5c3 3.6 5.5 6.6 5.5 10a5.5 5.5 0 0 1-11 0c0-3.4 2.5-6.4 5.5-10Z"/>'],
            // La même caisse que l'entrée « Commande matériel » de la barre.
            'materiel' => ['libelle' => 'Matériel', 'icone' => '<path d="m12 3.5 8 4v9L12 20.5l-8-4v-9z"/><path d="m4 7.5 8 4 8-4M12 11.5v9"/>'],
        ];
    }

    return $groupes;
}

/** La fenêtre, avec les rubriques de cette personne. */
function parametresRendu(PDO $db, Utilisateur $utilisateur): void
{
    parametresContexte();

    $groupes = parametresRubriques($utilisateur);
    $administre = isset($groupes['Administration']);
    $cadence = $administre ? prefaHoursSettings($db) : null;
    $types = $administre ? $db->query('SELECT id, libelle FROM type_passivation ORDER BY libelle')->fetchAll() : [];
    $unitesPretes = $administre && unitesEnBase($db);
    $unites = $unitesPretes ? unitesMateriel($db) : [];
    $metier = roleComplement($utilisateur->idRole);
    $jeton = prefaEscape(Session::jeton());
    // data-static-fields : floating-fields.js laisse les étiquettes à leur place. Sans lui,
    // il emportait « Temps par pouce ISO » dans un champ à étiquette flottante, à droite, et
    // la colonne de gauche restait vide.
    ?>
    <dialog id="parametres" class="fenetre parametres" aria-labelledby="parametres-titre" data-static-fields>
        <div class="parametres-cadre">
            <div class="fenetre-entete">
                <h2 id="parametres-titre">Paramètres</h2>
                <button type="button" class="modal-close" data-fermer-parametres aria-label="Fermer">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
            </div>

            <div class="parametres-corps">
                <nav class="parametres-nav" aria-label="Rubriques des paramètres">
                    <div class="parametres-recherche">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.6-3.6" /></svg>
                        <input type="search" placeholder="Rechercher" aria-label="Rechercher un réglage" autocomplete="off" spellcheck="false">
                    </div>
                    <?php foreach ($groupes as $groupe => $rubriques): ?>
                        <p class="parametres-groupe"><?= prefaEscape($groupe) ?></p>
                        <?php foreach ($rubriques as $cle => $rubrique): ?>
                            <button type="button" class="parametres-onglet" data-rubrique="<?= $cle ?>" aria-controls="parametres-<?= $cle ?>">
                                <?= parametresIcone($rubrique['icone']) ?>
                                <span><?= prefaEscape($rubrique['libelle']) ?></span>
                            </button>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </nav>

                <div class="parametres-contenu">
                    <?php // Chaque rubrique porte son nom, que l'on ne voit qu'en cherchant : les
                          // réponses de plusieurs rubriques s'empilent alors, il faut savoir d'où
                          // vient chacune. Le reste du temps, la colonne le dit déjà. ?>
                    <section class="parametres-rubrique" id="parametres-general" data-rubrique="general" aria-labelledby="parametres-general-titre">
                        <h3 class="parametres-rubrique-titre" id="parametres-general-titre">Général</h3>

                        <section class="parametres-section">
                            <h4>Apparence</h4>
                            <div class="parametre" data-cherche="couleurs mode système clair sombre">
                                <div class="parametre-texte">
                                    <span class="parametre-titre" id="parametre-theme">Thème</span>
                                </div>
                                <?php // De vrais boutons radio sous les icônes : les flèches passent de
                                      // l'un à l'autre, et le lecteur d'écran annonce le choix. ?>
                                <div class="parametres-segments" role="radiogroup" aria-labelledby="parametre-theme">
                                    <span class="parametres-segment" title="Système">
                                        <input type="radio" name="parametres-theme" value="auto" aria-label="Système">
                                        <?= parametresIcone('<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>') ?>
                                    </span>
                                    <span class="parametres-segment" title="Clair">
                                        <input type="radio" name="parametres-theme" value="light" aria-label="Clair">
                                        <?= parametresIcone('<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>') ?>
                                    </span>
                                    <span class="parametres-segment" title="Sombre">
                                        <input type="radio" name="parametres-theme" value="dark" aria-label="Sombre">
                                        <?= parametresIcone('<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5Z"/>') ?>
                                    </span>
                                </div>
                            </div>
                        </section>

                        <section class="parametres-section">
                            <h4>Raccourcis clavier</h4>
                            <div class="parametre" data-cherche="palette écran clavier">
                                <div class="parametre-texte"><span class="parametre-titre">Rechercher un écran</span></div>
                                <span class="parametre-touches"><kbd>Ctrl</kbd><kbd>K</kbd></span>
                            </div>
                            <div class="parametre" data-cherche="clavier">
                                <div class="parametre-texte"><span class="parametre-titre">Ouvrir les paramètres</span></div>
                                <span class="parametre-touches"><kbd>Ctrl</kbd><kbd>,</kbd></span>
                            </div>
                            <div class="parametre" data-cherche="clavier calendrier date">
                                <div class="parametre-texte"><span class="parametre-titre">Ouvrir le calendrier d’un champ date</span></div>
                                <span class="parametre-touches"><kbd>Alt</kbd><kbd>↓</kbd></span>
                            </div>
                        </section>
                    </section>

                    <section class="parametres-rubrique" id="parametres-compte" data-rubrique="compte" aria-labelledby="parametres-compte-titre" hidden>
                        <h3 class="parametres-rubrique-titre" id="parametres-compte-titre">Compte</h3>

                        <section class="parametres-section">
                            <h4>Profil</h4>
                            <?php if ($administre): ?>
                                <p class="parametres-section-aide">Modifiable dans <button type="button" class="parametres-lien" data-parametres-vers="list-users">Utilisateurs</button>.</p>
                            <?php else: ?>
                                <p class="parametres-section-aide">Tenu par le chargé de production.</p>
                            <?php endif; ?>
                            <div class="parametre">
                                <div class="parametre-texte"><span class="parametre-titre">Nom</span></div>
                                <span class="parametre-valeur"><?= prefaEscape($utilisateur->nomComplet()) ?></span>
                            </div>
                            <div class="parametre" data-cherche="login connexion">
                                <div class="parametre-texte"><span class="parametre-titre">Identifiant</span></div>
                                <span class="parametre-valeur"><?= prefaEscape($utilisateur->identifiant) ?></span>
                            </div>
                            <div class="parametre" data-cherche="droits profil métier">
                                <div class="parametre-texte"><span class="parametre-titre">Rôle</span></div>
                                <span class="parametre-valeur"><?= prefaEscape($utilisateur->roleEnBase . ($metier !== '' ? ' · ' . $metier : '')) ?></span>
                            </div>
                            <?php if ($utilisateur->email): ?>
                                <div class="parametre" data-cherche="mail courriel">
                                    <div class="parametre-texte"><span class="parametre-titre">Adresse e-mail</span></div>
                                    <span class="parametre-valeur"><?= prefaEscape($utilisateur->email) ?></span>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="parametres-section">
                            <h4>Session</h4>
                            <?php // Session::fermer() vide toute la session : les comptes ajoutés sur
                                  // cet appareil partent avec. On le dit avant le clic. ?>
                            <div class="parametre" data-cherche="déconnexion quitter">
                                <div class="parametre-texte">
                                    <span class="parametre-titre">Cet appareil</span>
                                    <p class="parametre-aide">Se déconnecter ferme tous les comptes ouverts ici.</p>
                                </div>
                                <a class="parametres-bouton" href="../auth/logout.php">Se déconnecter</a>
                            </div>
                        </section>
                    </section>

                    <?php if ($administre): ?>
                        <section class="parametres-rubrique" id="parametres-fabrication" data-rubrique="fabrication" aria-labelledby="parametres-fabrication-titre" hidden>
                            <h3 class="parametres-rubrique-titre" id="parametres-fabrication-titre">Fabrication</h3>

                            <section class="parametres-section">
                                <h4>Cadence</h4>
                                <?php // Les identifiants de l'ancien écran sont repris : dashboard-forms.js
                                      // envoie ce formulaire et en donne la réponse. Il part tout seul
                                      // quand la valeur change (parametres.js) ; le refus du serveur
                                      // s'écrit sous la ligne, à côté du nombre à corriger. ?>
                                <form id="prefa-settings-form" class="parametre" novalidate data-cherche="délai durée jours pouce iso estimation">
                                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                    <div class="parametre-texte">
                                        <label class="parametre-titre" for="parametres-cadence">Temps par pouce ISO</label>
                                        <p class="parametre-aide">Sert à estimer le délai d’une préfabrication.</p>
                                        <span class="prefa-message parametre-message" role="status" aria-live="polite"></span>
                                    </div>
                                    <span class="parametres-unite">
                                        <input id="parametres-cadence" class="parametres-champ parametres-champ-nombre" name="duree_jours" type="number" inputmode="decimal"
                                               min="0.0001" max="100" step="any" value="<?= prefaEscape($cadence['days']) ?>" required>
                                        <span aria-hidden="true">j</span>
                                    </span>
                                </form>
                            </section>
                        </section>

                        <section class="parametres-rubrique" id="parametres-passivation" data-rubrique="passivation" aria-labelledby="parametres-passivation-titre" hidden>
                            <h3 class="parametres-rubrique-titre" id="parametres-passivation-titre">Passivation</h3>

                            <section class="parametres-section" data-cherche="types décapage traitement">
                                <h4>Types de passivation</h4>
                                <p class="parametres-section-aide">Proposés au demandeur dans le formulaire de demande.</p>
                                <form id="passivation-form" class="parametre" novalidate>
                                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                    <div class="parametre-texte">
                                        <label class="parametre-titre" for="passivation-libelle">Ajouter un type</label>
                                        <span class="prefa-message parametre-message" role="status" aria-live="polite"></span>
                                    </div>
                                    <div class="parametre-controle">
                                        <input id="passivation-libelle" class="parametres-champ" name="libelle" maxlength="100"
                                               placeholder="Décapage, acide citrique…" autocomplete="off" required>
                                        <button type="submit" class="parametres-bouton">Ajouter</button>
                                    </div>
                                </form>

                                <?php // Les lignes sont celles de l'action de modification, qui les
                                      // remplace une à une : le même balisage aux trois endroits. ?>
                                <table class="parametres-liste">
                                    <tbody id="passivation-rows">
                                        <?php foreach ($types as $type): ?><?php passivationRow($type); ?><?php endforeach; ?>
                                        <?php if (!$types): ?>
                                            <tr class="passivation-empty"><td colspan="3">Aucun type pour l’instant.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </section>
                        </section>

                        <section class="parametres-rubrique" id="parametres-materiel" data-rubrique="materiel" aria-labelledby="parametres-materiel-titre" hidden>
                            <h3 class="parametres-rubrique-titre" id="parametres-materiel-titre">Matériel</h3>

                            <section class="parametres-section" data-cherche="unités unité mesure manque quantité pièce mètre">
                                <h4>Unités</h4>
                                <p class="parametres-section-aide">Proposées au personnel d’atelier quand il signale un manque de matériel. Retirer ou renommer une unité ne change pas les manques déjà signalés.</p>
                                <?php if (!$unitesPretes): ?>
                                    <p class="parametres-section-aide">La liste arrive avec la migration <code>2026-10-29_unites_materiel</code>, pas encore passée sur ce serveur : le personnel écrit l’unité à la main.</p>
                                <?php else: ?>
                                    <form id="unites-form" class="parametre" novalidate>
                                        <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                        <input type="hidden" name="operation" value="ajouter">
                                        <div class="parametre-texte">
                                            <label class="parametre-titre" for="unites-libelle">Ajouter une unité</label>
                                            <span class="prefa-message parametre-message" role="status" aria-live="polite"></span>
                                        </div>
                                        <div class="parametre-controle">
                                            <input id="unites-libelle" class="parametres-champ" name="libelle" maxlength="<?= UNITE_LONGUEUR ?>"
                                                   placeholder="m², litre, rouleau…" autocomplete="off" required>
                                            <button type="submit" class="parametres-bouton">Ajouter</button>
                                        </div>
                                    </form>

                                    <table class="parametres-liste unites-liste" data-csrf="<?= $jeton ?>">
                                        <tbody id="unites-lignes"><?= unitesLignesRendu($unites) ?></tbody>
                                    </table>
                                <?php endif; ?>
                            </section>
                        </section>
                    <?php endif; ?>

                    <p class="parametres-aucun" hidden>Aucun réglage ne correspond à « <span></span> ».</p>
                </div>
            </div>
        </div>
    </dialog>
    <?php
}
