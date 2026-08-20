<?php
/*
 * Created on   : Thu Aug 20 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : StationeryOverlayWriter.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Writers;

use ERRORToolkit\Traits\ErrorLog;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Legt einen Firmenbogen (Briefpapier) als PDF UNTER die Seiten eines
 * bereits erzeugten Inhalts-PDFs — erste Seite und Folgeseiten getrennt.
 *
 * **Warum kein Rasterbild:** Der naheliegende Weg ist, den Bogen zu rastern
 * und als Bild in den HTML-Renderer zu hängen. Das kostet die Vektor-Schärfe
 * (Logo und Feinlinien werden bei jeder Druckauflösung sichtbar weich), bläht
 * die Datei um ein Vielfaches auf und macht Text im Bogen unauffindbar. Der
 * Overlay-Weg behält den Originalbogen als Vektor.
 *
 * **Warum der FPDF-Adapter von FPDI und nicht der TCPDF-Adapter:** FPDIs
 * TCPDF-Adapter greift auf TCPDF-6-Interna zu (`_out()`), die im
 * TCPDF-7-Rewrite entfallen sind — mit dem hier gepinnten TCPDF ≥ 7 bricht er
 * zur Laufzeit ab (dieselbe Einschränkung dokumentiert
 * {@see ZugferdWriter::isFpdiAvailable()}). Der FPDF-Adapter ist davon nicht
 * betroffen; er kostet die zusätzliche Abhängigkeit `setasign/fpdf` (MIT,
 * reines PHP) und kann genau das, was hier gebraucht wird: Seiten importieren
 * und stapeln. Eigene Inhalte erzeugt dieser Writer nicht.
 *
 * **Warum kein {@see \PDFToolkit\Contracts\PDFWriterInterface}:** Dieser
 * Vertrag beschreibt die Umwandlung von {@see \PDFToolkit\Entities\PDFContent}
 * in ein PDF und die Fallback-Kette der Registry. Hier wird nichts
 * konvertiert — ein fertiges PDF wird nachbearbeitet. Ein `canHandle()` auf
 * PDFContent wäre eine Lüge, und der Writer würde in einer Kette landen, in
 * die er nicht gehört.
 *
 * Reihenfolge im Ausgabedokument: erst der Bogen, dann die Inhaltsseite.
 * Das setzt voraus, dass die Inhaltsseite KEINEN deckenden Hintergrund malt —
 * bei dompdf/wkhtmltopdf ohne gesetzte `background-color` ist das der Fall.
 */
final class StationeryOverlayWriter {
    use ErrorLog;

    /**
     * Bogen unter ein Inhalts-PDF legen (Dateipfade).
     *
     * @param string      $contentPath    Fertiges Inhalts-PDF.
     * @param string      $outputPath     Zielpfad.
     * @param string|null $firstPath      Bogen für die ERSTE Seite (null = keiner).
     * @param string|null $followingPath  Bogen für Folgeseiten (null = wie erste Seite;
     *                                    ausdrücklich '' = Folgeseiten ohne Bogen).
     */
    public function overlay(string $contentPath, string $outputPath, ?string $firstPath, ?string $followingPath = null): bool {
        $pdf = $this->build($contentPath, $firstPath, $followingPath);
        if ($pdf === null) {
            return false;
        }

        try {
            $pdf->Output($outputPath, 'F');

            return is_file($outputPath) && filesize($outputPath) > 0;
        } catch (Throwable $e) {
            $this->logError('Stationery overlay output failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Wie {@see overlay()}, liefert das Ergebnis als String.
     */
    public function overlayToString(string $contentPath, ?string $firstPath, ?string $followingPath = null): ?string {
        $pdf = $this->build($contentPath, $firstPath, $followingPath);
        if ($pdf === null) {
            return null;
        }

        try {
            return $pdf->Output('', 'S');
        } catch (Throwable $e) {
            $this->logError('Stationery overlay output failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Bogen unter ein Inhalts-PDF legen, alles als Roh-Bytes. Legt die
     * Eingaben in temporäre Dateien ab — FPDI liest ausschließlich aus
     * Dateien bzw. Streams.
     */
    public function overlayBytes(string $contentPdf, ?string $firstPdf, ?string $followingPdf = null): ?string {
        $temps = [];
        try {
            $contentPath = $this->toTempFile($contentPdf, $temps);
            if ($contentPath === null) {
                return null;
            }

            $firstPath = $firstPdf === null || $firstPdf === '' ? $firstPdf : $this->toTempFile($firstPdf, $temps);
            $followingPath = $followingPdf === null || $followingPdf === '' ? $followingPdf : $this->toTempFile($followingPdf, $temps);

            return $this->overlayToString($contentPath, $firstPath, $followingPath);
        } finally {
            foreach ($temps as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * FPDI kann Seiten importieren? (Ohne die Bibliothek gibt es keinen
     * Overlay — der Aufrufer fällt dann auf seinen bisherigen Weg zurück.)
     */
    public function isAvailable(): bool {
        return class_exists(Fpdi::class);
    }

    /** Baut das Ergebnisdokument; null bei Fehler. */
    private function build(string $contentPath, ?string $firstPath, ?string $followingPath): ?Fpdi {
        if (!$this->isAvailable()) {
            $this->logError('FPDI is not available — stationery overlay skipped.');

            return null;
        }
        if (!is_file($contentPath)) {
            $this->logError('Content PDF not found: ' . $contentPath);

            return null;
        }

        // null = Folgeseiten erben den Bogen der ersten Seite; '' = bewusst
        // keiner. Die Unterscheidung ist fachlich: „nur Seite 1 auf
        // Briefpapier" ist ein üblicher Wunsch und darf nicht dasselbe
        // bedeuten wie „nichts angegeben".
        $followingPath ??= $firstPath;

        try {
            // FPDF-Adapter: Einheit mm, Seitengröße kommt je Seite aus dem
            // Inhalts-PDF (AddPage unten).
            $pdf = new Fpdi('P', 'mm', 'A4');
            $pdf->SetAutoPageBreak(false, 0);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetCompression(true);

            // Bögen ZUERST importieren: importPage() bezieht sich immer auf
            // die zuletzt via setSourceFile() geöffnete Datei.
            $firstTemplate = $this->importSingle($pdf, $firstPath);
            $followingTemplate = $followingPath === $firstPath
                ? $firstTemplate
                : $this->importSingle($pdf, $followingPath);

            $pageCount = $pdf->setSourceFile($contentPath);
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $contentTemplate = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($contentTemplate);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);

                $stationery = $pageNo === 1 ? $firstTemplate : $followingTemplate;
                if ($stationery !== null) {
                    // Der Bogen wird auf die Seitengröße des Inhalts gezogen:
                    // ein A4-Bogen unter einer A4-Seite ist deckungsgleich,
                    // eine Abweichung wäre ein Konfigurationsfehler, den ein
                    // stiller Versatz nur verschleiern würde.
                    $pdf->useTemplate($stationery, 0, 0, $size['width'], $size['height']);
                }

                $pdf->useTemplate($contentTemplate, 0, 0, $size['width'], $size['height']);
            }

            return $pdf;
        } catch (Throwable $e) {
            $this->logError('Stationery overlay failed: ' . $e->getMessage(), ['exception' => get_class($e)]);

            return null;
        }
    }

    /** Erste Seite einer Bogendatei importieren; null, wenn keiner gewünscht/vorhanden ist. */
    private function importSingle(Fpdi $pdf, ?string $path): mixed {
        if ($path === null || $path === '' || !is_file($path)) {
            return null;
        }

        $pdf->setSourceFile($path);

        return $pdf->importPage(1);
    }

    /**
     * @param list<string> $temps
     */
    private function toTempFile(string $bytes, array &$temps): ?string {
        $base = tempnam(sys_get_temp_dir(), 'stationery_');
        if ($base === false) {
            $this->logError('Could not create a temporary file for the stationery overlay.');

            return null;
        }

        // FPDI erwartet die .pdf-Endung nicht zwingend, TCPDF-Fehlermeldungen
        // werden mit ihr aber lesbar.
        $path = $base . '.pdf';
        $temps[] = $base;
        $temps[] = $path;
        file_put_contents($path, $bytes);

        return $path;
    }
}
