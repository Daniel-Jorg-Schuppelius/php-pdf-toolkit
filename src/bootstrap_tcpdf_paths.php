<?php

declare(strict_types=1);

/**
 * Bootstrap: Lesepfad der tc-lib-Pakete für TCPDF 7.
 *
 * Die TCPDF-7-Fassade erlaubt lokale Dateien nur unter festen Wurzeln, dem
 * Arbeitsverzeichnis und dem Skriptordner. Die Geschwisterpakete einer
 * Composer-Installation (`vendor/tecnickcom/tc-lib-pdf` mit dem sRGB-Profil
 * für PDF/A) fehlen darin; die Wurzel `tcpdf/vendor/tecnickcom/` gibt es nur
 * im eigenständigen Checkout. Unter PHP-FPM (Arbeitsverzeichnis `public/`)
 * scheitert damit jede PDF/A-Ausgabe an „Unable to read sRGB.icc.z file“.
 *
 * K_ALLOWED_PATHS ist der dafür vorgesehene Erweiterungspunkt (wird zu den
 * Vorgaben addiert). Ein vom Projekt vorher gesetzter Wert gewinnt.
 */

namespace PDFToolkit;

use Com\Tecnick\Pdf\Tcpdf as TcLibPdf;

(static function (): void {
    if (\defined('K_ALLOWED_PATHS') || !\class_exists(TcLibPdf::class)) {
        return;
    }

    $file = (new \ReflectionClass(TcLibPdf::class))->getFileName();
    if ($file === false) {
        return;
    }

    // tecnickcom/tc-lib-pdf/src/Tcpdf.php → tecnickcom/
    \define('K_ALLOWED_PATHS', [\dirname($file, 3) . '/']);
})();
