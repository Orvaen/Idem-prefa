<?php

require_once __DIR__ . '/commandes.php';
require_once __DIR__ . '/commandes_lignes.php';

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
    $stmt = $db->prepare("SELECT id, id_document, rang, page, reference, designation, unite,
                                 quantite, date_livraison, confiance, douteuse
        FROM demande_materiel_lignes WHERE id_document IN ($places)
        ORDER BY id_document, page, rang, id");
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
        'etat_libelle' => materielEtats()[$etat],
        'resume' => materielResume($document),
        'numero' => $document['numero_commande'],
        'date' => $document['date_commande'] ? prefaFormatDate($document['date_commande']) : '',
        'articles' => count($document['lignes'] ?? []),
        'livraison' => materielEcheancesTexte($document),
        'lignes' => array_map(static fn (array $ligne): array => [
            'reference' => (string) ($ligne['reference'] ?? ''),
            'designation' => (string) $ligne['designation'],
            // Au format de la base : c'est celui qu'attend un champ de type date.
            'date_livraison' => (string) ($ligne['date_livraison'] ?? ''),
            'unite' => (string) $ligne['unite'],
            'quantite' => commandeQuantite($ligne['quantite']),
            // Ce que la lecture n'a pas su rendre nettement : la fenêtre marque ces
            // lignes-là, ce sont celles qu'on relit en premier.
            'douteuse' => !empty($ligne['douteuse']) || (int) $ligne['confiance'] < MATERIEL_CONFIANCE_NETTE,
        ], $document['lignes'] ?? []),
    ];
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
        'verifie' => 'Vérifié',
        'echec' => 'Lecture impossible',
    ];
}

/**
 * Lit un bon stocké et enregistre ses articles — ou la raison de l'échec, pour qu'un bon
 * illisible se dise en une phrase plutôt que de se faire relire indéfiniment.
 */
function materielLireDocument(PDO $db, int $idDocument): void
{
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
            (id_document, rang, page, reference, designation, unite, quantite, date_livraison, confiance, douteuse)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lu['lignes'] ?? [] as $ligne) {
            $reference = trim((string) ($ligne['reference'] ?? ''));
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
            ]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
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
 * @param list<array<string, mixed>> $lignes
 * @return int le nombre d'articles enregistrés
 * @throws DomainException sur une saisie qu'on ne peut pas enregistrer
 */
function materielEnregistrer(PDO $db, int $idDocument, array $lignes, int $idActeur): int
{
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

        $livraison = trim((string) ($ligne['date_livraison'] ?? ''));
        if ($livraison !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $livraison)) {
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

    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM demande_materiel_lignes WHERE id_document = ?')->execute([$idDocument]);
        $inserer = $db->prepare('INSERT INTO demande_materiel_lignes
            (id_document, rang, page, reference, designation, unite, quantite, date_livraison, confiance, douteuse)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, 100, 0)');
        foreach ($propres as $rang => $ligne) {
            $inserer->execute([
                $idDocument,
                $rang + 1,
                $ligne['reference'],
                $ligne['designation'],
                $ligne['unite'],
                $ligne['quantite'],
                $ligne['date_livraison'],
            ]);
        }
        $db->prepare('UPDATE demande_materiel_documents
            SET verifie_par = ?, date_verification = NOW() WHERE id = ?')
            ->execute([$idActeur, $idDocument]);
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
    return match (materielEtat($document)) {
        'a_lire' => 'Articles pas encore relevés',
        'echec' => 'Lecture impossible : ' . ($document['erreur'] ?: 'raison inconnue'),
        'verifie' => count($document['lignes'] ?? []) . ' article'
            . (count($document['lignes'] ?? []) > 1 ? 's' : '') . ', vérifiés par '
            . ($document['verificateur'] ?: 'l’encadrement')
            . ' le ' . prefaFormatDate($document['date_verification']),
        default => count($document['lignes'] ?? []) . ' article'
            . (count($document['lignes'] ?? []) > 1 ? 's' : '') . ' lus automatiquement, à vérifier',
    };
}

/**
 * Le bloc affiché sous un bon dans la fiche de la demande.
 *
 * Il ne déroule plus la liste : la fiche en portait quarante lignes entre les documents
 * et la décision, et on ne la lisait plus. Il dit où en est le bon et mène à l'écran où
 * on le corrige.
 *
 * @param array<string, mixed> $document tel que materielParDemandes le rend
 * @param bool $peutCorriger l'écran « Commande matériel » est réservé à l'encadrement
 */
function materielRendu(array $document, bool $peutCorriger): string
{
    $etat = materielEtat($document);
    $etats = materielEtats();

    // Rien lu, et personne pour le lire : le PDF reste ouvrable, et une ligne « pas
    // encore lu » devant quelqu'un qui ne peut rien y faire n'est qu'un reproche.
    if ($etat === 'a_lire' && !$peutCorriger) return '';

    $entete = '';
    if ($document['numero_commande']) {
        $entete = '<span class="materiel-numero">N° ' . prefaEscape($document['numero_commande']) . '</span>'
            . ($document['date_commande'] ? '<span class="materiel-date">du ' . prefaEscape(prefaFormatDate($document['date_commande'])) . '</span>' : '');
    }

    $echeances = materielEcheancesTexte($document);

    return '<div class="materiel-lecture">'
        . '<p class="materiel-resume">'
        . '<span class="materiel-etat materiel-etat-' . $etat . '">' . prefaEscape($etats[$etat]) . '</span>'
        . $entete
        . ($echeances !== '' ? '<span class="materiel-livraison">Livraison ' . prefaEscape($echeances) . '</span>' : '')
        . '<span class="materiel-compte">' . prefaEscape(materielResume($document)) . '</span>'
        . '</p>'
        . ($peutCorriger
            ? '<button type="button" class="btn-goto-commande-materiel" data-document="' . (int) $document['id'] . '">'
                . ($etat === 'a_lire' ? 'Lire et corriger le bon ↗' : 'Voir et corriger les articles ↗')
                . '</button>'
            : '')
        . '</div>';
}
