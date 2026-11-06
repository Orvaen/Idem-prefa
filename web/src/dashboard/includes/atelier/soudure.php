<?php

/**
 * Liste des soudures d'un plan ISO : lecture, écriture et rendu du tableau.
 *
 * Le numéro de soudure est le repère porté sur le plan. C'est lui qui relie une
 * radiographie à un cordon précis : il doit rester unique sur le plan, et ne jamais
 * être inventé par le logiciel.
 */

require_once __DIR__ . '/signature.php';

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
 * Les trois contrôles, et le nom que leur donne le plan.
 *
 * La colonne du contrôle visuel s'appelle « vi » en base, mais le bureau d'études imprime
 * « VT » en tête de sa liste soudure (plan_table.php), et c'est le mot de l'atelier. L'écran
 * dit donc VT.
 */
const SOUDURE_CONTROLES = ['rt' => 'RT', 'vi' => 'VT', 'pt' => 'PT'];

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
    //
    // La signature du visa vient avec : c'est elle que le plan dessine dans la case VISA.
    [$colonneSignature, $jointureSignature] = signatureLectureCordons($db);
    $stmt = $db->prepare("SELECT s.*,
            TRIM(CONCAT(u.prenom, ' ', u.name)) AS soudeur_nom,
            TRIM(CONCAT(v.prenom, ' ', v.name)) AS visa_nom" . $colonneSignature . "
        FROM plan_soudure s
        LEFT JOIN Utilisateur u ON u.id = s.id_soudeur
        LEFT JOIN Utilisateur v ON v.id = s.vise_par" . $jointureSignature . "
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
            'vise_par' => null, 'visa_nom' => null, 'signature_dessin' => null,
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
 * Tous les cordons relevés sur un plan, feuilles et pages confondues.
 *
 * L'encadrement les remplit page par page dans la fenêtre du plan, en regardant le dessin.
 * Mais pour savoir si l'atelier a travaillé — et sur quoi — il faut les voir ensemble, et
 * sans ouvrir quinze feuilles. C'est à quoi sert cette lecture : elle ne sert pas à saisir.
 *
 * @return list<array<string, mixed>> chaque ligne porte le nom de sa feuille et sa page
 */
function soudureRelevees(PDO $db, int $elementId): array
{
    $stmt = $db->prepare("SELECT s.*,
            TRIM(CONCAT(u.prenom, ' ', u.name)) AS soudeur_nom,
            TRIM(CONCAT(v.prenom, ' ', v.name)) AS visa_nom,
            d.cle_document, d.pages, d.numeros_ligne
        FROM plan_soudure s
        LEFT JOIN Utilisateur u ON u.id = s.id_soudeur
        LEFT JOIN Utilisateur v ON v.id = s.vise_par
        LEFT JOIN element_atelier_document d ON d.id = s.id_document
        WHERE s.id_element = ?
        ORDER BY d.id, s.page, LENGTH(s.numero), s.numero");
    $stmt->execute([$elementId]);
    return $stmt->fetchAll();
}

/**
 * La feuille d'un cordon, dite comme le chef la lit sur le dessin : le numéro de ligne ISO
 * de sa page quand le cahier le porte (« YU-PRE-101 »), le nom du fichier sinon ; et la
 * page, quand la feuille en a plusieurs.
 *
 * Les tableaux de cordons écrivaient « CAHIER_ISO · p. 1 » sur chaque ligne : un nom de
 * fichier que personne ne lit sur le plan, et des numéros 1, 2, 3 qui revenaient d'une page
 * à l'autre sans dire de quelle ligne on parlait. Ils se rangent maintenant sous cette
 * feuille, une fois (« cle » change quand on passe à la suivante).
 *
 * @param array<string, mixed> $cordon une ligne de soudureRelevees()
 * @return array{cle: string, ligne: string, page: ?int}
 */
function soudureFeuille(array $cordon): array
{
    require_once __DIR__ . '/plan_document.php';
    $page = max(1, (int) ($cordon['page'] ?? 1));
    $fichier = preg_replace('/\.pdf$/i', '', preg_replace('/^[a-f0-9]{32}-/', '', (string) ($cordon['cle_document'] ?? '')));
    $ligne = planDocumentNumeros($cordon['numeros_ligne'] ?? null)[$page] ?? ($fichier !== '' ? $fichier : 'Feuille sans nom');
    return [
        'cle' => (int) ($cordon['id_document'] ?? 0) . ':' . $page,
        'ligne' => $ligne,
        'page' => (int) ($cordon['pages'] ?? 1) > 1 ? $page : null,
    ];
}

/**
 * L'en-tête d'un groupe de cordons, dans un tableau qui les range par feuille : une
 * cellule qui couvre la ligne, le numéro de ligne ISO, la page en plus sourd.
 *
 * @param array{ligne: string, page: ?int} $feuille soudureFeuille()
 */
function soudureFeuilleEntete(array $feuille, int $colonnes): string
{
    return '<tr class="soudure-feuille"><th colspan="' . $colonnes . '" scope="rowgroup">'
        . prefaEscape($feuille['ligne'])
        . ($feuille['page'] !== null ? '<span class="soudure-feuille-page">page ' . $feuille['page'] . '</span>' : '')
        . '</th></tr>';
}

/**
 * Les plans ouverts au travail, avec qui y est affecté.
 *
 * C'est la matière de l'écran de suivi des soudures : l'encadrement y regarde l'atelier
 * entier d'un coup, au lieu d'ouvrir une demande après l'autre. Les comptes de cordons ne
 * sont pas faits ici — ils viennent d'avancement.php, qui lit le plan — pour que cette
 * fonction reste une lecture de base et rien d'autre.
 *
 * @return list<array<string, mixed>> chaque plan porte ses affectations actives
 */
function soudureSuiviPlans(PDO $db): array
{
    $plans = $db->query("SELECT e.id, e.reference, e.libelle, e.id_demande, d.nom_affaire, d.urgent
        FROM element_atelier e
        JOIN demande_prefabrication d ON d.id = e.id_demande
        WHERE d.id_statut = 2
        ORDER BY e.id_demande DESC, e.reference")->fetchAll();
    if (!$plans) return [];

    $stmt = $db->prepare("SELECT a.id, a.id_utilisateur, a.avancement, a.revision, a.type_affectation,
            TRIM(CONCAT(u.prenom, ' ', u.name)) AS personne, u.prenom, u.name
        FROM affectation_atelier a JOIN Utilisateur u ON u.id = a.id_utilisateur
        WHERE a.id_element = ? AND a.actif = 1
        ORDER BY a.type_affectation");
    foreach ($plans as &$plan) {
        $stmt->execute([$plan['id']]);
        $plan['affectations'] = $stmt->fetchAll();
    }
    unset($plan);
    return $plans;
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

    // Les visas déjà posés, retrouvés par numéro de soudure après la réécriture — et la
    // signature qui va avec, quand la base en connaît (signature.php).
    $visas = [];
    $signatures = [];
    foreach (soudureLignes($db, $elementId, $documentId, $page) as $ancienne) {
        if ($ancienne['vise_par']) {
            $visas[$ancienne['numero']] = [$ancienne['vise_par'], $ancienne['date_visa']];
        }
        if (!empty($ancienne['id_signature'])) $signatures[$ancienne['numero']] = (int) $ancienne['id_signature'];
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

        // La signature revient avec son visa. Elle se repose à part : sa colonne n'existe
        // qu'une fois la migration passée, et seules des lignes visées en portent une.
        if ($signatures) {
            $resigner = $db->prepare('UPDATE plan_soudure SET id_signature = ?
                WHERE id_element = ? AND id_document = ? AND page = ? AND numero = ?');
            foreach ($signatures as $numero => $signature) {
                $resigner->execute([$signature, $elementId, $documentId, $page, (string) $numero]);
            }
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
        . '<th scope="col" class="soudure-controle">VT</th><th scope="col" class="soudure-controle">PT</th>'
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
        . ' aria-label="Contrôle ' . SOUDURE_CONTROLES[$nom] . ' conforme">';

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

    // La case VISA garde la signature sous le nom : le plan, qui suit la saisie, la
    // redessine à chaque frappe (plan-viewer.js, valeursSaisies).
    $signature = signatureDessin($ligne['signature_dessin'] ?? null);
    $signatureVue = $signature ? ' data-signature="' . prefaEscape(json_encode(signatureVue($signature))) . '"' : '';

    return '<tr class="tableau-ligne' . ($venueDuPointage ? ' soudure-du-pointage' : '') . '">'
        . '<td><input type="text" name="numero" value="' . $valeur('numero') . '" maxlength="20" aria-label="Numéro de soudure"'
        . ($venueDuPointage ? ' title="Numéro proposé pour ce pointage. Corrigez-le si la soudure en porte un autre, puis enregistrez."' : '') . '>'
        . ($origine ? '<input type="hidden" name="pointage" value="' . (int) $origine . '">' : '') . '</td>'
        . '<td>' . ($soudeurFige ?? '<select name="soudeur" aria-label="Soudeur">' . $options . '</select>') . '</td>'
        . '<td><input type="date" name="date" value="' . $valeur('date_soudure') . '" aria-label="Date de soudage"></td>'
        . '<td><input type="text" name="type" value="' . $valeur('type_soudure') . '" maxlength="30" aria-label="Type de soudure" placeholder="TIG…"></td>'
        . '<td class="soudure-controle">' . $controle('rt', (string) ($ligne['rt'] ?? '')) . '</td>'
        . '<td class="soudure-controle">' . $controle('vi', (string) ($ligne['vi'] ?? '')) . '</td>'
        . '<td class="soudure-controle">' . $controle('pt', (string) ($ligne['pt'] ?? '')) . '</td>'
        . '<td class="soudure-visa"' . $signatureVue . '>' . prefaEscape($visa !== '' ? $visa : '—') . '</td>'
        . '<td><button type="button" class="tableau-retirer" aria-label="Retirer la soudure ' . $valeur('numero') . '">✕</button></td>'
        . '</tr>';
}

/**
 * Les contrôles à poser en validant : une soudure par ligne, et ses trois contrôles en
 * boutons côte à côte — RT, VT, PT — qu'on touche pour dire « OK ».
 *
 * Il s'écrit dans le formulaire de validation lui-même : les cases partent avec « Valider »,
 * et seulement avec lui — refuser ne pose rien. Le champ « controles_vus » dit au serveur
 * que le chef a eu la liste sous les yeux, même vide ; c'est ce qui l'autorise à exiger
 * qu'elle n'ait pas changé (avancementViserCordons()).
 *
 * Un contrôle coché est une croix sur le plan : il est OK. Un contrôle laissé n'écrit rien.
 * Ils arrivent cochés si l'encadrement les avait déjà posés dans la fenêtre du plan.
 *
 * Valider ces soudures, c'est aussi les viser : le pied du formulaire le dit, au-dessus du
 * bouton qui le fait (avancementDecisionFormulaire()).
 *
 * Refait le 6 novembre 2026, deux fois. Un tableau étalé sur toute la carte laissait un vide
 * de mille pixels entre le numéro et ses cases ; serré, il gardait une grammaire à lui —
 * en-têtes de colonnes, cases à cocher, bandeau d'en-tête — que rien d'autre n'a dans les
 * volets. Ce sont maintenant les lignes libellé / valeur et les boutons côte à côte des
 * « Étapes du plan », que l'atelier coche déjà : les soudures rangées sous leur feuille
 * (soudureFeuille()), le numéro et son procédé à gauche, RT · VT · PT à droite.
 *
 * Les cases restent des cases à cocher dans le balisage — le clavier et les lecteurs d'écran
 * les lisent comme telles — ; seul leur dessin change.
 *
 * @param list<array<string, mixed>> $cordons ce que rend avancementCordonsAValider()
 */
function soudureControlesRendu(array $cordons): string
{
    $html = '<input type="hidden" name="controles_vus" value="1">';
    if (!$cordons) return $html;

    // Trois boutons côte à côte, comme l'état d'une étape. Coché, un contrôle prend le vert
    // du succès et sa coche : c'est « OK ».
    $coche = '<svg class="soudure-controle-coche" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3.5 8.5 3 3 6-7" /></svg>';
    $boutons = static function (?int $id, array $etats, string $nom) use ($coche): string {
        $html = '<span class="mes-plans-segments soudure-controles-boutons" role="group" aria-label="' . $nom . '">';
        foreach (SOUDURE_CONTROLES as $cle => $libelle) {
            // « Toutes les soudures » n'a pas de nom : elle ne part pas avec le formulaire,
            // seules les cases des soudures comptent (atelier.js).
            $champ = $id === null
                ? 'class="soudure-croix soudure-controles-tout" data-controle="' . $cle . '"'
                : 'class="soudure-croix" name="controles[' . $cle . '][]" value="' . $id . '" data-controle="' . $cle . '"';
            $html .= '<label><input type="checkbox" ' . $champ . ($etats[$cle] ? ' checked' : '')
                . ' aria-label="' . $nom . ' : ' . $libelle . ' OK"><span>' . $coche . $libelle . '</span></label>';
        }
        return $html . '</span>';
    };

    $html .= '<div class="soudure-controles">'
        . '<div class="soudure-controles-entete">'
        . '<h4 class="soudure-controles-titre">Contrôles</h4>'
        . '<p class="soudure-controles-aide">Touchez ce qui est OK : une croix sur le plan. Touchez un N° pour le voir sur le PDF.</p>'
        . '</div>'
        . '<dl class="mes-plans-proprietes soudure-controles-lignes">';

    // Un cahier compte vite vingt soudures : cocher VT sur chacune, c'est vingt touchers pour
    // dire une seule chose. Une ligne les coche toutes ; on retire ensuite celles qui ne
    // passent pas.
    if (count($cordons) > 1) {
        $tous = [];
        foreach (SOUDURE_CONTROLES as $cle => $libelle) {
            $tous[$cle] = !array_filter($cordons, static fn (array $cordon): bool => trim((string) $cordon[$cle]) === '');
        }
        $html .= '<div class="soudure-controles-tous"><dt>Toutes les soudures</dt><dd>'
            . $boutons(null, $tous, 'Toutes les soudures') . '</dd></div>';
    }

    // « N° 1 » ne disait pas où était la soudure (6 novembre 2026). La feuille et le numéro se
    // touchent : la fenêtre du plan s'ouvre sur la feuille, la soudure cerclée sur le dessin
    // (prefa-interactions.js, [data-voir-soudure] ; actions/plan_final_lecteur.php).
    $voir = static function (array $cordon, ?string $numero = null): string {
        if (empty($cordon['id_document']) || empty($cordon['id_element'])) return '';
        return ' data-voir-soudure data-element="' . (int) $cordon['id_element'] . '" data-document="' . (int) $cordon['id_document'] . '"'
            . ' data-page="' . max(1, (int) ($cordon['page'] ?? 1)) . '"'
            . ($numero !== null ? ' data-numero="' . prefaEscape($numero) . '"' : '');
    };
    $oeil = '<svg class="icon soudure-voir-oeil" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12s-3.5 6.5-9.5 6.5S2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/></svg>';

    $groupe = null;
    foreach ($cordons as $cordon) {
        $feuille = soudureFeuille($cordon);
        if ($feuille['cle'] !== $groupe) {
            // La feuille, une fois, au-dessus de ses soudures : le numéro de ligne ISO qu'on lit
            // sur le dessin, la page en sourd. Elle se touche : la feuille s'ouvre.
            $titre = prefaEscape($feuille['ligne'])
                . ($feuille['page'] !== null ? '<span class="soudure-feuille-page">page ' . $feuille['page'] . '</span>' : '');
            $attributs = $voir($cordon);
            $html .= '<div class="soudure-controles-feuille"><dt>'
                . ($attributs !== ''
                    ? '<button type="button" class="soudure-voir"' . $attributs . ' title="Ouvrir le PDF de la feuille">' . $titre
                    . '<span class="soudure-voir-libelle">' . $oeil . 'Ouvrir le PDF</span></button>'
                    : $titre)
                . '</dt><dd></dd></div>';
            $groupe = $feuille['cle'];
        }
        $id = (int) $cordon['id'];
        $numero = prefaEscape((string) $cordon['numero']);
        $etats = [];
        foreach (SOUDURE_CONTROLES as $cle => $libelle) $etats[$cle] = trim((string) $cordon[$cle]) !== '';
        $detail = implode(' · ', array_filter([
            trim((string) ($cordon['type_soudure'] ?? '')),
            $cordon['date_soudure'] ? date('d/m/Y', strtotime((string) $cordon['date_soudure'])) : '',
        ], 'strlen'));
        // Le même numéro revient d'une feuille à l'autre : le nom des cases dit laquelle.
        $attributs = $voir($cordon, (string) $cordon['numero']);
        $nom = '<strong>N° ' . $numero . '</strong>';
        $html .= '<div><dt>' . ($attributs !== ''
                ? '<button type="button" class="soudure-voir"' . $attributs . ' title="Voir la soudure N° ' . $numero . ' sur le PDF">' . $nom . $oeil . '</button>'
                : $nom)
            . ($detail !== '' ? '<span class="soudure-controles-detail">' . prefaEscape($detail) . '</span>' : '')
            . '<input type="hidden" name="cordons[]" value="' . $id . '"></dt><dd>'
            . $boutons($id, $etats, 'Soudure ' . $numero . ' (' . prefaEscape($feuille['ligne']) . ')') . '</dd></div>';
    }
    return $html . '</dl></div>';
}
