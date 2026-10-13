<?php

/**
 * La requête HTTP telle que l'API la lit : une méthode, un chemin découpé, et un corps.
 *
 * Le chemin vient de REQUEST_URI et non d'un paramètre : Apache renvoie ici tout ce qui
 * commence par /api/ et ne correspond à aucun fichier (FallbackResource), l'adresse reste
 * donc celle qu'on a tapée.
 */
final class Requete
{
    private function __construct(
        public readonly string $methode,
        /** @var list<string> les segments du chemin après /api, vides retirés */
        public readonly array $segments,
        /** @var array<string, mixed> le corps JSON, ou le formulaire posté */
        public readonly array $corps,
        /** @var array<string, string> la chaîne de requête */
        public readonly array $parametres,
    ) {}

    public static function courante(): self
    {
        $chemin = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        // « /api/demandes/12 » donne ['demandes', '12']. Le préfixe tombe, les segments
        // vides aussi — une adresse finissant par / ne doit pas créer de segment fantôme.
        $segments = array_values(array_filter(explode('/', trim($chemin, '/')), static fn (string $s): bool => $s !== ''));
        if (($segments[0] ?? '') === 'api') array_shift($segments);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $segments,
            self::corpsLu(),
            array_map('strval', $_GET),
        );
    }

    /** Le segment de rang donné, ou null : $requete->segment(1) sur /api/demandes/12 vaut « 12 ». */
    public function segment(int $rang): ?string
    {
        return $this->segments[$rang] ?? null;
    }

    /** Un entier d'identifiant : refuse zéro, le négatif et tout ce qui n'est pas un nombre. */
    public function identifiant(int $rang): ?int
    {
        $brut = $this->segment($rang);
        if ($brut === null || !ctype_digit($brut)) return null;
        $valeur = (int) $brut;
        return $valeur > 0 ? $valeur : null;
    }

    public function parametre(string $nom, string $defaut = ''): string
    {
        return $this->parametres[$nom] ?? $defaut;
    }

    public function champ(string $nom): mixed
    {
        return $this->corps[$nom] ?? null;
    }

    /**
     * Le corps de la requête.
     *
     * JSON quand le client l'annonce — c'est ce qu'une API reçoit — et le formulaire posté
     * sinon, pour que les pages existantes puissent appeler l'API sans rien changer.
     */
    private static function corpsLu(): array
    {
        // vérifier le type de contenu pour savoir si c'est du JSON ou un formulaire classique
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($type, 'application/json') === false) return $_POST;

        $brut = file_get_contents('php://input');
        $lu = $brut === false || $brut === '' ? null : json_decode($brut, true);
        return is_array($lu) ? $lu : [];
    }
}
