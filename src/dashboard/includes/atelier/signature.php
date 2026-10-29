<?php

/**
 * Le visa des cordons : la signature de celui qui les valide.
 *
 * Chacun trace la sienne une fois, dans ses paramètres (rubrique Compte). Quand le chef
 * valide l'avancement d'un soudeur, les cordons que la validation couvre sont visés de sa
 * signature : la case VISA du plan la porte, dans la fenêtre du plan comme sur le plan
 * final. Qui n'en a pas tracé vise de son nom, écrit à la main (lecture/ecriture_visa.php).
 *
 * Une signature est gardée telle qu'elle a été posée. La refaire en range une nouvelle ;
 * l'ancienne reste sur les cordons qu'elle a visés — comme sur le papier, changer de
 * signature ne retouche pas ce qui est déjà signé — et s'efface si elle n'en vise aucun.
 *
 * Un visa posé au nom, lui, n'est signé de rien : il prend la première signature que la
 * personne trace, puis la garde comme les autres. Ce sont tous les visas d'avant les
 * signatures — sans quoi la même feuille mêlerait le nom et la signature du même chef.
 *
 * Elle est rangée en traits, pas en image : une suite de points par trait, en hauteurs de
 * signature. Elle se redessine nette à toutes les tailles, dans une case de quelques
 * millimètres comme à l'écran, et dans la couleur de ce qui l'entoure.
 */

/**
 * L'épaisseur du trait, en hauteurs de l'encre : assez pour que le visa se lise imprimé
 * dans une case de quelques millimètres. Même valeur à l'écran (SIGNATURE_TRAIT,
 * plan-viewer.js).
 */
const SIGNATURE_TRAIT = 0.055;

/** Ce qu'un tracé peut peser : bien au-delà d'une signature, bien en deçà d'un abus. */
const SIGNATURE_TRAITS_MAX = 60;
const SIGNATURE_POINTS_MAX = 4000;

/**
 * La base connaît-elle les signatures ? Elles viennent de la migration 2026-10-29_signatures,
 * dont la colonne du cordon est le dernier pas. Tant qu'elle n'est pas passée sur un
 * serveur, le visa reste le nom.
 */
function signaturesEnBase(PDO $db): bool
{
    static $presente = null;
    return $presente ??= (bool) $db->query("SHOW COLUMNS FROM plan_soudure LIKE 'id_signature'")->fetch();
}

/**
 * La signature en cours de quelqu'un, ou null s'il n'en a pas tracé.
 *
 * @return array{id: int, dessin: array{proportion: float, traits: list<list<array{0: float, 1: float}>>}, date: string}|null
 */
function signatureEnCours(PDO $db, int $utilisateurId): ?array
{
    if (!signaturesEnBase($db)) return null;
    $stmt = $db->prepare('SELECT id, dessin, date_creation FROM signature_utilisateur
        WHERE id_utilisateur = ? AND date_retrait IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$utilisateurId]);
    $ligne = $stmt->fetch();
    $dessin = $ligne ? signatureDessin($ligne['dessin']) : null;
    return $dessin ? ['id' => (int) $ligne['id'], 'dessin' => $dessin, 'date' => (string) $ligne['date_creation']] : null;
}

/** Le dessin tel qu'il est rangé, relu ; null s'il n'y en a pas, ou s'il est illisible. */
function signatureDessin(?string $json): ?array
{
    $dessin = json_decode((string) $json, true);
    return is_array($dessin) && is_numeric($dessin['proportion'] ?? null) && is_array($dessin['traits'] ?? null)
        ? $dessin : null;
}

/**
 * Le tracé reçu du cadre de signature, vérifié et remis à l'échelle.
 *
 * Le cadre envoie ses points en fractions de sa largeur et de sa hauteur, avec sa
 * proportion. On n'en garde que l'encre : la signature est recadrée sur ce qu'elle a tracé
 * et ramenée à une hauteur de 1. La case VISA la règle ensuite sur ce qu'elle dessine, pas
 * sur le cadre où elle a été faite, qui laisse toujours du blanc autour.
 *
 * @return array{proportion: float, traits: list<list<array{0: float, 1: float}>>}
 * @throws DomainException si le tracé est vide, trop maigre pour être une signature, ou démesuré
 */
function signatureNormaliser(string $brut): array
{
    if (strlen($brut) > 200000) throw new DomainException('Signature trop chargée. Effacez et recommencez.');
    $recu = json_decode($brut, true);
    $proportion = is_array($recu) && is_numeric($recu['proportion'] ?? null) ? (float) $recu['proportion'] : 0.0;
    if ($proportion < 0.5 || $proportion > 10 || !is_array($recu['traits'] ?? null)) {
        throw new DomainException('Signature illisible. Recommencez.');
    }
    if (count($recu['traits']) > SIGNATURE_TRAITS_MAX) {
        throw new DomainException('Trop de traits pour une signature. Effacez et recommencez.');
    }

    // En hauteurs du cadre : sa hauteur vaut 1, sa largeur sa proportion.
    $traits = [];
    $points = 0;
    foreach ($recu['traits'] as $trait) {
        $suite = [];
        foreach (is_array($trait) ? $trait : [] as $point) {
            if (!is_array($point) || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) continue;
            $suite[] = [min(1.0, max(0.0, (float) $point[0])) * $proportion, min(1.0, max(0.0, (float) $point[1]))];
        }
        if (!$suite) continue;
        $points += count($suite);
        if ($points > SIGNATURE_POINTS_MAX) throw new DomainException('Signature trop chargée. Effacez et recommencez.');
        $traits[] = $suite;
    }

    $xs = array_merge([], ...array_map(static fn (array $suite): array => array_column($suite, 0), $traits));
    $ys = array_merge([], ...array_map(static fn (array $suite): array => array_column($suite, 1), $traits));
    $largeur = $xs ? max($xs) - min($xs) : 0;
    $hauteur = $ys ? max($ys) - min($ys) : 0;
    // Un clic, un point, un trait d'un millimètre : rien de cela ne vise un cordon.
    $unTrait = (bool) array_filter($traits, static fn (array $suite): bool => count($suite) > 1);
    if (!$unTrait || max($largeur, $hauteur) < 0.2) {
        throw new DomainException('Signez dans le cadre : un point ne suffit pas.');
    }

    // Un trait presque plat garderait une proportion démesurée, et la case le réduirait à
    // un fil : la boîte s'agrandit autour de lui, centrée. De même pour un trait presque
    // vertical.
    $hauteurBoite = max($hauteur, $largeur / 12);
    $largeurBoite = max($largeur, $hauteurBoite / 2);
    $gauche = min($xs) - ($largeurBoite - $largeur) / 2;
    $haut = min($ys) - ($hauteurBoite - $hauteur) / 2;

    $rangees = [];
    foreach ($traits as $suite) {
        $rangee = [];
        foreach ($suite as [$x, $y]) {
            $point = [round(($x - $gauche) / $hauteurBoite, 3), round(($y - $haut) / $hauteurBoite, 3)];
            // Deux points confondus une fois arrondis n'en font qu'un.
            if ($rangee && end($rangee) === $point) continue;
            $rangee[] = $point;
        }
        $rangees[] = $rangee;
    }
    return ['proportion' => round($largeurBoite / $hauteurBoite, 3), 'traits' => $rangees];
}

/**
 * Range la nouvelle signature de quelqu'un, à la place de la sienne, et la pose sur ses
 * visas qui n'en portent aucune.
 *
 * @return int le nombre de cordons visés au nom qui la reçoivent
 * @throws DomainException voir signatureNormaliser()
 */
function signatureEnregistrer(PDO $db, int $utilisateurId, string $brut): int
{
    $dessin = signatureNormaliser($brut);
    $db->beginTransaction();
    try {
        signatureSupprimer($db, $utilisateurId);
        $db->prepare('INSERT INTO signature_utilisateur (id_utilisateur, dessin) VALUES (?, ?)')
            ->execute([$utilisateurId, json_encode($dessin)]);
        $signes = signatureSurVisasAuNom($db, $utilisateurId, (int) $db->lastInsertId());
        $db->commit();
        return $signes;
    } catch (Throwable $erreur) {
        $db->rollBack();
        throw $erreur;
    }
}

/**
 * Pose une signature sur les visas de quelqu'un qui n'en portent aucune : ceux qu'il a posés
 * avant d'en tracer une, ou pendant qu'il n'en avait plus. Ceux qui en portent une la gardent.
 *
 * @return int le nombre de cordons signés
 */
function signatureSurVisasAuNom(PDO $db, int $utilisateurId, int $signatureId): int
{
    $poser = $db->prepare('UPDATE plan_soudure SET id_signature = ? WHERE vise_par = ? AND id_signature IS NULL');
    $poser->execute([$signatureId, $utilisateurId]);
    return $poser->rowCount();
}

/**
 * Retire la signature en cours. Elle reste sur les cordons qu'elle a visés — elle les a
 * signés — et s'efface tout à fait si elle n'en vise aucun.
 */
function signatureSupprimer(PDO $db, int $utilisateurId): void
{
    $db->prepare('UPDATE signature_utilisateur SET date_retrait = NOW() WHERE id_utilisateur = ? AND date_retrait IS NULL')
        ->execute([$utilisateurId]);
    $db->prepare('DELETE FROM signature_utilisateur WHERE id_utilisateur = ? AND date_retrait IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM plan_soudure s WHERE s.id_signature = signature_utilisateur.id)')
        ->execute([$utilisateurId]);
}

/**
 * Vise ces cordons de la signature en cours de celui qui valide — d'aucune s'il n'en a pas
 * tracé, et son nom en tient lieu. Appelée par avancementViserCordons(), qui tient la
 * transaction et a déjà posé le visa.
 *
 * @param list<int> $cordonIds
 */
function signatureViser(PDO $db, array $cordonIds, int $acteurId): void
{
    if (!$cordonIds || !signaturesEnBase($db)) return;
    $signature = signatureEnCours($db, $acteurId);
    $poser = $db->prepare('UPDATE plan_soudure SET id_signature = ? WHERE id = ?');
    foreach ($cordonIds as $id) $poser->execute([$signature['id'] ?? null, $id]);
}

/**
 * Ce qu'une lecture des cordons ajoute pour connaître leur signature : la colonne
 * « signature_dessin », et sa jointure sur la table des cordons, nommée « s ». Tant que la
 * migration n'est pas passée, la colonne est là mais vide, et le visa reste le nom.
 *
 * @return array{0: string, 1: string} la colonne à ajouter au SELECT, puis la jointure
 */
function signatureLectureCordons(PDO $db): array
{
    return signaturesEnBase($db)
        ? [', sig.dessin AS signature_dessin', ' LEFT JOIN signature_utilisateur sig ON sig.id = s.id_signature']
        : [', NULL AS signature_dessin', ''];
}

/**
 * Le tracé lissé : chaque trait passe par les milieux de ses points et s'infléchit sur les
 * points eux-mêmes, une courbe du second degré par point. Le cadre de signature dessine
 * ainsi ; redessinée point à point, la signature serait une ligne brisée.
 *
 * Un point seul reste un point : un trait de longueur nulle, que l'extrémité ronde du trait
 * rend en rond — celui d'un i, d'un paraphe.
 *
 * @return list<array> ['M', x, y], ['L', x, y] ou ['Q', xContrôle, yContrôle, x, y]
 */
function signatureCommandes(array $dessin): array
{
    $milieu = static fn (array $a, array $b): array => [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
    $commandes = [];
    foreach ($dessin['traits'] as $trait) {
        $n = count($trait);
        if (!$n) continue;
        $commandes[] = ['M', $trait[0][0], $trait[0][1]];
        if ($n < 3) {
            $commandes[] = ['L', $trait[$n - 1][0], $trait[$n - 1][1]];
            continue;
        }
        $commandes[] = ['L', ...$milieu($trait[0], $trait[1])];
        for ($i = 1; $i < $n - 1; $i++) {
            $commandes[] = ['Q', $trait[$i][0], $trait[$i][1], ...$milieu($trait[$i], $trait[$i + 1])];
        }
        $commandes[] = ['L', $trait[$n - 1][0], $trait[$n - 1][1]];
    }
    return $commandes;
}

/** Le même tracé en chemin SVG, dans la boîte de la signature : 0 0 proportion 1. */
function signatureChemin(array $dessin): string
{
    $nombre = static fn (float $valeur): string => rtrim(rtrim(sprintf('%.3F', $valeur), '0'), '.');
    return implode('', array_map(
        static fn (array $commande): string => $commande[0] . implode(' ', array_map($nombre, array_slice($commande, 1))),
        signatureCommandes($dessin)));
}

/**
 * Ce que l'écran reçoit pour dessiner une signature : sa proportion et son chemin. Le
 * lecteur l'ajuste à la case (plan-viewer.js, dessinerSignature).
 *
 * @return array{proportion: float, chemin: string}
 */
function signatureVue(array $dessin): array
{
    return ['proportion' => (float) $dessin['proportion'], 'chemin' => signatureChemin($dessin)];
}

/**
 * Comment « Valider » visera, dit dans le bloc des contrôles (soudureControlesRendu()) :
 * de sa signature si on en a tracé une ; sinon de son nom, avec le chemin pour en tracer une.
 */
function signatureAnnonceVisa(PDO $db, Utilisateur $utilisateur): string
{
    static $annonces = [];
    if (isset($annonces[$utilisateur->id])) return $annonces[$utilisateur->id];
    if (signatureEnCours($db, $utilisateur->id)) return $annonces[$utilisateur->id] = 'les vise de votre signature';
    return $annonces[$utilisateur->id] = 'les vise à votre nom, ' . prefaEscape($utilisateur->nomComplet())
        . (signaturesEnBase($db)
            ? ' — <button type="button" class="soudure-controles-lien" data-ouvre-parametres="compte">tracer votre signature</button>'
            : '');
}

/**
 * La signature en SVG, à la taille de ce qui la contient, dans la couleur du texte. Elle s'y
 * ajuste sans se déformer, et s'assied en bas : une signature se pose sur son trait.
 */
function signatureApercu(array $dessin): string
{
    // Une marge d'un dixième de hauteur : le trait déborde de la moitié de son épaisseur.
    $boite = sprintf('-0.1 -0.1 %.3F 1.2', (float) $dessin['proportion'] + 0.2);
    return '<svg viewBox="' . $boite . '" preserveAspectRatio="xMidYMax meet" aria-hidden="true" focusable="false">'
        . '<path d="' . signatureChemin($dessin) . '"/></svg>';
}

/** Les dessins des outils de la signature, au trait des icônes de l'application. */
const SIGNATURE_ICONES = [
    'refaire' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
    'supprimer' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
    'defaire' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-4"/>',
    'effacer' => '<path d="m15 4 5 5-9 9H6l-3-3 9-9"/><path d="m9 8 6 6M11 18h9"/>',
];

function signatureIcone(string $nom): string
{
    return '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' . SIGNATURE_ICONES[$nom] . '</svg>';
}

/**
 * Le bloc « Signature » des paramètres, comme au bas d'un document : la signature au-dessus
 * d'un trait, le nom et la date dessous, et dans le coin de quoi la refaire ou la supprimer.
 * Sans signature, une zone à pointillés invite à la tracer.
 *
 * Le cadre où l'on signe a la même forme et prend sa place (vue/parametres.php) : on signe
 * là où elle s'affichera. L'action rend ce bloc après chaque changement
 * (actions/ma_signature.php) ; la fenêtre le remplace d'un bloc.
 */
function signatureParametreRendu(?array $signature, string $nom): string
{
    if (!$signature) {
        return '<button type="button" class="signature-cadre signature-vide" data-signature-tracer>'
            . '<span class="signature-vide-icone">' . signatureIcone('refaire') . '</span>'
            . '<span class="signature-vide-titre">Tracer ma signature</span>'
            . '<span class="signature-vide-aide">Sans elle, vos visas portent votre nom.</span>'
            . '</button>';
    }
    return '<figure class="signature-cadre">'
        . '<div class="signature-trace" role="img" aria-label="Votre signature">' . signatureApercu($signature['dessin']) . '</div>'
        . '<div class="signature-ligne" aria-hidden="true"></div>'
        . '<figcaption class="signature-pied"><span>' . prefaEscape($nom) . '</span>'
        . '<span>Tracée le ' . prefaFormatDate($signature['date']) . '</span></figcaption>'
        . '<div class="signature-outils">'
        . '<button type="button" class="signature-outil" data-signature-tracer title="Refaire" aria-label="Refaire ma signature">'
        . signatureIcone('refaire') . '</button>'
        . '<button type="button" class="signature-outil est-destructrice" data-signature-supprimer title="Supprimer" aria-label="Supprimer ma signature">'
        . signatureIcone('supprimer') . '</button>'
        . '</div></figure>';
}
