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

use Com\Tecnick\Pdf\Import\ImportException;
use Com\Tecnick\Pdf\Tcpdf as TcpdfEngine;
use CommonToolkit\Helper\FileSystem\File;
use Dompdf\{Dompdf, Options};
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Contracts\PDFWriterInterface;
use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Enums\PDFWriterType;
use PDFToolkit\Helper\{PDFAttachmentHelper, PDFOptimizeHelper, PDFXrefHelper, TempPath};
use RuntimeException;
use setasign\Fpdi\PdfParser\PdfParserException;
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

    /**
     * Kanonischer Dateiname der eingebetteten XML für ZUGFeRD 2.1/2.2 (= Factur-X 1.0).
     * Seit ZUGFeRD 2.1 ist dieser Name verbindlich und identisch zu Factur-X.
     */
    public const INVOICE_FILENAME_FACTURX = 'factur-x.xml';

    /**
     * Legacy-Dateiname aus ZUGFeRD 2.0. Nur für Rückwärtskompatibilität, nicht
     * mehr spec-konform für 2.1+. Über die Option 'invoice_filename' wählbar.
     */
    public const INVOICE_FILENAME_ZUGFERD_LEGACY = 'zugferd-invoice.xml';

    /** ZUGFeRD Conformance Levels */
    public const LEVEL_MINIMUM = 'MINIMUM';
    public const LEVEL_BASIC_WL = 'BASIC WL';
    public const LEVEL_BASIC = 'BASIC';
    public const LEVEL_EN16931 = 'EN 16931';
    public const LEVEL_EXTENDED = 'EXTENDED';

    /** Rendering-Engine */
    public const ENGINE_DOMPDF = 'dompdf';
    public const ENGINE_TCPDF = 'tcpdf';

    /** Zulässige AFRelationship-Werte nach PDF/A-3 (ISO 19005-3). */
    public const ATTACHMENT_RELATIONSHIPS = ['Source', 'Data', 'Alternative', 'Supplement', 'Unspecified'];

    /** Beziehung weiterer Dateien neben der Rechnung, etwa rechnungsbegründender Unterlagen. */
    public const DEFAULT_ATTACHMENT_RELATIONSHIP = 'Supplement';

    /** Name der Rechnungs-XML => Profil der XMP-Erweiterung (tc-lib-pdf HybridProfile); sonst Factur-X. */
    private const HYBRID_PROFILES = [
        self::INVOICE_FILENAME_ZUGFERD_LEGACY => 'zugferdv2',
        'ZUGFeRD-invoice.xml' => 'zugferdv1',
        'order-x.xml' => 'orderx',
    ];

    private const INVOICE_DESCRIPTION = 'Embedded electronic invoice (EN 16931)';

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
     * Prüft ob FPDI für den PDF-Import *benutzbar* ist.
     *
     * Vorhandensein der Klasse genügt nicht: FPDIs TCPDF-Adapter greift auf
     * TCPDF-6-Interna zu (u. a. `_out()`), die im TCPDF-7-Rewrite entfallen
     * sind — der Import bricht dann erst zur Laufzeit mit "Call to undefined
     * method setasign\Fpdi\Tcpdf\Fpdi::_out()" ab. Bis FPDI nachzieht, gilt
     * die Kombination als nicht verfügbar, damit createPdfString() sauber auf
     * den FPDI-freien TCPDF-Pfad ausweicht statt zu scheitern.
     *
     * @see https://github.com/Setasign/FPDI — Stand v2.6.8 ohne TCPDF-7-Support
     */
    public function isFpdiAvailable(): bool {
        if (!class_exists(Fpdi::class)) {
            return false;
        }

        return method_exists(Fpdi::class, '_out');
    }

    /**
     * Lässt sich ein vorhandenes PDF übernehmen ({@see embedIntoPdf()})?
     * Unter TCPDF 7 über den Import der Engine tc-lib-pdf, unter TCPDF 6 über FPDI.
     */
    public function isImportAvailable(): bool {
        return $this->isEngineImportAvailable() || $this->isFpdiAvailable();
    }

    private function isEngineImportAvailable(): bool {
        // PHPStan sieht nur die installierte tc-lib-pdf; ^7.0 erlaubt ältere
        // TCPDF-7-Stände, deren Engine noch keinen Seitenimport kennt.
        return class_exists(TcpdfEngine::class)
            /** @phpstan-ignore function.alreadyNarrowedType */
            && method_exists(TcpdfEngine::class, 'setImportSourceFile')
            && method_exists(TcpdfEngine::class, 'addPageFromImport');
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

        // Atomar schreiben: erst in eine temporäre Datei im Zielverzeichnis,
        // dann per rename() ersetzen. So bleibt eine bereits existierende
        // Ziel-PDF bei einem Schreibfehler unversehrt (kein truncate-in-place).
        $dir = \dirname($outputPath);
        $tempPath = @tempnam($dir, '.zugferd_');
        if ($tempPath === false) {
            $this->logError('Failed to create temporary file for ZUGFeRD PDF', ['dir' => $dir]);
            return false;
        }

        if (@file_put_contents($tempPath, $pdfString) === false) {
            @unlink($tempPath);
            $this->logError('Failed to write ZUGFeRD PDF file', ['path' => $outputPath]);
            return false;
        }

        if (!@rename($tempPath, $outputPath)) {
            @unlink($tempPath);
            $this->logError('Failed to move ZUGFeRD PDF into place', ['path' => $outputPath]);
            return false;
        }

        $this->logDebug('ZUGFeRD PDF created successfully', [
            'path' => $outputPath,
            'size' => strlen($pdfString),
            'profile' => $options['zugferd_profile'] ?? self::LEVEL_EN16931,
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
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Schreibt ein vorhandenes PDF mit neuer Rechnungs-XML als PDF/A-3 neu, etwa
     * um eine fremd erzeugte ZUGFeRD-Rechnung um Unterlagen zu ergänzen.
     *
     * Jede Seite wird unverändert als Formular-XObject in Originalgröße
     * übernommen. Was nicht zum Seiteninhalt gehört, entfällt: eingebettete
     * Dateien (auch die bisherige Rechnungs-XML), Lesezeichen, Links und andere
     * Annotationen, Formularfelder, Struktur-Tags, Info- und XMP-Metadaten,
     * Signaturen. Optionen wie bei createPdfString() (zugferd_profile,
     * invoice_filename, attachments), dazu title, author und subject.
     *
     * Scheitert der Import am Parser (etwa an kaputten Querverweisen), wird die
     * Datei einmal mit {@see PDFOptimizeHelper::clean()} neu geschrieben und
     * erneut importiert.
     *
     * @param array<string, mixed> $options
     * @return string|null PDF-Inhalt, null bei Fehler (Grund im Protokoll)
     */
    public function embedIntoPdf(string $sourcePdfPath, string $invoiceXml, array $options = []): ?string {
        if (!$this->isImportAvailable()) {
            $this->logError('Kein PDF-Import verfügbar (tc-lib-pdf aus TCPDF 7 oder FPDI mit TCPDF 6)');
            return null;
        }
        if (!File::exists($sourcePdfPath)) {
            $this->logError('Quell-PDF nicht gefunden', ['path' => $sourcePdfPath]);
            return null;
        }
        if (trim($invoiceXml) === '') {
            $this->logError('No invoice XML provided for ZUGFeRD PDF');
            return null;
        }

        try {
            return $this->importPages($sourcePdfPath, $invoiceXml, $options);
        } catch (\Throwable $e) {
            if (!$e instanceof ImportException && !$e instanceof PdfParserException) {
                $this->logError('ZUGFeRD PDF error: ' . $e->getMessage(), ['exception' => get_class($e), 'path' => $sourcePdfPath]);
                return null;
            }
            $this->logInfo('PDF-Import gescheitert, bereinige die Datei und versuche es erneut', ['path' => $sourcePdfPath, 'error' => $e->getMessage()]);
        }

        if (!PDFOptimizeHelper::isCleanAvailable()) {
            $this->logError('PDF lässt sich nicht importieren und mutool zum Bereinigen fehlt', ['path' => $sourcePdfPath]);
            return null;
        }

        $cleaned = TempPath::file('zugferd_clean', 'pdf');
        try {
            if (!PDFOptimizeHelper::clean($sourcePdfPath, $cleaned)) {
                return null;
            }

            return $this->importPages($cleaned, $invoiceXml, $options);
        } catch (\Throwable $e) {
            $this->logError('ZUGFeRD PDF error nach Bereinigung: ' . $e->getMessage(), ['exception' => get_class($e), 'path' => $sourcePdfPath]);
            return null;
        } finally {
            File::delete($cleaned);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function importPages(string $sourcePdf, string $invoiceXml, array $options): string {
        // Die Engine schreibt die Factur-X-Angaben ins XMP; FPDI bleibt für TCPDF 6.
        if ($this->isEngineImportAvailable()) {
            return $this->importWithEngine($sourcePdf, $invoiceXml, $options);
        }

        return $this->importAndEmbedXml($sourcePdf, null, $invoiceXml, $options, annotate: false);
    }

    /**
     * Erstellt ZUGFeRD PDF mit Dompdf für HTML-Rendering und FPDI/TCPDF für PDF/A-3.
     *
     * Workflow:
     * 1. Dompdf rendert HTML zu PDF (bessere CSS-Unterstützung)
     * 2. FPDI importiert das PDF
     * 3. TCPDF fügt XML-Attachment hinzu
     */
    private function createWithDompdf(PDFContent $content, string $invoiceXml, array $options): string {
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
            return $this->importAndEmbedXml($tempPdf, $content, $invoiceXml, $options, annotate: true);
        } finally {
            @unlink($tempPdf);
        }
    }

    /**
     * Importiert ein PDF mit FPDI und bettet XML ein.
     *
     * @param bool $annotate Büroklammer-Annotation auf der letzten Seite; nicht beim Übernehmen fremder Seiten
     */
    private function importAndEmbedXml(string $sourcePdf, ?PDFContent $content, string $invoiceXml, array $options, bool $annotate): string {
        // FPDI mit PDF/A-3 Modus erstellen (7. Parameter = 3 für PDF/A-3)
        $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false, 3);

        // Metadaten setzen
        $profile = $options['zugferd_profile'] ?? self::LEVEL_EN16931;

        [$title, $author, $subject] = $this->metadata($content, $options);
        if ($title !== null) {
            $pdf->SetTitle($title);
        }
        if ($author !== null) {
            $pdf->SetAuthor($author);
        }
        if ($subject !== null) {
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
        $this->embedInvoiceXml($pdf, $invoiceXml, $options, $annotate);

        return $pdf->Output('', 'S');
    }

    /**
     * Übernimmt die Seiten über den Import von tc-lib-pdf (Engine von TCPDF 7)
     * und bettet XML und Anhänge direkt in der Engine ein.
     *
     * @param array<string, mixed> $options
     */
    private function importWithEngine(string $sourcePdf, string $invoiceXml, array $options): string {
        // Die Engine liest selbst nur das ICC-Profil des PDF/A-Modus; die Quelle öffnet der Import ohne Pfadliste.
        $engineDir = dirname((string) (new \ReflectionClass(TcpdfEngine::class))->getFileName());
        $pdf = new TcpdfEngine('mm', true, false, true, 'pdfa3b', null, ['allowedPaths' => [$engineDir, sys_get_temp_dir()]]);

        [$title, $author, $subject] = $this->metadata(null, $options);
        if ($title !== null) {
            $pdf->setTitle($title);
        }
        if ($author !== null) {
            $pdf->setAuthor($author);
        }
        if ($subject !== null) {
            $pdf->setSubject($subject);
        }
        $pdf->setCreator('PHP PDF Toolkit - ZUGFeRD ' . self::ZUGFERD_VERSION);
        $pdf->setKeywords('ZUGFeRD, Factur-X, E-Rechnung, ' . (string) ($options['zugferd_profile'] ?? self::LEVEL_EN16931));

        $source = $pdf->setImportSourceFile($sourcePdf);
        $pageCount = $pdf->getSourcePageCount($source);
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $pdf->addPageFromImport($source, $pageNo);
        }

        $filename = $this->resolveInvoiceFilename($options);
        if (!$this->embedInvoiceInEngine($pdf, $filename, $invoiceXml, $options)) {
            throw new RuntimeException('Rechnungs-XML ließ sich nicht einbetten');
        }
        foreach ($this->normalizeAttachments($options['attachments'] ?? [], $filename) as $attachment) {
            if (!$this->embedFileInEngine($pdf, $attachment)) {
                throw new RuntimeException("Anhang {$attachment['name']} ließ sich nicht einbetten");
            }
        }

        return PDFXrefHelper::rebuild($pdf->getOutPDFString());
    }

    /**
     * Titel, Autor und Betreff: aus dem Inhalt, sonst aus den Optionen.
     *
     * @param array<string, mixed> $options
     * @return array{?string, ?string, ?string}
     */
    private function metadata(?PDFContent $content, array $options): array {
        $pick = static function (?string $fromContent, mixed $fromOptions): ?string {
            if ($fromContent !== null && $fromContent !== '') {
                return $fromContent;
            }

            return is_string($fromOptions) && $fromOptions !== '' ? $fromOptions : null;
        };

        return [
            $pick($content?->getTitle(), $options['title'] ?? null),
            $pick($content?->getAuthor(), $options['author'] ?? null),
            $pick($content?->getSubject(), $options['subject'] ?? null),
        ];
    }

    /**
     * Erstellt ZUGFeRD PDF nur mit TCPDF (weniger CSS-Unterstützung).
     */
    private function createWithTcpdf(PDFContent $content, string $invoiceXml, array $options): string {
        $pdf = $this->createZugferdPdfInstance($content, $options);

        // Inhalt hinzufügen
        $pdf->AddPage();

        if ($content->isText()) {
            $this->writeText($pdf, $content, $options);
        } else {
            $this->writeHtml($pdf, $content, $options);
        }

        // XML-Rechnung einbetten
        $this->embedInvoiceXml($pdf, $invoiceXml, $options, true);

        $output = $pdf->Output('', 'S');

        // TCPDF 7: die Engine verortet Objekte per Regex auch in roh eingebetteten Dateien.
        return $this->engineOf($pdf) !== null ? PDFXrefHelper::rebuild($output) : $output;
    }

    /**
     * Erstellt eine konfigurierte Dompdf-Instanz.
     */
    private function createDompdfInstance(array $options): Dompdf {
        $dompdfOptions = new Options;

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
     * Bettet die XML-Rechnung und die Option 'attachments' in das PDF ein.
     *
     * @param bool $annotate Büroklammer-Annotation auf der letzten Seite
     */
    private function embedInvoiceXml(TCPDF|Fpdi $pdf, string $xml, array $options, bool $annotate): void {
        $filename = $this->resolveInvoiceFilename($options);
        $engine = $this->engineOf($pdf);

        // TCPDF 7 wird bewusst über die Engine bedient statt über den
        // EmbedFileFromString()-Shim: dieser reicht die Argumente vertauscht an
        // addContentAsEmbeddedFile(string $file, string $content) weiter
        // (Stand 7.0.4), wodurch das komplette XML als *Dateiname* im Katalog
        // landet und Leser wie pdfdetach die Rechnung nicht mehr unter
        // 'factur-x.xml' finden.
        //
        // Der direkte Weg erlaubt zugleich die von ZUGFeRD/Factur-X geforderten
        // Metadaten: MIME text/xml und AFRelationship 'Alternative' (der Shim
        // setzt 'Source').
        if ($engine === null || !$this->embedInvoiceInEngine($engine, $filename, $xml, $options)) {
            $pdf->EmbedFileFromString($filename, $xml);
        }

        foreach ($this->normalizeAttachments($options['attachments'] ?? [], $filename) as $attachment) {
            if ($engine === null || !$this->embedFileInEngine($engine, $attachment)) {
                $pdf->EmbedFileFromString($attachment['name'], $attachment['content']);
            }
        }

        if (!$annotate) {
            return;
        }

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
                'Contents' => self::INVOICE_DESCRIPTION,
            ]
        );
    }

    /**
     * Bestimmt den Dateinamen der einzubettenden XML-Rechnung.
     *
     * Standard ist der spec-konforme Name 'factur-x.xml' (ZUGFeRD 2.1/2.2 = Factur-X 1.0).
     * Mit der Option 'invoice_filename' kann ein expliziter Name gesetzt werden
     * (z.B. der Legacy-Name 'zugferd-invoice.xml' für ZUGFeRD 2.0).
     */
    private function resolveInvoiceFilename(array $options): string {
        if (!empty($options['invoice_filename']) && is_string($options['invoice_filename'])) {
            return $options['invoice_filename'];
        }

        return self::INVOICE_FILENAME_FACTURX;
    }

    /**
     * Prüft die Option 'attachments'.
     *
     * Jeder Eintrag: name, content, optional mime, description, relationship
     * (Vorgabe 'Supplement'). Doppelte Namen — auch gegenüber der Rechnungs-XML,
     * ohne Rücksicht auf Groß-/Kleinschreibung — werden übersprungen, weil die
     * Engine einen zweiten Eintrag gleichen Namens still verwirft.
     *
     * @return list<array{name: string, content: string, mime: string, relationship: string, description: string}>
     */
    private function normalizeAttachments(mixed $attachments, string $invoiceFilename): array {
        if (!is_array($attachments) || $attachments === []) {
            return [];
        }

        $normalized = [];
        $taken = [mb_strtolower($invoiceFilename) => true];
        foreach ($attachments as $index => $attachment) {
            if (!is_array($attachment) || !is_string($attachment['name'] ?? null) || !is_string($attachment['content'] ?? null)) {
                $this->logWarning('ZUGFeRD-Anhang ohne Name oder Inhalt übersprungen', ['index' => $index]);
                continue;
            }

            $name = PDFAttachmentHelper::cleanName($attachment['name']);
            $content = $attachment['content'];
            if ($name === '' || $content === '') {
                $this->logWarning('ZUGFeRD-Anhang mit leerem Namen oder Inhalt übersprungen', ['index' => $index]);
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($taken[$key])) {
                $this->logWarning('ZUGFeRD-Anhang mit doppeltem Namen übersprungen', ['name' => $name]);
                continue;
            }
            $taken[$key] = true;

            $mime = is_string($attachment['mime'] ?? null) && preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $attachment['mime']) === 1
                ? $attachment['mime']
                : 'application/octet-stream';

            $relationship = is_string($attachment['relationship'] ?? null) ? $attachment['relationship'] : self::DEFAULT_ATTACHMENT_RELATIONSHIP;
            if (!in_array($relationship, self::ATTACHMENT_RELATIONSHIPS, true)) {
                $this->logWarning('Unbekannte AFRelationship, nutze Supplement', ['name' => $name, 'relationship' => $relationship]);
                $relationship = self::DEFAULT_ATTACHMENT_RELATIONSHIP;
            }

            $description = is_string($attachment['description'] ?? null)
                ? trim((string) preg_replace('/[\r\n\t]+/', ' ', $attachment['description']))
                : '';

            $normalized[] = ['name' => $name, 'content' => $content, 'mime' => $mime, 'relationship' => $relationship, 'description' => $description];
        }

        return $normalized;
    }

    /**
     * Die tc-lib-pdf-Engine unter TCPDF 7, null unter TCPDF 6.
     *
     * engine() ist protected, daher Reflection. Schlägt der Zugriff fehl
     * (TCPDF 6, umbenannte Methode), nutzt der Aufrufer EmbedFileFromString().
     */
    private function engineOf(TCPDF|Fpdi $pdf): ?object {
        // PHPStan analysiert gegen die gerade installierte TCPDF-Version und
        // hält den Aufruf dort für konstant. Das Paket unterstützt jedoch
        // ^6.6 || ^7.0, und engine() existiert erst ab 7 — die Prüfung bleibt.
        /** @phpstan-ignore function.alreadyNarrowedType */
        if (!method_exists($pdf, 'engine')) {
            return null;
        }

        try {
            // Kein setAccessible(): seit PHP 8.1 gewährt Reflection den Zugriff
            // ohnehin, und der Aufruf ist ab PHP 8.5 deprecated.
            // Klammern um new bleiben — die klammerlose Verkettung gibt es erst
            // ab PHP 8.4, das Paket unterstützt ab 8.1.
            $engine = (new \ReflectionMethod($pdf, 'engine'))->invoke($pdf);

            return is_object($engine) ? $engine : null;
        } catch (\Throwable $e) {
            $this->logDebug('Engine nicht erreichbar, nutze EmbedFileFromString: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Bettet die Rechnungs-XML in der Engine ein: MIME text/xml, AFRelationship
     * 'Alternative'. setFacturX() schreibt dazu die Factur-X/ZUGFeRD-Erweiterung
     * ins XMP (Dateiname, Version, Konformitätsstufe aus 'zugferd_profile');
     * eine Stufe, die die Engine nicht kennt, bettet die Datei ohne diese Angaben ein.
     *
     * @param array<string, mixed> $options
     */
    private function embedInvoiceInEngine(object $engine, string $filename, string $xml, array $options): bool {
        if (method_exists($engine, 'setFacturX')) {
            try {
                $engine->setFacturX(
                    $xml,
                    self::HYBRID_PROFILES[$filename] ?? 'facturx',
                    (string) ($options['zugferd_profile'] ?? self::LEVEL_EN16931),
                    '',
                    $filename,
                    '',
                    self::INVOICE_DESCRIPTION
                );

                return true;
            } catch (\Throwable $e) {
                $this->logWarning('Factur-X-Angaben im XMP nicht möglich, bette die Rechnung ohne sie ein: ' . $e->getMessage(), ['filename' => $filename]);
            }
        }

        return $this->embedFileInEngine($engine, [
            'name' => $filename,
            'content' => $xml,
            'mime' => 'text/xml',
            'relationship' => 'Alternative',
            'description' => self::INVOICE_DESCRIPTION,
        ]);
    }

    /**
     * @param array{name: string, content: string, mime: string, relationship: string, description: string} $file
     */
    private function embedFileInEngine(object $engine, array $file): bool {
        if (!method_exists($engine, 'addContentAsEmbeddedFile')) {
            return false;
        }

        try {
            $engine->addContentAsEmbeddedFile($file['name'], $file['content'], $file['mime'], $file['relationship'], $file['description']);

            return true;
        } catch (\Throwable $e) {
            $this->logDebug('Engine-Einbettung nicht möglich: ' . $e->getMessage(), ['name' => $file['name']]);
            return false;
        }
    }

    /**
     * Schreibt Text in das PDF.
     */
    private function writeText(TCPDF $pdf, PDFContent $content, array $options): void {
        $text = $content->getAsText();
        $pdf->MultiCell(0, 5, $text, 0, 'L', false, 1, null, null, true, 0, false, true, 0, 'T', false);
    }

    /**
     * Schreibt HTML in das PDF.
     */
    private function writeHtml(TCPDF $pdf, PDFContent $content, array $options): void {
        $html = $content->getAsHtml();
        $pdf->writeHTML($html, true, false, true, false, '');
    }
}
