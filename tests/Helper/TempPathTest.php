<?php
/*
 * Created on   : Wed Oct 14 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TempPathTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\TempPath;
use Tests\Contracts\BaseTestCase;

/**
 * Arbeitsdateien der Werkzeugaufrufe tragen Dokumentinhalt: Namen nicht
 * vorhersagbar, Verzeichnisse 0700, Dateien 0600 (Scan 13.10.2026 S4-03).
 */
class TempPathTest extends BaseTestCase {
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void {
        foreach (array_reverse($this->paths) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        parent::tearDown();
    }

    public function test_verzeichnis_ist_privat_und_nicht_vorhersagbar(): void {
        $first = $this->paths[] = TempPath::directory('pdftk_test');
        $second = $this->paths[] = TempPath::directory('pdftk_test');

        $this->assertDirectoryExists($first);
        $this->assertNotSame($first, $second);
        $this->assertSame(0700, fileperms($first) & 0777);
        $this->assertMatchesRegularExpression('/\/pdftk_test_[0-9a-f]{16}$/', $first);
    }

    public function test_datei_ist_exklusiv_angelegt_und_nur_fuer_den_eigenen_benutzer(): void {
        $file = $this->paths[] = TempPath::file('pdftk_test', 'txt');

        $this->assertFileExists($file);
        $this->assertSame('', (string) file_get_contents($file));
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertStringEndsWith('.txt', $file);
        $this->assertMatchesRegularExpression('/\/pdftk_test_[0-9a-f]{16}\.txt$/', $file);
    }

    public function test_datei_im_eigenen_verzeichnis(): void {
        $dir = $this->paths[] = TempPath::directory('pdftk_test');
        $file = $this->paths[] = TempPath::file('seite', 'png', $dir);

        $this->assertSame($dir, dirname($file));
    }

    public function test_ein_werkzeug_das_hineinschreibt_laesst_die_rechte_stehen(): void {
        $file = $this->paths[] = TempPath::file('pdftk_test', 'txt');

        // Wie pdftotext/PDFBox: Datei zum Schreiben oeffnen und kuerzen
        $handle = fopen($file, 'w');
        $this->assertNotFalse($handle);
        fwrite($handle, 'Dokumenttext');
        fclose($handle);
        clearstatcache(true, $file);

        $this->assertSame(0600, fileperms($file) & 0777);
    }
}
