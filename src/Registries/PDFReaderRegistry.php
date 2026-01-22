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

        if (!is_dir($readersDir)) {
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
                        $this->logDebug("Loaded PDF reader: " . $className::getName());
                    } else {
                        $this->logDebug("PDF reader not available: " . $className::getName());
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
     * Gibt alle verfügbaren Reader als Array zurück (indiziert nach Name).
     * 
     * @return array<string, PDFReaderInterface>
     */
    public function getAvailableReaders(): array {
        $result = [];
        foreach ($this->readers as $reader) {
            $result[$reader::getName()] = $reader;
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
     * Gibt einen Reader nach Namen zurück.
     */
    public function getByName(string $name): ?PDFReaderInterface {
        foreach ($this->readers as $reader) {
            if ($reader::getName() === $name) {
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
                $this->logDebug("Text extracted with " . $reader::getName());
                return new PDFDocument(
                    text: $text,
                    reader: $reader::getName(),
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
                $this->logDebug("Text extracted via OCR with " . $reader::getName());
                return new PDFDocument(
                    text: $text,
                    reader: $reader::getName(),
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
     * Gibt die Namen aller verfügbaren Reader zurück.
     * 
     * @return string[]
     */
    public function getAvailableReaderNames(): array {
        return array_map(fn($r) => $r::getName(), $this->readers);
    }
}
