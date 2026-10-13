<?php

/**
 * Les modèles se chargent tout seuls : une classe nommée Role vit dans Role.php.
 *
 * Cinq lignes plutôt qu'un require_once par classe en tête de chaque fichier — c'est cette
 * liste-là qui pourrissait, et qu'on oubliait de compléter en ajoutant un modèle.
 */
spl_autoload_register(static function (string $classe): void {
    $chemin = __DIR__ . '/' . $classe . '.php';
    if (is_file($chemin)) require_once $chemin;
});
