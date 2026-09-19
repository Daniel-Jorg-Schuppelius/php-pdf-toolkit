<?php
/*
 * Created on   : Sat Sep 20 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GlyphNameLayer.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;
use PDFToolkit\Entities\GlyphLayerResult;
use XMLReader;

/**
 * Baut die Textebene aus den GLYPHENNAMEN der eingebetteten Fonts auf.
 *
 * Hintergrund: Bei einer defekten ToUnicode-Tabelle liefert jeder Text-Reader
 * Zeichensalat, weil er genau diese Tabelle befragt. Der Glyphenname im Font
 * ("three", "period", "adieresis") bleibt davon unberührt — er benennt das
 * Zeichen, das der Setzer gemeint hat. mutool gibt beides nebeneinander aus:
 *
 *     <g unicode="P" glyph="three" x="375.591" y="558.63" adv=".556"/>
 *
 * "P" ist die Lüge der ToUnicode-Tabelle, "three" die Wahrheit des Fonts.
 *
 * Damit ist die Rekonstruktion exakt und deterministisch — anders als der
 * statistische {@see CipherLayerSolver}, der dieselbe Aufgabe über OCR-
 * Mehrheiten löst und an mehrdeutigen Glyphen scheitert (dieselbe Glyphe
 * "b" kann in einer Datei sowohl für "E" als auch für "b" stehen). Der
 * Solver bleibt für Dateien ohne brauchbare Glyphennamen (reine Subsets mit
 * "g17"/"cid42") die Rückfallebene.
 *
 * Zeilen und Spalten entstehen aus den Koordinaten: Glyphen gleicher
 * Grundlinie bilden eine Zeile, Lücken zwischen ihnen werden in Leerzeichen
 * umgerechnet — die Spaltenausrichtung von Kontoauszügen bleibt erhalten.
 */
final class GlyphNameLayer {
    use ErrorLog;

    /** Ab dieser Lücke (Anteil der Schriftgröße) steht ein Leerzeichen. */
    private const SPACE_GAP_RATIO = 0.2;

    /** Breite eines Leerzeichens (Anteil der Schriftgröße) für die Spaltenrasterung. */
    private const SPACE_WIDTH_RATIO = 0.5;

    /** Glyphen derselben Grundlinie bilden eine Zeile (Rundung in Punkt). */
    private const LINE_PRECISION = 1;

    /**
     * Mehr unbenennbare Glyphen heissen: Der Font traegt keine sprechenden
     * Namen (reines Subset), die Rekonstruktion waere geraten.
     */
    private const MAX_UNKNOWN_SHARE = 0.05;

    /** Adobe-Glyphennamen, die nicht schon das Zeichen selbst sind. */
    private const NAMED_GLYPHS = [
        'space' => ' ', 'exclam' => '!', 'quotedbl' => '"', 'numbersign' => '#', 'dollar' => '$',
        'percent' => '%', 'ampersand' => '&', 'quotesingle' => "'", 'parenleft' => '(', 'parenright' => ')',
        'asterisk' => '*', 'plus' => '+', 'comma' => ',', 'hyphen' => '-', 'period' => '.', 'slash' => '/',
        'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4',
        'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9',
        'colon' => ':', 'semicolon' => ';', 'less' => '<', 'equal' => '=', 'greater' => '>',
        'question' => '?', 'at' => '@', 'bracketleft' => '[', 'backslash' => '\\', 'bracketright' => ']',
        'asciicircum' => '^', 'underscore' => '_', 'grave' => '`', 'braceleft' => '{', 'bar' => '|',
        'braceright' => '}', 'asciitilde' => '~',
        'adieresis' => 'ä', 'odieresis' => 'ö', 'udieresis' => 'ü',
        'Adieresis' => 'Ä', 'Odieresis' => 'Ö', 'Udieresis' => 'Ü',
        'germandbls' => 'ß', 'Euro' => '€', 'euro' => '€', 'sterling' => '£', 'yen' => '¥', 'cent' => '¢',
        'section' => '§', 'paragraph' => '¶', 'degree' => '°', 'plusminus' => '±', 'multiply' => '×',
        'divide' => '÷', 'copyright' => '©', 'registered' => '®', 'trademark' => '™',
        'ordfeminine' => 'ª', 'ordmasculine' => 'º', 'onequarter' => '¼', 'onehalf' => '½',
        'threequarters' => '¾', 'periodcentered' => '·', 'middot' => '·',
        'agrave' => 'à', 'aacute' => 'á', 'acircumflex' => 'â', 'atilde' => 'ã', 'aring' => 'å',
        'ae' => 'æ', 'ccedilla' => 'ç', 'egrave' => 'è', 'eacute' => 'é', 'ecircumflex' => 'ê',
        'edieresis' => 'ë', 'igrave' => 'ì', 'iacute' => 'í', 'icircumflex' => 'î', 'idieresis' => 'ï',
        'ntilde' => 'ñ', 'ograve' => 'ò', 'oacute' => 'ó', 'ocircumflex' => 'ô', 'otilde' => 'õ',
        'oslash' => 'ø', 'ugrave' => 'ù', 'uacute' => 'ú', 'ucircumflex' => 'û', 'yacute' => 'ý',
        'ydieresis' => 'ÿ', 'thorn' => 'þ', 'eth' => 'ð',
        'Agrave' => 'À', 'Aacute' => 'Á', 'Acircumflex' => 'Â', 'Atilde' => 'Ã', 'Aring' => 'Å',
        'AE' => 'Æ', 'Ccedilla' => 'Ç', 'Egrave' => 'È', 'Eacute' => 'É', 'Ecircumflex' => 'Ê',
        'Edieresis' => 'Ë', 'Igrave' => 'Ì', 'Iacute' => 'Í', 'Icircumflex' => 'Î', 'Idieresis' => 'Ï',
        'Ntilde' => 'Ñ', 'Ograve' => 'Ò', 'Oacute' => 'Ó', 'Ocircumflex' => 'Ô', 'Otilde' => 'Õ',
        'Oslash' => 'Ø', 'Ugrave' => 'Ù', 'Uacute' => 'Ú', 'Ucircumflex' => 'Û', 'Yacute' => 'Ý',
        'endash' => '-', 'emdash' => '-', 'hyphenminus' => '-', 'minus' => '-',
        'quoteright' => "'", 'quoteleft' => "'", 'quotedblleft' => '"', 'quotedblright' => '"',
        'quotesinglbase' => ',', 'quotedblbase' => '"', 'guillemotleft' => '«', 'guillemotright' => '»',
        'guilsinglleft' => '<', 'guilsinglright' => '>', 'bullet' => '*', 'ellipsis' => '...',
        'fi' => 'fi', 'fl' => 'fl', 'dagger' => '+', 'perthousand' => '%%',
    ];

    /**
     * Rekonstruiert die Textebene einer PDF-Datei aus den Glyphennamen.
     *
     * @param string $pdfPath Pfad zur PDF-Datei.
     * @return GlyphLayerResult|null null, wenn mutool fehlt, die Datei keine
     *         Textglyphen traegt oder zu viele Glyphen unbenannt sind.
     */
    public static function build(string $pdfPath): ?GlyphLayerResult {
        $traceFile = self::trace($pdfPath);
        if ($traceFile === null) {
            return null;
        }

        try {
            return self::fromTraceFile($traceFile, $pdfPath);
        } finally {
            File::delete($traceFile);
        }
    }

    /**
     * Ob die Rekonstruktion ueberhaupt moeglich ist (mutool konfiguriert).
     */
    public static function isAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-trace');
    }

    /** Schreibt den Glyph-Trace nach XML; null, wenn mutool fehlt oder scheitert. */
    private static function trace(string $pdfPath): ?string {
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('mutool-trace')) {
            self::logDebug('mutool-trace ist nicht konfiguriert - Glyphennamen-Ebene nicht verfuegbar');

            return null;
        }

        $traceFile = sys_get_temp_dir() . '/glyphtrace_' . uniqid() . '.xml';
        $command = $config->buildCommand('mutool-trace', [
            '[PDF-FILE]' => $pdfPath,
            '[TRACE-FILE]' => $traceFile,
        ]);
        if ($command === null) {
            return null;
        }

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0 || !File::exists($traceFile)) {
            self::logDebug("mutool-trace fehlgeschlagen (Code $returnCode) fuer: $pdfPath");
            File::delete($traceFile);

            return null;
        }

        return $traceFile;
    }

    /**
     * Baut die Textebene aus einem bereits vorliegenden Trace (mutool draw
     * -F trace). Liest stromweise: eine 100-Seiten-Datei ergibt zweistellige
     * Megabyte XML, die nie vollstaendig im Speicher stehen sollen.
     *
     * @param string $traceFile Pfad zur Trace-XML.
     * @param string $pdfPath Nur fuer die Protokollzeile.
     */
    public static function fromTraceFile(string $traceFile, string $pdfPath = ''): ?GlyphLayerResult {
        $reader = new XMLReader;
        if (@$reader->open($traceFile, 'UTF-8', LIBXML_NOERROR | LIBXML_NOWARNING) === false) {
            return null;
        }

        /** @var array<int, array<string, list<array{float, string, float, float}>>> $pages */
        $pages = [];
        $page = 0;
        $size = 10.0;
        $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $glyphs = 0;
        $unknown = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            switch ($reader->name) {
                case 'page':
                    $page++;
                    break;
                case 'fill_text':
                case 'clip_text':
                    // Die Seitenmatrix spiegelt die y-Achse; ohne sie stuenden
                    // die Zeilen auf dem Kopf.
                    $parts = preg_split('/\s+/', trim((string) $reader->getAttribute('transform'))) ?: [];
                    if (count($parts) === 6) {
                        $matrix = array_map('floatval', $parts);
                    }
                    break;
                case 'span':
                    // trm = Textmatrix; ihr erster Wert ist die Schriftgroesse.
                    $trm = preg_split('/\s+/', trim((string) $reader->getAttribute('trm'))) ?: [];
                    $candidate = isset($trm[0]) ? abs((float) $trm[0]) : 0.0;
                    $size = $candidate > 0.0 ? $candidate : 10.0;
                    break;
                case 'g':
                    $glyphs++;
                    $name = (string) $reader->getAttribute('glyph');
                    $char = self::charForGlyphName($name);
                    if ($char === null) {
                        $unknown++;
                        // Besser die Luege der ToUnicode-Tabelle als ein Loch:
                        // der Aufrufer sieht den Anteil im Ergebnis.
                        $char = (string) $reader->getAttribute('unicode');
                    }
                    if ($char === '') {
                        break;
                    }

                    $rawX = (float) $reader->getAttribute('x');
                    $rawY = (float) $reader->getAttribute('y');
                    $x = $matrix[0] * $rawX + $matrix[2] * $rawY + $matrix[4];
                    $y = $matrix[1] * $rawX + $matrix[3] * $rawY + $matrix[5];
                    $advance = (float) $reader->getAttribute('adv') * $size;

                    $pages[max($page, 1)][(string) round($y, self::LINE_PRECISION)][] = [$x, $char, $advance, $size];
                    break;
            }
        }
        $reader->close();

        if ($glyphs === 0) {
            self::logDebug("Kein Textglyph im Trace von: $pdfPath");

            return null;
        }

        $unknownShare = $unknown / $glyphs;
        if ($unknownShare > self::MAX_UNKNOWN_SHARE) {
            self::logInfo(sprintf(
                'Glyphennamen-Ebene verworfen: %.1f%% der %d Glyphen tragen keinen sprechenden Namen: %s',
                $unknownShare * 100,
                $glyphs,
                $pdfPath
            ));

            return null;
        }

        $text = self::render($pages);

        return trim($text) === '' ? null : new GlyphLayerResult($text, $glyphs, $unknown);
    }

    /**
     * @param array<int, array<string, list<array{float, string, float, float}>>> $pages
     */
    private static function render(array $pages): string {
        ksort($pages);
        $lines = [];

        foreach ($pages as $rows) {
            $ordered = [];
            foreach ($rows as $y => $glyphs) {
                $ordered[] = [(float) $y, $glyphs];
            }
            usort($ordered, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

            foreach ($ordered as [, $glyphs]) {
                usort($glyphs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
                $lines[] = rtrim(self::renderLine($glyphs));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{float, string, float, float}> $glyphs
     */
    private static function renderLine(array $glyphs): string {
        $line = '';
        $cursor = null;

        foreach ($glyphs as [$x, $char, $advance, $size]) {
            if ($cursor !== null) {
                $gap = $x - $cursor;
                if ($gap > self::SPACE_GAP_RATIO * $size) {
                    $line .= str_repeat(' ', max(1, (int) round($gap / (self::SPACE_WIDTH_RATIO * $size))));
                }
            }
            $line .= $char;
            $cursor = $x + $advance;
        }

        return $line;
    }

    /**
     * Glyphenname -> Zeichen. Einzelzeichen-Namen ("A", "7") stehen fuer sich
     * selbst, benannte Glyphen stehen in der Tabelle, und "uniXXXX"/"uXXXXXX"
     * tragen den Codepoint im Namen. Alles andere ("g17", "cid42") ist kein
     * Name, sondern eine Nummer - dafuer gibt es keine Wahrheit im Font.
     */
    private static function charForGlyphName(string $name): ?string {
        if ($name === '') {
            return null;
        }
        if (isset(self::NAMED_GLYPHS[$name])) {
            return self::NAMED_GLYPHS[$name];
        }
        if (mb_strlen($name, 'UTF-8') === 1) {
            return $name;
        }
        if (preg_match('/^uni([0-9A-Fa-f]{4})$/', $name, $match) === 1) {
            return mb_chr((int) hexdec($match[1]), 'UTF-8') ?: null;
        }
        if (preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $match) === 1) {
            return mb_chr((int) hexdec($match[1]), 'UTF-8') ?: null;
        }

        return null;
    }
}
