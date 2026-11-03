<?php

/**
 * Les quatre profils de l'application.
 *
 * Un même profil porte trois noms selon l'endroit où on le lit : le libellé de la base
 * (« Gestionnaire »), le métier tel que le client le nomme (« Chef d'atelier ») et
 * l'abrégé du journal (« chef-atelier »). Aucun des trois ne se devine depuis un autre,
 * et la correspondance se perdait dans des comparaisons à des nombres —
 * `(int) $actor['id_role'] === 5` — qu'il fallait traduire de tête à chaque lecture.
 *
 * Les trois noms vivent donc ici, et `depuisLeTexte()` les accepte tous les trois : celui
 * qui crée un compte connaît le nom de son côté, pas les deux autres.
 *
 * Le numéro 4 n'existe pas : il a été sauté en base, et la suite ne se renumérote pas sans
 * reprendre chaque compte.
 */
enum Role: int
{
    case ChargeDeProduction = 1;
    case ChefDAtelier = 2;
    case PersonnelAtelier = 3;
    case ChargeDAffaire = 5;

    /** Le métier, tel que le client le nomme. */
    public function metier(): string
    {
        return match ($this) {
            self::ChargeDeProduction => 'Chargé de production',
            self::ChefDAtelier => "Chef d'atelier",
            self::PersonnelAtelier => 'Personnel atelier',
            self::ChargeDAffaire => "Chargé d'affaire",
        };
    }

    /**
     * Le libellé de la table `role`, qui ne dit pas le métier.
     *
     * Il ne s'affiche sur aucun écran — c'est le vocabulaire de la base, donc celui de
     * phpMyAdmin, où l'on va regarder un compte de temps en temps. Il est ici pour que
     * celui qui a lu « Demandeur » dans la table n'ait pas à chercher ailleurs qu'il
     * s'agit du chargé d'affaire.
     */
    public function libelleEnBase(): string
    {
        return match ($this) {
            self::ChargeDeProduction => 'Administrateur',
            self::ChefDAtelier => 'Gestionnaire',
            self::PersonnelAtelier => 'Utilisateur',
            self::ChargeDAffaire => 'Demandeur',
        };
    }

    /**
     * Le même, court et sans accent, pour le journal.
     *
     * Apache échappe tout octet non ASCII dans ses lignes : « Chargé » y ressortirait en
     * « Charg\xc3\xa9 » à chaque requête.
     *
     * Le métier garde son premier mot — « charge-production » et non « production » tout
     * court, qui se lisait comme l'environnement du serveur : on cherchait pourquoi un
     * poste en développement annonçait de la production, et c'était un rôle.
     */
    public function abrege(): string
    {
        return match ($this) {
            self::ChargeDeProduction => 'charge-production',
            self::ChefDAtelier => 'chef-atelier',
            self::PersonnelAtelier => 'personnel-atelier',
            self::ChargeDAffaire => 'charge-affaire',
        };
    }

    /**
     * Les trois noms sous lesquels ce profil se laisse désigner.
     *
     * Dans cet ordre : l'abrégé, le métier, le libellé en base. C'est l'ordre dans lequel
     * un script les montre, de celui qu'il attend à celui où l'on se reconnaît.
     *
     * @return list<string>
     */
    public function noms(): array
    {
        return [$this->abrege(), $this->metier(), $this->libelleEnBase()];
    }

    /**
     * Le rôle d'un texte tapé à la main, dans n'importe lequel des trois vocabulaires.
     *
     * Refuser « Chef d'atelier » parce qu'un script attend « chef-atelier » demande à
     * celui qui tape de traduire de tête ce que cette énumération sait traduire.
     *
     * La comparaison porte sur une forme réduite — sans accent ni majuscule, ponctuation
     * ramenée au tiret, liaisons et pluriel ôtés : « Chargé d'affaires »,
     * « charge-affaire » et « CHARGE D AFFAIRE » désignent donc le même profil.
     */
    public static function depuisLeTexte(string $texte): ?self
    {
        $cherche = self::reduire($texte);
        if ($cherche === '') return null;
        foreach (self::cases() as $cas) {
            foreach ($cas->noms() as $nom) {
                if (self::reduire($nom) === $cherche) return $cas;
            }
        }
        return null;
    }

    /**
     * Le rôle d'un numéro venu de la base.
     *
     * Un compte créé avec un rôle inconnu — cela s'est vu, un script de démonstration
     * posait un 4 — ne doit pas faire tomber l'application : il n'a simplement aucun droit,
     * et c'est à l'appelant de décider quoi en dire.
     */
    public static function depuisLaBase(?int $numero): ?self
    {
        return $numero === null ? null : self::tryFrom($numero);
    }

    /** « Chargé d'affaires » et « charge-affaire » ramenés à la même chaîne. */
    private static function reduire(string $texte): string
    {
        $sansAccent = strtr(mb_strtolower(trim($texte)), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y',
        ]);
        $mots = preg_split('/[^a-z0-9]+/', $sansAccent, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // La liaison saute : sans cela « chef d'atelier » donnerait « chef-d-atelier », qui
        // ne rejoindrait pas l'abrégé. Le pluriel aussi — « chargé d'affaires » est
        // l'orthographe courante du métier, que la base écrit au singulier.
        $mots = array_filter($mots, static fn (string $mot): bool => !in_array($mot, ['d', 'de', 'du', 'des'], true));
        $mots = array_map(static fn (string $mot): string => (string) preg_replace('/s$/', '', $mot), $mots);
        return implode('-', $mots);
    }
}
