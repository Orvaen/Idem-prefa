<?php

require_once __DIR__ . '/../modeles/autoload.php';

/**
 * L'authentification : vérifier un mot de passe, ouvrir une session, créer un compte.
 *
 * Qui est connecté et ce qu'il a le droit de faire ne se lit plus ici mais dans les
 * modèles Session et Utilisateur. Cette classe ne fait qu'ouvrir la porte.
 */
class User
{
    private MyPDO $db;

    public function __construct(MyPDO $db)
    {
        $this->db = $db;
    }

    public function getRole(string $Role_ID): string
    {
        $sql = 'SELECT * FROM role WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'id' => $Role_ID
        ]);

        $role = $stmt->fetch();
        return $role['name'];
    }

    public function login(string $identifiant, string $password): bool
    {
        $sql = 'SELECT password
            FROM Utilisateur
            WHERE identifiant = :identifiant
            LIMIT 1';

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'identifiant' => $identifiant
        ]);

        $hash = $stmt->fetchColumn();

        if (!is_string($hash) || !password_verify($password, $hash)) {
            // Noté : un compte qui n'arrive plus à entrer se voit ici, et une rafale de
            // tentatives sur le même identifiant aussi.
            Journal::noter('échec de connexion sur ' . $identifiant);
            return false;
        }

        if (!$this->startSession($identifiant)) return false;
        // Le mot de passe est vérifié : les deux heures de la connexion partent d'ici
        // (Session::DUREE). La bascule entre comptes passe par startSession() seul.
        Session::authentifiee();
        return true;
    }

    /**
     * Ouvre la session sur un compte déjà authentifié : utilisé par la bascule multi-compte.
     *
     * Seul l'identifiant est retenu. Le nom, le prénom, le courriel et le rôle y étaient
     * recopiés et devenaient faux dès qu'un compte était renommé ; ils se relisent
     * maintenant en base à chaque requête.
     */
    public function startSession(string $identifiant): bool
    {
        $utilisateur = Utilisateur::parIdentifiant($this->db, $identifiant);
        if (!$utilisateur) {
            return false;
        }

        Session::ouvrir($utilisateur->identifiant);
        // Le rôle au long, une fois : les lignes suivantes ne portent que son abrégé, et
        // c'est lui qui explique ce que cette personne a le droit de faire ensuite.
        Journal::noter('connexion de ' . $utilisateur->identifiant
            . ' (' . ($utilisateur->role?->metier() ?? $utilisateur->roleEnBase) . ')');

        return true;
    }

    // ------------------------------------------------------------ le mot de passe
    //
    // L'administrateur ne donne plus de mot de passe, et personne ne change le sien sur place :
    // un compte se crée sans, la personne choisit le sien à sa première connexion, et pour en
    // changer — oublié, ou simplement à remplacer — elle demande à l'administrateur de le
    // réinitialiser, puis choisit le nouveau à sa connexion suivante. Un mot de passe ne se
    // tape donc qu'à l'écran de connexion.
    //
    // Un compte sans mot de passe en porte un vide — tout juste créé, ou réinitialisé.
    // password_verify() ne reconnaît rien contre une chaîne vide : la porte reste fermée tant
    // qu'il n'est pas choisi.

    /** La longueur minimale d'un mot de passe qu'on choisit. */
    public const MOT_DE_PASSE_MINI = 8;

    /** Ce qui ne va pas dans un mot de passe qu'on choisit, ou null s'il convient. */
    public static function motDePasseRefus(string $motDePasse, string $confirmation): ?string
    {
        if (mb_strlen($motDePasse) < self::MOT_DE_PASSE_MINI) {
            return 'Le mot de passe compte au moins ' . self::MOT_DE_PASSE_MINI . ' caractères.';
        }
        // Au-delà, bcrypt ne lit plus rien : deux mots de passe qui ne diffèrent qu'après le
        // 72e octet seraient le même.
        if (strlen($motDePasse) > 72) return 'Le mot de passe tient en 72 caractères.';
        if ($motDePasse !== $confirmation) return 'Les deux saisies ne sont pas les mêmes.';
        return null;
    }

    /**
     * La demande de réinitialisation est-elle en base ? Pas tant que la migration
     * 2026-11-02_mot_de_passe n'est pas passée sur ce serveur : on ne retient alors aucune
     * demande, et l'écran dit de prévenir l'administrateur de vive voix.
     */
    public static function reinitialisationsEnBase(PDO $db): bool
    {
        static $presente = null;
        return $presente ??= (bool) $db->query("SHOW COLUMNS FROM Utilisateur LIKE 'reinitialisation_demandee_le'")->fetch();
    }

    /** Les demandes que l'administrateur n'a pas encore traitées : la pastille d'« Utilisateurs ». */
    public static function reinitialisationsEnAttente(PDO $db): int
    {
        if (!self::reinitialisationsEnBase($db)) return 0;
        return (int) $db->query('SELECT COUNT(*) FROM Utilisateur WHERE reinitialisation_demandee_le IS NOT NULL')->fetchColumn();
    }

    /** Ce compte attend-il que la personne choisisse son mot de passe ? */
    public function motDePasseACreer(string $identifiant): bool
    {
        $stmt = $this->db->prepare("SELECT password = '' FROM Utilisateur WHERE identifiant = ? LIMIT 1");
        $stmt->execute([$identifiant]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * La personne choisit son mot de passe — première connexion, ou après une
     * réinitialisation — et entre.
     *
     * Une seule fois : la requête ne pose rien sur un compte qui en a déjà un, que ce soit
     * un second onglet ou quelqu'un qui aurait devancé son titulaire. Le journal le note,
     * et l'écran des utilisateurs le montre : le compte n'y est plus « à créer ».
     */
    public function creerMotDePasse(string $identifiant, string $motDePasse): bool
    {
        $stmt = $this->db->prepare('UPDATE Utilisateur SET password = ?'
            . (self::reinitialisationsEnBase($this->db) ? ', reinitialisation_demandee_le = NULL' : '')
            . " WHERE identifiant = ? AND password = ''");
        $stmt->execute([password_hash($motDePasse, PASSWORD_BCRYPT), $identifiant]);
        if ($stmt->rowCount() !== 1) return false;
        Journal::noter('mot de passe choisi pour ' . $identifiant);
        if (!$this->startSession($identifiant)) return false;
        Session::authentifiee();
        return true;
    }

    /**
     * L'administrateur réinitialise : le mot de passe se vide, et la personne en choisira un
     * à sa prochaine connexion. Sa demande, s'il y en avait une, est traitée.
     */
    public function reinitialiserMotDePasse(int $id): void
    {
        $this->db->prepare("UPDATE Utilisateur SET password = ''"
            . (self::reinitialisationsEnBase($this->db) ? ', reinitialisation_demandee_le = NULL' : '') . ' WHERE id = ?')
            ->execute([$id]);
    }

    /**
     * La personne demande un nouveau mot de passe : elle a oublié le sien — depuis l'écran de
     * connexion —, ou veut en changer — depuis ses paramètres. Chaque chargé de production
     * l'apprend, nommément ; il réinitialise, et elle choisit le nouveau en se reconnectant.
     *
     * Retenue une fois : une seconde demande avant la réinitialisation ne le prévient pas une
     * seconde fois. Un compte qui n'a pas encore de mot de passe n'a rien à demander — il le
     * choisit en se connectant.
     *
     * @param string $motif 'oubli' ou 'changement' : l'alerte le dit, pour que l'administrateur
     *                      sache si quelqu'un attend, bloqué, à l'écran de connexion
     * @return bool la demande vient-elle d'être retenue ?
     */
    public function demanderReinitialisation(string $identifiant, string $motif): bool
    {
        if (!self::reinitialisationsEnBase($this->db)) return false;
        $stmt = $this->db->prepare("UPDATE Utilisateur SET reinitialisation_demandee_le = NOW()
            WHERE identifiant = ? AND password <> '' AND reinitialisation_demandee_le IS NULL");
        $stmt->execute([$identifiant]);
        if ($stmt->rowCount() !== 1) return false;
        $compte = $this->db->prepare("SELECT id, TRIM(CONCAT(prenom, ' ', name)) FROM Utilisateur WHERE identifiant = ?");
        $compte->execute([$identifiant]);
        [$id, $nom] = $compte->fetch(PDO::FETCH_NUM);
        Journal::noter('nouveau mot de passe demandé (' . $motif . ') pour ' . $identifiant);

        // Pas au demandeur lui-même : un chargé de production qui a oublié le sien le
        // demande aux autres.
        $admins = $this->db->prepare('SELECT id FROM Utilisateur WHERE id_role = ? AND id <> ?');
        $admins->execute([Role::ChargeDeProduction->value, $id]);
        foreach ($admins->fetchAll(PDO::FETCH_COLUMN) as $admin) {
            Evenement::noter($this->db, Evenement::MOT_DE_PASSE_A_REINITIALISER, null, (int) $id,
                ['nom' => $nom, 'identifiant' => $identifiant, 'motif' => $motif], (int) $admin);
        }
        return true;
    }

    public static function buildIdentifiant(string $name, string $prenom): string
    {
        $initial = mb_strtoupper(mb_substr(trim($name), 0, 1));
        return strtolower(str_replace(' ', '', $initial . '.' . $prenom));
    }

    public function identifiantExists(string $identifiant): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM Utilisateur WHERE identifiant = :identifiant LIMIT 1'
        );
        $stmt->execute(['identifiant' => $identifiant]);
        return $stmt->fetchColumn() !== false;
    }

    public function register(
        string $name,
        string $prenom,
        int $id_role,
        string $password,
        ?string $email = null
    ): bool {
        $identifiant = self::buildIdentifiant($name, $prenom);

        if ($this->identifiantExists($identifiant)) {
            throw new DomainException('Cet identifiant existe déjà : ' . $identifiant);
        }

        // Le courriel est facultatif, mais s'il est donné, c'est une adresse.
        //
        // Il n'était pas vérifié du tout, et un compte est parti avec « Manssouri » pour
        // courriel : en ligne de commande, un nom de famille en deux mots glisse son second
        // mot dans l'argument suivant, qui se trouve être celui-là. Rien ne s'en plaignait —
        // ni le script, ni la base, ni l'écran des comptes — et le compte était bien créé.
        $email = trim($email ?? '');
        if ($email === '') {
            $email = null;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException("Ceci n'est pas une adresse courriel : " . $email);
        }

        $sql = "INSERT INTO Utilisateur (name, id_role, password, email, prenom, identifiant)
                VALUES (:name, :id_role, :password, :email, :prenom, :identifiant)";
        $stmt = $this->db->prepare($sql);
        // Sans mot de passe, le compte attend que la personne choisisse le sien à sa première
        // connexion : c'est ainsi que l'écran des utilisateurs les crée.
        $hash = $password === '' ? '' : password_hash($password, PASSWORD_BCRYPT);

        return $stmt->execute([
            'name' => $name,
            'id_role' => $id_role,
            'password' => $hash,
            'email' => $email,
            'prenom' => $prenom,
            'identifiant' => $identifiant
        ]);
    }
}
