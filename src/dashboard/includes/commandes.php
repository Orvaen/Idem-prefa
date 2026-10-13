<?php

/**
 * Les bons de commande fournisseur rattachés à une demande.
 *
 * Le PDF du bon est déposé dans le PV « Réception matériel ». Le tableau qui l'accompagne
 * reprend ce qu'on a besoin de lire sans rouvrir le document : le numéro, le fournisseur,
 * quand ça arrive, où, et si c'est arrivé.
 *
 * Il n'y a pas de ligne d'article ici. Un bon en porte quarante ; ce qui intéresse
 * l'atelier à ce stade, c'est l'échéance et le lieu.
 */

/** Les deux seuls états que l'écran propose, pris dans l'énumération existante. */
function commandeEtats(): array
{
    return ['attendu' => 'Non reçu', 'complete' => 'Reçu'];
}

/**
 * Les commandes d'une demande, dans l'ordre où elles arrivent.
 *
 * @return list<array<string, mixed>>
 */
function commandesDeLaDemande(PDO $db, int $idDemande): array
{
    $stmt = $db->prepare('SELECT b.*, TRIM(CONCAT(u.prenom, \' \', u.name)) AS receptionnaire
        FROM bon_livraison b
        LEFT JOIN Utilisateur u ON u.id = b.receptionne_par
        WHERE b.id_demande = ?
        ORDER BY COALESCE(b.date_bl, \'9999-12-31\'), b.id');
    $stmt->execute([$idDemande]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Enregistre le tableau tel qu'il a été saisi.
 *
 * Le formulaire envoie des lignes parallèles : commande[id][], commande[numero][]… Une
 * ligne sans numéro ni fournisseur est une ligne vide qu'on vient d'ajouter sans la
 * remplir — on la laisse tomber plutôt que de refuser tout l'enregistrement.
 *
 * Les lignes absentes de l'envoi sont supprimées : c'est ainsi qu'on retire une commande.
 *
 * @param array<string, mixed> $saisie le bloc $_POST['commande']
 * @throws DomainException sur une saisie qu'on ne peut pas enregistrer
 */
function commandesEnregistrer(PDO $db, int $idDemande, array $saisie, int $idActeur): void
{
    $etats = commandeEtats();
    $colonnes = ['id', 'numero', 'fournisseur', 'date_bl', 'date_livraison_2', 'lieu_livraison', 'statut'];
    $lignes = [];

    // Ce qui est déjà en base, avant de toucher à quoi que ce soit : cela sert à repérer un
    // tableau périmé, et à ne pas réécrire la trace d'une réception déjà constatée.
    $connues = $db->prepare('SELECT id, statut, receptionne_par, date_reception
                             FROM bon_livraison WHERE id_demande = ?');
    $connues->execute([$idDemande]);
    $existantes = [];
    foreach ($connues->fetchAll(PDO::FETCH_ASSOC) as $connue) {
        $existantes[(int) $connue['id']] = $connue;
    }

    $nombre = count((array) ($saisie['numero'] ?? []));

    for ($rang = 0; $rang < $nombre; $rang++) {
        $ligne = [];
        foreach ($colonnes as $colonne) {
            $ligne[$colonne] = trim((string) (((array) ($saisie[$colonne] ?? []))[$rang] ?? ''));
        }
        if ($ligne['numero'] === '' && $ligne['fournisseur'] === '') continue;

        if ($ligne['numero'] === '') throw new DomainException('Donnez un numéro à chaque commande.');
        if (mb_strlen($ligne['numero']) > 40) throw new DomainException('Numéro de commande trop long (40 caractères maximum).');
        if ($ligne['fournisseur'] === '') throw new DomainException('Indiquez le fournisseur de la commande ' . $ligne['numero'] . '.');
        if (mb_strlen($ligne['fournisseur']) > 120) throw new DomainException('Nom de fournisseur trop long (120 caractères maximum).');
        if (mb_strlen($ligne['lieu_livraison']) > 255) throw new DomainException('Lieu de livraison trop long (255 caractères maximum).');
        if (!array_key_exists($ligne['statut'], $etats)) $ligne['statut'] = 'attendu';

        foreach (['date_bl', 'date_livraison_2'] as $champ) {
            if ($ligne[$champ] === '') { $ligne[$champ] = null; continue; }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ligne[$champ]);
            if (!$date || $date->format('Y-m-d') !== $ligne[$champ]) {
                throw new DomainException('Date de livraison invalide sur la commande ' . $ligne['numero'] . '.');
            }
        }
        // Deux échéances dans le désordre se lisent mal dans un tableau trié par date.
        if ($ligne['date_bl'] === null && $ligne['date_livraison_2'] !== null) {
            $ligne['date_bl'] = $ligne['date_livraison_2'];
            $ligne['date_livraison_2'] = null;
        }
        if ($ligne['date_bl'] !== null && $ligne['date_livraison_2'] !== null
            && $ligne['date_livraison_2'] < $ligne['date_bl']) {
            [$ligne['date_bl'], $ligne['date_livraison_2']] = [$ligne['date_livraison_2'], $ligne['date_bl']];
        }
        if ($ligne['lieu_livraison'] === '') $ligne['lieu_livraison'] = null;

        $lignes[] = $ligne;
    }

    foreach ($lignes as $ligne) {
        // Une ligne renvoyée avec un identifiant qu'on ne connaît pas vient d'un tableau
        // ouvert avant qu'un autre chef d'atelier ne la supprime. L'enregistrer en silence
        // perdrait la saisie — la modification ne porterait sur rien — et l'insérer
        // referait une commande que quelqu'un vient d'écarter.
        if ((int) $ligne['id'] > 0 && !isset($existantes[(int) $ligne['id']])) {
            throw new DomainException('Ce tableau n’est plus à jour. Actualisez avant de réessayer.');
        }
    }

    $gardes = array_values(array_filter(array_map(static fn (array $l): int => (int) $l['id'], $lignes)));

    // Ce qui a disparu du tableau est supprimé. Les marqueurs sont numérotés : l'émulation
    // des requêtes préparées est coupée, un marqueur ne se lie qu'une fois.
    if ($gardes) {
        $marqueurs = [];
        $parametres = ['demande' => $idDemande];
        foreach ($gardes as $rang => $garde) {
            $marqueurs[] = ':g' . $rang;
            $parametres['g' . $rang] = $garde;
        }
        $effacer = $db->prepare('DELETE FROM bon_livraison WHERE id_demande = :demande
                                 AND id NOT IN (' . implode(', ', $marqueurs) . ')');
        $effacer->execute($parametres);
    } else {
        $db->prepare('DELETE FROM bon_livraison WHERE id_demande = ?')->execute([$idDemande]);
    }

    $ajouter = $db->prepare('INSERT INTO bon_livraison
        (id_demande, numero, fournisseur, date_bl, date_livraison_2, lieu_livraison, statut, receptionne_par, date_reception)
        VALUES (:demande, :numero, :fournisseur, :date1, :date2, :lieu, :statut, :par, :le)');
    $modifier = $db->prepare('UPDATE bon_livraison SET numero = :numero, fournisseur = :fournisseur,
        date_bl = :date1, date_livraison_2 = :date2, lieu_livraison = :lieu, statut = :statut,
        receptionne_par = :par, date_reception = :le
        WHERE id = :id AND id_demande = :demande');

    foreach ($lignes as $ligne) {
        $recu = $ligne['statut'] === 'complete';
        $ancienne = $existantes[(int) $ligne['id']] ?? null;
        // Qui a constaté la réception, et quand : la trace se pose au passage à « Reçu » et
        // s'efface si l'on revient en arrière, mais elle ne se réécrit pas — sinon le
        // dernier à enregistrer le PV, pour une tout autre raison, prendrait la place de
        // celui qui a vraiment vu arriver la marchandise.
        $deja = $recu && $ancienne && $ancienne['statut'] === 'complete' && $ancienne['date_reception'] !== null;
        $valeurs = [
            'demande' => $idDemande,
            'numero' => $ligne['numero'],
            'fournisseur' => $ligne['fournisseur'],
            'date1' => $ligne['date_bl'],
            'date2' => $ligne['date_livraison_2'],
            'lieu' => $ligne['lieu_livraison'],
            'statut' => $ligne['statut'],
            'par' => $recu ? ($deja ? $ancienne['receptionne_par'] : $idActeur) : null,
            'le' => $recu ? ($deja ? $ancienne['date_reception'] : date('Y-m-d H:i:s')) : null,
        ];
        if ((int) $ligne['id'] > 0) {
            $modifier->execute($valeurs + ['id' => (int) $ligne['id']]);
        } else {
            $ajouter->execute($valeurs);
        }
    }
}
