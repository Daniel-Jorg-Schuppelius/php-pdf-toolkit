<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : WkhtmltopdfWriter.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Writers;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Contracts\PDFWriterInterface;
use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Config\Config;
use PDFToolkit\Enums\PDFWriterType;

/**
 * PDF-Writer basierend auf wkhtmltopdf.
 * 
 * Externes Tool für hochqualitative HTML-zu-PDF-Konvertierung.
 * Verwendet WebKit-Rendering, beste CSS/JavaScript-Unterstützung.
 * 
 * Die Pfadauflösung wird vollständig durch das ConfigToolkit erledigt,
 * das automatisch im System-PATH sucht und den Pfad validiert.
 */
final class WkhtmltopdfWriter implements PDFWriterInterface {
    use ErrorLog;

    private ?array $executableConfig = null;

    public function __construct() {
        $this->executableConfig = Config::getInstance()->getConfig('shellExecutables', 'wkhtmltopdf');
    }

    public static function getType(): PDFWriterType {
        return PDFWriterType::Wkhtmltopdf;
    }

    public static function getPriority(): int {
        return PDFWriterType::Wkhtmltopdf->getPriority();
    }

    public static function supportsHtml(): bool {
        return PDFWriterType::Wkhtmltopdf->supportsHtml();
    }

    public static function supportsText(): bool {
        return PDFWriterType::Wkhtmltopdf->supportsText();
    }

    public function isAvailable(): bool {
        return $this->getExecutablePath() !== null;
    }

    public function canHandle(PDFContent $content): bool {
        return $this->isAvailable();
    }

    public function createPdf(PDFContent $content, string $outputPath, array $options = []): bool {
        if (!$this->isAvailable()) {
            $this->logError('wkhtmltopdf is not available');
            return false;
        }

        try {
            $html = $content->getAsHtml();

            // Temporäre HTML-Datei erstellen
            $tempHtml = tempnam(sys_get_temp_dir(), 'pdf_') . '.html';
            File::write($tempHtml, $html);

            $command = $this->buildCommand($tempHtml, $outputPath, $content, $options);

            $this->logDebug('Executing wkhtmltopdf', ['command' => $command]);

            $output = [];
            $resultCode = 0;
            $success = Shell::executeShellCommand($command, $output, $resultCode);

            // Temporäre Datei löschen
            File::delete($tempHtml);

            if (!$success) {
                $this->logError('wkhtmltopdf failed', [
                    'returnCode' => $resultCode,
                    'output' => implode("\n", $output)
                ]);
                return false;
            }

            $this->logDebug('PDF created successfully', ['path' => $outputPath]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('wkhtmltopdf error: ' . $e->getMessage(), [
                'exception' => get_class($e)
            ]);
            return false;
        }
    }

    public function createPdfString(PDFContent $content, array $options = []): ?string {
        // Temporäre Ausgabedatei
        $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';

        if (!$this->createPdf($content, $tempOutput, $options)) {
            @unlink($tempOutput);
            return null;
        }

        $pdfContent = File::read($tempOutput);
        File::delete($tempOutput);

        return $pdfContent;
    }

    /**
     * Baut den wkhtmltopdf-Befehl zusammen.
     */
    private function buildCommand(string $inputPath, string $outputPath, PDFContent $content, array $options): string {
        $executable = escapeshellarg($this->getExecutablePath());
        $args = [];

        // Seitengröße
        $args[] = '--page-size ' . escapeshellarg($options['paper_size'] ?? 'A4');

        // Ausrichtung
        $orientation = $options['orientation'] ?? 'portrait';
        if ($orientation === 'landscape' || $orientation === 'L') {
            $args[] = '--orientation Landscape';
        }

        // Ränder
        $margins = $options['margins'] ?? [];
        if (isset($margins['top'])) {
            $args[] = '--margin-top ' . escapeshellarg((string)$margins['top']);
        }
        if (isset($margins['bottom'])) {
            $args[] = '--margin-bottom ' . escapeshellarg((string)$margins['bottom']);
        }
        if (isset($margins['left'])) {
            $args[] = '--margin-left ' . escapeshellarg((string)$margins['left']);
        }
        if (isset($margins['right'])) {
            $args[] = '--margin-right ' . escapeshellarg((string)$margins['right']);
        }

        // Metadaten
        if ($title = $content->getTitle()) {
            $args[] = '--title ' . escapeshellarg($title);
        }

        // Encoding
        $args[] = '--encoding UTF-8';

        // Zusätzliche Optionen
        if ($options['grayscale'] ?? false) {
            $args[] = '--grayscale';
        }
        if ($options['lowquality'] ?? false) {
            $args[] = '--lowquality';
        }
        if (isset($options['dpi'])) {
            $args[] = '--dpi ' . (int)$options['dpi'];
        }
        if (isset($options['zoom'])) {
            $args[] = '--zoom ' . (float)$options['zoom'];
        }

        // JavaScript deaktivieren (optional)
        if ($options['disable_javascript'] ?? false) {
            $args[] = '--disable-javascript';
        }

        // Quiet-Modus
        $args[] = '--quiet';

        return sprintf(
            '%s %s %s %s',
            $executable,
            implode(' ', $args),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );
    }

    /**
     * Gibt den Pfad zum wkhtmltopdf-Executable zurück.
     * 
     * Der Pfad wird vom ConfigToolkit automatisch aufgelöst.
     * Wenn das Executable nicht gefunden wurde, ist path = null.
     */
    private function getExecutablePath(): ?string {
        return $this->executableConfig['path'] ?? null;
    }
}
