<?php
/*
 * Created on   : Sat Jul 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : FontDataHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\Folder;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Helper-Klasse für die TCPDF-7-Font-Definitionen.
 *
 * Ab TCPDF 7 (Implementierung in tc-lib-pdf) werden die Font-Metriken nicht
 * mehr mit dem Composer-Paket ausgeliefert; ohne sie scheitert bereits
 * `new TCPDF()`. Dieses Toolkit liefert die benötigten Gruppen unter
 * data/fonts/ mit — siehe data/fonts/README.md zur Neuerzeugung.
 *
 * Der Pfad wird beim Autoload über src/bootstrap_fonts.php als K_PATH_FONTS
 * gesetzt. Diese Klasse dient der Diagnose und dem gezielten Nachschlagen.
 */
final class FontDataHelper {
    use ErrorLog;

    /** Ohne diese Gruppe ist keine PDF-Erzeugung möglich (Standard-14-Fonts). */
    public const GROUP_CORE = 'core';

    /** Eingebettete Varianten für PDF/A — Voraussetzung für ZUGFeRD/Factur-X. */
    public const GROUP_PDFA = 'pdfa';

    /** Referenz-Font, dessen Fehlen die gesamte Gruppe unbrauchbar macht. */
    private const PROBE_FONT = 'helvetica.json';

    /**
     * Gibt den mitgelieferten Font-Pfad zurück.
     */
    public static function getLocalFontPath(): string {
        return dirname(__DIR__, 2) . '/data/fonts';
    }

    /**
     * Gibt den tatsächlich aktiven Font-Pfad zurück (K_PATH_FONTS, sonst den
     * mitgelieferten).
     */
    public static function getActiveFontPath(): string {
        if (defined('K_PATH_FONTS')) {
            // Leerprüfung bewusst defensiv: ein fremdes Projekt kann
            // K_PATH_FONTS auf '' definieren.
            $path = trim((string) constant('K_PATH_FONTS'));
            if ($path !== '') {
                return $path;
            }
        }

        return self::getLocalFontPath();
    }

    /**
     * Prüft, ob eine Font-Gruppe im aktiven Pfad nutzbar ist.
     */
    public static function hasGroup(string $group = self::GROUP_CORE): bool {
        $dir = self::getActiveFontPath() . '/' . $group;
        if (!Folder::exists($dir)) {
            return false;
        }

        return glob($dir . '/*.json') !== [];
    }

    /**
     * Prüft, ob die Fonts für die PDF-Erzeugung bereitstehen.
     *
     * Bei TCPDF 6 immer true — dort bringt das Paket eigene Fonts mit und
     * K_PATH_FONTS wird von diesem Toolkit bewusst nicht gesetzt.
     */
    public static function isAvailable(): bool {
        if (!class_exists(\Com\Tecnick\Pdf\Tcpdf::class)) {
            return true;
        }

        if (!self::hasGroup(self::GROUP_CORE)) {
            self::logError('TCPDF-7-Fontgruppe "core" fehlt in ' . self::getActiveFontPath());
            return false;
        }

        return is_readable(self::getActiveFontPath() . '/' . self::GROUP_CORE . '/' . self::PROBE_FONT);
    }

    /**
     * Prüft, ob PDF/A-fähige Fonts vorliegen (für ZUGFeRD/Factur-X).
     */
    public static function isPdfaAvailable(): bool {
        return !class_exists(\Com\Tecnick\Pdf\Tcpdf::class) || self::hasGroup(self::GROUP_PDFA);
    }
}
