<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFReaderRegistry.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Registries;

use PDFToolkit\Contracts\PDFReaderInterface;
use PDFToolkit\Entities\PDFDocument;
use PDFToolkit\Enums\PDFReaderType;
use CommonToolkit\Helper\FileSystem\Folder;
use ERRORToolkit\Traits\ErrorLog;
use Generator;

/**
 * Registry für PDF-Reader.
 * 
 * Lädt automatisch alle Reader aus dem Readers-Verzeichnis
 * und stellt sie nach Priorität sortiert zur Verfügung.
 */
final class PDFReaderRegistry {
    use ErrorLog;

    /** @var PDFReaderInterface[] */
    private array $readers = [];

    private bool $loaded = false;

    public function __construct() {
        $this->loadReaders();
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
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @param array $options Optionen (z.B. 'language' => 'deu+eng')
     * @return PDFDocument
     */
    public function extractText(string $pdfPath, array $options = []): PDFDocument {
        // Zuerst Text-PDF Reader probieren
        foreach ($this->getTextPdfReaders() as $reader) {
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

        // Dann OCR Reader probieren (gescannte PDFs)
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

        $this->logWarning("No reader could extract text from: $pdfPath");
        return new PDFDocument(
            text: null,
            reader: null,
            isScanned: false,
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