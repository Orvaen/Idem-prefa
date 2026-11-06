<?php
/**
 * La décision du chef sur un avancement : ce que l'atelier annonce, et de quoi le trancher.
 *
 * Le même bloc sert partout où l'on valide — la fiche d'une demande, le volet « Plans &
 * affectations », l'écran « Soudures ». Chaque endroit l'écrivait à sa façon, et il avait
 * fini par avoir trois dessins pour un seul objet ; il n'en a plus qu'un.
 *
 * Refait le 6 novembre 2026 dans la grammaire des volets : « c'est une toute autre DA ». Il
 * était une carte grise dans le volet, avec ses chiffres en grand, une barre et un tableau
 * à en-têtes. C'est maintenant une section comme « Mes travaux » ou « Étapes du plan » : un
 * titre et la pastille « À valider », des lignes libellé / valeur, les contrôles en boutons
 * côte à côte — ceux des étapes —, et le pied, « Refuser » encadré et « Valider » plein.
 *
 * Le formulaire n'a pas changé de nature : « .atelier-action » et son « data-endpoint », les
 * champs cachés, les contrôles (soudureControlesRendu()) et le message que atelier.js écrit
 * en retour.
 */

require_once __DIR__ . '/atelier.php';
require_once __DIR__ . '/soudure.php';
require_once __DIR__ . '/signature.php';

/**
 * Le bloc : son titre et « À valider » ; le travail, qui l'annonce et quand ; ce que
 * « Valider » change — « 2 soudures sur 69 · 0 % → 3 % » — ; la note de l'atelier ; puis ce
 * qui tranche.
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

    // Ce que « Valider » change : du validé, sourd, au déclaré, de la couleur de l'attente.
    // La flèche se lit à l'œil ; les lecteurs d'écran lisent la phrase.
    $avancement = '<span class="decision-changement" aria-hidden="true">'
        . ($compte !== null ? '<span>' . prefaEscape($compte) . '</span><span class="decision-sep">·</span>' : '')
        . '<span class="decision-avant">' . $valide . ' %</span><span class="decision-fleche">→</span>'
        . '<strong class="decision-apres">' . $declare . ' %</strong></span>'
        . '<span class="sr-only">' . prefaEscape(($compte !== null ? $compte . ' : ' : '')
            . 'validé ' . $valide . ' %, après validation ' . $declare . ' %') . '</span>';

    $lignes = [];
    if (!empty($decision['contexte'])) $lignes[] = ['Travail', prefaEscape((string) $decision['contexte'])];
    if ($qui !== '') $lignes[] = ['Déclaré par', prefaEscape($qui)];
    $lignes[] = ['Avancement', $avancement];
    if (!empty($decision['commentaire'])) {
        $lignes[] = ['Note de l’atelier', '<span class="decision-note">' . prefaEscape((string) $decision['commentaire']) . '</span>'];
    }

    $html = '<section class="decision" aria-label="' . prefaEscape($decision['titre'] . ', à valider') . '">'
        . '<div class="decision-entete">'
        . '<h3 class="decision-titre">' . prefaEscape($decision['titre']) . '</h3>'
        . '<span class="decision-etat">À valider</span>'
        . '</div>'
        . '<dl class="mes-plans-proprietes decision-proprietes">';
    foreach ($lignes as [$libelle, $valeur]) {
        $html .= '<div><dt>' . $libelle . '</dt><dd>' . $valeur . '</dd></div>';
    }
    return $html . '</dl>' . $suite . '</section>';
}

/**
 * Ce qui tranche : les champs cachés, les contrôles des soudures couvertes, et le pied — ce
 * que « Valider » signera, puis les boutons et le message qui leur répond.
 *
 * Le pied reste en vue pendant qu'on coche (atelier.css, « position: sticky ») : sur vingt
 * soudures, « Valider » partait sous le bas de l'écran.
 *
 * Refuser n'existe que pour une déclaration : des soudures relevées que personne n'a
 * annoncées n'ont rien à refuser, on les valide ou on les laisse.
 *
 * Pas de visa sans signature (includes/atelier/signature.php) : à qui n'en a pas, le pied le
 * dit à la place du visa, avec « Tracer ma signature », et « Valider » reste éteint tant
 * qu'elle n'est pas tracée — parametres.js le rallume alors, sans recharger. « Refuser » ne
 * vise rien : il reste à portée.
 *
 * @param array{champs: array<string, int|string>, cordons: list<array<string, mixed>>,
 *              visa: array{annonce: string, manquante: bool}, valider: string, valider_aide: string,
 *              refuser?: bool} $formulaire
 *        « visa » : ce que « Valider » visera — signatureVisa()
 */
function avancementDecisionFormulaire(array $formulaire): string
{
    $refuser = $formulaire['refuser'] ?? false;
    $html = '<form class="atelier-action decision-formulaire" data-endpoint="update_atelier.php">'
        . '<input type="hidden" name="csrf" value="' . prefaEscape(Session::jeton()) . '">';
    foreach ($formulaire['champs'] as $nom => $valeur) {
        $html .= '<input type="hidden" name="' . prefaEscape($nom) . '" value="' . prefaEscape((string) $valeur) . '">';
    }
    // La signature n'est demandée que s'il y a des soudures à viser.
    $sansSignature = $formulaire['cordons'] && $formulaire['visa']['manquante'];
    $html .= soudureControlesRendu($formulaire['cordons'])
        . '<div class="decision-pied">';
    if ($sansSignature) {
        $html .= '<div class="decision-signature" data-signature-requise>'
            . '<p><strong>Il vous faut une signature pour valider.</strong> Tracez-la une fois : elle visera les soudures que vous validez.</p>'
            . '<button type="button" class="decision-signer" data-signature-tracer>' . signatureIcone('refaire') . 'Tracer ma signature</button>'
            . '</div>';
    } elseif ($formulaire['cordons']) {
        // Ce que « Valider » signera, au-dessus de lui.
        $html .= '<p class="decision-visa">' . $formulaire['visa']['annonce'] . '</p>';
    }
    $html .= '<div class="decision-boutons">';
    if ($refuser) {
        $html .= '<button type="submit" name="decision" value="refuser" class="decision-refuser"'
            . ' title="L’avancement ne change pas ; la personne affectée pourra déclarer autre chose.">Refuser</button>';
    }
    return $html . '<button type="submit"' . ($refuser ? ' name="decision" value="valider"' : '') . ' class="decision-valider"'
        . ' title="' . prefaEscape($formulaire['valider_aide']) . '"' . ($sansSignature ? ' disabled data-attend-signature' : '') . '>'
        . prefaEscape($formulaire['valider']) . '</button>'
        . '<span class="atelier-message" role="status" aria-live="polite"></span>'
        . '</div></div></form>';
}
