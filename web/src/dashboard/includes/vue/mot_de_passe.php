<?php
/**
 * Son mot de passe, dans Paramètres → Compte : une ligne, dont la commande dépend d'où il en
 * est.
 *
 * Il ne s'y change pas. On demande au chargé de production de le réinitialiser, et l'on
 * choisit le nouveau en se reconnectant : l'écran de connexion est le seul endroit où un mot
 * de passe se tape. Le chargé de production, qui réinitialise ceux des autres, réinitialise
 * le sien lui-même.
 *
 * La même ligne sert à la fenêtre et à la réponse de l'action (actions/mon_mot_de_passe.php),
 * qui la remplace.
 */

require_once __DIR__ . '/../../../api/User.php';

/**
 * Où en est le mot de passe de cette personne.
 *
 * @return array{reinitialise: bool, demandeLe: ?string, enBase: bool}
 */
function motDePasseEtat(PDO $db, int $id): array
{
    $enBase = User::reinitialisationsEnBase($db);
    $lecture = $db->prepare("SELECT password = '' AS reinitialise, "
        . ($enBase ? 'reinitialisation_demandee_le' : 'NULL') . ' AS demande_le FROM Utilisateur WHERE id = ?');
    $lecture->execute([$id]);
    $ligne = $lecture->fetch(PDO::FETCH_ASSOC) ?: ['reinitialise' => 0, 'demande_le' => null];
    return ['reinitialise' => (bool) $ligne['reinitialise'], 'demandeLe' => $ligne['demande_le'], 'enBase' => $enBase];
}

/** La ligne « Changer le mot de passe », et sa commande. */
function motDePasseParametre(array $etat, bool $administre): string
{
    [$aide, $commande] = match (true) {
        // Réinitialisé pendant qu'on était connecté : il ne reste qu'à se reconnecter.
        $etat['reinitialise'] => [
            'Réinitialisé : choisissez le nouveau en vous reconnectant.',
            '<a class="parametres-bouton" href="../auth/logout.php">Se reconnecter</a>',
        ],
        $administre => [
            'Réinitialisez-le : vous serez déconnecté, et choisirez le nouveau en vous reconnectant.',
            '<button type="button" class="parametres-bouton" data-mdp="reinitialiser">Réinitialiser</button>',
        ],
        $etat['demandeLe'] !== null => [
            'Demandé le ' . prefaFormatDate($etat['demandeLe'], true)
                . '. Une fois réinitialisé, vous choisirez le nouveau en vous reconnectant.',
            '<button type="button" class="parametres-bouton" disabled>Demandé</button>',
        ],
        !$etat['enBase'] => [
            'La demande arrive avec la migration 2026-11-02_mot_de_passe, pas encore passée sur ce serveur :'
                . ' demandez-le de vive voix au chargé de production.',
            '',
        ],
        default => [
            'Le chargé de production le réinitialise à votre demande, puis vous choisissez le nouveau en vous reconnectant.',
            '<button type="button" class="parametres-bouton" data-mdp="demander">Demander</button>',
        ],
    };
    return '<div class="parametre">'
        . '<div class="parametre-texte"><span class="parametre-titre">Changer le mot de passe</span>'
        . '<p class="parametre-aide">' . prefaEscape($aide) . '</p></div>'
        . $commande
        . '</div>';
}
