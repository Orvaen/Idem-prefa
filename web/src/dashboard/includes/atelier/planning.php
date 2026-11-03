<?php
/**
 * Le calcul du planning : d'une durée en heures à des bandes sur un calendrier.
 *
 * Une demande occupe des jours, et ces jours se posent sur des lignes — des « couloirs » —
 * de façon que deux demandes qui se chevauchent ne se recouvrent jamais à l'écran. C'est
 * tout ce que fait ce fichier : il ne dessine rien, il place.
 *
 * Les jours ouvrés sont une option et non une règle : l'atelier travaille parfois le samedi,
 * et un planning qui saute le week-end d'office annoncerait des fins de fabrication fausses.
 */

function planningDate(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Paris'));
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function planningPeriod(DateTimeImmutable $start, float $hours, bool $workingDays): array
{
    if (!is_finite($hours) || $hours <= 0) throw new InvalidArgumentException('La durée estimée doit être supérieure à zéro.');
    $days = (int) ceil($hours / 24);
    if ($days > 3650) throw new InvalidArgumentException('La durée dépasse 3 650 jours. Vérifiez les pouces ISO et la cadence.');
    if ($workingDays) {
        while ((int) $start->format('N') > 5) $start = $start->modify('+1 day');
    }
    $end = $start;
    for ($remaining = $days - 1; $remaining > 0;) {
        $end = $end->modify('+1 day');
        if (!$workingDays || (int) $end->format('N') <= 5) $remaining--;
    }
    return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'days' => $days];
}

function planningOccupiedDays(array $request, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $first = planningDate($request['date_debut_planifiee'] ?? null);
    $last = planningDate($request['date_fin_planifiee'] ?? null);
    if (!$first || !$last) return [];
    $first = max($first, $start);
    $last = min($last, $end);
    $days = [];
    for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
        if (empty($request['planning_jours_ouvres']) || (int) $day->format('N') <= 5) $days[] = $day->format('Y-m-d');
    }
    return $days;
}

function planningLanes(array $requests, DateTimeImmutable $start, int $length, bool $workingOnly = false): array
{
    $end = $start->modify('+' . ($length - 1) . ' days');
    $columns = [];
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        if (!$workingOnly || (int) $day->format('N') <= 5) $columns[$day->format('Y-m-d')] = count($columns) + 1;
    }
    usort($requests, static fn($a, $b) => [$a['date_debut_planifiee'], $a['id']] <=> [$b['date_debut_planifiee'], $b['id']]);
    $laneEnds = [];
    $blocks = [];
    $occupancy = [];
    foreach ($requests as $request) {
        $first = planningDate($request['date_debut_planifiee'] ?? null);
        $last = planningDate($request['date_fin_planifiee'] ?? null);
        if (!$first || !$last || $first > $end || $last < $start) continue;
        $left = max($first, $start);
        $right = min($last, $end);
        if ($workingOnly) {
            while ((int) $left->format('N') > 5) $left = $left->modify('+1 day');
            while ((int) $right->format('N') > 5) $right = $right->modify('-1 day');
            if ($left > $right) continue;
        }
        $lane = 0;
        while (isset($laneEnds[$lane]) && $laneEnds[$lane] >= $left) $lane++;
        $laneEnds[$lane] = $right;
        $request['column'] = $columns[$left->format('Y-m-d')];
        $request['span'] = $columns[$right->format('Y-m-d')] - $request['column'] + 1;
        $request['lane'] = $lane + 1;
        $blocks[] = $request;
        foreach (planningOccupiedDays($request, $start, $end) as $day) $occupancy[$day] = ($occupancy[$day] ?? 0) + 1;
    }
    foreach ($blocks as &$block) {
        $block['conflict'] = false;
        foreach (planningOccupiedDays($block, $start, $end) as $day) {
            if (($occupancy[$day] ?? 0) > 1) { $block['conflict'] = true; break; }
        }
    }
    unset($block);
    return [
        'blocks' => $blocks,
        'lanes' => max(1, count($laneEnds)),
        'occupied' => count($occupancy),
        'conflicts' => count(array_filter($occupancy, static fn($count) => $count > 1)),
    ];
}
