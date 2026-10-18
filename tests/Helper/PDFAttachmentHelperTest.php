<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFAttachmentHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\PDFAttachmentHelper;
use Tests\Contracts\BaseTestCase;

/**
 * Anhänge einbetten (pdf-attach.js), auflisten und herauslösen (pdfdetach).
 */
final class PDFAttachmentHelperTest extends BaseTestCase {
    private string $workDir;
    private string $source;

    protected function setUp(): void {
        parent::setUp();
        if (!PDFAttachmentHelper::isAvailable() || !PDFAttachmentHelper::isAttachAvailable() || !PdfProbe::toolsAvailable()) {
            $this->markTestSkipped('pdfdetach, mutool (mutool-pdf-attach) oder poppler nicht verfügbar');
        }
        $this->workDir = sys_get_temp_dir() . '/pdf-attach-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
        $this->source = PdfProbe::labelled($this, $this->workDir, 2);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    public function test_attach_list_and_extract_round_trip(): void {
        $text = $this->workDir . '/notiz.txt';
        file_put_contents($text, "Hallo Welt\nZeile zwei\n");
        $binary = $this->workDir . '/daten.bin';
        file_put_contents($binary, random_bytes(5000));

        $this->assertSame([], PDFAttachmentHelper::list($this->source));

        $withFiles = $this->workDir . '/mit-anhang.pdf';
        $this->assertSame(2, PDFAttachmentHelper::attach($this->source, $withFiles, [
            ['path' => $text, 'name' => 'Notiz 2026.txt', 'mime' => 'text/plain', 'description' => 'Eine Notiz'],
            ['path' => $binary, 'name' => '../daten.bin', 'mime' => 'ungueltig'],
        ]));

        $names = PDFAttachmentHelper::list($withFiles);
        sort($names);
        $this->assertSame(['Notiz 2026.txt', 'daten.bin'], $names);
        $this->assertSame(2, PdfProbe::pageCount($withFiles));
        $this->assertStringStartsWith('P1-', PdfProbe::pageText($withFiles, 1));

        $extracted = PDFAttachmentHelper::extractAll($withFiles, $this->workDir . '/aus');
        $this->assertCount(2, $extracted);
        $byName = [];
        foreach ($extracted as $path) {
            $byName[basename($path)] = $path;
        }
        $this->assertSame(file_get_contents($text), file_get_contents($byName['Notiz 2026.txt']));
        $this->assertSame(file_get_contents($binary), file_get_contents($byName['daten.bin']));

        // Gleicher Name ersetzt, andere bleiben
        file_put_contents($text, "Neu\n");
        $replaced = $this->workDir . '/ersetzt.pdf';
        $this->assertSame(2, PDFAttachmentHelper::attach($withFiles, $replaced, [['path' => $text, 'name' => 'Notiz 2026.txt']]));
        $again = PDFAttachmentHelper::extractAll($replaced, $this->workDir . '/aus2');
        $this->assertCount(2, $again);
        $this->assertSame("Neu\n", file_get_contents($this->workDir . '/aus2/Notiz 2026.txt'));
    }

    public function test_attach_rejects_missing_files_and_names(): void {
        $output = $this->workDir . '/none.pdf';

        $this->assertNull(PDFAttachmentHelper::attach($this->source, $output, [['path' => $this->workDir . '/fehlt.txt', 'name' => 'x.txt']]));
        $this->assertNull(PDFAttachmentHelper::attach($this->source, $output, [['path' => $this->source, 'name' => '']]));
        $this->assertNull(PDFAttachmentHelper::attach($this->source, $output, []));
        $this->assertFileDoesNotExist($output);
        $this->assertSame([], PDFAttachmentHelper::extractAll($this->source, $this->workDir . '/leer'));
    }

    public function test_clean_name_strips_paths_and_shortens(): void {
        $this->assertSame('bericht.pdf', PDFAttachmentHelper::cleanName("../../bericht.pdf\t"));
        // Pfadanteile fallen weg, Umlaute bleiben
        $this->assertSame('x.pdf', PDFAttachmentHelper::cleanName('Übersicht März/x.pdf'));
        $this->assertSame('Übersicht März.pdf', PDFAttachmentHelper::cleanName('Übersicht März.pdf'));
        $long = PDFAttachmentHelper::cleanName(str_repeat('a', 300) . '.xml');
        $this->assertLessThanOrEqual(PDFAttachmentHelper::MAX_NAME_LENGTH, strlen($long));
        $this->assertStringEndsWith('.xml', $long);
    }
}
