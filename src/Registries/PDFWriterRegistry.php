<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFWriterRegistry.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Registries;

use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Contracts\PDFWriterInterface;
use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Writers\DompdfWriter;
use PDFToolkit\Writers\TcpdfWriter;
use PDFToolkit\Writers\WkhtmltopdfWriter;

/**
 * Registry für PDF-Writer.
 * 
 * Verwaltet alle verfügbaren Writer und wählt automatisch
 * den besten verfügbaren Writer für die Erstellung aus.
 */
final class PDFWriterRegistry {
    use ErrorLog;

    /** @var PDFWriterInterface[] */
    private array $writers = [];

    /** @var array<string, class-string<PDFWriterInterface>> */
    private static array $writerClasses = [
        DompdfWriter::class,
        TcpdfWriter::class,
        WkhtmltopdfWriter::class,
    ];

    public function __construct() {
        $this->loadWriters();
    }

    /**
     * Lädt und sortiert alle verfügbaren Writer.
     */
    private function loadWriters(): void {
        $writers = [];

        foreach (self::$writerClasses as $writerClass) {
            try {
                $writer = new $writerClass();
                $writers[] = $writer;

                $this->logDebug('Writer loaded', [
                    'name' => $writerClass::getName(),
                    'priority' => $writerClass::getPriority(),
                    'available' => $writer->isAvailable()
                ]);
            } catch (\Throwable $e) {
                $this->logError('Failed to load writer', [
                    'class' => $writerClass,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Nach Priorität sortieren (niedrigere Werte zuerst)
        usort($writers, fn($a, $b) => $a::getPriority() <=> $b::getPriority());

        $this->writers = $writers;
    }

    /**
     * Registriert einen zusätzlichen Writer.
     */
    public function registerWriter(PDFWriterInterface $writer): void {
        $this->writers[] = $writer;

        // Neu sortieren
        usort($this->writers, fn($a, $b) => $a::getPriority() <=> $b::getPriority());

        $this->logDebug('Writer registered', [
            'name' => $writer::getName(),
            'priority' => $writer::getPriority()
        ]);
    }

    /**
     * Gibt alle registrierten Writer zurück.
     * 
     * @return PDFWriterInterface[]
     */
    public function getWriters(): array {
        return $this->writers;
    }

    /**
     * Gibt alle verfügbaren Writer zurück.
     * 
     * @return PDFWriterInterface[]
     */
    public function getAvailableWriters(): array {
        return array_filter($this->writers, fn($w) => $w->isAvailable());
    }

    /**
     * Gibt einen spezifischen Writer nach Namen zurück.
     */
    public function getWriter(string $name): ?PDFWriterInterface {
        foreach ($this->writers as $writer) {
            if ($writer::getName() === $name) {
                return $writer;
            }
        }
        return null;
    }

    /**
     * Erstellt eine PDF-Datei aus dem gegebenen Content.
     * Probiert Writer nach Priorität durch, bis einer erfolgreich ist.
     * 
     * @param PDFContent $content Der zu konvertierende Inhalt
     * @param string $outputPath Absoluter Pfad für die Ausgabedatei
     * @param array $options Optionale Konfiguration
     * @param string|null $preferredWriter Name eines bevorzugten Writers
     * @return bool true wenn erfolgreich
     */
    public function createPdf(
        PDFContent $content,
        string $outputPath,
        array $options = [],
        ?string $preferredWriter = null
    ): bool {
        // Bevorzugter Writer
        if ($preferredWriter !== null) {
            $writer = $this->getWriter($preferredWriter);
            if ($writer !== null && $writer->isAvailable() && $writer->canHandle($content)) {
                $this->logDebug('Using preferred writer', ['writer' => $preferredWriter]);
                return $writer->createPdf($content, $outputPath, $options);
            }
            $this->logDebug('Preferred writer not available, trying others', ['preferred' => $preferredWriter]);
        }

        // Writer nach Priorität durchprobieren
        foreach ($this->writers as $writer) {
            if (!$writer->isAvailable()) {
                $this->logDebug('Writer not available', ['writer' => $writer::getName()]);
                continue;
            }

            if (!$writer->canHandle($content)) {
                $this->logDebug('Writer cannot handle content', ['writer' => $writer::getName()]);
                continue;
            }

            $this->logDebug('Trying writer', ['writer' => $writer::getName()]);

            if ($writer->createPdf($content, $outputPath, $options)) {
                $this->logDebug('PDF created successfully', [
                    'writer' => $writer::getName(),
                    'path' => $outputPath
                ]);
                return true;
            }

            $this->logDebug('Writer failed, trying next', ['writer' => $writer::getName()]);
        }

        $this->logError('No writer could create the PDF', [
            'availableWriters' => array_map(fn($w) => $w::getName(), $this->getAvailableWriters())
        ]);

        return false;
    }

    /**
     * Erstellt eine PDF und gibt den Inhalt als String zurück.
     * 
     * @param PDFContent $content Der zu konvertierende Inhalt
     * @param array $options Optionale Konfiguration
     * @param string|null $preferredWriter Name eines bevorzugten Writers
     * @return string|null PDF-Inhalt als String oder null bei Fehler
     */
    public function createPdfString(
        PDFContent $content,
        array $options = [],
        ?string $preferredWriter = null
    ): ?string {
        // Bevorzugter Writer
        if ($preferredWriter !== null) {
            $writer = $this->getWriter($preferredWriter);
            if ($writer !== null && $writer->isAvailable() && $writer->canHandle($content)) {
                return $writer->createPdfString($content, $options);
            }
        }

        // Writer nach Priorität durchprobieren
        foreach ($this->writers as $writer) {
            if (!$writer->isAvailable() || !$writer->canHandle($content)) {
                continue;
            }

            $result = $writer->createPdfString($content, $options);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Schnelle Methode: HTML zu PDF.
     */
    public function htmlToPdf(string $html, string $outputPath, array $options = []): bool {
        $content = PDFContent::fromHtml($html, $options['metadata'] ?? []);
        return $this->createPdf($content, $outputPath, $options);
    }

    /**
     * Schnelle Methode: Text zu PDF.
     */
    public function textToPdf(string $text, string $outputPath, array $options = []): bool {
        $content = PDFContent::fromText($text, $options['metadata'] ?? []);
        return $this->createPdf($content, $outputPath, $options);
    }

    /**
     * Schnelle Methode: HTML-Datei zu PDF.
     */
    public function fileToPdf(string $htmlFilePath, string $outputPath, array $options = []): bool {
        $content = PDFContent::fromFile($htmlFilePath, $options['metadata'] ?? []);
        return $this->createPdf($content, $outputPath, $options);
    }

    /**
     * Prüft ob mindestens ein Writer verfügbar ist.
     */
    public function hasAvailableWriter(): bool {
        return count($this->getAvailableWriters()) > 0;
    }

    /**
     * Gibt Informationen über alle Writer zurück.
     */
    public function getWriterInfo(): array {
        $info = [];

        foreach ($this->writers as $writer) {
            $info[] = [
                'name' => $writer::getName(),
                'priority' => $writer::getPriority(),
                'available' => $writer->isAvailable(),
                'supportsHtml' => $writer::supportsHtml(),
                'supportsText' => $writer::supportsText(),
            ];
        }

        return $info;
    }
}
