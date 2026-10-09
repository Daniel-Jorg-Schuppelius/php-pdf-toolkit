<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TesseractDataHelperLanguageTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\TesseractDataHelper;
use Tests\Contracts\BaseTestCase;

/**
 * Positivliste der OCR-Sprachen: Nur bekannte Codes gelangen in Pfade und
 * Download-Adressen. Läuft ohne Netz.
 */
final class TesseractDataHelperLanguageTest extends BaseTestCase {
    private string $workDir;

    protected function setUp(): void {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/tessdata-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void {
        if (is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    public function test_supported_codes(): void {
        $this->assertTrue(TesseractDataHelper::isSupportedLanguage('deu'));
        $this->assertTrue(TesseractDataHelper::isSupportedLanguage('deu+eng'));
        $this->assertTrue(TesseractDataHelper::isSupportedLanguage('deu_frak'));
        $this->assertTrue(TesseractDataHelper::isSupportedLanguage(' eng + fra '));
        $this->assertSame(['deu', 'eng'], TesseractDataHelper::languageCodes('deu+eng+deu'));
    }

    public function test_rejects_unknown_and_malicious_codes(): void {
        foreach (['xyz', 'DEU', '../evil', 'deu+../x', 'deu;rm', 'deu eng', 'eng.traineddata'] as $language) {
            $this->assertFalse(TesseractDataHelper::isSupportedLanguage($language), "abgelehnt: $language");
            $this->assertNull(TesseractDataHelper::languageCodes($language), "abgelehnt: $language");
        }
        // Leer heißt: nichts verlangt - keine Sprache, aber auch kein Fehler
        $this->assertFalse(TesseractDataHelper::isSupportedLanguage(''));
        $this->assertSame([], TesseractDataHelper::languageCodes('+'));
    }

    public function test_helpers_refuse_unsupported_languages_without_touching_the_disk(): void {
        $this->assertFalse(TesseractDataHelper::ensureTrainedData($this->workDir, '../evil'));
        $this->assertFileDoesNotExist($this->workDir . '/../evil.traineddata');
        $this->assertFalse(TesseractDataHelper::hasLanguage($this->workDir, 'xyz'));
        $this->assertNull(TesseractDataHelper::getUsableDataPath('../evil'));
    }

    public function test_default_languages_are_supported(): void {
        $this->assertContains('deu', TesseractDataHelper::SUPPORTED_LANGUAGES);
        $this->assertContains('eng', TesseractDataHelper::SUPPORTED_LANGUAGES);
        $this->assertContains('osd', TesseractDataHelper::SUPPORTED_LANGUAGES);
    }
}
