<?php
/*
 * Created on   : Tue Oct 13 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ParallelShellTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use CommonToolkit\Helper\Shell;
use PDFToolkit\Helper\ParallelShell;
use Symfony\Component\Process\Process;
use Tests\Contracts\BaseTestCase;

class ParallelShellTest extends BaseTestCase {
    public function test_liefert_exit_codes_je_schluessel(): void {
        $this->requireUnix();

        $codes = ParallelShell::run(['eins' => 'exit 0', 'zwei' => 'exit 2', 'drei' => 'exit 3'], 2);

        ksort($codes);
        $this->assertSame(['drei' => 3, 'eins' => 0, 'zwei' => 2], $codes);
        $this->assertSame([], ParallelShell::run([]));
    }

    public function test_zeitgrenze_beendet_haengende_seite_samt_kindern(): void {
        $this->requireUnix();
        if (!is_dir('/proc/self') || !$this->hasGnuTimeout()) {
            $this->markTestSkipped('Braucht /proc und GNU timeout.');
        }
        $marker = sprintf('sleep %d.%04d', 40 + random_int(0, 9), random_int(0, 9999));
        $started = microtime(true);

        $codes = ParallelShell::run(['fertig' => 'exit 0', 'haengt' => "$marker | cat"], 2, 0.5);

        $this->assertSame(0, $codes['fertig']);
        $this->assertSame(Shell::EXIT_TIMEOUT, $codes['haengt']);
        $this->assertLessThan(3.0, microtime(true) - $started);

        $deadline = microtime(true) + 2.0;
        while ($this->processesWith($marker) !== [] && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertSame([], $this->processesWith($marker), 'Nach dem Abbruch darf nichts weiterlaufen.');
    }

    private function requireUnix(): void {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Die Befehle sind sh-Syntax.');
        }
    }

    private function hasGnuTimeout(): bool {
        $probe = new Process(['timeout', '--version']);
        $probe->run();

        return $probe->isSuccessful() && str_contains($probe->getOutput(), 'coreutils');
    }

    /**
     * @return list<string>
     */
    private function processesWith(string $marker): array {
        $found = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmdline = @file_get_contents($file);
            if (is_string($cmdline) && str_contains(str_replace("\0", ' ', $cmdline), $marker)) {
                $found[] = $cmdline;
            }
        }

        return $found;
    }
}
