<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFReaderInterface.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Contracts;

use PDFToolkit\Enums\PDFReaderType;

/**
 * Interface für PDF-Reader.
 * 
 * Jeder Reader versucht, Text aus einem PDF zu extrahieren.
 * Die Reader werden nach Priorität durchprobiert, bis einer erfolgreich ist.
 */
interface PDFReaderInterface {
    /**
     * Reader-Typ als Enum.
     */
    public static function getType(): PDFReaderType;

    /**
     * Priorität des Readers (niedriger = wird früher probiert).
     * 
     * Empfohlene Werte:
     * - 10-20: Schnelle Text-Extraktion (pdftotext)
     * - 30-40: Komplexe Layouts (pdfbox)
     * - 50-70: OCR für gescannte Dokumente (tesseract, ocrmypdf)
     */
    public static function getPriority(): int;

    /**
     * Prüft, ob der Reader auf dem System verfügbar ist.
     * (z.B. ob das benötigte Tool installiert ist)
     */
    public function isAvailable(): bool;

    /**
     * Versucht, Text aus der PDF-Datei zu extrahieren.
     * 
     * @param string $pdfPath Absoluter Pfad zur PDF-Datei
     * @param array $options Optionale Konfiguration (z.B. Sprache für OCR)
     * @return string|null Extrahierter Text oder null wenn fehlgeschlagen
     */
    public function extractText(string $pdfPath, array $options = []): ?string;

    /**
     * Gibt an, ob dieser Reader für gescannte (Bild-)PDFs geeignet ist.
     */
    public static function supportsScannedPdfs(): bool;

    /**
     * Gibt an, ob dieser Reader für Text-PDFs (mit eingebettetem Text) geeignet ist.
     */
    public static function supportsTextPdfs(): bool;

    /**
     * Schätzt, ob der Reader für die gegebene PDF-Datei erfolgreich sein könnte.
     * Kann z.B. prüfen, ob die PDF Text enthält oder nur Bilder.
     * 
     * @param string $pdfPath Absoluter Pfad zur PDF-Datei
     * @return bool True wenn der Reader wahrscheinlich erfolgreich ist
     */
    public function canHandle(string $pdfPath): bool;
}
