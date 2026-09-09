<?php
/*
 * Created on   : Tue Sep 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SequenceAligner.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

/**
 * Gebändertes LCS-Alignment über Skalar-Token-Arrays (int|string).
 *
 * Liefert difflib-artige Opcodes (equal/replace/delete/insert, Ranges
 * end-exklusiv) für zwei Token-Folgen. Gerechnet wird nur in einem Band um
 * die Hauptdiagonale: Pfade außerhalb gelten als unerreichbar. Das begrenzt
 * Laufzeit und Speicher auf O(n·band) — bei stark auseinanderlaufenden
 * Folgen desynchronisiert das Alignment kontrolliert (weniger equal-Runs),
 * was der Aufrufer über seine Qualitäts-Gates abfängt.
 *
 * @internal Baustein des Cipher-Layer-Rebuilds ({@see CipherLayerSolver}).
 */
final class SequenceAligner {
    public const DEFAULT_BAND = 256;

    /** Wert für Zellen außerhalb des Bandes (Pfad dorthin verboten). */
    private const FORBIDDEN = -1000000000;

    /**
     * @param list<int|string> $a
     * @param list<int|string> $b
     * @return list<array{tag: 'equal'|'replace'|'delete'|'insert', i1: int, i2: int, j1: int, j2: int}>
     */
    public static function opcodes(array $a, array $b, int $band = self::DEFAULT_BAND): array {
        $n = count($a);
        $m = count($b);
        if ($n === 0 && $m === 0) {
            return [];
        }
        if ($n === 0) {
            return [['tag' => 'insert', 'i1' => 0, 'i2' => 0, 'j1' => 0, 'j2' => $m]];
        }
        if ($m === 0) {
            return [['tag' => 'delete', 'i1' => 0, 'i2' => $n, 'j1' => 0, 'j2' => 0]];
        }

        // Gemeinsames Präfix/Suffix kostet im DP nur Fläche — vorab abschneiden.
        $prefix = 0;
        $max = min($n, $m);
        while ($prefix < $max && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $max - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }

        $core = self::alignCore(
            array_slice($a, $prefix, $n - $prefix - $suffix),
            array_slice($b, $prefix, $m - $prefix - $suffix),
            max($band, abs($n - $m) + 32),
        );

        $ops = [];
        if ($prefix > 0) {
            $ops[] = ['tag' => 'equal', 'i1' => 0, 'i2' => $prefix, 'j1' => 0, 'j2' => $prefix];
        }
        foreach ($core as $op) {
            $op['i1'] += $prefix;
            $op['i2'] += $prefix;
            $op['j1'] += $prefix;
            $op['j2'] += $prefix;
            $ops[] = $op;
        }
        if ($suffix > 0) {
            $ops[] = ['tag' => 'equal', 'i1' => $n - $suffix, 'i2' => $n, 'j1' => $m - $suffix, 'j2' => $m];
        }

        return self::mergeOps($ops);
    }

    /**
     * Banded-DP über die (präfix-/suffixfreien) Kernfolgen.
     *
     * @param list<int|string> $a
     * @param list<int|string> $b
     * @return list<array{tag: 'equal'|'replace'|'delete'|'insert', i1: int, i2: int, j1: int, j2: int}>
     */
    private static function alignCore(array $a, array $b, int $band): array {
        $n = count($a);
        $m = count($b);
        if ($n === 0 && $m === 0) {
            return [];
        }
        if ($n === 0) {
            return [['tag' => 'insert', 'i1' => 0, 'i2' => 0, 'j1' => 0, 'j2' => $m]];
        }
        if ($m === 0) {
            return [['tag' => 'delete', 'i1' => 0, 'i2' => $n, 'j1' => 0, 'j2' => 0]];
        }

        // Richtungs-Tabelle: je Zeile ein 1-Byte-String über das Fenster
        // ('D' diagonal, 'U' hoch = delete aus a, 'L' links = insert aus b).
        /** @var array<int, string> $dirs */
        $dirs = [];
        /** @var array<int, int> $windowLo */
        $windowLo = [];

        $prev = array_fill(0, $m + 1, 0); // Zeile i=0: LCS-Länge 0 überall
        $prevLo = 0;
        $prevHi = $m;

        for ($i = 1; $i <= $n; $i++) {
            $center = (int) round($i * $m / $n);
            $lo = max(1, $center - $band);
            $hi = min($m, $center + $band);
            $windowLo[$i] = $lo;
            $row = str_repeat('L', $hi - $lo + 1);
            $curr = [];
            $ai = $a[$i - 1];

            for ($j = $lo; $j <= $hi; $j++) {
                $diag = ($j - 1 >= $prevLo - 1 && $j - 1 <= $prevHi) ? ($prev[$j - 1] ?? self::FORBIDDEN) : self::FORBIDDEN;
                $up = ($j >= $prevLo - 1 && $j <= $prevHi) ? ($prev[$j] ?? self::FORBIDDEN) : self::FORBIDDEN;
                $left = $curr[$j - 1] ?? ($j - 1 === 0 ? 0 : self::FORBIDDEN);

                if ($ai === $b[$j - 1] && $diag !== self::FORBIDDEN) {
                    // Ein Treffer auf der Diagonale ist bei LCS immer optimal:
                    // Nachbarpfade können höchstens gleich lang werden.
                    $score = $diag + 1;
                    $dir = 'D';
                } elseif ($up >= $left) {
                    $score = $up;
                    $dir = 'U';
                } else {
                    $score = $left;
                    $dir = 'L';
                }

                $curr[$j] = $score;
                $row[$j - $lo] = $dir;
            }

            $dirs[$i] = $row;
            $prev = $curr;
            $prev[0] = 0; // Spalte 0 bleibt erreichbar (reine Deletes)
            $prevLo = $lo;
            $prevHi = $hi;
        }

        // Backtracking von (n, m).
        $steps = [];
        $i = $n;
        $j = $m;
        while ($i > 0 && $j > 0) {
            $lo = $windowLo[$i];
            $dir = ($j >= $lo && $j - $lo < strlen($dirs[$i])) ? $dirs[$i][$j - $lo] : 'U';
            if ($dir === 'D') {
                $steps[] = 'E';
                $i--;
                $j--;
            } elseif ($dir === 'U') {
                $steps[] = 'X';
                $i--;
            } else {
                $steps[] = 'Y';
                $j--;
            }
        }
        while ($i > 0) {
            $steps[] = 'X';
            $i--;
        }
        while ($j > 0) {
            $steps[] = 'Y';
            $j--;
        }
        $steps = array_reverse($steps);

        // Schritte zu Ranged-Ops einsammeln.
        $ops = [];
        $i = 0;
        $j = 0;
        foreach ($steps as $step) {
            $tag = match ($step) {
                'E' => 'equal',
                'X' => 'delete',
                default => 'insert',
            };
            $di = $step === 'Y' ? 0 : 1;
            $dj = $step === 'X' ? 0 : 1;
            $last = $ops === [] ? null : array_key_last($ops);
            if ($last !== null && $ops[$last]['tag'] === $tag) {
                $ops[$last]['i2'] += $di;
                $ops[$last]['j2'] += $dj;
            } else {
                $ops[] = ['tag' => $tag, 'i1' => $i, 'i2' => $i + $di, 'j1' => $j, 'j2' => $j + $dj];
            }
            $i += $di;
            $j += $dj;
        }

        return $ops;
    }

    /**
     * Verschmilzt benachbarte gleichartige Ops und macht aus angrenzenden
     * delete+insert-Läufen ein 'replace' (Längen dürfen differieren — der
     * Aufrufer prüft sie bei Bedarf selbst).
     *
     * @param list<array{tag: string, i1: int, i2: int, j1: int, j2: int}> $ops
     * @return list<array{tag: 'equal'|'replace'|'delete'|'insert', i1: int, i2: int, j1: int, j2: int}>
     */
    private static function mergeOps(array $ops): array {
        $merged = [];
        foreach ($ops as $op) {
            $last = $merged === [] ? null : array_key_last($merged);
            if ($last !== null && $merged[$last]['tag'] === $op['tag']
                && $merged[$last]['i2'] === $op['i1'] && $merged[$last]['j2'] === $op['j1']) {
                $merged[$last]['i2'] = $op['i2'];
                $merged[$last]['j2'] = $op['j2'];
                continue;
            }
            if ($last !== null && $op['tag'] !== 'equal' && $merged[$last]['tag'] !== 'equal'
                && $merged[$last]['tag'] !== $op['tag']
                && $merged[$last]['i2'] === $op['i1'] && $merged[$last]['j2'] === $op['j1']) {
                $merged[$last]['tag'] = 'replace';
                $merged[$last]['i2'] = $op['i2'];
                $merged[$last]['j2'] = $op['j2'];
                continue;
            }
            $merged[] = $op;
        }

        /** @var list<array{tag: 'equal'|'replace'|'delete'|'insert', i1: int, i2: int, j1: int, j2: int}> $merged */
        return $merged;
    }
}
