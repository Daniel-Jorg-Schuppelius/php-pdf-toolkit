<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFMetadataHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\{PDFHelper, PDFMetadataHelper};
use Tests\Contracts\BaseTestCase;

/**
 * Metadaten setzen und entfernen (pdf-metadata.js), gelesen mit pdfinfo.
 */
final class PDFMetadataHelperTest extends BaseTestCase {
    private string $workDir;
    private string $source;

    protected function setUp(): void {
        parent::setUp();
        if (!PDFMetadataHelper::isAvailable()) {
            $this->markTestSkipped('mutool (mutool-pdf-metadata) nicht verfügbar');
        }
        foreach (['gs', 'pdftotext', 'pdfinfo'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                $this->markTestSkipped("$tool nicht verfügbar");
            }
        }
        $this->workDir = sys_get_temp_dir() . '/pdf-meta-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
        $this->source = PdfProbe::labelled($this, $this->workDir, 2);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    public function test_set_writes_entries_with_umlauts_and_keeps_the_pages(): void {
        $output = $this->workDir . '/meta.pdf';

        $this->assertTrue(PDFMetadataHelper::set($this->source, $output, [
            'Title' => 'Jahresabschluss 2026 – Entwurf',
            'Author' => 'Kanzlei Müller & Söhne',
            'Keywords' => "Bilanz\nGuV",
        ]));

        $info = PdfProbe::info($output);
        $this->assertMatchesRegularExpression('/^Title:\s+Jahresabschluss 2026 – Entwurf$/m', $info);
        $this->assertMatchesRegularExpression('/^Author:\s+Kanzlei Müller & Söhne$/m', $info);
        $this->assertMatchesRegularExpression('/^Keywords:\s+Bilanz GuV$/m', $info);
        $this->assertSame(2, PdfProbe::pageCount($output));
        $this->assertStringStartsWith('P2-', PdfProbe::pageText($output, 2));

        // Nicht genannte Eintraege bleiben, ein leerer Wert entfernt
        $changed = $this->workDir . '/changed.pdf';
        $this->assertTrue(PDFMetadataHelper::set($output, $changed, ['Author' => '', 'Subject' => 'Prüfung']));
        $info = PdfProbe::info($changed);
        $this->assertStringContainsString('Jahresabschluss 2026', $info);
        $this->assertDoesNotMatchRegularExpression('/^Author:/m', $info);
        $this->assertMatchesRegularExpression('/^Subject:\s+Prüfung$/m', $info);
    }

    public function test_clear_removes_every_entry(): void {
        $withMeta = $this->workDir . '/meta.pdf';
        $this->assertTrue(PDFMetadataHelper::set($this->source, $withMeta, ['Title' => 'Geheim', 'Author' => 'Jemand']));

        $cleared = $this->workDir . '/cleared.pdf';
        $this->assertTrue(PDFMetadataHelper::clear($withMeta, $cleared));

        $info = PdfProbe::info($cleared);
        foreach (['Title', 'Author', 'Creator', 'Producer', 'Subject', 'Keywords'] as $key) {
            $this->assertDoesNotMatchRegularExpression("/^{$key}:/m", $info, $key);
        }
        $this->assertSame(2, PdfProbe::pageCount($cleared));
        $this->assertSame('', trim((string) (PDFHelper::getMetadata($cleared)['Title'] ?? '')));
    }

    public function test_rejects_unknown_keys_and_missing_input(): void {
        $output = $this->workDir . '/x.pdf';

        $this->assertFalse(PDFMetadataHelper::set($this->source, $output, ['Secret' => 'x']));
        $this->assertFalse(PDFMetadataHelper::set($this->source, $output, []));
        $this->assertFalse(PDFMetadataHelper::clear($this->workDir . '/fehlt.pdf', $output));
        $this->assertFileDoesNotExist($output);
    }
}
