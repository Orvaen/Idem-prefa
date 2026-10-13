<?php

/**
 * La session : qui est connecté, et le jeton qui protège les formulaires.
 *
 * Elle ne retient que deux choses — un identifiant et un jeton. Le nom, le prénom, le
 * courriel et le rôle étaient recopiés dedans à la connexion, puis relus un peu partout :
 * ils devenaient faux dès qu'un compte était renommé ou changeait de rôle, et personne ne
 * s'en apercevait avant la déconnexion. Ils se relisent maintenant en base à chaque
 * requête, une fois, et le reste de l'application ne touche plus à $_SESSION.
 *
 * La liste des comptes mémorisés fait exception : elle n'appartient à personne en
 * particulier, c'est le navigateur qui la porte, et elle n'a pas d'autre place.
 */
final class Session
{
    /** Relu une fois par requête : treize fichiers demandent qui est là. */
    private static ?Utilisateur $utilisateur = null;

    /** Ouvre la session du navigateur. Sans effet si elle l'est déjà. */
    public static function demarrer(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }

    public static function identifiant(): ?string
    {
        self::demarrer();
        $identifiant = $_SESSION['identifiant'] ?? null;
        return is_string($identifiant) && $identifiant !== '' ? $identifiant : null;
    }

    public static function connectee(): bool
    {
        return self::identifiant() !== null;
    }

    /** Connecte ce compte. L'authentification est faite ailleurs : ici on la retient. */
    public static function ouvrir(string $identifiant): void
    {
        self::demarrer();
        $_SESSION['identifiant'] = $identifiant;
        self::$utilisateur = null;
    }

    public static function fermer(): void
    {
        self::demarrer();
        $_SESSION = [];
        self::$utilisateur = null;
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'],
                $cookie['secure'], $cookie['httponly']);
        }
        session_destroy();
    }

    /**
     * La personne connectée, ou null — session vide, ou compte supprimé depuis.
     *
     * Le résultat est gardé pour la durée de la requête : la page du tableau de bord, ses
     * volets et ses actions le demandent chacun de leur côté.
     */
    public static function utilisateur(PDO $db): ?Utilisateur
    {
        if (self::$utilisateur !== null) return self::$utilisateur;
        $identifiant = self::identifiant();
        if ($identifiant === null) return null;
        return self::$utilisateur = Utilisateur::parIdentifiant($db, $identifiant);
    }

    // ------------------------------------------------------- le jeton des formulaires

    /** Posé à la première demande et gardé toute la session. */
    public static function jeton(): string
    {
        self::demarrer();
        return $_SESSION['prefa_csrf'] ??= bin2hex(random_bytes(32));
    }

    /** hash_equals et non === : la comparaison ne doit pas renseigner sur le jeton. */
    public static function jetonValide(?string $recu): bool
    {
        return hash_equals(self::jeton(), (string) $recu);
    }

    // ------------------------------------------------- les comptes mémorisés du poste

    /** @return list<string> les identifiants entre lesquels ce navigateur peut basculer */
    public static function comptes(): array
    {
        self::demarrer();
        $comptes = $_SESSION['comptes'] ?? [];
        return is_array($comptes) ? array_values(array_unique(array_filter($comptes, 'is_string'))) : [];
    }

    public static function retenirCompte(string $identifiant): void
    {
        self::demarrer();
        $_SESSION['comptes'] = array_values(array_unique(array_merge(self::comptes(), [$identifiant])));
    }

    public static function oublierCompte(string $identifiant): void
    {
        self::demarrer();
        $_SESSION['comptes'] = array_values(array_diff(self::comptes(), [$identifiant]));
    }

    /** Ne garde que les comptes qui existent encore, dans l'ordre où ils ont été mémorisés. */
    public static function limiterComptes(array $existants): void
    {
        self::demarrer();
        $_SESSION['comptes'] = array_values(array_intersect(self::comptes(), $existants));
    }
}
