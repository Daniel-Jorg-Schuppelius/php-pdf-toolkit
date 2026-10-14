<?php
/*
 * Created on   : Wed Oct 14 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TempPath.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Platform;
use ERRORToolkit\Traits\ErrorLog;
use RuntimeException;

/**
 * Arbeitsdateien und -verzeichnisse der Werkzeugaufrufe im Temp-Ordner.
 *
 * Darin liegen gerasterte Seiten, OCR- und Textausgaben, also Inhalte der
 * verarbeiteten Dokumente. Namen sind deshalb nicht vorhersagbar (64 Bit aus
 * random_bytes statt uniqid()), Verzeichnisse nur für den eigenen Benutzer
 * lesbar (0700), Dateien exklusiv angelegt und 0600 (Scan 13.10.2026 S4-03).
 * Werkzeuge, die in eine vorab angelegte Datei schreiben, kürzen sie nur -
 * die Rechte bleiben erhalten.
 */
final class TempPath {
    use ErrorLog;

    private const ATTEMPTS = 5;

    /**
     * Legt ein privates Arbeitsverzeichnis an (0700).
     *
     * @param string $prefix Namensanfang, z.B. "tesseract"
     * @return string Pfad des neuen Verzeichnisses
     * @throws RuntimeException wenn kein Verzeichnis anlegbar ist
     */
    public static function directory(string $prefix): string {
        $base = rtrim(Platform::getTempDirectory(), '/\\');
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $path = $base . DIRECTORY_SEPARATOR . $prefix . '_' . bin2hex(random_bytes(8));
            if (Folder::exists($path)) {
                continue;
            }
            Folder::create($path, 0700);

            return $path;
        }

        self::logErrorAndThrow(RuntimeException::class, "Temp-Verzeichnis nicht anlegbar: {$base}/{$prefix}_*");
    }

    /**
     * Legt eine leere Arbeitsdatei exklusiv an (0600).
     *
     * @param string $prefix Namensanfang, z.B. "pdftotext"
     * @param string|null $extension Dateiendung ohne Punkt
     * @param string|null $directory Zielordner (Standard: Temp-Ordner)
     * @return string Pfad der neuen Datei
     */
    public static function file(string $prefix, ?string $extension = null, ?string $directory = null): string {
        return File::createTemp('', $prefix . '_', $extension, 0600, $directory ?? Platform::getTempDirectory());
    }
}
