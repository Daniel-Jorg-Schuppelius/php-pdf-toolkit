<?php
/*
 * Created on   : Tue Oct 13 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TesseractReaderTimeoutTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Readers;

use Symfony\Component\Process\Process;
use Tests\Contracts\BaseTestCase;

/**
 * Ein abgebrochener Werkzeuglauf (Zeitgrenze, Exit 124) gilt als Fehler: Das
 * Deskew-Bild ist dann womöglich nur halb geschrieben und taugt nicht für die
 * OCR. Geprüft mit einer mogrify-Attrappe in einem eigenen PHP-Prozess (die
 * Konfiguration löst Werkzeuge beim ersten Zugriff über PATH auf).
 */
class TesseractReaderTimeoutTest extends BaseTestCase {
    private string $workDir = '';

    protected function setUp(): void {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Die Attrappe ist ein sh-Skript.');
        }

        $this->workDir = sys_get_temp_dir() . '/pdftk-deskew-' . bin2hex(random_bytes(4));
        mkdir($this->workDir . '/bin', 0700, true);
        // Seite 2 meldet, was Shell bei einer Zeitueberschreitung meldet
        file_put_contents($this->workDir . '/bin/mogrify', "#!/bin/sh\ncase \"\$*\" in *seite-2*) exit 124;; esac\nexit 0\n");
        chmod($this->workDir . '/bin/mogrify', 0700);
        file_put_contents($this->workDir . '/seite-1.png', '');
        file_put_contents($this->workDir . '/seite-2.png', '');
    }

    protected function tearDown(): void {
        if ($this->workDir !== '') {
            (new Process(['rm', '-rf', $this->workDir]))->run();
        }
        parent::tearDown();
    }

    public function test_deskew_meldet_abgebrochene_seite_als_unbrauchbar(): void {
        $this->assertFalse($this->deskew($this->workDir . '/seite-1.png', $this->workDir . '/seite-2.png'));
    }

    public function test_deskew_ohne_abbruch_bleibt_brauchbar(): void {
        $this->assertTrue($this->deskew($this->workDir . '/seite-1.png'));
    }

    private function deskew(string ...$pages): bool {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__) . '/scripts/deskew_probe.php', ...$pages],
            null,
            ['PATH' => $this->workDir . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin')],
            null,
            60.0,
        );
        $process->run();

        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
        $decoded = json_decode((string) end($lines), true);
        $this->assertIsArray($decoded, "Probe ohne JSON-Ergebnis (Exit {$process->getExitCode()}): " . $process->getOutput() . $process->getErrorOutput());

        return (bool) $decoded['usable'];
    }
}
