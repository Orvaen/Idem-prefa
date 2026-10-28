<?php

require_once __DIR__ . '/commandes.php';
require_once __DIR__ . '/../lecture/commandes_lignes.php';

/**
 * Les bons de commande déposés sur une demande, et les articles qu'ils portent.
 *
 * Le bon arrive dans « Commande matériel », avec la demande : c'est là que le chargé
 * d'affaire le dépose, et c'est donc là qu'on vient le chercher. La lecture elle-même vit
 * dans commandes_lignes.php, qui ne connaît ni la base ni le HTML — ce fichier-ci fait la
 * jonction, comme ccpu_demande.php le fait pour les certificats matière.
 *
 * Ce qui est lu ne vaut pas saisie tant que personne ne l'a relu : la lecture confond un
 * L et un 1, et deux quantités sur seize étaient fausses sur le premier bon essayé. D'où
 * l'écran « Commande matériel », où l'encadrement corrige — et d'où les colonnes
 * verifie_par et date_verification, qui disent si quelqu'un en répond.
 *
 * À ne pas confondre avec bon_livraison_ligne, que le PV « Réception matériel » écrit :
 * ici ce qui a été commandé, là ce qui est arrivé.
 */

/** En deçà, une ligne s'affiche avec une réserve : la lecture n'était pas nette. */
const MATERIEL_CONFIANCE_NETTE = 80;

/** Au-delà, ce n'est plus un bon de commande qu'on enregistre. */
const MATERIEL_LIGNES_MAX = 500;

/**
 * Les bons de plusieurs demandes, chacun avec les articles déjà lus. Le contenu des PDF
 * n'est pas chargé : une liste de demandes n'a pas à remonter des dizaines de Mo.
 *
 * @param list<int> $ids
 * @return array<int, list<array<string, mixed>>> indexé par identifiant de demande
 */
function materielParDemandes(PDO $db, array $ids): array
{
    if (!$ids) return [];
    $places = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT doc.id, doc.id_demande, doc.nom, doc.lecture, doc.erreur,
                                 doc.date_lecture, doc.numero_commande, doc.date_commande,
                                 doc.date_verification,
                                 TRIM(CONCAT(v.prenom, ' ', v.name)) AS verificateur
        FROM demande_materiel_documents doc
        LEFT JOIN Utilisateur v ON v.id = doc.verifie_par
        WHERE doc.id_demande IN ($places) ORDER BY doc.id");
    $stmt->execute(array_values($ids));

    $documents = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $document) {
        $documents[(int) $document['id']] = $document + ['lignes' => []];
    }
    if (!$documents) return [];

    foreach (materielLignes($db, array_keys($documents)) as $idDocument => $lignes) {
        $documents[$idDocument]['lignes'] = $lignes;
    }

    $parDemande = [];
    foreach ($documents as $document) $parDemande[(int) $document['id_demande']][] = $document;
    return $parDemande;
}

/**
 * Les articles de plusieurs bons, rangés par bon et dans l'ordre du document.
 *
 * @param list<int> $idsDocuments
 * @return array<int, list<array<string, mixed>>>
 */
function materielLignes(PDO $db, array $idsDocuments): array
{
    if (!$idsDocuments) return [];
    $places = implode(',', array_fill(0, count($idsDocuments), '?'));
    $stmt = $db->prepare("SELECT l.id, l.id_document, l.rang, l.page, l.reference, l.designation,
                                 l.unite, l.quantite, l.date_livraison, l.confiance, l.douteuse,
                                 l.recu, l.date_reception, l.receptionne_par,
                                 TRIM(CONCAT(u.prenom, ' ', u.name)) AS receptionnaire
        FROM demande_materiel_lignes l
        LEFT JOIN Utilisateur u ON u.id = l.receptionne_par
        WHERE l.id_document IN ($places)
        ORDER BY l.id_document, l.page, l.rang, l.id");
    $stmt->execute(array_values($idsDocuments));

    $parDocument = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
        $parDocument[(int) $ligne['id_document']][] = $ligne;
    }
    return $parDocument;
}

/**
 * Tous les bons déposés, demande comprise : c'est ce que l'écran « Commande matériel »
 * met dans son tableau.
 *
 * Les articles ne sont pas chargés ici — ils le sont d'un coup ensuite, pour les bons
 * affichés, par materielLignes().
 *
 * @return list<array<string, mixed>>
 */
function materielTousLesBons(PDO $db): array
{
    $stmt = $db->query("SELECT doc.id, doc.id_demande, doc.nom, doc.lecture, doc.erreur,
                               doc.date_lecture, doc.numero_commande, doc.date_commande,
                               doc.date_verification,
                               TRIM(CONCAT(v.prenom, ' ', v.name)) AS verificateur,
                               d.nom_affaire, d.id_statut,
                               TRIM(CONCAT(u.prenom, ' ', u.name)) AS demandeur
        FROM demande_materiel_documents doc
        JOIN demande_prefabrication d ON d.id = doc.id_demande
        LEFT JOIN Utilisateur u ON u.id = d.idUsers
        LEFT JOIN Utilisateur v ON v.id = doc.verifie_par
        ORDER BY doc.id DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Un bon et ses articles, après une lecture ou un enregistrement : c'est ce que les deux
 * actions renvoient à l'écran, pour qu'il se remette à jour sans recharger la page.
 *
 * @return array<string, mixed>|null
 */
function materielDocument(PDO $db, int $idDocument): ?array
{
    $stmt = $db->prepare("SELECT doc.id, doc.id_demande, doc.nom, doc.lecture, doc.erreur,
                                 doc.date_lecture, doc.numero_commande, doc.date_commande,
                                 doc.date_verification,
                                 TRIM(CONCAT(v.prenom, ' ', v.name)) AS verificateur
        FROM demande_materiel_documents doc
        LEFT JOIN Utilisateur v ON v.id = doc.verifie_par
        WHERE doc.id = ?");
    $stmt->execute([$idDocument]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) return null;

    $document['lignes'] = materielLignes($db, [$idDocument])[$idDocument] ?? [];
    return $document;
}

/**
 * Le même bon, mis en forme pour l'écran : ce que le tableau affiche et ce que la fenêtre
 * d'édition ouvre. Les libellés sont calculés ici, une fois, plutôt que redits en
 * JavaScript — deux copies d'une phrase finissent par ne plus dire la même chose.
 *
 * @param array<string, mixed> $document
 * @return array<string, mixed>
 */
function materielPayload(array $document): array
{
    $etat = materielEtat($document);
    return [
        'id' => (int) $document['id'],
        'etat' => $etat,
        'fige' => materielEstFige($document),
        'etat_libelle' => materielEtats()[$etat],
        'resume' => materielResume($document),
        'numero' => $document['numero_commande'],
        'date' => $document['date_commande'] ? prefaFormatDate($document['date_commande']) : '',
        'articles' => count($document['lignes'] ?? []),
        'livraison' => materielEcheancesTexte($document),
        'recus' => materielRecus($document)['recus'],
        'retards' => materielRetards($document),
        'lignes' => array_map(static fn (array $ligne): array => [
            // L'identifiant voyage : c'est lui que le pointage de réception renvoie.
            'id' => (int) $ligne['id'],
            'recu' => !empty($ligne['recu']),
            'recu_le' => !empty($ligne['date_reception'])
                ? 'Reçu le ' . prefaFormatDate($ligne['date_reception'])
                    . ($ligne['receptionnaire'] ? ' par ' . $ligne['receptionnaire'] : '')
                : '',
            'reference' => (string) ($ligne['reference'] ?? ''),
            'designation' => (string) $ligne['designation'],
            // Au format de la base : c'est celui qu'attend un champ de type date. La
            // seconde sert à l'affichage d'une commande validée, qui n'a pas de champ.
            'date_livraison' => (string) ($ligne['date_livraison'] ?? ''),
            'date_livraison_lue' => !empty($ligne['date_livraison'])
                ? prefaFormatDate($ligne['date_livraison']) : '',
            'en_retard' => materielEnRetard($ligne),
            // L'échéance est passée, reçu ou non : c'est elle qui fait d'une livraison une
            // livraison en retard dès qu'on y dépointe un article.
            'echue' => materielEchue($ligne),
            // La date de réception courte, pour la colonne « Reçu le » ; la phrase entière,
            // avec qui l'a pointé, reste dans « recu_le ».
            'recu_court' => !empty($ligne['recu']) && !empty($ligne['date_reception'])
                ? substr(prefaFormatDate($ligne['date_reception']), 0, 5) : '',
            'unite' => (string) $ligne['unite'],
            'quantite' => commandeQuantite($ligne['quantite']),
            // Ce que la lecture n'a pas su rendre nettement : la fenêtre marque ces
            // lignes-là, ce sont celles qu'on relit en premier.
            'douteuse' => !empty($ligne['douteuse']) || (int) $ligne['confiance'] < MATERIEL_CONFIANCE_NETTE,
        ], $document['lignes'] ?? []),
    ];
}

/**
 * Un article en retard : son échéance est passée et il n'est toujours pas arrivé.
 *
 * Un article déjà reçu n'est jamais en retard, quelle que soit sa date : il est là. La
 * comparaison se fait au jour, à l'heure de Paris — une livraison attendue aujourd'hui
 * n'est pas en retard à neuf heures du matin.
 *
 * @param array<string, mixed> $ligne
 */
function materielEnRetard(array $ligne): bool
{
    return empty($ligne['recu']) && materielEchue($ligne);
}

/**
 * L'échéance d'un article est passée — qu'il soit arrivé ou non.
 *
 * @param array<string, mixed> $ligne
 */
function materielEchue(array $ligne): bool
{
    if (empty($ligne['date_livraison'])) return false;
    $echeance = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $ligne['date_livraison'],
        new DateTimeZone('Europe/Paris'));
    if (!$echeance) return false;
    return $echeance < new DateTimeImmutable('today', new DateTimeZone('Europe/Paris'));
}

/**
 * Combien d'articles sont en retard sur un bon.
 *
 * @param array<string, mixed> $document
 */
function materielRetards(array $document): int
{
    $retards = 0;
    foreach ($document['lignes'] ?? [] as $ligne) if (materielEnRetard($ligne)) $retards++;
    return $retards;
}

/**
 * Combien d'articles sont arrivés, sur combien commandés.
 *
 * @param array<string, mixed> $document
 * @return array{recus: int, total: int}
 */
function materielRecus(array $document): array
{
    $recus = 0;
    foreach ($document['lignes'] ?? [] as $ligne) if (!empty($ligne['recu'])) $recus++;
    return ['recus' => $recus, 'total' => count($document['lignes'] ?? [])];
}

/**
 * Une commande validée ne se modifie plus. Ni ses articles, ni une relecture du bon.
 *
 * C'est la règle des achats : on ne retouche pas une commande passée. S'il manque quelque
 * chose ou si une quantité change, le fournisseur reçoit une seconde commande, qui arrive
 * avec son propre bon et sa propre lecture. Corriger l'ancienne ferait disparaître ce qui
 * a réellement été commandé, et personne ne saurait plus ce qui a été reçu contre quoi.
 *
 * @param array<string, mixed> $document
 */
function materielEstFige(array $document): bool
{
    return !empty($document['date_verification']);
}

/**
 * Les échéances d'un bon : les dates de livraison distinctes que portent ses lignes.
 *
 * Un bon en annonce souvent une seule, parfois deux — la commande arrive en deux fois, et
 * c'est la seconde qui décide de la date d'atelier. Les répéter sur seize lignes ne dit
 * rien ; les compter, si.
 *
 * @param array<string, mixed> $document
 * @return list<string> dates au format de la base, dans l'ordre
 */
function materielEcheances(array $document): array
{
    $dates = [];
    foreach ($document['lignes'] ?? [] as $ligne) {
        if (!empty($ligne['date_livraison'])) $dates[(string) $ligne['date_livraison']] = true;
    }
    $dates = array_keys($dates);
    sort($dates);
    return $dates;
}

/**
 * Les mêmes, en une phrase. « puis » est le mot qu'emploie déjà le tableau des commandes
 * pour une seconde échéance : deux écrans qui parlent de la même chose l'appellent pareil.
 *
 * @param array<string, mixed> $document
 */
function materielEcheancesTexte(array $document): string
{
    $dates = materielEcheances($document);
    return match (count($dates)) {
        0 => '',
        1 => prefaFormatDate($dates[0]),
        2 => prefaFormatDate($dates[0]) . ' puis ' . prefaFormatDate($dates[1]),
        // Au-delà, les énumérer ferait une colonne illisible : on donne le nombre et les
        // deux bornes, et le détail se lit ligne à ligne dans la fenêtre.
        default => count($dates) . ' échéances, de ' . prefaFormatDate($dates[0])
            . ' à ' . prefaFormatDate($dates[count($dates) - 1]),
    };
}

/**
 * Où en est un bon, en un mot : c'est la colonne « Lecture » du tableau, et le filtre.
 *
 * @param array<string, mixed> $document
 */
function materielEtat(array $document): string
{
    if (empty($document['date_lecture'])) return 'a_lire';
    if ($document['lecture'] === 'echec') return 'echec';
    if (!empty($document['date_verification'])) return 'verifie';
    return 'a_verifier';
}

/** Les états, dans l'ordre où ils se suivent. */
function materielEtats(): array
{
    return [
        'a_lire' => 'À lire',
        'a_verifier' => 'À vérifier',
        'verifie' => 'Validée',
        'echec' => 'Lecture impossible',
    ];
}

/**
 * Lit un bon stocké et enregistre ses articles — ou la raison de l'échec, pour qu'un bon
 * illisible se dise en une phrase plutôt que de se faire relire indéfiniment.
 */
function materielLireDocument(PDO $db, int $idDocument): void
{
    // Relire, c'est remplacer les articles : sur une commande validée, c'est une
    // modification comme une autre.
    $document = materielDocument($db, $idDocument);
    if ($document && materielEstFige($document)) {
        throw new DomainException('Cette commande est validée : son bon ne peut plus être relu.');
    }

    // Ce qui a été pointé reçu survit à la relecture, reporté sur l'article de même
    // référence : relire pour corriger une désignation ne doit pas effacer ce que
    // l'atelier a constaté à la livraison.
    $pointages = materielPointagesParArticle($document ?? []);

    $stmt = $db->prepare('SELECT contenu FROM demande_materiel_documents WHERE id = ?');
    $stmt->execute([$idDocument]);
    $contenu = $stmt->fetchColumn();
    if ($contenu === false) throw new RuntimeException('Bon introuvable.');

    // Le PDF est en base, pas sur le disque ; les outils de lecture, eux, veulent un
    // fichier. Il s'en va dès la lecture faite, qu'elle ait réussi ou non.
    $fichier = tempnam(sys_get_temp_dir(), 'bon-');
    $lu = null;
    $erreur = null;
    try {
        if ($fichier === false || file_put_contents($fichier, $contenu) === false) {
            throw new RuntimeException('Dossier de travail indisponible.');
        }
        unset($contenu);
        $lu = commandeLignesLire($fichier);
    } catch (RuntimeException $exception) {
        $erreur = $exception->getMessage();
    } finally {
        if ($fichier !== false) @unlink($fichier);
    }

    // Un PDF dont pas une page ne s'ouvre : ce n'est pas une lecture vide, c'est une
    // lecture ratée, et le dire évite qu'on cherche les articles qui n'y sont pas.
    if ($lu !== null && $lu['pages'] === 0) {
        $erreur = 'Document illisible : aucune page n’a pu être ouverte.';
        $lu = null;
    }

    $db->beginTransaction();
    try {
        // Une relecture repart de zéro : la correction précédente portait sur un texte
        // qu'on vient de relire, et la dire encore vérifiée serait mentir.
        $db->prepare('UPDATE demande_materiel_documents
            SET lecture = ?, erreur = ?, date_lecture = NOW(), numero_commande = ?,
                date_commande = ?, verifie_par = NULL, date_verification = NULL
            WHERE id = ?')
            ->execute([
                $lu ? ($lu['lecture'] ?: 'echec') : 'echec',
                $erreur === null ? null : mb_substr($erreur, 0, 255),
                $lu && $lu['numero'] ? mb_substr($lu['numero'], 0, 40) : null,
                $lu['date'] ?? null,
                $idDocument,
            ]);

        $db->prepare('DELETE FROM demande_materiel_lignes WHERE id_document = ?')->execute([$idDocument]);
        $inserer = $db->prepare('INSERT INTO demande_materiel_lignes
            (id_document, rang, page, reference, designation, unite, quantite, date_livraison,
             confiance, douteuse, recu, date_reception, receptionne_par)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lu['lignes'] ?? [] as $ligne) {
            $reference = trim((string) ($ligne['reference'] ?? ''));
            $pointage = $pointages[materielClefArticle($reference, (string) $ligne['designation'])] ?? null;
            $inserer->execute([
                $idDocument,
                min(65535, max(0, (int) ($ligne['rang'] ?? 0))),
                min(65535, max(1, (int) ($ligne['page'] ?? 1))),
                $reference === '' ? null : mb_substr($reference, 0, 80),
                mb_substr((string) $ligne['designation'], 0, 180),
                mb_substr((string) $ligne['unite'], 0, 16),
                $ligne['quantite'],
                $ligne['date_livraison'] ?? null,
                min(100, max(0, (int) ($ligne['confiance'] ?? 0))),
                empty($ligne['douteuse']) ? 0 : 1,
                $pointage ? 1 : 0,
                $pointage['date_reception'] ?? null,
                $pointage['receptionne_par'] ?? null,
            ]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

/**
 * Les articles déjà pointés reçus, rangés par ce qui les reconnaît.
 *
 * @param array<string, mixed> $document
 * @return array<string, array<string, mixed>>
 */
function materielPointagesParArticle(array $document): array
{
    $pointages = [];
    foreach ($document['lignes'] ?? [] as $ligne) {
        if (empty($ligne['recu'])) continue;
        $clef = materielClefArticle((string) ($ligne['reference'] ?? ''), (string) $ligne['designation']);
        $pointages[$clef] = $ligne;
    }
    return $pointages;
}

/**
 * Une date de livraison telle qu'elle revient de l'écran, ramenée au format de la base.
 *
 * L'écran la montre et la fait saisir à la française — 08/07/2026 — parce que c'est ainsi
 * qu'elle est imprimée sur le bon. On accepte aussi la forme de la base, qui est celle que
 * renvoyait l'ancien champ de type date.
 *
 * @return string|null|false la date, null si vide, false si illisible
 */
function materielDateIso(string $saisie): string|null|false
{
    if ($saisie === '') return null;

    // « 8/7/2026 » autant que « 08/07/2026 » : on tape une date au kilomètre, on ne la
    // compose pas. checkdate écarte le 32 janvier, qu'un format laisserait passer en
    // basculant au mois suivant.
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $saisie, $t)) {
        return checkdate((int) $t[2], (int) $t[1], (int) $t[3])
            ? sprintf('%04d-%02d-%02d', $t[3], $t[2], $t[1])
            : false;
    }

    // La forme de la base, que renvoyait l'ancien champ de type date.
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $saisie, $t)) {
        return checkdate((int) $t[2], (int) $t[3], (int) $t[1]) ? $saisie : false;
    }

    return false;
}

/**
 * De quoi reconnaître un article d'une écriture à l'autre : sa référence quand il en a une,
 * sa désignation sinon. C'est par là qu'un pointage de réception retrouve sa ligne après
 * une correction de la commande.
 */
function materielClefArticle(string $reference, string $designation): string
{
    $reference = trim($reference);
    return $reference !== '' ? 'r:' . mb_strtolower($reference) : 'd:' . mb_strtolower(trim($designation));
}

/**
 * Pointe la réception d'articles : ce qui est arrivé, et ce qui ne l'est pas.
 *
 * Rien à voir avec une modification de la commande. Celle-ci, une fois validée, est figée ;
 * la marchandise, elle, continue d'arriver, et c'est ce que ce pointage enregistre.
 *
 * @param array<int, bool> $pointages identifiant de ligne => reçu ou non
 * @return int le nombre de lignes touchées
 */
function materielPointerReception(PDO $db, int $idDocument, array $pointages, int $idActeur): int
{
    if (!$pointages) return 0;

    $recu = $db->prepare('UPDATE demande_materiel_lignes
        SET recu = 1, date_reception = NOW(), receptionne_par = ?
        WHERE id = ? AND id_document = ?');
    // Dépointer efface aussi qui et quand : garder une date de réception sur un article
    // qui n'est pas arrivé, c'est se préparer à la lire un jour comme une preuve.
    $pasRecu = $db->prepare('UPDATE demande_materiel_lignes
        SET recu = 0, date_reception = NULL, receptionne_par = NULL
        WHERE id = ? AND id_document = ?');

    $touchees = 0;
    $db->beginTransaction();
    try {
        foreach ($pointages as $idLigne => $estRecu) {
            if ($estRecu) $recu->execute([$idActeur, $idLigne, $idDocument]);
            else $pasRecu->execute([$idLigne, $idDocument]);
            $touchees += $recu->rowCount() > 0 || $pasRecu->rowCount() > 0 ? 1 : 0;
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
    return $touchees;
}

/**
 * Enregistre la liste telle que l'encadrement l'a corrigée, et retient qui en répond.
 *
 * Les lignes remplacent les précédentes : c'est ainsi qu'on en retire une. Une ligne sans
 * désignation est une ligne vide qu'on vient d'ajouter sans la remplir — on la laisse
 * tomber plutôt que de refuser tout l'enregistrement.
 *
 * Une ligne corrigée à la main n'est plus douteuse et vaut cent de confiance : c'est une
 * personne qui l'a écrite, pas le lecteur.
 *
 * Enregistrer et valider sont deux gestes : la validation fige la commande, et elle
 * partait avec le moindre enregistrement — garder une quantité corrigée suffisait à rendre
 * le bon intouchable. Elle ne se pose plus que lorsqu'on la demande ($valider).
 *
 * @param list<array<string, mixed>> $lignes
 * @return int le nombre d'articles enregistrés
 * @throws DomainException sur une saisie qu'on ne peut pas enregistrer
 */
function materielEnregistrer(PDO $db, int $idDocument, array $lignes, int $idActeur, bool $valider = false): int
{
    // Le verrou est posé ici et non seulement dans l'action : c'est la fonction qui écrit,
    // et c'est elle qui doit refuser, quel que soit le chemin qui l'appelle un jour.
    $document = materielDocument($db, $idDocument);
    if ($document && materielEstFige($document)) {
        throw new DomainException('Cette commande est validée : elle ne peut plus être modifiée. Une modification donne lieu à une nouvelle commande.');
    }

    if (count($lignes) > MATERIEL_LIGNES_MAX) {
        throw new DomainException('Un bon ne peut pas porter plus de ' . MATERIEL_LIGNES_MAX . ' articles.');
    }

    $propres = [];
    foreach ($lignes as $ligne) {
        if (!is_array($ligne)) continue;
        $designation = trim((string) ($ligne['designation'] ?? ''));
        if ($designation === '') continue;

        $quantite = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) ($ligne['quantite'] ?? '')));
        if ($quantite === '') $quantite = '0';
        if (!is_numeric($quantite)) {
            throw new DomainException('Quantité illisible sur « ' . mb_substr($designation, 0, 40) . ' ».');
        }
        if ((float) $quantite < 0) {
            throw new DomainException('Une quantité ne peut pas être négative.');
        }

        $livraison = materielDateIso(trim((string) ($ligne['date_livraison'] ?? '')));
        if ($livraison === false) {
            throw new DomainException('Date de livraison illisible sur « ' . mb_substr($designation, 0, 40) . ' ».');
        }

        $reference = trim((string) ($ligne['reference'] ?? ''));
        $unite = trim((string) ($ligne['unite'] ?? ''));
        $propres[] = [
            'reference' => $reference === '' ? null : mb_substr($reference, 0, 80),
            'designation' => mb_substr($designation, 0, 180),
            'date_livraison' => $livraison === '' ? null : $livraison,
            'unite' => $unite === '' ? 'u' : mb_substr($unite, 0, 16),
            'quantite' => round((float) $quantite, 3),
        ];
    }

    // Les lignes sont réécrites : sans ce report, corriger une quantité effacerait ce que
    // l'atelier avait constaté à la livraison.
    $pointages = materielPointagesParArticle($document ?? []);

    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM demande_materiel_lignes WHERE id_document = ?')->execute([$idDocument]);
        $inserer = $db->prepare('INSERT INTO demande_materiel_lignes
            (id_document, rang, page, reference, designation, unite, quantite, date_livraison,
             confiance, douteuse, recu, date_reception, receptionne_par)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, 100, 0, ?, ?, ?)');
        foreach ($propres as $rang => $ligne) {
            $pointage = $pointages[materielClefArticle((string) $ligne['reference'], $ligne['designation'])] ?? null;
            $inserer->execute([
                $idDocument,
                $rang + 1,
                $ligne['reference'],
                $ligne['designation'],
                $ligne['unite'],
                $ligne['quantite'],
                $ligne['date_livraison'],
                $pointage ? 1 : 0,
                $pointage['date_reception'] ?? null,
                $pointage ? ($pointage['receptionne_par'] ?? null) : null,
            ]);
        }
        if ($valider) {
            $db->prepare('UPDATE demande_materiel_documents
                SET verifie_par = ?, date_verification = NOW() WHERE id = ?')
                ->execute([$idActeur, $idDocument]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }

    return count($propres);
}

/**
 * Ce qu'un bon dit de lui-même en une phrase : numéro, nombre d'articles, et si quelqu'un
 * en répond. Partagé par la fiche de la demande et par le tableau de l'écran.
 *
 * @param array<string, mixed> $document
 */
function materielResume(array $document): string
{
    ['recus' => $recus, 'total' => $total] = materielRecus($document);
    $articles = $total . ' article' . ($total > 1 ? 's' : '');

    $phrase = match (materielEtat($document)) {
        'a_lire' => 'Articles pas encore relevés',
        'echec' => 'Lecture impossible : ' . ($document['erreur'] ?: 'raison inconnue'),
        'verifie' => $articles . ', validés par ' . ($document['verificateur'] ?: 'l’encadrement')
            . ' le ' . prefaFormatDate($document['date_verification']),
        default => $articles . ' lus automatiquement, à vérifier',
    };

    // Pointer une réception n'est pas modifier la commande : une commande validée continue
    // de recevoir sa marchandise, et c'est ce compte-là qu'on vient voir ensuite.
    return $recus > 0 ? $phrase . ' · ' . $recus . '/' . $total . ' reçus' : $phrase;
}

/**
 * Le bloc affiché sous un bon dans la fiche de la demande : un chemin, rien d'autre.
 *
 * La fiche d'une demande sert à savoir où en est la demande. L'état d'un bon, son numéro,
 * ses échéances, le compte de ce qui est arrivé : c'est le travail de l'écran « Commande
 * matériel », et personne ne vient le chercher ici. Ce bloc ne garde donc que le chemin
 * vers cet écran — le PDF, lui, est déjà au-dessus.
 *
 * @param array<string, mixed> $document tel que materielParDemandes le rend
 * @param bool $peutCorriger l'écran « Commande matériel » est réservé à l'encadrement
 */
function materielRendu(array $document, bool $peutCorriger): string
{
    // Sans accès à cet écran, il n'y a pas de chemin à proposer : le bloc disparaît.
    if (!$peutCorriger) return '';

    $etat = materielEtat($document);

    return '<div class="materiel-lecture">'
        . '<button type="button" class="btn-goto-commande-materiel"'
        . ' data-document="' . (int) $document['id'] . '"'
        . ' title="Ouvre ce bon dans l’écran « Commande matériel »">'
        . match (true) {
            materielEstFige($document) => 'Voir la liste des articles ↗',
            $etat === 'a_lire' => 'Relever la liste des articles ↗',
            default => 'Voir et corriger la liste ↗',
        }
        . '</button>'
        . '</div>';
}
