<?php

require_once __DIR__ . '/prefa_attachments.php';

/**
 * Les fichiers ISO d'un élément d'atelier, et celui sur lequel on travaille.
 *
 * Un élément — « un plan » dans le vocabulaire de l'atelier — porte une ligne de
 * tuyauterie, et une ligne tient rarement sur une seule feuille. Le chef dépose donc
 * plusieurs ISO, chacun avec sa nomenclature et sa liste soudure imprimées. Tout ce qui
 * se pose dessus est rattaché au fichier, pas à l'élément.
 */

/**
 * Les ISO d'un élément, dans l'ordre où ils ont été rattachés.
 *
 * @return list<array{id:int, cle_document:string, nom:string, pages:int}>
 */
function planDocuments(PDO $db, int $elementId): array
{
    $stmt = $db->prepare("SELECT id, cle_document, pages FROM element_atelier_document
        WHERE id_element = ? AND type_document = 'plan' ORDER BY id");
    $stmt->execute([$elementId]);

    $documents = [];
    foreach ($stmt->fetchAll() as $doc) {
        $cle = (string) $doc['cle_document'];
        $documents[] = [
            'id' => (int) $doc['id'],
            'cle_document' => $cle,
            // La clé porte un préfixe technique de 32 caractères et un tiret : ce qui
            // suit est le nom que la personne a déposé, et c'est lui qu'on montre.
            'nom' => preg_replace('/^[a-f0-9]{32}-/', '', $cle),
            // Un cahier ISO déposé d'un bloc porte une liste soudure par page : le
            // nombre de pages est retenu au dépôt, pour n'avoir pas à relire le PDF
            // chaque fois qu'on affiche le volet.
            'pages' => max(1, (int) $doc['pages']),
        ];
    }
    return $documents;
}

/**
 * Le fichier sur lequel porte la demande, ou le premier de l'élément à défaut.
 *
 * Rendre le premier quand rien n'est précisé garde leur sens aux adresses déjà en
 * circulation, du temps où un élément n'avait qu'un plan.
 *
 * @return array{id:int, cle_document:string, pages:int, id_demande:int, chemin:string}|null
 */
function planDocumentChoisi(PDO $db, int $elementId, ?int $documentId): ?array
{
    $conditions = "doc.id_element = ? AND doc.type_document = 'plan'";
    $parametres = [$elementId];
    if ($documentId) {
        $conditions .= ' AND doc.id = ?';
        $parametres[] = $documentId;
    }

    $stmt = $db->prepare("SELECT doc.id, doc.cle_document, doc.pages, e.id_demande
        FROM element_atelier_document doc
        JOIN element_atelier e ON e.id = doc.id_element
        WHERE $conditions ORDER BY doc.id LIMIT 1");
    $stmt->execute($parametres);
    $doc = $stmt->fetch();
    if (!$doc) return null;

    // La clé vient de la base, mais elle sert à bâtir un chemin : on la vérifie quand même.
    $cle = (string) $doc['cle_document'];
    if (!preg_match('/^[a-f0-9]{32}-[A-Za-z0-9._-]+$/', $cle)) return null;

    return [
        'id' => (int) $doc['id'],
        'cle_document' => $cle,
        'pages' => max(1, (int) $doc['pages']),
        'id_demande' => (int) $doc['id_demande'],
        'chemin' => prefaAttachmentDirectory((int) $doc['id_demande']) . '/' . $cle,
    ];
}

/** L'identifiant de plan reçu d'une requête, ou null s'il n'y en a pas. */
function planDocumentDemande(array $source): ?int
{
    $id = filter_var($source['document'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id ?: null;
}
