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

/** Les trois travaux d'un plan, ceux qui portent un avancement, tels que l'atelier les nomme. */
function atelierTravaux(): array
{
    return ['plan' => 'Plan / ISO', 'montage' => 'Montage', 'soudage' => 'Suivi soudage'];
}

function atelierSteps(): array
{
    return ['debit' => 'Débit', 'decoupage' => 'Découpage', 'pliage' => 'Pliage', 'pointage' => 'Pointage', 'soudage' => 'Soudage', 'passivation' => 'Passivation', 'autre' => 'Autre'];
}

/**
 * Les métiers de l'atelier, dans l'ordre où le plan passe entre leurs mains : le nom de
 * celui qui le tient, tel que l'atelier le dit.
 *
 * Le chef d'atelier les donne plan par plan à n'importe qui du personnel (table
 * affectation_metier) : une même personne peut en tenir plusieurs sur un plan, et un métier
 * revenir à plusieurs personnes. Ils s'ajoutent aux trois travaux d'un plan sans les
 * remplacer — un métier ne porte pas d'avancement — et donnent accès au plan comme eux
 * (atelierPlansDeSql()).
 *
 * @return array<string, string> clé en base => le métier
 */
function atelierMetiers(): array
{
    return ['debit' => 'Débiteur', 'assemblage' => 'Assembleur', 'pointage' => 'Pointeur', 'soudage' => 'Soudeur', 'passivation' => 'Passiveur'];
}

/**
 * Les métiers sont-ils en base ? Pas tant que la migration 2026-11-02_metiers n'est pas
 * passée sur ce serveur : l'écran s'en passe alors, au lieu de tomber sur une table absente.
 */
function metiersEnBase(PDO $db): bool
{
    static $presente = null;
    return $presente ??= (bool) $db->query("SHOW TABLES LIKE 'affectation_metier'")->fetch();
}

/**
 * Les plans d'une personne, à poser après « IN » : ceux où elle a un travail actif, et ceux
 * où elle tient un métier. C'est la règle de tout ce que le personnel atelier voit et fait
 * — ses écrans, ses fichiers, ses soudures —, écrite une seule fois.
 *
 * Le fragment attend l'identifiant de la personne deux fois, avec ou sans métiers en base :
 * les appelants n'ont pas à le savoir.
 */
function atelierPlansDeSql(PDO $db): string
{
    return metiersEnBase($db)
        ? '(SELECT id_element FROM affectation_atelier WHERE id_utilisateur = ? AND actif = 1
            UNION SELECT id_element FROM affectation_metier WHERE id_utilisateur = ?)'
        : '(SELECT id_element FROM affectation_atelier WHERE id_utilisateur = ? AND actif = 1 AND id_utilisateur = ?)';
}

/**
 * Les métiers d'un plan : qui tient lequel.
 *
 * @return array<string, list<array{id:int, nom:string}>> métier => ses personnes, par nom
 */
function atelierMetiersDuPlan(PDO $db, int $elementId): array
{
    $metiers = array_fill_keys(array_keys(atelierMetiers()), []);
    if (!metiersEnBase($db)) return $metiers;
    $stmt = $db->prepare("SELECT m.metier, u.id, TRIM(CONCAT(u.prenom, ' ', u.name)) AS nom
        FROM affectation_metier m JOIN Utilisateur u ON u.id = m.id_utilisateur
        WHERE m.id_element = ? ORDER BY u.prenom, u.name");
    $stmt->execute([$elementId]);
    foreach ($stmt->fetchAll() as $ligne) {
        $metiers[$ligne['metier']][] = ['id' => (int) $ligne['id'], 'nom' => (string) $ligne['nom']];
    }
    return $metiers;
}

/**
 * Les métiers d'une personne, plan par plan, dans l'ordre de atelierMetiers().
 *
 * @return array<int, list<string>> plan => ses clés de métier
 */
function atelierMetiersDe(PDO $db, int $userId): array
{
    if (!metiersEnBase($db)) return [];
    $stmt = $db->prepare('SELECT id_element, metier FROM affectation_metier WHERE id_utilisateur = ?');
    $stmt->execute([$userId]);
    $ordre = array_flip(array_keys(atelierMetiers()));
    $parPlan = [];
    foreach ($stmt->fetchAll() as $ligne) $parPlan[(int) $ligne['id_element']][] = (string) $ligne['metier'];
    foreach ($parPlan as &$metiers) usort($metiers, static fn (string $a, string $b): int => $ordre[$a] <=> $ordre[$b]);
    unset($metiers);
    return $parPlan;
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
    $stmt = $db->prepare('SELECT 1 FROM element_atelier e
        JOIN element_atelier_document doc ON doc.id_element = e.id
        JOIN demande_prefabrication d ON d.id = e.id_demande
        WHERE e.id IN ' . atelierPlansDeSql($db) . ' AND d.id_statut = 2
        AND e.id_demande = ? AND doc.type_document = ? AND doc.cle_document = ? LIMIT 1');
    $stmt->execute([$userId, $userId, $requestId, $kind, $key]);
    return (bool) $stmt->fetchColumn();
}

function atelierElements(PDO $db, int $requestId): array
{
    $stmt = $db->prepare('SELECT * FROM element_atelier WHERE id_demande = ? ORDER BY reference, id');
    $stmt->execute([$requestId]);
    $elements = $stmt->fetchAll();
    foreach ($elements as &$element) {
        $stmt = $db->prepare("SELECT a.*, TRIM(CONCAT(u.prenom, ' ', u.name)) AS utilisateur_nom
            FROM affectation_atelier a JOIN Utilisateur u ON u.id = a.id_utilisateur
            WHERE a.id_element = ? ORDER BY a.actif DESC, a.date_affectation DESC, a.id DESC");
        $stmt->execute([$element['id']]);
        $element['affectations'] = $stmt->fetchAll();
        $element['metiers'] = atelierMetiersDuPlan($db, (int) $element['id']);
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
    // La description est facultative : le nom suffit à reconnaître un plan.
    if ($reference === '' || mb_strlen($reference) > 80 || mb_strlen($description) > 180) {
        throw new InvalidArgumentException('Le nom est obligatoire (80 caractères maximum) ; la description, facultative, tient en 180.');
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
