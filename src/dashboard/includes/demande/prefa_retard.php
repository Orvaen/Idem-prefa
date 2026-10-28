<?php

/**
 * Le retard d'une demande, en jours — 0 quand elle n'en a pas.
 *
 * Une demande est en retard quand sa fin prévue est passée ET qu'elle court encore.
 * « Validée » ne veut pas dire « faite » : c'est l'accord de l'encadrement, la demande
 * s'ouvre alors à l'atelier. Elle cesse de courir dans deux cas :
 * - refusée : elle ne sera pas faite ;
 * - fabriquée : le PV « Fin de fabrication et conformité » est renseigné « Fait ». La
 *   pièce est sortie de l'atelier, une échéance dépassée ensuite n'a plus de sens.
 *
 * Une seule règle pour la ligne et pour le compte « N en retard » de l'en-tête : écrites
 * deux fois, elles finissaient par ne plus dire la même chose.
 *
 * @param array<string, mixed> $demande  porte date_fin_prevue, id_statut et fabrication_finie
 */
function prefaJoursDeRetard(array $demande): int
{
    if (empty($demande['date_fin_prevue'])) return 0;
    if ((int) ($demande['id_statut'] ?? 0) === 3) return 0;
    if (!empty($demande['fabrication_finie'])) return 0;
    $fin = new DateTimeImmutable((string) $demande['date_fin_prevue']);
    $aujourdhui = new DateTimeImmutable('today');
    return $fin < $aujourdhui ? $fin->diff($aujourdhui)->days : 0;
}

/**
 * Pourquoi la demande court encore : ce qui manque pour qu'elle cesse d'être en retard.
 * Sans cette raison, « 13 j de retard » sur une demande « Validée » se lisait comme une
 * erreur — validée semblait vouloir dire terminée.
 *
 * @return array{court: string, long: string}
 */
function prefaRaisonDuRetard(array $demande): array
{
    $fin = prefaFormatDate($demande['date_fin_prevue'] ?? null);
    if ((int) ($demande['id_statut'] ?? 0) === 1) {
        return [
            'court' => 'pas encore validée',
            'long' => 'Fin prévue le ' . $fin . ' dépassée, et la demande attend encore sa validation.',
        ];
    }
    return [
        'court' => 'fabrication non terminée',
        'long' => 'Fin prévue le ' . $fin . ' dépassée, et la fabrication n’est pas déclarée terminée. '
            . 'Le retard s’arrête quand le PV « Fin de fabrication et conformité » passe à « Fait ».',
    ];
}
