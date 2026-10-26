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

        return $this->startSession($identifiant);
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

        $sql = "INSERT INTO Utilisateur (name, id_role, password, email, prenom, identifiant)
                VALUES (:name, :id_role, :password, :email, :prenom, :identifiant)";
        $stmt = $this->db->prepare($sql);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $email = trim($email ?? ''); // Peut être null

        if ($email === '') {
            $email = null;
        }

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
