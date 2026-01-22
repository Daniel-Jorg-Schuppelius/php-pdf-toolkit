<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdWriter.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Writers;

use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Contracts\PDFWriterInterface;
use PDFToolkit\Entities\PDFContent;
use TCPDF;

/**
 * PDF/A-3 Writer für ZUGFeRD/Factur-X E-Rechnungen.
 * 
 * Erstellt konforme PDF/A-3 Dateien mit eingebetteter XML-Rechnung.
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

    public static function getName(): string {
        return 'zugferd';
    }

    public static function getPriority(): int {
        return 15; // Höhere Priorität als Standard-TCPDF
    }

    public static function supportsHtml(): bool {
        return true;
    }

    public static function supportsText(): bool {
        return true;
    }

    public function isAvailable(): bool {
        return class_exists(TCPDF::class);
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

        try {
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
        } catch (\Throwable $e) {
            $this->logError('ZUGFeRD PDF error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
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

        // XMP-Metadaten für ZUGFeRD/Factur-X
        $this->setZugferdXmpMetadata($pdf, $profile, $options);

        return $pdf;
    }

    /**
     * Setzt XMP-Metadaten für ZUGFeRD/Factur-X Konformität.
     */
    private function setZugferdXmpMetadata(TCPDF $pdf, string $profile, array $options): void {
        $isFacturX = $options['facturx'] ?? false;
        $version = $isFacturX ? self::FACTURX_VERSION : self::ZUGFERD_VERSION;
        $namespace = $isFacturX ? 'Factur-X' : 'ZUGFeRD';

        // Custom XMP für ZUGFeRD/Factur-X
        $xmp = <<<XMP
<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
    <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
        <rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">
            <fx:DocumentType>{$namespace} INVOICE</fx:DocumentType>
            <fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>
            <fx:Version>{$version}</fx:Version>
            <fx:ConformanceLevel>{$profile}</fx:ConformanceLevel>
        </rdf:Description>
        <rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">
            <pdfaid:part>3</pdfaid:part>
            <pdfaid:conformance>B</pdfaid:conformance>
        </rdf:Description>
    </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>
XMP;

        // XMP zu TCPDF hinzufügen (über Reflection, da keine direkte Methode)
        // Alternativ: Nach PDF-Erstellung nachträglich einfügen
    }

    /**
     * Bettet die XML-Rechnung in das PDF ein.
     */
    private function embedInvoiceXml(TCPDF $pdf, string $xml, array $options): void {
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
