<?php
// Lit un certificat matière en ligne de commande et affiche ce qui a été reconnu.
// Sert à essayer la lecture sur un nouveau modèle de certificat avant de s'y fier.
//
//   php scripts/ccpu_lire.php chemin/vers/certificat.pdf
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/dashboard/includes/ccpu.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage : php ccpu_lire.php certificat.pdf\n");
    exit(2);
}

try {
    $debut = microtime(true);
    $lu = ccpuLire($argv[1]);
    $duree = microtime(true) - $debut;
} catch (RuntimeException $erreur) {
    fwrite(STDERR, $erreur->getMessage() . "\n");
    exit(1);
}

printf("Pages       : %d lue(s) sur %d — lecture %s, %.1f s\n", $lu['pages_lues'], $lu['pages'], $lu['lecture'], $duree);
printf("Certificat  : %s\n", $lu['certificat'] ?? 'non reconnu');
printf("Dimensions  : %s\n", $lu['dimensions'] ? implode(' ; ', $lu['dimensions']) : 'non reconnues');
printf("Nuances     : %s\n", $lu['nuances'] ? implode(' ; ', $lu['nuances']) : 'non reconnues');
echo "Coulées     : ", $lu['coulees'] ? '' : 'aucune colonne de coulée repérée', "\n";
foreach ($lu['coulees'] as $coulee) {
    printf("  %-12s page %d · confiance %3d %% · %s\n", $coulee['numero'], $coulee['page'], $coulee['confiance'],
        $coulee['confirmee'] ? 'confirmée par une seconde lecture' : 'lue une seule fois');
}
