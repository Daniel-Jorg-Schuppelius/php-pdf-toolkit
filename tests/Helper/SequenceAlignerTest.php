<?php
/*
 * Created on   : Tue Sep 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SequenceAlignerTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\SequenceAligner;
use PHPUnit\Framework\TestCase;

class SequenceAlignerTest extends TestCase {
    /**
     * Rekonstruiert beide Folgen aus den Opcodes — jede gültige Ausgabe muss
     * die Eingaben lückenlos und überschneidungsfrei abdecken.
     *
     * @param list<int|string> $a
     * @param list<int|string> $b
     * @param list<array{tag: string, i1: int, i2: int, j1: int, j2: int}> $ops
     */
    private function assertOpsCover(array $a, array $b, array $ops): void {
        $i = 0;
        $j = 0;
        foreach ($ops as $op) {
            $this->assertSame($i, $op['i1'], 'Lücke/Überlappung in a');
            $this->assertSame($j, $op['j1'], 'Lücke/Überlappung in b');
            $this->assertGreaterThanOrEqual($op['i1'], $op['i2']);
            $this->assertGreaterThanOrEqual($op['j1'], $op['j2']);
            if ($op['tag'] === 'equal') {
                $this->assertSame($op['i2'] - $op['i1'], $op['j2'] - $op['j1']);
                for ($k = 0; $k < $op['i2'] - $op['i1']; $k++) {
                    $this->assertSame($a[$op['i1'] + $k], $b[$op['j1'] + $k], 'equal-Run mit ungleichen Tokens');
                }
            }
            $i = $op['i2'];
            $j = $op['j2'];
        }
        $this->assertSame(count($a), $i);
        $this->assertSame(count($b), $j);
    }

    public function test_identical_sequences_yield_single_equal(): void {
        $a = [3, 1, 4, 1, 5, 9, 2, 6];
        $ops = SequenceAligner::opcodes($a, $a);
        $this->assertCount(1, $ops);
        $this->assertSame('equal', $ops[0]['tag']);
        $this->assertOpsCover($a, $a, $ops);
    }

    public function test_empty_inputs(): void {
        $this->assertSame([], SequenceAligner::opcodes([], []));

        $ops = SequenceAligner::opcodes([], [1, 2]);
        $this->assertSame([['tag' => 'insert', 'i1' => 0, 'i2' => 0, 'j1' => 0, 'j2' => 2]], $ops);

        $ops = SequenceAligner::opcodes(['x'], []);
        $this->assertSame([['tag' => 'delete', 'i1' => 0, 'i2' => 1, 'j1' => 0, 'j2' => 0]], $ops);
    }

    public function test_insert_and_delete_detected(): void {
        $a = ['der', 'schnelle', 'braune', 'fuchs'];
        $b = ['der', 'braune', 'fuchs', 'springt'];
        $ops = SequenceAligner::opcodes($a, $b);
        $this->assertOpsCover($a, $b, $ops);
        $tags = array_column($ops, 'tag');
        $this->assertContains('delete', $tags);   // "schnelle"
        $this->assertContains('insert', $tags);   // "springt"
        $equalTokens = 0;
        foreach ($ops as $op) {
            if ($op['tag'] === 'equal') {
                $equalTokens += $op['i2'] - $op['i1'];
            }
        }
        $this->assertSame(3, $equalTokens);
    }

    public function test_adjacent_delete_insert_becomes_replace(): void {
        $a = ['a', 'FALSCH', 'c'];
        $b = ['a', 'richtig', 'c'];
        $ops = SequenceAligner::opcodes($a, $b);
        $this->assertOpsCover($a, $b, $ops);
        $this->assertSame(['equal', 'replace', 'equal'], array_column($ops, 'tag'));
        $this->assertSame(1, $ops[1]['i2'] - $ops[1]['i1']);
        $this->assertSame(1, $ops[1]['j2'] - $ops[1]['j1']);
    }

    public function test_length_mismatch_band_widening(): void {
        // b = a plus 100 eingeschobene Tokens am Anfang: ohne Band-Weitung
        // (band >= |n-m|+32) wäre das Ende unerreichbar.
        $a = range(1000, 1200);
        $b = [...array_fill(0, 100, -1), ...$a];
        $ops = SequenceAligner::opcodes($a, $b, 8);
        $this->assertOpsCover($a, $b, $ops);
        $equalTokens = 0;
        foreach ($ops as $op) {
            if ($op['tag'] === 'equal') {
                $equalTokens += $op['i2'] - $op['i1'];
            }
        }
        $this->assertSame(count($a), $equalTokens);
    }

    public function test_string_tokens_with_multibyte(): void {
        $a = ['Über', 'wei', 'sung'];
        $b = ['Über', 'xyz', 'sung'];
        $ops = SequenceAligner::opcodes($a, $b);
        $this->assertOpsCover($a, $b, $ops);
        $this->assertSame(['equal', 'replace', 'equal'], array_column($ops, 'tag'));
    }

    public function test_determinism(): void {
        $a = [1, 2, 1, 2, 1, 2, 3, 1, 2];
        $b = [2, 1, 2, 1, 3, 2, 1];
        $first = SequenceAligner::opcodes($a, $b);
        $this->assertOpsCover($a, $b, $first);
        for ($run = 0; $run < 3; $run++) {
            $this->assertSame($first, SequenceAligner::opcodes($a, $b));
        }
    }
}
