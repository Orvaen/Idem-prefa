<?php

/**
 * Où tourne l'application : en développement, ou en production.
 *
 * La distinction n'existe que pour ouvrir, en développement, des commodités qu'on ne veut
 * à aucun prix ailleurs — au premier rang desquelles la connexion à n'importe quel compte
 * sans son mot de passe.
 *
 * Elle se lit dans l'environnement du serveur, APP_ENV, et nulle part ailleurs : ni dans
 * une requête, ni dans un fichier du dossier web, ni d'après l'adresse demandée. Une
 * machine de développement se reconnaît à la façon dont on l'a démarrée, pas à ce que le
 * navigateur raconte.
 *
 * Le défaut est « production », et c'est le point important : docker-compose.yml est livré
 * avec APP_ENV: ${APP_ENV:-prod}. Le serveur du réseau, qui démarre sans rien de plus,
 * tourne donc en production. Le développement s'active en posant APP_ENV=dev dans un
 * fichier .env local, que .gitignore écarte — il ne peut pas partir par mégarde.
 */
final class Environnement
{
    public const DEVELOPPEMENT = 'dev';
    public const PRODUCTION = 'prod';

    /** Relu une fois : la valeur ne change pas pendant une requête. */
    private static ?string $courant = null;

    public static function courant(): string
    {
        if (self::$courant !== null) return self::$courant;

        $brut = getenv('APP_ENV');
        if ($brut === false) $brut = $_SERVER['APP_ENV'] ?? '';

        // Tout ce qui n'est pas exactement « dev » est de la production. Une valeur mal
        // orthographiée — « develop », « DEV ! » — ne doit pas ouvrir la porte par accident.
        return self::$courant = strtolower(trim((string) $brut)) === self::DEVELOPPEMENT
            ? self::DEVELOPPEMENT
            : self::PRODUCTION;
    }

    public static function estDev(): bool
    {
        return self::courant() === self::DEVELOPPEMENT;
    }
}
