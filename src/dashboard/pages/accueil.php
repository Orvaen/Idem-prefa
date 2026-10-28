<?php

/**
 * L'accueil, rechargé par la barre.
 *
 * Le premier affichage vient de dashboard.php, qui a déjà tout en main ; celui-ci sert
 * quand on y revient depuis un autre écran. Les deux rendent la même vue.
 */
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/demande/accueil.php';
require_once __DIR__ . '/../includes/demande/affaires_nouvelles.php';

$prenom = $utilisateur->prenom;
$name = $utilisateur->nom;
$tuiles = accueilTuiles($db, $utilisateur);
$activite = accueilActivite($db, $utilisateur);
// Montrées ici, les affaires nouvelles sont lues : la pastille de l'entrée repart à zéro.
$affairesNouvelles = affairesNouvelles($db, $utilisateur->id);
affairesNouvellesMarquerVues($db, $utilisateur->id, $affairesNouvelles);

require __DIR__ . '/../includes/vue/accueil_vue.php';
