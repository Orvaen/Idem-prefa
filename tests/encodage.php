<?php
// No text in the database may be double-encoded UTF-8 ("ValidÃ©e" instead of "Validée").
//
// It happens on import, not in the application: the mysql client inside the db container
// has no locale, so it opens its connection in latin1 unless told otherwise. UTF-8 bytes
// sent through it are read as latin1 and stored re-encoded — é (C3A9) becomes Ã© (C383C2A9).
// The run scripts pass --default-character-set=utf8mb4 for that reason; this check is what
// notices the day someone imports a dump by hand without it.
//
// Run from stdin in the PHP container. Reads only.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
$db = new MyPDO($root . '/my_setting.ini');

function checkEncodage(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// C383 is "Ã" and C382 is "Â": the two bytes that lead a double-encoded accent. No French
// text holds them on its own — "Ã" alone does not appear in the trade's vocabulary.
$colonnes = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND DATA_TYPE IN ('char', 'varchar', 'text', 'mediumtext', 'longtext', 'tinytext')
    ORDER BY TABLE_NAME, COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);

checkEncodage($colonnes !== [], 'No text column found: is the schema installed?');

$abimees = [];
foreach ($colonnes as $colonne) {
    $table = $colonne['TABLE_NAME'];
    $nom = $colonne['COLUMN_NAME'];
    $combien = (int) $db->query("SELECT COUNT(*) FROM `$table`
        WHERE HEX(`$nom`) LIKE '%C383%' OR HEX(`$nom`) LIKE '%C382%'")->fetchColumn();
    if ($combien > 0) {
        $exemple = (string) $db->query("SELECT `$nom` FROM `$table`
            WHERE HEX(`$nom`) LIKE '%C383%' OR HEX(`$nom`) LIKE '%C382%' LIMIT 1")->fetchColumn();
        $abimees[] = $table . '.' . $nom . ' (' . $combien . ', ex. « ' . $exemple . ' »)';
    }
}

checkEncodage($abimees === [], 'Double-encoded text found in ' . implode(', ', $abimees)
    . '. Repair with: UPDATE <table> SET <col> = CONVERT(BINARY(CONVERT(<col> USING latin1)) USING utf8mb4)'
    . " WHERE HEX(<col>) LIKE '%C383%' OR HEX(<col>) LIKE '%C382%';");

echo 'Text encoding across ' . count($colonnes) . ' columns: OK' . PHP_EOL;
