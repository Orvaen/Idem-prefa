<?php
// La refonte des PV, d'après le cahier des charges :
//
// 1. « PV de fin de fabrication et de conformité de la préfa » est un seul PV. Les PV
//    « conformite » existants sont repris dans « fin_fabrication » de la même demande :
//    documents déplacés (lignes et fichiers), commentaires mis bout à bout, état fusionné.
//    Rien n'est perdu ; la valeur « conformite » reste dans l'ENUM pour ne rien casser.
// 2. Les photos de la préfa ont leur place : une catégorie « photo » sur les documents.
//
// Relançable sans effet de bord : une demande déjà fusionnée n'a plus de PV « conformite ».
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../../src';
require_once $root . '/db.php';
require_once $root . '/dashboard/includes/demande/pv.php';
$db = new MyPDO($root . '/my_setting.ini');

// ---------------------------------------------------------------- 2. la catégorie photo

$colonne = $db->query("SHOW COLUMNS FROM pv_document LIKE 'categorie'")->fetch();
if (!$colonne) {
    $db->exec("ALTER TABLE pv_document
        ADD COLUMN categorie ENUM('document', 'photo') NOT NULL DEFAULT 'document' AFTER nom");
    echo "Colonne pv_document.categorie ajoutée.\n";
}

// ---------------------------------------------------------------- 1. la fusion

/** L'état du PV fusionné : fait seulement si rien ne reste à faire des deux côtés. */
function pvEtatFusionne(string $a, string $b): string
{
    if ($a === 'a_faire' || $b === 'a_faire') return 'a_faire';
    if ($a === 'sans_objet' && $b === 'sans_objet') return 'sans_objet';
    return 'fait';
}

$conformites = $db->query("SELECT * FROM pv_prefabrication WHERE type = 'conformite'")->fetchAll();
$fusionnees = 0;

foreach ($conformites as $conformite) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM pv_prefabrication WHERE id_demande = ? AND type = 'fin_fabrication' FOR UPDATE");
        $stmt->execute([$conformite['id_demande']]);
        $fin = $stmt->fetch();

        if (!$fin) {
            // Pas de PV de fin de fabrication : celui de conformité le devient, tel quel.
            $db->prepare("UPDATE pv_prefabrication SET type = 'fin_fabrication', revision = revision + 1 WHERE id = ?")
                ->execute([$conformite['id']]);
            $db->commit();
            $fusionnees++;
            continue;
        }

        $commentaires = array_filter([
            trim((string) $fin['commentaire']),
            trim((string) $conformite['commentaire']) !== '' ? 'Conformité : ' . trim((string) $conformite['commentaire']) : '',
        ]);
        $date = max((string) $fin['date_pv'], (string) $conformite['date_pv']) ?: null;

        $db->prepare('UPDATE pv_prefabrication
            SET statut = ?, date_pv = ?, commentaire = ?, revision = revision + 1 WHERE id = ?')
            ->execute([
                pvEtatFusionne($fin['statut'], $conformite['statut']),
                $date,
                $commentaires ? mb_substr(implode("\n", $commentaires), 0, 1000) : null,
                $fin['id'],
            ]);

        // Les documents changent de PV, donc de dossier. Les lignes d'abord, dans la
        // transaction ; les fichiers après, une fois la base engagée : un déplacement sur
        // disque ne se défait pas, une transaction si.
        $docs = $db->prepare('SELECT * FROM pv_document WHERE id_pv = ?');
        $docs->execute([$conformite['id']]);
        $aDeplacer = $docs->fetchAll();
        $db->prepare('UPDATE pv_document SET id_pv = ? WHERE id_pv = ?')->execute([$fin['id'], $conformite['id']]);
        $db->prepare('DELETE FROM pv_prefabrication WHERE id = ?')->execute([$conformite['id']]);
        $db->commit();

        $source = pvDocumentDirectory((int) $conformite['id']);
        $cible = pvDocumentDirectory((int) $fin['id']);
        foreach ($aDeplacer as $doc) {
            if (!is_file($source . '/' . $doc['cle'])) continue;
            if (!is_dir($cible)) @mkdir($cible, 0775, true);
            if (!@rename($source . '/' . $doc['cle'], $cible . '/' . $doc['cle'])) {
                fwrite(STDERR, "À déplacer à la main : $source/{$doc['cle']} vers $cible/\n");
            }
        }
        @rmdir($source);
        $fusionnees++;
    } catch (Throwable $erreur) {
        if ($db->inTransaction()) $db->rollBack();
        fwrite(STDERR, 'Demande ' . $conformite['id_demande'] . ' : ' . $erreur->getMessage() . "\n");
        exit(1);
    }
}

echo $fusionnees
    ? "$fusionnees PV de conformité repris dans la fin de fabrication.\n"
    : "Aucun PV de conformité à reprendre.\n";
