<?php
/*
 * Created on   : Tue Oct 13 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ToolTimeout.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;

/**
 * Zeitgrenzen der externen Werkzeuge - alle Werte an dieser einen Stelle.
 *
 * Jeder Werkzeugaufruf des Pakets laeuft ueber Shell::executeShellCommand()
 * bzw. Shell::executeParallel() und bekommt seinen Deckel von hier: kurze
 * Proben einen kleinen festen, eine einzelne Seite einen mittleren, Arbeit am
 * ganzen Dokument einen, der mit der Dateigroesse (Text, Seitenbearbeitung,
 * Ghostscript) bzw. der Seitenzahl (Rendern, OCR) waechst. Nach Ablauf endet
 * der ganze Prozessbaum, der Aufruf meldet einen Fehler.
 *
 * Die Deckel fangen Haenger ab und sind deshalb grosszuegig, damit legitime
 * grosse Dateien durchlaufen. Skalierte Deckel liegen nie unter der
 * prozessweiten Standard-Zeitgrenze ({@see Shell::setDefaultTimeout()}):
 * Wer sie fuer lange OCR-Laeufe hochsetzt, hebt diese Deckel mit an.
 */
final class ToolTimeout {
    /** Infoabfragen und kurze Proben: pdfinfo, pdfdetach -list, mutool-Passwortprobe. */
    public const PROBE = Shell::TIMEOUT_PROBE;

    /** Eine einzelne Seite: pdftoppm einer Seite, pdftotext der ersten Seite, tesseract und mogrify je Seite. */
    public const PAGE = 120.0;

    /** Grundwert fuer Arbeit am ganzen Dokument. */
    public const DOCUMENT = 180.0;

    /** Zuschlag je MB Eingabe fuer Text und Seitenbearbeitung (pdftotext, pdfbox, mutool, pdftk, poppler, Ghostscript). */
    public const PER_MEGABYTE = 10.0;

    /** Zuschlag je Seite fuer das Rendern (pdftoppm, pdfimages, mutool draw). */
    public const RENDER_PER_PAGE = 10.0;

    /** Zuschlag je Seite fuer OCR ganzer Dokumente (OCRmyPDF, auch dessen Optimierer und PDF/A: --skip-text erkennt Scanseiten). */
    public const OCR_PER_PAGE = 60.0;

    /** veraPDF (Java, volle Regelpruefung) fuer die PDF/A-Pruefung. */
    public const VERAPDF = 300.0;

    /** PdfCraft CLI als Rueckfall der PDF/A-Pruefung. */
    public const PDFCRAFT = 120.0;

    /** Obergrenze jedes skalierten Deckels: 4 Stunden. */
    public const MAX = 14400.0;

    /** Angenommene Seiten je MB, wenn pdfinfo keine Seitenzahl liefert - eher zu viele als zu wenige. */
    private const PAGES_PER_MEGABYTE = 10;

    /**
     * Deckel fuer Text- und Strukturarbeit am ganzen Dokument, nach Dateigroesse.
     *
     * @param string ...$paths Eingabedateien (z. B. alle Teile beim Zusammenfuegen).
     */
    public static function forSize(string ...$paths): float {
        return self::scaled(self::PER_MEGABYTE * self::megabytes(...$paths));
    }

    /**
     * Deckel fuers Rendern; ohne $pages zaehlt die ganze Datei.
     */
    public static function forRendering(string $pdfPath, ?int $pages = null): float {
        return self::scaled(self::RENDER_PER_PAGE * ($pages ?? self::pageCount($pdfPath)));
    }

    /**
     * Deckel fuer OCR; ohne $pages zaehlt die ganze Datei.
     */
    public static function forOcr(string $pdfPath, ?int $pages = null): float {
        return self::scaled(self::OCR_PER_PAGE * ($pages ?? self::pageCount($pdfPath)));
    }

    /**
     * DOCUMENT plus Zuschlag, hoechstens MAX, nie unter der prozessweiten Standard-Zeitgrenze.
     */
    private static function scaled(float $surcharge): float {
        return max(Shell::getDefaultTimeout(), min(self::MAX, self::DOCUMENT + max(0.0, $surcharge)));
    }

    private static function megabytes(string ...$paths): float {
        $bytes = 0;
        foreach ($paths as $path) {
            $bytes += File::exists($path) ? File::size($path) : 0;
        }

        return $bytes / 1048576;
    }

    /**
     * Seitenzahl per pdfinfo; ohne Ergebnis (verschluesselt, kaputt, kein PDF) aus der Groesse geschaetzt.
     */
    private static function pageCount(string $pdfPath): int {
        $pages = PDFHelper::isValidPdf($pdfPath) ? PDFHelper::getPageCount($pdfPath) : 0;

        return $pages > 0 ? $pages : max(1, (int) ceil(self::megabytes($pdfPath) * self::PAGES_PER_MEGABYTE));
    }
}
