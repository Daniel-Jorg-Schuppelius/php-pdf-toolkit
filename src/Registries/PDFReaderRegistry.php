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
use PDFToolkit\Helper\PDFHelper;
use PDFToolkit\Helper\TextQualityAnalyzer;
use PDFToolkit\Config\Config;
use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\FileSystem\Folder;
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
     * @return PDFDocument
     * @throws InvalidArgumentException wenn die Datei nicht existiert
     */
    public function extractText(string $pdfPath, array $options = []): PDFDocument {
        if (!File::exists($pdfPath)) {
            self::logErrorAndThrow(InvalidArgumentException::class, "PDF-Datei existiert nicht: $pdfPath");
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
     */
    private function extractWithOcrReaders(string $pdfPath, array $options): ?PDFDocument {
        foreach ($this->getScannedPdfReaders() as $reader) {
            if (!$reader->canHandle($pdfPath)) {
                continue;
            }

            $text = $reader->extractText($pdfPath, $options);
            if ($text !== null && trim($text) !== '') {
                $this->logDebug("Text extracted via OCR with " . $reader::getType()->value);
                return new PDFDocument(
                    text: $text,
                    reader: $reader::getType(),
                    isScanned: true,
                    sourcePath: $pdfPath
                );
            }
        }

        return null;
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
     * @return PDFDocument
     * @throws InvalidArgumentException wenn die Datei nicht existiert
     */
    public function extractTextOnly(string $pdfPath, array $options = []): PDFDocument {
        if (!File::exists($pdfPath)) {
            self::logErrorAndThrow(InvalidArgumentException::class, "PDF-Datei existiert nicht: $pdfPath");
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
