<?php
/*
 * Created on   : Fri Jan 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFReaderType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Enums;

/**
 * Enum für verfügbare PDF-Reader-Typen.
 * 
 * Prioritäten:
 * - 10-20: Schnelle Text-Extraktion
 * - 30-40: Komplexe Layouts
 * - 50-70: OCR für gescannte Dokumente
 */
enum PDFReaderType: string {
    case Pdftotext = 'pdftotext';
    case Pdfbox    = 'pdfbox';
    case Tesseract = 'tesseract';
    case Ocrmypdf  = 'ocrmypdf';

    /**
     * Gibt die Priorität des Readers zurück.
     */
    public function getPriority(): int {
        return match ($this) {
            self::Pdftotext => 10,
            self::Pdfbox    => 30,
            self::Tesseract => 50,
            self::Ocrmypdf  => 60,
        };
    }

    /**
     * Gibt an, ob der Reader OCR für gescannte PDFs unterstützt.
     */
    public function supportsScannedPdfs(): bool {
        return match ($this) {
            self::Pdftotext, self::Pdfbox => false,
            self::Tesseract, self::Ocrmypdf => true,
        };
    }

    /**
     * Gibt an, ob der Reader Text-PDFs unterstützt.
     */
    public function supportsTextPdfs(): bool {
        return match ($this) {
            self::Pdftotext, self::Pdfbox => true,
            self::Tesseract, self::Ocrmypdf => false,
        };
    }

    /**
     * Gibt an, ob der Reader nur für OCR geeignet ist (keine normale Text-Extraktion).
     */
    public function isOcrOnly(): bool {
        return $this->supportsScannedPdfs() && !$this->supportsTextPdfs();
    }

    /**
     * Gibt eine lesbare Beschreibung zurück.
     */
    public function getDescription(): string {
        return match ($this) {
            self::Pdftotext => 'Poppler pdftotext - Schnelle Text-Extraktion',
            self::Pdfbox    => 'Apache PDFBox - Komplexe Layouts',
            self::Tesseract => 'Tesseract OCR - Gescannte Dokumente',
            self::Ocrmypdf  => 'OCRmyPDF - Hochwertige OCR',
        };
    }

    /**
     * Gibt alle OCR-Reader zurück.
     * 
     * @return self[]
     */
    public static function ocrReaders(): array {
        return array_filter(self::cases(), fn(self $type) => $type->supportsScannedPdfs());
    }

    /**
     * Gibt alle Text-PDF-Reader zurück.
     * 
     * @return self[]
     */
    public static function textReaders(): array {
        return array_filter(self::cases(), fn(self $type) => $type->supportsTextPdfs());
    }
}
