<?php
require_once __DIR__ . '/../atelier/plan_document.php';
require_once __DIR__ . '/plan_viewer.php';

/**
 * La fenêtre du plan d'un élément, telle que « Mes soudures » l'ouvre : c'est là que le
 * soudeur renseigne ses soudures, en cliquant les lignes de la liste soudure imprimée.
 *
 * Rendue à la demande (actions/soudures_lecteur.php), à la première feuille qu'on ouvre de
 * ce plan, et non avec la liste : l'écran montre tous les plans de la personne, et une
 * fenêtre pèse vingt-deux kilo-octets et une lecture de nomenclature par page — à
 * construire pour des plans qu'on n'ouvrira peut-être pas.
 *
 * @return string le bloc, caché, qui porte la fenêtre ; vide si le plan n'a pas de feuille
 */
function souduresLecteurRendu(PDO $db, int $elementId): string
{
    $documents = planDocuments($db, $elementId);
    if (!$documents) return '';

    $fenetre = planViewerRendu($elementId, array_map(static function (array $document) use ($db, $elementId): array {
        $reperes = [];
        for ($page = 1; $page <= max(1, (int) $document['pages']); $page++) {
            $reperes[$page] = nomenclatureLignes($db, $elementId, $document['id'], $page);
        }
        return $document + ['reperes' => $reperes, 'empreintes' => []];
    }, $documents), [], false, false);

    // Le jeton est sur le bloc : soudures.js le lit pour lire et enregistrer chaque feuille.
    return '<div class="soudures-lecteur" data-element="' . $elementId . '" data-csrf="'
        . prefaEscape(Session::jeton()) . '" hidden>' . $fenetre . '</div>';
}
