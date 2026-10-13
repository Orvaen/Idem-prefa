<?php
require_once __DIR__ . '/ccpu.php';

/**
 * Les certificats matière (CCPU) d'une demande : chargement groupé, lecture d'un
 * certificat stocké et rendu dans la fiche de détail. La lecture proprement dite vit
 * dans ccpu.php, qui ne connaît ni la base ni le HTML.
 */

/** À partir de cette confiance, et si elle a été relue ailleurs sur la page, une coulée s'affiche sans réserve. */
const CCPU_CONFIANCE_NETTE = 85;

/** Ce qui sépare, une fois enregistrées, les dimensions lues sur un même certificat. */
const CCPU_SEPARATEUR_DIMENSIONS = ' ; ';

/**
 * Certificats de plusieurs demandes, chacun avec les coulées déjà lues. Le contenu des
 * PDF n'est pas chargé : une liste de demandes n'a pas à remonter des dizaines de Mo.
 *
 * @return array<int, list<array<string, mixed>>> indexé par identifiant de demande
 */
function ccpuParDemandes(PDO $db, array $ids): array
{
    if (!$ids) return [];
    $places = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, id_demande, nom, lecture, erreur, date_lecture, certificat, dimensions, nuances
        FROM demande_ccpu_documents WHERE id_demande IN ($places) ORDER BY id");
    $stmt->execute(array_values($ids));
    $documents = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $document) {
        $documents[(int) $document['id']] = $document + ['coulees' => []];
    }

    if ($documents) {
        $places = implode(',', array_fill(0, count($documents), '?'));
        $stmt = $db->prepare("SELECT id_document, numero, page, confiance, confirmee
            FROM demande_ccpu_coulees WHERE id_document IN ($places) ORDER BY id");
        $stmt->execute(array_keys($documents));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $coulee) {
            $documents[(int) $coulee['id_document']]['coulees'][] = $coulee;
        }
    }

    $parDemande = [];
    foreach ($documents as $document) $parDemande[(int) $document['id_demande']][] = $document;
    return $parDemande;
}

/**
 * Lit un certificat stocké et enregistre ce qui a été reconnu — ou la raison de l'échec,
 * pour qu'un certificat illisible ne soit pas relu à chaque ouverture de la fiche.
 */
function ccpuLireDocument(PDO $db, int $idDocument): void
{
    $stmt = $db->prepare('SELECT contenu FROM demande_ccpu_documents WHERE id = ?');
    $stmt->execute([$idDocument]);
    $contenu = $stmt->fetchColumn();
    if ($contenu === false) throw new RuntimeException('Certificat introuvable.');

    $fichier = tempnam(sys_get_temp_dir(), 'ccpu-');
    $lu = null;
    $erreur = null;
    try {
        if ($fichier === false || file_put_contents($fichier, $contenu) === false) {
            throw new RuntimeException('Dossier de travail indisponible.');
        }
        unset($contenu);
        $lu = ccpuLire($fichier);
    } catch (RuntimeException $exception) {
        $erreur = $exception->getMessage();
    } finally {
        if ($fichier !== false) @unlink($fichier);
    }

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE demande_ccpu_documents
            SET lecture = ?, erreur = ?, date_lecture = NOW(), certificat = ?, dimensions = ?, nuances = ? WHERE id = ?')
            ->execute([
                $lu ? $lu['lecture'] : 'echec',
                $erreur === null ? null : mb_substr($erreur, 0, 255),
                $lu['certificat'] ?? null,
                $lu && $lu['dimensions'] ? mb_substr(implode(CCPU_SEPARATEUR_DIMENSIONS, $lu['dimensions']), 0, 255) : null,
                $lu && $lu['nuances'] ? mb_substr(implode(', ', $lu['nuances']), 0, 255) : null,
                $idDocument,
            ]);
        // Une relecture remplace la précédente : rien ne doit survivre d'une lecture périmée.
        $db->prepare('DELETE FROM demande_ccpu_coulees WHERE id_document = ?')->execute([$idDocument]);
        $inserer = $db->prepare('INSERT INTO demande_ccpu_coulees (id_document, numero, page, confiance, confirmee) VALUES (?, ?, ?, ?, ?)');
        foreach ($lu['coulees'] ?? [] as $coulee) {
            $inserer->execute([$idDocument, mb_substr($coulee['numero'], 0, 20), $coulee['page'], min(100, $coulee['confiance']), (int) $coulee['confirmee']]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

/**
 * Bloc affiché sous un certificat dans la fiche de détail.
 *
 * Trois états : pas encore lu (la lecture se lance d'elle-même à l'ouverture de la
 * fiche), lu, ou lecture impossible. Ce qui a été lu est toujours présenté comme une
 * lecture automatique à vérifier : rien ici ne vaut validation.
 */
function ccpuRendu(array $document, string $csrf): string
{
    $id = (int) $document['id'];
    $jeton = prefaEscape($csrf);

    if (empty($document['date_lecture'])) {
        return '<div class="ccpu-lecture is-en-attente" data-ccpu-lire="' . $id . '" data-csrf="' . $jeton . '" role="status">Lecture des coulées…</div>';
    }

    if ($document['lecture'] === 'echec') {
        return '<div class="ccpu-lecture is-echec"><p>Lecture impossible : '
            . prefaEscape($document['erreur'] ?: 'raison inconnue') . '</p>'
            . '<button type="button" class="ccpu-relire" data-ccpu-document="' . $id
            . '" data-csrf="' . $jeton . '">Réessayer</button></div>';
    }

    // Une lecture qui n'a rien reconnu se dit en une phrase, pas en une grille de champs vides.
    if (!$document['certificat'] && !$document['dimensions'] && !$document['nuances'] && !$document['coulees']) {
        return '<div class="ccpu-lecture"><p>Rien n’a pu être lu automatiquement : les coulées sont à relever sur le certificat.</p></div>';
    }

    // Même vocabulaire que la grille d'informations de la fiche : un libellé discret,
    // la valeur dessous. Un champ non reconnu reste affiché, pour qu'on sache qu'il a
    // été cherché et pas trouvé. La valeur arrive déjà échappée.
    $champ = static fn (string $libelle, string $valeur): string =>
        '<div><dt>' . $libelle . '</dt><dd' . ($valeur !== '' ? '>' . $valeur : ' class="est-vide">Non reconnu') . '</dd></div>';

    $html = '<div class="ccpu-lecture"><dl class="ccpu-fiche">'
        . $champ('Certificat n°', prefaEscape($document['certificat']))
        // Une dimension par ligne : à la suite, le retour à la ligne couperait une cote en deux.
        . $champ('Dimensions', str_replace(CCPU_SEPARATEUR_DIMENSIONS, '<br>', prefaEscape($document['dimensions'])))
        . $champ('Nuance', prefaEscape($document['nuances']))
        . '<div class="ccpu-fiche-coulees"><dt>Coulées</dt><dd';

    // D'où viennent ces numéros. Les deux chemins n'ont pas la même valeur : la couche
    // texte rend les caractères mêmes du fichier du fournisseur, exacts, tandis qu'un
    // scan passe par la reconnaissance d'image, qui confond un 8 et un B. Demander la
    // même vigilance dans les deux cas, sans dire lequel, c'est n'en obtenir aucune.
    $note = match ($document['lecture']) {
        'texte' => 'Lu dans le texte du PDF : ce sont les caractères du fichier du fournisseur, non une reconnaissance d’image. À confirmer sur le certificat.',
        'ocr' => 'Certificat scanné : lu par reconnaissance d’image, donc à vérifier chiffre par chiffre sur le certificat.',
        'mixte' => 'Lu dans le texte du PDF, sauf les pages scannées, reconnues à l’image. À vérifier sur le certificat.',
        default => 'Lecture automatique, à vérifier sur le certificat.',
    };
    if ($document['coulees']) {
        $aVerifier = false;
        $html .= '><ul class="ccpu-coulees">';
        foreach ($document['coulees'] as $coulee) {
            $nette = (int) $coulee['confiance'] >= CCPU_CONFIANCE_NETTE && (int) $coulee['confirmee'] === 1;
            $aVerifier = $aVerifier || !$nette;
            $detail = 'Confiance ' . (int) $coulee['confiance'] . ' % · '
                . ((int) $coulee['confirmee'] === 1 ? 'confirmée par une seconde lecture' : 'lue une seule fois');
            $html .= '<li class="ccpu-coulee' . ($nette ? '' : ' is-a-verifier') . '" title="' . prefaEscape($detail) . '">'
                . prefaEscape($coulee['numero']) . '</li>';
        }
        $html .= '</ul>';
        if ($aVerifier) $note .= ' Les coulées marquées « ? » ont été lues avec moins de certitude.';
    } else {
        $html .= ' class="est-vide">Aucune reconnue, à relever sur le certificat';
    }

    // Une lecture réussie ne se relance pas : le lecteur est déterministe, et la relire
    // ne changerait rien tant que le certificat n'a pas bougé. Un certificat remplacé se
    // dépose à nouveau dans la demande, et c'est son dépôt qui déclenche la lecture.
    return $html . '</dd></div></dl><p class="ccpu-note">' . prefaEscape($note) . '</p></div>';
}
