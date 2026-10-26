<?php

/**
 * Les comptes d'essai : un par profil, deux pour le personnel d'atelier.
 *
 * Le script en posait cinquante, tous dans le même rôle. Il était né d'un besoin précis —
 * remplir assez de lignes pour éprouver la pagination de la liste des comptes — et il est
 * resté le jeu de données par défaut une fois ce besoin passé. On se retrouvait donc avec
 * cinquante et un comptes dont aucun chef d'atelier ni chargé d'affaire : les écrans de ces
 * deux profils n'étaient pas atteignables, et la liste des comptes était illisible.
 *
 * Deux personnels d'atelier, et non un : c'est le seul profil où l'on a besoin de deux
 * personnes à la fois — affecter un plan à l'un puis à l'autre, et vérifier que chacun ne
 * voit que ses propres affectations.
 *
 * Les mots de passe sont tirés au hasard et jamais affichés : en développement, un chargé
 * de production prend la place de n'importe quel compte sans mot de passe (APP_ENV=dev),
 * et c'est par là qu'on essaie les profils. En production, ce script n'a rien à faire.
 *
 * Relançable sans doublon : un compte déjà présent est laissé tel quel.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir('/var/www/html');
require_once '/var/www/html/db.php';
// Pour l'énumération Role : les libellés en base ne disent pas le métier — « Utilisateur »
// y désigne le personnel d'atelier, « Demandeur » le chargé d'affaire. Chercher un rôle par
// son nom, comme le faisait ce script, revenait à écrire cette traduction une seconde fois.
require_once '/var/www/html/modeles/autoload.php';

$db = new MyPDO();

/** @var list<array{Role, string, string}> nom, prénom, et le rôle du compte */
$comptes = [
    [Role::ChargeDeProduction, 'Mercier', 'Paul'],
    [Role::ChefDAtelier, 'Nadal', 'Sophie'],
    [Role::PersonnelAtelier, 'Belkacem', 'Karim'],
    [Role::PersonnelAtelier, 'Fabre', 'Élodie'],
    [Role::ChargeDAffaire, 'Moreau', 'Antoine'],
];

$existe = $db->prepare('SELECT COUNT(*) FROM Utilisateur WHERE identifiant = ? OR email = ?');
$insere = $db->prepare('INSERT INTO Utilisateur (name, prenom, identifiant, email, id_role, password)
                        VALUES (?, ?, ?, ?, ?, ?)');

$crees = 0;
$passes = 0;
$db->beginTransaction();

try {
    // Le compteur par rôle n'est pas global : le second personnel d'atelier devient
    // « personnel-atelier.2 », quand les profils uniques restent sans numéro. L'identifiant
    // dit ainsi le métier, ce que « test.pagination.027 » ne faisait pas.
    $rangs = [];

    foreach ($comptes as [$role, $nom, $prenom]) {
        $rang = ($rangs[$role->value] = ($rangs[$role->value] ?? 0) + 1);
        $combien = count(array_filter($comptes, static fn (array $c): bool => $c[0] === $role));
        $identifiant = $role->abrege() . ($combien > 1 ? '.' . $rang : '');
        $courriel = $identifiant . '@example.com';

        $existe->execute([$identifiant, $courriel]);
        if ((int) $existe->fetchColumn() > 0) {
            $passes++;
            continue;
        }

        $insere->execute([
            $nom,
            $prenom,
            $identifiant,
            $courriel,
            $role->value,
            password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
        ]);
        $crees++;
    }

    $db->commit();
} catch (Throwable $erreur) {
    $db->rollBack();
    throw $erreur;
}

echo 'Comptes créés : ' . $crees . ($passes ? ' (déjà présents : ' . $passes . ')' : '') . PHP_EOL;

// Le compte par rôle, pour voir d'un coup d'œil ce qu'on peut essayer.
$repartition = $db->query('SELECT r.id, r.name, COUNT(u.id) AS combien
                           FROM role r LEFT JOIN Utilisateur u ON u.id_role = r.id
                           GROUP BY r.id, r.name ORDER BY r.id')->fetchAll(PDO::FETCH_ASSOC);

foreach ($repartition as $ligne) {
    $role = Role::depuisLaBase((int) $ligne['id']);
    printf("  %-22s %s%s", $role?->metier() ?? $ligne['name'], $ligne['combien'], PHP_EOL);
}

echo 'Total : ' . $db->query('SELECT COUNT(*) FROM Utilisateur')->fetchColumn() . ' compte(s)' . PHP_EOL;
