<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFSanitizeHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\{PDFAttachmentHelper, PDFSanitizeHelper};
use Tests\Contracts\BaseTestCase;

/**
 * Bereinigen (pdf-sanitize.js) und Einbrennen (Ghostscript) an einer PDF mit
 * Kommentar, Verknüpfung, Formularfeld und OpenAction-JavaScript.
 */
final class PDFSanitizeHelperTest extends BaseTestCase {
    private string $workDir;
    private string $source;

    protected function setUp(): void {
        parent::setUp();
        if (!PDFSanitizeHelper::isAvailable() || !PdfProbe::toolsAvailable()) {
            $this->markTestSkipped('mutool (mutool-pdf-sanitize) oder poppler nicht verfügbar');
        }
        $this->workDir = sys_get_temp_dir() . '/pdf-sanitize-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
        $this->source = PdfProbe::annotated($this, $this->workDir);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    public function test_probe_carries_annotations_form_and_javascript(): void {
        $this->assertStringContainsString('/Annots', PdfProbe::object($this->source, 'pages/1'));
        $root = PdfProbe::object($this->source, 'trailer/Root');
        $this->assertStringContainsString('/AcroForm', $root);
        $this->assertStringContainsString('/OpenAction', $root);
    }

    public function test_sanitize_everything_leaves_plain_pages(): void {
        $output = $this->workDir . '/clean.pdf';

        $this->assertSame(1, PDFSanitizeHelper::sanitize($this->source, $output, PDFSanitizeHelper::REMOVALS));

        $this->assertStringNotContainsString('/Annots', PdfProbe::object($output, 'pages/1'));
        $root = PdfProbe::object($output, 'trailer/Root');
        $this->assertStringNotContainsString('/AcroForm', $root);
        $this->assertStringNotContainsString('/OpenAction', $root);
        $this->assertStringContainsString('Seite eins', PdfProbe::pageText($output, 1));
    }

    public function test_javascript_only_keeps_annotations_and_form(): void {
        $output = $this->workDir . '/js.pdf';

        $this->assertSame(1, PDFSanitizeHelper::sanitize($this->source, $output, [PDFSanitizeHelper::REMOVE_JAVASCRIPT]));

        $this->assertStringContainsString('/Annots', PdfProbe::object($output, 'pages/1'));
        $root = PdfProbe::object($output, 'trailer/Root');
        $this->assertStringContainsString('/AcroForm', $root);
        $this->assertStringNotContainsString('/OpenAction', $root);
    }

    public function test_attachments_removal_drops_embedded_files(): void {
        if (!PDFAttachmentHelper::isAvailable() || !PDFAttachmentHelper::isAttachAvailable()) {
            $this->markTestSkipped('pdfdetach oder mutool-pdf-attach nicht verfügbar');
        }
        $note = $this->workDir . '/n.txt';
        file_put_contents($note, "x\n");
        $withFile = $this->workDir . '/mit.pdf';
        $this->assertSame(1, PDFAttachmentHelper::attach($this->source, $withFile, [['path' => $note, 'name' => 'n.txt']]));
        $this->assertSame(['n.txt'], PDFAttachmentHelper::list($withFile));

        $output = $this->workDir . '/ohne.pdf';
        $this->assertSame(1, PDFSanitizeHelper::sanitize($withFile, $output, [PDFSanitizeHelper::REMOVE_ATTACHMENTS]));
        $this->assertSame([], PDFAttachmentHelper::list($output));
        // Der Kommentar bleibt
        $this->assertStringContainsString('/Annots', PdfProbe::object($output, 'pages/1'));
    }

    public function test_sanitize_rejects_unknown_and_empty_removals(): void {
        $output = $this->workDir . '/none.pdf';

        $this->assertNull(PDFSanitizeHelper::sanitize($this->source, $output, ['alles']));
        $this->assertNull(PDFSanitizeHelper::sanitize($this->source, $output, []));
        $this->assertFileDoesNotExist($output);
    }

    public function test_flatten_burns_annotations_into_the_page(): void {
        if (!PDFSanitizeHelper::isFlattenAvailable()) {
            $this->markTestSkipped('Ghostscript (gs-flatten) nicht verfügbar');
        }
        $output = $this->workDir . '/flat.pdf';

        $this->assertTrue(PDFSanitizeHelper::flatten($this->source, $output));

        $this->assertSame(1, PdfProbe::pageCount($output));
        $this->assertStringNotContainsString('/Annots', PdfProbe::object($output, 'pages/1'));
        $this->assertStringNotContainsString('/AcroForm', PdfProbe::object($output, 'trailer/Root'));
        $this->assertStringContainsString('Seite eins', PdfProbe::pageText($output, 1));
    }
}
