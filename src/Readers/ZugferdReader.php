<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdReader.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Readers;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * Reader für ZUGFeRD/Factur-X PDFs.
 * 
 * Extrahiert die eingebettete XML-Rechnung aus PDF/A-3 Dokumenten.
 * 
 * HINWEIS: Diese Klasse implementiert NICHT das PDFReaderInterface,
 * da sie für einen anderen Zweck konzipiert ist:
 * - PDFReaderInterface: Extrahiert lesbaren TEXT aus PDFs
 * - ZugferdReader: Extrahiert strukturierte XML-DATEN (E-Rechnungen)
 * 
 * Unterstützte Formate:
 * - ZUGFeRD 1.0/2.0/2.1/2.2 (DE)
 * - Factur-X 1.0 (FR/EU)
 * 
 * Sucht nach folgenden eingebetteten Dateien:
 * - factur-x.xml (Factur-X Standard)
 * - zugferd-invoice.xml (ZUGFeRD 2.x)
 * - ZUGFeRD-invoice.xml (ZUGFeRD 1.0)
 * 
 * @see https://www.ferd-net.de/zugferd
 * @see https://fnfe-mpe.org/factur-x/
 */
final class ZugferdReader {
    use ErrorLog;

    /** Bekannte ZUGFeRD/Factur-X Dateinamen */
    private const INVOICE_FILENAMES = [
        'factur-x.xml',
        'zugferd-invoice.xml',
        'ZUGFeRD-invoice.xml',
        'xrechnung.xml',
    ];

    private Config $config;
    private ?bool $available = null;

    public function __construct() {
        $this->config = Config::getInstance();
    }

    /**
     * Prüft ob das Tool zum Extrahieren von PDF-Attachments verfügbar ist.
     */
    public function isAvailable(): bool {
        if ($this->available !== null) {
            return $this->available;
        }

        // pdftk oder pdfdetach (poppler) benötigt - prüfe die spezifischen Befehle
        $this->available = $this->config->buildCommand('pdfdetach-list', ['[PDF-FILE]' => '/test']) !== null
            || $this->config->buildCommand('pdftk-dump', ['[PDF-FILE]' => '/test']) !== null;

        return $this->available;
    }

    /**
     * Prüft ob die PDF-Datei eine ZUGFeRD/Factur-X Rechnung enthält.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @return bool True wenn eine eingebettete Rechnung gefunden wurde
     */
    public function isZugferdPdf(string $pdfPath): bool {
        $attachments = $this->listAttachments($pdfPath);

        foreach ($attachments as $filename) {
            if ($this->isInvoiceFile($filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrahiert die XML-Rechnung aus einer ZUGFeRD/Factur-X PDF.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @return string|null XML-Inhalt oder null wenn keine Rechnung gefunden
     */
    public function extractInvoiceXml(string $pdfPath): ?string {
        if (!File::exists($pdfPath)) {
            $this->logError('PDF file not found', ['path' => $pdfPath]);
            return null;
        }

        $attachments = $this->listAttachments($pdfPath);

        foreach (self::INVOICE_FILENAMES as $invoiceFilename) {
            if (in_array($invoiceFilename, $attachments, true)) {
                $xml = $this->extractAttachment($pdfPath, $invoiceFilename);
                if ($xml !== null) {
                    $this->logDebug('Extracted ZUGFeRD XML', [
                        'pdf' => $pdfPath,
                        'filename' => $invoiceFilename,
                        'size' => strlen($xml)
                    ]);
                    return $xml;
                }
            }
        }

        // Fallback: Suche nach beliebiger XML-Datei
        foreach ($attachments as $filename) {
            if (str_ends_with(strtolower($filename), '.xml')) {
                $xml = $this->extractAttachment($pdfPath, $filename);
                if ($xml !== null && $this->looksLikeInvoiceXml($xml)) {
                    $this->logDebug('Extracted XML attachment as invoice', [
                        'pdf' => $pdfPath,
                        'filename' => $filename
                    ]);
                    return $xml;
                }
            }
        }

        $this->logDebug('No ZUGFeRD/Factur-X XML found in PDF', ['path' => $pdfPath]);
        return null;
    }

    /**
     * Listet alle eingebetteten Dateien (Attachments) in der PDF auf.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @return string[] Liste der Dateinamen
     */
    public function listAttachments(string $pdfPath): array {
        if (!$this->isAvailable()) {
            $this->logError('No PDF attachment tool available (pdfdetach or pdftk required)');
            return [];
        }

        // Versuche pdfdetach (poppler)
        if ($this->config->buildCommand('pdfdetach-list', ['[PDF-FILE]' => $pdfPath]) !== null) {
            return $this->listAttachmentsWithPdfdetach($pdfPath);
        }

        // Fallback: pdftk
        if ($this->config->buildCommand('pdftk-dump', ['[PDF-FILE]' => $pdfPath]) !== null) {
            return $this->listAttachmentsWithPdftk($pdfPath);
        }

        return [];
    }

    /**
     * Extrahiert eine spezifische eingebettete Datei aus der PDF.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @param string $filename Name der eingebetteten Datei
     * @return string|null Dateiinhalt oder null bei Fehler
     */
    public function extractAttachment(string $pdfPath, string $filename): ?string {
        if (!$this->isAvailable()) {
            return null;
        }

        $tempDir = sys_get_temp_dir() . '/zugferd_' . uniqid();
        Folder::create($tempDir, 0755, true);
        $outputPath = $tempDir . '/' . $filename;

        try {
            // Versuche pdfdetach
            if ($this->config->buildCommand('pdfdetach-savefile', ['[FILENAME]' => $filename, '[OUTPUT-FILE]' => $outputPath, '[PDF-FILE]' => $pdfPath]) !== null) {
                return $this->extractWithPdfdetach($pdfPath, $filename, $tempDir);
            }

            // Fallback: pdftk
            if ($this->config->buildCommand('pdftk-unpack', ['[PDF-FILE]' => $pdfPath, '[OUTPUT-DIR]' => $tempDir]) !== null) {
                return $this->extractWithPdftk($pdfPath, $filename, $tempDir);
            }
        } finally {
            // Cleanup
            $this->cleanupTempDir($tempDir);
        }

        return null;
    }

    /**
     * Listet Attachments mit pdfdetach (poppler-utils).
     */
    private function listAttachmentsWithPdfdetach(string $pdfPath): array {
        $command = $this->config->buildCommand('pdfdetach-list', [
            '[PDF-FILE]' => $pdfPath,
        ]);
        if ($command === null) {
            return [];
        }

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $attachments = [];
        foreach ($output as $line) {
            // Format: "1: filename.xml"
            if (preg_match('/^\d+:\s+(.+)$/', trim($line), $matches)) {
                $attachments[] = trim($matches[1]);
            }
        }

        return $attachments;
    }

    /**
     * Listet Attachments mit pdftk.
     */
    private function listAttachmentsWithPdftk(string $pdfPath): array {
        $command = $this->config->buildCommand('pdftk-dump', [
            '[PDF-FILE]' => $pdfPath,
        ]);
        if ($command === null) {
            return [];
        }

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $attachments = [];
        $inAttachment = false;

        foreach ($output as $line) {
            if (str_starts_with($line, 'EmbeddedFile')) {
                $inAttachment = true;
            } elseif ($inAttachment && str_starts_with($line, 'EmbeddedFileName:')) {
                $attachments[] = trim(substr($line, strlen('EmbeddedFileName:')));
            } elseif ($inAttachment && $line === '') {
                $inAttachment = false;
            }
        }

        return $attachments;
    }

    /**
     * Extrahiert Attachment mit pdfdetach.
     */
    private function extractWithPdfdetach(string $pdfPath, string $filename, string $tempDir): ?string {
        $outputPath = $tempDir . '/' . $filename;

        $command = $this->config->buildCommand('pdfdetach-savefile', [
            '[FILENAME]' => $filename,
            '[OUTPUT-FILE]' => $outputPath,
            '[PDF-FILE]' => $pdfPath,
        ]);
        if ($command === null) {
            return null;
        }

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0 || !File::exists($outputPath)) {
            // Versuche mit saveall
            $command = $this->config->buildCommand('pdfdetach-saveall', [
                '[OUTPUT-DIR]' => $tempDir,
                '[PDF-FILE]' => $pdfPath,
            ]);
            if ($command !== null) {
                Shell::executeShellCommand($command, $output, $returnCode);
            }

            if (!File::exists($outputPath)) {
                return null;
            }
        }

        return File::read($outputPath);
    }

    /**
     * Extrahiert Attachment mit pdftk.
     */
    private function extractWithPdftk(string $pdfPath, string $filename, string $tempDir): ?string {
        $command = $this->config->buildCommand('pdftk-unpack', [
            '[PDF-FILE]' => $pdfPath,
            '[OUTPUT-DIR]' => $tempDir,
        ]);
        if ($command === null) {
            return null;
        }

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        $outputPath = $tempDir . '/' . $filename;
        if (!File::exists($outputPath)) {
            return null;
        }

        return File::read($outputPath);
    }

    /**
     * Prüft ob der Dateiname eine bekannte Rechnungs-XML ist.
     */
    private function isInvoiceFile(string $filename): bool {
        $lower = strtolower($filename);

        foreach (self::INVOICE_FILENAMES as $known) {
            if (strtolower($known) === $lower) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prüft ob XML-Inhalt nach einer E-Rechnung aussieht.
     */
    private function looksLikeInvoiceXml(string $xml): bool {
        // Schneller Check auf bekannte Namespaces/Root-Elemente
        return str_contains($xml, 'CrossIndustryInvoice')
            || str_contains($xml, 'urn:un:unece:uncefact')
            || str_contains($xml, 'Invoice xmlns')
            || str_contains($xml, 'ubl:Invoice')
            || str_contains($xml, 'CreditNote xmlns');
    }

    /**
     * Räumt temporäres Verzeichnis auf.
     */
    private function cleanupTempDir(string $dir): void {
        if (!Folder::exists($dir)) {
            return;
        }

        Folder::delete($dir, true);
    }
}
