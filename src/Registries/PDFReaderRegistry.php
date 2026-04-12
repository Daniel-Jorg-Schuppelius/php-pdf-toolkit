<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFReaderRegistry.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Registries;

use PDFToolkit\Contracts\PDFReaderInterface;
use PDFToolkit\Entities\PDFDocument;
use PDFToolkit\Enums\PDFReaderType;
use PDFToolkit\Helper\{PDFHelper, PDFSplitHelper, TextQualityAnalyzer};
use PDFToolkit\Config\Config;
use CommonToolkit\Helper\FileSystem\{File, Folder};
use ERRORToolkit\Traits\ErrorLog;
use Generator;
use InvalidArgumentException;

/**
 * Registry für PDF-Reader (Singleton).
 * 
 * Lädt automatisch alle Reader aus dem Readers-Verzeichnis
 * und stellt sie nach Priorität sortiert zur Verfügung.
 * 
 * Verwendung:
 * ```php
 * $registry = PDFReaderRegistry::getInstance();
 * $document = $registry->extractText('/path/to/file.pdf');
 * ```
 */
final class PDFReaderRegistry {
    use ErrorLog;

    private static ?self $instance = null;

    /** @var PDFReaderInterface[] */
    private array $readers = [];

    private bool $loaded = false;

    /**
     * Privater Konstruktor für Singleton-Pattern.
     */
    private function __construct() {
        $this->loadReaders();
    }

    /**
     * Gibt die Singleton-Instanz zurück.
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Setzt die Singleton-Instanz zurück (nur für Tests).
     */
    public static function resetInstance(): void {
        self::$instance = null;
    }

    /**
     * Lädt alle verfügbaren PDF-Reader.
     */
    private function loadReaders(): void {
        if ($this->loaded) {
            return;
        }

        $readersDir = dirname(__DIR__) . '/Readers';

        if (!Folder::exists($readersDir)) {
            $this->logWarning("Readers directory not found: $readersDir");
            $this->loaded = true;
            return;
        }

        foreach (Folder::findByPattern($readersDir, '*.php') as $file) {
            $className = $this->getClassFromFile($file);
            if ($className && is_subclass_of($className, PDFReaderInterface::class)) {
                try {
                    $reader = new $className();
                    if ($reader->isAvailable()) {
                        $this->readers[] = $reader;
                        $this->logDebug("Loaded PDF reader: " . $className::getType()->value);
                    } else {
                        $this->logDebug("PDF reader not available: " . $className::getType()->value);
                    }
                } catch (\Throwable $e) {
                    $this->logWarning("Failed to load PDF reader $className: " . $e->getMessage());
                }
            }
        }

        // Nach Priorität sortieren (niedrig = zuerst)
        usort($this->readers, fn($a, $b) => $a::getPriority() <=> $b::getPriority());

        $this->loaded = true;
        $this->logInfo("Loaded " . count($this->readers) . " PDF readers");
    }

    /**
     * Ermittelt den Klassennamen aus einer PHP-Datei.
     */
    private function getClassFromFile(string $file): ?string {
        $basename = basename($file, '.php');
        $className = "PDFToolkit\\Readers\\$basename";

        if (class_exists($className)) {
            return $className;
        }

        return null;
    }

    /**
     * Gibt alle verfügbaren Reader als Generator zurück (nach Priorität sortiert).
     * 
     * @return Generator<PDFReaderInterface>
     */
    public function getReaders(): Generator {
        foreach ($this->readers as $reader) {
            yield $reader;
        }
    }

    /**
     * Gibt alle verfügbaren Reader als Array zurück (indiziert nach Typ).
     * 
     * @return array<string, PDFReaderInterface>
     */
    public function getAvailableReaders(): array {
        $result = [];
        foreach ($this->readers as $reader) {
            $result[$reader::getType()->value] = $reader;
        }
        return $result;
    }

    /**
     * Gibt nur Reader zurück, die für Text-PDFs geeignet sind.
     * 
     * @return Generator<PDFReaderInterface>
     */
    public function getTextPdfReaders(): Generator {
        foreach ($this->readers as $reader) {
            if ($reader::supportsTextPdfs()) {
                yield $reader;
            }
        }
    }

    /**
     * Gibt nur Reader zurück, die für gescannte PDFs (OCR) geeignet sind.
     * 
     * @return Generator<PDFReaderInterface>
     */
    public function getScannedPdfReaders(): Generator {
        foreach ($this->readers as $reader) {
            if ($reader::supportsScannedPdfs()) {
                yield $reader;
            }
        }
    }

    /**
     * Gibt einen Reader nach Typ zurück.
     */
    public function getByType(PDFReaderType $type): ?PDFReaderInterface {
        foreach ($this->readers as $reader) {
            if ($reader::getType() === $type) {
                return $reader;
            }
        }
        return null;
    }

    /**
     * Versucht, Text aus einer PDF zu extrahieren.
     * Probiert alle Reader der Reihe nach durch.
     * OCR wird nur verwendet, wenn kein eingebetteter Text vorhanden ist
     * oder wenn die Textqualität unter einem Schwellwert liegt.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @param array $options Optionen:
     *   - 'language': Sprache für OCR (z.B. 'deu+eng')
     *   - 'forceOcr': OCR erzwingen auch bei Text-PDFs (Standard: false)
     *   - 'qualityCheck': Qualitätsprüfung aktivieren (Standard: Config)
     *   - 'qualityThreshold': Schwellwert für OCR-Fallback (Standard: 60)
     *   - 'layout': Layout-Modus für pdftotext (Standard: true)
     *   - 'preferredReader': PDFReaderType – diesen Reader bevorzugt verwenden.
     *                        Bei Erfolg wird sofort das Ergebnis zurückgegeben.
     *                        Bei Fehler/nicht verfügbar: normaler Ablauf als Fallback.
     * @return PDFDocument
     * @throws InvalidArgumentException wenn die Datei nicht existiert
     */
    public function extractText(string $pdfPath, array $options = []): PDFDocument {
        if (!File::exists($pdfPath)) {
            self::logErrorAndThrow(InvalidArgumentException::class, "PDF-Datei existiert nicht: $pdfPath");
        }

        // Bevorzugten Reader zuerst versuchen
        $preferredResult = $this->tryPreferredReader($pdfPath, $options);
        if ($preferredResult !== null) {
            return $preferredResult;
        }

        $forceOcr = $options['forceOcr'] ?? false;
        $qualityCheck = $options['qualityCheck'] ?? $this->getQualityCheckSetting();
        $qualityThreshold = $options['qualityThreshold'] ?? $this->getQualityThresholdSetting();

        // Prüfen ob das PDF eingebetteten Text hat
        $hasEmbeddedText = PDFHelper::hasEmbeddedText($pdfPath);

        if ($hasEmbeddedText && !$forceOcr) {
            // PDF hat eingebetteten Text -> nur Text-Reader verwenden (kein OCR)
            $this->logDebug("PDF has embedded text, using text readers only");

            $textResult = $this->extractWithTextReaders($pdfPath, $options);

            if ($textResult !== null) {
                // Qualitätsprüfung durchführen wenn aktiviert
                if ($qualityCheck && $textResult->hasText()) {
                    $language = $options['language'] ?? Config::getInstance()->getConfig('PDFSettings', 'tesseract_lang') ?? 'deu+eng';
                    $qualityScore = TextQualityAnalyzer::calculateQualityScore($textResult->text, $language);

                    $this->logDebug("Text quality score: " . round($qualityScore, 2) . " (threshold: $qualityThreshold)");

                    if ($qualityScore < $qualityThreshold) {
                        $this->logInfo("Text quality below threshold ($qualityScore < $qualityThreshold), trying OCR fallback");

                        $ocrResult = $this->extractWithOcrReaders($pdfPath, $options);

                        if ($ocrResult !== null && $ocrResult->hasText()) {
                            $ocrScore = TextQualityAnalyzer::calculateQualityScore($ocrResult->text, $language);
                            $this->logDebug("OCR quality score: " . round($ocrScore, 2));

                            if ($ocrScore > $qualityScore) {
                                $this->logInfo("OCR result better ($ocrScore > $qualityScore), using OCR");
                                return $ocrResult;
                            } else {
                                $this->logDebug("Original text result still better, keeping it");
                            }
                        }
                    }
                }

                return $textResult;
            }
        } else {
            // Kein eingebetteter Text oder OCR erzwungen -> OCR verwenden
            $this->logDebug($forceOcr ? "OCR forced by option" : "PDF likely scanned, using OCR readers");

            $ocrResult = $this->extractWithOcrReaders($pdfPath, $options);
            if ($ocrResult !== null) {
                return $ocrResult;
            }
        }

        $this->logWarning("No reader could extract text from: $pdfPath");
        return new PDFDocument(
            text: null,
            reader: null,
            isScanned: false,
            sourcePath: $pdfPath
        );
    }

    /**
     * Extrahiert Text mit Text-Readern (pdftotext, pdfbox, etc.).
     */
    private function extractWithTextReaders(string $pdfPath, array $options): ?PDFDocument {
        foreach ($this->getTextPdfReaders() as $reader) {
            // OCR-Reader überspringen wenn Text vorhanden
            if ($reader::supportsScannedPdfs() && !$reader::supportsTextPdfs()) {
                continue;
            }

            // Reine OCR-Reader überspringen (z.B. tesseract, ocrmypdf)
            if ($reader::getType()->isOcrOnly()) {
                continue;
            }

            if (!$reader->canHandle($pdfPath)) {
                continue;
            }

            $text = $reader->extractText($pdfPath, $options);
            if ($text !== null && trim($text) !== '') {
                $this->logDebug("Text extracted with " . $reader::getType()->value);
                return new PDFDocument(
                    text: $text,
                    reader: $reader::getType(),
                    isScanned: false,
                    sourcePath: $pdfPath
                );
            }
        }

        return null;
    }

    /**
     * Extrahiert Text mit OCR-Readern (tesseract, ocrmypdf).
     * 
     * Bei aktivierter Qualitätsprüfung werden OCR-Ergebnisse verglichen:
     * - Wenn der erste Reader einen ausreichenden Score liefert (>= Schwellwert),
     *   wird das Ergebnis sofort zurückgegeben (Performance).
     * - Liegt der Score unter dem Schwellwert, werden weitere Reader getestet
     *   und das beste Ergebnis zurückgegeben.
     */
    private function extractWithOcrReaders(string $pdfPath, array $options): ?PDFDocument {
        $qualityCheck = $options['qualityCheck'] ?? $this->getQualityCheckSetting();
        $qualityThreshold = $options['qualityThreshold'] ?? $this->getQualityThresholdSetting();

        $bestResult = null;
        $bestScore = -1.0;

        foreach ($this->getScannedPdfReaders() as $reader) {
            if (!$reader->canHandle($pdfPath)) {
                continue;
            }

            $text = $reader->extractText($pdfPath, $options);
            if ($text === null || trim($text) === '') {
                continue;
            }

            $result = new PDFDocument(
                text: $text,
                reader: $reader::getType(),
                isScanned: true,
                sourcePath: $pdfPath
            );

            // Ohne Qualitätsprüfung: erstes erfolgreiches Ergebnis zurückgeben (Originalverhalten)
            if (!$qualityCheck) {
                $this->logDebug("Text extracted via OCR with " . $reader::getType()->value);
                return $result;
            }

            // Mit Qualitätsprüfung: OCR-Reader vergleichen
            $language = $options['language'] ?? Config::getInstance()->getConfig('PDFSettings', 'tesseract_lang') ?? 'deu+eng';
            $score = TextQualityAnalyzer::calculateQualityScore($text, $language);
            $this->logDebug("OCR reader {$reader::getType()->value}: quality score " . round($score, 2) . ", " . strlen($text) . " bytes");

            if ($score > $bestScore) {
                $bestResult = $result;
                $bestScore = $score;
            }

            // Bei ausreichender Qualität sofort zurückgeben (kein unnötiges OCR)
            if ($score >= $qualityThreshold) {
                $this->logDebug("OCR quality above threshold ($score >= $qualityThreshold), using {$reader::getType()->value}");
                return $result;
            }
        }

        if ($bestResult !== null) {
            $this->logInfo("Best OCR result: {$bestResult->reader->value} with score " . round($bestScore, 2));
        }

        return $bestResult;
    }

    /**
     * Extrahiert Text mit selektivem seitenweisen OCR-Fallback.
     * 
     * Kombiniert Text-Reader und OCR-Reader auf Seitenebene:
     * 1. Text-Extraktion für das gesamte Dokument
     * 2. Seitenweise Qualitätsbewertung
     * 3. Nur für Seiten mit niedriger Qualität: OCR-Fallback
     * 4. Merge der besten Ergebnisse pro Seite
     * 
     * Voraussetzung: pdftk muss verfügbar sein (für Seiten-Extraktion).
     * Wenn pdftk nicht verfügbar ist, wird auf den normalen extractText() Fallback zurückgegriffen.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @param array $options Optionen (wie bei extractText)
     * @return PDFDocument
     * @throws InvalidArgumentException wenn die Datei nicht existiert
     */
    public function extractWithSelectiveOcr(string $pdfPath, array $options = []): PDFDocument {
        if (!File::exists($pdfPath)) {
            self::logErrorAndThrow(InvalidArgumentException::class, "PDF-Datei existiert nicht: $pdfPath");
        }

        // Zuerst Text mit normalen Readern extrahieren
        $textResult = $this->extractWithTextReaders($pdfPath, $options);
        if ($textResult === null || !$textResult->hasText()) {
            // Kein Text gefunden, Fallback auf Standard-OCR
            return $this->extractText($pdfPath, array_merge($options, ['forceOcr' => true]));
        }

        $language = $options['language'] ?? Config::getInstance()->getConfig('PDFSettings', 'tesseract_lang') ?? 'deu+eng';
        $qualityThreshold = $options['qualityThreshold'] ?? $this->getQualityThresholdSetting();

        // Seitenweise Qualitätsbewertung
        $pageScores = TextQualityAnalyzer::calculatePageQualityScores($textResult->text, $language);
        $lowQualityPages = $pageScores['lowQualityPages'];

        if (empty($lowQualityPages)) {
            $this->logDebug("All pages above quality threshold, no selective OCR needed");
            return $textResult;
        }

        // pdftk muss verfügbar sein für Seiten-Extraktion
        if (!PDFSplitHelper::isAvailable()) {
            $this->logDebug("pdftk not available, falling back to full OCR");
            return $this->extractText($pdfPath, $options);
        }

        $totalPages = count($pageScores['scores']);

        // Wenn mehr als 50% der Seiten schlecht sind, lohnt sich selektives OCR nicht
        if (count($lowQualityPages) > $totalPages * 0.5) {
            $this->logDebug(sprintf(
                "Too many low-quality pages (%d/%d), falling back to full OCR",
                count($lowQualityPages),
                $totalPages
            ));
            return $this->extractText($pdfPath, $options);
        }

        $this->logInfo(sprintf(
            "Selective OCR: %d/%d pages need OCR (pages: %s)",
            count($lowQualityPages),
            $totalPages,
            implode(', ', $lowQualityPages)
        ));

        // Text in Seiten aufteilen
        $textPages = explode("\f", $textResult->text);
        $ocrUsed = false;
        $tempDir = sys_get_temp_dir() . '/selective_ocr_' . uniqid();

        try {
            Folder::create($tempDir);

            foreach ($lowQualityPages as $pageNum) {
                $pageIndex = $pageNum - 1; // 0-basiert

                if ($pageIndex >= count($textPages)) {
                    continue;
                }

                // Einzelne Seite als PDF extrahieren
                $pagePdf = $tempDir . "/page_{$pageNum}.pdf";
                if (!PDFSplitHelper::extractPages($pdfPath, $pagePdf, $pageNum, $pageNum)) {
                    $this->logDebug("Could not extract page $pageNum for selective OCR");
                    continue;
                }

                // OCR für diese einzelne Seite
                $ocrResult = $this->extractWithOcrReaders($pagePdf, $options);
                if ($ocrResult !== null && $ocrResult->hasText()) {
                    $ocrScore = TextQualityAnalyzer::calculateQualityScore($ocrResult->text, $language);
                    $originalScore = $pageScores['scores'][$pageIndex] ?? 0.0;

                    if ($ocrScore > $originalScore) {
                        $this->logDebug(sprintf(
                            "Page %d: OCR better (%.2f > %.2f), replacing",
                            $pageNum,
                            $ocrScore,
                            $originalScore
                        ));
                        $textPages[$pageIndex] = trim($ocrResult->text);
                        $ocrUsed = true;
                    } else {
                        $this->logDebug(sprintf(
                            "Page %d: Original text still better (%.2f >= %.2f)",
                            $pageNum,
                            $originalScore,
                            $ocrScore
                        ));
                    }
                }
            }
        } finally {
            Folder::delete($tempDir, true);
        }

        // Seiten mit Form-Feed wieder zusammensetzen
        $mergedText = implode("\f", $textPages);

        return new PDFDocument(
            text: $mergedText,
            reader: $ocrUsed ? null : $textResult->reader,
            isScanned: $ocrUsed,
            sourcePath: $pdfPath,
            metadata: ['selectiveOcr' => $ocrUsed, 'lowQualityPages' => $lowQualityPages]
        );
    }

    /**
     * Holt die Einstellung für automatische Qualitätsprüfung aus der Config.
     */
    private function getQualityCheckSetting(): bool {
        return (bool) (Config::getInstance()->getConfig('PDFSettings', 'quality_check_enabled') ?? true);
    }

    /**
     * Holt den Qualitätsschwellwert aus der Config.
     */
    private function getQualityThresholdSetting(): float {
        return (float) (Config::getInstance()->getConfig('PDFSettings', 'quality_threshold') ?? 60.0);
    }

    /**
     * Versucht den bevorzugten Reader aus den Optionen zu verwenden.
     *
     * Wenn 'preferredReader' in $options gesetzt ist, wird dieser Reader
     * zuerst versucht. Bei Erfolg wird das Ergebnis sofort zurückgegeben.
     * Bei Fehler, nicht verfügbar, oder leerem Ergebnis wird null zurückgegeben
     * und der normale Ablauf greift als Fallback.
     *
     * @param string $pdfPath Pfad zur PDF-Datei
     * @param array $options Optionen (inkl. 'preferredReader')
     * @param bool $textOnly Wenn true, werden OCR-Reader abgelehnt
     * @return PDFDocument|null Ergebnis oder null wenn Fallback nötig
     */
    private function tryPreferredReader(string $pdfPath, array $options, bool $textOnly = false): ?PDFDocument {
        $preferredType = $options['preferredReader'] ?? null;

        if (!$preferredType instanceof PDFReaderType) {
            return null;
        }

        // Bei textOnly dürfen keine reinen OCR-Reader verwendet werden
        if ($textOnly && $preferredType->isOcrOnly()) {
            $this->logDebug("Preferred reader {$preferredType->value} is OCR-only, skipped in textOnly mode");
            return null;
        }

        $reader = $this->getByType($preferredType);
        if ($reader === null) {
            $this->logDebug("Preferred reader {$preferredType->value} not available, falling back to default");
            return null;
        }

        if (!$reader->canHandle($pdfPath)) {
            $this->logDebug("Preferred reader {$preferredType->value} cannot handle file, falling back to default");
            return null;
        }

        $text = $reader->extractText($pdfPath, $options);
        if ($text === null || trim($text) === '') {
            $this->logDebug("Preferred reader {$preferredType->value} returned empty result, falling back to default");
            return null;
        }

        $isScanned = $reader::supportsScannedPdfs() && !$reader::supportsTextPdfs();
        $this->logDebug("Text extracted with preferred reader: {$preferredType->value}");

        return new PDFDocument(
            text: $text,
            reader: $preferredType,
            isScanned: $isScanned,
            sourcePath: $pdfPath
        );
    }

    /**
     * Gibt die Anzahl verfügbarer Reader zurück.
     */
    public function count(): int {
        return count($this->readers);
    }

    /**
     * Extrahiert Text nur mit Text-Readern (kein OCR).
     * 
     * Ideal für PDFs mit eingebettetem Text wie z.B. Bank-Kontoauszüge.
     * Schneller als extractText(), da kein OCR-Fallback versucht wird.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @param array $options Optionen:
     *   - 'layout': Layout-Modus für pdftotext (Standard: true)
     *              Bei false wird der Text ohne Layout-Formatierung extrahiert,
     *              was für Regex-basierte Transaktions-Extraktion besser geeignet ist.
     *   - 'preferredReader': PDFReaderType – diesen Reader bevorzugt verwenden.
     *                        Bei Erfolg wird sofort das Ergebnis zurückgegeben.
     *                        Bei Fehler/nicht verfügbar: normaler Text-Reader-Ablauf als Fallback.
     * @return PDFDocument
     * @throws InvalidArgumentException wenn die Datei nicht existiert
     */
    public function extractTextOnly(string $pdfPath, array $options = []): PDFDocument {
        if (!File::exists($pdfPath)) {
            self::logErrorAndThrow(InvalidArgumentException::class, "PDF-Datei existiert nicht: $pdfPath");
        }

        // Bevorzugten Reader zuerst versuchen (nur wenn es ein Text-Reader ist)
        $preferredResult = $this->tryPreferredReader($pdfPath, $options, textOnly: true);
        if ($preferredResult !== null) {
            return $preferredResult;
        }

        $result = $this->extractWithTextReaders($pdfPath, $options);

        if ($result !== null) {
            return $result;
        }

        $this->logDebug("No text reader could extract text from: $pdfPath");
        return new PDFDocument(
            text: null,
            reader: null,
            isScanned: false,
            sourcePath: $pdfPath
        );
    }

    /**
     * Extrahiert Text mit ALLEN verfügbaren Readern.
     * Nützlich um verschiedene OCR-Ergebnisse zu vergleichen.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @param array $options Optionen (z.B. 'language' => 'deu+eng')
     * @param bool $ocrOnly Nur OCR-Reader verwenden (Standard: false)
     * @return PDFDocument Mit allen Ergebnissen in alternatives
     */
    public function extractAllText(string $pdfPath, array $options = [], bool $ocrOnly = false): PDFDocument {
        $primaryText = null;
        $primaryReader = null;
        $primaryIsScanned = false;
        $alternatives = [];

        $readers = $ocrOnly ? $this->getScannedPdfReaders() : $this->getReaders();

        foreach ($readers as $reader) {
            if (!$reader->canHandle($pdfPath)) {
                continue;
            }

            $text = $reader->extractText($pdfPath, $options);
            if ($text !== null && trim($text) !== '') {
                $readerType = $reader::getType();
                $isScanned = $reader::supportsScannedPdfs();

                if ($primaryText === null) {
                    // Erstes erfolgreiches Ergebnis wird primär
                    $primaryText = $text;
                    $primaryReader = $readerType;
                    $primaryIsScanned = $isScanned;
                    $this->logDebug("Primary text extracted with " . $readerType->value);
                } else {
                    // Weitere Ergebnisse werden als Alternativen gespeichert
                    $alternatives[$readerType->value] = [
                        'text' => $text,
                        'isScanned' => $isScanned
                    ];
                    $this->logDebug("Alternative text extracted with " . $readerType->value);
                }
            }
        }

        if ($primaryText === null) {
            $this->logWarning("No reader could extract text from: $pdfPath");
        } else {
            $this->logInfo(sprintf(
                "Extracted text with %d reader(s) from: %s",
                1 + count($alternatives),
                $pdfPath
            ));
        }

        return new PDFDocument(
            text: $primaryText,
            reader: $primaryReader,
            isScanned: $primaryIsScanned,
            sourcePath: $pdfPath,
            alternatives: $alternatives
        );
    }

    /**
     * Gibt die Typen aller verfügbaren Reader zurück.
     * 
     * @return PDFReaderType[]
     */
    public function getAvailableReaderTypes(): array {
        return array_map(fn($r) => $r::getType(), $this->readers);
    }
}
