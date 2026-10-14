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
    $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$commandes) return [];

    // Les articles en une seule requête : un bon en porte quarante, et interroger la base
    // par commande ferait quarante allers-retours pour afficher un volet.
    $articles = commandesArticles($db, array_map(static fn (array $c): int => (int) $c['id'], $commandes));
    foreach ($commandes as &$commande) {
        $commande['articles'] = $articles[(int) $commande['id']] ?? [];
    }
    return $commandes;
}

/**
 * Les articles de plusieurs bons, rangés par bon.
 *
 * @param list<int> $idsBons
 * @return array<int, list<array<string, mixed>>>
 */
function commandesArticles(PDO $db, array $idsBons): array
{
    if (!$idsBons) return [];
    // Marqueurs numérotés : l'émulation des requêtes préparées est coupée, et un marqueur
    // ne se lie qu'une fois.
    $marqueurs = [];
    $parametres = [];
    foreach (array_values($idsBons) as $rang => $id) {
        $marqueurs[] = ':b' . $rang;
        $parametres['b' . $rang] = $id;
    }
    $stmt = $db->prepare('SELECT id, id_bon, ordre, reference_article, designation, unite,
                                 quantite_attendue, quantite_recue, commentaire
                          FROM bon_livraison_ligne
                          WHERE id_bon IN (' . implode(', ', $marqueurs) . ')
                          ORDER BY id_bon, ordre, id');
    $stmt->execute($parametres);

    $parBon = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
        $parBon[(int) $ligne['id_bon']][] = $ligne;
    }
    return $parBon;
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

        // Les articles voyagent en JSON plutôt qu'en tableaux parallèles : un bon en porte
        // quarante, chacun avec six champs, et six tableaux de quarante entrées rangés en
        // regard de trois commandes ne se relisent plus.
        $ligne['articles'] = commandesArticlesSaisis(
            (string) (((array) ($saisie['articles'] ?? []))[$rang] ?? ''),
            $ligne['numero']
        );

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
            $idBon = (int) $ligne['id'];
        } else {
            $ajouter->execute($valeurs);
            $idBon = (int) $db->lastInsertId();
        }
        commandesArticlesEnregistrer($db, $idBon, $ligne['articles']);
    }
}

/** Au-delà, ce n'est plus un bon de commande : on refuse plutôt que d'écrire mille lignes. */
const COMMANDE_ARTICLES_MAX = 300;

/**
 * Les articles tels que le formulaire les envoie, vérifiés.
 *
 * @throws DomainException sur une saisie qu'on ne peut pas enregistrer
 * @return list<array<string, mixed>>
 */
function commandesArticlesSaisis(string $json, string $numeroCommande): array
{
    $json = trim($json);
    if ($json === '' || $json === '[]') return [];

    $brut = json_decode($json, true);
    if (!is_array($brut)) throw new DomainException('Articles illisibles sur la commande ' . $numeroCommande . '.');
    if (count($brut) > COMMANDE_ARTICLES_MAX) {
        throw new DomainException('Trop d’articles sur la commande ' . $numeroCommande
            . ' (' . COMMANDE_ARTICLES_MAX . ' au maximum).');
    }

    $articles = [];
    foreach ($brut as $article) {
        if (!is_array($article)) continue;
        $designation = trim((string) ($article['designation'] ?? ''));
        $reference = trim((string) ($article['reference_article'] ?? ''));
        // Une ligne vide est une ligne qu'on vient d'ajouter sans la remplir : on la laisse
        // tomber plutôt que de refuser tout l'enregistrement.
        if ($designation === '' && $reference === '') continue;
        if ($designation === '') {
            throw new DomainException('Donnez une désignation à l’article ' . $reference
                . ' de la commande ' . $numeroCommande . '.');
        }
        if (mb_strlen($designation) > 180) throw new DomainException('Désignation trop longue (180 caractères maximum).');
        if (mb_strlen($reference) > 80) throw new DomainException('Référence d’article trop longue (80 caractères maximum).');

        $unite = trim((string) ($article['unite'] ?? ''));
        if (mb_strlen($unite) > 16) throw new DomainException('Unité trop longue (16 caractères maximum).');

        $attendue = commandesArticleQuantite($article['quantite_attendue'] ?? null);
        if ($attendue === null) {
            throw new DomainException('Quantité attendue invalide sur l’article ' . ($reference ?: $designation)
                . ' de la commande ' . $numeroCommande . '.');
        }
        $recue = array_key_exists('quantite_recue', $article) && $article['quantite_recue'] !== null
            && $article['quantite_recue'] !== ''
            ? commandesArticleQuantite($article['quantite_recue'])
            : null;
        if ($recue === null && array_key_exists('quantite_recue', $article)
            && $article['quantite_recue'] !== null && $article['quantite_recue'] !== '') {
            throw new DomainException('Quantité reçue invalide sur l’article ' . ($reference ?: $designation) . '.');
        }

        $commentaire = trim((string) ($article['commentaire'] ?? ''));
        if (mb_strlen($commentaire) > 500) throw new DomainException('Commentaire d’article trop long (500 caractères maximum).');

        $articles[] = [
            'reference_article' => $reference !== '' ? $reference : null,
            'designation' => $designation,
            'unite' => $unite !== '' ? $unite : 'u',
            'quantite_attendue' => $attendue,
            'quantite_recue' => $recue,
            'commentaire' => $commentaire !== '' ? $commentaire : null,
        ];
    }
    return $articles;
}

/** Une quantité du bon : positive, au millième, écrite à la française ou à l'anglaise. */
function commandesArticleQuantite($valeur): ?float
{
    if (is_int($valeur) || is_float($valeur)) $valeur = (string) $valeur;
    if (!is_string($valeur)) return null;
    $propre = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($valeur));
    if (!preg_match('/^\d+(?:\.\d{1,3})?$/', $propre)) return null;
    $nombre = (float) $propre;
    return $nombre <= 999999999 ? round($nombre, 3) : null;
}

/**
 * Remplace les articles d'un bon par ceux qu'on vient de saisir.
 *
 * On efface et on réécrit plutôt que de rapprocher ligne à ligne : un bon se corrige en
 * bloc après relecture — on en retire deux, on en ajoute une, on renumérote — et faire
 * correspondre les anciennes aux nouvelles supposerait un identifiant que la lecture du
 * PDF ne donne pas.
 *
 * La quantité reçue est la seule chose que l'atelier pose lui-même : elle est reportée
 * sur l'article de même référence, sinon pointer une réception puis corriger une
 * désignation effacerait le pointage.
 *
 * @param list<array<string, mixed>> $articles
 */
function commandesArticlesEnregistrer(PDO $db, int $idBon, array $articles): void
{
    $stmt = $db->prepare('SELECT reference_article, designation, quantite_recue
                          FROM bon_livraison_ligne WHERE id_bon = ? AND quantite_recue IS NOT NULL');
    $stmt->execute([$idBon]);
    $pointages = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ancienne) {
        $pointages[commandesArticleCle($ancienne)] = $ancienne['quantite_recue'];
    }

    $db->prepare('DELETE FROM bon_livraison_ligne WHERE id_bon = ?')->execute([$idBon]);
    if (!$articles) return;

    $ajouter = $db->prepare('INSERT INTO bon_livraison_ligne
        (id_bon, ordre, reference_article, designation, unite, quantite_attendue, quantite_recue, commentaire)
        VALUES (:bon, :ordre, :reference, :designation, :unite, :attendue, :recue, :commentaire)');

    foreach ($articles as $ordre => $article) {
        $cle = commandesArticleCle($article);
        $ajouter->execute([
            'bon' => $idBon,
            'ordre' => $ordre,
            'reference' => $article['reference_article'],
            'designation' => $article['designation'],
            'unite' => $article['unite'],
            'attendue' => $article['quantite_attendue'],
            'recue' => $article['quantite_recue'] ?? ($pointages[$cle] ?? null),
            'commentaire' => $article['commentaire'],
        ]);
    }
}

/** De quoi reconnaître un article d'une saisie à l'autre : sa référence, sinon son libellé. */
function commandesArticleCle(array $article): string
{
    $reference = trim((string) ($article['reference_article'] ?? ''));
    return mb_strtolower($reference !== '' ? $reference : (string) ($article['designation'] ?? ''));
}
