<?php

/**
 * Les quatre profils de l'application.
 *
 * Le libellé en base ne dit pas le métier : « Administrateur » est le chargé de production,
 * « Gestionnaire » le chef d'atelier, « Utilisateur » le personnel d'atelier, « Demandeur »
 * le chargé d'affaire. La correspondance se perdait dans des comparaisons à des nombres —
 * `(int) $actor['id_role'] === 5` — qu'il fallait traduire de tête à chaque lecture.
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
}
