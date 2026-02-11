<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFWriterRegistry.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Registries;

use CommonToolkit\Helper\FileSystem\File;
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use PDFToolkit\Contracts\PDFWriterInterface;
use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;
use PDFToolkit\Writers\{DompdfWriter, TcpdfWriter, WkhtmltopdfWriter};

/**
 * Registry für PDF-Writer.
 * 
 * Verwaltet alle verfügbaren Writer und wählt automatisch
 * den besten verfügbaren Writer für die Erstellung aus.
 * 
 * Diese Klasse verwendet das Singleton-Pattern, da die Writer-Liste
 * beim ersten Laden initialisiert wird und danach wiederverwendet werden kann.
 * 
 * @example
 * ```php
 * $registry = PDFWriterRegistry::getInstance();
 * $registry->htmlToPdf('<h1>Test</h1>', '/path/to/output.pdf');
 * ```
 */
final class PDFWriterRegistry {
    use ErrorLog;

    /** @var self|null Singleton-Instanz */
    private static ?self $instance = null;

    /** @var PDFWriterInterface[] */
    private array $writers = [];

    /** @var array<string, class-string<PDFWriterInterface>> */
    private static array $writerClasses = [
        DompdfWriter::class,
        TcpdfWriter::class,
        WkhtmltopdfWriter::class,
    ];

    /**
     * Private constructor - use getInstance() instead.
     */
    private function __construct() {
        $this->loadWriters();
    }

    /**
     * Gibt die Singleton-Instanz der Registry zurück.
     * 
     * @return self Die einzige Instanz der Registry
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
     * Lädt und sortiert alle verfügbaren Writer.
     */
    private function loadWriters(): void {
        $writers = [];

        foreach (self::$writerClasses as $writerClass) {
            try {
                $writer = new $writerClass();
                $writers[] = $writer;

                $this->logDebug('Writer loaded', [
                    'name' => $writerClass::getType()->value,
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
            'name' => $writer::getType()->value,
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
     * Gibt einen spezifischen Writer nach Typ zurück.
     */
    public function getByType(PDFWriterType $type): ?PDFWriterInterface {
        foreach ($this->writers as $writer) {
            if ($writer::getType() === $type) {
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
     * @param PDFWriterType|null $preferredWriter Bevorzugter Writer-Typ
     * @return bool true wenn erfolgreich
     */
    public function createPdf(PDFContent $content, string $outputPath, array $options = [], ?PDFWriterType $preferredWriter = null): bool {
        // Bevorzugter Writer
        if ($preferredWriter !== null) {
            $writer = $this->getByType($preferredWriter);
            if ($writer !== null && $writer->isAvailable() && $writer->canHandle($content)) {
                $this->logDebug('Using preferred writer', ['writer' => $preferredWriter->value]);
                return $writer->createPdf($content, $outputPath, $options);
            }
            $this->logDebug('Preferred writer not available, trying others', ['preferred' => $preferredWriter->value]);
        }

        // Writer nach Priorität durchprobieren
        foreach ($this->writers as $writer) {
            if (!$writer->isAvailable()) {
                $this->logDebug('Writer not available', ['writer' => $writer::getType()->value]);
                continue;
            }

            if (!$writer->canHandle($content)) {
                $this->logDebug('Writer cannot handle content', ['writer' => $writer::getType()->value]);
                continue;
            }

            $this->logDebug('Trying writer', ['writer' => $writer::getType()->value]);

            if ($writer->createPdf($content, $outputPath, $options)) {
                $this->logDebug('PDF created successfully', [
                    'writer' => $writer::getType()->value,
                    'path' => $outputPath
                ]);
                return true;
            }

            $this->logDebug('Writer failed, trying next', ['writer' => $writer::getType()->value]);
        }

        $this->logError('No writer could create the PDF', [
            'availableWriters' => array_map(fn($w) => $w::getType()->value, $this->getAvailableWriters())
        ]);

        return false;
    }

    /**
     * Erstellt eine PDF und gibt den Inhalt als String zurück.
     * 
     * @param PDFContent $content Der zu konvertierende Inhalt
     * @param array $options Optionale Konfiguration
     * @param PDFWriterType|null $preferredWriter Bevorzugter Writer-Typ
     * @return string|null PDF-Inhalt als String oder null bei Fehler
     */
    public function createPdfString(
        PDFContent $content,
        array $options = [],
        ?PDFWriterType $preferredWriter = null
    ): ?string {
        // Bevorzugter Writer
        if ($preferredWriter !== null) {
            $writer = $this->getByType($preferredWriter);
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
     * 
     * @param string $htmlFilePath Absoluter Pfad zur HTML-Eingabedatei
     * @param string $outputPath Absoluter Pfad für die PDF-Ausgabedatei
     * @param array $options Optionale Konfiguration:
     *        - metadata: Array mit PDF-Metadaten (title, author, subject)
     * @return bool true wenn erfolgreich
     * @throws InvalidArgumentException Wenn die HTML-Datei nicht existiert
     */
    public function fileToPdf(string $htmlFilePath, string $outputPath, array $options = []): bool {
        if (!File::exists($htmlFilePath)) {
            throw new InvalidArgumentException("HTML-Datei nicht gefunden: {$htmlFilePath}");
        }
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
                'type' => $writer::getType(),
                'name' => $writer::getType()->value,
                'priority' => $writer::getPriority(),
                'available' => $writer->isAvailable(),
                'supportsHtml' => $writer::supportsHtml(),
                'supportsText' => $writer::supportsText(),
            ];
        }

        return $info;
    }

    /**
     * Gibt die Typen aller verfügbaren Writer zurück.
     * 
     * @return PDFWriterType[]
     */
    public function getAvailableWriterTypes(): array {
        return array_map(fn($w) => $w::getType(), $this->getAvailableWriters());
    }
}