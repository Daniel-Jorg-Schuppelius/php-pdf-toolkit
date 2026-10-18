<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PdfProbe.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Probe-PDFs und Messhelfer für die Seiten-, Raster- und Schutz-Tests:
 * beschriftete Seiten aus Ghostscript, Text und Masse über poppler.
 */
final class PdfProbe {
    /** gs, pdftotext und pdfinfo vorhanden? Ohne sie entstehen keine Probe-PDFs. */
    public static function toolsAvailable(): bool {
        foreach (['gs', 'pdftotext', 'pdfinfo'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A4-PDF mit $pages Seiten, jede in 5 Zeilen x 2 Spalten beschriftet
     * ("P2-R3C1" = Seite 2, Zeile 3, Spalte 1).
     */
    public static function labelled(TestCase $test, string $dir, int $pages): string {
        $ps = $dir . '/labels.ps';
        file_put_contents($ps, sprintf(<<<'PS'
            /Helvetica findfont 20 scalefont setfont
            1 1 %d { /p exch def
              0 1 4 { /r exch def 0 1 1 { /c exch def
                c 297.5 mul 40 add  842 r 1 add 168.4 mul sub 70 add moveto
                (P) show p 3 string cvs show (-R) show r 1 add 3 string cvs show (C) show c 1 add 3 string cvs show
              } for } for showpage } for
            PS, $pages));

        $pdf = $dir . '/labels.pdf';
        exec(sprintf(
            'gs -q -sDEVICE=pdfwrite -dDEVICEWIDTHPOINTS=595 -dDEVICEHEIGHTPOINTS=842 -dFIXEDMEDIA -o %s %s 2>&1',
            escapeshellarg($pdf),
            escapeshellarg($ps)
        ), $output, $code);
        $test->assertSame(0, $code, 'Probe-PDF konnte nicht erzeugt werden: ' . implode("\n", $output));

        return $pdf;
    }

    /**
     * PDF mit einem großen JPEG je Seite (für Komprimieren und PDF/A).
     */
    public static function imageHeavy(TestCase $test, string $dir, int $pages = 1): ?string {
        if (trim((string) shell_exec('command -v convert')) === '') {
            return null;
        }
        $images = [];
        for ($i = 1; $i <= $pages; $i++) {
            $jpg = $dir . "/big-$i.jpg";
            exec(sprintf('convert -size 1600x2200 plasma:fractal -quality 92 %s 2>&1', escapeshellarg($jpg)), $output, $code);
            if ($code !== 0) {
                return null;
            }
            $images[] = escapeshellarg($jpg);
        }
        $pdf = $dir . '/images.pdf';
        exec(sprintf('convert %s -density 300 -units PixelsPerInch %s 2>&1', implode(' ', $images), escapeshellarg($pdf)), $output, $code);
        $test->assertSame(0, $code, 'Bild-PDF konnte nicht erzeugt werden: ' . implode("\n", $output));

        return $pdf;
    }

    /**
     * Einseitige PDF mit Kommentar (Text-Anmerkung), Verknüpfung, Formularfeld
     * (Widget samt AcroForm) und OpenAction-JavaScript - über Ghostscript-pdfmarks.
     */
    public static function annotated(TestCase $test, string $dir): string {
        $ps = $dir . '/annotated.ps';
        file_put_contents($ps, <<<'PS'
            [ /Rect [50 700 200 750] /Subtype /Text /Contents (Kommentar) /ANN pdfmark
            [ /Rect [50 600 200 650] /Subtype /Link /Action << /Subtype /URI /URI (https://example.org) >> /ANN pdfmark
            [ /_objdef {fld} /Rect [50 500 300 530] /Subtype /Widget /FT /Tx /T (feld) /V (Wert) /DA (/Helv 12 Tf 0 g) /F 4 /ANN pdfmark
            [ {Catalog} << /AcroForm << /Fields [ {fld} ] /DA (/Helv 0 Tf 0 g) /NeedAppearances true >> >> /PUT pdfmark
            [ {Catalog} << /OpenAction << /S /JavaScript /JS (app.alert\(1\)) >> >> /PUT pdfmark
            /Helvetica findfont 20 scalefont setfont 40 800 moveto (Seite eins) show showpage
            PS);

        $pdf = $dir . '/annotated.pdf';
        exec(sprintf(
            'gs -q -sDEVICE=pdfwrite -dDEVICEWIDTHPOINTS=595 -dDEVICEHEIGHTPOINTS=842 -dFIXEDMEDIA -o %s %s 2>&1',
            escapeshellarg($pdf),
            escapeshellarg($ps)
        ), $output, $code);
        $test->assertSame(0, $code, 'Annotierte Probe-PDF konnte nicht erzeugt werden: ' . implode("\n", $output));

        return $pdf;
    }

    /** Objekt einer PDF als Text (mutool show), z. B. "pages/1" oder "trailer/Root" */
    public static function object(string $pdf, string $path): string {
        return (string) shell_exec(sprintf('mutool show %s %s 2>/dev/null', escapeshellarg($pdf), escapeshellarg($path)));
    }

    /**
     * Lesezeichen einer PDF als Text (mutool show outline): je Zeile Titel und
     * Seite ("#page=1", aeltere Versionen "#1" oder "#1,0,842"). MuPDF 1.17
     * schreibt Nicht-ASCII-Bytes als \xNN; die werden zurueckgewandelt.
     */
    public static function outline(string $pdf): string {
        $text = (string) shell_exec(sprintf('mutool show %s outline 2>/dev/null', escapeshellarg($pdf)));

        return (string) preg_replace_callback('/\\\\x([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $text);
    }

    /** Mittlere Helligkeit 0-255 eines Bildausschnitts (GD) */
    public static function meanBrightness(\GdImage $image, int $x0, int $y0, int $x1, int $y1): float {
        $sum = 0;
        $count = 0;
        for ($y = $y0; $y <= $y1; $y += 2) {
            for ($x = $x0; $x <= $x1; $x += 2) {
                $rgb = imagecolorat($image, $x, $y);
                $sum += ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 3;
                $count++;
            }
        }

        return $count === 0 ? 0.0 : $sum / $count;
    }

    public static function pageText(string $pdf, int $page, ?string $password = null): string {
        $pw = $password !== null ? ' -upw ' . escapeshellarg($password) : '';
        $text = (string) shell_exec(sprintf('pdftotext%s -f %d -l %d %s - 2>/dev/null', $pw, $page, $page, escapeshellarg($pdf)));

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public static function pageCount(string $pdf, ?string $password = null): int {
        return preg_match('/^Pages:\s+(\d+)/m', self::info($pdf, $password), $m) ? (int) $m[1] : 0;
    }

    public static function pageRotation(string $pdf, int $page): int {
        $info = (string) shell_exec(sprintf('pdfinfo -f %d -l %d %s 2>/dev/null', $page, $page, escapeshellarg($pdf)));

        return preg_match('/Page\s+' . $page . '\s+rot:\s+(\d+)/', $info, $m) ? (int) $m[1] : 0;
    }

    /** @return array{0: float, 1: float} Breite und Höhe in Punkten */
    public static function pageSize(string $pdf, int $page): array {
        $info = (string) shell_exec(sprintf('pdfinfo -f %d -l %d %s 2>/dev/null', $page, $page, escapeshellarg($pdf)));
        preg_match('/Page\s+' . $page . '\s+size:\s+([\d.]+) x ([\d.]+)/', $info, $m);

        return [(float) ($m[1] ?? 0), (float) ($m[2] ?? 0)];
    }

    /** Zeile "Encrypted: ..." von pdfinfo, leer wenn pdfinfo die Datei nicht öffnet */
    public static function encryptionLine(string $pdf, ?string $password = null): string {
        return preg_match('/^Encrypted:\s+(.*)$/m', self::info($pdf, $password), $m) ? trim($m[1]) : '';
    }

    public static function info(string $pdf, ?string $password = null): string {
        $pw = $password !== null ? ' -upw ' . escapeshellarg($password) : '';

        return (string) shell_exec(sprintf('pdfinfo%s %s 2>/dev/null', $pw, escapeshellarg($pdf)));
    }

    public static function removeDir(string $dir): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
