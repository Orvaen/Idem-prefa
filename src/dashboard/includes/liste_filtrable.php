<?php

/**
 * Le champ « liste filtrable » : on tape pour chercher, on choisit à la souris ou au
 * clavier, et l'identifiant retenu part dans un champ caché.
 *
 * Son balisage faisait vingt lignes, écrites à la main pour la passivation puis recopiées
 * pour la matière — avec les identifiants, les rôles ARIA et les styles en ligne à refaire
 * à l'identique. Ajouter un troisième champ voulait dire recopier une troisième fois, et
 * on ne voyait plus, dans le formulaire, où finissait un champ et où commençait le suivant.
 *
 * Tout est ici. Le script liste-filtrable.js reconnaît ce balisage à son attribut
 * data-liste-filtrable et s'y branche tout seul : il n'y a rien à déclarer de son côté.
 *
 * Les identifiants restent dérivés du préfixe — « matiere » donne #matiere-search — parce
 * que l'étiquette et les attributs ARIA en ont besoin. C'est le composant qui les écrit,
 * plus personne à la main.
 */

/**
 * @param string $prefixe        « passivation », « matiere », « affaire ». Donne les
 *                               identifiants et le nom du champ caché, id_<préfixe>.
 * @param string $libelle        l'étiquette au-dessus du champ
 * @param list<array{id:int|string, libelle:string}> $options  ce qu'on peut choisir
 * @param int|string|null $choisi l'option retenue, le cas échéant
 * @param array{
 *     vide?: string,            le premier choix, celui qui ne choisit rien ; absent = pas de choix vide
 *     autre?: string,           l'étiquette de « Autre (préciser)… » ; absent = pas d'issue libre
 *     aucun?: string,           ce qu'on affiche quand la recherche ne donne rien
 *     aide?: string,            une phrase sous le champ
 *     obligatoire?: bool,
 *     chercher?: string,        le texte d'invite dans le champ
 *     tableau?: bool,           pour une cellule : ni groupe de formulaire ni étiquette
 *     nom?: string,             le nom du champ caché ; par défaut id_<préfixe>
 * } $reglages
 */
function listeFiltrableRendu(string $prefixe, string $libelle, array $options, int|string|null $choisi, array $reglages = []): string
{
    $vide = $reglages['vide'] ?? null;
    $autre = $reglages['autre'] ?? null;
    $aucun = $reglages['aucun'] ?? 'Aucun résultat.';
    $chercher = $reglages['chercher'] ?? 'Rechercher ou sélectionner…';
    $choisi = (string) ($choisi ?? '');

    // Le texte affiché dans le champ est celui de l'option retenue : à l'ouverture d'une
    // demande déjà enregistrée, on doit lire « Inox 316L » et non son numéro.
    $texte = '';
    foreach ($options as $option) {
        if ((string) $option['id'] === $choisi && $choisi !== '') $texte = (string) $option['libelle'];
    }

    // Dans un tableau, l'intitulé de la colonne tient lieu d'étiquette : on la donne aux
    // lecteurs d'écran par aria-label plutôt que de l'écrire une fois par ligne.
    //
    // Sans <label for>, floating-fields.js laisse aussi le champ tranquille — et c'est
    // voulu : son habillage fait 44 px de haut, une ligne de tableau en fait 40.
    $tableau = (bool) ($reglages['tableau'] ?? false);

    $html = $tableau ? '' : '<div class="form-group">'
        . '<label for="' . $prefixe . '-search">' . prefaEscape($libelle)
        . (($reglages['obligatoire'] ?? false) ? ' <span class="required-mark" aria-hidden="true">*</span>' : '')
        . '</label>';

    // L'astérisque de l'intitulé se lit, mais ne se vérifie pas : la valeur choisie vit
    // dans un champ caché, qu'aucun « required » ne couvre. Le marqueur le dit au script
    // qui contrôle le formulaire avant de l'envoyer.
    $html .= '<div class="liste-filtrable' . ($tableau ? ' liste-filtrable-tableau' : '')
        . '" data-liste-filtrable="' . $prefixe . '"'
        . (($reglages['obligatoire'] ?? false) ? ' data-obligatoire="1"' : '') . '>'
        . '<input id="' . $prefixe . '-search" class="liste-filtrable-champ" type="text"'
        . ' value="' . prefaEscape($texte) . '" placeholder="' . prefaEscape($chercher) . '"'
        . ($tableau ? ' aria-label="' . prefaEscape($libelle) . '"' : '')
        . ' autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false"'
        . ' aria-controls="' . $prefixe . '-options">'
        . '<button type="button" id="' . $prefixe . '-clear" class="liste-filtrable-effacer"'
        // Un « × » typographique n'a ni la graisse ni la taille des autres icônes de
        // l'écran, qui sont toutes des tracés : il arrivait plus épais et mal centré.
        . ' aria-label="Effacer : ' . prefaEscape($libelle) . '" title="Effacer">'
        . '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="2" stroke-linecap="round">'
        . '<path d="m6 6 12 12M18 6 6 18" /></svg></button>'
        // Le nom du champ caché se dérive du préfixe, sauf quand un formulaire déjà en
        // service en attend un autre : on ne renomme pas un champ reçu par une action pour
        // le confort du composant.
        . '<input type="hidden" name="' . prefaEscape($reglages['nom'] ?? ('id_' . $prefixe))
        . '" value="' . prefaEscape($choisi) . '">'
        // Le volet est caché des deux façons : l'attribut pour les lecteurs d'écran, le
        // style parce qu'une règle de mise en page lui pose un display qui l'emporterait.
        . '<div id="' . $prefixe . '-options" class="liste-filtrable-options" role="listbox" hidden style="display: none;">';

    if ($vide !== null) {
        $html .= '<div id="' . $prefixe . '-none" role="option" data-value=""'
            . ' aria-selected="' . ($choisi === '' ? 'true' : 'false') . '">' . prefaEscape($vide) . '</div>';
    }

    foreach ($options as $option) {
        $id = (string) $option['id'];
        $html .= '<div id="' . $prefixe . '-option-' . prefaEscape($id) . '" role="option"'
            . ' data-value="' . prefaEscape($id) . '"'
            . ' aria-selected="' . ($id === $choisi ? 'true' : 'false') . '">'
            . prefaEscape($option['libelle']) . '</div>';
    }

    // L'issue libre n'est pas toujours offerte : on choisit sa matière hors catalogue, on
    // ne choisit pas une affaire hors catalogue — c'est le chargé de production qui les ouvre.
    if ($autre !== null) {
        $html .= '<div id="' . $prefixe . '-option-autre" class="liste-filtrable-autre" role="option"'
            . ' data-value="autre" aria-selected="false">' . prefaEscape($autre) . '</div>';
    }

    $html .= '<p class="liste-filtrable-vide" hidden style="display: none;">' . prefaEscape($aucun) . '</p>'
        . '</div></div>';

    if (!empty($reglages['aide'])) {
        $html .= '<small class="prefa-aide">' . prefaEscape($reglages['aide']) . '</small>';
    }

    return $tableau ? $html : $html . '</div>';
}

/**
 * Le champ de précision qui accompagne « Autre (préciser)… ».
 *
 * Il vit hors du champ filtrable, sur toute la largeur du formulaire : c'est une seconde
 * question, posée seulement si on a répondu « Autre » à la première. Le script le montre
 * et le rend obligatoire à ce moment-là.
 */
function listeFiltrableAutreRendu(string $prefixe, string $libelle, string $invite, string $nom): string
{
    return '<div class="form-group prefa-full" id="' . $prefixe . '-autre-field" hidden style="display: none;">'
        . '<label for="' . $prefixe . '-autre">' . prefaEscape($libelle)
        . ' <span class="required-mark" aria-hidden="true">*</span></label>'
        . '<input id="' . $prefixe . '-autre" name="' . $nom . '" type="text" maxlength="100"'
        . ' placeholder="' . prefaEscape($invite) . '" autocomplete="off">'
        . '</div>';
}
