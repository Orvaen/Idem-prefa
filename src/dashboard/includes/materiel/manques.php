<?php

/**
 * Les manques de matériel : ce que l'atelier signale, et ce que l'encadrement en fait.
 *
 * Le soudeur constate qu'il lui manque une bride, ou qu'un tube est parti au rebut. Il le
 * dit depuis « Mes soudures » ; le chargé de production et le chef d'atelier l'apprennent
 * en direct et le traitent depuis « Commande matériel ». Trois états, pas plus : envoyé,
 * vu, traité. Le signalement ne commande rien lui-même — il dit qu'il faut commander.
 *
 * La table existait (demande_approvisionnement) sans qu'aucun écran ne s'en serve : le
 * titre « Mes soudures & Suivi matériel » promettait un suivi que rien ne permettait.
 */

require_once __DIR__ . '/../../../modeles/autoload.php';

/** Les motifs, dans l'ordre où on les propose. */
function manquesMotifs(): array
{
    return [
        'oubli' => 'Oubli dans la commande',
        'erreur' => 'Erreur de livraison',
        'fabrication' => 'Casse ou rebut en fabrication',
        'autre' => 'Autre',
    ];
}

/**
 * Les états, et la puce de la maison qui va avec chacun.
 *
 * @return array<string, array{libelle: string, puce: string}>
 */
function manquesEtats(): array
{
    return [
        'nouvelle' => ['libelle' => 'Nouveau', 'puce' => 'pv-puce-a_faire'],
        'vue' => ['libelle' => 'Vu', 'puce' => 'pv-puce-vue'],
        'traitee' => ['libelle' => 'Traité', 'puce' => 'pv-puce-fait'],
    ];
}

/** Une quantité lisible : « 2 », « 2,5 », jamais « 2.000 ». */
function manqueQuantite($quantite): string
{
    $texte = rtrim(rtrim(number_format((float) $quantite, 3, ',', ' '), '0'), ',');
    return $texte === '' ? '0' : $texte;
}

/**
 * Les articles du bon de commande de la demande, à proposer en saisie : un manque porte
 * le plus souvent sur ce qui a été commandé, et le nommer comme sur le bon évite que le
 * chef cherche à quoi correspond « bride 40 ».
 *
 * @return list<array{designation: string, reference: string, unite: string}>
 */
function manquesArticlesProposes(PDO $db, int $demandeId): array
{
    $stmt = $db->prepare("SELECT l.designation, MAX(COALESCE(l.reference, '')) AS reference, MAX(l.unite) AS unite
        FROM demande_materiel_lignes l
        JOIN demande_materiel_documents doc ON doc.id = l.id_document
        WHERE doc.id_demande = ?
        GROUP BY l.designation
        ORDER BY l.designation");
    $stmt->execute([$demandeId]);
    return array_map(static fn (array $l): array => [
        'designation' => (string) $l['designation'],
        'reference' => (string) $l['reference'],
        'unite' => (string) ($l['unite'] ?: 'u'),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Enregistre un manque. Le contrôle des droits — la personne travaille-t-elle sur cette
 * demande ? — est fait par l'action ; ici on vérifie la saisie.
 *
 * @param array<string, mixed> $saisie designation, quantite, unite, motif, commentaire
 * @return int l'identifiant du signalement
 * @throws DomainException sur une saisie qu'on ne peut pas enregistrer
 */
function manqueSignaler(PDO $db, int $demandeId, int $auteurId, array $saisie): int
{
    $designation = trim((string) ($saisie['designation'] ?? ''));
    if ($designation === '') throw new DomainException('Indiquez l’article qui manque.');
    if (mb_strlen($designation) > 180) throw new DomainException('Le nom de l’article tient en 180 caractères.');

    $quantite = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) ($saisie['quantite'] ?? '')));
    if (!is_numeric($quantite) || (float) $quantite <= 0) {
        throw new DomainException('Indiquez une quantité supérieure à zéro.');
    }
    if ((float) $quantite > 999999) throw new DomainException('Quantité trop grande.');

    $motif = (string) ($saisie['motif'] ?? '');
    if (!isset(manquesMotifs()[$motif])) throw new DomainException('Choisissez le motif du manque.');

    // Choisie dans la liste des paramètres, quand elle existe (unites.php).
    require_once __DIR__ . '/unites.php';
    $unite = uniteDuManque($db, (string) ($saisie['unite'] ?? ''));

    $commentaire = trim((string) ($saisie['commentaire'] ?? ''));
    if (mb_strlen($commentaire) > 1000) throw new DomainException('Le commentaire tient en 1 000 caractères.');

    $db->prepare('INSERT INTO demande_approvisionnement
            (id_demande, designation, quantite, unite, motif, commentaire, demande_par)
        VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$demandeId, $designation, round((float) $quantite, 3), $unite, $motif,
            $commentaire === '' ? null : $commentaire, $auteurId]);
    return (int) $db->lastInsertId();
}

/** Les colonnes communes aux deux lectures. */
const MANQUES_SELECT = "SELECT m.id, m.id_demande, m.designation, m.quantite, m.unite, m.motif,
        m.commentaire, m.date_creation, m.statut, m.date_traitement, m.demande_par,
        TRIM(CONCAT(a.prenom, ' ', a.name)) AS auteur,
        TRIM(CONCAT(t.prenom, ' ', t.name)) AS traitant,
        d.nom_affaire
    FROM demande_approvisionnement m
    JOIN demande_prefabrication d ON d.id = m.id_demande
    JOIN Utilisateur a ON a.id = m.demande_par
    LEFT JOIN Utilisateur t ON t.id = m.traite_par";

/**
 * Ce qu'une personne a signalé : les manques ouverts, puis ceux traités ces trente
 * derniers jours — assez pour voir que la bride est commandée, pas l'historique entier.
 *
 * @return list<array<string, mixed>>
 */
function manquesDuSoudeur(PDO $db, int $userId): array
{
    $stmt = $db->prepare(MANQUES_SELECT . "
        WHERE m.demande_par = ?
          AND (m.statut <> 'traitee' OR m.date_traitement >= NOW() - INTERVAL 30 DAY)
        ORDER BY m.statut = 'traitee', m.date_creation DESC, m.id DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ce que l'encadrement a à traiter : tout ce qui n'est pas encore traité, les nouveaux
 * d'abord.
 *
 * @return list<array<string, mixed>>
 */
function manquesATraiter(PDO $db): array
{
    return $db->query(MANQUES_SELECT . "
        WHERE m.statut <> 'traitee'
        ORDER BY m.statut = 'vue', m.date_creation DESC, m.id DESC")->fetchAll(PDO::FETCH_ASSOC);
}

/** Les manques que personne n'a encore regardés : la pastille de « Commande matériel ». */
function manquesNouveauxCompte(PDO $db): int
{
    try {
        return (int) $db->query("SELECT COUNT(*) FROM demande_approvisionnement WHERE statut = 'nouvelle'")->fetchColumn();
    } catch (PDOException) {
        // Une base d'avant la table : pas de pastille plutôt qu'un tableau de bord en panne.
        return 0;
    }
}

/**
 * Fait avancer un manque : vu, ou traité. On ne revient pas en arrière — un manque traité
 * par erreur se signale de nouveau, c'est plus simple à lire qu'un état qui recule.
 *
 * @return array<string, mixed> le manque, tel qu'il était avant
 * @throws DomainException s'il n'existe plus ou s'il est déjà à cet état ou au-delà
 */
function manqueTraiter(PDO $db, int $id, string $statut, int $acteurId): array
{
    $ordre = ['nouvelle' => 0, 'vue' => 1, 'traitee' => 2];
    if (!in_array($statut, ['vue', 'traitee'], true)) throw new DomainException('État invalide.');

    $stmt = $db->prepare('SELECT * FROM demande_approvisionnement WHERE id = ?');
    $stmt->execute([$id]);
    $manque = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$manque) throw new DomainException('Ce signalement n’existe plus. Actualisez la page.');
    if ($ordre[$manque['statut']] >= $ordre[$statut]) {
        throw new DomainException($manque['statut'] === 'traitee'
            ? 'Ce manque est déjà traité.' : 'Ce manque est déjà marqué vu.');
    }

    $db->prepare('UPDATE demande_approvisionnement SET statut = ?, traite_par = ?, date_traitement = NOW() WHERE id = ?')
        ->execute([$statut, $acteurId, $id]);
    return $manque;
}

/**
 * Prévient l'encadrement d'un nouveau manque : chaque chargé de production et chaque chef
 * d'atelier reçoit l'annonce, nommément. Sans destinataire, le flux l'aurait envoyée à
 * tous ceux qui voient la demande — les autres soudeurs du plan compris.
 *
 * @param array<string, mixed> $donnees
 */
function manquesPrevenirEncadrement(PDO $db, int $demandeId, int $auteurId, array $donnees): void
{
    $stmt = $db->prepare('SELECT id FROM Utilisateur WHERE id_role IN (?, ?) AND id <> ?');
    $stmt->execute([Role::ChargeDeProduction->value, Role::ChefDAtelier->value, $auteurId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $destinataire) {
        Evenement::noter($db, Evenement::MANQUE_SIGNALE, $demandeId, $auteurId, $donnees, (int) $destinataire);
    }
}
