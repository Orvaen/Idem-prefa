<?php

require_once __DIR__ . '/../User.php';

/**
 * POST   /api/session   ouvre une session  { "identifiant": "…", "mot_de_passe": "…" }
 * DELETE /api/session   la ferme
 *
 * C'est la seule ressource accessible sans être connecté — autrement on ne pourrait
 * jamais l'être.
 */
final class Connexion
{
    public static function repondre(Requete $requete, PDO $db): Reponse
    {
        return match ($requete->methode) {
            'POST' => self::ouvrir($requete, $db),
            'DELETE' => self::fermer(),
            default => Reponse::methodeRefusee(['POST', 'DELETE']),
        };
    }

    private static function ouvrir(Requete $requete, PDO $db): Reponse
    {
        $identifiant = trim((string) $requete->champ('identifiant'));
        $motDePasse = (string) $requete->champ('mot_de_passe');
        if ($identifiant === '' || $motDePasse === '') {
            return Reponse::demandeInvalide('Renseignez l’identifiant et le mot de passe.');
        }

        // Le même message dans les deux cas : dire « ce compte n'existe pas » apprendrait
        // à un inconnu quels identifiants sont valides.
        if (!(new User($db))->login($identifiant, $motDePasse)) {
            return Reponse::erreur(401, 'identifiants_incorrects', 'Identifiants incorrects.');
        }

        // Le numéro de session change à l'ouverture : sans cela, un numéro obtenu avant la
        // connexion resterait valable après, et c'est par là qu'on détourne une session.
        session_regenerate_id(true);

        $utilisateur = Session::utilisateur($db);
        return Reponse::succes([
            'identifiant' => $utilisateur?->identifiant,
            // Le jeton des formulaires, pour que le client puisse enchaîner sur une action
            // du tableau de bord sans recharger une page pour l'y lire.
            'jeton' => Session::jeton(),
        ]);
    }

    private static function fermer(): Reponse
    {
        Session::fermer();
        return Reponse::succes(null);
    }
}
