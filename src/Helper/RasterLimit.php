<?php
/*
 * Created on   : Thu Nov 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : RasterLimit.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use PDFToolkit\Config\Config;
use PDFToolkit\Entities\PageSize;

/**
 * Pixelgrenze der Rasterwege (seit 0.32.1) - der Wert an dieser einen Stelle,
 * wie die Zeitgrenzen in {@see ToolTimeout}.
 *
 * pdfinfo nennt als Seitengröße die CropBox, pdftoppm rastert ohne -cropbox
 * die MediaBox. Eine 1,6-KB-PDF mit A4-CropBox und 9000 x 9000 pt MediaBox
 * bestand deshalb jede Prüfung der Seitengröße und kostete mit 200 dpi
 * 2,4 GB Speicher und 17 s je Seite. Jeder Rasterweg des Pakets fragt vorher
 * hier nach, gemessen an der größeren der beiden Boxen
 * ({@see \PDFToolkit\Entities\PageBoxes::largest()}), egal welche gerastert wird:
 *
 * - pdftoppm (Vorschau, Seitenbilder, Miniaturen, Barcodes, Tesseract-OCR):
 *   {@see dpiFor()} senkt die Auflösung des Laufs so weit, dass keine Seite
 *   mehr als {@see maxPixels()} Pixel bekommt. Seiten unter der Grenze
 *   behalten die verlangte Auflösung.
 * - OCRmyPDF wählt die Auflösung selbst (mindestens {@see OCRMYPDF_DPI} für
 *   Seiten mit Text oder Vektorinhalt) und rastert die MediaBox mit Ghostscript:
 *   {@see fits()} weist Dateien mit zu großen Seiten vorher ab.
 *
 * Sind die Seitenmaße nicht lesbar, wird nicht gerastert.
 *
 * Konfiguration: `PDFSettings.raster_max_pixels` (Pixel je Seite), für einen
 * Prozess vorrangig {@see configure()}.
 */
final class RasterLimit {
    use ErrorLog;

    /**
     * Vorgabe: 150 Megapixel je Seite. A0 mit 300 dpi (139 MP) und A1 mit den
     * 400 dpi von OCRmyPDF (124 MP) passen; pdftoppm braucht an der Grenze rund
     * 0,6 GB (gemessen 12.11.2026, poppler 25.03: knapp 4 Byte je Pixel).
     */
    public const DEFAULT_MAX_PIXELS = 150_000_000;

    /** Auflösung, mit der OCRmyPDF Seiten mit Text oder Vektorinhalt mindestens rastert (VECTOR_PAGE_DPI). */
    public const OCRMYPDF_DPI = 400.0;

    /**
     * Darunter wird nicht gerastert: Eine Seite, die selbst mit 1 dpi über der
     * Grenze läge, misst Hunderte Meter je Kante - das ist kein Dokument.
     */
    public const MIN_DPI = 1.0;

    /** Pixelgrenze aus configure(), vorrangig vor der Konfiguration */
    private static ?int $configuredMaxPixels = null;

    /**
     * Setzt die Pixelgrenze für diesen Prozess, vorrangig vor
     * `PDFSettings.raster_max_pixels`; null kehrt zur Konfiguration zurück.
     *
     * @throws InvalidArgumentException bei einer Grenze unter 1
     */
    public static function configure(?int $maxPixels): void {
        if ($maxPixels !== null && $maxPixels < 1) {
            self::logErrorAndThrow(InvalidArgumentException::class, 'Die Pixelgrenze zum Rastern muss mindestens 1 sein.');
        }
        self::$configuredMaxPixels = $maxPixels;
    }

    /**
     * Setzt die Einstellung aus configure() zurück (Tests).
     */
    public static function reset(): void {
        self::$configuredMaxPixels = null;
    }

    /**
     * Höchstzahl Pixel je gerasterter Seite: configure(), sonst
     * `PDFSettings.raster_max_pixels`, sonst {@see DEFAULT_MAX_PIXELS}. Ein
     * unbrauchbarer Konfigurationswert (keine Zahl, unter 1) gilt als Vorgabe -
     * abschalten lässt sich die Grenze nicht, nur anheben.
     */
    public static function maxPixels(): int {
        if (self::$configuredMaxPixels !== null) {
            return self::$configuredMaxPixels;
        }

        $configured = Config::getInstance()->getConfig('PDFSettings', 'raster_max_pixels');
        if ($configured === null) {
            return self::DEFAULT_MAX_PIXELS;
        }
        if (!is_numeric($configured) || (int) $configured < 1) {
            self::logWarning('PDFSettings.raster_max_pixels ist keine positive Zahl, es gilt die Vorgabe', ['value' => $configured, 'default' => self::DEFAULT_MAX_PIXELS]);
            return self::DEFAULT_MAX_PIXELS;
        }

        return (int) $configured;
    }

    /**
     * Höchste Auflösung bis $dpi, mit der eine Seite dieser Größe höchstens
     * {@see maxPixels()} Pixel bekommt; auf 0,01 dpi abgerundet. Unter
     * {@see MIN_DPI} (oder bei unbrauchbaren Maßen) null: nicht rastern.
     */
    public static function dpiForSize(PageSize $size, float $dpi): ?float {
        $maxPixels = (float) self::maxPixels();
        if ($dpi <= 0.0 || $size->pixelsAt($dpi) <= $maxPixels) {
            return $dpi;
        }

        $area = $size->widthPt * $size->heightPt;
        if (!is_finite($area) || $area <= 0.0) {
            return null;
        }

        // Fläche in Punkten x (dpi/72)^2 = Pixel; das Aufrunden je Kante holt die Schleife ein
        $reduced = floor(min($dpi, 72.0 * sqrt($maxPixels / $area)) * 100) / 100;
        while ($reduced >= self::MIN_DPI && $size->pixelsAt($reduced) > $maxPixels) {
            $reduced = floor($reduced * 99) / 100;
        }

        return $reduced >= self::MIN_DPI ? $reduced : null;
    }

    /**
     * Auflösung für einen pdftoppm-Lauf über die Seiten $firstPage bis
     * $lastPage: $dpi, wenn jede Seite darunter bleibt, sonst so weit gesenkt,
     * dass die größte passt. Gemessen wird die größere von MediaBox und
     * CropBox - auch wenn mit -cropbox gerastert wird.
     *
     * @param int $lastPage Letzte Seite; 0 = bis zur letzten
     * @return float|null null, wenn die Seitenmaße nicht lesbar sind oder eine
     *                    Seite selbst mit {@see MIN_DPI} zu groß wäre - dann nicht rastern
     */
    public static function dpiFor(string $pdfPath, float $dpi, int $firstPage = 1, int $lastPage = 0): ?float {
        $pages = PDFHelper::getAllPageBoxes($pdfPath, $firstPage, $lastPage);
        if ($pages === []) {
            return self::logErrorAndReturn(null, 'Seitenmaße nicht lesbar, es wird nicht gerastert', ['path' => $pdfPath, 'first' => $firstPage, 'last' => $lastPage]);
        }

        $result = $dpi;
        foreach ($pages as $page => $boxes) {
            $size = $boxes->largest();
            $pageDpi = self::dpiForSize($size, $result);
            if ($pageDpi === null) {
                return self::logErrorAndReturn(null, 'Seite ist zu groß zum Rastern, es wird nicht gerastert', [
                    'path' => $pdfPath,
                    'page' => $page,
                    'widthPt' => $size->widthPt,
                    'heightPt' => $size->heightPt,
                    'maxPixels' => self::maxPixels(),
                ]);
            }
            $result = $pageDpi;
        }

        if ($result < $dpi) {
            self::logWarning('Seite zu groß zum Rastern, Auflösung gesenkt', [
                'path' => $pdfPath,
                'requestedDpi' => $dpi,
                'dpi' => $result,
                'maxPixels' => self::maxPixels(),
            ]);
        }

        return $result;
    }

    /**
     * Bleibt jede Seite (bzw. jede aus $pages) mit $dpi unter der Grenze? Für
     * Werkzeuge, denen sich keine Auflösung vorgeben lässt (OCRmyPDF mit
     * {@see OCRMYPDF_DPI}). Nicht lesbare Seitenmaße zählen als zu groß.
     *
     * @param list<int>|null $pages Seiten (1-basiert); null = alle
     */
    public static function fits(string $pdfPath, float $dpi, ?array $pages = null): bool {
        $boxes = PDFHelper::getAllPageBoxes($pdfPath);
        if ($boxes === []) {
            return self::logErrorAndReturn(false, 'Seitenmaße nicht lesbar, es wird nicht gerastert', ['path' => $pdfPath]);
        }

        $maxPixels = (float) self::maxPixels();
        foreach ($pages ?? array_keys($boxes) as $page) {
            if (!isset($boxes[$page])) {
                continue; // Seite gibt es nicht: rastert auch niemand
            }
            $size = $boxes[$page]->largest();
            if ($size->pixelsAt($dpi) > $maxPixels) {
                return self::logErrorAndReturn(false, 'Seite ist zu groß zum Rastern', [
                    'path' => $pdfPath,
                    'page' => $page,
                    'dpi' => $dpi,
                    'widthPt' => $size->widthPt,
                    'heightPt' => $size->heightPt,
                    'maxPixels' => self::maxPixels(),
                ]);
            }
        }

        return true;
    }

    /**
     * Wert für den Platzhalter [DPI]: ganze Zahlen wie bisher ("300"), eine
     * gesenkte Auflösung mit bis zu zwei Nachkommastellen ("97.53");
     * pdftoppm liest -r als Gleitkommazahl.
     */
    public static function format(float $dpi): string {
        if (floor($dpi) === $dpi) {
            return (string) (int) $dpi;
        }

        return rtrim(rtrim(number_format($dpi, 2, '.', ''), '0'), '.');
    }
}
