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
 * Les coulées proposées pour une demande : celles relevées sur ses certificats.
 *
 * @return list<array{id:int, numero:string, certificat:?string, confiance:int, confirmee:int}>
 */
function nomenclatureCouleesDisponibles(PDO $db, int $requestId): array
{
    $stmt = $db->prepare('SELECT c.id, c.numero, d.certificat, c.confiance, c.confirmee
        FROM demande_ccpu_coulees c
        JOIN demande_ccpu_documents d ON d.id = c.id_document
        WHERE d.id_demande = ? ORDER BY c.numero');
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
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
