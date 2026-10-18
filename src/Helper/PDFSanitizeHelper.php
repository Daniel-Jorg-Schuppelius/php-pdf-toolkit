<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFSanitizeHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * PDF bereinigen, bevor sie das Haus verlässt: Formularfelder und
 * Anmerkungen einbrennen (Ghostscript, {@see flatten()}) oder Anmerkungen,
 * Verknüpfungen, Formulare, JavaScript und eingebettete Dateien entfernen
 * (data/mupdf/pdf-sanitize.js, {@see sanitize()}). Metadaten entfernt
 * {@see PDFMetadataHelper::clear()}.
 */
final class PDFSanitizeHelper {
    use ErrorLog;

    /** Kommentare, Stempel, Markierungen (nicht: Verknüpfungen, Felder, Dateianhänge) */
    public const REMOVE_ANNOTATIONS = 'annots';
    /** Verknüpfungen */
    public const REMOVE_LINKS = 'links';
    /** Formularfelder samt AcroForm; ohne vorheriges Einbrennen verschwinden die Werte */
    public const REMOVE_FORMS = 'forms';
    /** JavaScript und Aktionen (Names/JavaScript, OpenAction, AA an Dokument, Seiten, Anmerkungen, Feldern) */
    public const REMOVE_JAVASCRIPT = 'javascript';
    /** Eingebettete Dateien und Dateianhang-Anmerkungen */
    public const REMOVE_ATTACHMENTS = 'attachments';

    public const REMOVALS = [self::REMOVE_ANNOTATIONS, self::REMOVE_LINKS, self::REMOVE_FORMS, self::REMOVE_JAVASCRIPT, self::REMOVE_ATTACHMENTS];

    /**
     * Brennt Formularfelder und Anmerkungen in den Seiteninhalt ein
     * (Ghostscript pdfwrite ohne PreserveAnnots): Was der Leser sieht, bleibt
     * sichtbar, ist aber nicht mehr änderbar. Text bleibt Text.
     */
    public static function flatten(string $inputPath, string $outputPath): bool {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('gs-flatten')) {
            self::logError('Ghostscript ist nicht konfiguriert oder nicht verfügbar (gs-flatten)');
            return false;
        }
        $command = $config->buildCommand('gs-flatten', ['[INPUT]' => $inputPath, '[OUTPUT]' => $outputPath]);
        if ($command === null) {
            self::logError('Konnte gs-flatten-Befehl nicht erstellen');
            return false;
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode, timeout: ToolTimeout::forSize($inputPath)) || $returnCode !== 0 || !File::exists($outputPath)) {
            self::logError('Ghostscript (Einbrennen) fehlgeschlagen', ['returnCode' => $returnCode, 'output' => implode("\n", $output)]);
            return false;
        }

        self::logInfo('PDF-Formulare und Anmerkungen eingebrannt', ['input' => $inputPath]);

        return true;
    }

    /**
     * Entfernt die genannten Bestandteile; der Seiteninhalt bleibt.
     *
     * @param list<string> $removals Werte aus {@see REMOVALS}
     * @return int|null Seitenzahl der Datei, null bei Fehler
     */
    public static function sanitize(string $inputPath, string $outputPath, array $removals): ?int {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return null;
        }
        $what = [];
        foreach ($removals as $removal) {
            if (!in_array($removal, self::REMOVALS, true)) {
                self::logError('Unbekannter Bereinigungsschritt', ['removal' => $removal]);
                return null;
            }
            $what[$removal] = true;
        }
        if ($what === []) {
            self::logError('Nichts zu bereinigen');
            return null;
        }

        $pages = PDFPageHelper::runScript('mutool-pdf-sanitize', 'pdf-sanitize.js', [
            '[INPUT]' => $inputPath,
            '[OUTPUT]' => $outputPath,
            '[WHAT]' => implode(',', array_keys($what)),
        ]);
        if ($pages === null || !File::exists($outputPath)) {
            return null;
        }

        self::logInfo('PDF bereinigt', ['input' => $inputPath, 'removed' => array_keys($what), 'pages' => $pages]);

        return $pages;
    }

    public static function isAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-pdf-sanitize');
    }

    public static function isFlattenAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('gs-flatten');
    }
}
