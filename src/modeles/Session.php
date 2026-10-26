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
        $identifiant = is_string($identifiant) && $identifiant !== '' ? $identifiant : null;
        // Le journal des requêtes relit cette note sous le nom %{qui}n : une ligne
        // « 200 POST /dashboard/actions/save_atelier.php » ne dit rien tant qu'on ignore
        // de quel poste de l'atelier elle vient. Le rôle s'y ajoute dans utilisateur().
        if ($identifiant !== null) self::noterQui($identifiant);
        return $identifiant;
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
        // La requête qui ouvre la session n'a encore demandé qui est là à personne : sans
        // cette note, la seule ligne qui compte — celle de la connexion — sort sans compte.
        self::noterQui($identifiant);
    }

    public static function fermer(): void
    {
        self::demarrer();
        // Avant de vider : après, plus personne ne sait qui vient de partir.
        if (self::identifiant() !== null) Journal::noter('déconnexion');
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
        self::$utilisateur = Utilisateur::parIdentifiant($db, $identifiant);
        if (self::$utilisateur !== null) {
            self::noterQui($identifiant . '/' . (self::$utilisateur->role?->abrege() ?? 'sans-role'));
        }
        return self::$utilisateur;
    }

    /**
     * Dit au journal des requêtes qui est derrière celle-ci.
     *
     * apache_note n'existe que sous Apache : en ligne de commande — les scripts, les
     * migrations — il n'y a pas de requête à annoter, et Journal lit la session lui-même.
     */
    private static function noterQui(string $qui): void
    {
        if (!function_exists('apache_note')) return;
        // Sans ce garde-fou, « A.Admin/chef-atelier » redevient « A.Admin » : identifiant()
        // repasse treize fois par requête, utilisateur() une seule, et la dernière note
        // écrite est celle que le journal garde.
        if (str_contains((string) apache_note('qui'), '/')) return;
        apache_note('qui', $qui);
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
