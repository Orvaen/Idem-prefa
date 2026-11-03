<?php

require_once __DIR__ . '/../demande/prefa_attachments.php';

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
    $stmt = $db->prepare("SELECT id, cle_document, pages, numeros_ligne FROM element_atelier_document
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
            // Le numéro de ligne que porte chaque feuille, relevé au dépôt lui aussi.
            // Les clés sont des numéros de page.
            'numeros_ligne' => planDocumentNumeros($doc['numeros_ligne'] ?? null),
        ];
    }
    return $documents;
}

/**
 * Les plans finaux que l'on peut sortir de chaque demande : ses plans, et leurs feuilles.
 *
 * Une demande n'a pas un plan final mais un par feuille ISO, puisque chacune reçoit ses
 * coulées et sa liste soudure (download_plan_final.php). Le menu d'une ligne de la liste
 * les propose tous, et il lui faut toutes les demandes affichées d'un coup : une requête
 * pour la page, pas une par ligne.
 *
 * « posee » dit si l'atelier a mis quelque chose sur la feuille : une coulée placée, ou un
 * cordon numéroté. C'est une approximation faite pour le menu — le plan final n'écrit que
 * les cordons validés, et relire chaque PDF pour le savoir coûterait trop cher sur une
 * liste. Le téléchargement reste juge : s'il n'a rien à écrire, il le dit.
 *
 * @param list<int> $demandeIds
 * @return array<int, list<array{id:int, reference:string,
 *                                feuilles:list<array{id:int, nom:string, pages:int, posee:bool}>}>>
 */
function planFinalParDemandes(PDO $db, array $demandeIds): array
{
    if (!$demandeIds) return [];

    $marqueurs = implode(',', array_fill(0, count($demandeIds), '?'));
    $stmt = $db->prepare("SELECT e.id_demande, e.id AS id_element, e.reference, doc.id, doc.cle_document, doc.pages,
            EXISTS (SELECT 1 FROM plan_nomenclature n WHERE n.id_element = e.id AND n.id_document = doc.id
                        AND n.numero_coulee <> '' AND n.pos_x IS NOT NULL)
            OR EXISTS (SELECT 1 FROM plan_soudure s WHERE s.id_element = e.id AND s.id_document = doc.id
                        AND s.numero <> '') AS posee
        FROM element_atelier e
        JOIN element_atelier_document doc ON doc.id_element = e.id AND doc.type_document = 'plan'
        WHERE e.id_demande IN ($marqueurs)
        ORDER BY e.reference, e.id, doc.id");
    $stmt->execute(array_values($demandeIds));

    $parDemande = [];
    foreach ($stmt->fetchAll() as $ligne) {
        $demande = (int) $ligne['id_demande'];
        $element = (int) $ligne['id_element'];
        $parDemande[$demande][$element] ??= ['id' => $element, 'reference' => (string) $ligne['reference'], 'feuilles' => []];
        $parDemande[$demande][$element]['feuilles'][] = [
            'id' => (int) $ligne['id'],
            'nom' => preg_replace('/^[a-f0-9]{32}-/', '', (string) $ligne['cle_document']),
            'pages' => max(1, (int) $ligne['pages']),
            'posee' => (bool) $ligne['posee'],
        ];
    }
    return array_map('array_values', $parDemande);
}

/**
 * Les numéros de ligne tels qu'ils sortent de la base.
 *
 * La colonne est à NULL pour un plan déposé avant qu'on les relève, et pour celui dont le
 * cartouche n'a rien donné : le sommaire retombe alors sur le numéro de page.
 *
 * @return array<int, string>
 */
function planDocumentNumeros($brut): array
{
    $numeros = is_string($brut) ? json_decode($brut, true) : null;
    if (!is_array($numeros)) return [];
    $propres = [];
    foreach ($numeros as $page => $numero) {
        if ((int) $page > 0 && is_string($numero) && $numero !== '') $propres[(int) $page] = $numero;
    }
    return $propres;
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

/**
 * Le nombre de pages d'un PDF, lu par pdfinfo ; 0 s'il est illisible.
 *
 * Retenu au moment où le plan est rattaché à un élément — qu'il soit déposé dans le volet
 * ou choisi parmi les documents de la demande — pour que chaque feuille d'un cahier ait sa
 * liste soudure.
 */
function planDocumentCompterPages(string $chemin): int
{
    $infos = [];
    exec('pdfinfo ' . escapeshellarg($chemin) . ' 2>/dev/null', $infos);
    return preg_match('/^Pages:\s+(\d+)/m', implode("\n", $infos), $trouve) ? (int) $trouve[1] : 0;
}

/**
 * Lance la lecture de toutes les feuilles d'un plan, sans attendre qu'elle finisse.
 *
 * La réponse part tout de suite ; la liste soudure de chaque page est gardée à mesure
 * (outils/lire_feuilles.php). Sans cela le total des cordons prévus ne comptait que les
 * pages déjà ouvertes. Relancer pendant une lecture ne fait rien : le script se retire si
 * un autre tient déjà ce plan.
 */
function planDocumentLireEnFond(int $elementId): void
{
    $php = is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
    $script = dirname(__DIR__, 2) . '/outils/lire_feuilles.php';
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $elementId . ' > /dev/null 2>&1 &');
}

