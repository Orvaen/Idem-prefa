<?php
// Feuille de plan intelligent d'un plan ISO, en PDF. Le personnel atelier y a accès
// pour les plans qui lui sont affectés : c'est sa feuille de travail.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/plan_document.php';
require_once __DIR__ . '/../includes/lecture/plan_iso_pdf.php';
require_once __DIR__ . '/../includes/nomenclature.php';
require_once __DIR__ . '/../includes/soudure.php';
require_once __DIR__ . '/../includes/atelier.php';

$elementId = filter_var($_GET['plan'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Plan invalide.');

// La feuille ISO visée. Un élément en porte plusieurs, chacune avec sa nomenclature et sa
// liste soudure : écrire sans préciser laquelle toucherait la mauvaise.
$plan = planDocumentChoisi($db, $elementId, planDocumentDemande($_GET));
if (!$plan) prefaError(404, 'Aucun plan rattaché à cet élément.');
$documentId = $plan['id'];

// « Dessiné par » est le demandeur, « Approuvé par » l'administrateur qui a validé :
// les deux sont déjà portés par la demande, rien n'est à ressaisir sur le plan.
$stmt = $db->prepare("SELECT e.id, e.id_demande, e.reference, e.libelle, d.nom_affaire, d.idUsers,
        d.date_validation,
        TRIM(CONCAT(dem.prenom, ' ', dem.name)) AS demandeur,
        TRIM(CONCAT(val.prenom, ' ', val.name)) AS valideur
    FROM element_atelier e
    JOIN demande_prefabrication d ON d.id = e.id_demande
    LEFT JOIN Utilisateur dem ON dem.id = d.idUsers
    LEFT JOIN Utilisateur val ON val.id = d.valide_par
    WHERE e.id = ?");
$stmt->execute([$elementId]);
$plan = $stmt->fetch();
if (!$plan) prefaError(404, 'Plan introuvable.');

if (!$canViewAllPrefa) {
    if ($isWorkshopPersonnel) {
        // L'ouvrier n'obtient que les plans sur lesquels il est affecté et actif.
        $acces = $db->prepare('SELECT 1 FROM affectation_atelier
            WHERE id_element = ? AND id_utilisateur = ? AND actif = 1 LIMIT 1');
        $acces->execute([$elementId, $actor['id']]);
    } else {
        $acces = $db->prepare('SELECT 1 FROM demande_prefabrication WHERE id = ? AND idUsers = ?');
        $acces->execute([$plan['id_demande'], $actor['id']]);
    }
    if (!$acces->fetchColumn()) prefaError(404, 'Plan introuvable ou inaccessible.');
}

// Le trigramme du monteur n'existe pas encore en base : le nom de la personne affectée
// au montage en tient lieu tant que la colonne n'est pas ajoutée.
$monteur = $db->prepare("SELECT TRIM(CONCAT(u.prenom, ' ', u.name)) FROM affectation_atelier a
    JOIN Utilisateur u ON u.id = a.id_utilisateur
    WHERE a.id_element = ? AND a.type_affectation = 'montage' AND a.actif = 1 LIMIT 1");
$monteur->execute([$elementId]);

$entete = [
    'projet' => (string) ($plan['nom_affaire'] ?? ''),
    'ligne' => (string) $plan['reference'],
    'dessine_par' => (string) ($plan['demandeur'] ?? ''),
    'approuve_par' => (string) ($plan['valideur'] ?? ''),
    // La date du cartouche est celle de la validation : c'est elle qui fait foi.
    'date' => $plan['date_validation'] ? atelierLocalDateTime($plan['date_validation'], 'd/m/y') : '',
    'tuyauteur' => (string) ($monteur->fetchColumn() ?: ''),
];

$nomenclature = array_map(static fn (array $ligne): array => [
    'n' => (string) $ligne['rang'],
    'qte' => (string) $ligne['quantite'],
    'dn' => (string) $ligne['dn'],
    'ep' => (string) $ligne['epaisseur'],
    'description' => (string) $ligne['description'],
    'coulee' => (string) $ligne['numero_coulee'],
// La nomenclature de la première page seulement, comme la liste soudure plus bas : cette
// feuille-ci est dessinée par l'application, avec un seul tableau.
], nomenclatureLignes($db, $elementId, $documentId, 1));

$lignesSoudure = array_map(static fn (array $ligne): array => [
    'n' => (string) $ligne['numero'],
    'soudeur' => (string) ($ligne['soudeur_nom'] ?? ''),
    'date' => $ligne['date_soudure'] ? date('d/m/y', strtotime((string) $ligne['date_soudure'])) : '',
    'type' => (string) $ligne['type_soudure'],
    'rt' => (string) $ligne['rt'],
    'vi' => (string) $ligne['vi'],
    'pt' => (string) $ligne['pt'],
    'visa' => (string) ($ligne['visa_nom'] ?? ''),
// La liste de la première page seulement : cette feuille-ci est dessinée par
// l'application, avec un seul tableau soudure. Pour un cahier déposé d'un bloc, il reste à
// décider comment y montrer la page de chaque cordon — le plan final, lui, écrit la liste
// de chaque page sur sa page.
], soudureLignes($db, $elementId, $documentId, 1));

$pdf = planIsoPdf($entete, $nomenclature, $lignesSoudure);

$nom = 'Plan-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $plan['reference']) . '.pdf';
header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $nom . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
