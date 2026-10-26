<?php

require_once __DIR__ . '/../../../modeles/autoload.php';

/**
 * Quelles demandes une personne a le droit de voir.
 *
 * La règle elle-même a déménagé dans le modèle — Demande::clauseVisible() — avec tout ce
 * qui touche à la table des demandes. Cette fonction reste parce que les écrans et le flux
 * l'appellent sous cette forme, et qu'une règle d'accès ne se recopie pas : on la renvoie.
 *
 * « where » est le fragment prêt à coller derrière les jointures de
 * demande_prefabrication, aliasée d. « condition » est le même sans son WHERE : le flux
 * doit le combiner par un OU avec sa propre règle, et un WHERE au milieu d'une parenthèse
 * ne se recolle pas.
 *
 * @return array{where: string, condition: string, params: array<string, mixed>}
 */
function prefaDemandesVisibles(Utilisateur $utilisateur): array
{
    ['condition' => $condition, 'params' => $params] = Demande::clauseVisible($utilisateur);

    return ['where' => ' WHERE ' . $condition, 'condition' => $condition, 'params' => $params];
}
