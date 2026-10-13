<?php
// Le plan final : le plan du bureau d'études avec, écrits dessus, les numéros de coulée
// et la liste des soudures. C'est le document qui part au client ou au contrôle.
//
// Il n'est pas redessiné : le PDF d'origine est repris tel quel et reçoit un calque, donc
// le dessin reste vectoriel et les cotes restent celles du bureau d'études.
//
// Un cahier ISO déposé d'un bloc ressort entier, page par page : chaque isométrique porte
// sa propre liste soudure imprimée, donc chacune reçoit la sienne. Les numéros de coulée,
// eux, sont posés sur la première page — c'est celle que le lecteur montre.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/prefa_attachments.php';
require_once __DIR__ . '/../includes/plan_document.php';
require_once __DIR__ . '/../includes/atelier.php';
require_once __DIR__ . '/../includes/plan_lignes.php';
require_once __DIR__ . '/../includes/plan_final.php';
require_once __DIR__ . '/../includes/nomenclature.php';

$elementId = filter_var($_GET['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

// La feuille ISO visée : un élément en porte plusieurs, et chacune sort annotée à part.
$plan = planDocumentChoisi($db, $elementId, planDocumentDemande($_GET));
if (!$plan) prefaError(404, 'Aucun plan rattaché à cet élément.');

$reference = $db->prepare('SELECT reference FROM element_atelier WHERE id = ?');
$reference->execute([$elementId]);
$plan['reference'] = (string) $reference->fetchColumn();

$requestId = (int) $plan['id_demande'];
$cle = (string) $plan['cle_document'];

// Mêmes droits que l'aperçu du plan : l'atelier obtient les plans sur lesquels il est
// affecté, l'encadrement voit tout, le demandeur ses demandes.
if ($isWorkshopPersonnel) {
    if (!atelierDocumentAllowed($db, (int) $actor['id'], $requestId, 'plan', $cle)) {
        prefaError(404, 'Plan introuvable ou inaccessible.');
    }
} elseif (!$canViewAllPrefa) {
    $acces = $db->prepare('SELECT 1 FROM demande_prefabrication WHERE id = ? AND idUsers = ?');
    $acces->execute([$requestId, $actor['id']]);
    if (!$acces->fetchColumn()) prefaError(404, 'Plan introuvable ou inaccessible.');
}

$source = $plan['chemin'];
if (!is_file($source)) prefaError(404, 'Plan introuvable.');

$pdf = @file_get_contents($source);
if ($pdf === false) prefaError(404, 'Plan introuvable.');

// Les numéros de coulée vont là où le chef les a posés : leurs positions sont enregistrées
// avec eux, en fractions de la page, et valent donc à n'importe quelle échelle.
$couleesDeLaPage = static function (PDO $db, int $elementId, int $documentId, int $page): array {
    $textes = [];
    foreach (nomenclatureLignes($db, $elementId, $documentId, $page) as $repere) {
        $coulee = trim((string) $repere['numero_coulee']);
        if ($coulee === '' || $repere['pos_x'] === null) continue;
        $textes[] = [
            'texte' => $coulee,
            'x' => (float) $repere['pos_x'],
            'y' => (float) $repere['pos_y'],
            'w' => (float) ($repere['pos_w'] ?: 0.058),
            'h' => (float) ($repere['pos_h'] ?: 0.009),
        ];
    }
    return $textes;
};

// Page après page : la géométrie se lit toujours sur le fichier d'origine — nos révisions
// ne déplacent rien — et le calque s'ajoute au PDF qu'on tient, qui grossit d'une révision
// par page annotée. Chaque page reçoit ses coulées et sa liste. Une page sans rien à
// écrire est laissée exactement telle quelle.
//
// Tout est préparé avant d'écrire quoi que ce soit : un cahier dont l'atelier n'a rien
// rempli ne doit pas sortir en quinze révisions qui ne porteraient que des numéros de page.
$cahier = (int) $plan['pages'] > 1;
$aEcrire = [];
$contenu = 0;
try {
    for ($numero = 1; $numero <= (int) $plan['pages']; $numero++) {
        $geometrie = planFinalGeometriePage($source, $numero);
        if (!$geometrie) continue;

        // La liste soudure se relit sur le plan au moment du téléchargement : elle est
        // calculée par le même code que le lecteur, donc le PDF montre ce que l'écran
        // montrait. Chaque page a la sienne, imprimée sur l'isométrique.
        $analyse = planLignesAnalyse($source, planLignesCache($requestId, $cle, $numero), $numero);
        $soudures = $analyse['soudures'] ?? null;
        $liste = planLignesSoudures($db, $elementId, $plan['id'], $soudures, $numero);
        $dessin = planLignesSoudureDessin($soudures, $liste['valeurs']);

        $textes = array_merge($couleesDeLaPage($db, $elementId, (int) $plan['id'], $numero), $dessin['textes']);
        if ($textes || $dessin['traits']) $contenu++;

        // Le numéro de page prend la place de l'intitulé « Numéro de ligne » du cartouche.
        // Un cahier ISO ne numérote pas ses feuilles : sans cela, un tirage papier qu'on
        // mélange ne se remet plus dans l'ordre. La valeur au-dessus continue de dire quel
        // numéro de ligne porte la feuille — c'est le mot qu'on remplace, pas lui.
        $caches = [];
        $cartouche = $analyse['cartouche'] ?? null;
        if ($cahier && $cartouche) {
            $caches[] = $cartouche['cache'];
            $textes[] = [
                'texte' => 'Page ' . $numero . ' / ' . (int) $plan['pages'],
                'x' => $cartouche['x'], 'y' => $cartouche['y'],
                'w' => $cartouche['w'], 'h' => $cartouche['h'],
                'police' => PLAN_FINAL_POLICE_CARTOUCHE,
            ];
        }

        if (!$textes && !$dessin['traits']) continue;
        $aEcrire[$numero] = ['textes' => $textes, 'traits' => $dessin['traits'],
                             'caches' => $caches, 'geometrie' => $geometrie];
    }

    // Le numéro de page seul ne fait pas un plan final : c'est un repère, pas le travail
    // de l'atelier. prefaError s'arrête ici même, avant d'avoir composé quoi que ce soit.
    if (!$contenu) {
        prefaError(409, 'Rien à écrire sur ce plan : aucune coulée posée ni soudure numérotée.');
    }

    foreach ($aEcrire as $numero => $quoi) {
        // Les traits ajoutés ont l'épaisseur de ceux du plan : un point à 100 dpi, la
        // résolution à laquelle les tableaux ont été lus.
        $epaisseur = max(0.3, min($quoi['geometrie']['largeur'], $quoi['geometrie']['hauteur']) / 1169 * 1.0);
        $pdf = planFinalComposer($pdf, planFinalFlux($quoi['textes'], $quoi['traits'],
            $quoi['geometrie'], $epaisseur, $quoi['caches']), $numero);
    }
} catch (Throwable $erreur) {
    // Le message dit ce qui bloque : un PDF d'un autre gabarit n'est pas une panne, c'est
    // un fichier qu'on ne sait pas annoter, et l'atelier doit pouvoir le comprendre.
    prefaError(422, 'Plan final impossible : ' . $erreur->getMessage());
}

$nom = 'Plan-final-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $plan['reference']) . '.pdf';
header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $nom . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
