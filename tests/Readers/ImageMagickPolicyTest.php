<?php
/*
 * Created on   : Tue Oct 13 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ImageMagickPolicyTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Readers;

use CommonToolkit\Helper\Shell;
use PDFToolkit\Readers\TesseractReader;
use PHPUnit\Framework\TestCase;

/**
 * Scan 13.10.2026 S4-06: mogrify (Deskew vor der OCR) lief ohne die
 * restriktive ImageMagick-Policy, die der Core um seine eigenen Aufrufe
 * setzt. Ghostscript lief ohne -dSAFER und verliess sich auf die Vorgabe der
 * jeweiligen gs-Version.
 */
final class ImageMagickPolicyTest extends TestCase {
    public function test_mogrify_laeuft_unter_der_mitgelieferten_policy(): void {
        $prefix = TesseractReader::imageMagickPolicyPrefix();

        $this->assertStringStartsWith('MAGICK_CONFIGURE_PATH=', $prefix);
        $this->assertFileExists(TesseractReader::IMAGEMAGICK_POLICY_DIR . '/policy.xml');

        $output = [];
        $code = 0;
        if (!Shell::executeShellCommand('command -v convert', $output, $code, timeout: Shell::TIMEOUT_PROBE) || $code !== 0) {
            $this->markTestSkipped('ImageMagick nicht installiert');
        }

        $output = [];
        Shell::executeShellCommand($prefix . 'convert -list policy', $output, $code, timeout: Shell::TIMEOUT_PROBE);
        $policy = implode("\n", $output);
        // Ohne die mitgelieferte Policy gibt es diese Sperre auf einem Standard-Server nicht
        $this->assertMatchesRegularExpression('/Policy: Delegate\s+rights: None\s+pattern: \*/', $policy);
    }

    public function test_ghostscript_laeuft_mit_dsafer(): void {
        $config = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/config/executables.json'), true);
        $gs = array_filter($config['shellExecutables'] ?? [], static fn (array $eintrag): bool => ($eintrag['path'] ?? '') === 'gs');

        $this->assertNotSame([], $gs);
        foreach ($gs as $name => $eintrag) {
            $this->assertSame('-dSAFER', $eintrag['arguments'][0] ?? null, $name);
        }
    }
}
