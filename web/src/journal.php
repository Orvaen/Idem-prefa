<?php

/**
 * Le journal : ce qui sort dans « docker compose logs php ».
 *
 * Ce fichier est chargé avant chaque script — auto_prepend_file, posé dans le Dockerfile —
 * parce qu'il doit être en place avant la première ligne de code qui peut échouer, y
 * compris dans les pages et les actions qui ne touchent pas à la base.
 *
 * Deux choses l'ont rendu nécessaire.
 *
 * Les erreurs de PHP n'étaient notées nulle part : log_errors valait Off et display_errors
 * STDOUT, donc un avertissement partait dans la page. Dans une page entière on finissait
 * par le voir ; dans un fragment chargé en fetch ou dans une réponse JSON, il cassait la
 * réponse sans rien dire. Pire : il envoyait les en-têtes avant l'heure, PHP ne pouvait
 * plus répondre 500, et l'échec ressortait en 200 dans le journal des requêtes.
 *
 * Et la voie normale — error_log() — passe par Apache, qui échappe tout octet non ASCII
 * dans ses lignes : « Création » y devenait « Cr\xc3\xa9ation ». Une application écrite en
 * français y perd ses messages. On écrit donc dans php://stderr, déjà ouvert et qui ne
 * transforme rien ; /dev/stderr, lui, ne se rouvre pas : www-data n'a pas le droit sur le
 * tuyau de Docker, et PHP retombait silencieusement sur Apache.
 *
 * Une ligne, et une seule, par fait :
 *
 *   ** 09:12:04 php POST /auth/action.php  r.pierre  connexion de r.pierre (Chef d'atelier)
 *   !! 09:13:52 php POST /dashboard/actions/save_atelier.php  r.pierre/chef-atelier  Atelier — PDOException : …
 *
 * La marque de tête et les colonnes sont celles des requêtes, posées dans
 * docker/apache-journal.conf : ** ce qui s'est passé, !! ce qui a raté.
 */
final class Journal
{
    /** Le fuseau des écrans, pour que l'heure du journal soit celle de l'atelier. */
    private const FUSEAU = 'Europe/Paris';

    /** Ouvert une fois : php://stderr duplique le descripteur à chaque appel. */
    private static mixed $sortie = null;

    /** Un fait notable : une connexion, une demande validée. */
    public static function noter(string $message): void
    {
        self::ecrire('**', $message);
    }

    /**
     * Un ennui. L'exception vient en second : son nom, son message et l'endroit exact
     * valent mieux que le « Atelier: » tout seul que les blocs catch notaient avant.
     */
    public static function erreur(string $message, ?Throwable $erreur = null): void
    {
        if ($erreur !== null) {
            $message .= ' — ' . $erreur::class . ' : ' . $erreur->getMessage()
                . ' (' . basename($erreur->getFile()) . ':' . $erreur->getLine() . ')';
        }
        self::ecrire('!!', $message);
    }

    /** Le nom d'un niveau d'erreur de PHP, tel qu'on le cherche dans la documentation. */
    public static function niveau(int $code): string
    {
        return match ($code) {
            E_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            E_RECOVERABLE_ERROR => 'Error',
            default => 'Erreur ' . $code,
        };
    }

    private static function ecrire(string $marque, string $message): void
    {
        // Sur une seule ligne : une trace d'appels ou un message de MySQL en contient
        // plusieurs, et une ligne par fait est ce qui rend le journal lisible.
        $message = (string) preg_replace('/\s+/', ' ', trim($message));
        $heure = (new DateTimeImmutable('now', new DateTimeZone(self::FUSEAU)))->format('H:i:s');
        $ligne = sprintf("%s %s php %s  %s  %s\n", $marque, $heure, self::ou(), self::qui(), $message);

        self::$sortie ??= fopen('php://stderr', 'w');
        if (self::$sortie === false) {
            error_log($message); // Apache, accents échappés : mieux que de perdre la ligne.
            return;
        }
        fwrite(self::$sortie, $ligne);
    }

    /** D'où vient la ligne : la requête, ou le script en ligne de commande. */
    private static function ou(): string
    {
        if (PHP_SAPI === 'cli') return basename((string) ($_SERVER['argv'][0] ?? 'php'));
        return ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '?');
    }

    /**
     * Le compte connecté, lu dans $_SESSION et non par Session::identifiant() : celle-ci
     * ouvre la session si elle ne l'est pas, et un journal ne doit rien changer à ce qu'il
     * raconte. session_write_close() ferme le fichier mais laisse $_SESSION en place.
     */
    private static function qui(): string
    {
        $identifiant = $_SESSION['identifiant'] ?? null;
        return is_string($identifiant) && $identifiant !== '' ? $identifiant : '-';
    }
}

/**
 * Les avertissements de PHP arrivent ici plutôt que dans la page.
 *
 * Rendre la main avec true dit à PHP que c'est traité : sans cela il le noterait une
 * seconde fois, par Apache et sans les accents.
 */
set_error_handler(static function (int $niveau, string $message, string $fichier, int $ligne): bool {
    // E_USER_ERROR arrête le script. Le traiter ici le rendrait inoffensif : la suite du
    // code tournerait alors qu'elle n'aurait pas dû. On laisse PHP s'en charger.
    if ($niveau === E_USER_ERROR) return false;
    // Le @ de @unlink() retire le niveau du masque : l'appelant demande le silence.
    if (!(error_reporting() & $niveau)) return true;
    Journal::erreur(sprintf('%s : %s (%s:%d)', Journal::niveau($niveau), $message, basename($fichier), $ligne));
    return true;
});

/** Une exception qui remonte jusqu'ici : la page est perdue, le journal doit dire laquelle. */
set_exception_handler(static function (Throwable $erreur): void {
    Journal::erreur('Exception non rattrapée', $erreur);
    // Sans gestionnaire, PHP répondait 500 de lui-même ; avec le nôtre, c'est à nous de le
    // faire, sinon la requête ressort en 200 avec une page vide et le journal dit que tout
    // va bien.
    if (!headers_sent()) http_response_code(500);
    // Et il sortait en 255. Sans ce code, un script d'essai qui lève une exception rend 0
    // à celui qui l'a lancé : « run test » affichait l'échec et se déclarait vert.
    exit(255);
});
