<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DompdfWriter.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Writers;

use CommonToolkit\Helper\FileSystem\File;
use Dompdf\{Dompdf, Options};
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Contracts\PDFWriterInterface;
use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;

/**
 * PDF-Writer basierend auf Dompdf.
 * 
 * Konvertiert HTML zu PDF mit reinem PHP.
 * Gute Unterstützung für CSS, ideal für einfache bis mittlere Layouts.
 */
final class DompdfWriter implements PDFWriterInterface {
    use ErrorLog;

    public static function getType(): PDFWriterType {
        return PDFWriterType::Dompdf;
    }

    public static function getPriority(): int {
        return PDFWriterType::Dompdf->getPriority();
    }

    public static function supportsHtml(): bool {
        return PDFWriterType::Dompdf->supportsHtml();
    }

    public static function supportsText(): bool {
        return PDFWriterType::Dompdf->supportsText();
    }

    public function isAvailable(): bool {
        return class_exists(Dompdf::class);
    }

    public function canHandle(PDFContent $content): bool {
        return $this->isAvailable();
    }

    public function createPdf(PDFContent $content, string $outputPath, array $options = []): bool {
        $pdfString = $this->createPdfString($content, $options);

        if ($pdfString === null) {
            return false;
        }

        try {
            File::write($outputPath, $pdfString);
        } catch (\Throwable $e) {
            $this->logError('Failed to write PDF file', ['path' => $outputPath, 'error' => $e->getMessage()]);
            return false;
        }

        $this->logDebug('PDF created successfully', [
            'path' => $outputPath,
            'size' => strlen($pdfString)
        ]);

        return true;
    }

    public function createPdfString(PDFContent $content, array $options = []): ?string {
        if (!$this->isAvailable()) {
            $this->logError('Dompdf is not available');
            return null;
        }

        try {
            $dompdf = $this->createDompdfInstance($options);

            $html = $content->getAsHtml();
            $dompdf->loadHtml($html, $options['encoding'] ?? 'UTF-8');

            $paperSize = $options['paper_size'] ?? 'A4';
            $orientation = $options['orientation'] ?? 'portrait';
            $dompdf->setPaper($paperSize, $orientation);

            $dompdf->render();

            // Metadaten setzen
            $this->setMetadata($dompdf, $content);

            return $dompdf->output();
        } catch (\Throwable $e) {
            $this->logError('Dompdf error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Erstellt eine konfigurierte Dompdf-Instanz.
     */
    private function createDompdfInstance(array $options): Dompdf {
        $dompdfOptions = new Options();

        // Standard-Optionen
        $dompdfOptions->set('isHtml5ParserEnabled', true);
        $dompdfOptions->set('isRemoteEnabled', $options['remote_enabled'] ?? false);
        $dompdfOptions->set('defaultFont', $options['default_font'] ?? 'DejaVu Sans');
        $dompdfOptions->set('isFontSubsettingEnabled', $options['font_subsetting'] ?? true);

        // Temporäres Verzeichnis
        if (isset($options['temp_dir'])) {
            $dompdfOptions->set('tempDir', $options['temp_dir']);
        }

        // Chroot für Sicherheit
        if (isset($options['chroot'])) {
            $dompdfOptions->set('chroot', $options['chroot']);
        }

        return new Dompdf($dompdfOptions);
    }

    /**
     * Setzt PDF-Metadaten.
     */
    private function setMetadata(Dompdf $dompdf, PDFContent $content): void {
        $canvas = $dompdf->getCanvas();

        // Dompdf 3.x: Metadaten direkt über Canvas setzen
        if (method_exists($canvas, 'add_info')) {
            if ($title = $content->getTitle()) {
                $canvas->add_info('Title', $title);
            }
            if ($author = $content->getAuthor()) {
                $canvas->add_info('Author', $author);
            }
            if ($subject = $content->getSubject()) {
                $canvas->add_info('Subject', $subject);
            }

            $canvas->add_info('Creator', 'PHP PDF Toolkit (Dompdf)');
        }
    }
}
