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
    // Choisir son mot de passe demande un code d'accès — décidé le 6 novembre 2026. Sans lui,
    // un compte qui attendait le sien se prenait avec son seul identifiant : le premier qui le
    // tapait choisissait le mot de passe, et entrait. L'administrateur reçoit le code en créant
    // le compte ou en le réinitialisant, une seule fois, et le donne à la personne. Il vaut
    // sept jours, et ne sert qu'une fois.
    //
    // La colonne du mot de passe porte donc, au choix :
    //   - le hachage du mot de passe ;
    //   - « !échéance!hachage du code » : un compte qui attend, avec son code ;
    //   - une chaîne vide : un compte qui attendait avant les codes. Il ne se prend plus : il
    //     lui faut un code, que l'administrateur donne en le réinitialisant.
    // password_verify() ne reconnaît rien contre les deux derniers : la porte reste fermée tant
    // que le mot de passe n'est pas choisi.

    /** La longueur minimale d'un mot de passe qu'on choisit. */
    public const MOT_DE_PASSE_MINI = 8;

    /** Ce que vaut un code d'accès, en secondes : une semaine. */
    public const CODE_DUREE = 7 * 86400;

    /** Les caractères d'un code : ni I ni O, ni 0 ni 1, qu'on confond en le dictant. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** La marque d'un compte qui attend, en tête de la colonne : un hachage bcrypt commence par « $ ». */
    private const CODE_MARQUE = '!';

    /**
     * Un code neuf : huit caractères de l'alphabet, soit quarante bits — un million de
     * milliards d'essais, chacun au prix d'un bcrypt. Rendu tel qu'on le range (son échéance
     * et son hachage), et tel qu'on le lit : « K7P4-QX9M ».
     *
     * @return array{range: string, code: string, jusqua: int}
     */
    private static function codeNeuf(): array
    {
        $brut = '';
        for ($i = 0; $i < 8; $i++) $brut .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        $jusqua = time() + self::CODE_DUREE;
        return [
            'range' => self::CODE_MARQUE . $jusqua . self::CODE_MARQUE . password_hash($brut, PASSWORD_BCRYPT),
            'code' => substr($brut, 0, 4) . '-' . substr($brut, 4),
            'jusqua' => $jusqua,
        ];
    }

    /** Ce qu'on a tapé, ramené à la forme du code : sans tiret ni espace, en majuscules. */
    private static function codeNormalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /**
     * Ce que la colonne dit d'un compte qui attend son mot de passe : null s'il a le sien ;
     * sinon l'échéance et le hachage de son code — vides pour un compte d'avant les codes.
     *
     * @return array{echeance: int, hachage: string}|null
     */
    private static function attente(string $range): ?array
    {
        if ($range === '') return ['echeance' => 0, 'hachage' => ''];
        if (!str_starts_with($range, self::CODE_MARQUE)) return null;
        $morceaux = explode(self::CODE_MARQUE, $range, 3);
        return ['echeance' => (int) ($morceaux[1] ?? 0), 'hachage' => (string) ($morceaux[2] ?? '')];
    }

    /**
     * Où en est un compte qui attend son mot de passe, pour l'écran des utilisateurs : null
     * s'il a le sien ; sinon « code » (un code valable, jusqu'à « jusqua »), « expire » (son
     * code a passé) ou « sans-code » (un compte d'avant les codes).
     *
     * @param string $range la colonne du mot de passe
     * @return array{etat: string, jusqua: ?int}|null
     */
    public static function etatAttente(string $range): ?array
    {
        $attente = self::attente($range);
        if ($attente === null) return null;
        if ($attente['hachage'] === '') return ['etat' => 'sans-code', 'jusqua' => null];
        return ['etat' => $attente['echeance'] < time() ? 'expire' : 'code', 'jusqua' => $attente['echeance']];
    }

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

    /** Ce compte attend-il que la personne choisisse son mot de passe, avec un code ou sans ? */
    public function motDePasseACreer(string $identifiant): bool
    {
        $stmt = $this->db->prepare('SELECT password FROM Utilisateur WHERE identifiant = ? LIMIT 1');
        $stmt->execute([$identifiant]);
        $range = $stmt->fetchColumn();
        return is_string($range) && self::attente($range) !== null;
    }

    /**
     * La personne choisit son mot de passe — première connexion, ou après une
     * réinitialisation —, avec le code que l'administrateur lui a donné, et entre.
     *
     * Une seule fois : le mot de passe ne se pose que sur la colonne telle qu'on vient de la
     * lire — un second onglet, ou quelqu'un qui aurait devancé son titulaire, ne pose rien. Un
     * code faux se note au journal : une rafale sur le même compte s'y voit. Le journal note
     * aussi le mot de passe choisi, et l'écran des utilisateurs le montre : le compte n'y
     * attend plus.
     *
     * @return string|null pourquoi c'est refusé ; null : c'est fait, et la session est ouverte
     */
    public function creerMotDePasse(string $identifiant, string $code, string $motDePasse): ?string
    {
        $dejaLa = 'Ce compte a déjà un mot de passe. Connectez-vous, ou signalez un oubli à l’administrateur.';
        $stmt = $this->db->prepare('SELECT id, password FROM Utilisateur WHERE identifiant = ? LIMIT 1');
        $stmt->execute([$identifiant]);
        $compte = $stmt->fetch(PDO::FETCH_ASSOC);
        $attente = $compte ? self::attente((string) $compte['password']) : null;
        // Un compte inconnu et un compte qui a son mot de passe répondent pareil.
        if ($attente === null) return $dejaLa;
        if ($attente['hachage'] === '') {
            return 'Il vous faut un code pour choisir votre mot de passe : demandez-le à l’administrateur.';
        }
        if (!password_verify(self::codeNormalise($code), $attente['hachage'])) {
            Journal::noter('code d’accès erroné pour ' . $identifiant);
            return 'Ce code n’est pas le bon. Vérifiez-le, ou demandez-en un nouveau à l’administrateur.';
        }
        // L'échéance après le code : qu'un code a passé ne se dit qu'à qui l'a.
        if ($attente['echeance'] < time()) return 'Ce code a expiré : demandez-en un nouveau à l’administrateur.';

        $poser = $this->db->prepare('UPDATE Utilisateur SET password = ?'
            . (self::reinitialisationsEnBase($this->db) ? ', reinitialisation_demandee_le = NULL' : '')
            . ' WHERE id = ? AND password = ?');
        $poser->execute([password_hash($motDePasse, PASSWORD_BCRYPT), $compte['id'], $compte['password']]);
        if ($poser->rowCount() !== 1) return $dejaLa;
        Journal::noter('mot de passe choisi pour ' . $identifiant);
        if (!$this->startSession($identifiant)) return 'Connexion impossible. Réessayez.';
        Session::authentifiee();
        return null;
    }

    /**
     * L'administrateur réinitialise : le mot de passe cède la place à un code d'accès neuf,
     * que la personne tapera pour choisir le nouveau. Sa demande, s'il y en avait une, est
     * traitée. Sur un compte qui attendait déjà, c'est un nouveau code : l'ancien ne vaut plus.
     *
     * @return array{code: string, jusqua: int} à donner à la personne — il n'est rendu qu'ici
     */
    public function reinitialiserMotDePasse(int $id): array
    {
        $neuf = self::codeNeuf();
        $this->db->prepare('UPDATE Utilisateur SET password = ?'
            . (self::reinitialisationsEnBase($this->db) ? ', reinitialisation_demandee_le = NULL' : '') . ' WHERE id = ?')
            ->execute([$neuf['range'], $id]);
        return ['code' => $neuf['code'], 'jusqua' => $neuf['jusqua']];
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
        // Pas un compte qui attend déjà le sien, avec un code ou sans : « !… » est un code.
        $stmt = $this->db->prepare("UPDATE Utilisateur SET reinitialisation_demandee_le = NOW()
            WHERE identifiant = ? AND password <> '' AND password NOT LIKE '!%' AND reinitialisation_demandee_le IS NULL");
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

    /**
     * Crée un compte.
     *
     * @param string $password vide : la personne choisira le sien à sa première connexion,
     *                         avec le code d'accès que cette méthode rend
     * @return array{code: string, jusqua: int}|null le code à donner à la personne, s'il est
     *                                               créé sans mot de passe — rendu ici seulement
     */
    public function register(
        string $name,
        string $prenom,
        int $id_role,
        string $password,
        ?string $email = null
    ): ?array {
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
        // connexion, avec un code : c'est ainsi que l'écran des utilisateurs les crée.
        $code = $password === '' ? self::codeNeuf() : null;

        $stmt->execute([
            'name' => $name,
            'id_role' => $id_role,
            'password' => $code ? $code['range'] : password_hash($password, PASSWORD_BCRYPT),
            'email' => $email,
            'prenom' => $prenom,
            'identifiant' => $identifiant
        ]);
        return $code ? ['code' => $code['code'], 'jusqua' => $code['jusqua']] : null;
    }
}
