<?php
/**
 * Un classeur Excel (.xlsx) d'une feuille, écrit à la main.
 *
 * L'image PHP n'a ni l'extension zip ni bibliothèque de tableur, et un export n'en demande
 * pas tant : un .xlsx est un paquet ZIP de quelques fichiers XML (Office Open XML). Le ZIP
 * s'écrit ici, compressé par zlib, présente partout.
 *
 * Les styles sont ceux de la maison (docs/direction-artistique.md) : la pile système
 * (Segoe UI), l'accent #006399 en tête de tableau et en trait sous le titre, le fond de
 * carte #F9F9F9 pour les propriétés et de groupe #F2F2F3 pour le total, les filets #EAEBEC
 * entre les lignes, et les couleurs de sens — succès, avertissement — pour dire un état.
 *
 * Les textes s'écrivent en chaînes en ligne : jamais en formule, quoi qu'ils contiennent.
 */
final class Classeur
{
    /** Les styles, par nom, dans l'ordre de « cellXfs » (stylesXml()). */
    private const STYLES = [
        'normal' => 0,
        'titre' => 1,
        'sous-titre' => 2,
        'etiquette' => 3,
        'valeur' => 4,
        'valeur-accent' => 5,
        'entete' => 6,
        'entete-droite' => 7,
        'entete-centre' => 8,
        'texte' => 9,
        'texte-sourd' => 10,
        'nombre' => 11,
        'date' => 12,
        'date-sourde' => 13,
        'etat-recu' => 14,
        'etat-retard' => 15,
        'etat-attente' => 16,
        'total' => 17,
        'note' => 18,
        'vide-ligne' => 19,
    ];

    /** @var array<int, array<int, array{0: string, 1: string, 2: int}>> ligne => colonne => [type, valeur, style] */
    private array $cellules = [];
    /** @var array<int, float> */
    private array $hauteurs = [];
    /** @var list<string> */
    private array $fusions = [];
    /** @var list<float> */
    private array $largeurs = [];
    private ?string $filtre = null;
    private int $figer = 0;
    private int $repeter = 0;
    /** @var array{png: string, largeur: int, hauteur: int, rogne: float}|null */
    private ?array $logo = null;
    private string $pied = '';
    private string $titre = '';

    public function __construct(private readonly string $feuille, private readonly string $auteur = 'IDEM Préfabrication')
    {
    }

    public function texte(int $ligne, int $colonne, string $texte, string $style = 'texte'): void
    {
        $texte = self::nettoyer($texte);
        $this->cellules[$ligne][$colonne] = $texte === '' ? ['', '', self::style($style)] : ['s', $texte, self::style($style)];
    }

    public function nombre(int $ligne, int $colonne, float|int|string|null $nombre, string $style = 'nombre'): void
    {
        if ($nombre === null || $nombre === '' || !is_numeric($nombre)) {
            $this->vide($ligne, $colonne, $style);
            return;
        }
        // Sans exposant ni séparateur local : « 12.5 », que le tableur affiche à sa façon.
        $ecrit = rtrim(rtrim(number_format((float) $nombre, 3, '.', ''), '0'), '.');
        $this->cellules[$ligne][$colonne] = ['n', $ecrit === '-0' ? '0' : $ecrit, self::style($style)];
    }

    /** Une date « 2026-11-09 » ou « 2026-11-09 14:32:00 », en vraie date du tableur : elle se trie et se filtre. */
    public function date(int $ligne, int $colonne, ?string $date, string $style = 'date'): void
    {
        $jour = $date ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), new DateTimeZone('UTC')) : false;
        if (!$jour) {
            $this->vide($ligne, $colonne, $style);
            return;
        }
        $origine = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
        $this->cellules[$ligne][$colonne] = ['n', $origine->diff($jour)->format('%a'), self::style($style)];
    }

    /** Une cellule sans valeur, mais avec son fond et ses filets. */
    public function vide(int $ligne, int $colonne, string $style): void
    {
        $this->cellules[$ligne][$colonne] = ['', '', self::style($style)];
    }

    public function hauteur(int $ligne, float $points): void
    {
        $this->hauteurs[$ligne] = $points;
    }

    public function fusionner(int $ligne, int $de, int $a): void
    {
        $this->fusions[] = self::colonne($de) . $ligne . ':' . self::colonne($a) . $ligne;
    }

    /** @param list<float> $largeurs en caractères, colonne A d'abord */
    public function largeurs(array $largeurs): void
    {
        $this->largeurs = $largeurs;
    }

    /** Les flèches de filtre du tableur, sur l'en-tête d'un tableau. */
    public function filtre(int $ligneEntete, int $derniereLigne, int $colonnes): void
    {
        $this->filtre = 'A' . $ligneEntete . ':' . self::colonne($colonnes) . $derniereLigne;
    }

    /** Fige les lignes jusqu'à celle-ci : l'en-tête du tableau reste à l'écran. */
    public function figer(int $ligne): void
    {
        $this->figer = $ligne;
    }

    /** Répète cette ligne en tête de chaque page imprimée. */
    public function repeter(int $ligne): void
    {
        $this->repeter = $ligne;
    }

    /**
     * Le logo, en haut à gauche, à cette hauteur. $rogne retire une part du bord droit de
     * l'image — le dessin de la maison laisse un blanc à droite du mot.
     */
    public function logo(string $png, int $hauteurPx, float $rogne = 0.0): void
    {
        if (strlen($png) < 24 || substr($png, 1, 3) !== 'PNG') return;
        ['l' => $largeur, 'h' => $hauteur] = unpack('Nl/Nh', substr($png, 16, 8));
        if ($largeur < 1 || $hauteur < 1) return;
        $rogne = max(0.0, min(0.9, $rogne));
        $this->logo = [
            'png' => $png,
            'hauteur' => $hauteurPx,
            'largeur' => (int) round($largeur * (1 - $rogne) / $hauteur * $hauteurPx),
            'rogne' => $rogne,
        ];
    }

    /** Le pied des pages imprimées, à gauche ; le numéro de page est à droite. */
    public function pied(string $texte): void
    {
        $this->pied = self::nettoyer($texte);
    }

    /** Le titre du document, dans ses propriétés. */
    public function titre(string $titre): void
    {
        $this->titre = self::nettoyer($titre);
    }

    /** Le classeur, prêt à envoyer. */
    public function xlsx(): string
    {
        $fichiers = [
            '[Content_Types].xml' => $this->typesXml(),
            '_rels/.rels' => self::relations([
                ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'xl/workbook.xml'],
                ['rId2', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
                ['rId3', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties', 'docProps/app.xml'],
            ]),
            'docProps/core.xml' => $this->proprietesXml(),
            'docProps/app.xml' => self::ENTETE_XML
                . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
                . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
                . '<Application>' . self::x($this->auteur) . '</Application></Properties>',
            'xl/workbook.xml' => $this->classeurXml(),
            'xl/_rels/workbook.xml.rels' => self::relations([
                ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'worksheets/sheet1.xml'],
                ['rId2', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles', 'styles.xml'],
            ]),
            'xl/styles.xml' => self::stylesXml(),
            'xl/worksheets/sheet1.xml' => $this->feuilleXml(),
        ];
        if ($this->logo) {
            $fichiers['xl/worksheets/_rels/sheet1.xml.rels'] = self::relations([
                ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing', '../drawings/drawing1.xml'],
            ]);
            $fichiers['xl/drawings/drawing1.xml'] = $this->dessinXml();
            $fichiers['xl/drawings/_rels/drawing1.xml.rels'] = self::relations([
                ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', '../media/image1.png'],
            ]);
            $fichiers['xl/media/image1.png'] = $this->logo['png'];
        }
        return self::zip($fichiers);
    }

    // ------------------------------------------------------------------ Les parties XML

    private const ENTETE_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    private function typesXml(): string
    {
        $types = self::ENTETE_XML
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        if ($this->logo) {
            $types .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
        }
        return $types . '</Types>';
    }

    private function proprietesXml(): string
    {
        $maintenant = gmdate('Y-m-d\TH:i:s\Z');
        return self::ENTETE_XML
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . ($this->titre !== '' ? '<dc:title>' . self::x($this->titre) . '</dc:title>' : '')
            . '<dc:creator>' . self::x($this->auteur) . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $maintenant . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $maintenant . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function classeurXml(): string
    {
        $nom = "'" . str_replace("'", "''", $this->feuille) . "'";
        $noms = '';
        if ($this->filtre) {
            [$debut, $fin] = explode(':', $this->filtre);
            $noms .= '<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
                . self::x($nom . '!' . self::absolue($debut) . ':' . self::absolue($fin)) . '</definedName>';
        }
        if ($this->repeter) {
            $noms .= '<definedName name="_xlnm.Print_Titles" localSheetId="0">'
                . self::x($nom . '!$' . $this->repeter . ':$' . $this->repeter) . '</definedName>';
        }
        return self::ENTETE_XML
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="28800" windowHeight="15000"/></bookViews>'
            . '<sheets><sheet name="' . self::x($this->feuille) . '" sheetId="1" r:id="rId1"/></sheets>'
            . ($noms !== '' ? '<definedNames>' . $noms . '</definedNames>' : '')
            . '</workbook>';
    }

    private function feuilleXml(): string
    {
        ksort($this->cellules);
        $lignes = array_unique(array_merge(array_keys($this->cellules), array_keys($this->hauteurs)));
        sort($lignes);
        $derniereLigne = $lignes ? max($lignes) : 1;
        $derniereColonne = 1;
        foreach ($this->cellules as $cellules) {
            if ($cellules) $derniereColonne = max($derniereColonne, max(array_keys($cellules)));
        }
        $derniereColonne = max($derniereColonne, count($this->largeurs));

        $xml = self::ENTETE_XML
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><tabColor rgb="FF006399"/><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<dimension ref="A1:' . self::colonne($derniereColonne) . $derniereLigne . '"/>'
            . '<sheetViews><sheetView showGridLines="0" tabSelected="1" workbookViewId="0">';
        if ($this->figer > 0) {
            $haut = 'A' . ($this->figer + 1);
            $xml .= '<pane ySplit="' . $this->figer . '" topLeftCell="' . $haut . '" activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="' . $haut . '" sqref="' . $haut . '"/>';
        }
        $xml .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';

        if ($this->largeurs) {
            $xml .= '<cols>';
            foreach ($this->largeurs as $i => $largeur) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . round((float) $largeur, 2) . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($lignes as $numero) {
            $xml .= '<row r="' . $numero . '"';
            if (isset($this->hauteurs[$numero])) $xml .= ' ht="' . round($this->hauteurs[$numero], 2) . '" customHeight="1"';
            $cellules = $this->cellules[$numero] ?? [];
            if (!$cellules) {
                $xml .= '/>';
                continue;
            }
            ksort($cellules);
            $xml .= '>';
            foreach ($cellules as $colonne => [$type, $valeur, $style]) {
                $ref = self::colonne($colonne) . $numero;
                $xml .= match ($type) {
                    's' => '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::x($valeur) . '</t></is></c>',
                    'n' => '<c r="' . $ref . '" s="' . $style . '"><v>' . $valeur . '</v></c>',
                    default => '<c r="' . $ref . '" s="' . $style . '"/>',
                };
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($this->filtre) $xml .= '<autoFilter ref="' . $this->filtre . '"/>';
        if ($this->fusions) {
            $xml .= '<mergeCells count="' . count($this->fusions) . '">';
            foreach ($this->fusions as $plage) $xml .= '<mergeCell ref="' . $plage . '"/>';
            $xml .= '</mergeCells>';
        }

        // À l'impression : A4 en paysage, toute la largeur sur une page, le pied de la maison.
        $xml .= '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
        $pied = '&L&8' . str_replace('&', '&&', $this->pied) . '&R&8Page &P / &N';
        $xml .= '<headerFooter><oddFooter>' . self::x($pied) . '</oddFooter></headerFooter>';
        if ($this->logo) $xml .= '<drawing r:id="rId1"/>';

        return $xml . '</worksheet>';
    }

    private function dessinXml(): string
    {
        // Les longueurs du dessin sont en EMU : 9 525 par pixel.
        $largeur = $this->logo['largeur'] * 9525;
        $hauteur = $this->logo['hauteur'] * 9525;
        $rogne = $this->logo['rogne'] > 0 ? '<a:srcRect r="' . (int) round($this->logo['rogne'] * 100000) . '"/>' : '';
        return self::ENTETE_XML
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<xdr:oneCellAnchor>'
            . '<xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>57150</xdr:rowOff></xdr:from>'
            . '<xdr:ext cx="' . $largeur . '" cy="' . $hauteur . '"/>'
            . '<xdr:pic>'
            . '<xdr:nvPicPr><xdr:cNvPr id="2" name="Logo" descr="' . self::x($this->auteur) . '"/>'
            . '<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip r:embed="rId1"/>' . $rogne . '<a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $largeur . '" cy="' . $hauteur . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
            . '</xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
    }

    /**
     * Les styles de la maison. Polices, fonds et filets d'abord, puis les combinaisons que
     * les cellules désignent par leur nom (STYLES) — dans le même ordre.
     */
    private static function stylesXml(): string
    {
        $police = static fn (float $taille, string $couleur, string $graisse = ''): string =>
            '<font>' . $graisse . '<sz val="' . $taille . '"/><color rgb="FF' . $couleur . '"/><name val="Segoe UI"/><family val="2"/></font>';
        $polices = [
            $police(10, '141415'),             // 0 le texte
            $police(18, '141415', '<b/>'),     // 1 le titre
            $police(11, '494D50'),             // 2 le sous-titre
            $police(9, '494D50'),              // 3 une étiquette
            $police(10, '141415', '<b/>'),     // 4 une valeur
            $police(10, '006399', '<b/>'),     // 5 une valeur à l'accent
            $police(10, 'FFFFFF', '<b/>'),     // 6 l'en-tête du tableau
            $police(10, '494D50'),             // 7 le texte sourd
            $police(10, '2F934B', '<b/>'),     // 8 reçu
            $police(10, 'BB4D00', '<b/>'),     // 9 en retard
            $police(9, '494D50', '<i/>'),      // 10 une note
        ];
        $fond = static fn (string $couleur): string =>
            '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $couleur . '"/><bgColor indexed="64"/></patternFill></fill>';
        $fonds = [
            '<fill><patternFill patternType="none"/></fill>',     // 0 et 1 : réservés par le format
            '<fill><patternFill patternType="gray125"/></fill>',
            $fond('006399'),  // 2 l'accent
            $fond('F9F9F9'),  // 3 une carte
            $fond('E4F6E9'),  // 4 succès
            $fond('FEF3C6'),  // 5 avertissement
            $fond('F2F2F3'),  // 6 un groupe, le total
        ];
        $filet = static fn (string $cote, string $trait, string $couleur): string =>
            '<' . $cote . ' style="' . $trait . '"><color rgb="FF' . $couleur . '"/></' . $cote . '>';
        $filets = [
            '<border><left/><right/><top/><bottom/><diagonal/></border>',                                          // 0
            '<border><left/><right/><top/>' . $filet('bottom', 'thin', 'EAEBEC') . '<diagonal/></border>',          // 1 entre deux lignes
            '<border><left/><right/><top/>' . $filet('bottom', 'medium', '006399') . '<diagonal/></border>',        // 2 sous le titre
            '<border><left/><right/>' . $filet('top', 'thin', 'D5D7D9') . $filet('bottom', 'thin', 'D5D7D9') . '<diagonal/></border>', // 3 le total
        ];

        // [format, police, fond, filet, alignement horizontal, retrait, retour à la ligne]
        $xf = static function (int $format, int $police, int $fond, int $filet, string $horizontal = '', int $retrait = 0, bool $retour = false): string {
            $alignement = '<alignment vertical="center"' . ($horizontal !== '' ? ' horizontal="' . $horizontal . '"' : '')
                . ($retrait ? ' indent="' . $retrait . '"' : '') . ($retour ? ' wrapText="1"' : '') . '/>';
            return '<xf numFmtId="' . $format . '" fontId="' . $police . '" fillId="' . $fond . '" borderId="' . $filet . '" xfId="0"'
                . ' applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $alignement . '</xf>';
        };
        $combinaisons = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>', // normal
            $xf(0, 1, 0, 2, 'left'),               // titre, souligné de l'accent
            $xf(0, 2, 0, 0, 'left'),               // sous-titre
            $xf(0, 3, 3, 0, 'left', 1),            // étiquette, sur la carte
            $xf(0, 4, 3, 0, 'left'),               // valeur
            $xf(0, 5, 3, 0, 'left'),               // valeur à l'accent
            $xf(0, 6, 2, 0, 'left', 1),            // en-tête
            $xf(0, 6, 2, 0, 'right', 1),           // en-tête, à droite
            $xf(0, 6, 2, 0, 'center'),             // en-tête, centré
            $xf(0, 0, 0, 1, 'left', 1, true),      // texte
            $xf(0, 7, 0, 1, 'left', 1),            // texte sourd
            $xf(0, 0, 0, 1, 'right', 1),           // nombre
            $xf(164, 0, 0, 1, 'center'),           // date
            $xf(164, 7, 0, 1, 'center'),           // date sourde
            $xf(0, 8, 4, 1, 'center'),             // reçu
            $xf(0, 9, 5, 1, 'center'),             // en retard
            $xf(0, 7, 0, 1, 'center'),             // à recevoir
            $xf(0, 4, 6, 3, 'left', 1),            // total
            $xf(0, 10, 0, 0, 'left'),              // note
            $xf(0, 7, 0, 1, 'center'),             // une ligne vide du tableau
        ];

        return self::ENTETE_XML
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>'
            . '<fonts count="' . count($polices) . '">' . implode('', $polices) . '</fonts>'
            . '<fills count="' . count($fonds) . '">' . implode('', $fonds) . '</fills>'
            . '<borders count="' . count($filets) . '">' . implode('', $filets) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($combinaisons) . '">' . implode('', $combinaisons) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
            . '</styleSheet>';
    }

    /** @param list<array{0: string, 1: string, 2: string}> $relations [identifiant, type, cible] */
    private static function relations(array $relations): string
    {
        $xml = self::ENTETE_XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($relations as [$id, $type, $cible]) {
            $xml .= '<Relationship Id="' . $id . '" Type="' . $type . '" Target="' . $cible . '"/>';
        }
        return $xml . '</Relationships>';
    }

    // ------------------------------------------------------------------ Le paquet ZIP

    /**
     * Le paquet : en-têtes locaux, répertoire central, fin de répertoire — le strict format
     * ZIP, entrées compressées par « deflate ».
     *
     * @param array<string, string> $fichiers chemin dans le paquet => contenu
     */
    private static function zip(array $fichiers): string
    {
        $date = getdate();
        $heure = ($date['hours'] << 11) | ($date['minutes'] << 5) | intdiv($date['seconds'], 2);
        $jour = (($date['year'] - 1980) << 9) | ($date['mon'] << 5) | $date['mday'];

        $donnees = '';
        $repertoire = '';
        foreach ($fichiers as $nom => $contenu) {
            $compresse = gzdeflate($contenu, 6);
            $crc = crc32($contenu);
            $decalage = strlen($donnees);
            $donnees .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, $heure, $jour, $crc,
                strlen($compresse), strlen($contenu), strlen($nom), 0) . $nom . $compresse;
            $repertoire .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, $heure, $jour, $crc,
                strlen($compresse), strlen($contenu), strlen($nom), 0, 0, 0, 0, 0, $decalage) . $nom;
        }
        return $donnees . $repertoire
            . pack('VvvvvVVv', 0x06054b50, 0, 0, count($fichiers), count($fichiers), strlen($repertoire), strlen($donnees), 0);
    }

    // ------------------------------------------------------------------ Les petites choses

    private static function style(string $nom): int
    {
        return self::STYLES[$nom] ?? throw new InvalidArgumentException('Style de classeur inconnu : ' . $nom);
    }

    /** « A », « B »… « Z », « AA » : les colonnes du tableur. */
    private static function colonne(int $numero): string
    {
        $lettres = '';
        while ($numero > 0) {
            $reste = ($numero - 1) % 26;
            $lettres = chr(65 + $reste) . $lettres;
            $numero = intdiv($numero - 1, 26);
        }
        return $lettres;
    }

    /** « A9 » → « $A$9 ». */
    private static function absolue(string $ref): string
    {
        return preg_replace('/^([A-Z]+)(\d+)$/', '\$$1\$$2', $ref) ?? $ref;
    }

    /** Ce que le XML refuse — les caractères de contrôle — ne passe pas ; la longueur est celle d'une cellule. */
    private static function nettoyer(string $texte): string
    {
        $texte = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texte) ?? '';
        return mb_substr($texte, 0, 32000);
    }

    private static function x(string $texte): string
    {
        return htmlspecialchars($texte, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
