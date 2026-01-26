<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFWriterInterface.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Contracts;

use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;

/**
 * Interface für PDF-Writer.
 * 
 * Jeder Writer erstellt PDF-Dateien aus verschiedenen Quellformaten.
 * Die Writer werden nach Priorität durchprobiert, bis einer erfolgreich ist.
 */
interface PDFWriterInterface {
    /**
     * Writer-Typ als Enum.
     */
    public static function getType(): PDFWriterType;

    /**
     * Priorität des Writers (niedriger = wird früher probiert).
     * 
     * Empfohlene Werte:
     * - 10-20: Reine PHP-Lösungen (dompdf, tcpdf)
     * - 30-40: Externe Tools mit guter Qualität (wkhtmltopdf)
     * - 50-70: Spezielle Formate (LaTeX)
     */
    public static function getPriority(): int;

    /**
     * Prüft, ob der Writer HTML-Inhalte unterstützt.
     */
    public static function supportsHtml(): bool;

    /**
     * Prüft, ob der Writer reinen Text unterstützt.
     */
    public static function supportsText(): bool;

    /**
     * Prüft, ob der Writer auf dem System verfügbar ist.
     * (z.B. ob das benötigte Tool/Library installiert ist)
     */
    public function isAvailable(): bool;

    /**
     * Prüft, ob der Writer den gegebenen Content verarbeiten kann.
     */
    public function canHandle(PDFContent $content): bool;

    /**
     * Erstellt eine PDF-Datei aus dem gegebenen Content.
     * 
     * @param PDFContent $content Der zu konvertierende Inhalt
     * @param string $outputPath Absoluter Pfad für die Ausgabedatei
     * @param array $options Optionale Konfiguration (z.B. Seitengröße, Ränder)
     * @return bool true wenn erfolgreich, false bei Fehler
     */
    public function createPdf(PDFContent $content, string $outputPath, array $options = []): bool;

    /**
     * Erstellt eine PDF und gibt den Inhalt als String zurück.
     * 
     * @param PDFContent $content Der zu konvertierende Inhalt
     * @param array $options Optionale Konfiguration
     * @return string|null PDF-Inhalt als String oder null bei Fehler
     */
    public function createPdfString(PDFContent $content, array $options = []): ?string;
}
