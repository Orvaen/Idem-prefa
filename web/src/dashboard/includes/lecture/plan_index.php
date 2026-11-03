<?php
/**
 * L'indexation d'un plan : tout ce que les écrans lisent d'une page de plan, préparé dès que
 * le fichier arrive — et non plus à la première ouverture.
 *
 * Par page : les deux tableaux (plan_lignes.php), les repères du dessin (plan_reperes.php),
 * la nomenclature lue à l'OCR (plan_nomenclature.php), l'image que la fenêtre du plan
 * affiche et sa vignette de sommaire. Près de trois secondes par page, mesurées sur le
 * cahier d'essai : le soudeur attendait devant chaque feuille qu'il ouvrait pour la première
 * fois. Tout est gardé à côté du plan, dans le dossier de sa demande, et n'est refait que si
 * le fichier change ou si une lecture change de version.
 *
 * Un repère par document dit qu'il a été lu en entier pour les lectures d'aujourd'hui
 * (planIndexAJour()). C'est lui qu'on regarde avant de relancer quoi que ce soit, et c'est
 * lui qui fait rattraper, à sa première ouverture, un plan déposé avant qu'une lecture
 * existe : la tâche de fond n'était relancée que s'il manquait une liste soudure, et les
 * repères, la nomenclature et les images d'un plan déjà lu se faisaient donc à l'ouverture.
 */

require_once __DIR__ . '/../atelier/plan_document.php';
require_once __DIR__ . '/plan_lignes.php';
require_once __DIR__ . '/plan_reperes.php';
require_once __DIR__ . '/plan_nomenclature.php';

/** La définition de l'image d'une page dans la fenêtre du plan. */
const PLAN_APERCU_DPI = 150;

/**
 * La définition d'une vignette de sommaire. On y reconnaît la feuille à son tracé, on n'y
 * lit rien : quinze pages en pleine définition feraient trois méga-octets pour une colonne
 * large comme le pouce.
 */
const PLAN_APERCU_DPI_VIGNETTE = 25;

/**
 * Les versions de toutes les lectures d'une page, en une chaîne : que l'une change, et chaque
 * plan est relu une fois, en fond. La dernière est celle des images et du repère lui-même —
 * 2 depuis qu'il porte le compte des soudures de chaque page.
 */
function planIndexVersion(): string
{
    return implode('.', [PLAN_LIGNES_VERSION, PLAN_REPERES_VERSION, PLAN_NOMENCLATURE_VERSION, 2]);
}

/** Une clé de plan déposé : 32 caractères hexadécimaux, un tiret, un nom sans dossier. */
function planIndexCleValide(string $cle): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}-[^\/\\\\]+$/', $cle);
}

function planIndexRepere(int $requestId, string $cle): string
{
    return prefaAttachmentDirectory($requestId) . '/index-' . substr($cle, 0, 32) . '.json';
}

/**
 * Le repère d'un document lu en entier, pour les lectures d'aujourd'hui, depuis son dépôt —
 * ou null s'il ne l'est pas.
 *
 * Il porte aussi le compte des soudures de chaque page (null pour une page illisible) : le
 * total d'un cahier se lit alors dans ce seul fichier, au lieu des quinze caches de ses
 * pages. Les fichiers de l'application sont servis à travers un montage depuis Windows, où
 * chaque accès coûte : relire quinze caches prenait 0,27 s à chaque feuille ouverte.
 *
 * @return array{version:string, pages:int, soudures?:list<int|null>}|null
 */
function planIndexLu(int $requestId, string $cle): ?array
{
    $source = prefaAttachmentDirectory($requestId) . '/' . $cle;
    $repere = planIndexRepere($requestId, $cle);
    if (!is_file($repere) || !is_file($source) || filemtime($repere) < filemtime($source)) return null;
    $lu = json_decode((string) file_get_contents($repere), true);
    return is_array($lu) && ($lu['version'] ?? '') === planIndexVersion() ? $lu : null;
}

/** Le document a-t-il été lu en entier, pour les lectures d'aujourd'hui, depuis son dépôt ? */
function planIndexAJour(int $requestId, string $cle): bool
{
    return planIndexLu($requestId, $cle) !== null;
}

/**
 * L'image d'une page — ou sa vignette —, rastérisée une fois puis gardée à côté du plan.
 *
 * Écrite sous un nom provisoire puis renommée : la fenêtre et la tâche de fond peuvent la
 * demander au même moment, et aucune ne doit servir une image à moitié écrite.
 *
 * @return string|null le chemin de l'image, ou null si le PDF ne se rastérise pas
 */
function planApercu(int $requestId, string $cle, int $page, bool $vignette = false): ?string
{
    $source = prefaAttachmentDirectory($requestId) . '/' . $cle;
    // La première page garde le nom de cache d'origine, pour que les images déjà rastérisées
    // resservent.
    $cache = prefaAttachmentDirectory($requestId) . '/apercu-' . substr($cle, 0, 32)
        . ($page > 1 ? '-p' . $page : '') . ($vignette ? '-v' : '') . '.jpg';
    if (is_file($cache) && is_file($source) && filemtime($cache) >= filemtime($source)) return $cache;

    $provisoire = substr($cache, 0, -4) . '-' . bin2hex(random_bytes(4));
    exec(sprintf(
        'pdftoppm -r %d -jpeg -f %d -l %d -singlefile %s %s 2>/dev/null',
        $vignette ? PLAN_APERCU_DPI_VIGNETTE : PLAN_APERCU_DPI,
        $page,
        $page,
        escapeshellarg($source),
        escapeshellarg($provisoire)
    ), $sortie, $code);
    if ($code !== 0 || !is_file($provisoire . '.jpg')) {
        @unlink($provisoire . '.jpg');
        return null;
    }
    return @rename($provisoire . '.jpg', $cache) ? $cache : null;
}

/**
 * Lit tout un document, page après page, puis le marque lu.
 *
 * Un verrou par document : la fiche, Plans / ISO et l'ouverture d'une feuille peuvent le
 * demander au même moment, il n'est lu qu'une fois. Une page qui résiste n'arrête pas les
 * suivantes : elle sera relue à son ouverture, comme avant, et le document est marqué lu
 * quand même — sans quoi chaque ouverture relancerait la même lecture ratée.
 *
 * @return string ce qui s'est passé, pour le compte rendu de lire_feuilles.php : « lu »,
 *                « à jour », « en cours » (un autre le lit déjà) ou « absent »
 */
function planIndexerDocument(int $requestId, string $cle): string
{
    if (!planIndexCleValide($cle)) return 'absent';
    $source = prefaAttachmentDirectory($requestId) . '/' . $cle;
    if (!is_file($source) || strtolower(pathinfo($cle, PATHINFO_EXTENSION)) !== 'pdf') return 'absent';
    if (planIndexAJour($requestId, $cle)) return 'à jour';

    $verrou = fopen(sys_get_temp_dir() . '/idem-plan-' . $requestId . '-' . substr($cle, 0, 32) . '.lock', 'c');
    if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) return 'en cours';

    $pages = max(1, planDocumentCompterPages($source));
    $soudures = [];
    for ($page = 1; $page <= $pages; $page++) {
        // Le compte des soudures de la page, pour le total du cahier ; null si elle résiste.
        $soudures[$page - 1] = null;
        try {
            $analyse = planLignesAnalyse($source, planLignesCache($requestId, $cle, $page), $page);
            $soudures[$page - 1] = count($analyse['soudures']['lignes'] ?? []);
            planReperesAnalyse($source, planReperesCache($requestId, $cle, $page), $page, $analyse['soudures'] ?? null);
            planNomenclatureAnalyse($source, planNomenclatureCache($requestId, $cle, $page), $page);
            planApercu($requestId, $cle, $page);
            planApercu($requestId, $cle, $page, true);
        } catch (Throwable) {
            continue;
        }
    }
    @file_put_contents(planIndexRepere($requestId, $cle), json_encode([
        'version' => planIndexVersion(),
        'pages' => $pages,
        'soudures' => $soudures,
    ]));
    flock($verrou, LOCK_UN);
    return 'lu';
}

/** Les plans d'une demande : ce qu'elle porte dans « Plans BPE / ISO », en PDF. */
function planIndexDocumentsDemande(int $requestId): array
{
    return array_values(array_filter(
        array_column(prefaAttachments($requestId), 'key'),
        static fn (string $cle): bool => strtolower(pathinfo($cle, PATHINFO_EXTENSION)) === 'pdf'
    ));
}

/**
 * Lance la lecture des plans d'une demande — ou de l'un d'eux —, sans attendre qu'elle
 * finisse : la réponse part tout de suite (outils/lire_feuilles.php).
 */
function planIndexerDemandeEnFond(int $requestId, ?string $cle = null): void
{
    $php = is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
    $script = dirname(__DIR__, 2) . '/outils/lire_feuilles.php';
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --demande ' . $requestId
        . ($cle !== null ? ' ' . escapeshellarg($cle) : '') . ' > /dev/null 2>&1 &');
}

/**
 * À l'ouverture d'une feuille : les documents du plan qui ne sont pas à jour — déposés avant
 * qu'une lecture existe, ou lus par une version plus ancienne — partent en lecture, en fond.
 * La feuille ouverte se lit tout de suite, elle ; les autres seront prêtes.
 */
function planIndexerElementSiBesoin(PDO $db, int $elementId, int $requestId): void
{
    foreach (planDocuments($db, $elementId) as $document) {
        if (!planIndexAJour($requestId, $document['cle_document'])) {
            planDocumentLireEnFond($elementId);
            return;
        }
    }
}
