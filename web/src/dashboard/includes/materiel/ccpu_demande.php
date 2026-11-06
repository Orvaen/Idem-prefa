<?php
require_once __DIR__ . '/../lecture/ccpu.php';

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
 *
 * La fonction rend une chaîne et non du HTML écrit directement : actions/lire_ccpu.php
 * renvoie ce bloc en JSON, et le navigateur remplace celui qui attendait la lecture. Le
 * balisage est pourtant écrit en HTML, dans un tampon (ob_start) : concaténé morceau par
 * morceau, il se lisait mal, et une balise oubliée en fin de chaîne laissait la grille
 * ouverte — tout le reste de la fiche tombait dedans.
 */
function ccpuRendu(array $document, string $csrf): string
{
    $id = (int) $document['id'];

    // Une coulée est nette si sa confiance est haute et qu'une seconde lecture l'a confirmée.
    // Celle qui ne l'est pas porte la marque « à vérifier », et son infobulle dit sa confiance.
    //
    // La note qui suivait la grille — « Certificat scanné : lu par reconnaissance d'image… »
    // et ses variantes — a été retirée le 6 novembre 2026, à la demande du client : elle se
    // répétait sous chaque certificat.
    $coulees = array_map(static function (array $coulee): array {
        $confirmee = (int) $coulee['confirmee'] === 1;
        return [
            'numero' => (string) $coulee['numero'],
            'nette' => (int) $coulee['confiance'] >= CCPU_CONFIANCE_NETTE && $confirmee,
            'detail' => 'Confiance ' . (int) $coulee['confiance'] . ' % · '
                . ($confirmee ? 'confirmée par une seconde lecture' : 'lue une seule fois'),
        ];
    }, $document['coulees'] ?? []);

    // Même vocabulaire que la grille d'informations de la fiche : un libellé discret,
    // la valeur dessous. Un champ non reconnu reste affiché, pour qu'on sache qu'il a
    // été cherché et pas trouvé. Une dimension par ligne : à la suite, le retour à la
    // ligne couperait une cote en deux.
    $champs = [
        'Certificat n°' => [(string) ($document['certificat'] ?? '')],
        'Dimensions' => array_values(array_filter(explode(CCPU_SEPARATEUR_DIMENSIONS, (string) ($document['dimensions'] ?? '')), 'strlen')),
        'Nuance' => [(string) ($document['nuances'] ?? '')],
    ];

    ob_start();

    if (empty($document['date_lecture'])): ?>
        <div class="ccpu-lecture is-en-attente" data-ccpu-lire="<?= $id ?>" data-csrf="<?= prefaEscape($csrf) ?>" role="status">Lecture des coulées…</div>
    <?php elseif ($document['lecture'] === 'echec'): ?>
        <div class="ccpu-lecture is-echec">
            <p>Lecture impossible : <?= prefaEscape($document['erreur'] ?: 'raison inconnue') ?></p>
            <button type="button" class="ccpu-relire" data-ccpu-document="<?= $id ?>" data-csrf="<?= prefaEscape($csrf) ?>">Réessayer</button>
        </div>
    <?php elseif (!$document['certificat'] && !$document['dimensions'] && !$document['nuances'] && !$coulees): ?>
        <?php // Une lecture qui n'a rien reconnu se dit en une phrase, pas en une grille de champs vides. ?>
        <div class="ccpu-lecture">
            <p>Rien n’a pu être lu automatiquement : les coulées sont à relever sur le certificat.</p>
        </div>
    <?php else: ?>
        <div class="ccpu-lecture">
            <dl class="ccpu-fiche">
                <?php foreach ($champs as $libelle => $valeurs): ?>
                    <?php $valeurs = array_filter($valeurs, 'strlen'); ?>
                    <div>
                        <dt><?= $libelle ?></dt>
                        <?php if ($valeurs): ?>
                            <dd><?= implode('<br>', array_map('prefaEscape', $valeurs)) ?></dd>
                        <?php else: ?>
                            <dd class="est-vide">Non reconnu</dd>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="ccpu-fiche-coulees">
                    <dt>Coulées</dt>
                    <?php if ($coulees): ?>
                        <dd>
                            <ul class="ccpu-coulees">
                                <?php foreach ($coulees as $coulee): ?>
                                    <li class="ccpu-coulee<?= $coulee['nette'] ? '' : ' is-a-verifier' ?>" title="<?= prefaEscape($coulee['detail']) ?>"><?= prefaEscape($coulee['numero']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </dd>
                    <?php else: ?>
                        <dd class="est-vide">Aucune reconnue, à relever sur le certificat</dd>
                    <?php endif; ?>
                </div>
            </dl>
            <?php // Une lecture réussie ne se relance pas : le lecteur est déterministe, et la
                  // relire ne changerait rien tant que le certificat n'a pas bougé. Un
                  // certificat remplacé se dépose à nouveau dans la demande, et c'est son
                  // dépôt qui déclenche la lecture. ?>
        </div>
    <?php endif;

    return trim((string) ob_get_clean());
}
