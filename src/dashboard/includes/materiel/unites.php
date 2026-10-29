<?php

/**
 * Les unités du matériel : celles qu'on choisit en signalant un manque.
 *
 * L'unité était un champ libre, et la même chose s'y écrivait de quatre façons — « u »,
 * « U », « pce », « pièce ». C'est maintenant une liste, que le chargé de production tient
 * dans les paramètres (rubrique Matériel) : il ajoute, renomme et retire. Le soudeur la
 * retrouve dans sa fenêtre, où il tape pour chercher et choisit.
 *
 * Un manque garde son unité en texte : renommer ou retirer une unité ne réécrit pas ceux
 * qui l'ont déjà portée.
 */

/** La longueur d'une unité : celle de la colonne des manques, demande_approvisionnement.unite. */
const UNITE_LONGUEUR = 16;

/**
 * La base connaît-elle la liste ? Elle vient de la migration 2026-10-29_unites_materiel.
 * Tant qu'elle n'est pas passée sur un serveur, la fenêtre du manque garde son champ libre.
 */
function unitesEnBase(PDO $db): bool
{
    static $presente = null;
    return $presente ??= (bool) $db->query("SHOW TABLES LIKE 'unite_materiel'")->fetch();
}

/**
 * La liste, dans l'ordre où on la lit : alphabétique, sans égard aux majuscules.
 *
 * @return list<array{id: int, libelle: string}>
 */
function unitesMateriel(PDO $db): array
{
    if (!unitesEnBase($db)) return [];
    return array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'libelle' => (string) $u['libelle']],
        $db->query('SELECT id, libelle FROM unite_materiel ORDER BY LOWER(libelle), id')->fetchAll());
}

/**
 * Un libellé d'unité, nettoyé et vérifié.
 *
 * La comparaison avec les autres se fait sans égard aux majuscules ni aux accents — c'est la
 * collation de la colonne, et la clé unique la redit : « U » est déjà « u ».
 *
 * @throws DomainException si le libellé est vide, trop long, ou déjà pris par une autre
 */
function uniteLibelle(PDO $db, string $brut, ?int $sauf = null): string
{
    $libelle = trim(preg_replace('/\s+/u', ' ', $brut));
    if ($libelle === '') throw new DomainException('Écrivez l’unité : u, m, kg…');
    if (mb_strlen($libelle) > UNITE_LONGUEUR) {
        throw new DomainException('Une unité tient en ' . UNITE_LONGUEUR . ' caractères.');
    }
    $stmt = $db->prepare('SELECT libelle FROM unite_materiel WHERE libelle = ? AND id <> ? LIMIT 1');
    $stmt->execute([$libelle, $sauf ?? 0]);
    $deja = $stmt->fetchColumn();
    if ($deja !== false) throw new DomainException('« ' . $deja . ' » est déjà dans la liste.');
    return $libelle;
}

/** @throws DomainException voir uniteLibelle() */
function uniteAjouter(PDO $db, string $brut): void
{
    $db->prepare('INSERT INTO unite_materiel (libelle) VALUES (?)')->execute([uniteLibelle($db, $brut)]);
}

/** @throws DomainException voir uniteLibelle(), ou si l'unité n'existe plus */
function uniteRenommer(PDO $db, int $id, string $brut): void
{
    $stmt = $db->prepare('UPDATE unite_materiel SET libelle = ? WHERE id = ?');
    $stmt->execute([uniteLibelle($db, $brut, $id), $id]);
    if (!$stmt->rowCount() && !uniteExiste($db, $id)) throw new DomainException('Cette unité n’existe plus. Actualisez.');
}

function uniteSupprimer(PDO $db, int $id): void
{
    $db->prepare('DELETE FROM unite_materiel WHERE id = ?')->execute([$id]);
}

function uniteExiste(PDO $db, int $id): bool
{
    $stmt = $db->prepare('SELECT 1 FROM unite_materiel WHERE id = ?');
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

/**
 * L'unité choisie dans la fenêtre du manque, telle qu'on la range.
 *
 * Elle doit être dans la liste : c'est tout l'objet de la liste. On la range sous le libellé
 * de la liste, pas sous ce qui est arrivé — « pce » choisi devient « PCE ». Sans liste (la
 * migration n'est pas passée, ou l'administration l'a vidée), on garde l'ancien champ
 * libre, et l'unité par défaut.
 *
 * @throws DomainException si une liste existe et que l'unité n'y est pas
 */
function uniteDuManque(PDO $db, string $brut): string
{
    $unite = trim($brut);
    $liste = array_column(unitesMateriel($db), 'libelle');
    if (!$liste) return $unite === '' ? 'u' : mb_substr($unite, 0, UNITE_LONGUEUR);

    if ($unite === '') throw new DomainException('Choisissez l’unité.');
    foreach ($liste as $libelle) {
        if (mb_strtolower($libelle) === mb_strtolower($unite)) return $libelle;
    }
    throw new DomainException('Choisissez l’unité dans la liste.');
}

/**
 * Les lignes du tableau des paramètres : une unité par ligne, « Modifier » et « Supprimer »
 * au bout. La saisie d'un nouveau nom se fait sur la ligne même (parametres.js) ; l'action
 * rend ces lignes après chaque changement, dans l'ordre de la liste.
 *
 * @param list<array{id: int, libelle: string}> $unites
 */
function unitesLignesRendu(array $unites): string
{
    if (!$unites) {
        return '<tr class="parametres-liste-vide"><td colspan="2">Aucune unité : le soudeur écrit la sienne à la main.</td></tr>';
    }
    $html = '';
    foreach ($unites as $unite) {
        $libelle = prefaEscape($unite['libelle']);
        $html .= '<tr data-unite="' . $unite['id'] . '">'
            . '<td class="unite-libelle">' . $libelle . '</td>'
            . '<td><button type="button" class="parametres-ligne-action" data-unite-modifier'
            . ' aria-label="Modifier l’unité ' . $libelle . '">Modifier</button>'
            . '<button type="button" class="parametres-ligne-action est-destructrice" data-unite-supprimer'
            . ' aria-label="Supprimer l’unité ' . $libelle . '">Supprimer</button></td>'
            . '</tr>';
    }
    return $html;
}
