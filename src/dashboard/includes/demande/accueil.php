<?php

require_once __DIR__ . '/prefa_visibilite.php';
require_once __DIR__ . '/pv.php';
require_once __DIR__ . '/../atelier/avancement.php';
// Pour accueilIcone() : pages/accueil.php n'ouvre pas le menu, lui.
require_once __DIR__ . '/../menu.php';

/**
 * Ce que l'accueil montre : quelques chiffres, et ce qui a bougé aujourd'hui.
 *
 * La page saluait et s'arrêtait là. Or c'est l'écran qu'on voit en arrivant, et chacun y
 * cherche la même chose : combien en attente, combien pour moi, qu'est-ce qui a changé
 * depuis hier. Les chiffres sont donc ceux du métier de la personne — ce qu'un chargé de
 * production doit valider n'intéresse pas le soudeur — et chaque tuile ouvre l'écran où
 * l'on agit dessus.
 *
 * Les demandes comptées sont celles que la personne a le droit de voir : la règle est
 * celle de la liste et de l'API, prefaDemandesVisibles().
 */

/**
 * Le jour en cours, en bornes UTC.
 *
 * Les dates sont rangées en UTC et lues à l'heure de Paris. « Aujourd'hui » ne peut donc
 * pas se demander à la base avec CURDATE() : à minuit passé, elle répondrait la veille.
 *
 * @return array{0: string, 1: string, 2: string} début, fin, et la date du jour à Paris
 */
function accueilJour(): array
{
    $paris = new DateTimeZone('Europe/Paris');
    $jour = new DateTimeImmutable('today', $paris);
    return [
        $jour->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        $jour->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        $jour->format('Y-m-d'),
    ];
}

/**
 * Les tuiles de chiffres, dans l'ordre d'affichage.
 *
 * @return list<array{valeur:int, libelle:string, detail:string, ouvre:?string, alerte:bool}>
 */
function accueilTuiles(PDO $db, Utilisateur $utilisateur): array
{
    $visible = prefaDemandesVisibles($utilisateur);

    /** Compte les demandes visibles qui remplissent en plus cette condition. */
    $demandes = static function (string $condition = '', array $parametres = []) use ($db, $visible): int {
        $lecture = $db->prepare('SELECT COUNT(*) FROM demande_prefabrication d' . $visible['where'] . $condition);
        foreach ($visible['params'] + $parametres as $nom => $valeur) $lecture->bindValue(':' . $nom, $valeur);
        $lecture->execute();
        return (int) $lecture->fetchColumn();
    };

    // « cle » nomme la tuile pour le flux d'événements, qui y repose un compteur relu en
    // base. Les tuiles qu'il ne met pas à jour n'en portent pas.
    $tuile = static fn (int $valeur, string $libelle, string $detail, ?string $ouvre = null, bool $alerte = false, ?string $cle = null): array =>
        compact('valeur', 'libelle', 'detail', 'ouvre', 'alerte', 'cle');

    if ($utilisateur->est(Role::ChargeDeProduction)) {
        $enAttente = $demandes(' AND d.id_statut = 1');
        $urgentes = $demandes(' AND d.id_statut = 1 AND d.urgent = 1');
        return [
            $tuile($enAttente, 'À valider', 'demandes en attente de votre décision', 'btn-list-prefa', $enAttente > 0, 'en-attente'),
            $tuile($urgentes, 'Urgentes', 'parmi celles en attente', 'btn-urgent-prefa', $urgentes > 0, 'urgentes'),
            $tuile(accueilPvAFaire($db), 'PV à renseigner', 'sur les demandes validées', 'btn-pv'),
            $tuile((int) $db->query('SELECT COUNT(*) FROM affaire')->fetchColumn(), 'Affaires', 'chantiers ouverts', 'btn-affaires'),
        ];
    }

    if ($utilisateur->est(Role::ChefDAtelier)) {
        // « Sans plan » remplace « À prendre en charge » : c'est le même signal — personne
        // n'a encore commencé — mais lu sur le travail réel plutôt que sur une case cochée.
        $sansPlan = $demandes(' AND d.id_statut = 2 AND NOT EXISTS
            (SELECT 1 FROM element_atelier e WHERE e.id_demande = d.id)');
        // L'avancement déclaré par l'atelier n'avance à rien tant qu'il n'est pas tranché,
        // et il se trouve au fond d'un plan : c'est ici qu'on apprend qu'il y en a.
        $aValider = avancementAValider($db);
        return [
            $tuile($aValider, 'Avancements à valider', 'déclarés par l’atelier', 'btn-plans-iso', $aValider > 0),
            $tuile($sansPlan, 'Sans plan', 'demandes validées où rien n’est préparé', 'btn-plans-iso', $sansPlan > 0),
            $tuile($demandes(' AND d.id_statut = 2'), 'En atelier', 'demandes validées, ouvertes au travail', 'btn-plans-iso'),
            $tuile(accueilPvAFaire($db), 'PV à renseigner', 'sur les demandes validées', 'btn-pv'),
        ];
    }

    if ($utilisateur->est(Role::PersonnelAtelier)) {
        // Les mêmes affectations que l'écran « Mes plans » — actives, sur une demande
        // validée —, et des plans comptés comme des plans : un plan confié pour le montage
        // et pour le soudage en faisait deux, et la tuile disait 3 quand la liste en
        // montrait 2.
        $courantes = 'FROM affectation_atelier a JOIN element_atelier e ON e.id = a.id_element
            JOIN demande_prefabrication d ON d.id = e.id_demande
            WHERE a.id_utilisateur = ? AND a.actif = 1 AND d.id_statut = 2';
        $actives = $db->prepare('SELECT COUNT(DISTINCT a.id_element) ' . $courantes);
        $actives->execute([$utilisateur->id]);
        $aFinir = $db->prepare('SELECT COUNT(*) ' . $courantes . ' AND COALESCE(a.avancement, 0) < 100');
        $aFinir->execute([$utilisateur->id]);
        // Les cordons qu'il a déclarés : c'est son travail, et c'est ce qui fait avancer
        // ses plans. Les heures ne sont plus comptées — le soudeur renseigne des soudures.
        $cordons = $db->prepare("SELECT COUNT(*) FROM plan_soudure
            WHERE id_soudeur = ? AND (TRIM(type_soudure) <> '' OR date_soudure IS NOT NULL)");
        $cordons->execute([$utilisateur->id]);
        return [
            $tuile((int) $actives->fetchColumn(), 'Mes plans', 'plans qui me sont confiés', 'btn-list-prefa'),
            $tuile((int) $aFinir->fetchColumn(), 'À terminer', 'travaux sous les 100 %', 'btn-list-prefa'),
            $tuile((int) $cordons->fetchColumn(), 'Mes soudures', 'cordons que j’ai renseignés', 'btn-soudures'),
        ];
    }

    // Chargé d'affaire.
    $affaires = $db->prepare('SELECT COUNT(*) FROM affaire WHERE id_charge_affaire = ?');
    $affaires->execute([$utilisateur->id]);
    return [
        // « mes-affaires » : le flux la remet à jour dès qu'une affaire est confiée — sans
        // quoi l'alerte arrivait et la tuile restait sur l'ancien chiffre jusqu'au rechargement.
        $tuile((int) $affaires->fetchColumn(), 'Mes affaires', 'chantiers qui me sont confiés', 'btn-list-prefa', false, 'mes-affaires'),
        $tuile($demandes(' AND d.id_statut = 1'), 'En attente', 'demandes pas encore validées', 'btn-list-prefa'),
        $tuile($demandes(' AND d.id_statut = 2'), 'Validées', 'demandes parties en atelier', 'btn-list-prefa'),
        $tuile($demandes(' AND d.id_statut = 3'), 'Refusées', 'demandes à reprendre', 'btn-list-prefa', $demandes(' AND d.id_statut = 3') > 0),
    ];
}

/**
 * Le dessin d'une tuile : celui de l'écran qu'elle ouvre.
 *
 * Une tuile porte déjà « ouvre », l'identifiant du bouton de menu qu'elle déclenche —
 * « btn-pv » pour l'entrée « pv ». On reprend donc l'icône du menu au lieu d'en tenir une
 * seconde liste ici : elle aurait divergé au premier écran redessiné, et la tuile aurait
 * montré autre chose que la page où elle mène.
 *
 * Une tuile sans cible n'a pas d'icône, et la vue n'en dessine alors aucune.
 */
function accueilIcone(?string $ouvre): string
{
    static $icones = null;
    if ($icones === null) {
        $icones = [];
        foreach (dashboardMenu() as $entree) {
            $icones[$entree['id']] = $entree['icone'] ?? '';
        }
    }
    if (!$ouvre) return '';
    return $icones[preg_replace('/^btn-/', '', $ouvre)] ?? '';
}

/** Les PV encore à faire : la même règle que l'écran des PV, écrite une seule fois. */
function accueilPvAFaire(PDO $db): int
{
    return pvAFaireTotal($db);
}

/**
 * Ce qui a bougé aujourd'hui, du plus récent au plus ancien.
 *
 * Seulement sur les demandes que la personne a le droit de voir : l'accueil ne doit pas
 * raconter ce que la liste cache.
 *
 * @return list<array{heure:string, quoi:string, texte:string}>
 */
function accueilActivite(PDO $db, Utilisateur $utilisateur, int $maximum = 8): array
{
    [$debutJour, $finJour] = accueilJour();
    $visible = prefaDemandesVisibles($utilisateur);

    // Trois sources interrogées séparément et fondues ici, plutôt qu'un UNION : la clause
    // de visibilité et les bornes du jour y reviendraient trois fois, donc les mêmes
    // marqueurs nommés trois fois — ce que MySQL refuse dès qu'on ne simule plus les
    // requêtes préparées. Les listes font huit lignes : les trier en PHP ne coûte rien.
    $sources = [
        'SELECT d.date_creation AS quand, \'depot\' AS quoi, d.nom_affaire AS sujet,
                TRIM(CONCAT(u.prenom, \' \', u.name)) AS qui
         FROM demande_prefabrication d
         LEFT JOIN Utilisateur u ON u.id = d.idUsers' . $visible['where'] . '
           AND d.date_creation >= :debut AND d.date_creation < :fin',

        'SELECT d.date_validation AS quand, IF(d.id_statut = 3, \'refus\', \'validation\') AS quoi,
                d.nom_affaire AS sujet, TRIM(CONCAT(v.prenom, \' \', v.name)) AS qui
         FROM demande_prefabrication d
         LEFT JOIN Utilisateur v ON v.id = d.valide_par' . $visible['where'] . '
           AND d.date_validation >= :debut AND d.date_validation < :fin',

        'SELECT p.date_maj AS quand, \'pv\' AS quoi, d.nom_affaire AS sujet,
                TRIM(CONCAT(m.prenom, \' \', m.name)) AS qui
         FROM pv_prefabrication p
         JOIN demande_prefabrication d ON d.id = p.id_demande
         LEFT JOIN Utilisateur m ON m.id = p.maj_par' . $visible['where'] . '
           AND p.date_maj >= :debut AND p.date_maj < :fin AND p.statut <> \'a_faire\'',
    ];

    $evenements = [];
    foreach ($sources as $sql) {
        $lecture = $db->prepare($sql . ' ORDER BY quand DESC LIMIT ' . max(1, $maximum));
        foreach ($visible['params'] + ['debut' => $debutJour, 'fin' => $finJour] as $nom => $valeur) {
            $lecture->bindValue(':' . $nom, $valeur);
        }
        $lecture->execute();
        foreach ($lecture->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            if ($ligne['quand'] !== null) $evenements[] = $ligne;
        }
    }

    usort($evenements, static fn (array $a, array $b): int => strcmp((string) $b['quand'], (string) $a['quand']));
    $evenements = array_slice($evenements, 0, $maximum);

    $paris = new DateTimeZone('Europe/Paris');
    return array_map(static function (array $ligne) use ($paris): array {
        $quand = new DateTimeImmutable((string) $ligne['quand'], new DateTimeZone('UTC'));
        $qui = trim((string) $ligne['qui']);
        $sujet = trim((string) $ligne['sujet']) ?: 'sans affaire';
        return [
            'heure' => $quand->setTimezone($paris)->format('H:i'),
            'quoi' => (string) $ligne['quoi'],
            'texte' => match ($ligne['quoi']) {
                'depot' => 'Demande déposée sur ' . $sujet . ($qui ? ' par ' . $qui : ''),
                'validation' => 'Demande validée sur ' . $sujet . ($qui ? ' par ' . $qui : ''),
                'refus' => 'Demande refusée sur ' . $sujet . ($qui ? ' par ' . $qui : ''),
                default => 'PV renseigné sur ' . $sujet . ($qui ? ' par ' . $qui : ''),
            },
        ];
    }, $evenements);
}

/**
 * La date du jour en toutes lettres : « Lundi 13 octobre 2026 ».
 *
 * Écrite à la main plutôt qu'avec IntlDateFormatter : l'extension intl n'est pas dans
 * l'image, et douze mois plus sept jours ne valent pas une dépendance.
 */
function accueilDateDuJour(): string
{
    $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    $jour = new DateTimeImmutable('now', new DateTimeZone('Europe/Paris'));
    return $jours[(int) $jour->format('w')] . ' ' . (int) $jour->format('j') . ' '
        . $mois[(int) $jour->format('n') - 1] . ' ' . $jour->format('Y');
}
