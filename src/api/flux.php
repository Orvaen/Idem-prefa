<?php

/**
 * Le flux d'événements du tableau de bord.
 *
 * Le navigateur ouvre une connexion et la garde : c'est le serveur qui parle quand il a
 * quelque chose à dire. Il n'y a donc rien qui redemande « alors, du nouveau ? » toutes les
 * x secondes depuis chaque onglet ouvert dans l'atelier.
 *
 * Ce fichier existe en propre, à côté de index.php, et n'est pas routé par lui : une
 * réponse text/event-stream n'a ni l'enveloppe JSON ni la durée de vie des autres. Apache
 * sert les fichiers qui existent avant d'appliquer FallbackResource, l'adresse est donc
 * /api/flux.php.
 *
 * Ce qu'il faut savoir avant d'y toucher :
 *
 * - Ce qu'on annonce vient de la table evenement, écrite par les actions qui changent
 *   quelque chose. Le flux guettait d'abord le plus grand identifiant de demande : il
 *   voyait les dépôts et rien d'autre, puisqu'une validation modifie une ligne au lieu
 *   d'en créer une. Un journal donne un seul curseur pour toutes les sortes de nouvelles.
 *
 * - La boucle interroge quand même la base, une fois toutes les deux secondes. Sans file
 *   de messages — Redis, un démon — il n'y a pas d'autre moyen de savoir qu'une ligne est
 *   arrivée. Mais c'est une requête sur une clé primaire indexée, dans un processus qui
 *   dort le reste du temps, et surtout : c'est le serveur qui la fait, une fois, pour une
 *   connexion ouverte — pas chaque onglet qui redemande une page entière.
 *
 * - Le flux se coupe tout seul au bout d'une minute. EventSource se reconnecte seul, en
 *   rappelant où il en était par l'en-tête Last-Event-ID : on rend donc son processus
 *   Apache régulièrement, et on passe sous les délais des proxys, sans rien coder côté
 *   navigateur.
 *
 * - La session est refermée tout de suite après la lecture. Un fichier de session reste
 *   verrouillé tant que le script tourne : garder celui-ci ouvert une minute bloquerait
 *   toutes les autres requêtes de la même personne.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../dashboard/includes/demande/prefa_visibilite.php';
require_once __DIR__ . '/../dashboard/includes/demande/affaires_nouvelles.php';

/** Durée d'une connexion, en secondes. Au-delà, on raccroche et le navigateur rappelle. */
const FLUX_DUREE = 60;
/** Intervalle entre deux regards sur le journal. */
const FLUX_PAS = 2;
/** Au-delà de ce silence, on envoie un commentaire pour que rien ne coupe la connexion. */
const FLUX_BATTEMENT = 15;

Session::demarrer();
$db = new MyPDO(__DIR__ . '/../my_setting.ini');
$utilisateur = Session::utilisateur($db);
session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
// Pour les proxys qui mettent en tampon : sans cela, ils gardent les événements et ne
// relâchent qu'à la fermeture, ce qui annule tout l'intérêt du flux.
header('X-Accel-Buffering: no');

if (!$utilisateur) {
    // 204 et non 401 : EventSource ne se reconnecte pas sur un code hors 2xx, et c'est ce
    // qu'on veut ici — inutile de harceler le serveur depuis une page qu'il faut recharger.
    http_response_code(204);
    exit;
}

@ini_set('zlib.output_compression', '0');
@set_time_limit(0);
while (ob_get_level()) ob_end_flush();

/** Écrit un événement et le pousse tout de suite sur le fil. */
function fluxEcrire(string $type, array $donnees, ?int $id = null): void
{
    if ($id !== null) echo 'id: ', $id, "\n";
    echo 'event: ', $type, "\n";
    echo 'data: ', json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n\n";
    flush();
}

$visible = prefaDemandesVisibles($utilisateur);

/** Les compteurs que les pastilles et les tuiles affichent, relus en base. */
$compteurs = static function () use ($db, $visible, $utilisateur): array {
    $compte = static function (string $condition) use ($db, $visible): int {
        $lecture = $db->prepare('SELECT COUNT(*) FROM demande_prefabrication d' . $visible['where'] . $condition);
        foreach ($visible['params'] as $nom => $valeur) $lecture->bindValue(':' . $nom, $valeur);
        $lecture->execute();
        return (int) $lecture->fetchColumn();
    };

    return [
        'enAttente' => $compte(' AND d.id_statut = 1'),
        'urgentes' => $compte(' AND d.id_statut = 1 AND d.urgent = 1'),
        // Les affaires confiées pas encore vues : la pastille « +N » de l'entrée Accueil.
        'affairesNouvelles' => affairesNouvellesCompte($db, $utilisateur->id),
    ];
};

// Où en était ce navigateur. Il le rappelle de lui-même à chaque reconnexion ; au tout
// premier passage seulement, on part du dernier événement du journal — on annonce ce qui
// arrive après l'ouverture de la page, pas l'historique de la semaine.
//
// « Aucun curseur » et « curseur à zéro » sont deux choses différentes, et les confondre
// perdait des événements : un navigateur arrivé quand le journal était vide repartait avec
// zéro, le renvoyait à la reconnexion, et on le remettait à la fin du journal — tout ce qui
// s'était passé entre les deux connexions était sauté, définitivement.
$curseur = $_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['depuis'] ?? null;
$depuis = is_string($curseur) && ctype_digit($curseur)
    ? (int) $curseur
    : (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM evenement')->fetchColumn();

// À qui s'adresse un événement, en deux cas.
//
// Nommé — il porte un destinataire — il ne va qu'à cette personne : une affaire confiée ne
// regarde que celui à qui on la confie. Sinon, le droit de le voir est celui de voir la
// demande qu'il concerne, et la jointure reprend la règle de la liste sans la réécrire.
//
// La jointure sur la demande est donc externe : un événement nommé peut n'en concerner
// aucune. L'auteur est exclu dans les deux cas — ce qu'on vient de faire soi-même n'est pas
// une nouvelle.
//
// La référence est lue ici et non par prefaReference() : cette fonction garde sa table en
// mémoire statique, ce qui convient à une page affichée une fois, pas à un processus qui
// vit une minute — la demande qu'on vient d'annoncer n'y serait pas.
$journal = $db->prepare(
    'SELECT e.id, e.type, e.donnees, e.id_demande, r.reference, u.prenom, u.name
       FROM evenement e
       LEFT JOIN demande_prefabrication d ON d.id = e.id_demande
       LEFT JOIN prefa_reference r ON r.id_demande = d.id
       LEFT JOIN Utilisateur u ON u.id = d.idUsers
      WHERE (
              e.id_destinataire = :vis_pour
           OR (e.id_destinataire IS NULL AND d.id IS NOT NULL AND (' . $visible['condition'] . '))
            )
        AND e.id > :vis_depuis AND (e.id_acteur IS NULL OR e.id_acteur <> :vis_moi)
      ORDER BY e.id'
);

$debut = time();
$dernierMot = $debut;

// On annonce d'emblée où l'on en est : la page qui vient de se connecter cale ses
// compteurs sur la base, sans attendre qu'il se passe quelque chose.
fluxEcrire('compteurs', $compteurs(), $depuis);

while (time() - $debut < FLUX_DUREE) {
    if (connection_aborted()) break;

    foreach ($visible['params'] as $nom => $valeur) $journal->bindValue(':' . $nom, $valeur);
    $journal->bindValue(':vis_depuis', $depuis, PDO::PARAM_INT);
    $journal->bindValue(':vis_moi', $utilisateur->id, PDO::PARAM_INT);
    // Deux marqueurs pour la même valeur : l'émulation des requêtes préparées est coupée,
    // et un marqueur ne peut alors être lié qu'une fois.
    $journal->bindValue(':vis_pour', $utilisateur->id, PDO::PARAM_INT);
    $journal->execute();
    $lignes = $journal->fetchAll(PDO::FETCH_ASSOC);

    if ($lignes) {
        $etat = $compteurs();
        foreach ($lignes as $ligne) {
            $depuis = (int) $ligne['id'];
            $donnees = json_decode((string) $ligne['donnees'], true) ?: [];
            fluxEcrire($ligne['type'], [
                'demande' => (int) $ligne['id_demande'],
                'reference' => (string) ($ligne['reference'] ?? ''),
                'affaire' => (string) ($donnees['affaire'] ?? ''),
                'demandeur' => trim(($ligne['prenom'] ?? '') . ' ' . ($ligne['name'] ?? '')),
                'urgente' => (bool) ($donnees['urgente'] ?? false),
                'motif' => (string) ($donnees['motif'] ?? ''),
            ] + $etat, $depuis);
        }
        $dernierMot = time();
    } elseif (time() - $dernierMot >= FLUX_BATTEMENT) {
        // Un commentaire : le navigateur l'ignore, mais la connexion reste vivante.
        echo ": \n\n";
        flush();
        $dernierMot = time();
    }

    sleep(FLUX_PAS);
}
