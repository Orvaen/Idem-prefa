<?php

/**
 * Écriture de PDF réduite à ce dont les plans ont besoin : des traits, des cadres et
 * du texte en Helvetica. Pas de bibliothèque externe, pas de composer — le projet n'en
 * a aucune, et un cartouche de plan ne demande rien de plus.
 *
 * Les coordonnées sont en millimètres et l'origine est en HAUT à gauche, comme on lit
 * un plan. La conversion vers le repère PDF (origine en bas, unités en points) est faite
 * ici une fois pour toutes.
 */
class PdfPlan
{
    /** Largeurs Helvetica pour 1000 unités de corps, ASCII imprimable. */
    private const LARGEURS = [
        'normal' => [32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
            40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
            48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
            56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
            64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
            72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
            80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
            88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
            96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
            104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556,
            111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556,
            118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584],
        'gras' => [32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238,
            40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
            48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
            56 => 556, 57 => 556, 58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611,
            64 => 975, 65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
            72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778,
            80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
            88 => 667, 89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556,
            96 => 333, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611,
            104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611,
            111 => 611, 112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611,
            118 => 556, 119 => 778, 120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584],
    ];

    private string $flux = '';

    public function __construct(private float $largeurMm = 297, private float $hauteurMm = 210)
    {
    }

    private function pt(float $mm): float
    {
        return $mm * 72 / 25.4;
    }

    /** Un y mesuré depuis le haut de la page devient le y du repère PDF. */
    private function versBas(float $mmDepuisHaut): float
    {
        return $this->pt($this->hauteurMm - $mmDepuisHaut);
    }

    public function cadre(float $x, float $y, float $largeur, float $hauteur, float $trait = 0.25): void
    {
        $this->flux .= sprintf("%.2f w %.2f %.2f %.2f %.2f re S\n", $trait,
            $this->pt($x), $this->versBas($y + $hauteur), $this->pt($largeur), $this->pt($hauteur));
    }

    public function ligne(float $x1, float $y1, float $x2, float $y2, float $trait = 0.25): void
    {
        $this->flux .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $trait,
            $this->pt($x1), $this->versBas($y1), $this->pt($x2), $this->versBas($y2));
    }

    /** Largeur d'un texte en millimètres, pour centrer ou vérifier qu'il tient. */
    public function largeurTexte(string $texte, float $corps, bool $gras = false): float
    {
        return self::largeurPoints($texte, $corps, $gras) * 25.4 / 72;
    }

    /**
     * Largeur d'un texte en points, en Helvetica. Publique et statique : le plan final
     * écrit sur un PDF qu'il n'a pas produit, mais mesure son texte de la même façon.
     */
    public static function largeurPoints(string $texte, float $corps, bool $gras = false): float
    {
        $table = self::LARGEURS[$gras ? 'gras' : 'normal'];
        $total = 0;
        foreach (str_split(self::versWinAnsi($texte)) as $caractere) {
            $total += $table[ord($caractere)] ?? 556;
        }
        return $total / 1000 * $corps;
    }

    /** Le texte prêt à figurer entre parenthèses dans un flux PDF. */
    public static function chaine(string $texte): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::versWinAnsi($texte));
    }

    /** Les polices de base du PDF lisent du Windows-1252, pas de l'UTF-8. */
    public static function versWinAnsi(string $texte): string
    {
        return mb_convert_encoding($texte, 'Windows-1252', 'UTF-8');
    }

    /** $y est la ligne de base du texte, mesurée depuis le haut. */
    public function texte(string $texte, float $x, float $y, float $corps = 8, bool $gras = false): void
    {
        $this->flux .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $gras ? 'F2' : 'F1', $corps, $this->pt($x), $this->versBas($y), $this->echapper($texte));
    }

    public function texteCentre(string $texte, float $x, float $largeur, float $y, float $corps = 8, bool $gras = false): void
    {
        $this->texte($texte, $x + max(0, ($largeur - $this->largeurTexte($texte, $corps, $gras)) / 2), $y, $corps, $gras);
    }

    /** Coupe ce qui déborde plutôt que de laisser le texte sortir de sa case. */
    public function texteAjuste(string $texte, float $x, float $largeurMax, float $y, float $corps = 8, bool $gras = false): void
    {
        while ($texte !== '' && $this->largeurTexte($texte, $corps, $gras) > $largeurMax) {
            $texte = mb_substr($texte, 0, mb_strlen($texte) - 1);
        }
        $this->texte($texte, $x, $y, $corps, $gras);
    }

    private function echapper(string $texte): string
    {
        return self::chaine($texte);
    }

    public function rendu(): string
    {
        $objets = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            sprintf("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>",
                $this->pt($this->largeurMm), $this->pt($this->hauteurMm)),
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($this->flux) + 1, $this->flux),
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>",
        ];

        $pdf = "%PDF-1.4\n";
        $positions = [];
        foreach ($objets as $index => $corps) {
            $positions[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $corps . "\nendobj\n";
        }

        // La table xref donne la position exacte de chaque objet : elle se calcule
        // après coup, sur le document déjà assemblé.
        $debutXref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objets) + 1) . "\n0000000000 65535 f \n";
        foreach ($positions as $position) {
            $pdf .= sprintf("%010d 00000 n \n", $position);
        }
        $pdf .= "trailer\n<< /Size " . (count($objets) + 1) . " /Root 1 0 R >>\nstartxref\n" . $debutXref . "\n%%EOF\n";
        return $pdf;
    }
}
