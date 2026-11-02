<?php

/**
 * Les morceaux de « Mes soudures » (pages/soudures.php) : un plan et ses feuilles dans le
 * tableau, et la fenêtre d'une soudure. L'écran ne fait que les ranger.
 */

require_once __DIR__ . '/liste_filtrable.php';

/**
 * Comment une feuille se nomme : par son numéro de ligne, celui qui est imprimé sur le
 * papier que le soudeur a en main. Le nom du fichier et sa page ne servent que de secours,
 * pour un cahier déposé avant qu'on relève les numéros. C'est la règle du sommaire de la
 * fenêtre du plan, et deux noms pour une même feuille se paieraient à chaque recherche.
 */
function souduresNomFeuille(array $feuille): string
{
    return $feuille['numero'] !== ''
        ? $feuille['numero']
        : $feuille['nom'] . ($feuille['pages'] > 1 ? ' — p. ' . $feuille['page'] : '');
}

/**
 * Un plan dans le tableau : sa ligne en tête, puis ses feuilles.
 *
 * La ligne du plan est la ligne de groupe des autres listes — son nom, ses feuilles, sa
 * demande et son affaire —, et dans la colonne des soudures, le compte du plan entier.
 * soudures.js le refait après chaque enregistrement : ses deux nombres sont en attributs.
 *
 * Elle se déroule et se replie, d'un clic n'importe où : la flèche est un bouton, pour le
 * clavier. Tous les plans arrivent repliés — le plan qu'on travaille ne se devine pas, on le
 * déroule.
 *
 * @param array{element:int, reference:string, demande:int, affaire:string} $plan
 * @param list<array<string, mixed>> $feuilles les siennes (soudureAtelierFeuilles())
 */
function souduresPlanRendu(array $plan, array $feuilles): string
{
    $cherche = mb_strtolower(prefaReferenceComplete($plan['demande']) . ' ' . $plan['reference'] . ' ' . $plan['affaire']);
    $faites = array_sum(array_column($feuilles, 'faites'));
    $prevues = array_sum(array_map(static fn (array $feuille): int => (int) $feuille['imprimees'], $feuilles));
    ob_start();
    ?>
<tbody class="prefa-groupe soudures-plan" data-element="<?= (int) $plan['element'] ?>">
    <tr>
        <th scope="colgroup" colspan="2">
            <button type="button" class="soudures-plan-bascule" aria-expanded="false">
                <svg class="soudures-plan-fleche" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 4 4 4-4 4" /></svg>
                <span class="prefa-groupe-nom"><?= prefaEscape($plan['reference']) ?></span>
            </button>
            <span class="prefa-groupe-compte"><?= count($feuilles) ?></span>
            <span class="prefa-groupe-aide"><?= prefaEscape(prefaReference($plan['demande'])) ?><?= $plan['affaire'] !== '' ? ' · ' . prefaEscape($plan['affaire']) : '' ?></span>
        </th>
        <?php // Des en-têtes, comme le titre : la ligne de groupe en a toute l'allure, et le
              // survol des lignes de données ne la touche pas. ?>
        <th class="prefa-nombre soudures-plan-cordons" data-plan-cordons
            data-faites="<?= $faites ?>" data-prevues="<?= $prevues ?>"><?= prefaEscape(soudureAtelierCompteFeuille($faites, $prevues > 0 ? $prevues : null)) ?></th>
        <?php // La place d'un « Renseigner », invisible : les plans repliés, la colonne des
              // actions se serrait sur son titre, et le compte du plan sautait de cinquante
              // pixels au premier dépli. ?>
        <th aria-hidden="true"><span class="prefa-decision soudures-plan-gabarit"><button type="button" class="prefa-ligne-contour" tabindex="-1">Renseigner</button></span></th>
    </tr>
</tbody>
<?php if (!$feuilles): ?>
    <?php // Le soudeur n'a pas l'écran « Plans / ISO » : lui dire où le chef clique ne
          // l'avance à rien. Il lui faut savoir que ce n'est pas prêt, et vers qui se tourner. ?>
    <tbody class="soudures-plan-vide" data-element="<?= (int) $plan['element'] ?>" data-cherche="<?= prefaEscape($cherche) ?>" hidden>
        <tr><td colspan="4" class="prefa-table-empty">Ce plan n’a pas encore de feuilles. Demandez au chef d’atelier de les ajouter.</td></tr>
    </tbody>
<?php endif; ?>
<?php
    foreach ($feuilles as $feuille) echo souduresFeuilleRendu($feuille, $cherche);
    return ob_get_clean();
}

/**
 * Une feuille, un corps de tableau à elle, comme une demande ou un bon : la ligne porte son
 * repère, son état et de quoi la chercher — le script n'a rien à recomposer.
 *
 * @param string $cherchePlan ce qu'on cherche de son plan : sa demande, son nom, son affaire
 */
function souduresFeuilleRendu(array $feuille, string $cherchePlan): string
{
    // Le repère voyage d'un seul morceau — élément, feuille, page — sur la ligne comme sur ses
    // boutons : c'est ce qu'il faut pour lire le tableau soudure et pour ouvrir le dessin.
    $repere = $feuille['element'] . ':' . $feuille['document'] . ':' . $feuille['page'];
    $nom = souduresNomFeuille($feuille);
    $etat = soudureAtelierEtatFeuille($feuille['faites'], $feuille['imprimees']);
    ob_start();
    ?>
<tbody class="prefa-request soudures-feuille"
       data-repere="<?= prefaEscape($repere) ?>"
       data-feuille="<?= prefaEscape($nom) ?>"
       data-element="<?= (int) $feuille['element'] ?>"
       data-etat="<?= $etat['cle'] ?>"
       data-cherche="<?= prefaEscape($cherchePlan . ' ' . mb_strtolower($nom)) ?>" hidden>
    <tr>
        <?php // Le point de l'état ouvre la ligne, puis le numéro de ligne imprimé sur le
              // tirage — c'est par lui qu'on la cherche. ?>
        <td class="prefa-reference soudures-feuille-nom">
            <span class="prefa-point soudures-point est-<?= $etat['cle'] ?>" role="img" data-etat-point
                  aria-label="<?= prefaEscape($etat['libelle']) ?>" title="<?= prefaEscape($etat['libelle']) ?>"></span><?= prefaEscape($feuille['numero'] !== '' ? $feuille['numero'] : $feuille['nom']) ?>
        </td>
        <td class="prefa-secondaire soudures-feuille-page"><?= $feuille['page'] ?> / <?= $feuille['pages'] ?></td>
        <td class="prefa-nombre soudures-feuille-cordons">
            <span data-cordons><?= prefaEscape(soudureAtelierCompteFeuille($feuille['faites'], $feuille['imprimees'])) ?></span>
            <?php if ($feuille['imprimees']): ?><span class="soudures-barre" aria-hidden="true"><span style="--part: <?= soudureAtelierPartFeuille($feuille['faites'], $feuille['imprimees']) ?>%"></span></span><?php endif; ?>
        </td>
        <?php // La feuille s'ouvre sur son dessin, et c'est là qu'on la remplit : on clique une
              // ligne de sa liste soudure. Le bouton au contour des lignes de la maison —
              // « Détails » d'un bon. Toute la ligne l'actionne. ?>
        <td class="prefa-actions">
            <span class="prefa-decision">
                <button type="button" class="prefa-ligne-contour soudures-ouvrir-plan" data-repere="<?= prefaEscape($repere) ?>"
                        aria-haspopup="dialog" title="Ouvrir le dessin et renseigner ses soudures">Renseigner</button>
            </span>
        </td>
    </tr>
</tbody>
<?php
    return ob_get_clean();
}

/**
 * La fenêtre d'une soudure : ce qui s'ouvre quand on clique sa ligne dans la liste soudure
 * du dessin, ou son numéro sur le tube. La fenêtre de la maison — titre, champs, pied —,
 * posée par-dessus celle du plan. Son état tient dans une pastille à côté du titre ; aucune
 * phrase ne l'explique.
 */
function souduresCordonFenetre(): string
{
    // Les procédés qu'on propose. Un autre s'écrit à la main, dans le champ qui paraît dessous.
    $procedes = array_map(static fn (string $procede): array => ['id' => $procede, 'libelle' => $procede],
        ['TIG', 'MIG', 'MAG', '111', '135', '141']);
    ob_start();
    ?>
<dialog class="fenetre soudures-cordon" id="soudures-cordon" aria-labelledby="soudures-cordon-titre">
    <form method="dialog" id="soudures-cordon-form">
        <div class="fenetre-entete">
            <h2 id="soudures-cordon-titre">Soudure <span data-cordon-numero></span>
                <span class="pv-puce soudures-cordon-etat" data-cordon-etat hidden></span></h2>
            <button type="button" class="modal-close" data-fermer-cordon aria-label="Fermer">
                <svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
            </button>
        </div>
        <div class="fenetre-corps">
            <input type="date" class="fenetre-champ" name="date" aria-label="Date de soudage">
            <?php // Les procédés de la maison, dans la liste filtrable : on tape pour chercher,
                  // on choisit d'un clic. ?>
            <?= listeFiltrableRendu('cordon-procede', 'Procédé', $procedes, null, [
                'nom' => 'type',
                'autre' => 'Autre procédé…',
                'aucun' => 'Pas dans la liste : choisissez « Autre procédé ».',
                'chercher' => 'Procédé',
                'tableau' => true,
            ]) ?>
            <div id="cordon-procede-autre-field" class="soudures-cordon-autre" hidden>
                <input id="cordon-procede-autre" class="fenetre-champ" name="type_autre" maxlength="30"
                       autocomplete="off" placeholder="Procédé — 131, électrode…" aria-label="Autre procédé">
            </div>
            <p class="fenetre-message" role="status" aria-live="polite"></p>
        </div>
        <div class="fenetre-pied">
            <button type="button" class="soudures-cordon-effacer" data-cordon-effacer
                    title="Vider cette soudure : elle redevient à faire">Effacer</button>
            <button type="button" class="fenetre-annuler" data-fermer-cordon>Annuler</button>
            <button type="submit" class="fenetre-valider">Enregistrer</button>
        </div>
    </form>
</dialog>
<?php
    return ob_get_clean();
}
