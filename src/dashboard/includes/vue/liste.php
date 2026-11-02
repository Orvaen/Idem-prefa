<?php

/**
 * Les pièces communes des écrans de liste : la densité des lignes, « Actualiser », la
 * recherche, les onglets d'état.
 *
 * Chaque écran les écrivait à la main — la densité en dix-neuf lignes de SVG, recopiées à
 * l'identique dans huit écrans. Et c'est l'écart d'un écran avec ses voisins qui le fait
 * paraître « mal organisé » (docs/direction-artistique.md) : écrites une fois, elles ne
 * peuvent plus s'écarter.
 *
 * Elles ne posent que le balisage. Chaque écran garde ses classes à lui, celles que son
 * script écoute par délégation : le composant les reçoit, il ne les invente pas.
 */

/** Les deux densités du tableau, confortable et compacte : prefa-interactions.js les bascule. */
function listeDensite(): string
{
    ob_start();
    ?>
<div class="prefa-densite" role="group" aria-label="Densité du tableau">
    <button type="button" class="prefa-densite-choix" data-densite="confortable"
            aria-pressed="true" aria-label="Lignes confortables" title="Lignes confortables">
        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
            <rect x="2" y="3" width="12" height="2.6" rx="1" />
            <rect x="2" y="6.7" width="12" height="2.6" rx="1" />
            <rect x="2" y="10.4" width="12" height="2.6" rx="1" />
        </svg>
    </button>
    <button type="button" class="prefa-densite-choix" data-densite="compact"
            aria-pressed="false" aria-label="Lignes compactes" title="Lignes compactes">
        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
            <rect x="2" y="2.6" width="12" height="1.6" rx=".8" />
            <rect x="2" y="5.6" width="12" height="1.6" rx=".8" />
            <rect x="2" y="8.6" width="12" height="1.6" rx=".8" />
            <rect x="2" y="11.6" width="12" height="1.6" rx=".8" />
        </svg>
    </button>
</div>
<?php
    return ob_get_clean();
}

/**
 * « Actualiser », au contour : recharge l'écran.
 *
 * @param string $classe les classes du bouton — celle que le script de l'écran écoute
 */
function listeActualiser(string $classe): string
{
    return '<button type="button" class="' . prefaEscape($classe) . '">'
        . '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"'
        . ' stroke-linecap="round" stroke-linejoin="round"><path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 3M4 16l2 3a7 7 0 0 1 12-2" /></svg>'
        . 'Actualiser</button>';
}

/**
 * Le champ de recherche de la barre, sa loupe dedans.
 *
 * @param string $id      l'identifiant du champ, celui que le script de l'écran écoute
 * @param string $invite  ce qu'on y cherche, « N° de ligne, plan, affaire… »
 * @param string $libelle son nom pour qui ne voit pas l'invite, « Rechercher une feuille »
 */
function listeRecherche(string $id, string $invite, string $libelle): string
{
    return '<div class="filtres-recherche">'
        . '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"'
        . ' stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>'
        . '<input id="' . prefaEscape($id) . '" type="search" autocomplete="off" aria-label="' . prefaEscape($libelle)
        . '" placeholder="' . prefaEscape($invite) . '">'
        . '</div>';
}

/**
 * La barre des onglets d'état : le premier ne filtre rien, puis un onglet par état, avec
 * le point de couleur qui sert de légende à ceux des lignes, et son compte. À droite, le
 * résumé chiffré de l'écran.
 *
 * @param string $classe  la classe des onglets de l'écran, celle que son script écoute
 * @param string $donnee  la donnée que porte chaque onglet : « etat » donne data-etat
 * @param string $libelle le nom du groupe, pour qui ne voit pas les onglets
 * @param array{libelle:string, compte:int} $tous le premier onglet, « Toutes » ou « Tous »
 * @param list<array{cle:string, libelle:string, compte:int, point:string, aide?:string}> $onglets
 *        « point » : les classes du point, après « prefa-point »
 * @param string $resume  le résumé à droite, en HTML déjà échappé ; vide, il n'y en a pas
 */
function listeOnglets(string $classe, string $donnee, string $libelle, array $tous, array $onglets, string $resume = ''): string
{
    $onglet = static fn (string $cle, string $contenu, string $aide, bool $presse): string =>
        '<button type="button" class="prefa-onglet ' . prefaEscape($classe) . '" data-' . $donnee . '="' . prefaEscape($cle) . '"'
        . ($aide !== '' ? ' title="' . prefaEscape($aide) . '"' : '') . ' aria-pressed="' . ($presse ? 'true' : 'false') . '">'
        . $contenu . '</button>';
    $compte = static fn (int $n): string => ' <span class="prefa-onglet-compte">' . $n . '</span>';

    $html = $onglet('', prefaEscape($tous['libelle']) . $compte($tous['compte']), '', true);
    foreach ($onglets as $etat) {
        $html .= $onglet($etat['cle'], '<span class="prefa-point ' . prefaEscape($etat['point']) . '" aria-hidden="true"></span> '
            . prefaEscape($etat['libelle']) . $compte($etat['compte']), $etat['aide'] ?? '', false);
    }
    return '<div class="prefa-onglets-barre"><div class="prefa-onglets" role="group" aria-label="' . prefaEscape($libelle) . '">'
        . $html . '</div>' . ($resume !== '' ? '<p class="prefa-list-summary">' . $resume . '</p>' : '') . '</div>';
}
