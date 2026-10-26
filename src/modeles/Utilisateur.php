<?php

/**
 * La personne connectée, et ce qu'elle a le droit de faire.
 *
 * Les droits étaient treize variables globales posées en tête de prefa_context.php et
 * relues partout — $isAdmin, $canEditPrefa, $pvLimiteAuDemandeur… Pour savoir si le chef
 * d'atelier remplit les PV, il fallait retrouver la ligne qui composait $canFillPv. Ils
 * sont ici, nommés par ce qu'ils permettent, et chacun porte la règle qui le justifie.
 *
 * L'objet est immuable : il décrit qui est là, il ne change rien.
 */
final class Utilisateur
{
    private function __construct(
        public readonly int $id,
        public readonly string $identifiant,
        public readonly string $prenom,
        public readonly string $nom,
        public readonly ?string $email,
        public readonly ?Role $role,
        /** Le numéro brut du rôle : un compte peut en porter un que l'énumération ignore. */
        public readonly int $idRole,
        /** Le libellé du rôle tel qu'il est écrit en base, pour les écrans qui l'affichent. */
        public readonly string $roleEnBase,
    ) {}

    /** Null quand l'identifiant ne correspond à aucun compte : le compte a pu être supprimé. */
    public static function parIdentifiant(PDO $db, string $identifiant): ?self
    {
        $lecture = $db->prepare('SELECT u.id, u.identifiant, u.prenom, u.name, u.email, u.id_role,
            r.name AS role_name
            FROM Utilisateur u JOIN role r ON r.id = u.id_role
            WHERE u.identifiant = ? LIMIT 1');
        $lecture->execute([$identifiant]);
        $ligne = $lecture->fetch();
        if (!$ligne) return null;

        return new self(
            (int) $ligne['id'],
            (string) $ligne['identifiant'],
            (string) ($ligne['prenom'] ?? ''),
            (string) ($ligne['name'] ?? ''),
            $ligne['email'] !== null ? (string) $ligne['email'] : null,
            Role::depuisLaBase((int) $ligne['id_role']),
            (int) $ligne['id_role'],
            (string) ($ligne['role_name'] ?? ''),
        );
    }

    public function est(Role ...$roles): bool
    {
        return $this->role !== null && in_array($this->role, $roles, true);
    }

    public function nomComplet(): string
    {
        return trim($this->prenom . ' ' . $this->nom);
    }

    // ------------------------------------------------------------------ les droits

    /**
     * Voir les demandes de tout le monde. Le chargé d'affaire ne voit que les siennes, le
     * personnel d'atelier que celles où il est affecté.
     */
    public function voitToutesLesDemandes(): bool
    {
        return $this->est(Role::ChargeDeProduction, Role::ChefDAtelier);
    }

    /** Déposer et modifier une demande. L'atelier la reçoit, il ne l'écrit pas. */
    public function deposeDesDemandes(): bool
    {
        return $this->est(Role::ChargeDeProduction, Role::ChargeDAffaire);
    }

    /**
     * Valider ou refuser une demande déposée.
     *
     * Le chef d'atelier voit tout ce qui est validé, mais ne décide pas : c'est le chargé
     * de production qui engage l'atelier, parce que c'est lui qui connaît la charge.
     */
    public function valideLesDemandes(): bool
    {
        return $this->est(Role::ChargeDeProduction);
    }

    /** Affecter l'atelier, remplir les plans et les suivis de soudage. */
    public function gereLAtelier(): bool
    {
        return $this->est(Role::ChargeDeProduction, Role::ChefDAtelier);
    }

    /** Saisir les quatre PV : réception matière, fin de fabrication, conformité, passivation. */
    public function remplitLesPv(): bool
    {
        return $this->est(Role::ChargeDeProduction, Role::ChefDAtelier);
    }

    /** Les consulter. Le chargé d'affaire suit son avancement sans rien y écrire. */
    public function consulteLesPv(): bool
    {
        return $this->remplitLesPv() || $this->est(Role::ChargeDAffaire);
    }

    /** Et seulement sur les demandes qu'il a lui-même déposées. */
    public function pvLimitesASesDemandes(): bool
    {
        return $this->est(Role::ChargeDAffaire) && !$this->remplitLesPv();
    }
}
