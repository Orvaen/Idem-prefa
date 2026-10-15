<?php

require_once __DIR__ . '/../atelier/nomenclature.php';

/**
 * Les fichiers ISO d'un élément, et les numéros de coulée posés sur chacun.
 *
 * Un élément — « un plan » dans le vocabulaire de l'atelier — porte une ligne de
 * tuyauterie, et une ligne tient rarement sur une seule feuille. Le chef dépose donc
 * plusieurs ISO. Chacun a sa nomenclature imprimée, avec la colonne « N° COULÉE » laissée
 * en blanc par le bureau d'études : chacun reçoit donc ses propres cases, et son propre
 * bloc ici. Recopier le reste dans un tableau à part serait du travail pour rien.
 *
 * Le volet ne montre qu'un résumé par feuille : un plan A3 tassé dans une colonne ne se
 * lit pas, et on ne clique pas au millimètre sur une vignette. Le travail se fait dans une
 * fenêtre plein écran, une par feuille.
 *
 * Le balisage est écrit tel quel, entre ?> et <?php, et non assemblé bout à bout dans une
 * variable. C'est du HTML qu'on lit et qu'on corrige ligne à ligne : retirer un bouton,
 * c'est retirer ses lignes, sans se demander où retombent les points de concaténation.
 *
 * @param list<array{id:int, nom:string, reperes:list<array<string,mixed>>, empreinte:string}> $plans
 * @param list<array<string, mixed>> $coulees les coulées proposées par les certificats
 */
function planViewerRendu(int $elementId, array $plans, array $coulees, bool $modifiable, bool $avecListe = true): string
{
    // Les coulées des certificats, posées une seule fois : chaque case y puise ses
    // suggestions au moment où on la remplit. Une même coulée relevée sur deux certificats
    // n'apparaît qu'une fois, confirmée si l'un des deux la confirme.
    $proposees = [];
    foreach ($coulees as $coulee) {
        $numero = (string) $coulee['numero'];
        if ($numero === '') continue;
        $proposees[$numero] = ($proposees[$numero] ?? false) || (int) $coulee['confirmee'] === 1;
    }
    $proposees = array_map(static fn (string $numero, bool $confirmee): array =>
        ['numero' => $numero, 'confirmee' => $confirmee], array_keys($proposees), $proposees);
    $couleesJson = prefaEscape(json_encode($proposees));
    $csrf = $modifiable ? prefaEscape(Session::jeton()) : '';

    ob_start();
    ?>
<section class="plan-viewer-plans<?= $avecListe ? '' : ' plan-viewer-plans-muet' ?>">
    <div class="plan-viewer-entete">
        <h4>Feuilles ISO</h4>
        <?php if ($modifiable): ?>
            <!-- Le chef reçoit les plans d'exécution après la demande : il les dépose
                 lui-même, plutôt que d'attendre que le demandeur les joigne. Chaque dépôt
                 s'ajoute aux précédents : une ligne tient rarement sur une seule feuille. -->
            <label class="plan-viewer-deposer" data-plan="<?= $elementId ?>" data-csrf="<?= $csrf ?>">
                Déposer un plan
                <input type="file" accept="application/pdf,.pdf" hidden>
            </label>
        <?php endif; ?>
    </div>

    <?php if (!$plans): ?>
        <p class="plan-viewer-vide">Aucun plan ISO déposé pour cet élément.</p>
    <?php endif; ?>

    <?php if ($modifiable && count($plans) > 1): ?>
        <!-- Retirer un cahier déposé par erreur, c'est quinze feuilles à sortir : une par
             une, c'est une corvée. La case de chaque ligne ouvre cette barre, qui n'existe
             que tant qu'il y a une sélection. -->
        <div class="plan-viewer-selection" data-plan="<?= $elementId ?>" data-csrf="<?= $csrf ?>">
            <label class="plan-viewer-tout-label">
                <input type="checkbox" class="plan-viewer-tout"> Tout sélectionner
            </label>
            <span class="plan-viewer-selection-compte" role="status" aria-live="polite"></span>
            <button type="button" class="plan-viewer-supprimer-lot" hidden>Supprimer la sélection</button>
        </div>
    <?php endif; ?>

    <?php foreach ($plans as $plan): ?>
        <!-- Un bloc par feuille : son nom, ce qu'on y a posé, et de quoi l'ouvrir ou la
             sortir annotée. Chaque bloc porte son empreinte, puisque chaque feuille
             s'enregistre séparément. -->
        <?php
        // Un cahier déposé d'un bloc porte plusieurs pages : la fenêtre les parcourt, et
        // chacune a sa liste soudure. Le résumé le dit sur la ligne, sans avoir à ouvrir.
        $pagesPlan = max(1, (int) ($plan['pages'] ?? 1));
        // Ce que chaque page porte déjà. Le sommaire le dit sous sa vignette : on retrouve
        // la feuille où l'on s'était arrêté sans avoir à les ouvrir une par une. Une feuille
        // unique n'a pas de sommaire, donc rien à compter.
        $posees = [];
        foreach ($pagesPlan > 1 ? $plan['reperes'] : [] as $numeroPage => $reperesPage) {
            $posees[(int) $numeroPage] = count(array_filter(
                $reperesPage,
                static fn (array $repere): bool => (string) $repere['numero_coulee'] !== ''
            ));
        }
        $resume = [];
        if ($pagesPlan > 1) $resume[] = $pagesPlan . ' pages';
        if (!empty($plan['cordons'])) $resume[] = (int) $plan['cordons']
            . ((int) $plan['cordons'] > 1 ? ' cordons saisis' : ' cordon saisi');
        ?>
        <div class="plan-viewer" data-plan="<?= $elementId ?>" data-document="<?= (int) $plan['id'] ?>"
                 data-empreintes="<?= prefaEscape(json_encode((object) ($plan['empreintes'] ?? []))) ?>"
                 data-pages="<?= $pagesPlan ?>" data-page="1"
                 <?php if ($modifiable): ?>data-csrf="<?= $csrf ?>" data-coulees="<?= $couleesJson ?>"
                 <?php else: ?><?php // Lire les tableaux imprimés sans rien pouvoir poser dessus :
                                     // c'est ce qu'il faut pour que la liste des soudures s'affiche
                                     // sur le plan de celui qui le travaille. Un jeton distinct, pour
                                     // qu'aucune commande d'écriture ne se croie permise. ?>
                 data-lecture="<?= prefaEscape(Session::jeton()) ?>"<?php endif; ?>>

            <div class="plan-viewer-ligne">
                <?php if ($modifiable && count($plans) > 1): ?>
                    <input type="checkbox" class="plan-viewer-choix"
                           aria-label="Sélectionner <?= prefaEscape($plan['nom']) ?>">
                <?php endif; ?>
                <span class="plan-viewer-nom"><?= prefaEscape($plan['nom']) ?></span>
                <?php if ($resume): ?><span class="plan-viewer-resume"><?= prefaEscape(implode(' · ', $resume)) ?></span><?php endif; ?>
                <span class="plan-viewer-boutons">
                    <button type="button" class="plan-viewer-ouvrir"><?= $modifiable ? 'Ouvrir' : 'Voir' ?></button>
                    <a class="plan-viewer-final"
                       href="actions/download_plan_final.php?plan=<?= $elementId ?>&amp;document=<?= (int) $plan['id'] ?>"
                       download title="Cette feuille avec les numéros de coulée et la liste des soudures">
                        Télécharger
                    </a>
                    <?php if ($modifiable): ?>
                        <!-- Une feuille déposée par erreur, ou reçue en révision, se remplace sur
                             place : le nouveau PDF prend sa ligne. Ce qui était posé dessus part
                             avec elle — des positions relevées sur un autre dessin ne veulent
                             plus rien dire. -->
                        <label class="plan-viewer-remplacer" title="Déposer un autre PDF à la place de cette feuille">
                            Remplacer
                            <input type="file" accept="application/pdf,.pdf" hidden>
                        </label>
                        <!-- Le chef dépose ses plans, il doit pouvoir en retirer un. Ce qui a
                             été posé sur la feuille part avec elle : le bouton le dit avant
                             d'agir, et reste discret jusqu'au survol. -->
                        <button type="button" class="plan-viewer-supprimer"
                                title="Retirer cette feuille de l'élément">Supprimer</button>
                    <?php endif; ?>
                </span>
            </div>

            <dialog class="plan-dialog" aria-label="Plan <?= prefaEscape($plan['nom']) ?>">
                <div class="plan-dialog-barre">
                    <div class="plan-dialog-titre">
                        <strong><?= prefaEscape($plan['nom']) ?></strong>
                        <span><?= $modifiable
                            ? 'Double-cliquez une case pour choisir sa coulée. Ctrl+D en ajoute une sous la case choisie.'
                            : 'Plan en consultation.' ?></span>
                    </div>

                    <!-- La barre range ses commandes par métier : se déplacer dans le
                         document, le regarder, agir dessus, fermer. Chaque groupe est un
                         bloc d'un seul tenant — même hauteur, même arrondi, un seul contour
                         autour des flèches et de leur valeur — pour qu'on lise trois objets
                         et non huit boutons disparates. -->
                    <div class="plan-dialog-outils">
                        <?php if ($pagesPlan > 1): ?>
                            <!-- Les pages du cahier. Le dessin, ses cases de coulée, sa liste
                                 soudure et les tableaux reconnus dessus vont ensemble : on
                                 tourne la page, tout suit. -->
                            <span class="plan-dialog-groupe plan-dialog-pages">
                                <button type="button" class="plan-page" data-saut="-1" aria-label="Page précédente">‹</button>
                                <span class="plan-page-valeur" title="Double-cliquez pour aller à une page">Page 1 / <?= $pagesPlan ?></span>
                                <button type="button" class="plan-page" data-saut="1" aria-label="Page suivante">›</button>
                            </span>
                        <?php endif; ?>

                        <span class="plan-dialog-groupe plan-dialog-zoom">
                            <button type="button" class="plan-zoom" data-zoom="-1" aria-label="Réduire">−</button>
                            <span class="plan-zoom-valeur">100&nbsp;%</span>
                            <button type="button" class="plan-zoom" data-zoom="1" aria-label="Agrandir">+</button>
                        </span>

                        <span class="plan-viewer-message" role="status" aria-live="polite"></span>

                        <?php if ($pagesPlan > 1): ?>
                            <!-- Le sommaire s'ouvre avec la fenêtre, puisque c'est par lui
                                 qu'on circule : son bouton de rappel ne sert qu'une fois
                                 replié, et reste donc caché jusque-là. -->
                            <button type="button" class="plan-dialog-sommaire-ouvrir" hidden>Sommaire</button>
                        <?php endif; ?>

                        <?php if (!empty($plan['soudures'])): ?>
                            <!-- Sur un plan qu'on peut remplir, la liste est repliée à
                                 l'ouverture : on vient d'abord voir le dessin, en grand, et
                                 c'est d'ici qu'on la déplie. En lecture seule, c'est
                                 l'inverse — on n'a rien à poser sur le dessin, on vient
                                 relire une ligne déjà écrite : elle est là d'emblée, et ce
                                 bouton ne sert qu'à la rappeler après l'avoir rangée. -->
                            <button type="button" class="plan-dialog-liste-ouvrir"<?= $modifiable ? '' : ' hidden' ?>>Liste des soudures</button>
                        <?php endif; ?>

                        <?php if ($modifiable): ?>
                            <!-- La seule commande qui écrit : c'est elle qu'on remplit. -->
                            <button type="button" class="plan-viewer-enregistrer">Enregistrer</button>
                        <?php endif; ?>

                        <button type="button" class="plan-dialog-fermer" aria-label="Fermer">✕</button>
                    </div>
                </div>

                <div class="plan-dialog-fond">
                <?php if ($pagesPlan > 1): ?>
                    <!-- Le sommaire du cahier, à gauche du dessin. Un isométrique se
                         reconnaît à son tracé bien avant à son numéro : les flèches
                         obligeaient à feuilleter les quinze pages pour retrouver la bonne,
                         la vignette la donne d'un coup d'œil. Il tient la gauche, comme le
                         sommaire d'un lecteur de PDF, et la liste des soudures garde la
                         droite : on ne se demande pas où chercher quoi. -->
                    <nav class="plan-dialog-sommaire" aria-label="Pages du plan <?= prefaEscape($plan['nom']) ?>">
                        <!-- Même poignée que la liste des soudures, posée sur l'autre bord :
                             une colonne de vignettes qu'on ne peut pas élargir se lit mal
                             dès que le cartouche compte. -->
                        <div class="plan-dialog-glissiere" role="separator" aria-orientation="vertical"
                             title="Glisser pour régler la largeur du sommaire"></div>
                        <div class="plan-dialog-sommaire-barre">
                            <span class="plan-dialog-listes-titre">Sommaire</span>
                            <button type="button" class="plan-dialog-sommaire-replier">Replier</button>
                        </div>
                        <?php if ($pagesPlan > 5): ?>
                            <!-- Au-delà de cinq feuilles la liste défile, et retrouver un
                                 numéro demande de la parcourir à l'œil. En deçà elles
                                 tiennent toutes à l'écran : un champ de plus serait du
                                 bruit. -->
                            <input type="search" class="plan-sommaire-recherche"
                                   placeholder="Chercher un numéro"
                                   aria-label="Chercher une feuille par son numéro de ligne">
                            <p class="plan-sommaire-vide" hidden>Aucune feuille à ce numéro.</p>
                        <?php endif; ?>
                        <ol class="plan-dialog-sommaire-corps">
                            <?php for ($numeroPage = 1; $numeroPage <= $pagesPlan; $numeroPage++): ?>
                                <li data-recherche="<?= prefaEscape(mb_strtolower(($plan['numeros_ligne'][$numeroPage] ?? '') . ' page ' . $numeroPage)) ?>">
                                    <!-- La vignette n'est demandée qu'à l'ouverture de la
                                         fenêtre, comme le plan : un volet qui montre vingt
                                         feuilles ne doit pas en rastériser trois cents. -->
                                    <button type="button" class="plan-sommaire-page<?= $numeroPage === 1 ? ' est-vue' : '' ?>"
                                            data-page="<?= $numeroPage ?>" <?= $numeroPage === 1 ? 'aria-current="page"' : '' ?>>
                                        <img alt="" data-src="actions/plan_image.php?plan=<?= $elementId ?>&amp;document=<?= (int) $plan['id'] ?>&amp;page=<?= $numeroPage ?>&amp;vignette=1">
                                        <?php
                                        // Le numéro de ligne plutôt que celui de la page : c'est
                                        // lui qui est imprimé sur la feuille qu'on a en main, et
                                        // un cahier ne numérote pas ses pages. On retombe sur le
                                        // rang quand le cartouche n'a rien donné.
                                        $numeroLigne = $plan['numeros_ligne'][$numeroPage] ?? null;
                                        ?>
                                        <span class="plan-sommaire-numero"<?= $numeroLigne ? ' title="Page ' . $numeroPage . ' du cahier"' : '' ?>><?= prefaEscape($numeroLigne ?? 'Page ' . $numeroPage) ?></span>
                                        <?php if (!empty($posees[$numeroPage])): ?>
                                            <span class="plan-sommaire-marque"><?= $posees[$numeroPage] ?> coulée<?= $posees[$numeroPage] > 1 ? 's' : '' ?></span>
                                        <?php endif; ?>
                                    </button>
                                </li>
                            <?php endfor; ?>
                        </ol>
                    </nav>
                <?php endif; ?>
                <div class="plan-dialog-corps">
                    <div class="plan-viewer-scene" <?= $modifiable ? 'data-posable="1"' : '' ?>>
                        <!-- L'image n'est chargée qu'à l'ouverture : un plan pèse quelques
                             centaines de kilo-octets, et avec vingt feuilles on les
                             téléchargerait toutes pour n'en regarder qu'une. -->
                        <img data-src="actions/plan_image.php?plan=<?= $elementId ?>&amp;document=<?= (int) $plan['id'] ?>"
                             alt="Plan <?= prefaEscape($plan['nom']) ?>">

                        <?php foreach ($plan['reperes'] as $numeroPage => $reperesPage): ?>
                            <?php foreach ($reperesPage as $repere): ?>
                                <?php if ((string) $repere['numero_coulee'] === '' && $repere['pos_x'] === null) continue; ?>
                                <?= planViewerRepere($repere, $modifiable, (int) $numeroPage, (int) $numeroPage !== 1) ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>

                        <?php if ($modifiable): ?>
                            <!-- Le moule des cases : celles que la lecture du tableau pose, et
                                 celles qu'on duplique au clavier. Sa taille est celle d'une
                                 case détectée sur ces plans : son chiffre sort alors à la
                                 taille des lettres imprimées, comme partout ailleurs. -->
                            <template class="plan-viewer-modele">
                                <?= planViewerRepere(['numero_coulee' => '', 'pos_x' => 0, 'pos_y' => 0,
                                    'pos_w' => 0.058, 'pos_h' => 0.009], true) ?>
                            </template>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($plan['soudures'])): ?>
                    <!-- La liste se remplit à côté du dessin sur lequel elle est imprimée : on
                         relit le cordon sur le plan en saisissant sa ligne, plutôt que de
                         fermer la fenêtre pour aller la chercher dans le volet.
                         Elle tient la droite : un isométrique est en paysage, c'est la hauteur
                         qui lui manque, pas la largeur. Le partage reste réglable — la poignée
                         se glisse, et le bouton replie la liste quand c'est le dessin qu'on
                         veut en entier. -->
                    <div class="plan-dialog-listes"<?= $modifiable ? ' hidden' : '' ?>>
                        <div class="plan-dialog-glissiere" role="separator" aria-orientation="vertical"
                             title="Glisser pour partager la fenêtre entre le plan et la liste"></div>
                        <div class="plan-dialog-listes-barre">
                            <span class="plan-dialog-listes-titre">Liste des soudures</span>
                            <button type="button" class="plan-dialog-replier" aria-expanded="true">Replier</button>
                        </div>
                        <div class="plan-dialog-listes-corps">
                            <?php foreach ($plan['soudures'] as $numeroPage => $tableau): ?>
                                <section class="plan-dialog-liste" data-page="<?= (int) $numeroPage ?>" <?= (int) $numeroPage === 1 ? '' : 'hidden' ?>>
                                    <?php if ($pagesPlan > 1): ?><h5>Page <?= (int) $numeroPage ?></h5><?php endif; ?>
                                    <?= $tableau ?>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </dialog>
        </div>
    <?php endforeach; ?>
</section>
    <?php
    return ob_get_clean();
}

/**
 * Une case posée sur le plan : le numéro de coulée d'une ligne de la nomenclature.
 *
 * Elle appartient à une page : un cahier en porte une nomenclature par isométrique, et
 * les cases d'une page n'ont pas de sens sur le dessin d'une autre. Seules celles de la
 * page affichée sont visibles ; le script montre et cache les autres au fil des pages.
 */
function planViewerRepere(array $repere, bool $modifiable, int $page = 1, bool $masquee = false): string
{
    $coulee = (string) ($repere['numero_coulee'] ?? '');
    // Position ET dimensions en pourcentage du plan : le repère couvre toujours la même
    // portion de la feuille, à n'importe quel zoom.
    $largeur = (float) ($repere['pos_w'] ?? 0.09);
    $hauteur = (float) ($repere['pos_h'] ?? 0.018);
    $style = sprintf(
        'left:%.4f%%;top:%.4f%%;width:%.4f%%;height:%.4f%%',
        (float) ($repere['pos_x'] ?? 0) * 100,
        (float) ($repere['pos_y'] ?? 0) * 100,
        ($largeur > 0 ? $largeur : 0.09) * 100,
        ($hauteur > 0 ? $hauteur : 0.018) * 100
    );
    $affiche = prefaEscape($coulee !== '' ? $coulee : '—');

    ob_start();
    if (!$modifiable): ?>
<div class="plan-coulee is-figee" data-page="<?= $page ?>" style="<?= $style ?>" <?= $masquee ? 'hidden' : '' ?>><?= $affiche ?></div>
    <?php else: ?>
<!-- Une case se manipule comme un élément de mise en page : on la saisit n'importe où pour
     la déplacer, et huit poignées la redimensionnent. Le choix du numéro est une autre
     action, au double-clic — sans quoi le champ avalerait chaque clic et la case
     deviendrait impossible à attraper.

     Un seul champ, qui fait les deux : on y tape un numéro, et les coulées des certificats
     s'ouvrent dessous, filtrées au fil de la frappe. -->
<div class="plan-coulee" data-page="<?= $page ?>" style="<?= $style ?>" <?= $masquee ? 'hidden' : '' ?> tabindex="0"
     title="Glisser pour déplacer · double-clic pour choisir la coulée · Ctrl+D pour en ajouter une dessous · Suppr pour retirer">
    <span class="plan-coulee-valeur"><?= $affiche ?></span>
    <input type="text" name="coulee" value="<?= prefaEscape($coulee) ?>" maxlength="20"
           role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off"
           aria-label="Numéro de coulée" placeholder="n° coulée" hidden>
    <?php foreach (['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'] as $coin): ?>
        <span class="plan-poignee poignee-<?= $coin ?>" data-coin="<?= $coin ?>" aria-hidden="true"></span>
    <?php endforeach; ?>
</div>
    <?php endif;
    return ob_get_clean();
}
