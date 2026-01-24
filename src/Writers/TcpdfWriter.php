<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TcpdfWriter.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Writers;

use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Contracts\PDFWriterInterface;
use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;
use TCPDF;

/**
 * PDF-Writer basierend auf TCPDF.
 * 
 * Programmatische PDF-Erstellung mit reinem PHP.
 * Sehr flexibel, unterstützt komplexe Layouts und Formulare.
 */
final class TcpdfWriter implements PDFWriterInterface {
    use ErrorLog;

    public static function getType(): PDFWriterType {
        return PDFWriterType::Tcpdf;
    }

    public static function getPriority(): int {
        return PDFWriterType::Tcpdf->getPriority();
    }

    public static function supportsHtml(): bool {
        return PDFWriterType::Tcpdf->supportsHtml();
    }

    public static function supportsText(): bool {
        return PDFWriterType::Tcpdf->supportsText();
    }

    public function isAvailable(): bool {
        return class_exists(TCPDF::class);
    }

    public function canHandle(PDFContent $content): bool {
        return $this->isAvailable();
    }

    public function createPdf(PDFContent $content, string $outputPath, array $options = []): bool {
        $pdfString = $this->createPdfString($content, $options);

        if ($pdfString === null) {
            return false;
        }

        $result = file_put_contents($outputPath, $pdfString);

        if ($result === false) {
            $this->logError('Failed to write PDF file', ['path' => $outputPath]);
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
            $this->logError('TCPDF is not available');
            return null;
        }

        try {
            $pdf = $this->createTcpdfInstance($content, $options);

            $pdf->AddPage();

            if ($content->isText()) {
                $this->writeText($pdf, $content, $options);
            } else {
                $this->writeHtml($pdf, $content, $options);
            }

            return $pdf->Output('', 'S');
        } catch (\Throwable $e) {
            $this->logError('TCPDF error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Erstellt eine konfigurierte TCPDF-Instanz.
     */
    private function createTcpdfInstance(PDFContent $content, array $options): TCPDF {
        $orientation = $options['orientation'] ?? 'P';
        $unit = $options['unit'] ?? 'mm';
        $format = $options['paper_size'] ?? 'A4';

        $pdf = new TCPDF($orientation, $unit, $format, true, 'UTF-8', false);

        // Metadaten setzen
        if ($title = $content->getTitle()) {
            $pdf->SetTitle($title);
        }
        if ($author = $content->getAuthor()) {
            $pdf->SetAuthor($author);
        }
        if ($subject = $content->getSubject()) {
            $pdf->SetSubject($subject);
        }

        $pdf->SetCreator('PHP PDF Toolkit (TCPDF)');

        // Ränder setzen
        $margins = $options['margins'] ?? ['left' => 15, 'top' => 15, 'right' => 15];
        $pdf->SetMargins($margins['left'], $margins['top'], $margins['right']);
        $pdf->SetAutoPageBreak(true, $margins['bottom'] ?? 15);

        // Header und Footer deaktivieren (kann über Optionen aktiviert werden)
        if (!($options['header'] ?? false)) {
            $pdf->setPrintHeader(false);
        }
        if (!($options['footer'] ?? false)) {
            $pdf->setPrintFooter(false);
        }

        // Schriftart
        $fontFamily = $options['font_family'] ?? 'dejavusans';
        $fontSize = $options['font_size'] ?? 12;
        $pdf->SetFont($fontFamily, '', $fontSize);

        return $pdf;
    }

    /**
     * Schreibt Text in das PDF.
     */
    private function writeText(TCPDF $pdf, PDFContent $content, array $options): void {
        $text = $content->getAsText();
        $pdf->MultiCell(0, 0, $text, 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T', false);
    }

    /**
     * Schreibt HTML in das PDF.
     */
    private function writeHtml(TCPDF $pdf, PDFContent $content, array $options): void {
        $html = $content->getAsHtml();

        // Extrahiere nur den Body-Inhalt, falls vorhanden
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $html = $matches[1];
        }

        $pdf->writeHTML($html, true, false, true, false, '');
    }
}
