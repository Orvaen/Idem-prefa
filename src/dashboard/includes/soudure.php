<?php

/**
 * Liste des soudures d'un plan ISO : lecture, écriture et rendu du tableau.
 *
 * Le numéro de soudure est le repère porté sur le plan. C'est lui qui relie une
 * radiographie à un cordon précis : il doit rester unique sur le plan, et ne jamais
 * être inventé par le logiciel.
 */

/**
 * Un contrôle se croise, comme sur le papier : le cordon l'a passé, on marque la case ;
 * sinon elle reste vide. La colonne du plan ne fait que deux pour cent de la feuille —
 * une croix s'y lit d'un coup d'œil, « Non OK » s'y écrase.
 *
 * La lettre X plutôt qu'un signe typographique : le calque du plan final écrit en
 * Helvetica/WinAnsi, où ✗ n'existe pas et ne sortirait pas à l'impression.
 */
const SOUDURE_CROIX = 'X';

/**
 * La liste des soudures d'un plan : les soudures enregistrées, suivies des pointages qui
 * n'en nourrissent encore aucune.
 *
 * Le soudeur déclare son temps et son type de soudure en pointant ; inutile de lui
 * redemander la même chose ici. Un pointage vaut une soudure : sa ligne arrive donc
 * complète, numéro compris — le prochain libre — et il ne reste à l'encadrement que les
 * contrôles et le visa, que lui seul peut renseigner.
 *
 * Ce numéro est une proposition, pas une décision : deux pointages peuvent porter sur le
 * même cordon, et c'est l'encadrement qui tranche en le corrigeant avant d'enregistrer.
 *
 * @return list<array<string, mixed>>
 */
function soudureLignes(PDO $db, int $elementId, int $documentId, int $page = 1): array
{
    // Par page de fichier ISO : chaque isométrique a sa liste soudure imprimée, avec ses
    // propres numéros de cordon. Un élément porte plusieurs fichiers, et un cahier déposé
    // d'un bloc porte plusieurs isométriques.
    $stmt = $db->prepare("SELECT s.*,
            TRIM(CONCAT(u.prenom, ' ', u.name)) AS soudeur_nom,
            TRIM(CONCAT(v.prenom, ' ', v.name)) AS visa_nom
        FROM plan_soudure s
        LEFT JOIN Utilisateur u ON u.id = s.id_soudeur
        LEFT JOIN Utilisateur v ON v.id = s.vise_par
        WHERE s.id_element = ? AND s.id_document = ? AND s.page = ?
        ORDER BY LENGTH(s.numero), s.numero");
    $stmt->execute([$elementId, $documentId, $page]);
    $lignes = $stmt->fetchAll();

    // Un pointage se propose là où il a été déclaré : sur la feuille choisie — ou sur
    // n'importe laquelle s'il n'en nomme aucune — et sur SA page. Sans cette dernière
    // condition, les mêmes pointages revenaient sur les quinze pages d'un cahier, et la
    // liste ne changeait pas d'une page à l'autre.
    //
    // Il ne se propose que tant qu'aucune soudure ne le porte : le rattacher ailleurs le
    // fait disparaître d'ici, ce qui est juste — un cordon n'est soudé qu'une fois.
    $pointages = $db->prepare("SELECT p.id, p.id_utilisateur, p.date_travail, p.type_soudure,
            TRIM(CONCAT(u.prenom, ' ', u.name)) AS soudeur_nom
        FROM pointage_atelier p
        JOIN affectation_atelier a ON a.id = p.id_affectation
        JOIN Utilisateur u ON u.id = p.id_utilisateur
        LEFT JOIN plan_soudure s ON s.id_pointage = p.id
        WHERE a.id_element = ? AND s.id IS NULL
          AND (p.id_document IS NULL OR p.id_document = ?)
          AND p.page = ?
        ORDER BY p.date_travail, p.id");
    $pointages->execute([$elementId, $documentId, $page]);

    // Les numéros déjà pris, pour que la proposition ne tombe jamais sur l'un d'eux :
    // le couple (plan, numéro) est unique en base, un doublon serait refusé à l'écriture.
    $pris = [];
    foreach ($lignes as $ligne) {
        $pris[(string) $ligne['numero']] = true;
    }
    $prochain = 1;

    foreach ($pointages->fetchAll() as $pointage) {
        while (isset($pris[(string) $prochain])) $prochain++;
        $pris[(string) $prochain] = true;
        $lignes[] = [
            'id' => null,
            'numero' => (string) $prochain,
            'numero_propose' => true,
            'id_soudeur' => $pointage['id_utilisateur'],
            'soudeur_nom' => $pointage['soudeur_nom'],
            'id_pointage' => $pointage['id'],
            'date_soudure' => $pointage['date_travail'],
            'type_soudure' => $pointage['type_soudure'],
            'rt' => '', 'vi' => '', 'pt' => '',
            'vise_par' => null, 'visa_nom' => null,
        ];
    }
    return $lignes;
}

/** Combien de cordons sont saisis sur une feuille, toutes ses pages confondues. */
function soudureCompte(PDO $db, int $elementId, int $documentId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM plan_soudure WHERE id_element = ? AND id_document = ?');
    $stmt->execute([$elementId, $documentId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Les soudeurs proposés : ceux qui ont pointé sur ce plan.
 *
 * Un cordon est l'œuvre de quelqu'un qui a déclaré son temps dessus. Proposer tout
 * l'atelier — ou pire, tous les comptes — laisserait attribuer une soudure à quelqu'un
 * qui n'y a jamais touché, et le nom porté sur le plan ne vaudrait plus rien.
 *
 * Un soudeur déjà nommé sur une soudure reste proposé même si son pointage a disparu :
 * sans cela l'enregistrement suivant, qui contrôle cette même liste, effacerait son nom
 * sans prévenir personne.
 *
 * @return list<array{id:int, nom:string, affecte:int}>
 */
function soudureSoudeursDisponibles(PDO $db, int $elementId): array
{
    $stmt = $db->prepare("SELECT u.id, TRIM(CONCAT(u.prenom, ' ', u.name)) AS nom,
            MAX(a.id_element IS NOT NULL) AS affecte
        FROM Utilisateur u
        LEFT JOIN affectation_atelier a
            ON a.id_utilisateur = u.id AND a.id_element = ? AND a.actif = 1
        WHERE u.id IN (
            SELECT p.id_utilisateur FROM pointage_atelier p
            JOIN affectation_atelier ap ON ap.id = p.id_affectation
            WHERE ap.id_element = ?
            UNION
            SELECT s.id_soudeur FROM plan_soudure s
            WHERE s.id_element = ? AND s.id_soudeur IS NOT NULL
        )
        GROUP BY u.id, u.prenom, u.name
        ORDER BY affecte DESC, u.prenom, u.name");
    $stmt->execute([$elementId, $elementId, $elementId]);
    return $stmt->fetchAll();
}

/**
 * Empreinte de la liste telle qu'elle est en base. Même raison que pour la
 * nomenclature : l'enregistrement efface et réécrit, donc une page périmée ne doit
 * pas pouvoir effacer le travail de quelqu'un d'autre.
 */
function soudureEmpreinte(PDO $db, int $elementId, int $documentId, int $page = 1): string
{
    // L'empreinte couvre la liste telle qu'elle s'affiche, pointages compris : un
    // nouveau pointage ajoute une ligne, donc la page ouverte avant lui est périmée.
    $lignes = array_map(static fn (array $l): array => [
        (string) $l['numero'], (string) ($l['id_soudeur'] ?? ''), (string) ($l['id_pointage'] ?? ''),
        (string) ($l['date_soudure'] ?? ''), (string) $l['type_soudure'],
        (string) $l['rt'], (string) $l['vi'], (string) $l['pt'],
    ], soudureLignes($db, $elementId, $documentId, $page));
    return hash('sha256', json_encode($lignes));
}

/**
 * Remplace la liste des soudures d'un plan par les lignes reçues.
 *
 * Le visa n'est pas touché ici : il engage celui qui l'a posé et se pose par une action
 * séparée. Réécrire la liste ne doit pas effacer ni déplacer une signature.
 *
 * @param list<array<string, string>> $lignes
 */
function soudureEnregistrer(PDO $db, int $elementId, int $documentId, int $page, array $lignes, int $auteur): int
{
    $soudeursPermis = [];
    foreach (soudureSoudeursDisponibles($db, $elementId) as $soudeur) {
        $soudeursPermis[(int) $soudeur['id']] = true;
    }

    // Les visas déjà posés, retrouvés par numéro de soudure après la réécriture.
    $visas = [];
    foreach (soudureLignes($db, $elementId, $documentId, $page) as $ancienne) {
        if ($ancienne['vise_par']) {
            $visas[$ancienne['numero']] = [$ancienne['vise_par'], $ancienne['date_visa']];
        }
    }

    $db->beginTransaction();
    try {
        // Seule la liste de cette page est réécrite : les autres pages du cahier, et les
        // autres fichiers de l'élément, gardent la leur.
        $db->prepare('DELETE FROM plan_soudure WHERE id_element = ? AND id_document = ? AND page = ?')
            ->execute([$elementId, $documentId, $page]);
        $inserer = $db->prepare('INSERT INTO plan_soudure
            (id_element, id_document, page, numero, id_soudeur, id_pointage, date_soudure, type_soudure, rt, vi, pt, vise_par, date_visa, saisie_par)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        // Les pointages de ce plan, et qui les a déclarés : un numéro ne peut se
        // rattacher qu'à l'un des siens, et son soudeur est celui qui l'a pointé.
        $pointagesPermis = [];
        $stmt = $db->prepare('SELECT p.id, p.id_utilisateur FROM pointage_atelier p
            JOIN affectation_atelier a ON a.id = p.id_affectation WHERE a.id_element = ?');
        $stmt->execute([$elementId]);
        foreach ($stmt->fetchAll() as $pointage) {
            $pointagesPermis[(int) $pointage['id']] = (int) $pointage['id_utilisateur'];
        }

        $total = 0;
        $vus = [];
        $pointagesVus = [];
        foreach ($lignes as $ligne) {
            $numero = trim(mb_substr((string) ($ligne['numero'] ?? ''), 0, 20));
            // Une ligne sans repère reste un pointage en attente de numéro : elle n'est
            // pas enregistrée et continuera de s'afficher à part. Un doublon est une
            // erreur de saisie : la contrainte d'unicité le refuserait de toute façon.
            if ($numero === '' || isset($vus[$numero])) continue;
            $vus[$numero] = true;

            // Le pointage d'origine, quand la ligne vient de l'historique de l'ouvrier.
            // Un même pointage ne peut nourrir qu'une seule soudure.
            $pointage = filter_var($ligne['pointage'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$pointage || !isset($pointagesPermis[$pointage]) || isset($pointagesVus[$pointage])) {
                $pointage = null;
            } else {
                $pointagesVus[$pointage] = true;
            }

            $soudeur = filter_var($ligne['soudeur'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$soudeur || !isset($soudeursPermis[$soudeur])) $soudeur = null;
            // Ligne venue d'un pointage : le soudeur est celui qui l'a déclaré. Le
            // formulaire ne l'offre plus, et ce qui arriverait quand même est ignoré —
            // une case en lecture seule ne protège rien, c'est ici que ça se tient.
            if ($pointage) $soudeur = $pointagesPermis[$pointage] ?: null;

            $date = trim((string) ($ligne['date'] ?? ''));
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;

            // Deux états et pas un de plus : la case est cochée, ou elle ne l'est pas.
            // Un navigateur n'envoie rien pour une case décochée, et le formulaire ne
            // peut donc plus déposer dans la colonne du plan trois graphies de la même
            // réponse.
            $controle = static fn (string $valeur): string => trim($valeur) === '' ? '' : SOUDURE_CROIX;

            $type = trim(mb_substr((string) ($ligne['type'] ?? ''), 0, 30));
            $rt = $controle((string) ($ligne['rt'] ?? ''));
            $vi = $controle((string) ($ligne['vi'] ?? ''));
            $pt = $controle((string) ($ligne['pt'] ?? ''));

            // Une ligne qui ne porte que son numéro n'est pas une soudure. Le volet offre
            // autant de lignes que le plan en a imprimées, numéro compris ; l'atelier n'en
            // remplit souvent que quelques-unes, et les autres ne doivent pas se compter
            // comme des cordons.
            if (!$pointage && !$soudeur && !$date && $type === '' && $rt === '' && $vi === '' && $pt === '') {
                continue;
            }

            [$visePar, $dateVisa] = $visas[$numero] ?? [null, null];
            $inserer->execute([
                $elementId, $documentId, $page, $numero, $soudeur, $pointage, $date,
                $type, $rt, $vi, $pt, $visePar, $dateVisa, $auteur,
            ]);
            $total++;
        }
        $db->commit();
        return $total;
    } catch (Throwable $erreur) {
        $db->rollBack();
        throw $erreur;
    }
}

/** Le tableau de saisie, affiché dans le volet d'un plan. */
function soudureRendu(array $lignes, array $soudeurs, int $elementId, int $documentId, bool $modifiable, string $empreinte = '', int $page = 1): string
{
    $html = '<div class="nomenclature tableau-editable" data-plan="' . $elementId . '"'
        . ' data-document="' . $documentId . '"'
        // La page du cahier : l'enregistrement ne doit toucher que la liste de celle-ci.
        . ' data-page="' . $page . '"'
        . ' data-action="actions/save_soudure.php"'
        . ' data-empreinte="' . prefaEscape($empreinte) . '"'
        . ($modifiable ? ' data-csrf="' . prefaEscape(Session::jeton()) . '"' : '') . '>';
    // Le titre est porté par le dépliant qui entoure ce tableau : le répéter ici le
    // dirait deux fois.
    $html .= '<div class="nomenclature-entete"></div>';

    if (!$soudeurs && $modifiable) {
        $html .= '<p class="nomenclature-note">Aucun soudeur proposé : personne n’a encore pointé'
            . ' sur ce plan. Le soudeur se choisit parmi ceux qui y ont déclaré leur temps.</p>';
    }

    // Les lignes venues d'un pointage portent un numéro proposé, pas encore enregistré.
    // Le dire : le plan les montre déjà, et on pourrait croire le travail fait.
    $proposees = count(array_filter($lignes, static fn (array $ligne): bool =>
        !empty($ligne['numero_propose'])));
    if ($proposees && $modifiable) {
        $html .= '<p class="nomenclature-note">' . $proposees
            . ($proposees > 1 ? ' lignes viennent des pointages' : ' ligne vient d’un pointage')
            . ', avec un numéro de soudure proposé. Corrigez-le si besoin, puis enregistrez'
            . ' pour le rattacher définitivement.</p>';
    }

    // Ses colonnes n'ont rien à voir avec celles de la nomenclature : elles ont donc
    // leurs propres largeurs, sans quoi le nom du soudeur se retrouve rogné.
    $html .= '<table class="nomenclature-table soudure-table"><thead><tr>'
        . '<th scope="col">N°</th><th scope="col">Soudeur</th><th scope="col">Date</th>'
        . '<th scope="col">Type de soudure</th><th scope="col" class="soudure-controle">RT</th>'
        . '<th scope="col" class="soudure-controle">VI</th><th scope="col" class="soudure-controle">PT</th>'
        . '<th scope="col">Visa</th>'
        . ($modifiable ? '<th scope="col"><span class="sr-only">Retirer</span></th>' : '')
        . '</tr></thead><tbody>';

    $affichees = $lignes ?: ($modifiable ? [[]] : []);
    foreach ($affichees as $ligne) {
        $html .= soudureLigneRendu($ligne, $soudeurs, $modifiable);
    }
    if (!$affichees) {
        $html .= '<tr><td colspan="8" class="nomenclature-vide">Aucune soudure renseignée.</td></tr>';
    }
    $html .= '</tbody></table>';

    // Le moule d'une ligne neuve. Copier la première ligne du tableau ne convient pas :
    // elle vient souvent d'un pointage, et sa copie emporterait le nom du soudeur et le
    // rattachement de ce pointage. Celle-ci est vierge par construction.
    if ($modifiable) {
        $html .= '<template class="soudure-modele">' . soudureLigneRendu([], $soudeurs, true) . '</template>';
    }

    if ($modifiable) {
        $html .= '<div class="nomenclature-actions">'
            . '<button type="button" class="tableau-ajouter">Ajouter une soudure</button>'
            . '<button type="button" class="tableau-enregistrer">Enregistrer les soudures</button>'
            . '<span class="nomenclature-message" role="status" aria-live="polite"></span></div>';
    }
    return $html . '</div>';
}

function soudureLigneRendu(array $ligne, array $soudeurs, bool $modifiable): string
{
    $valeur = static fn (string $cle): string => prefaEscape((string) ($ligne[$cle] ?? ''));
    $visa = trim((string) ($ligne['visa_nom'] ?? ''));

    if (!$modifiable) {
        // La croix seule, centrée comme sur le plan : c'est ce que porte la feuille.
        $croix = static fn (string $cle): string => '<td class="soudure-controle">'
            . (trim((string) ($ligne[$cle] ?? '')) === '' ? '' : SOUDURE_CROIX) . '</td>';
        return '<tr><td>' . $valeur('numero') . '</td><td>' . $valeur('soudeur_nom') . '</td>'
            . '<td>' . prefaFormatDate($ligne['date_soudure'] ?? null) . '</td>'
            . '<td>' . $valeur('type_soudure') . '</td>' . $croix('rt')
            . $croix('vi') . $croix('pt')
            . '<td>' . prefaEscape($visa !== '' ? $visa : '—') . '</td></tr>';
    }

    // La personne affectée au plan est marquée : une soudure ajoutée à la main part
    // sur elle plutôt que sur une case vide, puisque c'est elle qui travaille dessus.
    $options = '<option value="">—</option>';
    $defautPose = false;
    foreach ($soudeurs as $soudeur) {
        $choisi = (int) ($ligne['id_soudeur'] ?? 0) === (int) $soudeur['id'];
        $defaut = !$defautPose && (int) $soudeur['affecte'] === 1;
        $defautPose = $defautPose || $defaut;
        $options .= '<option value="' . (int) $soudeur['id'] . '"' . ($choisi ? ' selected' : '')
            . ($defaut ? ' data-defaut="1"' : '') . '>'
            . prefaEscape($soudeur['nom']) . ((int) $soudeur['affecte'] === 1 ? '' : '') . '</option>';
    }

    // Une case à cocher plutôt qu'un champ : le contrôle est passé ou il ne l'est pas, et
    // c'est une croix qui le dit sur le plan. Cocher se fait d'un geste ; écrire « OK »
    // demandait une convention que chacun orthographiait à sa façon.
    $controle = static fn (string $nom, string $actuel): string =>
        '<input type="checkbox" class="soudure-croix" name="' . $nom . '" value="' . SOUDURE_CROIX . '"'
        . (trim($actuel) !== '' ? ' checked' : '')
        . ' aria-label="Contrôle ' . strtoupper($nom) . ' conforme">';

    // Une ligne issue d'un pointage emporte son origine : lui donner un numéro la
    // rattache, et le pointage cesse alors d'apparaître comme en attente.
    $origine = $ligne['id_pointage'] ?? null;
    $venueDuPointage = $origine && ($ligne['id'] ?? null) === null;

    // Le soudeur d'une ligne née d'un pointage n'est pas un choix : c'est celui qui a
    // déclaré ce temps, sur son compte. Le laisser modifiable permettait d'attribuer le
    // cordon à quelqu'un d'autre, et le nom porté sur le plan ne valait plus rien. Il
    // s'affiche donc, et le serveur le relit du pointage sans écouter le formulaire.
    $soudeurFige = $origine
        ? '<span class="soudure-soudeur" title="Déclaré au pointage par cette personne.">'
            . prefaEscape((string) ($ligne['soudeur_nom'] ?? '—')) . '</span>'
        : null;

    return '<tr class="tableau-ligne' . ($venueDuPointage ? ' soudure-du-pointage' : '') . '">'
        . '<td><input type="text" name="numero" value="' . $valeur('numero') . '" maxlength="20" aria-label="Numéro de soudure"'
        . ($venueDuPointage ? ' title="Numéro proposé pour ce pointage. Corrigez-le si le cordon en porte un autre, puis enregistrez."' : '') . '>'
        . ($origine ? '<input type="hidden" name="pointage" value="' . (int) $origine . '">' : '') . '</td>'
        . '<td>' . ($soudeurFige ?? '<select name="soudeur" aria-label="Soudeur">' . $options . '</select>') . '</td>'
        . '<td><input type="date" name="date" value="' . $valeur('date_soudure') . '" aria-label="Date de soudage"></td>'
        . '<td><input type="text" name="type" value="' . $valeur('type_soudure') . '" maxlength="30" aria-label="Type de soudure" placeholder="TIG…"></td>'
        . '<td class="soudure-controle">' . $controle('rt', (string) ($ligne['rt'] ?? '')) . '</td>'
        . '<td class="soudure-controle">' . $controle('vi', (string) ($ligne['vi'] ?? '')) . '</td>'
        . '<td class="soudure-controle">' . $controle('pt', (string) ($ligne['pt'] ?? '')) . '</td>'
        . '<td class="soudure-visa">' . prefaEscape($visa !== '' ? $visa : '—') . '</td>'
        . '<td><button type="button" class="tableau-retirer" aria-label="Retirer la soudure ' . $valeur('numero') . '">✕</button></td>'
        . '</tr>';
}
