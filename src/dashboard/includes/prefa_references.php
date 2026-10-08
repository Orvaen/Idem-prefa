<?php

/** Allocate inside the transaction that creates the request. */
function prefaAssignReference(PDO $db, int $id, int $year): string
{
    if (!$db->inTransaction()) throw new LogicException('La numérotation nécessite une transaction.');
    $counter = $db->prepare('INSERT INTO prefa_reference_compteur (annee, dernier_numero) VALUES (?, 1)
        ON DUPLICATE KEY UPDATE dernier_numero = dernier_numero + 1');
    $counter->execute([$year]);
    $find = $db->prepare('SELECT dernier_numero FROM prefa_reference_compteur WHERE annee = ? FOR UPDATE');
    $find->execute([$year]);
    $number = (int) $find->fetchColumn();
    $reference = sprintf('%02d-DP-%04d', $year % 100, $number);
    $save = $db->prepare('INSERT INTO prefa_reference (id_demande, annee, numero, reference) VALUES (?, ?, ?, ?)');
    $save->execute([$id, $year, $number, $reference]);
    return $reference;
}

/**
 * Code seul : « 26-DP-0006 ». Stable, il ne bouge jamais, et c'est lui qui sert de
 * clé dans les colonnes de tableau où le nom du chantier figure déjà à côté.
 *
 * Une seule lecture pour toute la page, historique d'atelier compris.
 */
function prefaReference(int $id): string
{
    return prefaReferences()[$id]['reference']
        ?? throw new RuntimeException('Référence de demande manquante. Appliquez la migration des références.');
}

/**
 * Référence complète : « 26-DP-0006 - Réseau vapeur ». C'est la forme à montrer quand
 * la référence est seule, sans nom d'affaire à proximité. Le nom du chantier n'est pas
 * stocké dans la référence : il peut être renommé, et un code figé mentirait.
 */
function prefaReferenceComplete(int $id): string
{
    $ligne = prefaReferences()[$id] ?? null;
    if (!$ligne) throw new RuntimeException('Référence de demande manquante. Appliquez la migration des références.');
    $chantier = trim((string) $ligne['nom_affaire']);
    return $chantier === '' ? $ligne['reference'] : $ligne['reference'] . ' - ' . $chantier;
}

/** @return array<int, array{reference: string, nom_affaire: ?string}> */
function prefaReferences(): array
{
    global $db;
    static $references = null;
    $references ??= $db->query('SELECT r.id_demande, r.reference, d.nom_affaire
        FROM prefa_reference r LEFT JOIN demande_prefabrication d ON d.id = r.id_demande')
        ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    return $references;
}
