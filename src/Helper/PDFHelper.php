<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;
use PDFToolkit\Entities\PageSize;
use PDFToolkit\Enums\PaperFormat;

/**
 * Helper-Klasse für PDF-Operationen.
 */
final class PDFHelper {
    use ErrorLog;

    /** Cache für hasEmbeddedText() Ergebnisse (filePath:minChars => bool) */
    private static array $embeddedTextCache = [];

    /** Cache für den bei hasEmbeddedText() extrahierten Text (filePath => string) */
    private static array $extractedTextCache = [];

    /**
     * Löscht den Cache für hasEmbeddedText() und den Text-Cache.
     */
    public static function clearCache(): void {
        self::$embeddedTextCache = [];
        self::$extractedTextCache = [];
    }

    /**
     * Gibt den gecacheten Text vom hasEmbeddedText()-Check zurück.
     *
     * Der Text wurde bereits beim Check extrahiert. Statt ihn nochmal
     * zu extrahieren, kann er direkt wiederverwendet werden.
     *
     * @param string $filePath Pfad zur PDF-Datei
     * @return string|null Der gecachte Text oder null wenn nicht verfügbar
     */
    public static function getCachedExtractedText(string $filePath): ?string {
        return self::$extractedTextCache[$filePath] ?? null;
    }

    /**
     * Prüft ob eine Datei ein gültiges PDF ist.
     */
    public static function isValidPdf(string $filePath): bool {
        if (!File::exists($filePath) || !File::isReadable($filePath)) {
            return false;
        }

        if (!File::isExtension($filePath, ['.pdf'])) {
            return false;
        }

        // PDF-Signatur prüfen (%PDF-)
        // Manche PDFs haben führende Whitespace-Bytes vor der Signatur.
        // Laut PDF-Spec sollte %PDF- innerhalb der ersten 1024 Bytes stehen.
        $header = File::readPartial($filePath, 1024);
        if ($header === false) {
            return false;
        }

        return str_contains($header, '%PDF-');
    }

    /**
     * Extrahiert Metadaten aus einer PDF-Datei via pdfinfo.
     *
     * @return array<string, string> Assoziatives Array mit Metadaten
     */
    public static function getMetadata(string $filePath): array {
        if (!self::isValidPdf($filePath)) {
            return [];
        }

        $config = Config::getInstance();
        if ($config->getShellExecutable('pdfinfo') === null) {
            return [];
        }

        $command = $config->buildCommand('pdfinfo', [
            '[PDF-FILE]' => $filePath,
        ]);

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $metadata = [];
        foreach ($output as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * Gibt die Seitenanzahl einer PDF zurück.
     */
    public static function getPageCount(string $filePath): int {
        $metadata = self::getMetadata($filePath);
        return isset($metadata['Pages']) ? (int) $metadata['Pages'] : 0;
    }

    /**
     * Prüft ob das PDF Text enthält (nicht nur Bilder).
     * Nutzt pdftotext für einen schnellen Check der ersten Seite.
     * Ergebnis wird gecacht um redundante Shell-Aufrufe zu vermeiden.
     */
    public static function hasEmbeddedText(string $filePath, int $minChars = 20): bool {
        $cacheKey = $filePath . ':' . $minChars;
        if (isset(self::$embeddedTextCache[$cacheKey])) {
            return self::$embeddedTextCache[$cacheKey];
        }

        // Optimierung: Wenn ein höherer minChars-Wert schon true war,
        // dann ist ein niedrigerer Wert garantiert auch true.
        foreach (self::$embeddedTextCache as $key => $value) {
            if ($value && str_starts_with($key, $filePath . ':')) {
                $cachedMinChars = (int) substr($key, strlen($filePath) + 1);
                if ($cachedMinChars >= $minChars) {
                    self::$embeddedTextCache[$cacheKey] = true;
                    return true;
                }
            }
        }

        $result = self::checkEmbeddedText($filePath, $minChars);
        self::$embeddedTextCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Tatsächliche Prüfung ob das PDF eingebetteten Text enthält (ohne Cache).
     *
     * Mehrstufig (v1-Parität, file_converter::pdf_handling): ein PDF gilt erst dann als
     * "gescannt", wenn KEIN Extraktor über das GESAMTE Dokument genug Text findet. v1 hat
     * pdfbox UND pdftotext (mit/ohne Cleanup) über das ganze Dokument geprüft und nur bei
     * Fehlschlag aller Extraktoren ge-OCR't – deutlich robuster als ein Seite-1-Probe mit
     * einem einzigen Extraktor:
     *   - Bild-Deckblatt + Text-Folgeseiten würde ein Seite-1-Check fälschlich als Scan werten.
     *   - Layouts, an denen pdftotext scheitert, liest pdfbox oft problemlos.
     *
     * Reihenfolge (schnell → gründlich, damit der Normalfall den billigen Pfad nimmt):
     *   1. Schneller Probe-Check auf Seite 1 (häufigster Fall: digitales PDF mit Text ab S.1)
     *   2. Erst wenn Seite 1 leer wirkt: Ganzdokument-pdftotext
     *   3. Zuletzt: pdfbox als zweiter Extraktor (anderes Engine, andere Stärken)
     */
    private static function checkEmbeddedText(string $filePath, int $minChars): bool {
        if (!self::isValidPdf($filePath)) {
            return false;
        }

        // 1. Schneller Probe-Check auf Seite 1
        $text = self::runTextProbe($filePath, true);
        if ($text !== null && self::countEmbeddedChars($text) >= $minChars) {
            self::cacheExtractedText($filePath, $text);
            return true;
        }

        // 2. Gegen-Probe über das gesamte Dokument (Seite 1 kann Bild/leer sein, Text folgt später)
        $fullText = self::runTextProbe($filePath, false);
        if ($fullText !== null && self::countEmbeddedChars($fullText) >= $minChars) {
            self::cacheExtractedText($filePath, $fullText);
            return true;
        }

        // 3. Zweiter Extraktor (pdfbox): liest Layouts, an denen pdftotext scheitert
        $pdfBoxText = self::runPdfBoxProbe($filePath);
        if ($pdfBoxText !== null && self::countEmbeddedChars($pdfBoxText) >= $minChars) {
            self::cacheExtractedText($filePath, $pdfBoxText);
            return true;
        }

        // Kein Extraktor fand über das gesamte Dokument genug Text → echter Scan (OCR nötig)
        return false;
    }

    /**
     * Führt eine pdftotext-Textextraktion aus (Seite 1 oder ganzes Dokument).
     *
     * @param bool $firstPageOnly true = nur Seite 1 (schneller Probe-Check), false = ganzes Dokument
     * @return string|null Extrahierter Rohtext oder null bei Fehler/kein Extraktor verfügbar
     */
    private static function runTextProbe(string $filePath, bool $firstPageOnly): ?string {
        $config = Config::getInstance();
        $tempFile = sys_get_temp_dir() . '/pdf_check_' . uniqid() . '.txt';

        // Seite-1-Probe bevorzugt pdftotext-check; Ganzdokument bevorzugt den layout-losen
        // pdftotext-raw und fällt sonst auf pdftotext (mit Layout) zurück.
        if ($firstPageOnly && $config->getShellExecutable('pdftotext-check') !== null) {
            $command = $config->buildCommand('pdftotext-check', [
                '[LAST-PAGE]' => '1',
                '[PDF-FILE]' => $filePath,
                '[TEXT-FILE]' => $tempFile,
            ]);
        } elseif ($config->getShellExecutable('pdftotext-raw') !== null) {
            $command = $config->buildCommand('pdftotext-raw', [
                '[PDF-FILE]' => $filePath,
                '[TEXT-FILE]' => $tempFile,
            ]);
        } elseif ($config->getShellExecutable('pdftotext') !== null) {
            $command = $config->buildCommand('pdftotext', [
                '[PDF-FILE]' => $filePath,
                '[TEXT-FILE]' => $tempFile,
            ]);
        } else {
            return null;
        }

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0 || !File::exists($tempFile)) {
            File::delete($tempFile);
            return null;
        }

        $text = File::read($tempFile);
        File::delete($tempFile);
        return $text;
    }

    /**
     * Führt eine pdfbox-Textextraktion (Java) als zweiten Extraktor aus.
     *
     * Fehlertolerant: ist Java/pdfbox nicht verfügbar, wird null zurückgegeben und der
     * Aufrufer verlässt sich auf die pdftotext-Ergebnisse.
     *
     * @return string|null Extrahierter Rohtext oder null bei Fehler/nicht verfügbar
     */
    private static function runPdfBoxProbe(string $filePath): ?string {
        $config = Config::getInstance();
        if ($config->getShellExecutable('java') === null || $config->getJavaExecutable('pdfbox') === null) {
            return null;
        }

        $tempFile = sys_get_temp_dir() . '/pdf_check_box_' . uniqid() . '.txt';
        $command = $config->buildJavaCommand('pdfbox', [
            '[INPUT]' => $filePath,
            '[OUTPUT]' => $tempFile,
        ]);

        if ($command === null) {
            return null;
        }

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0 || !File::exists($tempFile)) {
            File::delete($tempFile);
            return null;
        }

        $text = File::read($tempFile);
        File::delete($tempFile);
        return $text;
    }

    /**
     * Zählt die Nicht-Whitespace-Zeichen eines extrahierten Textes.
     */
    private static function countEmbeddedChars(string $text): int {
        return strlen(preg_replace('/\s+/', '', $text) ?? '');
    }

    /**
     * Cacht den extrahierten Text zur späteren Wiederverwendung durch die Reader.
     */
    private static function cacheExtractedText(string $filePath, string $text): void {
        if (trim($text) !== '') {
            self::$extractedTextCache[$filePath] = $text;
        }
    }

    /**
     * Schätzt ob das PDF gescannt ist (nur Bilder, kein Text).
     */
    public static function isLikelyScanned(string $filePath): bool {
        return self::isValidPdf($filePath) && !self::hasEmbeddedText($filePath);
    }

    /**
     * Versucht die Sprache aus den PDF-Metadaten zu erkennen.
     *
     * Prüft das "Language"-Tag aus pdfinfo. Gibt ein Tesseract-kompatibles
     * Sprachkürzel zurück (z.B. 'deu', 'eng', 'fra') oder null.
     *
     * @return string|null Tesseract-Sprachkürzel oder null
     */
    public static function detectLanguage(string $filePath): ?string {
        $metadata = self::getMetadata($filePath);
        $language = $metadata['Language'] ?? null;

        if ($language === null || trim($language) === '') {
            return null;
        }

        // ISO 639-1 (2-stellig) oder ISO 639-2 (3-stellig) zu Tesseract-Kürzel
        $language = strtolower(trim($language));
        $map = [
            'de' => 'deu',
            'deu' => 'deu',
            'ger' => 'deu',
            'german' => 'deu',
            'deutsch' => 'deu',
            'en' => 'eng',
            'eng' => 'eng',
            'english' => 'eng',
            'fr' => 'fra',
            'fra' => 'fra',
            'fre' => 'fra',
            'french' => 'fra',
            'es' => 'spa',
            'spa' => 'spa',
            'spanish' => 'spa',
            'it' => 'ita',
            'ita' => 'ita',
            'italian' => 'ita',
            'nl' => 'nld',
            'nld' => 'nld',
            'dut' => 'nld',
            'dutch' => 'nld',
            'pt' => 'por',
            'por' => 'por',
            'portuguese' => 'por',
            'pl' => 'pol',
            'pol' => 'pol',
            'polish' => 'pol',
            'ru' => 'rus',
            'rus' => 'rus',
            'russian' => 'rus',
            'tr' => 'tur',
            'tur' => 'tur',
            'turkish' => 'tur',
        ];

        return $map[$language] ?? null;
    }

    /**
     * Gibt die PDF-Version zurück (z.B. "1.4", "1.7").
     */
    public static function getPdfVersion(string $filePath): ?string {
        if (!self::isValidPdf($filePath)) {
            return null;
        }

        $header = File::readPartial($filePath, 8);
        if ($header === false) {
            return null;
        }

        if (preg_match('/%PDF-(\d+\.\d+)/', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Gibt die Seitengröße einer bestimmten Seite zurück.
     *
     * @param string $filePath Pfad zur PDF-Datei
     * @param int $pageNumber Seitennummer (1-basiert, Standard: 1)
     * @return PageSize|null Die Seitengröße oder null bei Fehler
     */
    public static function getPageSize(string $filePath, int $pageNumber = 1): ?PageSize {
        $metadata = self::getMetadata($filePath);

        if (empty($metadata) || !isset($metadata['Page size'])) {
            return null;
        }

        // pdfinfo gibt "Page size" im Format "595.3 x 841.9 pts (A4)" zurück
        return PageSize::fromPdfInfoString($metadata['Page size'], $pageNumber);
    }

    /**
     * Gibt die Seitengrößen aller Seiten zurück.
     *
     * Nutzt pdfinfo -l für seitenweise Metadaten (nur wenn pdfinfo verfügbar).
     *
     * @return array<int, PageSize> Array mit PageSize-Objekten, indiziert nach Seitennummer (1-basiert)
     */
    public static function getAllPageSizes(string $filePath): array {
        if (!self::isValidPdf($filePath)) {
            return [];
        }

        $config = Config::getInstance();
        if ($config->getShellExecutable('pdfinfo') === null) {
            return [];
        }

        $pageCount = self::getPageCount($filePath);
        if ($pageCount === 0) {
            return [];
        }

        // pdfinfo -l N gibt detaillierte Info pro Seite
        $command = $config->buildCommand('pdfinfo', [
            '[PDF-FILE]' => $filePath,
        ], ['-l', (string) $pageCount]);

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $sizes = [];

        foreach ($output as $line) {
            // "Page    1 size:  ..." oder "Page   12 size:  ..."
            if (preg_match('/^Page\s+(\d+)\s+size:\s*([0-9.]+)\s*x\s*([0-9.]+)\s*pts/i', $line, $matches)) {
                $pageNum = (int) $matches[1];
                $sizes[$pageNum] = new PageSize(
                    widthPt: (float) $matches[2],
                    heightPt: (float) $matches[3],
                    pageNumber: $pageNum
                );
            }
        }

        // Fallback auf Standard-Seitengröße wenn keine seitenweisen Daten
        if (empty($sizes)) {
            $defaultSize = self::getPageSize($filePath, 1);
            if ($defaultSize !== null) {
                for ($i = 1; $i <= $pageCount; $i++) {
                    $sizes[$i] = new PageSize(
                        widthPt: $defaultSize->widthPt,
                        heightPt: $defaultSize->heightPt,
                        pageNumber: $i
                    );
                }
            }
        }

        return $sizes;
    }

    /**
     * Prüft ob das PDF ein bestimmtes Format hat.
     *
     * @param string $filePath Pfad zur PDF-Datei
     * @param PaperFormat|string $format Format-Enum oder String (z.B. "A4", "letter")
     * @param int $pageNumber Seitennummer (1-basiert, Standard: 1)
     * @param float $tolerancePt Toleranz in Points (Standard: 5.0 ≈ 1.8mm)
     */
    public static function isFormat(string $filePath, PaperFormat|string $format, int $pageNumber = 1, float $tolerancePt = 5.0): bool {
        $pageSize = self::getPageSize($filePath, $pageNumber);
        if ($pageSize === null) {
            return false;
        }
        return $pageSize->isFormat($format, $tolerancePt);
    }

    /**
     * Erkennt automatisch das Papierformat einer Seite.
     *
     * @param string $filePath Pfad zur PDF-Datei
     * @param int $pageNumber Seitennummer (1-basiert, Standard: 1)
     * @param float $tolerancePt Toleranz in Points
     * @return PaperFormat|null Das erkannte Format oder null
     */
    public static function detectFormat(string $filePath, int $pageNumber = 1, float $tolerancePt = 5.0): ?PaperFormat {
        $pageSize = self::getPageSize($filePath, $pageNumber);
        if ($pageSize === null) {
            return null;
        }
        return $pageSize->detectFormat($tolerancePt);
    }

    /**
     * Wählt einen Start-PSM (Page Segmentation Mode) für die OCR gescannter Seiten anhand
     * der Seitengröße (v1-Parität, file_converter: A4 → Standard-PSM, abweichende Größen → 12).
     *
     * Nicht-A4-Scans (Endlospapier, Quer-/Sonderformate) sind häufiger mehrspaltig oder
     * gedreht; PSM 12 (Sparse Text + OSD) trifft solche Layouts besser als der Block-Auto-Modus.
     * A4 und unbekannte Seitengrößen behalten bewusst den konfigurierten Default, damit der
     * häufigste Fall unverändert bleibt.
     *
     * @param int $defaultPsm Konfigurierter Standard-PSM (für A4/unbekannt)
     */
    public static function suggestScanPsm(string $filePath, int $defaultPsm): int {
        $pageSize = self::getPageSize($filePath, 1);
        if ($pageSize === null) {
            return $defaultPsm;
        }
        return $pageSize->isFormat(PaperFormat::A4) ? $defaultPsm : 12;
    }

    /**
     * Prüft ob das PDF im Landscape-Format ist.
     *
     * @param string $filePath Pfad zur PDF-Datei
     * @param int $pageNumber Seitennummer (1-basiert, Standard: 1)
     */
    public static function isLandscape(string $filePath, int $pageNumber = 1): bool {
        $pageSize = self::getPageSize($filePath, $pageNumber);
        return $pageSize?->isLandscape() ?? false;
    }

    /**
     * Prüft ob das PDF im Portrait-Format ist.
     *
     * @param string $filePath Pfad zur PDF-Datei
     * @param int $pageNumber Seitennummer (1-basiert, Standard: 1)
     */
    public static function isPortrait(string $filePath, int $pageNumber = 1): bool {
        $pageSize = self::getPageSize($filePath, $pageNumber);
        return $pageSize?->isPortrait() ?? false;
    }

    /**
     * Prüft ob alle Seiten das gleiche Format haben.
     *
     * @param float $tolerancePt Toleranz in Points
     */
    public static function hasUniformPageSize(string $filePath, float $tolerancePt = 5.0): bool {
        $sizes = self::getAllPageSizes($filePath);
        if (count($sizes) <= 1) {
            return true;
        }

        $firstSize = reset($sizes);
        foreach ($sizes as $size) {
            if (
                abs($size->widthPt - $firstSize->widthPt) > $tolerancePt ||
                abs($size->heightPt - $firstSize->heightPt) > $tolerancePt
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Gibt eine lesbare Beschreibung des PDF-Formats zurück.
     *
     * @param string $filePath Pfad zur PDF-Datei
     * @param int $pageNumber Seitennummer (1-basiert, Standard: 1)
     */
    public static function getFormatDescription(string $filePath, int $pageNumber = 1): string {
        $pageSize = self::getPageSize($filePath, $pageNumber);
        return $pageSize?->description() ?? 'Unbekannt';
    }

    /**
     * Rendert eine einzelne PDF-Seite als PNG-Bild.
     *
     * Nutzt pdftoppm für die Konvertierung. Die Ausgabedatei wird automatisch
     * generiert wenn kein outputPath angegeben wird.
     *
     * @param string $filePath Pfad zur PDF-Datei
     * @param int $pageNumber Seitennummer (1-basiert, Standard: 1)
     * @param int $dpi Auflösung in DPI (Standard: 72 für Vorschau, 150 für bessere Qualität)
     * @param string|null $outputPath Optionaler Ausgabepfad (ohne Extension)
     * @return string|null Pfad zur PNG-Datei oder null bei Fehler
     */
    public static function renderPageToImage(
        string $filePath,
        int $pageNumber = 1,
        int $dpi = 72,
        ?string $outputPath = null
    ): ?string {
        if (!self::isValidPdf($filePath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $filePath]);
            return null;
        }

        $config = Config::getInstance();
        if (
            $config->getShellExecutable('pdftoppm-page') === null
            && $config->getShellExecutable('pdftoppm') === null
        ) {
            self::logError('pdftoppm ist nicht konfiguriert oder nicht verfügbar');
            return null;
        }

        // Seitenzahl prüfen
        $pageCount = self::getPageCount($filePath);
        if ($pageNumber < 1 || $pageNumber > $pageCount) {
            self::logError('Ungültige Seitennummer', [
                'page' => $pageNumber,
                'totalPages' => $pageCount,
            ]);
            return null;
        }

        // Ausgabepfad generieren
        $outputPrefix = $outputPath ?? sys_get_temp_dir() . '/pdf_preview_' . uniqid();

        // Versuche zuerst pdftoppm-page (Einzelseiten-Modus)
        $command = $config->buildCommand('pdftoppm-page', [
            '[DPI]' => (string) $dpi,
            '[PAGE]' => (string) $pageNumber,
            '[PDF-FILE]' => $filePath,
            '[OUTPUT-PREFIX]' => $outputPrefix,
        ]);

        if ($command === null) {
            self::logError('Konnte pdftoppm-page Befehl nicht erstellen');
            return null;
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command, $output, $returnCode) || $returnCode !== 0) {
            self::logError('pdftoppm fehlgeschlagen', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return null;
        }

        // pdftoppm mit -singlefile erzeugt: {prefix}.png
        $outputFile = $outputPrefix . '.png';
        if (!File::exists($outputFile)) {
            self::logError('Vorschau-Bild wurde nicht erstellt', ['path' => $outputFile]);
            return null;
        }

        self::logDebug('PDF-Seite gerendert', [
            'input' => $filePath,
            'page' => $pageNumber,
            'output' => $outputFile,
            'dpi' => $dpi,
        ]);

        return $outputFile;
    }

    /**
     * Rendert eine PDF-Seite als Base64-kodiertes PNG.
     *
     * Praktisch für API-Responses ohne temporäre Dateien.
     *
     * @param string $filePath Pfad zur PDF-Datei
     * @param int $pageNumber Seitennummer (1-basiert, Standard: 1)
     * @param int $dpi Auflösung in DPI (Standard: 72)
     * @return string|null Base64-kodierter PNG-String oder null bei Fehler
     */
    public static function renderPageToBase64(
        string $filePath,
        int $pageNumber = 1,
        int $dpi = 72
    ): ?string {
        $imagePath = self::renderPageToImage($filePath, $pageNumber, $dpi);
        if ($imagePath === null) {
            return null;
        }

        try {
            $imageData = File::read($imagePath);
            return base64_encode($imageData);
        } finally {
            // Temporäre Datei aufräumen
            if (str_starts_with($imagePath, sys_get_temp_dir())) {
                File::delete($imagePath);
            }
        }
    }
}
