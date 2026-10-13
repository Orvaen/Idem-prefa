<?php

/**
 * Les classes de l'API se chargent toutes seules, comme celles des modèles : une classe
 * Demandes vit dans ressources/Demandes.php, une classe Requete dans Requete.php.
 */
require_once __DIR__ . '/../modeles/autoload.php';

spl_autoload_register(static function (string $classe): void {
    foreach ([__DIR__ . '/' . $classe . '.php', __DIR__ . '/ressources/' . $classe . '.php'] as $chemin) {
        if (is_file($chemin)) {
            require_once $chemin;
            return;
        }
    }
});
