<?php

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/plan_lignes.php';
require_once __DIR__ . '/ecriture_visa.php';
require_once __DIR__ . '/../atelier/signature.php';

/**
 * Le plan final : le plan du bureau d'études, avec par-dessus ce que l'atelier y a porté.
 *
 * On n'en redessine pas un autre. Le PDF d'origine est repris tel quel — son dessin reste
 * vectoriel, net à n'importe quel zoom, et ses cotes restent celles du bureau d'études — et
 * on lui ajoute un calque : les numéros de coulée dans la colonne « N° COULÉE », la liste
 * soudure dans son tableau. Rasteriser le plan pour écrire dessus aurait tout aplati.
 *
 * Le PDF est modifié par mise à jour incrémentale : les objets changés sont réécrits à la
 * suite du fichier, avec leur propre table de références. C'est le mécanisme prévu par le
 * format pour annoter sans toucher à l'existant — rien de ce qu'a produit AutoCAD n'est
 * relu ni réencodé, donc rien ne peut être abîmé.
 */

/** Les positions sont des fractions de l'image du plan : il faut la page pour les placer. */
function planFinalGeometriePage(string $pdf, int $page = 1): ?array
{
    $sortie = [];
    exec(sprintf('pdfinfo -f %d -l %d %s 2>/dev/null', $page, $page, escapeshellarg($pdf)), $sortie);
    $infos = implode("\n", $sortie);
    if (!preg_match('/^Page\s+\d+\s+size:\s+([\d.]+)\s+x\s+([\d.]+)/m', $infos, $taille)
        && !preg_match('/^Page\s+size:\s+([\d.]+)\s+x\s+([\d.]+)/m', $infos, $taille)) {
        return null;
    }
    preg_match('/^Page\s+(?:\d+\s+)?rot:\s+(-?\d+)/m', $infos, $tourne);
    return [
        'largeur' => (float) $taille[1],
        'hauteur' => (float) $taille[2],
        // La rotation est une consigne d'affichage : le lecteur tourne la page sans toucher
        // à ses coordonnées. L'aperçu que l'atelier a annoté est déjà tourné, donc nos
        // positions sont dans la page vue — elles doivent revenir dans la page stockée.
        'rotation' => ((int) ($tourne[1] ?? 0) % 360 + 360) % 360,
    ];
}

/**
 * Place un point donné en fractions de l'image affichée dans le repère de la page PDF.
 *
 * @return array{0: float, 1: float} en points, origine en bas à gauche de la page stockée
 */
function planFinalPoint(float $fx, float $fy, array $page): array
{
    $l = $page['largeur'];
    $h = $page['hauteur'];
    // À 90° et 270°, l'image affichée a la largeur de la hauteur de la page, et vice versa.
    return match ($page['rotation']) {
        90 => [$fy * $l, $fx * $h],
        180 => [(1 - $fx) * $l, $fy * $h],
        270 => [(1 - $fy) * $l, (1 - $fx) * $h],
        default => [$fx * $l, (1 - $fy) * $h],
    };
}

/**
 * Le flux à ajouter à la page : les textes et les traits, dans le repère de la page.
 *
 * Le texte est tourné comme la page pour rester lisible une fois la page redressée.
 *
 * Un texte marqué « visa » s'écrit dans l'écriture du visa (ecriture_visa.php) : la police
 * doit alors avoir été ajoutée au PDF (planFinalEcritureVisa()). S'il porte une signature,
 * c'est elle qui se dessine, et la police n'est pas nécessaire.
 *
 * @param list<array{texte:string, x:float, y:float, w:float, h:float, police?:float, visa?:bool, signature?:array}> $textes positions en fractions
 * @param list<array{x1:float, y1:float, x2:float, y2:float}> $traits
 */
function planFinalFlux(array $textes, array $traits, array $page, float $epaisseurTrait): string
{
    // La matrice qui oriente le texte comme le dessin de la page.
    $matrice = match ($page['rotation']) {
        90 => [0.0, 1.0, -1.0, 0.0],
        180 => [-1.0, 0.0, 0.0, -1.0],
        270 => [0.0, -1.0, 1.0, 0.0],
        default => [1.0, 0.0, 0.0, 1.0],
    };
    // Le côté de l'image affichée sur lequel se mesurent hauteurs et largeurs.
    $cote = in_array($page['rotation'], [90, 270], true)
        ? ['h' => $page['largeur'], 'l' => $page['hauteur']]
        : ['h' => $page['hauteur'], 'l' => $page['largeur']];

    // q/Q encadre le tout : l'état graphique de la page est rendu intact, sans quoi notre
    // couleur ou notre épaisseur de trait déborderait sur le dessin.
    $flux = "q\n0 0 0 rg 0 0 0 RG\n";

    foreach ($traits as $trait) {
        [$x1, $y1] = planFinalPoint($trait['x1'], $trait['y1'], $page);
        [$x2, $y2] = planFinalPoint($trait['x2'], $trait['y2'], $page);
        $flux .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $epaisseurTrait, $x1, $y1, $x2, $y2);
    }

    foreach ($textes as $champ) {
        $texte = trim($champ['texte']);
        if ($texte === '') continue;

        if (!empty($champ['signature'])) {
            $flux .= planFinalSignature($champ['signature'], $champ, $page, $cote);
            continue;
        }
        if (!empty($champ['visa'])) {
            $flux .= planFinalVisa($texte, $champ, $page, $cote, $matrice);
            continue;
        }

        // Même règle qu'à l'écran : la police vaut 1,24 fois la hauteur de la case, qui est
        // en retrait des traits. Le texte sort ainsi à la taille des lettres imprimées.
        $corps = $champ['h'] * $cote['h'] * ($champ['police'] ?? PLAN_FINAL_POLICE);
        $largeurMax = $champ['w'] * $cote['l'];
        // Un nom trop long pour sa colonne rétrécit plutôt que de mordre sur la voisine.
        $largeur = PdfPlan::largeurPoints($texte, $corps);
        if ($largeur > $largeurMax && $largeur > 0) $corps *= $largeurMax / $largeur;
        if ($corps < 0.5) continue;

        // Centré dans la case, horizontalement et verticalement. Le 0,36 place la ligne de
        // base : une capitale occupe environ 0,72 du corps, elle se centre à la moitié.
        $largeur = PdfPlan::largeurPoints($texte, $corps);
        $fx = $champ['x'] + ($champ['w'] - $largeur / $cote['l']) / 2;
        $fy = $champ['y'] + $champ['h'] / 2 + 0.36 * $corps / $cote['h'];
        [$x, $y] = planFinalPoint($fx, $fy, $page);

        $flux .= sprintf("BT /%s %.2f Tf %.4f %.4f %.4f %.4f %.2f %.2f Tm (%s) Tj ET\n",
            PLAN_FINAL_POLICE_NOM, $corps,
            $matrice[0], $matrice[1], $matrice[2], $matrice[3], $x, $y,
            PdfPlan::chaine($texte));
    }

    return $flux . "Q\n";
}

/**
 * Le visa dans sa case : le nom, dans l'écriture du visa, réglé sur sa propre encre.
 *
 * Une écriture manuscrite a de grandes capitales, de longs jambages et de petites
 * minuscules. Réglé comme le reste de la liste — sur la hauteur de la case —, le nom laissait
 * du blanc au-dessus et au-dessous de ses minuscules, et se lisait mal. Il se règle donc sur
 * l'encre de ses lettres (visaEncre()) : du haut de sa plus haute boucle au bas de son plus
 * long jambage, elle occupe toute la ligne à une fine marge près, centrée. Un nom trop long
 * rétrécit pour tenir en largeur. Même calcul qu'à l'écran (plan-viewer.js, dessinerVisa).
 *
 * @param array{x:float, y:float, w:float, h:float} $champ la case, en fractions de la page
 * @param array{h:float, l:float} $cote les côtés de la page affichée, en points
 * @param list<float> $matrice l'orientation du texte
 */
function planFinalVisa(string $texte, array $champ, array $page, array $cote, array $matrice): string
{
    $encre = visaEncre($texte);
    $hauteurEncre = max(0.2, $encre['haut'] - $encre['bas']);
    $largeurMax = $champ['w'] * $cote['l'];
    $corps = min($champ['h'] * $cote['h'] * PLAN_FINAL_VISA / $hauteurEncre,
        $largeurMax / max(0.01, visaLargeur($texte, 1)));
    if ($corps < 0.5) return '';

    // L'encre centrée sur le milieu de la case : la ligne de base tombe sous le milieu de la
    // demi-somme de son haut et de son bas.
    $fx = $champ['x'] + ($champ['w'] - visaLargeur($texte, $corps) / $cote['l']) / 2;
    $fy = $champ['y'] + $champ['h'] / 2 + ($encre['haut'] + $encre['bas']) / 2 * $corps / $cote['h'];
    [$x, $y] = planFinalPoint($fx, $fy, $page);

    return sprintf("BT /%s %.2f Tf %.4f %.4f %.4f %.4f %.2f %.2f Tm (%s) Tj ET\n",
        PLAN_FINAL_VISA_NOM, $corps,
        $matrice[0], $matrice[1], $matrice[2], $matrice[3], $x, $y,
        PdfPlan::chaine($texte));
}

/**
 * Le visa dans sa case quand celui qui a validé a tracé sa signature : ses traits, réglés
 * comme l'encre du nom — la signature occupe la ligne à une fine marge près, centrée, et
 * rétrécit pour tenir en largeur. Même calcul qu'à l'écran (plan-viewer.js,
 * dessinerSignature).
 *
 * Des courbes du PDF, pas une image : nettes à tous les zooms, et rien à embarquer. Chaque
 * point passe par planFinalPoint(), qui tourne avec la page ; une courbe reste une courbe
 * sous cette transformation, ses points de contrôle suffisent.
 *
 * @param array{proportion: float, traits: list<list<array{0: float, 1: float}>>} $dessin
 * @param array{x:float, y:float, w:float, h:float} $champ la case, en fractions de la page
 * @param array{h:float, l:float} $cote les côtés de la page affichée, en points
 */
function planFinalSignature(array $dessin, array $champ, array $page, array $cote): string
{
    $hauteurMax = $champ['h'] * $cote['h'] * PLAN_FINAL_VISA;
    $epaisseur = $hauteurMax * SIGNATURE_TRAIT;
    // Points par hauteur de signature. Le trait déborde de sa demi-épaisseur tout autour.
    $echelle = min($hauteurMax - $epaisseur, ($champ['w'] * $cote['l'] - $epaisseur) / $dessin['proportion']);
    if ($echelle < 0.5) return '';

    // Le coin haut gauche de la signature, centrée dans la case, en fractions de la page.
    $gauche = $champ['x'] + ($champ['w'] - $dessin['proportion'] * $echelle / $cote['l']) / 2;
    $haut = $champ['y'] + ($champ['h'] - $echelle / $cote['h']) / 2;
    $point = static fn (float $x, float $y): string =>
        vsprintf('%.2f %.2f', planFinalPoint($gauche + $x * $echelle / $cote['l'], $haut + $y * $echelle / $cote['h'], $page));

    $trace = '';
    $depuis = [0.0, 0.0];
    foreach (signatureCommandes($dessin) as $commande) {
        if ($commande[0] === 'Q') {
            // Le PDF ne connaît que les courbes du troisième degré : la même courbe s'y écrit
            // avec deux points de contrôle, aux deux tiers de chaque extrémité vers celui-ci.
            [, $cx, $cy, $x, $y] = $commande;
            $trace .= $point($depuis[0] + 2 / 3 * ($cx - $depuis[0]), $depuis[1] + 2 / 3 * ($cy - $depuis[1])) . ' '
                . $point($x + 2 / 3 * ($cx - $x), $y + 2 / 3 * ($cy - $y)) . ' ' . $point($x, $y) . " c\n";
        } else {
            [, $x, $y] = $commande;
            $trace .= $point($x, $y) . ($commande[0] === 'M' ? " m\n" : " l\n");
        }
        $depuis = [$x, $y];
    }
    // Extrémités et jonctions rondes, comme une plume : un point seul devient un rond.
    return sprintf("q 1 J 1 j %.2f w\n%sS Q\n", $epaisseur, $trace);
}

/**
 * L'écriture du visa dans le PDF : son nom de ressource, et la hauteur que son encre prend
 * par rapport à la case. La case est en retrait des traits, aux deux tiers de la ligne :
 * 1,4 fois sa hauteur, c'est 90 % de la ligne — une fine marge reste sous chaque trait. Le
 * même facteur qu'à l'écran (VISA_ENCRE, plan-viewer.js).
 */
const PLAN_FINAL_VISA_NOM = 'PlanFinalVisa';
const PLAN_FINAL_VISA = 1.4;

/** La police du calque, en gras : le numéro doit se distinguer du dessin sous lui. */
const PLAN_FINAL_POLICE_NOM = 'PlanFinalF1';
/** Même facteur qu'à l'écran : mesuré sur les lettres du bureau d'études. */
const PLAN_FINAL_POLICE = 1.24;

/**
 * Ajoute le calque au PDF, par mise à jour incrémentale.
 *
 * Le PDF est pris et rendu en mémoire : le résultat se repasse tel quel pour annoter une
 * autre page. Les révisions s'empilent alors à la suite, chacune ne réécrivant que sa
 * page — c'est ainsi qu'un cahier ressort entier, chaque isométrique portant sa liste.
 *
 * @param string $brut le PDF d'origine, ou le retour d'un appel précédent
 * @param int|null $ecritureVisa l'objet de l'écriture du visa, quand la page en porte
 *        (planFinalEcritureVisa()) : elle rejoint les polices de la page
 * @return string le PDF complet, original intact suivi de la révision
 * @throws RuntimeException si le fichier n'a pas la forme attendue
 */
function planFinalComposer(string $brut, string $flux, int $page = 1, ?int $ecritureVisa = null): string
{
    planFinalVerifier($brut);
    [$numeroPage, $dictionnaire] = planFinalTrouverPage($brut, $page);
    $maxObjet = planFinalDernierObjet($brut);

    // Quatre objets ajoutés : la police, l'ouverture d'état, le calque, et la page réécrite.
    $objPolice = $maxObjet + 1;
    $objOuvre = $maxObjet + 2;
    $objFlux = $maxObjet + 3;

    // Le contenu d'origine est encadré par q … Q. Sans cela le calque hériterait de ce que
    // le producteur a laissé actif : les plans d'AutoCAD ouvrent par « 0.06 0 0 0.06 0 0 cm »
    // et ne l'annulent jamais — tout ce qu'on écrirait ensuite serait réduit à 6 % et
    // s'entasserait dans un coin de la feuille. Le Q rend le repère de la page.
    $ouverture = "q\n";
    $calque = "Q\n" . $flux;

    $contenus = planFinalContenus($brut, $dictionnaire);
    $polices = '/' . PLAN_FINAL_POLICE_NOM . ' ' . $objPolice . ' 0 R'
        . ($ecritureVisa !== null ? ' /' . PLAN_FINAL_VISA_NOM . ' ' . $ecritureVisa . ' 0 R' : '');
    $ressources = planFinalRessources($dictionnaire, $polices);
    // Le nouveau dictionnaire de page : mêmes clés, /Contents et /Resources remplacés.
    $nouvellePage = planFinalRemplacerCle($dictionnaire, 'Contents',
        '[' . $objOuvre . ' 0 R ' . substr($contenus, 1) . ' ' . $objFlux . ' 0 R]');
    $nouvellePage = planFinalRemplacerCle($nouvellePage, 'Resources', $ressources);
    $nouvellePage = planFinalNettoyerAnnotations($brut, $nouvellePage);

    return planFinalRevision($brut, [
        $objPolice => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        $objOuvre => '<< /Length ' . strlen($ouverture) . " >>\nstream\n" . $ouverture . 'endstream',
        $objFlux => '<< /Length ' . strlen($calque) . " >>\nstream\n" . $calque . 'endstream',
        $numeroPage => '<<' . $nouvellePage . '>>',
    ]);
}

/**
 * Ajoute au PDF l'écriture du visa — la police, embarquée — en une révision à elle.
 *
 * Une seule fois pour tout le document, avant les pages : chaque page qui porte un visa la
 * nomme dans ses ressources (planFinalComposer()), sans la recopier. Quinze feuilles visées
 * ne pèsent pas quinze polices.
 *
 * Elle est déclarée en TrueType simple, encodée en WinAnsi comme le reste du calque : ses
 * chasses, sa boîte et ses hauteurs viennent du fichier (visaEcriture()), et le fichier
 * lui-même part compressé.
 *
 * @return array{0: string, 1: int} le PDF complété, et le numéro d'objet de la police
 */
function planFinalEcritureVisa(string $brut): array
{
    planFinalVerifier($brut);
    $ecriture = visaEcriture();
    $maxObjet = planFinalDernierObjet($brut);
    [$objPolice, $objDescripteur, $objFichier] = [$maxObjet + 1, $maxObjet + 2, $maxObjet + 3];

    $compresse = gzcompress($ecriture['fichier'], 9);
    $nom = '/' . $ecriture['nom'];
    return [planFinalRevision($brut, [
        $objPolice => '<< /Type /Font /Subtype /TrueType /BaseFont ' . $nom
            . ' /FirstChar 32 /LastChar 255 /Widths [' . implode(' ', $ecriture['chasses']) . ']'
            . ' /Encoding /WinAnsiEncoding /FontDescriptor ' . $objDescripteur . ' 0 R >>',
        // Drapeau 32 : une police « non symbolique », dont les caractères se lisent par
        // leur code Unicode — c'est ce qui fait trouver « é » dans la table du fichier.
        $objDescripteur => '<< /Type /FontDescriptor /FontName ' . $nom . ' /Flags 32'
            . ' /FontBBox [' . implode(' ', $ecriture['boite']) . '] /ItalicAngle 0'
            . ' /Ascent ' . $ecriture['ascendante'] . ' /Descent ' . $ecriture['descendante']
            . ' /CapHeight ' . $ecriture['capitales'] . ' /StemV 80 /FontFile2 ' . $objFichier . ' 0 R >>',
        $objFichier => '<< /Length ' . strlen($compresse) . ' /Length1 ' . strlen($ecriture['fichier'])
            . " /Filter /FlateDecode >>\nstream\n" . $compresse . "\nendstream",
    ]), $objPolice];
}

/**
 * Le PDF se laisse-t-il compléter ?
 *
 * @throws RuntimeException si le fichier n'a pas la forme attendue
 */
function planFinalVerifier(string $brut): void
{
    if (strncmp($brut, '%PDF-', 5) !== 0) {
        throw new RuntimeException('Le plan n’est pas un PDF lisible.');
    }
    // Les PDF récents rangent leurs objets dans des flux compressés : les retrouver
    // demanderait de décompresser tout le fichier. Les plans d'AutoCAD n'en ont pas.
    if (preg_match('~/Type\s*/ObjStm~', $brut)) {
        throw new RuntimeException('Ce PDF est compressé d’une façon que le plan final ne sait pas annoter.');
    }
    if (!preg_match('/startxref\s+(\d+)\s*%%EOF\s*$/s', $brut)) {
        throw new RuntimeException('Ce PDF n’a pas de table de références exploitable.');
    }
}

/**
 * Écrit une révision à la suite du PDF : les objets donnés, leur table de références, et la
 * remorque qui renvoie à la table précédente pour tout le reste.
 *
 * @param array<int, string> $objets numéro d'objet => son contenu, entre « obj » et « endobj »
 */
function planFinalRevision(string $brut, array $objets): string
{
    preg_match('/startxref\s+(\d+)\s*%%EOF\s*$/s', $brut, $fin);
    $maxObjet = planFinalDernierObjet($brut);

    $ajout = "\n";
    $positions = [];
    foreach ($objets as $numero => $contenu) {
        $positions[$numero] = strlen($brut) + strlen($ajout);
        $ajout .= $numero . " 0 obj\n" . $contenu . "\nendobj\n";
    }

    // La table de références de la révision : seuls les objets réécrits y figurent, et
    // /Prev renvoie à la table d'origine pour tout le reste.
    ksort($positions);
    $xref = "xref\n";
    foreach (planFinalGroupes(array_keys($positions)) as $groupe) {
        $xref .= $groupe[0] . ' ' . count($groupe) . "\n";
        foreach ($groupe as $numero) {
            $xref .= sprintf("%010d %05d n \n", $positions[$numero], 0);
        }
    }

    $debutXref = strlen($brut) + strlen($ajout);
    $ajout .= $xref . "trailer\n<< /Size " . (max($maxObjet, ...array_keys($objets)) + 1) . ' ' . planFinalRacine($brut)
        . ' /Prev ' . (int) $fin[1] . " >>\nstartxref\n" . $debutXref . "\n%%EOF\n";

    return $brut . $ajout;
}

/** Les numéros consécutifs se déclarent par groupes dans la table de références. */
function planFinalGroupes(array $numeros): array
{
    $groupes = [];
    foreach ($numeros as $numero) {
        $dernier = $groupes ? end($groupes) : null;
        if ($dernier !== null && $numero === end($dernier) + 1) {
            $groupes[count($groupes) - 1][] = $numero;
        } else {
            $groupes[] = [$numero];
        }
    }
    return $groupes;
}

/**
 * Le dictionnaire de la page demandée, et son numéro d'objet.
 *
 * @return array{0: int, 1: string}
 */
function planFinalTrouverPage(string $brut, int $page): array
{
    // Un plan déjà annoté — un plan final redéposé comme plan — porte des révisions : sa
    // page y apparaît deux fois, sous le même numéro d'objet. C'est la DERNIÈRE définition
    // qui fait foi : repartir de la première jetterait le calque précédent, alors que
    // c'est lui que le lecteur affiche et que la détection a mesuré pour savoir quelles
    // cases sont déjà écrites. Les deux doivent regarder la même page.
    //
    // L'ordre des pages reste celui de leur première apparition : une révision réécrit
    // une page, elle n'en ajoute pas.
    $ordre = [];
    $corpsParObjet = [];
    $position = 0;
    while (preg_match('/(\d+)\s+0\s+obj\b/', $brut, $m, PREG_OFFSET_CAPTURE, $position)) {
        $numero = (int) $m[1][0];
        $debut = $m[0][1] + strlen($m[0][0]);
        $fin = strpos($brut, 'endobj', $debut);
        if ($fin === false) break;
        $position = $fin + 6;

        $corps = substr($brut, $debut, $fin - $debut);
        // « /Type /Page » et pas « /Pages » : le nœud parent porte le même préfixe.
        if (!preg_match('~/Type\s*/Page(?![a-zA-Z])~', $corps)) continue;
        if (!isset($corpsParObjet[$numero])) $ordre[] = $numero;
        $corpsParObjet[$numero] = $corps;
    }

    $numero = $ordre[$page - 1] ?? null;
    if ($numero !== null) {
        $corps = $corpsParObjet[$numero];
        $ouvre = strpos($corps, '<<');
        $ferme = strrpos($corps, '>>');
        if ($ouvre !== false && $ferme !== false) {
            return [$numero, substr($corps, $ouvre + 2, $ferme - $ouvre - 2)];
        }
    }
    throw new RuntimeException('Page ' . $page . ' introuvable dans le plan.');
}

function planFinalDernierObjet(string $brut): int
{
    preg_match_all('/(\d+)\s+0\s+obj\b/', $brut, $tous);
    $max = $tous[1] ? max(array_map('intval', $tous[1])) : 0;
    // Le /Size du fichier d'origine fait foi s'il est plus grand : des numéros peuvent
    // être réservés sans qu'aucun objet ne porte leur en-tête.
    if (preg_match_all('~/Size\s+(\d+)~', $brut, $tailles)) {
        $max = max($max, max(array_map('intval', $tailles[1])) - 1);
    }
    return $max;
}

/** La référence /Root, reprise telle quelle : le catalogue ne change pas. */
function planFinalRacine(string $brut): string
{
    if (preg_match_all('~/Root\s+(\d+\s+\d+\s+R)~', $brut, $m)) {
        return '/Root ' . end($m[1]);
    }
    throw new RuntimeException('Ce PDF n’a pas de catalogue repérable.');
}

/**
 * Le /Contents existant, ouvert en tableau pour que le calque s'ajoute à la suite.
 *
 * Le crochet fermant n'est pas posé ici : l'appelant y glisse d'abord sa référence. Un
 * flux ajouté après ceux de la page s'y dessine par-dessus, ce qui est bien le but.
 *
 * /Contents prend trois formes, et la troisième est traîtresse : un tableau écrit sur
 * place, un flux désigné par référence, ou une référence vers un TABLEAU de flux. Prendre
 * la troisième pour la deuxième produit un tableau contenant un tableau — que le format
 * interdit, et que les lecteurs refusent d'afficher.
 */
function planFinalContenus(string $brut, string $dictionnaire): string
{
    if (preg_match('~/Contents\s*\[(.*?)\]~s', $dictionnaire, $m)) {
        return '[' . trim($m[1]);
    }
    if (!preg_match('~/Contents\s+(\d+)\s+\d+\s+R~', $dictionnaire, $m)) {
        throw new RuntimeException('Cette page n’a pas de contenu repérable.');
    }

    // L'objet désigné : tableau de flux, ou flux unique ?
    $numero = (int) $m[1];
    if (preg_match('/(?<![0-9])' . $numero . '\s+\d+\s+obj\s*(.{0,32})/s', $brut, $corps)) {
        $debut = ltrim($corps[1]);
        if (str_starts_with($debut, '[')) {
            // On recopie le contenu du tableau plutôt que sa référence : ses flux
            // rejoignent les nôtres dans une seule liste, à plat.
            $position = strpos($brut, '[', strpos($brut, $corps[0]));
            $fin = strpos($brut, ']', $position);
            if ($fin !== false) return '[' . trim(substr($brut, $position + 1, $fin - $position - 1));
        }
    }
    return '[' . $m[1] . ' 0 R';
}

/**
 * Les ressources de la page, avec nos polices ajoutées.
 *
 * Le dictionnaire est pris tel quel et seule l'entrée /Font est touchée : les calques,
 * les états graphiques et les polices du bureau d'études restent intacts.
 *
 * @param string $police nos entrées de police, prêtes : « /PlanFinalF1 12 0 R … »
 */
function planFinalRessources(string $dictionnaire, string $police): string
{
    if (!preg_match('~/Resources\s*<<~', $dictionnaire)) {
        // Une page sans ressources propres les hérite de son parent. On ne touche pas au
        // parent : la page reçoit les siennes, qui ne contiennent que notre police.
        return '<< /Font << ' . $police . ' >> /ProcSet [/PDF /Text] >>';
    }

    $debut = planFinalFinDuDictionnaire($dictionnaire, strpos($dictionnaire, '/Resources'));
    $ressources = substr($dictionnaire, $debut['ouvre'], $debut['fin'] - $debut['ouvre']);
    $interieur = substr($ressources, 2, -2);

    if (preg_match('~/Font\s*<<~', $interieur)) {
        $fonte = planFinalFinDuDictionnaire($interieur, strpos($interieur, '/Font'));
        $avant = substr($interieur, 0, $fonte['fin'] - 2);
        return '<<' . $avant . ' ' . $police . ' >>' . substr($interieur, $fonte['fin']) . '>>';
    }
    if (preg_match('~/Font\s+\d+\s+\d+\s+R~', $interieur)) {
        // Les polices sont dans un objet à part : on ne le réécrit pas, on met les deux
        // dictionnaires côte à côte serait ambigu — la page prend donc les siennes.
        return '<< /Font << ' . $police . ' >> /ProcSet [/PDF /Text] >>';
    }
    return '<<' . $interieur . ' /Font << ' . $police . ' >> >>';
}

/**
 * Les bornes du dictionnaire << >> qui suit une clé, en comptant les imbrications.
 *
 * @return array{ouvre: int, fin: int}
 */
function planFinalFinDuDictionnaire(string $texte, int $depuis): array
{
    $ouvre = strpos($texte, '<<', $depuis);
    if ($ouvre === false) throw new RuntimeException('Dictionnaire PDF illisible.');
    $profondeur = 0;
    for ($i = $ouvre; $i < strlen($texte) - 1; $i++) {
        if ($texte[$i] === '<' && $texte[$i + 1] === '<') { $profondeur++; $i++; continue; }
        if ($texte[$i] === '>' && $texte[$i + 1] === '>') {
            if (--$profondeur === 0) return ['ouvre' => $ouvre, 'fin' => $i + 2];
            $i++;
        }
    }
    throw new RuntimeException('Dictionnaire PDF non refermé.');
}

/**
 * Retire /Annots quand il désigne des objets absents du fichier.
 *
 * Extraire une page avec pdfseparate laisse ce tableau derrière lui sans recopier ce qu'il
 * pointe : le PDF obtenu porte alors des dizaines de références mortes, et les lecteurs
 * s'en plaignent à chaque ouverture. Sur les plans AutoCAD ce sont des marqueurs invisibles
 * — /F 64, sans bordure — qui doublent le texte tracé en police SHX : rien de visible n'est
 * perdu. Un tableau dont les objets sont bien là n'est pas touché.
 */
function planFinalNettoyerAnnotations(string $brut, string $dictionnaire): string
{
    if (!preg_match('~/Annots\s*\[([^\]]*)\]~s', $dictionnaire, $m)) return $dictionnaire;
    preg_match_all('/(\d+)\s+\d+\s+R/', $m[1], $refs);
    foreach ($refs[1] as $ref) {
        if (!preg_match('/(?<![0-9])' . $ref . '\s+\d+\s+obj\b/', $brut)) {
            return planFinalRemplacerCle($dictionnaire, 'Annots', '[]');
        }
    }
    return $dictionnaire;
}

/** Remplace la valeur d'une clé du dictionnaire de page. */
function planFinalRemplacerCle(string $dictionnaire, string $cle, string $valeur): string
{
    $motif = '~/' . $cle . '\s*(?:\[[^\]]*\]|<<|\d+\s+\d+\s+R|/[^\s/>\[]+)~s';
    if (!preg_match($motif, $dictionnaire, $m, PREG_OFFSET_CAPTURE)) {
        return $dictionnaire . ' /' . $cle . ' ' . $valeur;
    }
    $debut = $m[0][1];
    $fin = $debut + strlen($m[0][0]);
    // Une valeur qui est un dictionnaire doit être sautée en entier.
    if (str_ends_with($m[0][0], '<<')) {
        $fin = planFinalFinDuDictionnaire($dictionnaire, $debut)['fin'];
    }
    return substr($dictionnaire, 0, $debut) . '/' . $cle . ' ' . $valeur . substr($dictionnaire, $fin);
}
