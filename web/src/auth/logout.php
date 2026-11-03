<?php
// Fermer la session : vider les variables, retirer le cookie, détruire le stockage. Le
// détail vit dans le modèle, parce qu'oublier l'une des trois étapes laisse une session
// à moitié ouverte.
require_once __DIR__ . '/../modeles/autoload.php';

Session::fermer();

// La racine du site, c'est-à-dire l'écran de connexion.
header('Location: ../');
