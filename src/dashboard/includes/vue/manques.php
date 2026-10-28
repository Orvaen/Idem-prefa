<?php

require_once __DIR__ . '/../materiel/manques.php';
require_once __DIR__ . '/../demande/prefa_references.php';

/**
 * Le tableau des manques qu'une personne a signalés, tel que « Mes soudures » l'affiche.
 *
 * Rendu ici, et non dans la page : l'action qui enregistre un signalement renvoie le même
 * tableau, et l'écran le remplace sans se recharger. Deux copies du balisage auraient fini
 * par ne plus dire la même chose.
 *
 * @param list<array<string, mixed>> $manques ce que rend manquesDuSoudeur()
 */
function manquesSoudeurRendu(array $manques): string
{
    if (!$manques) {
        return '<p class="soudures-materiel-vide">Aucun manque signalé. Si une pièce vous manque pour souder,'
            . ' utilisez « Signaler un manque » : le chef d’atelier est prévenu tout de suite.</p>';
    }

    $motifs = manquesMotifs();
    $etats = manquesEtats();
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $html = '<div class="prefa-table-scroll" role="region" aria-label="Matériel signalé" tabindex="0">'
        . '<table class="prefa-table soudures-materiel-table"><thead><tr>'
        . '<th scope="col">Article</th>'
        . '<th scope="col" class="prefa-nombre">Qté</th>'
        . '<th scope="col">Motif</th>'
        . '<th scope="col">Demande</th>'
        . '<th scope="col">Signalé le</th>'
        . '<th scope="col">État</th>'
        . '</tr></thead><tbody>';
    foreach ($manques as $manque) {
        $etat = $etats[$manque['statut']] ?? $etats['nouvelle'];
        // Qui l'a traité et quand : c'est la réponse qu'on attend, au survol de la puce.
        $titre = $manque['statut'] === 'nouvelle'
            ? 'Pas encore vu par le chef d’atelier'
            : ($manque['statut'] === 'vue' ? 'Vu' : 'Traité')
                . ($manque['traitant'] ? ' par ' . $manque['traitant'] : '')
                . ($manque['date_traitement'] ? ' le ' . date('d/m/Y', strtotime((string) $manque['date_traitement'])) : '');
        $html .= '<tr>'
            . '<td class="soudures-materiel-article"><strong>' . $e($manque['designation']) . '</strong>'
            . ($manque['commentaire'] ? '<span>' . $e($manque['commentaire']) . '</span>' : '') . '</td>'
            . '<td class="prefa-nombre">' . $e(manqueQuantite($manque['quantite']) . ' ' . $manque['unite']) . '</td>'
            . '<td>' . $e($motifs[$manque['motif']] ?? $manque['motif']) . '</td>'
            . '<td class="prefa-reference">' . $e(prefaReference((int) $manque['id_demande'])) . '</td>'
            . '<td>' . $e(date('d/m/Y', strtotime((string) $manque['date_creation']))) . '</td>'
            . '<td><span class="pv-puce ' . $etat['puce'] . '" title="' . $e($titre) . '">' . $e($etat['libelle']) . '</span></td>'
            . '</tr>';
    }
    return $html . '</tbody></table></div>';
}
