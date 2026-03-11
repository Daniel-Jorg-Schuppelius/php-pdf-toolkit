<?php
/*
 * Created on   : Wed Mar 11 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PageSize.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Entities;

use PDFToolkit\Enums\PaperFormat;

/**
 * Value Object für die Seitengröße eines PDF-Dokuments.
 * 
 * Alle Maße werden intern in Points gespeichert (1 Point = 1/72 Inch).
 */
final readonly class PageSize {
    /**
     * @param float $widthPt Breite in Points
     * @param float $heightPt Höhe in Points
     * @param int|null $pageNumber Seitennummer (1-basiert, null für Dokument-Standard)
     */
    public function __construct(
        public float $widthPt,
        public float $heightPt,
        public ?int $pageNumber = null
    ) {
    }

    /**
     * Erstellt eine PageSize aus pdfinfo-Output.
     * Erwartet Formate wie "595.3 x 841.9 pts" oder "595.3 x 841.9 pts (A4)"
     */
    public static function fromPdfInfoString(string $sizeString, ?int $pageNumber = null): ?self {
        // Pattern: "WIDTHxHEIGHT pts" (mit optionalem Formatnamen)
        if (preg_match('/([0-9.]+)\s*x\s*([0-9.]+)\s*pts/i', $sizeString, $matches)) {
            return new self(
                widthPt: (float) $matches[1],
                heightPt: (float) $matches[2],
                pageNumber: $pageNumber
            );
        }
        return null;
    }

    /**
     * Erstellt eine PageSize aus Millimeter-Angaben.
     */
    public static function fromMm(float $widthMm, float $heightMm, ?int $pageNumber = null): self {
        return new self(
            widthPt: $widthMm * 72 / 25.4,
            heightPt: $heightMm * 72 / 25.4,
            pageNumber: $pageNumber
        );
    }

    /**
     * Erstellt eine PageSize aus Inch-Angaben.
     */
    public static function fromInches(float $widthIn, float $heightIn, ?int $pageNumber = null): self {
        return new self(
            widthPt: $widthIn * 72,
            heightPt: $heightIn * 72,
            pageNumber: $pageNumber
        );
    }

    /**
     * Erstellt eine PageSize aus einem PaperFormat.
     */
    public static function fromFormat(PaperFormat $format, bool $landscape = false, ?int $pageNumber = null): self {
        if ($landscape) {
            return new self(
                widthPt: $format->heightPt(),
                heightPt: $format->widthPt(),
                pageNumber: $pageNumber
            );
        }
        return new self(
            widthPt: $format->widthPt(),
            heightPt: $format->heightPt(),
            pageNumber: $pageNumber
        );
    }

    /**
     * Gibt die Breite in Millimetern zurück.
     */
    public function widthMm(): float {
        return $this->widthPt * 25.4 / 72;
    }

    /**
     * Gibt die Höhe in Millimetern zurück.
     */
    public function heightMm(): float {
        return $this->heightPt * 25.4 / 72;
    }

    /**
     * Gibt die Breite in Inches zurück.
     */
    public function widthIn(): float {
        return $this->widthPt / 72;
    }

    /**
     * Gibt die Höhe in Inches zurück.
     */
    public function heightIn(): float {
        return $this->heightPt / 72;
    }

    /**
     * Prüft ob die Seite im Landscape-Format ist.
     */
    public function isLandscape(): bool {
        return $this->widthPt > $this->heightPt;
    }

    /**
     * Prüft ob die Seite im Portrait-Format ist.
     */
    public function isPortrait(): bool {
        return $this->heightPt > $this->widthPt;
    }

    /**
     * Prüft ob die Seite quadratisch ist (innerhalb der Toleranz).
     */
    public function isSquare(float $tolerancePt = 1.0): bool {
        return abs($this->widthPt - $this->heightPt) <= $tolerancePt;
    }

    /**
     * Prüft ob die Seitengröße einem bestimmten Format entspricht.
     * 
     * @param PaperFormat|string $format Format-Enum oder String (z.B. "A4", "letter")
     * @param float $tolerancePt Toleranz in Points (Standard: 5.0 ≈ 1.8mm)
     * @param bool $ignoreOrientation Wenn true, wird Portrait/Landscape ignoriert
     */
    public function isFormat(PaperFormat|string $format, float $tolerancePt = 5.0, bool $ignoreOrientation = true): bool {
        if (is_string($format)) {
            $format = PaperFormat::fromString($format);
            if ($format === null) {
                return false;
            }
        }
        return $format->matches($this->widthPt, $this->heightPt, $tolerancePt, $ignoreOrientation);
    }

    /**
     * Erkennt automatisch das Papierformat.
     * 
     * @param float $tolerancePt Toleranz in Points
     * @return PaperFormat|null Das erkannte Format oder null
     */
    public function detectFormat(float $tolerancePt = 5.0): ?PaperFormat {
        return PaperFormat::detect($this->widthPt, $this->heightPt, $tolerancePt);
    }

    /**
     * Gibt eine lesbare Beschreibung der Seitengröße zurück.
     */
    public function description(): string {
        $format = $this->detectFormat();
        $orientation = $this->isLandscape() ? 'Landscape' : 'Portrait';

        if ($format !== null) {
            return sprintf(
                '%s %s (%.1f × %.1f mm)',
                $format->value,
                $orientation,
                $this->widthMm(),
                $this->heightMm()
            );
        }

        return sprintf(
            'Custom %s (%.1f × %.1f mm)',
            $orientation,
            $this->widthMm(),
            $this->heightMm()
        );
    }

    /**
     * Gibt die Seitengröße als Array zurück.
     * 
     * @return array{width: float, height: float, unit: string}
     */
    public function toArray(string $unit = 'pt'): array {
        return match ($unit) {
            'mm' => ['width' => $this->widthMm(), 'height' => $this->heightMm(), 'unit' => 'mm'],
            'in' => ['width' => $this->widthIn(), 'height' => $this->heightIn(), 'unit' => 'in'],
            default => ['width' => $this->widthPt, 'height' => $this->heightPt, 'unit' => 'pt'],
        };
    }

    /**
     * Berechnet die Fläche in Quadrat-Points.
     */
    public function area(): float {
        return $this->widthPt * $this->heightPt;
    }

    /**
     * Berechnet das Seitenverhältnis (Breite/Höhe).
     */
    public function aspectRatio(): float {
        return $this->heightPt > 0 ? $this->widthPt / $this->heightPt : 0;
    }
}
