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

// Journal ne vit pas ici — il est chargé avant tout le monde, par auto_prepend_file, pour
// attraper aussi les erreurs des fichiers qui ne demandent aucun modèle. Mais Session, User
// et Evenement y notent ce qu'ils font : on le réclame plutôt que de compter dessus.
// « php -r » est le seul cas où PHP saute le fichier d'en-tête.
require_once __DIR__ . '/../journal.php';
