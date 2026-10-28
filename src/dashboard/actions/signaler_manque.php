<?php
// Le soudeur signale un manque de matériel sur un plan qui lui est confié.
//
// C'est le plan qui situe le manque — le soudeur sait sur quoi il travaille, pas le numéro
// de la demande —, et c'est son affectation qui lui en donne le droit. Le manque se range
// sur la demande : c'est elle qui porte le bon de commande, et c'est là que le chef le
// cherchera.
require_once __DIR__ . '/../includes/prefa_context.php';
require_once __DIR__ . '/../includes/atelier/soudure_atelier.php';
require_once __DIR__ . '/../includes/materiel/manques.php';
require_once __DIR__ . '/../includes/vue/manques.php';

prefaPost();
if (!$isWorkshopPersonnel) prefaError(403, 'Le signalement de matériel est réservé au personnel atelier.');

$elementId = filter_var($_POST['element'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$elementId) prefaError(400, 'Choisissez le plan sur lequel il manque du matériel.');
if (!soudureAtelierAffectation($db, $elementId, (int) $actor['id'])) prefaError(403, 'Ce plan ne vous est pas affecté.');

$stmt = $db->prepare('SELECT e.id_demande, e.reference, d.nom_affaire FROM element_atelier e
    JOIN demande_prefabrication d ON d.id = e.id_demande WHERE e.id = ?');
$stmt->execute([$elementId]);
$plan = $stmt->fetch();
if (!$plan) prefaError(404, 'Plan introuvable.');

try {
    manqueSignaler($db, (int) $plan['id_demande'], (int) $actor['id'], $_POST);
} catch (DomainException $refus) {
    prefaError(422, $refus->getMessage());
} catch (PDOException $erreur) {
    Journal::erreur('Signalement de manque', $erreur);
    prefaError(500, 'Signalement impossible. Réessayez.');
}

// Après l'écriture, jamais avant : une annonce pour un manque qui n'a pas été enregistré
// ferait chercher au chef ce qui n'existe pas.
manquesPrevenirEncadrement($db, (int) $plan['id_demande'], (int) $actor['id'], [
    'affaire' => (string) ($plan['nom_affaire'] ?? ''),
    'article' => trim((string) $_POST['designation']) . ' × '
        . manqueQuantite(str_replace(',', '.', (string) $_POST['quantite'])) . ' ' . (trim((string) ($_POST['unite'] ?? '')) ?: 'u'),
    'plan' => (string) $plan['reference'],
    'auteur' => trim($utilisateur->prenom . ' ' . $utilisateur->nom),
]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => 'Manque signalé : le chef d’atelier est prévenu.',
    // Le tableau « Matériel signalé » à jour : l'écran le remplace sans se recharger.
    'html' => manquesSoudeurRendu(manquesDuSoudeur($db, (int) $actor['id'])),
]);
