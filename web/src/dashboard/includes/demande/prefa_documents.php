<?php

require_once __DIR__ . '/../materiel/ccpu_demande.php';
require_once __DIR__ . '/../materiel/materiel_bon.php';

/**
 * Les documents d'une demande : où ils sont rangés, et comment ils s'affichent dans sa fiche.
 *
 * Cinq familles, deux rangements. Les plans sont des fichiers sur le disque, parce qu'ils
 * sont gros et qu'on les sert tels quels ; les quatre autres sont des blobs, parce qu'ils
 * suivent la demande et disparaissent avec elle. Le reste de l'application n'a pas à
 * savoir laquelle est laquelle.
 *
 * Le rendu d'un document vit ici et non dans la fiche : il est aussi rendu par l'action de
 * dépôt, qui renvoie les lignes à ajouter sans recharger la page. Deux balisages pour la
 * même ligne finissent toujours par diverger.
 */

/** Au-delà, une famille s'ouvre repliée : sa liste pousserait les suivantes hors de l'écran. */
const FICHE_DOCUMENTS_DEPLIES = 4;

/**
 * Les familles de documents, dans l'ordre où la fiche les présente.
 *
 * « pages » dit que l'action sait rendre ses pages en images (lecture/pdf_pages.php) : le
 * PDF s'ouvre alors dans le lecteur de l'application, et non dans celui du navigateur.
 *
 * @return array<string, array{titre: string, action: string, table: ?string, pages?: bool}>
 *         table vaut null pour les plans : eux seuls vivent sur le disque.
 */
function prefaDocumentFamilles(): array
{
    return [
        'plan' => ['titre' => 'Plans BPE / ISO', 'action' => 'download_prefa_attachment.php', 'table' => null],
        'qmos' => ['titre' => 'QMOS', 'action' => 'download_qmos.php', 'table' => 'demande_qmos_documents'],
        'dmos' => ['titre' => 'DMOS', 'action' => 'download_dmos.php', 'table' => 'demande_dmos_documents'],
        'materiel' => ['titre' => 'Commande matériel', 'action' => 'download_materiel.php', 'table' => 'demande_materiel_documents', 'pages' => true],
        'ccpu' => ['titre' => 'CCPU', 'action' => 'download_ccpu.php', 'table' => 'demande_ccpu_documents'],
    ];
}

/**
 * Une ligne de la liste des documents : le lien, et ce qui se lit dessous.
 *
 * Sous un certificat, les coulées que la lecture y a trouvées ; sous un bon de commande,
 * où en est sa lecture. Les autres familles n'ont rien à ajouter.
 *
 * @param array<string, mixed> $document un enregistrement, ou un fichier de prefaAttachments()
 * @param array<string, mixed> $groupe   une entrée de prefaDocumentFamilles(), clé comprise
 */
function prefaDocumentLigne(int $idDemande, array $groupe, array $document, bool $canViewAllPrefa): string
{
    $nom = (string) ($document['name'] ?? $document['nom'] ?? 'Document');
    $cle = (string) ($document['key'] ?? $document['id']);
    $estPdf = strtolower(pathinfo($nom, PATHINFO_EXTENSION)) === 'pdf';

    // C'est la référence qui voyage dans l'adresse, pas l'identifiant technique : elle
    // s'affiche au survol du lien, dans l'historique de téléchargement et dans l'onglet
    // d'aperçu d'un PDF.
    $url = 'actions/' . $groupe['action']
        . '?ref=' . rawurlencode(prefaReference($idDemande))
        . '&file=' . rawurlencode($cle);

    $html = '<li>'
        . '<a class="prefa-document-link' . ($estPdf ? ' prefa-pdf-link' : '') . '"'
        . ' href="' . prefaEscape($url) . '"'
        . ' data-name="' . prefaEscape($nom) . '"'
        . ($estPdf && !empty($groupe['pages']) ? ' data-lecteur="pages"' : '')
        . ($estPdf ? ' aria-haspopup="dialog"' : '') . '>'
        . '<span class="prefa-document-text"><strong>' . prefaEscape($nom) . '</strong>'
        . '<small>' . ($estPdf ? 'Ouvrir l’aperçu' : 'Télécharger le document') . '</small></span>'
        . '<span class="prefa-document-arrow" aria-hidden="true">↗</span>'
        . '</a>';

    if (($groupe['cle'] ?? '') === 'ccpu') $html .= ccpuRendu($document, Session::jeton());
    if (($groupe['cle'] ?? '') === 'materiel') $html .= materielRendu($document, $canViewAllPrefa);

    return $html . '</li>';
}

/**
 * Qui peut déposer un document sur cette demande.
 *
 * La même règle que la modification — le chargé de production sur toutes, le chargé
 * d'affaire sur les siennes — mais sans ce qu'elle entraîne : déposer un certificat ne
 * renvoie pas la demande en attente de validation, alors que passer par « Modifier », si.
 * C'est pour cela que le dépôt a son action à lui.
 *
 * @param array<string, mixed> $demande au moins idUsers
 */
function prefaPeutDeposer(array $demande, bool $canEditPrefa, bool $isAdmin, int $idActeur): bool
{
    if (!$canEditPrefa) return false;
    return $isAdmin || (int) ($demande['idUsers'] ?? 0) === $idActeur;
}
