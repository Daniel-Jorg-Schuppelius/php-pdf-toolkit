<?php
/*
 * Created on   : Wed Mar 11 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PaperFormat.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Enums;

/**
 * Standard-Papierformate mit Abmessungen in Points (1 Point = 1/72 Inch).
 * 
 * Die Toleranz für Format-Erkennung sollte berücksichtigen, dass PDFs
 * oft leicht von den exakten Maßen abweichen (Rundungsfehler, Scanner, etc.).
 */
enum PaperFormat: string {
    // ISO 216 A-Serie (metrisch)
    case A0 = 'A0';
    case A1 = 'A1';
    case A2 = 'A2';
    case A3 = 'A3';
    case A4 = 'A4';
    case A5 = 'A5';
    case A6 = 'A6';
    case A7 = 'A7';
    case A8 = 'A8';

    // ISO 216 B-Serie
    case B0 = 'B0';
    case B1 = 'B1';
    case B2 = 'B2';
    case B3 = 'B3';
    case B4 = 'B4';
    case B5 = 'B5';
    case B6 = 'B6';

    // ISO 216 C-Serie (Umschläge)
    case C4 = 'C4';
    case C5 = 'C5';
    case C6 = 'C6';

    // US Formate
    case LETTER = 'Letter';
    case LEGAL = 'Legal';
    case TABLOID = 'Tabloid';
    case LEDGER = 'Ledger';
    case EXECUTIVE = 'Executive';

    // Japanische B-Serie (JIS)
    case JIS_B4 = 'JIS-B4';
    case JIS_B5 = 'JIS-B5';

    /**
     * Gibt die Breite in Points zurück (Portrait-Orientierung).
     */
    public function widthPt(): float {
        return match ($this) {
            // A-Serie (mm → pts: mm * 72 / 25.4)
            self::A0 => 2383.94,  // 841 mm
            self::A1 => 1683.78,  // 594 mm
            self::A2 => 1190.55,  // 420 mm
            self::A3 => 841.89,   // 297 mm
            self::A4 => 595.28,   // 210 mm
            self::A5 => 419.53,   // 148 mm
            self::A6 => 297.64,   // 105 mm
            self::A7 => 209.76,   // 74 mm
            self::A8 => 147.40,   // 52 mm
            // B-Serie
            self::B0 => 2834.65,  // 1000 mm
            self::B1 => 2004.09,  // 707 mm
            self::B2 => 1417.32,  // 500 mm
            self::B3 => 1000.63,  // 353 mm
            self::B4 => 708.66,   // 250 mm
            self::B5 => 498.90,   // 176 mm
            self::B6 => 354.33,   // 125 mm
            // C-Serie
            self::C4 => 649.13,   // 229 mm
            self::C5 => 459.21,   // 162 mm
            self::C6 => 323.15,   // 114 mm
            // US Formate (Inches → pts: in * 72)
            self::LETTER => 612.0,    // 8.5 in
            self::LEGAL => 612.0,     // 8.5 in
            self::TABLOID => 792.0,   // 11 in
            self::LEDGER => 1224.0,   // 17 in
            self::EXECUTIVE => 522.0, // 7.25 in
            // JIS B-Serie
            self::JIS_B4 => 728.50,   // 257 mm
            self::JIS_B5 => 515.91,   // 182 mm
        };
    }

    /**
     * Gibt die Höhe in Points zurück (Portrait-Orientierung).
     */
    public function heightPt(): float {
        return match ($this) {
            // A-Serie
            self::A0 => 3370.39,  // 1189 mm
            self::A1 => 2383.94,  // 841 mm
            self::A2 => 1683.78,  // 594 mm
            self::A3 => 1190.55,  // 420 mm
            self::A4 => 841.89,   // 297 mm
            self::A5 => 595.28,   // 210 mm
            self::A6 => 419.53,   // 148 mm
            self::A7 => 297.64,   // 105 mm
            self::A8 => 209.76,   // 74 mm
            // B-Serie
            self::B0 => 4008.19,  // 1414 mm
            self::B1 => 2834.65,  // 1000 mm
            self::B2 => 2004.09,  // 707 mm
            self::B3 => 1417.32,  // 500 mm
            self::B4 => 1000.63,  // 353 mm
            self::B5 => 708.66,   // 250 mm
            self::B6 => 498.90,   // 176 mm
            // C-Serie
            self::C4 => 918.43,   // 324 mm
            self::C5 => 649.13,   // 229 mm
            self::C6 => 459.21,   // 162 mm
            // US Formate
            self::LETTER => 792.0,    // 11 in
            self::LEGAL => 1008.0,    // 14 in
            self::TABLOID => 1224.0,  // 17 in
            self::LEDGER => 792.0,    // 11 in
            self::EXECUTIVE => 756.0, // 10.5 in
            // JIS B-Serie
            self::JIS_B4 => 1031.81,  // 364 mm
            self::JIS_B5 => 728.50,   // 257 mm
        };
    }

    /**
     * Gibt die Abmessungen in Millimetern zurück [width, height].
     * 
     * @return array{0: float, 1: float}
     */
    public function dimensionsMm(): array {
        return [
            $this->widthPt() * 25.4 / 72,
            $this->heightPt() * 25.4 / 72,
        ];
    }

    /**
     * Gibt die Abmessungen in Inches zurück [width, height].
     * 
     * @return array{0: float, 1: float}
     */
    public function dimensionsIn(): array {
        return [
            $this->widthPt() / 72,
            $this->heightPt() / 72,
        ];
    }

    /**
     * Prüft ob die gegebenen Abmessungen diesem Format entsprechen.
     * 
     * @param float $widthPt Breite in Points
     * @param float $heightPt Höhe in Points
     * @param float $tolerancePt Toleranz in Points (Standard: 5.0 ≈ 1.8mm)
     * @param bool $ignoreOrientation Wenn true, wird Portrait/Landscape ignoriert
     */
    public function matches(float $widthPt, float $heightPt, float $tolerancePt = 5.0, bool $ignoreOrientation = true): bool {
        $formatWidth = $this->widthPt();
        $formatHeight = $this->heightPt();

        // Prüfe Portrait-Orientierung
        if (abs($widthPt - $formatWidth) <= $tolerancePt && abs($heightPt - $formatHeight) <= $tolerancePt) {
            return true;
        }

        // Prüfe Landscape-Orientierung (wenn nicht ignoriert)
        if ($ignoreOrientation && abs($widthPt - $formatHeight) <= $tolerancePt && abs($heightPt - $formatWidth) <= $tolerancePt) {
            return true;
        }

        return false;
    }

    /**
     * Prüft ob die gegebenen Abmessungen Landscape-Orientierung haben.
     */
    public function isLandscape(float $widthPt, float $heightPt): bool {
        return $this->matches($widthPt, $heightPt) && $widthPt > $heightPt;
    }

    /**
     * Erstellt ein PaperFormat aus einem String.
     */
    public static function fromString(string $format): ?self {
        $normalized = strtoupper(str_replace(['-', '_', ' '], '', $format));

        return match ($normalized) {
            'A0' => self::A0,
            'A1' => self::A1,
            'A2' => self::A2,
            'A3' => self::A3,
            'A4' => self::A4,
            'A5' => self::A5,
            'A6' => self::A6,
            'A7' => self::A7,
            'A8' => self::A8,
            'B0' => self::B0,
            'B1' => self::B1,
            'B2' => self::B2,
            'B3' => self::B3,
            'B4' => self::B4,
            'B5' => self::B5,
            'B6' => self::B6,
            'C4' => self::C4,
            'C5' => self::C5,
            'C6' => self::C6,
            'LETTER' => self::LETTER,
            'LEGAL' => self::LEGAL,
            'TABLOID' => self::TABLOID,
            'LEDGER' => self::LEDGER,
            'EXECUTIVE' => self::EXECUTIVE,
            'JISB4', 'JB4' => self::JIS_B4,
            'JISB5', 'JB5' => self::JIS_B5,
            default => null,
        };
    }

    /**
     * Erkennt automatisch das Format aus gegebenen Abmessungen.
     * 
     * @param float $widthPt Breite in Points
     * @param float $heightPt Höhe in Points
     * @param float $tolerancePt Toleranz in Points
     * @return self|null Das erkannte Format oder null
     */
    public static function detect(float $widthPt, float $heightPt, float $tolerancePt = 5.0): ?self {
        // Häufigste Formate zuerst prüfen
        $commonFormats = [
            self::A4,
            self::LETTER,
            self::A3,
            self::LEGAL,
            self::A5,
            self::TABLOID,
            self::LEDGER,
        ];

        foreach ($commonFormats as $format) {
            if ($format->matches($widthPt, $heightPt, $tolerancePt)) {
                return $format;
            }
        }

        // Alle anderen Formate
        foreach (self::cases() as $format) {
            if (!in_array($format, $commonFormats, true) && $format->matches($widthPt, $heightPt, $tolerancePt)) {
                return $format;
            }
        }

        return null;
    }

    /**
     * Gibt eine lesbare Beschreibung des Formats zurück.
     */
    public function description(): string {
        [$wMm, $hMm] = $this->dimensionsMm();
        return sprintf('%s (%.0f × %.0f mm)', $this->value, $wMm, $hMm);
    }
}
