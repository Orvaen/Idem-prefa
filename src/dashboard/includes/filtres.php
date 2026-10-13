<?php

/**
 * La barre de filtres : une pastille par critère, qui ouvre sa liste à cocher.
 *
 * Les filtres étaient quatre champs de formulaire à étiquette flottante, hauts de 44 px,
 * tous affichés en permanence même vides — le même gabarit que « Nom » ou « Mot de passe »,
 * alors qu'on ne saisit rien ici, on choisit. Et rien ne disait qu'un filtre était posé.
 *
 * Une pastille porte son intitulé, ce qui est retenu, et une croix pour l'enlever. Elle se
 * teinte quand elle filtre quelque chose : la barre se lit d'un coup d'œil.
 *
 * Chaque choix part dans un champ caché nommé <nom>[] : le rafraîchissement de la liste
 * sérialise le formulaire, il n'a donc rien de particulier à savoir.
 */

/**
 * @param string $nom       la clé envoyée au serveur, « status », « user »…
 * @param string $libelle   ce qu'annonce la pastille
 * @param list<array{id:int|string, libelle:string}> $options
 * @param list<string> $choisis  ce qui est retenu
 * @param array{aucun?:string, chercher?:bool} $reglages
 */
function filtreRendu(string $nom, string $libelle, array $options, array $choisis, array $reglages = []): string
{
    $choisis = array_map('strval', $choisis);
    $actif = $choisis !== [];

    // Ce que la pastille affiche : le choix lui-même quand il n'y en a qu'un — « Validée »
    // se lit mieux que « 1 sélectionné » — et le compte au-delà.
    $resume = 'Tous';
    if (count($choisis) === 1) {
        foreach ($options as $option) {
            if ((string) $option['id'] === $choisis[0]) $resume = (string) $option['libelle'];
        }
    } elseif ($actif) {
        $resume = count($choisis) . ' sélectionnés';
    }

    $html = '<div class="filtre' . ($actif ? ' est-actif' : '') . '" data-filtre="' . prefaEscape($nom) . '">'
        . '<button type="button" class="filtre-declencheur" aria-expanded="false"'
        . ' aria-controls="filtre-' . prefaEscape($nom) . '-volet">'
        . '<span class="filtre-libelle">' . prefaEscape($libelle) . '</span>'
        . ($actif && count($choisis) > 1
            ? '<span class="filtre-compte">' . count($choisis) . '</span>'
            : '')
        . '<span class="filtre-resume">' . prefaEscape($resume) . '</span>'
        . '<svg class="filtre-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . '<path d="m6 9 6 6 6-6" /></svg>'
        . '</button>';

    // La croix n'existe que s'il y a quelque chose à enlever : une croix qui ne fait rien
    // apprend à ne plus la regarder.
    if ($actif) {
        $html .= '<button type="button" class="filtre-effacer"'
            . ' aria-label="Ne plus filtrer par ' . prefaEscape($libelle) . '" title="Enlever ce filtre">'
            . '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>'
            . '</button>';
    }

    $html .= '<div class="filtre-volet" id="filtre-' . prefaEscape($nom) . '-volet" role="group"'
        . ' aria-label="' . prefaEscape($libelle) . '" hidden>';

    // Une liste longue se cherche : au-delà d'une dizaine de choix, on ne balaie plus, on tape.
    if (count($options) > 10) {
        $html .= '<div class="filtre-recherche">'
            . '<input type="text" class="filtre-chercher" autocomplete="off"'
            . ' placeholder="Rechercher…" aria-label="Rechercher dans ' . prefaEscape($libelle) . '">'
            . '</div>';
    }

    $html .= '<div class="filtre-options">';
    foreach ($options as $option) {
        $id = (string) $option['id'];
        $coche = in_array($id, $choisis, true);
        $html .= '<label class="filtre-option">'
            . '<input type="checkbox" name="' . prefaEscape($nom) . '[]" value="' . prefaEscape($id) . '"'
            . ($coche ? ' checked' : '') . '>'
            . '<span class="filtre-case" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"'
            . ' stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4 10-10" /></svg>'
            . '</span>'
            . '<span class="filtre-texte">' . prefaEscape((string) $option['libelle']) . '</span>'
            . '</label>';
    }
    $html .= '</div>';

    $html .= '<p class="filtre-vide" hidden>' . prefaEscape($reglages['aucun'] ?? 'Aucun résultat.') . '</p>'
        . '<div class="filtre-pied">'
        . '<button type="button" class="filtre-tout">Tout sélectionner</button>'
        . '</div>'
        . '</div></div>';

    return $html;
}

/** La barre entière, avec son « tout effacer » quand au moins un filtre est posé. */
function filtresBarre(string $html, bool $unFiltrePose): string
{
    return '<div class="filtres-barre">'
        . $html
        . ($unFiltrePose
            ? '<button type="button" class="filtres-tout-effacer">Tout effacer</button>'
            : '')
        . '</div>';
}
