<?php

require_once __DIR__ . '/../../modeles/autoload.php';

/**
 * Quelles demandes une personne a le droit de voir.
 *
 * La règle vivait dans le partial qui dessine la liste. L'API devait la reprendre, et deux
 * copies d'une règle d'accès finissent toujours par diverger — l'une se corrige, l'autre
 * laisse passer. Elle est donc écrite ici, et les deux l'appellent.
 *
 * Le fragment se colle derrière les jointures de demande_prefabrication, aliasée d, et ses
 * marqueurs sont préfixés « vis_ » pour ne jamais heurter ceux des filtres de l'écran.
 *
 * « condition » est le même fragment sans son WHERE : le flux doit le combiner par un OU
 * avec sa propre règle, et un WHERE au milieu d'une parenthèse ne se recolle pas.
 *
 * @return array{where: string, condition: string, params: array<string, mixed>}
 */
function prefaDemandesVisibles(Utilisateur $utilisateur): array
{
    $params = [];

    if ($utilisateur->voitToutesLesDemandes()) {
        $condition = '1=1';
    } else {
        // Chargé d'affaire et personnel d'atelier : seulement les demandes à leur nom.
        $condition = 'd.idUsers = :vis_demandeur';
        $params['vis_demandeur'] = $utilisateur->id;
    }

    // L'atelier ne travaille que sur du validé : une demande encore en attente peut
    // changer, et ce qu'on aurait commencé dessus serait à refaire.
    if ($utilisateur->est(Role::PersonnelAtelier)) {
        $condition .= ' AND d.id_statut = 2';
    }

    // Le chef d'atelier voit tout ce qui est validé, plus ce qu'il a lui-même déposé —
    // sans quoi sa propre demande disparaîtrait de sa vue tant qu'elle attend validation.
    if ($utilisateur->est(Role::ChefDAtelier)) {
        $condition .= ' AND (d.id_statut = 2 OR d.idUsers = :vis_chef)';
        $params['vis_chef'] = $utilisateur->id;
    }

    return ['where' => ' WHERE ' . $condition, 'condition' => $condition, 'params' => $params];
}
