<?php

/**
 * La bascule entre les comptes connectés sur un même poste.
 *
 * La liste elle-même est tenue par le modèle Session : ici on ne fait que la compléter du
 * compte actif et l'habiller des informations à jour de chacun.
 */
require_once __DIR__ . '/../../modeles/autoload.php';

/** Comptes mémorisés dans la session, hors compte actif. */
function comptesStored(): array
{
    return Session::comptes();
}

/** Identifiants des comptes connectés sur cet appareil, compte actif compris. */
function comptesSession(): array
{
    $comptes = Session::comptes();
    $actif = Session::identifiant();
    if ($actif !== null && !in_array($actif, $comptes, true)) $comptes[] = $actif;
    return array_values(array_unique($comptes));
}

function comptesRemember(string $identifiant): void
{
    if ($identifiant === '') return;
    Session::retenirCompte($identifiant);
}

function comptesForget(string $identifiant): void
{
    Session::oublierCompte($identifiant);
}

/**
 * Comptes connectés avec leurs informations à jour, dans leur ordre d’ajout.
 * Un compte supprimé entre-temps est oublié plutôt qu’affiché.
 */
function comptesList(PDO $db): array
{
    $comptes = comptesSession();
    if (!$comptes) return [];
    $placeholders = implode(',', array_fill(0, count($comptes), '?'));
    $stmt = $db->prepare("
        SELECT u.identifiant, u.name, u.prenom, u.id_role, r.name AS role_name
        FROM Utilisateur u
        JOIN role r ON r.id = u.id_role
        WHERE u.identifiant IN ($placeholders)
    ");
    $stmt->execute($comptes);
    $connus = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $connus[$row['identifiant']] = $row;
    // Un compte supprimé entre-temps sort de la liste plutôt que d'y rester sans fiche.
    $vivants = array_values(array_intersect($comptes, array_keys($connus)));
    Session::limiterComptes($vivants);
    return array_map(fn($identifiant) => $connus[$identifiant], $vivants);
}
