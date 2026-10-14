<?php
/**
 * Les éléments d'atelier d'une demande : les plans qu'on y a ouverts, et leur avancement.
 *
 * Un élément est une feuille de travail — un plan ISO — rattachée à une demande validée.
 * Il porte des étapes, chacune faite ou non, et des documents.
 *
 * atelierDocumentAllowed() est la porte : elle dit si cette personne a le droit d'ouvrir ce
 * fichier-là. Le personnel atelier ne voit que les éléments qui lui sont affectés, et c'est
 * ici que la règle est écrite, pas dans chaque action de téléchargement.
 */

function atelierLocalDateTime(string $value, string $format = 'd/m/Y à H:i'): string
{
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Paris'))->format($format);
}

function atelierSteps(): array
{
    return ['debit' => 'Débit', 'decoupage' => 'Découpage', 'pliage' => 'Pliage', 'pointage' => 'Pointage', 'soudage' => 'Soudage', 'passivation' => 'Passivation', 'autre' => 'Autre'];
}

function atelierElementSteps(PDO $db, int $elementId): array
{
    $stmt = $db->prepare('SELECT statuts, revision FROM element_atelier_etapes WHERE id_element = ?');
    $stmt->execute([$elementId]);
    $row = $stmt->fetch();
    return ['statuts' => $row ? json_decode($row['statuts'], true) : [], 'revision' => $row ? (int) $row['revision'] : 0];
}

function atelierDocumentAllowed(PDO $db, int $userId, int $requestId, string $kind, string $key): bool
{
    $stmt = $db->prepare('SELECT 1 FROM affectation_atelier a
        JOIN element_atelier e ON e.id = a.id_element
        JOIN element_atelier_document doc ON doc.id_element = e.id
        JOIN demande_prefabrication d ON d.id = e.id_demande
        WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2
        AND e.id_demande = ? AND doc.type_document = ? AND doc.cle_document = ? LIMIT 1');
    $stmt->execute([$userId, $requestId, $kind, $key]);
    return (bool) $stmt->fetchColumn();
}

function atelierElements(PDO $db, int $requestId): array
{
    $stmt = $db->prepare('SELECT * FROM element_atelier WHERE id_demande = ? ORDER BY reference, id');
    $stmt->execute([$requestId]);
    $elements = $stmt->fetchAll();
    foreach ($elements as &$element) {
        $stmt = $db->prepare("SELECT a.*, TRIM(CONCAT(u.prenom, ' ', u.name)) AS utilisateur_nom,
            (SELECT COALESCE(SUM(p.duree_minutes), 0) FROM pointage_atelier p WHERE p.id_affectation = a.id) AS minutes
            FROM affectation_atelier a JOIN Utilisateur u ON u.id = a.id_utilisateur
            WHERE a.id_element = ? ORDER BY a.actif DESC, a.date_affectation DESC, a.id DESC");
        $stmt->execute([$element['id']]);
        $element['affectations'] = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT * FROM element_atelier_document WHERE id_element = ? ORDER BY id');
        $stmt->execute([$element['id']]);
        $element['documents'] = $stmt->fetchAll();
    }
    unset($element);
    return $elements;
}

/** The caller checks workshop rights and locks the validated request in a transaction. */
function atelierChangeElement(PDO $db, int $requestId, int $elementId, string $operation, mixed $reference = '', mixed $description = ''): void
{
    if (!$db->inTransaction()) throw new LogicException('Une transaction est nécessaire pour modifier un plan.');
    if (!in_array($operation, ['update_element', 'delete_element'], true)) throw new InvalidArgumentException('Action sur le plan invalide.');
    $find = $db->prepare('SELECT id FROM element_atelier WHERE id = ? AND id_demande = ? FOR UPDATE');
    $find->execute([$elementId, $requestId]);
    if (!$find->fetchColumn()) throw new InvalidArgumentException('Plan introuvable dans cette demande.');
    if ($operation === 'delete_element') {
        // Related assignments and tracking cascade; the request attachments remain intact.
        $db->prepare('DELETE FROM element_atelier WHERE id = ? AND id_demande = ?')->execute([$elementId, $requestId]);
        return;
    }
    if (!is_string($reference) || !is_string($description)) throw new InvalidArgumentException('Saisissez un nom et une description valides.');
    $reference = trim($reference);
    $description = trim($description);
    if ($reference === '' || mb_strlen($reference) > 80 || $description === '' || mb_strlen($description) > 180) {
        throw new InvalidArgumentException('Le nom est obligatoire (80 caractères maximum), ainsi que la description (180 caractères maximum).');
    }
    $save = $db->prepare('UPDATE element_atelier SET reference = ?, libelle = ? WHERE id = ? AND id_demande = ?');
    $save->execute([$reference, $description, $elementId, $requestId]);
}

function atelierDocumentName(PDO $db, int $requestId, string $kind, string $key): ?string
{
    if ($kind === 'plan') {
        if (!preg_match('/^[a-f0-9]{32}-[A-Za-z0-9._-]+$/', $key)) return null;
        if (!is_file(prefaAttachmentDirectory($requestId) . '/' . $key)) return null;
        return substr($key, 33);
    }
    if (!in_array($kind, ['qmos', 'dmos'], true) || !ctype_digit($key)) return null;
    $stmt = $db->prepare('SELECT nom FROM demande_' . $kind . '_documents WHERE id_demande = ? AND id = ?');
    $stmt->execute([$requestId, $key]);
    return $stmt->fetchColumn() ?: null;
}
