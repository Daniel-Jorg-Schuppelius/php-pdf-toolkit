<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdWriter.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Writers;

use Dompdf\Dompdf;
use Dompdf\Options;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Contracts\PDFWriterInterface;
use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;
use setasign\Fpdi\Tcpdf\Fpdi;
use TCPDF;

/**
 * PDF/A-3 Writer für ZUGFeRD/Factur-X E-Rechnungen.
 * 
 * Erstellt konforme PDF/A-3 Dateien mit eingebetteter XML-Rechnung.
 * Nutzt Dompdf für bessere HTML/CSS-Unterstützung bei der visuellen Darstellung,
 * und TCPDF für PDF/A-3-Konformität und XML-Einbettung.
 * 
 * Unterstützte Formate:
 * - ZUGFeRD 2.1/2.2 (DE)
 * - Factur-X 1.0 (FR/EU)
 * 
 * @see https://www.ferd-net.de/zugferd
 * @see https://fnfe-mpe.org/factur-x/
 */
final class ZugferdWriter implements PDFWriterInterface {
    use ErrorLog;

    public const ZUGFERD_VERSION = '2.2';
    public const FACTURX_VERSION = '1.0';

    /** ZUGFeRD Conformance Levels */
    public const LEVEL_MINIMUM = 'MINIMUM';
    public const LEVEL_BASIC_WL = 'BASIC WL';
    public const LEVEL_BASIC = 'BASIC';
    public const LEVEL_EN16931 = 'EN 16931';
    public const LEVEL_EXTENDED = 'EXTENDED';

    /** Rendering-Engine */
    public const ENGINE_DOMPDF = 'dompdf';
    public const ENGINE_TCPDF = 'tcpdf';

    public static function getType(): PDFWriterType {
        return PDFWriterType::Zugferd;
    }

    public static function getPriority(): int {
        return PDFWriterType::Zugferd->getPriority();
    }

    public static function supportsHtml(): bool {
        return PDFWriterType::Zugferd->supportsHtml();
    }

    public static function supportsText(): bool {
        return PDFWriterType::Zugferd->supportsText();
    }

    public function isAvailable(): bool {
        return class_exists(TCPDF::class);
    }

    /**
     * Prüft ob Dompdf verfügbar ist.
     */
    public function isDompdfAvailable(): bool {
        return class_exists(Dompdf::class);
    }

    /**
     * Prüft ob FPDI verfügbar ist (für PDF-Import).
     */
    public function isFpdiAvailable(): bool {
        return class_exists(Fpdi::class);
    }

    public function canHandle(PDFContent $content): bool {
        // Nur wenn XML-Daten für E-Rechnung vorhanden sind
        return $this->isAvailable() && $content->getMeta('invoice_xml') !== null;
    }

    public function createPdf(PDFContent $content, string $outputPath, array $options = []): bool {
        $pdfString = $this->createPdfString($content, $options);

        if ($pdfString === null) {
            return false;
        }

        $result = file_put_contents($outputPath, $pdfString);

        if ($result === false) {
            $this->logError('Failed to write ZUGFeRD PDF file', ['path' => $outputPath]);
            return false;
        }

        $this->logDebug('ZUGFeRD PDF created successfully', [
            'path' => $outputPath,
            'size' => strlen($pdfString),
            'profile' => $options['zugferd_profile'] ?? self::LEVEL_EN16931
        ]);

        return true;
    }

    public function createPdfString(PDFContent $content, array $options = []): ?string {
        if (!$this->isAvailable()) {
            $this->logError('TCPDF is not available for ZUGFeRD');
            return null;
        }

        $invoiceXml = $content->getMeta('invoice_xml');
        if ($invoiceXml === null) {
            $this->logError('No invoice XML provided for ZUGFeRD PDF');
            return null;
        }

        // Wähle Rendering-Engine
        $engine = $options['render_engine'] ?? self::ENGINE_DOMPDF;

        // Falls Dompdf gewünscht, prüfe ob auch FPDI verfügbar ist
        if ($engine === self::ENGINE_DOMPDF) {
            if (!$this->isDompdfAvailable()) {
                $this->logDebug('Dompdf not available, falling back to TCPDF');
                $engine = self::ENGINE_TCPDF;
            } elseif (!$this->isFpdiAvailable()) {
                $this->logDebug('FPDI not available for PDF import, falling back to TCPDF');
                $engine = self::ENGINE_TCPDF;
            }
        }

        try {
            if ($engine === self::ENGINE_DOMPDF) {
                return $this->createWithDompdf($content, $invoiceXml, $options);
            } else {
                return $this->createWithTcpdf($content, $invoiceXml, $options);
            }
        } catch (\Throwable $e) {
            $this->logError('ZUGFeRD PDF error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Erstellt ZUGFeRD PDF mit Dompdf für HTML-Rendering und FPDI/TCPDF für PDF/A-3.
     * 
     * Workflow:
     * 1. Dompdf rendert HTML zu PDF (bessere CSS-Unterstützung)
     * 2. FPDI importiert das PDF
     * 3. TCPDF fügt XML-Attachment hinzu
     */
    private function createWithDompdf(PDFContent $content, string $invoiceXml, array $options): ?string {
        // Schritt 1: HTML mit Dompdf zu PDF rendern
        $dompdf = $this->createDompdfInstance($options);

        $html = $content->getAsHtml();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($options['paper_size'] ?? 'A4', $options['orientation'] ?? 'portrait');
        $dompdf->render();

        // PDF als temporäre Datei speichern
        $tempPdf = tempnam(sys_get_temp_dir(), 'zugferd_dompdf_');
        file_put_contents($tempPdf, $dompdf->output());

        try {
            // Schritt 2: Mit FPDI das PDF importieren und mit TCPDF XML einbetten
            return $this->importAndEmbedXml($tempPdf, $content, $invoiceXml, $options);
        } finally {
            @unlink($tempPdf);
        }
    }

    /**
     * Importiert ein PDF mit FPDI und bettet XML ein.
     */
    private function importAndEmbedXml(string $sourcePdf, PDFContent $content, string $invoiceXml, array $options): ?string {
        // FPDI mit PDF/A-3 Modus erstellen (7. Parameter = 3 für PDF/A-3)
        $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false, 3);

        // Metadaten setzen
        $profile = $options['zugferd_profile'] ?? self::LEVEL_EN16931;

        if ($title = $content->getTitle()) {
            $pdf->SetTitle($title);
        }
        if ($author = $content->getAuthor()) {
            $pdf->SetAuthor($author);
        }
        if ($subject = $content->getSubject()) {
            $pdf->SetSubject($subject);
        }

        $pdf->SetCreator('PHP PDF Toolkit - ZUGFeRD ' . self::ZUGFERD_VERSION);
        $pdf->SetKeywords('ZUGFeRD, Factur-X, E-Rechnung, ' . $profile);

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Quell-PDF importieren
        $pageCount = $pdf->setSourceFile($sourcePdf);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);
        }

        // XML-Rechnung einbetten
        $this->embedInvoiceXml($pdf, $invoiceXml, $options);

        return $pdf->Output('', 'S');
    }

    /**
     * Erstellt ZUGFeRD PDF nur mit TCPDF (weniger CSS-Unterstützung).
     */
    private function createWithTcpdf(PDFContent $content, string $invoiceXml, array $options): ?string {
        $pdf = $this->createZugferdPdfInstance($content, $options);

        // Inhalt hinzufügen
        $pdf->AddPage();

        if ($content->isText()) {
            $this->writeText($pdf, $content, $options);
        } else {
            $this->writeHtml($pdf, $content, $options);
        }

        // XML-Rechnung einbetten
        $this->embedInvoiceXml($pdf, $invoiceXml, $options);

        return $pdf->Output('', 'S');
    }

    /**
     * Erstellt eine konfigurierte Dompdf-Instanz.
     */
    private function createDompdfInstance(array $options): Dompdf {
        $dompdfOptions = new Options();

        $dompdfOptions->set('isHtml5ParserEnabled', true);
        $dompdfOptions->set('isRemoteEnabled', $options['remote_enabled'] ?? false);
        $dompdfOptions->set('defaultFont', $options['default_font'] ?? 'DejaVu Sans');
        $dompdfOptions->set('isFontSubsettingEnabled', $options['font_subsetting'] ?? true);

        if (isset($options['temp_dir'])) {
            $dompdfOptions->set('tempDir', $options['temp_dir']);
        }

        if (isset($options['chroot'])) {
            $dompdfOptions->set('chroot', $options['chroot']);
        }

        return new Dompdf($dompdfOptions);
    }

    /**
     * Erstellt ein PDF/A-3 konformes TCPDF für ZUGFeRD.
     */
    private function createZugferdPdfInstance(PDFContent $content, array $options): TCPDF {
        $orientation = $options['orientation'] ?? 'P';
        $unit = $options['unit'] ?? 'mm';
        $format = $options['paper_size'] ?? 'A4';

        // PDF/A-3 Mode über Konstruktor aktivieren (7. Parameter = 3 für PDF/A-3)
        $pdf = new TCPDF($orientation, $unit, $format, true, 'UTF-8', false, 3);

        // Metadaten setzen
        $profile = $options['zugferd_profile'] ?? self::LEVEL_EN16931;

        if ($title = $content->getTitle()) {
            $pdf->SetTitle($title);
        }
        if ($author = $content->getAuthor()) {
            $pdf->SetAuthor($author);
        }
        if ($subject = $content->getSubject()) {
            $pdf->SetSubject($subject);
        }

        $pdf->SetCreator('PHP PDF Toolkit - ZUGFeRD ' . self::ZUGFERD_VERSION);
        $pdf->SetKeywords('ZUGFeRD, Factur-X, E-Rechnung, ' . $profile);

        // Ränder setzen
        $margins = $options['margins'] ?? ['left' => 15, 'top' => 15, 'right' => 15, 'bottom' => 15];
        $pdf->SetMargins($margins['left'], $margins['top'], $margins['right']);
        $pdf->SetAutoPageBreak(true, $margins['bottom']);

        // Header und Footer
        if (!($options['header'] ?? false)) {
            $pdf->setPrintHeader(false);
        }
        if (!($options['footer'] ?? false)) {
            $pdf->setPrintFooter(false);
        }

        // Schriftart (muss eingebettet sein für PDF/A)
        $fontFamily = $options['font_family'] ?? 'dejavusans';
        $fontSize = $options['font_size'] ?? 10;
        $pdf->SetFont($fontFamily, '', $fontSize);

        return $pdf;
    }

    /**
     * Bettet die XML-Rechnung in das PDF ein.
     */
    private function embedInvoiceXml(TCPDF|Fpdi $pdf, string $xml, array $options): void {
        $isFacturX = $options['facturx'] ?? false;
        $filename = $isFacturX ? 'factur-x.xml' : 'zugferd-invoice.xml';

        // XML als eingebettete Datei hinzufügen (PDF/A-3 kompatibel)
        $pdf->EmbedFileFromString($filename, $xml);

        // Annotation für die eingebettete Datei (optional, für bessere Sichtbarkeit)
        $pdf->Annotation(
            10,
            10,
            15,
            15,
            'ZUGFeRD/Factur-X XML Invoice',
            [
                'Subtype' => 'FileAttachment',
                'Name' => 'Paperclip',
                'FS' => $filename,
                'Contents' => 'Embedded electronic invoice (EN 16931)'
            ]
        );
    }

    /**
     * Schreibt Text in das PDF.
     */
    private function writeText(TCPDF $pdf, PDFContent $content, array $options): void {
        $text = $content->getAsText();
        $pdf->MultiCell(0, 5, $text, 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T', false);
    }

    /**
     * Schreibt HTML in das PDF.
     */
    private function writeHtml(TCPDF $pdf, PDFContent $content, array $options): void {
        $html = $content->getAsHtml();
        $pdf->writeHTML($html, true, false, true, false, '');
    }
}
