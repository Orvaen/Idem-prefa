<?php

/**
 * Ce que l'API renvoie, toujours sous la même forme.
 *
 * Les trente-deux actions du tableau de bord répondent chacune à sa façon — { success,
 * message }, { success, redirect }, { success, cases, soudures, valeurs } — et le
 * JavaScript traite donc un cas par appel. Ici il n'y en a que deux : ça a marché, ou non.
 *
 *   { "ok": true,  "donnees": … }
 *   { "ok": false, "erreur": { "code": "…", "message": "…" } }
 *
 * Le code d'erreur est pour le programme — il ne change pas quand on reformule le
 * message — et le message est pour la personne devant l'écran.
 */
final class Reponse
{
    private function __construct(
        private readonly int $statut,
        private readonly array $corps,
    ) {}

    public static function succes(mixed $donnees = null, int $statut = 200): self
    {
        return new self($statut, ['ok' => true, 'donnees' => $donnees]);
    }

    public static function erreur(int $statut, string $code, string $message): self
    {
        return new self($statut, ['ok' => false, 'erreur' => ['code' => $code, 'message' => $message]]);
    }

    // Les erreurs qu'on écrit tous les jours, nommées une fois pour toutes.

    public static function nonConnecte(): self
    {
        return self::erreur(401, 'non_connecte', 'Veuillez vous reconnecter.');
    }

    public static function interdit(string $message = 'Vous n’avez pas accès à cette ressource.'): self
    {
        return self::erreur(403, 'interdit', $message);
    }

    public static function introuvable(string $message = 'Ressource introuvable.'): self
    {
        return self::erreur(404, 'introuvable', $message);
    }

    public static function methodeRefusee(array $permises): self
    {
        header('Allow: ' . implode(', ', $permises));
        return self::erreur(405, 'methode_refusee', 'Méthode non autorisée sur cette ressource.');
    }

    public static function demandeInvalide(string $message): self
    {
        return self::erreur(400, 'demande_invalide', $message);
    }

    public function envoyer(): never
    {
        http_response_code($this->statut);
        header('Content-Type: application/json; charset=utf-8');
        // Une API n'est jamais mise en cache par erreur : ses réponses dépendent de qui
        // la demande.
        header('Cache-Control: no-store');
        echo json_encode($this->corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
