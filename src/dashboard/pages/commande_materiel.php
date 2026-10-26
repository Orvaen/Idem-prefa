<?php
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/materiel/materiel_bon.php';

// Le bon décrit la demande entière, pas un plan : l'atelier le lit par affectation, pas
// ici. Cet écran est celui de l'encadrement, qui fait lire et qui corrige.
if (!$canViewAllPrefa) prefaError(403, 'Cet écran est réservé au chargé de production et au chef d’atelier.');

$bons = materielTousLesBons($db);
$lignesParBon = materielLignes($db, array_map(static fn (array $b): int => (int) $b['id'], $bons));
foreach ($bons as &$bon) $bon['lignes'] = $lignesParBon[(int) $bon['id']] ?? [];
unset($bon);

$etats = materielEtats();

// Combien restent à lire ou à vérifier : c'est le travail qu'il reste, et c'est la
// première chose qu'on vient voir sur cet écran.
$aFaire = 0;
$affaires = [];
foreach ($bons as $bon) {
    if (in_array(materielEtat($bon), ['a_lire', 'a_verifier'], true)) $aFaire++;
    if ($bon['nom_affaire']) $affaires[$bon['nom_affaire']] = $bon['nom_affaire'];
}
ksort($affaires);
?>
<section class="page prefa-page prefa-list atelier-page" id="materiel-page" aria-labelledby="materiel-page-title">
    <div class="prefa-shell">

        <header class="prefa-heading prefa-list-heading">
            <div>
                <h1 id="materiel-page-title">Commande matériel</h1>
                <p>Les bons déposés sur les demandes, et les articles qu’ils commandent. La lecture propose, vous corrigez.</p>
            </div>
        </header>

        <div class="prefa-filters" role="search">
            <div class="floating-field">
                <label for="materiel-filtre-recherche">Rechercher</label>
                <input id="materiel-filtre-recherche" class="floating-control" type="search"
                       placeholder="N° de demande, affaire, n° de commande ou fichier">
            </div>
            <div class="floating-field floating-always">
                <label for="materiel-filtre-etat">Lecture</label>
                <select id="materiel-filtre-etat" class="floating-control">
                    <option value="">Tous les bons</option>
                    <option value="a_faire">À lire ou à vérifier</option>
                    <?php foreach ($etats as $valeur => $libelle): ?>
                        <option value="<?= prefaEscape($valeur) ?>"><?= prefaEscape($libelle) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (count($affaires) > 1): ?>
                <div class="floating-field floating-always">
                    <label for="materiel-filtre-affaire">Affaire</label>
                    <select id="materiel-filtre-affaire" class="floating-control">
                        <option value="">Toutes les affaires</option>
                        <?php foreach ($affaires as $nom): ?><option value="<?= prefaEscape($nom) ?>"><?= prefaEscape($nom) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <p class="prefa-list-summary">
            <strong id="materiel-compte"><?= count($bons) ?></strong>
            <span id="materiel-compte-libelle"><?= count($bons) === 1 ? 'bon de commande' : 'bons de commande' ?></span>
            <?= $aFaire ? ' · ' . $aFaire . ($aFaire === 1 ? ' reste à traiter' : ' restent à traiter') : '' ?>
        </p>

        <div class="prefa-table-scroll" role="region" aria-label="Bons de commande" tabindex="0">
            <table class="prefa-table materiel-liste">
                <thead>
                    <tr>
                        <th scope="col">Demande n°</th>
                        <th scope="col">Affaire</th>
                        <th scope="col">Bon déposé</th>
                        <th scope="col">N° de commande</th>
                        <th scope="col">Date Livraison</th>
                        <th scope="col">Articles</th>
                        <th scope="col">Reçus</th>
                        <th scope="col">Lecture</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$bons): ?>
                        <tr><td colspan="9" class="prefa-table-empty">
                            Aucun bon de commande déposé. Le bon se dépose sur la demande, dans « Commande matériel ».
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
                    ?>
                        <tr class="materiel-rang"
                            id="materiel-bon-<?= $id ?>"
                            data-document="<?= $id ?>"
                            data-recherche="<?= prefaEscape($recherche) ?>"
                            data-affaire="<?= prefaEscape((string) ($bon['nom_affaire'] ?? '')) ?>"
                            data-etat="<?= prefaEscape($etat) ?>"
                            data-demande="<?= prefaEscape(prefaReferenceComplete($idDemande)) ?>"
                            data-bon="<?= prefaEscape(json_encode(materielPayload($bon), JSON_UNESCAPED_UNICODE)) ?>">

                            <td><?= prefaEscape(prefaReferenceComplete($idDemande)) ?></td>
                            <td><?= prefaEscape($bon['nom_affaire'] ?: '—') ?></td>
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
                            <td class="materiel-cellule-articles"><?= count($bon['lignes']) ?: '—' ?></td>
                            <?php ['recus' => $recus, 'total' => $total] = materielRecus($bon); ?>
                            <td class="materiel-cellule-recus">
                                <?= $total ? prefaEscape($recus . '/' . $total) : '<span class="materiel-vide">—</span>' ?>
                            </td>
                            <td>
                                <span class="materiel-etat materiel-etat-<?= prefaEscape($etat) ?>"
                                      title="<?= prefaEscape(materielResume($bon)) ?>">
                                    <?= prefaEscape($etats[$etat]) ?>
                                </span>
                            </td>
                            <td class="materiel-actions">
                                <button type="button" class="prefa-toggle materiel-ouvrir" data-document="<?= $id ?>">
                                    <?php // Une commande validée ne s'ouvre plus que pour être lue. ?>
                                    <?= materielEstFige($bon) ? 'Voir' : ($etat === 'a_lire' ? 'Lire le bon' : 'Voir et corriger') ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="prefa-table-empty materiel-aucun-resultat" hidden>Aucun bon ne correspond à cette recherche.</p>
    </div>

    <!-- La fenêtre d'édition, une seule pour tout l'écran : elle se remplit du bon qu'on
         ouvre. Quarante lignes par bon, et autant de fenêtres que de bons dans le
         balisage, c'était la page entière chargée deux fois. -->
    <dialog id="materiel-dialog" class="materiel-dialog" aria-labelledby="materiel-dialog-titre">
        <form class="prefa-form" id="materiel-form">
            <input type="hidden" name="csrf" value="<?= prefaEscape(Session::jeton()) ?>">
            <input type="hidden" name="document" value="">

            <div class="materiel-dialog-entete">
                <div>
                    <?php // Le numero du bon tient lieu de titre : c'est son nom. Ce qui le situe
                          // - la demande, le nombre d'articles - tient sur la ligne du dessous, et
                          // l'etat dans sa pastille. Les echeances etaient reprises ici alors
                          // qu'elles ont leur colonne, et la phrase sur la lecture redisait ce que
                          // la pastille annonce. ?>
                    <p class="materiel-dialog-surtitre">Bon de commande</p>
                    <h2 id="materiel-dialog-titre">Articles du bon</h2>
                    <p class="materiel-dialog-sous-titre"></p>
                    <?php // Elle ne parait que lorsqu'il y a quelque chose a faire, ou que la
                          // lecture a echoue. Devant une liste lue, elle n'apprendrait rien. ?>
                    <p class="materiel-dialog-note" role="status" aria-live="polite" hidden></p>
                </div>
                <div class="materiel-dialog-coin">
                    <span class="materiel-etat materiel-dialog-etat"></span>
                    <button type="button" class="materiel-fermer modal-close" aria-label="Fermer">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </div>
            </div>

            <div class="materiel-dialog-corps">
                <div class="materiel-dialog-scroll">
                    <table class="materiel-edition">
                        <thead>
                            <tr>
                                <?php // L'état de réception se lit en toutes lettres : une case cochée
                                      // ne disait pas ce qu'elle voulait dire. ?>
                                <th scope="col" class="materiel-colonne-recu">Réception</th>
                                <th scope="col">Référence</th>
                                <th scope="col">Désignation</th>
                                <th scope="col">Date Livraison</th>
                                <th scope="col">Unité</th>
                                <th scope="col">Quantité</th>
                                <th scope="col"><span class="sr-only">Retirer</span></th>
                            </tr>
                        </thead>
                        <tbody class="materiel-edition-lignes"></tbody>
                    </table>
                </div>
                <p class="materiel-edition-vide" hidden>Aucun article. Lisez le bon déposé, ou ajoutez-les à la main.</p>
            </div>

            <div class="materiel-dialog-pied">
                <div class="materiel-dialog-pied-gauche">
                    <div class="materiel-dialog-outils">
                        <button type="button" class="materiel-relire bouton-discret">Lire le bon déposé</button>
                        <button type="button" class="materiel-ajouter bouton-discret">Ajouter un article</button>
                    </div>
                    <button type="button" class="materiel-tout-recu bouton-discret">Tout marquer reçu</button>
                </div>
                <div class="materiel-dialog-boutons">
                    <span class="materiel-message" role="status" aria-live="polite"></span>
                    <button type="button" class="materiel-fermer bouton-discret">Annuler</button>
                    <button type="submit">Enregistrer</button>
                </div>
            </div>
        </form>
    </dialog>

    <!-- Le gabarit d'une ligne. Il vit dans un template plutôt que dans le script : son
         balisage est celui des lignes rendues par le script, et l'écrire deux fois le
         ferait diverger au premier champ ajouté. -->
    <template id="materiel-gabarit">
        <tr class="materiel-edition-ligne">
            <td class="materiel-colonne-recu">
                <button type="button" class="materiel-recu bouton-discret" aria-pressed="false">
                    <span class="materiel-recu-point" aria-hidden="true"></span>
                    <span class="materiel-recu-mot">Pas reçu</span>
                </button>
            </td>
            <?php // readonly par défaut : la case s'ouvre au clic, et se referme dès qu'on
                  // la quitte. La réception, elle, ne se pointe que par son bouton — un
                  // clic sur la ligne la cochait, et il suffisait de viser une case pour
                  // déclarer un article arrivé. ?>
            <td><input class="materiel-champ" data-champ="reference" maxlength="80" aria-label="Référence" readonly></td>
            <td><input class="materiel-champ" data-champ="designation" maxlength="180" aria-label="Désignation" readonly></td>
            <td><input class="materiel-champ materiel-champ-date" data-champ="date_livraison"
                       inputmode="numeric" maxlength="10" placeholder="jj/mm/aaaa"
                       aria-label="Date de livraison" readonly></td>
            <td><input class="materiel-champ" data-champ="unite" maxlength="16" aria-label="Unité" readonly></td>
            <td><input class="materiel-champ materiel-champ-nombre" data-champ="quantite" inputmode="decimal" aria-label="Quantité" readonly></td>
            <td>
                <button type="button" class="materiel-retirer bouton-discret" aria-label="Retirer cet article" title="Retirer">
                    <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                </button>
            </td>
        </tr>
    </template>
</section>
