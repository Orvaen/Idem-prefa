<?php

/**
 * Le contexte commun à toutes les pages et actions du tableau de bord : qui est connecté,
 * sur quelle base, et les quelques fonctions que tout le monde appelle.
 *
 * Il ne lit plus $_SESSION lui-même. La session est un modèle — src/modeles/Session.php —
 * et l'identité est un objet, Utilisateur, qui porte ses droits. Ce qui suit n'en est que
 * la traduction dans les anciens noms.
 */
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../modeles/autoload.php';
require_once __DIR__ . '/demande/prefa_references.php';

Session::demarrer();
// Pas d'en-tête si la réponse est déjà commencée : dashboard.php rend la page demandée
// chez lui, et il a posé le même en-tête bien avant d'écrire sa première balise.
if (!headers_sent()) header('Cache-Control: no-store');
date_default_timezone_set('Europe/Paris');

function prefaError(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$db = new MyPDO(__DIR__ . '/../../my_setting.ini');

/** La personne connectée. C'est elle qu'on interroge pour tout droit. */
$utilisateur = Session::utilisateur($db);
// Session vide, ou compte supprimé depuis la connexion : dans les deux cas il faut
// repasser par l'écran d'ouverture.
if (!$utilisateur) prefaError(401, 'Veuillez vous reconnecter.');

// Le jeton est posé dès maintenant : les formulaires l'écrivent dans leur balisage, et
// certains sont rendus avant qu'une action ne l'ait demandé.
Session::jeton();

// ---------------------------------------------------------------------------------------
// Les anciens noms, dérivés de l'objet. Cinquante fichiers les lisent encore ; ils
// disparaîtront au fur et à mesure que chacun passera à $utilisateur, sans qu'il faille
// tout reprendre d'un coup.
// ---------------------------------------------------------------------------------------
$actor = ['id' => $utilisateur->id, 'id_role' => $utilisateur->idRole, 'role_name' => $utilisateur->roleEnBase];

$isAdmin = $utilisateur->est(Role::ChargeDeProduction);
$isWorkshopChief = $utilisateur->est(Role::ChefDAtelier);
$isWorkshopPersonnel = $utilisateur->est(Role::PersonnelAtelier);
$isRequester = $utilisateur->est(Role::ChargeDAffaire);

$canViewAllPrefa = $utilisateur->voitToutesLesDemandes();
$canEditPrefa = $utilisateur->deposeDesDemandes();
$canManageWorkshop = $utilisateur->gereLAtelier();
$canFillPv = $utilisateur->remplitLesPv();
$canViewPv = $utilisateur->consulteLesPv();
$pvLimiteAuDemandeur = $utilisateur->pvLimitesASesDemandes();

function prefaPost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') prefaError(405, 'Méthode non autorisée.');
    if (!Session::jetonValide($_POST['csrf'] ?? null)) {
        prefaError(403, 'Rechargez la page puis réessayez.');
    }
}

function prefaEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function prefaFormatDate($value, bool $withTime = false): string
{
    if (!$value) return 'Non renseignée';
    $date = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone('Europe/Paris'))
        ->format($withTime ? 'd/m/Y à H:i' : 'd/m/Y');
}
