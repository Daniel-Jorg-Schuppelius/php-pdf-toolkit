<?php
/*
 * Created on   : Fri Jan 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFWriterType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Enums;

/**
 * Enum für verfügbare PDF-Writer-Typen.
 * 
 * Prioritäten:
 * - 10-20: Reine PHP-Lösungen (dompdf, tcpdf)
 * - 30-40: Externe Tools mit guter Qualität (wkhtmltopdf)
 * - 50-70: Spezielle Formate (LaTeX)
 */
enum PDFWriterType: string {
    case Dompdf      = 'dompdf';
    case Tcpdf       = 'tcpdf';
    case Zugferd     = 'zugferd';
    case Wkhtmltopdf = 'wkhtmltopdf';

    /**
     * Gibt die Priorität des Writers zurück.
     */
    public function getPriority(): int {
        return match ($this) {
            self::Dompdf      => 10,
            self::Zugferd     => 15,
            self::Tcpdf       => 20,
            self::Wkhtmltopdf => 30,
        };
    }

    /**
     * Gibt an, ob der Writer HTML unterstützt.
     */
    public function supportsHtml(): bool {
        return match ($this) {
            self::Dompdf, self::Tcpdf, self::Zugferd, self::Wkhtmltopdf => true,
        };
    }

    /**
     * Gibt an, ob der Writer reinen Text unterstützt.
     */
    public function supportsText(): bool {
        return match ($this) {
            self::Dompdf, self::Tcpdf, self::Zugferd, self::Wkhtmltopdf => true,
        };
    }

    /**
     * Gibt an, ob der Writer ein externes Tool benötigt.
     */
    public function requiresExternalTool(): bool {
        return match ($this) {
            self::Dompdf, self::Tcpdf, self::Zugferd => false,
            self::Wkhtmltopdf => true,
        };
    }

    /**
     * Gibt eine lesbare Beschreibung zurück.
     */
    public function getDescription(): string {
        return match ($this) {
            self::Dompdf      => 'Dompdf - Reine PHP-Lösung für HTML zu PDF',
            self::Tcpdf       => 'TCPDF - Umfangreiche PHP-PDF-Bibliothek',
            self::Zugferd     => 'ZUGFeRD/Factur-X - E-Rechnungs-PDFs',
            self::Wkhtmltopdf => 'wkhtmltopdf - Webkit-basierte Konvertierung',
        };
    }

    /**
     * Gibt alle PHP-basierten Writer zurück (keine externen Tools).
     * 
     * @return self[]
     */
    public static function phpWriters(): array {
        return array_filter(self::cases(), fn(self $type) => !$type->requiresExternalTool());
    }

    /**
     * Gibt alle Writer zurück, die externe Tools benötigen.
     * 
     * @return self[]
     */
    public static function externalWriters(): array {
        return array_filter(self::cases(), fn(self $type) => $type->requiresExternalTool());
    }
}
