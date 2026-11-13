<?php
/*
 * Created on   : Fri Nov 13 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ConfinedToolTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use CommonToolkit\Helper\FileSystem\Folder;
use PDFToolkit\Helper\{ConfinedTool, PDFCropHelper};
use Tests\Contracts\BaseTestCase;

/**
 * Ubuntu (ab 25.10) sperrt /usr/bin/gs per AppArmor auf /tmp und /var/tmp.
 * Auf dem Server scheiterte jeder Zuschnitt am TMPDIR der Site
 * (/var/www/clients/.../tmp): "Could not open temporary file" (13.11.2026).
 */
final class ConfinedToolTest extends BaseTestCase {
    private const SAMPLE_PDF = __DIR__ . '/../../.samples/PDF/test-a4-text.pdf';

    private string $tempDir;

    /** Verzeichnis ausserhalb von /tmp, wie die Ablage unter /var/www */
    private string $outsideDir;

    protected function setUp(): void {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/confined-tool-test-' . bin2hex(random_bytes(6));
        Folder::create($this->tempDir, 0700);
        $this->outsideDir = dirname(__DIR__, 2) . '/build/confined-tool-test-' . bin2hex(random_bytes(6));
        Folder::create($this->outsideDir, 0700, true);
    }

    protected function tearDown(): void {
        foreach ([$this->tempDir, $this->outsideDir] as $dir) {
            if (Folder::exists($dir)) {
                Folder::delete($dir, true);
            }
        }
        parent::tearDown();
    }

    public function test_ghostscript_schneidet_zu_obwohl_das_tmpdir_nicht_nutzbar_ist(): void {
        if (!PDFCropHelper::isAvailable()) {
            $this->markTestSkipped('Ghostscript (gs-crop) nicht verfügbar');
        }
        $input = $this->outsideDir . '/beleg.pdf';
        copy(self::SAMPLE_PDF, $input);
        $output = $this->outsideDir . '/beleg_oben.pdf';

        $previous = getenv('TMPDIR');
        putenv('TMPDIR=/nicht/vorhanden');
        try {
            $ok = PDFCropHelper::cropUpperHalf($input, $output);
        } finally {
            putenv($previous === false ? 'TMPDIR' : 'TMPDIR=' . $previous);
        }

        $this->assertTrue($ok, 'Ghostscript muss sein TMPDIR im eigenen Aufrufverzeichnis bekommen');
        $this->assertFileExists($output);
        $this->assertSame([], glob(sys_get_temp_dir() . '/pdftoolkit_confined_*') ?: [], 'Aufrufverzeichnis bleibt nicht liegen');
    }

    public function test_eingabe_ausserhalb_wird_kopiert_und_ausgabe_an_ihr_ziel_verschoben(): void {
        $input = $this->outsideDir . '/beleg.pdf';
        copy(self::SAMPLE_PDF, $input);
        $target = $this->outsideDir . '/ergebnis.pdf';

        $run = ConfinedTool::open(['[INPUT]' => $input, '[OUTPUT]' => $target, '[WIDTH]' => '100'], root: $this->tempDir);
        $directory = $run->directory();
        $this->assertNotNull($directory);
        $replacements = $run->replacements();

        $this->assertStringStartsWith($directory . '/', $replacements['[INPUT]']);
        $this->assertFileEquals($input, $replacements['[INPUT]']);
        $this->assertStringStartsWith($directory . '/', $replacements['[OUTPUT]']);
        $this->assertSame('100', $replacements['[WIDTH]']);
        $this->assertSame('TMPDIR=' . escapeshellarg($directory) . ' ', $run->environmentPrefix());

        file_put_contents($replacements['[OUTPUT]'], 'ergebnis');
        $run->commit();
        $run->close();

        $this->assertSame('ergebnis', file_get_contents($target));
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_vorhandenes_ziel_wird_ersetzt(): void {
        $target = $this->outsideDir . '/vorab_angelegt.pdf';
        file_put_contents($target, '');

        $run = ConfinedTool::open(['[OUTPUT]' => $target], [], root: $this->tempDir);
        file_put_contents($run->replacements()['[OUTPUT]'], 'neu');
        $run->commit();
        $run->close();

        $this->assertSame('neu', file_get_contents($target));
    }

    public function test_pfade_unter_tmp_bleiben_wie_sie_sind(): void {
        $input = $this->tempDir . '/beleg.pdf';
        copy(self::SAMPLE_PDF, $input);
        $replacements = ['[INPUT]' => $input, '[OUTPUT]' => $this->tempDir . '/ergebnis.pdf'];

        $run = ConfinedTool::open($replacements, root: $this->tempDir);

        $this->assertSame($replacements, $run->replacements());
        $this->assertStringContainsString('TMPDIR=', $run->environmentPrefix(['OMP_THREAD_LIMIT' => '1']));
        $this->assertStringStartsWith("OMP_THREAD_LIMIT='1' ", $run->environmentPrefix(['OMP_THREAD_LIMIT' => '1']));
        $run->close();
    }

    public function test_nur_ghostscript_und_ocrmypdf_werden_eingesperrt(): void {
        $this->assertTrue(ConfinedTool::isConfined('/usr/bin/gs'));
        $this->assertTrue(ConfinedTool::isConfined('gs'));
        $this->assertTrue(ConfinedTool::isConfined('/usr/local/bin/ocrmypdf'));
        $this->assertFalse(ConfinedTool::isConfined('/usr/bin/mutool'));
        $this->assertFalse(ConfinedTool::isConfined('/usr/bin/gsettings'));
        $this->assertFalse(ConfinedTool::isConfined(null));
    }

    public function test_wurzel_ausserhalb_von_tmp_weicht_auf_tmp_aus(): void {
        $this->assertSame('/tmp', ConfinedTool::root('/var/www/clients/client4/web146/tmp'));
        $this->assertSame(realpath($this->tempDir), ConfinedTool::root($this->tempDir));
    }
}
