<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/materiel_bon.php';
require_once __DIR__ . '/../includes/materiel/manques.php';

// Le bon décrit la demande entière, pas un plan : l'atelier le lit par affectation, pas
// ici. Cet écran est celui de l'encadrement, qui fait lire et qui corrige.
if (!$canViewAllPrefa) prefaError(403, 'Cet écran est réservé au chargé de production et au chef d’atelier.');

$bons = materielTousLesBons($db);
$lignesParBon = materielLignes($db, array_map(static fn (array $b): int => (int) $b['id'], $bons));
foreach ($bons as &$bon) $bon['lignes'] = $lignesParBon[(int) $bon['id']] ?? [];
unset($bon);

$etats = materielEtats();

// Ce que l'atelier a signalé et que personne n'a encore traité.
$manques = $canManageWorkshop ? manquesATraiter($db) : [];
$motifs = manquesMotifs();
$etatsManque = manquesEtats();

?>
<section class="page prefa-page prefa-list atelier-page" id="materiel-page" aria-labelledby="materiel-page-title">
    <div class="prefa-shell">

        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="materiel-page-title">Commande matériel</h1>
                <p>Les bons déposés sur les demandes, et les articles qu’ils commandent. La lecture propose, vous corrigez.</p>
            </div>
        </header>

        <?php // Les manques signalés par l'atelier, au-dessus des bons : c'est ce qui attend
              // une action, et un soudeur arrêté faute d'une bride passe avant une relecture
              // de bon. La section n'existe que s'il y a quelque chose à traiter. ?>
        <?php if ($manques): ?>
            <section class="materiel-manques" aria-labelledby="materiel-manques-titre" data-csrf="<?= prefaEscape(Session::jeton()) ?>">
                <h2 id="materiel-manques-titre">Manques signalés par l’atelier
                    <span class="prefa-onglet-compte" data-manques-compte><?= count($manques) ?></span></h2>
                <div class="prefa-table-scroll" role="region" aria-label="Manques signalés" tabindex="0">
                    <table class="prefa-table materiel-manques-table">
                        <thead><tr>
                            <th scope="col">Article</th>
                            <th scope="col" class="prefa-nombre">Qté</th>
                            <th scope="col">Motif</th>
                            <th scope="col">Demande</th>
                            <th scope="col">Signalé par</th>
                            <th scope="col">État</th>
                            <th scope="col"><span class="atelier-sr-only">Actions</span></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($manques as $manque):
                                $etat = $etatsManque[$manque['statut']]; ?>
                                <tr class="materiel-manque" data-manque="<?= (int) $manque['id'] ?>" data-statut="<?= prefaEscape($manque['statut']) ?>">
                                    <td class="materiel-manque-article">
                                        <strong><?= prefaEscape($manque['designation']) ?></strong>
                                        <?php if ($manque['commentaire']): ?><span><?= prefaEscape($manque['commentaire']) ?></span><?php endif; ?>
                                    </td>
                                    <td class="prefa-nombre"><?= prefaEscape(manqueQuantite($manque['quantite']) . ' ' . $manque['unite']) ?></td>
                                    <td><?= prefaEscape($motifs[$manque['motif']] ?? $manque['motif']) ?></td>
                                    <td class="prefa-reference"><?= prefaEscape(prefaReference((int) $manque['id_demande'])) ?></td>
                                    <td><?= prefaEscape($manque['auteur']) ?><span class="materiel-manque-date"><?= prefaEscape(prefaFormatDate($manque['date_creation'])) ?></span></td>
                                    <td><span class="pv-puce <?= $etat['puce'] ?>" data-manque-etat><?= prefaEscape($etat['libelle']) ?></span></td>
                                    <td class="materiel-manque-actions">
                                        <?php if ($manque['statut'] === 'nouvelle'): ?>
                                            <button type="button" class="materiel-manque-bouton" data-traiter="vue">Marquer vu</button>
                                        <?php endif; ?>
                                        <button type="button" class="materiel-manque-bouton est-principal" data-traiter="traitee">Traité</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="prefa-table-empty materiel-manques-vide" hidden>Tous les manques sont traités.</p>
            </section>
        <?php endif; ?>

        <?php // La barre de la maison : la recherche à loupe, puis un onglet par état avec son
              // compte. Trois étiquettes flottantes de 44 px faisaient un formulaire de
              // recherche au-dessus d'une liste de deux bons ; l'affaire se cherche au champ. ?>
        <div class="prefa-filters" role="search">
            <div class="filtres-recherche">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input id="materiel-filtre-recherche" type="search" autocomplete="off" aria-label="Rechercher un bon"
                       placeholder="Demande, affaire, commande…">
            </div>
        </div>

        <?php $parEtat = array_fill_keys(array_keys($etats), 0);
              foreach ($bons as $bon) $parEtat[materielEtat($bon)]++; ?>
        <div class="prefa-onglets-barre">
            <div class="prefa-onglets" role="group" aria-label="État des bons">
                <button type="button" class="prefa-onglet materiel-onglet" data-etat="" aria-pressed="true">
                    Tous <span class="prefa-onglet-compte"><?= count($bons) ?></span>
                </button>
                <?php foreach ($etats as $valeur => $libelle): ?>
                    <?php // Un état qu'aucun bon ne porte n'a rien à filtrer : « Lecture
                          // impossible » ne s'affiche que s'il y en a une. ?>
                    <?php if ($valeur === 'echec' && !$parEtat[$valeur]) continue; ?>
                    <button type="button" class="prefa-onglet materiel-onglet" data-etat="<?= prefaEscape($valeur) ?>" aria-pressed="false">
                        <?= prefaEscape($libelle) ?> <span class="prefa-onglet-compte"><?= $parEtat[$valeur] ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="prefa-list-summary">
                <span><strong id="materiel-compte"><?= count($bons) ?></strong>
                <span id="materiel-compte-libelle"><?= count($bons) === 1 ? 'bon de commande' : 'bons de commande' ?></span></span>
            </p>
        </div>

        <div class="prefa-table-scroll" role="region" aria-label="Bons de commande" tabindex="0">
            <table class="prefa-table materiel-liste">
                <thead>
                    <tr>
                        <th scope="col">Demande n°</th>
                        <th scope="col">Affaire</th>
                        <th scope="col">Bon déposé</th>
                        <th scope="col">N° de commande</th>
                        <th scope="col">Livraison</th>
                        <th scope="col" class="prefa-nombre">Reçus</th>
                        <th scope="col">État</th>
                        <th scope="col"><span class="atelier-sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$bons): ?>
                        <tr><td colspan="8" class="prefa-table-empty">
                            Aucun bon de commande déposé. Le bon se dépose sur la demande, dans ses documents.
                        </td></tr>
                    <?php endif; ?>

                    <?php foreach ($bons as $bon):
                        $id = (int) $bon['id'];
                        $idDemande = (int) $bon['id_demande'];
                        $etat = materielEtat($bon);
                        $recherche = mb_strtolower(prefaReferenceComplete($idDemande) . ' ' . $idDemande . ' '
                            . ($bon['nom_affaire'] ?? '') . ' ' . ($bon['numero_commande'] ?? '') . ' ' . $bon['nom']);
                        // La référence de la demande voyage dans l'adresse, pas son
                        // identifiant technique : elle s'affiche au survol du lien et dans
                        // l'onglet d'aperçu.
                        $url = 'actions/download_materiel.php?ref=' . rawurlencode(prefaReference($idDemande))
                            . '&file=' . rawurlencode((string) $id);
                        ['recus' => $recus, 'total' => $total] = materielRecus($bon);
                    ?>
                        <tr class="materiel-rang"
                            id="materiel-bon-<?= $id ?>"
                            data-document="<?= $id ?>"
                            data-recherche="<?= prefaEscape($recherche) ?>"
                            data-etat="<?= prefaEscape($etat) ?>"
                            data-demande="<?= prefaEscape(prefaReferenceComplete($idDemande)) ?>"
                            data-fichier="<?= prefaEscape($url) ?>"
                            data-nom="<?= prefaEscape($bon['nom']) ?>"
                            data-bon="<?= prefaEscape(json_encode(materielPayload($bon), JSON_UNESCAPED_UNICODE)) ?>">

                            <td class="prefa-reference"><?= prefaEscape(prefaReference($idDemande)) ?></td>
                            <td class="prefa-affaire"><?= prefaEscape($bon['nom_affaire'] ?: '—') ?></td>
                            <td>
                                <a class="prefa-pdf-link materiel-fichier"
                                   href="<?= prefaEscape($url) ?>"
                                   data-name="<?= prefaEscape($bon['nom']) ?>"
                                   aria-haspopup="dialog">
                                    <span class="prefa-document-text"><strong><?= prefaEscape($bon['nom']) ?></strong></span>
                                </a>
                            </td>
                            <td class="materiel-cellule-numero">
                                <?php if ($bon['numero_commande']): ?>
                                    <span class="materiel-numero"><?= prefaEscape($bon['numero_commande']) ?></span>
                                <?php else: ?>
                                    <span class="materiel-vide">—</span>
                                <?php endif; ?>
                            </td>
                            <?php // L'échéance du bon, pas celle de chacune de ses lignes : seize fois
                                  // la même date ne dit rien, deux dates distinctes disent que la
                                  // commande arrive en deux fois. ?>
                            <td class="materiel-cellule-livraison">
                                <?php $echeances = materielEcheancesTexte($bon); ?>
                                <?= $echeances !== '' ? prefaEscape($echeances) : '<span class="materiel-vide">—</span>' ?>
                            </td>
                            <?php // « 1/5 » dit à la fois combien d'articles et combien sont arrivés :
                                  // la colonne « Articles » le répétait. ?>
                            <td class="prefa-nombre materiel-cellule-recus">
                                <?= $total ? prefaEscape($recus . '/' . $total) : '<span class="materiel-vide">—</span>' ?>
                            </td>
                            <td>
                                <span class="materiel-etat materiel-etat-<?= prefaEscape($etat) ?>"
                                      title="<?= prefaEscape(materielResume($bon)) ?>">
                                    <?= prefaEscape($etats[$etat]) ?>
                                </span>
                            </td>
                            <td class="materiel-actions">
                                <button type="button" class="prefa-toggle materiel-ouvrir" data-document="<?= $id ?>">Ouvrir</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="prefa-table-empty materiel-aucun-resultat" hidden>Aucun bon ne correspond à cette recherche.</p>
    </div>

    <?php // Le volet d'un bon : le même panneau flottant que les PV, le planning et la fiche
          // d'une demande. C'était une fenêtre pleine page à part, avec sa propre typographie.
          // Un seul volet pour l'écran, rempli par commande-materiel.js : quarante lignes par
          // bon, et un volet par bon dans le balisage, c'était la page chargée deux fois. ?>
    <div id="materiel-volet" class="prefa-detail-row materiel-volet-rang" hidden>
        <form class="pv-volet" id="materiel-form" role="dialog" aria-labelledby="materiel-volet-titre">
            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
            <input type="hidden" name="document" value="">

            <header class="pv-volet-tete">
                <div>
                    <?php // Le numéro du bon tient lieu de titre : c'est son nom. ?>
                    <h2 id="materiel-volet-titre">Bon de commande</h2>
                    <p class="materiel-volet-sous-titre"></p>
                </div>
                <div class="pv-volet-outils">
                    <button type="button" class="prefa-detail-close" data-close-target="materiel-volet" aria-label="Fermer le volet" title="Fermer">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </div>
            </header>

            <section class="pv-volet-section">
                <div class="pv-volet-titre-rang">
                    <h3 class="pv-volet-titre">Commande</h3>
                    <span class="pv-puce materiel-volet-etat"></span>
                </div>
                <dl class="pv-proprietes">
                    <div><dt>Demande</dt><dd class="materiel-volet-demande"></dd></div>
                    <div><dt>Bon déposé</dt><dd><a class="prefa-pdf-link materiel-volet-fichier" href="#" aria-haspopup="dialog"></a></dd></div>
                    <div><dt>Date de commande</dt><dd class="materiel-volet-date"></dd></div>
                    <div><dt>Livraison</dt><dd class="materiel-volet-livraison"></dd></div>
                    <div><dt>Réception</dt><dd class="materiel-volet-reception"></dd></div>
                </dl>
                <?php // Ce qu'on attend de vous, ou pourquoi la lecture a échoué. Vide devant une
                      // liste lue : elle n'apprendrait rien. ?>
                <p class="pv-volet-note materiel-volet-note" role="status" aria-live="polite" hidden></p>
            </section>

            <?php // Les articles servent deux tâches qui ne se ressemblent pas, et le volet
                  // les sépare. Avant la validation, on RELIT : le tableau du bon, dans son
                  // ordre, case par case contre le PDF. Après, on REÇOIT : une liste à cocher,
                  // rangée par livraison, où ce qui manque ressort et ce qui est arrivé
                  // s'efface. Un seul tableau faisait les deux — on y pointait des réceptions
                  // sur une commande pas encore validée, et la date se répétait seize fois. ?>
            <section class="pv-volet-section materiel-articles">
                <div class="pv-volet-titre-rang">
                    <h3 class="pv-volet-titre">Articles <span class="materiel-volet-compte"></span></h3>
                    <div class="materiel-volet-outils">
                        <button type="button" class="materiel-relire bouton-discret">Lire le bon</button>
                        <button type="button" class="materiel-ajouter bouton-discret">Ajouter un article</button>
                        <button type="button" class="materiel-tout-recu bouton-discret">Tout marquer reçu</button>
                    </div>
                </div>

                <?php // ----- Relire : le bon tel qu'il est imprimé ----- ?>
                <div class="materiel-relecture">
                    <div class="materiel-dialog-scroll">
                        <table class="materiel-edition">
                            <thead>
                                <tr>
                                    <th scope="col">Référence</th>
                                    <th scope="col">Désignation</th>
                                    <th scope="col">Livraison</th>
                                    <th scope="col" class="materiel-champ-nombre">Qté</th>
                                    <th scope="col">Unité</th>
                                    <th scope="col"><span class="atelier-sr-only">Retirer</span></th>
                                </tr>
                            </thead>
                            <tbody class="materiel-edition-lignes"></tbody>
                        </table>
                    </div>
                    <p class="materiel-edition-vide" hidden>Aucun article. Lisez le bon déposé, ou ajoutez-les à la main.</p>
                </div>

                <?php // ----- Recevoir : une liste à cocher, par livraison ----- ?>
                <div class="materiel-reception">
                    <div class="materiel-reception-barre">
                        <?php // Le choix en trois segments, comme l'état d'un PV : de vrais boutons
                              // radio, que les flèches du clavier parcourent. ?>
                        <div class="pv-segments materiel-segments" role="radiogroup" aria-label="Articles à montrer">
                            <label><input type="radio" name="materiel-vue" value="" checked><span>Tous <b data-compte="tous">0</b></span></label>
                            <label><input type="radio" name="materiel-vue" value="attendu"><span>À recevoir <b data-compte="attendu">0</b></span></label>
                            <label><input type="radio" name="materiel-vue" value="recu"><span>Reçus <b data-compte="recu">0</b></span></label>
                        </div>
                        <?php // Le bon de livraison en main, on cherche sa ligne : par la référence,
                              // qui est imprimée sur les deux papiers. ?>
                        <div class="filtres-recherche materiel-reception-recherche">
                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                            <input type="search" autocomplete="off" aria-label="Rechercher un article" placeholder="Référence, désignation…">
                        </div>
                    </div>

                    <table class="materiel-recevoir">
                        <thead>
                            <tr>
                                <th scope="col" class="materiel-col-case"><span class="atelier-sr-only">Reçu</span></th>
                                <th scope="col" class="materiel-col-ref">Référence</th>
                                <th scope="col">Désignation</th>
                                <th scope="col" class="materiel-col-nombre">Qté</th>
                                <th scope="col" class="materiel-col-nombre">Reçu le</th>
                            </tr>
                        </thead>
                        <?php // Un <tbody> par livraison, posé par le script. ?>
                    </table>
                    <p class="materiel-reception-vide" hidden></p>
                </div>
            </section>

            <?php // Deux gestes, et non plus un : « Enregistrer » validait la commande, et une
                  // commande validée ne se modifie plus. Un clic pour garder une correction la
                  // figeait pour toujours, sans un mot. Enregistrer garde le travail ; Valider
                  // engage la commande, et le demande avant. ?>
            <footer class="materiel-volet-pied">
                <span class="pv-message materiel-message" role="status" aria-live="polite"></span>
                <button type="submit" name="valider" value="0" class="materiel-enregistrer bouton-discret">Enregistrer</button>
                <button type="submit" name="valider" value="1" class="pv-enregistrer materiel-valider">Valider la commande</button>
            </footer>
        </form>
    </div>

    <!-- Le gabarit d'une ligne. Il vit dans un template plutôt que dans le script : son
         balisage est celui des lignes rendues par le script, et l'écrire deux fois le
         ferait diverger au premier champ ajouté. -->
    <template id="materiel-gabarit">
        <tr class="materiel-edition-ligne">
            <?php // readonly par défaut : la case s'ouvre au clic, et se referme dès qu'on
                  // la quitte. La réception n'est pas ici : elle se pointe une fois la
                  // commande validée, dans la liste à cocher. ?>
            <td><input class="materiel-champ" data-champ="reference" maxlength="80" aria-label="Référence" readonly></td>
            <td><input class="materiel-champ" data-champ="designation" maxlength="180" aria-label="Désignation" readonly></td>
            <td><input class="materiel-champ materiel-champ-date" data-champ="date_livraison"
                       inputmode="numeric" maxlength="10" placeholder="jj/mm/aaaa"
                       aria-label="Date de livraison" readonly></td>
            <td><input class="materiel-champ materiel-champ-nombre" data-champ="quantite" inputmode="decimal" aria-label="Quantité" readonly></td>
            <td><input class="materiel-champ" data-champ="unite" maxlength="16" aria-label="Unité" readonly></td>
            <td>
                <button type="button" class="materiel-retirer bouton-discret" aria-label="Retirer cet article" title="Retirer">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
            </td>
        </tr>
    </template>
</section>
