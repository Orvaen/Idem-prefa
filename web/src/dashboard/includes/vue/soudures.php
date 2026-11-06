<?php

/**
 * Les morceaux des soudures de « Mes plans » (pages/atelier.php) : la carte d'une feuille, et la
 * fenêtre d'une soudure. L'écran ne fait que les ranger.
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
 * Une feuille, en carte : son dessin en vignette, son numéro de ligne, et où elle en est.
 *
 * Refaite deux fois le 6 novembre 2026. L'écran montrait des plans à déplier, puis leurs
 * feuilles en tableau (« je vais dans soudures, je vois un plan ? ») ; puis des cartes de
 * cinq lignes de texte — état, compte, barre, affaire — sans plus dire de quel plan elles
 * étaient (« trop de détails, et je ne sais pas à quel plan c'est relié »). Le plan est
 * maintenant la ligne qui se déplie sur ses cartes (pages/atelier.php), et la carte ne dit plus
 * que ce qui se reconnaît d'un coup d'œil : le dessin, le numéro imprimé sur le papier, et
 * « 3 / 5 » sur une barre — verte, avec une coche, quand la feuille est finie. L'état en
 * toutes lettres reste pour les lecteurs d'écran.
 *
 * La carte est un seul bouton : la toucher ouvre le dessin, à sa page (soudures.js). Elle
 * porte ce que le script relit — repère, état, de quoi la chercher — et ce qu'il récrit
 * après chaque enregistrement (data-cordons, data-etat-libelle, la barre).
 *
 * @param array<string, mixed> $feuille une entrée de soudureAtelierFeuilles()
 * @param array{element:int, reference:string, demande:int, affaire:string} $plan le sien
 */
function souduresCarteRendu(array $feuille, array $plan): string
{
    // Le repère voyage d'un seul morceau — élément, feuille, page — : c'est ce qu'il faut
    // pour lire le tableau soudure et pour ouvrir le dessin.
    $repere = $feuille['element'] . ':' . $feuille['document'] . ':' . $feuille['page'];
    $nom = souduresNomFeuille($feuille);
    $etat = soudureAtelierEtatFeuille($feuille['faites'], $feuille['imprimees']);
    // On la cherche par son numéro de ligne d'abord ; son plan, sa demande et son affaire
    // aussi, pour qui ne l'a pas sous les yeux.
    $cherche = mb_strtolower($nom . ' ' . $plan['reference'] . ' ' . $plan['libelle'] . ' '
        . prefaReferenceComplete($plan['demande']) . ' ' . $plan['affaire']);
    $part = soudureAtelierPartFeuille($feuille['faites'], $feuille['imprimees']);
    // La vignette du sommaire de la fenêtre du plan : rastérisée au dépôt, gardée en cache,
    // et demandée seulement quand la carte arrive à l'écran (loading="lazy").
    $vignette = 'actions/plan_image.php?plan=' . (int) $feuille['element'] . '&document=' . (int) $feuille['document']
        . '&page=' . (int) $feuille['page'] . '&vignette=1';
    ob_start();
    ?>
<button type="button" class="soudures-feuille soudures-ouvrir-plan"
        data-repere="<?= prefaEscape($repere) ?>"
        data-feuille="<?= prefaEscape($nom) ?>"
        data-element="<?= (int) $feuille['element'] ?>"
        data-etat="<?= $etat['cle'] ?>"
        data-cherche="<?= prefaEscape($cherche) ?>"
        aria-haspopup="dialog">
    <span class="soudures-carte-image">
        <img src="<?= prefaEscape($vignette) ?>" alt="" loading="lazy" decoding="async">
        <svg class="soudures-carte-coche" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
    </span>
    <span class="soudures-carte-nom"><?= prefaEscape($nom) ?></span>
    <span class="soudures-carte-avancement">
        <span class="soudures-barre" aria-hidden="true"><span style="--part: <?= $part ?>%"></span></span>
        <span class="soudures-carte-compte" data-cordons><?= prefaEscape(soudureAtelierCompteFeuille($feuille['faites'], $feuille['imprimees'])) ?></span>
    </span>
    <span class="sr-only" data-etat-libelle><?= prefaEscape($etat['libelle']) ?></span>
</button>
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
