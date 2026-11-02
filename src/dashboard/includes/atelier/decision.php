<?php
/**
 * La décision du chef sur un avancement : ce que l'atelier annonce, et de quoi le trancher.
 *
 * Le même bloc sert partout où l'on valide — la fiche d'une demande, le volet « Plans &
 * affectations », l'écran « Soudures ». Chaque endroit l'écrivait à sa façon, et il avait
 * fini par avoir trois dessins pour un seul objet ; il n'en a plus qu'un.
 *
 * Le formulaire n'a pas changé de nature : « .atelier-action » et son « data-endpoint », les
 * champs cachés, la liste des contrôles (soudureControlesRendu()) et le message que
 * atelier.js écrit en retour.
 */

require_once __DIR__ . '/atelier.php';
require_once __DIR__ . '/soudure.php';

/**
 * Le bloc : l'état et ce qui est annoncé, par qui et quand ; la mesure, du validé au
 * déclaré ; la note de l'atelier ; puis ce qui tranche.
 *
 * @param array{titre: string, avancement: int, valide: int, compte?: ?string, contexte?: ?string,
 *              qui?: ?string, quand?: ?string, commentaire?: ?string} $decision
 * @param string $suite un ou plusieurs avancementDecisionFormulaire(), ou une note qui dit
 *                      pourquoi on ne peut pas trancher d'ici
 */
function avancementDecisionRendu(array $decision, string $suite): string
{
    $declare = max(0, min(100, (int) $decision['avancement']));
    $valide = max(0, min(100, (int) $decision['valide']));
    $compte = $decision['compte'] ?? null;
    $qui = implode(' · ', array_filter([$decision['qui'] ?? null,
        !empty($decision['quand']) ? atelierLocalDateTime((string) $decision['quand']) : null]));

    $html = '<section class="decision" aria-label="' . prefaEscape($decision['titre'] . ', à valider') . '">'
        . '<header class="decision-entete">'
        . '<span class="decision-etat">À valider</span>'
        . '<div class="decision-identite"><h3 class="decision-titre">' . prefaEscape($decision['titre']) . '</h3>'
        . (!empty($decision['contexte']) ? '<p class="decision-contexte">' . prefaEscape($decision['contexte']) . '</p>' : '')
        . '</div>'
        . ($qui !== '' ? '<p class="decision-qui">' . prefaEscape($qui) . '</p>' : '')
        . '</header>';

    // La barre va du validé au déclaré : la part qui changerait en validant est celle qui
    // attend, de la couleur de l'attente. Un chiffre déclaré sous le validé se lit aussi —
    // la part en attente est alors celle qui serait retirée.
    //
    // Les chiffres et la note ne sont pas des paragraphes : le thème clair peint tout « p »
    // en sourd, et le compte est ce qu'on doit lire en premier.
    $html .= '<div class="decision-mesure">'
        . '<div class="decision-compte">' . prefaEscape($compte ?? $declare . ' %') . '</div>'
        . ($compte !== null ? '<div class="decision-pourcentage">' . $declare . ' %</div>' : '')
        . '<span class="decision-barre" aria-hidden="true" style="--base: ' . min($valide, $declare) . '%; --ecart: ' . abs($declare - $valide) . '%">'
        . '<span class="decision-barre-validee"></span><span class="decision-barre-attente"></span></span>'
        . '<p class="decision-legende"><span class="decision-legende-validee">Validé ' . $valide . ' %</span>'
        . '<span class="decision-legende-attente">Après validation ' . $declare . ' %</span></p>'
        . '</div>';

    if (!empty($decision['commentaire'])) {
        $html .= '<div class="decision-note"><span>Note de l’atelier</span>' . prefaEscape((string) $decision['commentaire']) . '</div>';
    }
    return $html . $suite . '</section>';
}

/**
 * Ce qui tranche : les champs cachés, les contrôles des soudures couvertes, et le pied —
 * le message à gauche, les boutons à droite.
 *
 * Refuser n'existe que pour une déclaration : des soudures relevées que personne n'a
 * annoncées n'ont rien à refuser, on les valide ou on les laisse.
 *
 * @param array{champs: array<string, int|string>, cordons: list<array<string, mixed>>, visa: string,
 *              valider: string, valider_aide: string, refuser?: bool} $formulaire
 */
function avancementDecisionFormulaire(array $formulaire): string
{
    $refuser = $formulaire['refuser'] ?? false;
    $html = '<form class="atelier-action decision-formulaire" data-endpoint="update_atelier.php">'
        . '<input type="hidden" name="csrf" value="' . prefaEscape(Session::jeton()) . '">';
    foreach ($formulaire['champs'] as $nom => $valeur) {
        $html .= '<input type="hidden" name="' . prefaEscape($nom) . '" value="' . prefaEscape((string) $valeur) . '">';
    }
    $html .= soudureControlesRendu($formulaire['cordons'], $formulaire['visa'])
        . '<div class="decision-pied"><span class="atelier-message" role="status" aria-live="polite"></span>';
    if ($refuser) {
        $html .= '<button type="submit" name="decision" value="refuser" class="decision-refuser"'
            . ' title="L’avancement ne change pas ; la personne affectée pourra déclarer autre chose.">Refuser</button>';
    }
    return $html . '<button type="submit"' . ($refuser ? ' name="decision" value="valider"' : '') . ' class="decision-valider"'
        . ' title="' . prefaEscape($formulaire['valider_aide']) . '">' . prefaEscape($formulaire['valider']) . '</button>'
        . '</div></form>';
}
