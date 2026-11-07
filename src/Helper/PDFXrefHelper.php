<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFXrefHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use RuntimeException;

/**
 * Baut die klassische Querverweistabelle einer PDF mit genau einer Revision
 * aus einem sequenziellen Durchlauf neu auf.
 *
 * Anlass: tc-lib-pdf (Engine von TCPDF 7) sucht die Objektanfänge per Regex
 * im ganzen Puffer; der letzte Treffer gewinnt. Steht in einem unkomprimierten
 * Strom ein "N 0 obj" am Zeilenanfang - etwa in einer eingebetteten PDF, die
 * die Engine im PDF/A-3-Modus roh ablegt -, zeigt die Tabelle mitten in den
 * Strom und die Datei ist kaputt.
 *
 * Der Durchlauf nimmt Objektanfänge nur dort, wo das vorige Objekt endet, und
 * überspringt Ströme anhand ihrer direkten /Length. Der Rumpf bleibt Byte für
 * Byte gleich, damit auch startxref. Was diesem Format nicht folgt
 * (Querverweisstrom, mehrere Revisionen, indirekte oder falsche /Length,
 * Signatur), wird mit RuntimeException abgelehnt statt geraten.
 */
final class PDFXrefHelper {
    private const WHITESPACE = " \t\r\n\f\0";
    private const DELIMITERS = " \t\r\n\f\0()<>[]{}/%";

    /**
     * @throws RuntimeException wenn die Datei nicht dem erwarteten Format folgt
     */
    public static function rebuild(string $pdf): string {
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('Keine PDF-Datei');
        }

        $startxrefPos = strrpos($pdf, 'startxref');
        if ($startxrefPos === false || preg_match('/\Gstartxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $m, 0, $startxrefPos) !== 1) {
            throw new RuntimeException('startxref am Dateiende fehlt');
        }
        $xrefPos = (int) $m[1];
        if (preg_match('/\Gxref\r?\n/', $pdf, $unused, 0, $xrefPos) !== 1) {
            throw new RuntimeException('Keine klassische Querverweistabelle an startxref');
        }

        $trailerPos = strpos($pdf, 'trailer', $xrefPos);
        if ($trailerPos === false || $trailerPos > $startxrefPos) {
            throw new RuntimeException('Trailer fehlt');
        }
        $trailer = substr($pdf, $trailerPos, $startxrefPos - $trailerPos);
        if (preg_match('#/Prev\b#', $trailer) === 1 || preg_match('#/XRefStm\b#', $trailer) === 1) {
            throw new RuntimeException('Mehr als eine Revision');
        }
        if (preg_match('#/Size\s+(\d+)#', $trailer, $size) !== 1) {
            throw new RuntimeException('Trailer ohne /Size');
        }
        $size = (int) $size[1];

        $objects = self::scanObjects($pdf, $xrefPos);
        $table = "xref\n0 {$size}\n0000000000 65535 f \n";
        $freeGeneration = $size + 1;
        for ($number = 1; $number < $size; $number++) {
            $table .= isset($objects[$number])
                ? sprintf("%010d %05d n \n", $objects[$number][0], $objects[$number][1])
                : sprintf("0000000000 %05d f \n", $freeGeneration++);
        }
        foreach (array_keys($objects) as $number) {
            if ($number >= $size) {
                throw new RuntimeException("Objekt {$number} liegt außerhalb von /Size {$size}");
            }
        }

        return substr($pdf, 0, $xrefPos) . $table . substr($pdf, $trailerPos);
    }

    /**
     * Objektanfänge des Rumpfs in Dateireihenfolge.
     *
     * @return array<int, array{int, int}> Objektnummer => [Offset, Generation]
     */
    private static function scanObjects(string $pdf, int $end): array {
        $objects = [];
        $pos = 0;
        while (true) {
            $pos = self::skipWhitespaceAndComments($pdf, $pos, $end);
            if ($pos >= $end) {
                break;
            }
            if (preg_match('/\G(\d+) (\d+) obj(?=[' . preg_quote(self::DELIMITERS, '/') . '])/', $pdf, $m, 0, $pos) !== 1) {
                throw new RuntimeException("Kein Objektanfang bei Byte {$pos}");
            }
            $number = (int) $m[1];
            if (isset($objects[$number])) {
                throw new RuntimeException("Objekt {$number} kommt zweimal vor");
            }
            $objects[$number] = [$pos, (int) $m[2]];
            $pos = self::skipObject($pdf, $pos + strlen($m[0]), $end, $number);
        }

        return $objects;
    }

    /**
     * Liest ein Objekt bis hinter "endobj". Zeichenketten und Kommentare
     * werden übersprungen, Ströme anhand der direkten /Length im obersten
     * Wörterbuch.
     */
    private static function skipObject(string $pdf, int $pos, int $end, int $number): int {
        $depth = 0;
        $length = null;
        $lengthKey = false;

        while ($pos < $end) {
            $char = $pdf[$pos];

            if (str_contains(self::WHITESPACE, $char)) {
                $pos++;
                continue;
            }
            if ($char === '%') {
                $pos = self::skipLine($pdf, $pos, $end);
                continue;
            }
            if ($char === '(') {
                $pos = self::skipLiteralString($pdf, $pos, $end, $number);
                $lengthKey = false;
                continue;
            }
            if ($char === '<') {
                if (($pdf[$pos + 1] ?? '') === '<') {
                    $depth++;
                    $pos += 2;
                    continue;
                }
                $close = strpos($pdf, '>', $pos);
                if ($close === false || $close >= $end) {
                    throw new RuntimeException("Objekt {$number}: Hex-Zeichenkette ohne Ende");
                }
                $pos = $close + 1;
                $lengthKey = false;
                continue;
            }
            if ($char === '>') {
                if (($pdf[$pos + 1] ?? '') !== '>' || $depth === 0) {
                    throw new RuntimeException("Objekt {$number}: unerwartetes '>' bei Byte {$pos}");
                }
                $depth--;
                $pos += 2;
                $lengthKey = false;
                continue;
            }
            if ($char === '/') {
                preg_match('/\G\/[^' . preg_quote(self::DELIMITERS, '/') . ']*/', $pdf, $name, 0, $pos);
                $pos += strlen($name[0]);
                if ($name[0] === '/ByteRange') {
                    throw new RuntimeException("Objekt {$number}: Signatur (/ByteRange); eine neue Tabelle bräche sie");
                }
                $lengthKey = $depth === 1 && $name[0] === '/Length';
                continue;
            }
            if (preg_match('/\G[+-]?[0-9.]+/', $pdf, $numeric, 0, $pos) === 1) {
                if ($lengthKey) {
                    if (preg_match('/\G[0-9]+\s+[0-9]+\s+R(?=[' . preg_quote(self::DELIMITERS, '/') . '])/', $pdf, $unused, 0, $pos) === 1) {
                        throw new RuntimeException("Objekt {$number}: indirekte /Length");
                    }
                    $length = (int) $numeric[0];
                    $lengthKey = false;
                }
                $pos += strlen($numeric[0]);
                continue;
            }
            if (preg_match('/\G[A-Za-z]+/', $pdf, $keyword, 0, $pos) === 1) {
                $pos += strlen($keyword[0]);
                $lengthKey = false;
                if ($keyword[0] === 'endobj') {
                    if ($depth !== 0) {
                        throw new RuntimeException("Objekt {$number}: endobj in offenem Wörterbuch");
                    }
                    return $pos;
                }
                if ($keyword[0] === 'stream') {
                    $pos = self::skipStream($pdf, $pos, $end, $number, $length);
                }
                continue;
            }

            // [, ], { und }
            $pos++;
            $lengthKey = false;
        }

        throw new RuntimeException("Objekt {$number}: endobj fehlt");
    }

    private static function skipStream(string $pdf, int $pos, int $end, int $number, ?int $length): int {
        if ($length === null) {
            throw new RuntimeException("Objekt {$number}: Strom ohne direkte /Length");
        }
        // Nach "stream" folgt CRLF oder LF (ISO 32000-1, 7.3.8.1)
        if (substr($pdf, $pos, 2) === "\r\n") {
            $pos += 2;
        } elseif (($pdf[$pos] ?? '') === "\n") {
            $pos++;
        } else {
            throw new RuntimeException("Objekt {$number}: kein Zeilenende nach stream");
        }

        $pos += $length;
        if ($pos > $end || preg_match('/\G(?:\r\n|\r|\n)?endstream/', $pdf, $m, 0, $pos) !== 1) {
            throw new RuntimeException("Objekt {$number}: Strom endet nicht nach /Length {$length}");
        }

        return $pos + strlen($m[0]);
    }

    private static function skipLiteralString(string $pdf, int $pos, int $end, int $number): int {
        $nesting = 0;
        for (; $pos < $end; $pos++) {
            $char = $pdf[$pos];
            if ($char === '\\') {
                $pos++;
            } elseif ($char === '(') {
                $nesting++;
            } elseif ($char === ')' && --$nesting === 0) {
                return $pos + 1;
            }
        }

        throw new RuntimeException("Objekt {$number}: Zeichenkette ohne Ende");
    }

    private static function skipWhitespaceAndComments(string $pdf, int $pos, int $end): int {
        while ($pos < $end) {
            $pos += strspn($pdf, self::WHITESPACE, $pos, $end - $pos);
            if ($pos < $end && $pdf[$pos] === '%') {
                $pos = self::skipLine($pdf, $pos, $end);
                continue;
            }
            break;
        }

        return $pos;
    }

    private static function skipLine(string $pdf, int $pos, int $end): int {
        $pos += strcspn($pdf, "\r\n", $pos, $end - $pos);

        return $pos;
    }
}
