<?php

/**
 * Une affaire : un chantier, confié à un chargé d'affaire, qui y dépose ses demandes de
 * préfabrication.
 *
 * Une affaire porte plusieurs demandes. C'était l'inverse jusqu'ici : le chargé d'affaire
 * tapait un nom de chantier à chaque demande, et l'affaire naissait toute seule au premier
 * nom rencontré. Deux demandes du même chantier n'étaient alors reliées que par un texte
 * identique, « BATIMENT A » contre « Bâtiment A » en faisait deux, et personne ne décidait
 * de l'ouverture d'un chantier.
 *
 * C'est maintenant le chargé de production qui ouvre l'affaire et désigne qui en a la
 * charge. Le chargé d'affaire choisit parmi les siennes : il ne peut plus en inventer une,
 * ni déposer sur le chantier d'un collègue.
 */
final class Affaire
{
    private function __construct(
        public readonly int $id,
        public readonly string $nom,
        public readonly ?int $idChargeAffaire,
        /** Le nom du chargé d'affaire, vide quand l'affaire n'est confiée à personne. */
        public readonly string $chargeAffaire,
        /**
         * Combien de demandes y sont rattachées : une affaire qui en porte ne se supprime pas.
         * Lue par pour() pour un chargé d'affaire, seulement les siennes — voir lecture().
         */
        public readonly int $demandes,
    ) {}

    /**
     * La requête de lecture.
     *
     * $siennes restreint le compte des demandes à celles du chargé d'affaire. Il ne voit que
     * les demandes qu'il a déposées, et une affaire reprise d'un collègue garde celles du
     * collègue : sa carte annoncerait « 3 demandes » et la liste qu'elle ouvre en montrerait
     * une.
     */
    private static function lecture(bool $siennes = false): string
    {
        return 'SELECT a.id, a.nom, a.id_charge_affaire,
                TRIM(CONCAT(COALESCE(u.prenom, \'\'), \' \', COALESCE(u.name, \'\'))) AS charge,
                (SELECT COUNT(*) FROM demande_prefabrication d WHERE d.id_affaire = a.id'
            . ($siennes ? ' AND d.idUsers = a.id_charge_affaire' : '') . ') AS demandes
            FROM affaire a
            LEFT JOIN Utilisateur u ON u.id = a.id_charge_affaire';
    }

    /** Toutes les affaires, pour l'écran du chargé de production. */
    public static function toutes(PDO $db): array
    {
        return array_map(self::depuisLaBase(...), $db->query(self::lecture() . ' ORDER BY a.nom')->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Les affaires sur lesquelles cette personne peut déposer une demande — et, pour le
     * chargé d'affaire, celles que montre « Mes affaires ».
     *
     * Le chargé d'affaire ne voit que les siennes. Le chargé de production les voit toutes :
     * il n'en dépose pas, mais il corrige les demandes des autres, et il doit pouvoir les
     * rattacher où il faut.
     */
    public static function pour(PDO $db, Utilisateur $utilisateur): array
    {
        if (!$utilisateur->est(Role::ChargeDAffaire)) return self::toutes($db);

        $lecture = $db->prepare(self::lecture(siennes: true) . ' WHERE a.id_charge_affaire = ? ORDER BY a.nom');
        $lecture->execute([$utilisateur->id]);
        return array_map(self::depuisLaBase(...), $lecture->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Une affaire, si cette personne a le droit d'y déposer. Null sinon — on ne dit pas pourquoi. */
    public static function accessible(PDO $db, Utilisateur $utilisateur, int $id): ?self
    {
        foreach (self::pour($db, $utilisateur) as $affaire) {
            if ($affaire->id === $id) return $affaire;
        }
        return null;
    }

    public static function parId(PDO $db, int $id): ?self
    {
        $lecture = $db->prepare(self::lecture() . ' WHERE a.id = ?');
        $lecture->execute([$id]);
        $ligne = $lecture->fetch(PDO::FETCH_ASSOC);
        return $ligne ? self::depuisLaBase($ligne) : null;
    }

    /** @throws DomainException si une affaire porte déjà ce nom */
    public static function ouvrir(PDO $db, string $nom, ?int $chargeAffaire): int
    {
        self::refuserUnDoublon($db, $nom, null);
        $db->prepare('INSERT INTO affaire (nom, id_charge_affaire) VALUES (?, ?)')
            ->execute([$nom, $chargeAffaire]);
        return (int) $db->lastInsertId();
    }

    /** @throws DomainException si le nouveau nom est déjà pris par une autre affaire */
    public static function modifier(PDO $db, int $id, string $nom, ?int $chargeAffaire): void
    {
        self::refuserUnDoublon($db, $nom, $id);
        // Le nom est recopié sur chaque demande — c'est lui qui s'affiche dans les listes et
        // dans les références. Renommer l'affaire doit donc renommer ses demandes, sans quoi
        // l'écran montrerait l'ancien nom jusqu'à la prochaine modification de chacune.
        $db->prepare('UPDATE affaire SET nom = ?, id_charge_affaire = ? WHERE id = ?')
            ->execute([$nom, $chargeAffaire, $id]);
        $db->prepare('UPDATE demande_prefabrication SET nom_affaire = ? WHERE id_affaire = ?')
            ->execute([$nom, $id]);
    }

    /** @throws DomainException si des demandes y sont encore rattachées */
    public static function fermer(PDO $db, int $id): void
    {
        $affaire = self::parId($db, $id);
        if (!$affaire) throw new DomainException('Cette affaire n’existe plus.');
        if ($affaire->demandes > 0) {
            throw new DomainException($affaire->demandes === 1
                ? 'Une demande est rattachée à cette affaire : elle ne peut pas être supprimée.'
                : $affaire->demandes . ' demandes sont rattachées à cette affaire : elle ne peut pas être supprimée.');
        }
        $db->prepare('DELETE FROM affaire WHERE id = ?')->execute([$id]);
    }

    /**
     * Deux chantiers du même nom, c'est le désordre qu'on vient de quitter : on refuse,
     * sans tenir compte de la casse ni des accents — c'est ce que fait la collation de la
     * colonne, « BATIMENT A » et « Bâtiment a » sont le même nom.
     */
    private static function refuserUnDoublon(PDO $db, string $nom, ?int $sauf): void
    {
        $lecture = $db->prepare('SELECT nom FROM affaire WHERE nom = ?' . ($sauf === null ? '' : ' AND id <> ?'));
        $lecture->execute($sauf === null ? [$nom] : [$nom, $sauf]);
        $existant = $lecture->fetchColumn();
        if ($existant !== false) {
            throw new DomainException('Une affaire porte déjà ce nom : « ' . $existant . ' ».');
        }
    }

    private static function depuisLaBase(array $ligne): self
    {
        return new self(
            (int) $ligne['id'],
            (string) $ligne['nom'],
            $ligne['id_charge_affaire'] !== null ? (int) $ligne['id_charge_affaire'] : null,
            trim((string) ($ligne['charge'] ?? '')),
            (int) $ligne['demandes'],
        );
    }
}
