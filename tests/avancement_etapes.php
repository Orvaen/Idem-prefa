<?php
// L'avancement calculé : sur les étapes du plan, ou sur les cordons pour le suivi soudage.
// Lancé dans le conteneur PHP. Tout ce que le test écrit est annulé à la fin.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/db.php';
require_once $root . '/dashboard/includes/atelier/atelier.php';
require_once $root . '/dashboard/includes/atelier/avancement.php';
$db = new MyPDO($root . '/my_setting.ini');

function verifier(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// ----- Le comptage, sans la base -----

$vide = avancementEtapes([]);
verifier($vide === ['faites' => 0, 'applicables' => 6], '« Autre » non renseignée vaut RAS : six étapes à faire');

$compte = avancementEtapes(['debit' => 'OK', 'decoupage' => 'Non OK', 'pliage' => '', 'pointage' => 'OK',
    'soudage' => '', 'passivation' => 'RAS', 'autre' => 'RAS']);
verifier($compte === ['faites' => 2, 'applicables' => 5], 'OK compte, Non OK et vide restent à faire, RAS sort du total');

$rien = avancementEtapes(array_fill_keys(array_keys(atelierSteps()), 'RAS'));
verifier($rien['applicables'] === 0, 'Tout en RAS : aucune étape ne s’applique');

verifier(avancementSource('soudage', 12) === 'cordons', 'Le suivi soudage d’un plan avec liste se mesure aux cordons');
verifier(avancementSource('soudage', 0) === 'etapes', 'Sans liste, le suivi soudage se mesure aux étapes');
verifier(avancementSource('montage', 12) === 'etapes', 'Le montage se mesure aux étapes, liste ou non');

verifier(avancementPhrase(['avancement' => 67, 'etapes' => 4, 'etapes_total' => 6]) === '4 étapes sur 6 — 67 %', 'Phrase des étapes');
verifier(avancementPhrase(['avancement' => 17, 'etapes' => 1, 'etapes_total' => 6]) === '1 étape sur 6 — 17 %', 'Singulier');
verifier(avancementPhrase(['avancement' => 58, 'soudures' => 7, 'soudures_total' => 12]) === '7 soudures sur 12 — 58 %', 'Phrase des cordons inchangée');
verifier(avancementPhrase(['avancement' => 30]) === '30 %', 'Une ancienne déclaration se lit telle quelle');

// ----- Le recalcul, sur la base -----

$demande = (int) $db->query('SELECT id FROM demande_prefabrication WHERE id_statut = 2 LIMIT 1')->fetchColumn();
$personnes = $db->query('SELECT id FROM Utilisateur ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (!$demande || count($personnes) < 2) throw new RuntimeException('Il faut une demande validée et deux comptes pour ce test');
[$premier, $second] = array_map('intval', $personnes);

$db->beginTransaction();
try {
    $db->prepare('INSERT INTO element_atelier (id_demande, reference, libelle, cree_par) VALUES (?, ?, ?, ?)')
        ->execute([$demande, 'TEST-' . bin2hex(random_bytes(6)), 'Essai du calcul', $premier]);
    $element = (int) $db->lastInsertId();
    $affecter = $db->prepare('INSERT INTO affectation_atelier (id_element, id_utilisateur, type_affectation, affecte_par, commentaire) VALUES (?, ?, ?, ?, ?)');
    $affecter->execute([$element, $premier, 'montage', $premier, 'Compte rendu du chef']);
    $montage = (int) $db->lastInsertId();
    $affecter->execute([$element, $second, 'soudage', $premier, null]);
    $soudage = (int) $db->lastInsertId();

    $poserEtapes = static function (array $statuts) use ($db, $element, $premier): void {
        $db->prepare('INSERT INTO element_atelier_etapes (id_element, statuts, revision, modifie_par) VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE statuts = VALUES(statuts), revision = revision + 1')
            ->execute([$element, json_encode($statuts), $premier]);
    };
    $attente = static function (int $affectation) use ($db): ?array {
        $stmt = $db->prepare("SELECT * FROM avancement_atelier WHERE id_affectation = ? AND etat = 'propose'");
        $stmt->execute([$affectation]);
        return $stmt->fetch() ?: null;
    };
    $avancement = static function (int $affectation) use ($db): int {
        $stmt = $db->prepare('SELECT avancement FROM affectation_atelier WHERE id = ?');
        $stmt->execute([$affectation]);
        return (int) $stmt->fetchColumn();
    };

    // Trois étapes faites sur six : 50 % pour les deux travaux — le plan n'a pas de liste
    // de soudures, le suivi soudage se mesure lui aussi aux étapes.
    $poserEtapes(['debit' => 'OK', 'decoupage' => 'OK', 'pliage' => 'OK', 'pointage' => '', 'soudage' => '',
        'passivation' => 'Non OK', 'autre' => 'RAS']);
    $calcul = avancementSelonEtapes($db, $element, $premier, false);
    verifier($calcul !== null && $calcul['pourcentage'] === 50 && $calcul['travaux'] === 2, 'Les deux travaux reçoivent 50 %');
    foreach ([$montage, $soudage] as $affectation) {
        $ligne = $attente($affectation);
        verifier($ligne !== null && (int) $ligne['avancement'] === 50, 'Le chiffre part en déclaration');
        verifier($avancement($affectation) === 0, 'Rien ne bouge avant la validation du chef');
        if (avancementEtapesEnBase($db)) {
            verifier((int) $ligne['etapes'] === 3 && (int) $ligne['etapes_total'] === 6, 'La déclaration retient « 3 sur 6 »');
        }
    }

    // Le chef valide le montage : son compte rendu reste, un calcul n'en a pas.
    avancementTrancher($db, $montage, (int) $attente($montage)['id'], $premier, true);
    verifier($avancement($montage) === 50, 'La validation pose le chiffre');
    $commentaire = $db->prepare('SELECT commentaire FROM affectation_atelier WHERE id = ?');
    $commentaire->execute([$montage]);
    verifier($commentaire->fetchColumn() === 'Compte rendu du chef', 'Valider un calcul n’efface pas le compte rendu');

    // Mêmes étapes, enregistrées de nouveau : le montage est déjà à 50 %, rien ne repart.
    avancementSelonEtapes($db, $element, $premier, false);
    verifier($attente($montage) === null, 'Un chiffre déjà reconnu ne repart pas en validation');
    verifier($attente($soudage) !== null, 'L’autre travail garde sa déclaration');

    // Le chef change les étapes lui-même : le chiffre est posé d'office.
    $poserEtapes(['debit' => 'OK', 'decoupage' => 'OK', 'pliage' => 'OK', 'pointage' => 'OK', 'soudage' => 'OK',
        'passivation' => 'RAS', 'autre' => 'RAS']);
    avancementSelonEtapes($db, $element, $premier, true);
    verifier($avancement($montage) === 100 && $avancement($soudage) === 100, 'Le chef pose 100 % d’office');
    verifier($attente($soudage) === null, 'Poser d’office retire la déclaration en attente');

    // Tout en RAS : rien ne s'applique, rien ne se calcule.
    $poserEtapes(array_fill_keys(array_keys(atelierSteps()), 'RAS'));
    verifier(avancementSelonEtapes($db, $element, $premier, false) === null, 'Aucune étape applicable : pas de calcul');

    // Le travail des cordons : le suivi soudage du plan, quel que soit celui qui le tient.
    $cible = avancementAffectationCordons($db, $element);
    verifier($cible !== null && $cible['id'] === $soudage, 'Les cordons font avancer le suivi soudage du plan');
} finally {
    $db->rollBack();
}

echo "Avancement calculé sur les étapes et les cordons : OK\n";
