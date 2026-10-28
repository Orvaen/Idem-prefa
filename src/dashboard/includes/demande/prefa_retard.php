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
