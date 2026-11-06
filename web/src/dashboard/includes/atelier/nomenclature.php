<?php

/**
 * Nomenclature de matériel d'un plan ISO : lecture, écriture et rendu du tableau.
 *
 * Le numéro de coulée est le point sensible. Il vient d'un menu déroulant alimenté par
 * les certificats lus, mais la saisie libre reste possible : le cahier des charges
 * l'exige, et la lecture automatique ne relève pas tout. Une coulée fausse est une
 * non-conformité de traçabilité, donc on garde toujours trace de son origine.
 */

/**
 * La nomenclature d'un plan : les lignes saisies, suivies des coulées lues sur les
 * certificats qui ne figurent encore dans aucune ligne.
 *
 * Les coulées sont la seule chose que la machine connaisse déjà avec certitude ; les
 * redemander à la main serait absurde, et c'est là qu'une erreur de recopie se glisse.
 * Chaque coulée inutilisée se présente donc comme une ligne à compléter : la désignation
 * et les cotes restent à saisir, elles ne sont écrites nulle part de façon exploitable.
 *
 * @return list<array<string, mixed>>
 */
function nomenclatureLignes(PDO $db, int $elementId, int $documentId, int $page = 1): array
{
    // Par page de fichier ISO, pas par élément : un élément porte plusieurs fichiers, et
    // un cahier déposé d'un bloc plusieurs isométriques, chacun avec sa nomenclature
    // imprimée. Les mélanger afficherait les coulées d'une feuille sur les autres, à des
    // positions qui n'y veulent rien dire.
    $stmt = $db->prepare('SELECT n.*, c.confiance, c.confirmee
        FROM plan_nomenclature n
        LEFT JOIN demande_ccpu_coulees c ON c.id = n.id_coulee
        WHERE n.id_element = ? AND n.id_document = ? AND n.page = ? ORDER BY n.rang');
    $stmt->execute([$elementId, $documentId, $page]);
    $lignes = $stmt->fetchAll();

    $utilisees = [];
    foreach ($lignes as $ligne) {
        if ($ligne['numero_coulee'] !== '') $utilisees[$ligne['numero_coulee']] = true;
    }

    $demande = $db->prepare('SELECT id_demande FROM element_atelier WHERE id = ?');
    $demande->execute([$elementId]);
    $idDemande = (int) $demande->fetchColumn();

    // Les coulées non encore posées ne sont pas inventées ici : elles n'ont pas de
    // place sur le plan tant que personne n'a cliqué où elles vont. Elles restent
    // disponibles dans les listes déroulantes des repères.
    unset($utilisees, $idDemande);
    return $lignes;
}

/**
 * Les coulées proposées pour une demande : celles relevées sur ses certificats, avec ce que
 * le certificat dit du matériau — son numéro, ses dimensions, sa nuance — pour les ranger
 * sous lui (nomenclatureCertificatLibelle()).
 *
 * @return list<array{id:int, numero:string, certificat:?string, confiance:int, confirmee:int,
 *                    id_document:int, nom:string, dimensions:?string, nuances:?string}>
 */
function nomenclatureCouleesDisponibles(PDO $db, int $requestId): array
{
    $stmt = $db->prepare('SELECT c.id, c.numero, d.certificat, c.confiance, c.confirmee,
            d.id AS id_document, d.nom, d.dimensions, d.nuances
        FROM demande_ccpu_coulees c
        JOIN demande_ccpu_documents d ON d.id = c.id_document
        WHERE d.id_demande = ? ORDER BY c.numero');
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

/**
 * Le titre sous lequel se rangent les coulées d'un certificat, dans le choix du soudeur :
 * « Certificat C-1234 · 60.3 x 2.77, 88.9 x 3.05 · 316L ». Une demande compte vite trente
 * coulées ; rangées sous leur certificat, on retrouve la bonne en comparant ses dimensions
 * et sa nuance à la désignation de la ligne. Un certificat dont le numéro n'a pas été lu se
 * nomme par son fichier.
 *
 * @param array{certificat:?string, nom:string, dimensions:?string, nuances:?string} $coulee
 *        une ligne de nomenclatureCouleesDisponibles()
 */
function nomenclatureCertificatLibelle(array $coulee): string
{
    $numero = trim((string) ($coulee['certificat'] ?? ''));
    $titre = $numero !== '' ? 'Certificat ' . $numero
        : (preg_replace('/\.pdf$/i', '', trim((string) ($coulee['nom'] ?? ''))) ?: 'Certificat');
    // Les dimensions s'enregistrent séparées par « ; » (CCPU_SEPARATEUR_DIMENSIONS) : on les
    // découpe sur le point-virgule seul, quelle que soit l'espace autour.
    $dimensions = implode(', ', array_filter(array_map('trim', explode(';', (string) ($coulee['dimensions'] ?? ''))), 'strlen'));
    return implode(' · ', array_filter([$titre, $dimensions, trim((string) ($coulee['nuances'] ?? ''))], 'strlen'));
}

/**
 * Empreinte de la nomenclature telle qu'elle est en base.
 *
 * Le tableau s'enregistre d'un bloc : il efface tout et réécrit. Une page restée
 * ouverte pendant que quelqu'un d'autre saisit renverrait donc des champs périmés et
 * effacerait son travail sans un mot. Le formulaire emporte cette empreinte ; si elle
 * ne correspond plus, l'écriture est refusée.
 */
function nomenclatureEmpreinte(PDO $db, int $elementId, int $documentId, int $page = 1): string
{
    // L'empreinte couvre le tableau tel qu'il s'affiche : une page bâtie sur d'autres
    // lignes est périmée, et son enregistrement refusé.
    $lignes = array_map(static fn (array $l): array => [
        (string) ($l['rang'] ?? ''), (string) ($l['pos_x'] ?? ''), (string) ($l['pos_y'] ?? ''),
        (string) ($l['pos_w'] ?? ''), (string) ($l['pos_h'] ?? ''), (string) $l['numero_coulee'],
    ], nomenclatureLignes($db, $elementId, $documentId, $page));
    return hash('sha256', json_encode($lignes));
}

/**
 * Remplace la nomenclature d'un plan par les lignes reçues.
 *
 * Un remplacement complet plutôt qu'une mise à jour ligne à ligne : le tableau se saisit
 * d'un bloc, les rangs se renumérotent quand on insère une ligne au milieu, et une
 * transaction évite de laisser une nomenclature à moitié écrite.
 *
 * @param list<array<string, string>> $lignes
 */
function nomenclatureEnregistrer(PDO $db, int $elementId, int $documentId, int $page, array $lignes, int $auteur): int
{
    $couleesConnues = [];
    $stmt = $db->prepare('SELECT c.id, c.numero FROM demande_ccpu_coulees c
        JOIN demande_ccpu_documents d ON d.id = c.id_document
        JOIN element_atelier e ON e.id_demande = d.id_demande
        WHERE e.id = ?');
    $stmt->execute([$elementId]);
    foreach ($stmt->fetchAll() as $coulee) {
        $couleesConnues[$coulee['numero']] = (int) $coulee['id'];
    }

    $db->beginTransaction();
    try {
        // Seule la page concernée est réécrite : les autres pages du cahier, et les autres
        // fichiers de l'élément, gardent la leur.
        $db->prepare('DELETE FROM plan_nomenclature WHERE id_element = ? AND id_document = ? AND page = ?')
            ->execute([$elementId, $documentId, $page]);
        $inserer = $db->prepare('INSERT INTO plan_nomenclature
            (id_element, id_document, page, rang, pos_x, pos_y, pos_w, pos_h, quantite, dn, epaisseur, description, numero_coulee, id_coulee, saisie_par)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        // Une fraction de l'image, bornée : un repère hors du plan serait invisible.
        $fraction = static function ($valeur): ?float {
            $nombre = filter_var($valeur, FILTER_VALIDATE_FLOAT);
            return $nombre === false ? null : max(0.0, min(1.0, $nombre));
        };

        // Toutes les lignes sont écrites, y compris celles sans coulée : le rang doit
        // rester celui de la ligne sur le plan. Sauter une ligne vide décalerait les
        // suivantes, et la coulée de la ligne 4 se retrouverait en face de la ligne 3.
        $rang = 0;
        $renseignees = 0;
        foreach ($lignes as $ligne) {
            $coulee = trim(mb_substr((string) ($ligne['coulee'] ?? ''), 0, 20));
            if ($coulee !== '') $renseignees++;
            $inserer->execute([
                $elementId, $documentId, $page, ++$rang,
                $fraction($ligne['x'] ?? null), $fraction($ligne['y'] ?? null),
                // Une largeur nulle rendrait le repère inattrapable : on retombe sur la valeur par défaut.
                $fraction($ligne['w'] ?? null) ?: 0.09,
                $fraction($ligne['h'] ?? null) ?: 0.018,
                trim(mb_substr((string) ($ligne['quantite'] ?? ''), 0, 20)),
                trim(mb_substr((string) ($ligne['dn'] ?? ''), 0, 20)),
                trim(mb_substr((string) ($ligne['epaisseur'] ?? ''), 0, 20)),
                trim(mb_substr((string) ($ligne['description'] ?? ''), 0, 180)),
                $coulee, $couleesConnues[$coulee] ?? null, $auteur,
            ]);
        }
        $db->commit();
        return $renseignees;
    } catch (Throwable $erreur) {
        $db->rollBack();
        throw $erreur;
    }
}

/**
 * Le numéro de coulée d'UNE ligne de la nomenclature, posé par le soudeur depuis « Mes
 * soudures » : il touche la case « N° COULÉE » de la ligne, sur le dessin, et choisit parmi
 * les coulées lues sur les certificats — ou tape le numéro.
 *
 * Pas de réécriture de la page, comme celle de l'encadrement (nomenclatureEnregistrer()) :
 * la case qui couvre déjà cette ligne prend le numéro ; s'il n'y en a pas, elle est posée,
 * dans la cellule, à la suite des autres. L'empreinte de la page change : un chef qui
 * l'aurait ouverte entre-temps se voit refuser son enregistrement, au lieu d'effacer celui-ci.
 *
 * Sur une page qui n'a encore aucune case, une case est posée pour CHAQUE ligne ($toutes), et
 * pas seulement pour celle-ci : la visionneuse de l'encadrement ne pose les siennes d'office
 * que sur une page vide, et le chef se serait retrouvé avec une case et quatre à refaire.
 *
 * @param array{x:float, y:float, w:float, h:float} $cellule la case « N° COULÉE » de la ligne,
 *        en fractions de l'image
 * @param list<array{y:float, h:float}> $toutes les lignes de la page, dans la même colonne
 */
function nomenclatureCouleeDeLigne(PDO $db, int $elementId, int $documentId, int $page, array $cellule,
    string $coulee, string $description, int $auteur, array $toutes = []): void
{
    // Dans la cellule, pas sur ses traits : les retraits de la pose d'office (plan_table.php).
    $dans = static function (float $x, float $y, float $w, float $h): array {
        $margeX = $w * 0.07;
        $margeY = $h * 0.18;
        return [$x + $margeX, $y + $margeY, $w - 2 * $margeX, $h - 2 * $margeY];
    };
    $coulee = trim(mb_substr($coulee, 0, 20));
    $connue = $db->prepare('SELECT c.id FROM demande_ccpu_coulees c
        JOIN demande_ccpu_documents d ON d.id = c.id_document
        JOIN element_atelier e ON e.id_demande = d.id_demande
        WHERE e.id = ? AND c.numero = ? LIMIT 1');
    $connue->execute([$elementId, $coulee]);
    $idCoulee = $coulee !== '' ? ($connue->fetchColumn() ?: null) : null;

    $db->beginTransaction();
    try {
        // La case de la ligne : celle dont le milieu tombe dans la bande de la ligne. Une
        // case posée à la main par le chef, un peu décalée, reste reconnue.
        $existante = $db->prepare('SELECT id FROM plan_nomenclature
            WHERE id_element = ? AND id_document = ? AND page = ? AND pos_y IS NOT NULL
              AND pos_y + pos_h / 2 BETWEEN ? AND ?
            ORDER BY rang LIMIT 1 FOR UPDATE');
        $existante->execute([$elementId, $documentId, $page, $cellule['y'], $cellule['y'] + $cellule['h']]);
        $id = $existante->fetchColumn();

        $compte = $db->prepare('SELECT COUNT(*) FROM plan_nomenclature WHERE id_element = ? AND id_document = ? AND page = ?');
        $compte->execute([$elementId, $documentId, $page]);
        $pageVide = (int) $compte->fetchColumn() === 0;

        $inserer = $db->prepare('INSERT INTO plan_nomenclature
            (id_element, id_document, page, rang, pos_x, pos_y, pos_w, pos_h, description, numero_coulee, id_coulee, saisie_par)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        if ($id) {
            $db->prepare('UPDATE plan_nomenclature SET numero_coulee = ?, id_coulee = ?, saisie_par = ?, date_saisie = NOW()
                WHERE id = ?')->execute([$coulee, $idCoulee, $auteur, $id]);
        } elseif ($pageVide && $toutes) {
            // Une case par ligne, dans l'ordre du tableau ; seule la ligne touchée a sa coulée.
            usort($toutes, static fn (array $a, array $b): int => $a['y'] <=> $b['y']);
            $rang = 0;
            foreach ($toutes as $ligne) {
                $touchee = abs($ligne['y'] - $cellule['y']) < 1e-6;
                [$x, $y, $w, $h] = $dans($cellule['x'], $ligne['y'], $cellule['w'], $ligne['h']);
                $inserer->execute([$elementId, $documentId, $page, ++$rang, $x, $y, $w, $h,
                    $touchee ? trim(mb_substr($description, 0, 180)) : '',
                    $touchee ? $coulee : '', $touchee ? $idCoulee : null, $auteur]);
            }
        } else {
            $rang = $db->prepare('SELECT COALESCE(MAX(rang), 0) + 1 FROM plan_nomenclature
                WHERE id_element = ? AND id_document = ? AND page = ?');
            $rang->execute([$elementId, $documentId, $page]);
            [$x, $y, $w, $h] = $dans($cellule['x'], $cellule['y'], $cellule['w'], $cellule['h']);
            $inserer->execute([$elementId, $documentId, $page, (int) $rang->fetchColumn(), $x, $y, $w, $h,
                trim(mb_substr($description, 0, 180)), $coulee, $idCoulee, $auteur]);
        }
        $db->commit();
    } catch (Throwable $erreur) {
        $db->rollBack();
        throw $erreur;
    }
}

/** Le tableau de saisie, affiché dans le volet d'un plan. */
function nomenclatureRendu(array $lignes, array $coulees, int $elementId, bool $modifiable, string $empreinte = '', string $urlPlan = ''): string
{
    $html = '<div class="nomenclature tableau-editable" data-plan="' . $elementId . '"'
        . ' data-action="actions/save_nomenclature.php"'
        . ' data-empreinte="' . prefaEscape($empreinte) . '"'
        . ($modifiable ? ' data-csrf="' . prefaEscape(Session::jeton()) . '"' : '') . '>';
    $html .= '<div class="nomenclature-entete"><h4>N° de coulée par ligne du plan</h4><div class="nomenclature-liens">';
    // Le plan déposé reste la référence : c'est lui qui porte les désignations, et
    // c'est en face de ses lignes qu'on renseigne les coulées.
    if ($urlPlan !== '') {
        $html .= '<a class="nomenclature-feuille" href="' . prefaEscape($urlPlan)
            . '" target="_blank" rel="noopener">Voir le plan</a>';
    }
    $html .= '<a class="nomenclature-feuille" href="actions/download_plan_iso.php?plan=' . $elementId
        . '" target="_blank" rel="noopener">Voir la feuille</a></div></div>';

    if (!$coulees && $modifiable) {
        $html .= '<p class="nomenclature-note">Aucune coulée disponible : déposez les certificats CCPU'
            . ' dans la demande pour alimenter la liste. La saisie manuelle reste possible.</p>';
    }

    // Une colonne, et une seule : le plan porte déjà la désignation, la quantité, le DN
    // et l'épaisseur de chaque ligne. Seule la coulée y manque, laissée en blanc par le
    // bureau d'études. Redemander le reste serait recopier le plan pour rien.
    $html .= '<table class="nomenclature-table nomenclature-coulees"><thead><tr>'
        . '<th scope="col">Ligne</th><th scope="col">N° coulée</th>'
        . ($modifiable ? '<th scope="col"><span class="sr-only">Retirer</span></th>' : '')
        . '</tr></thead><tbody>';

    $affichees = $lignes ?: ($modifiable ? [[]] : []);
    foreach ($affichees as $index => $ligne) {
        $html .= nomenclatureLigneRendu($ligne, $coulees, $index + 1, $modifiable);
    }
    if (!$affichees) {
        $html .= '<tr><td colspan="2" class="nomenclature-vide">Aucune coulée renseignée.</td></tr>';
    }
    $html .= '</tbody></table>';

    if ($modifiable) {
        $html .= '<div class="nomenclature-actions">'
            . '<button type="button" class="tableau-ajouter">Ajouter une ligne</button>'
            . '<button type="button" class="tableau-enregistrer">Enregistrer</button>'
            . '<span class="nomenclature-message" role="status" aria-live="polite"></span></div>';
    }
    return $html . '</div>';
}

function nomenclatureLigneRendu(array $ligne, array $coulees, int $rang, bool $modifiable): string
{
    $valeur = static fn (string $cle): string => prefaEscape((string) ($ligne[$cle] ?? ''));
    $coulee = (string) ($ligne['numero_coulee'] ?? '');

    if (!$modifiable) {
        return '<tr><td class="nomenclature-rang">' . $rang . '</td><td>'
            . prefaEscape($coulee !== '' ? $coulee : '—') . '</td></tr>';
    }

    // La liste propose les coulées lues ; « Autre » ouvre le champ de saisie à côté.
    $options = '<option value="">—</option>';
    $connue = false;
    foreach ($coulees as $proposee) {
        $choisie = $coulee !== '' && $coulee === $proposee['numero'];
        $connue = $connue || $choisie;
        $options .= '<option value="' . prefaEscape($proposee['numero']) . '"' . ($choisie ? ' selected' : '') . '>'
            . prefaEscape($proposee['numero'])
            . ((int) $proposee['confirmee'] === 1 ? '' : ' (lue une seule fois)')
            . '</option>';
    }
    $libre = $coulee !== '' && !$connue;
    $options .= '<option value="__autre"' . ($libre ? ' selected' : '') . '>Saisir un autre numéro…</option>';

    // Une ligne née d'une coulée lue sur un certificat, pas encore enregistrée.
    $venueDuCertificat = ($ligne['id'] ?? null) === null && $coulee !== '';

    return '<tr class="tableau-ligne' . ($venueDuCertificat ? ' ligne-du-certificat' : '') . '"'
        . ($venueDuCertificat ? ' title="Coulée lue sur un certificat. Enregistrez pour la rattacher à cette ligne du plan."' : '') . '>'
        . '<td class="nomenclature-rang">' . $rang . '</td>'
        . '<td class="nomenclature-coulee"><select name="coulee_liste" aria-label="Numéro de coulée de la ligne ' . $rang . '">' . $options . '</select>'
        . '<input type="text" name="coulee" value="' . prefaEscape($coulee) . '" maxlength="20"'
        . ' aria-label="Numéro de coulée saisi" placeholder="N° coulée"' . ($libre ? '' : ' hidden') . '></td>'
        . '<td><button type="button" class="tableau-retirer" aria-label="Retirer la ligne ' . $rang . '">✕</button></td>'
        . '</tr>';
}
