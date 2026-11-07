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

    /**
     * Ein lokales Modell (z. B. nachtrainiertes deu_finetuned) ist ein Sprachcode,
     * sobald seine traineddata in einem Modellverzeichnis liegt - und nur dann.
     */
    public function test_local_model_in_given_directory_counts_as_language(): void {
        mkdir($this->workDir, 0755, true);
        file_put_contents($this->workDir . '/deu_probe.traineddata', 'x');

        $this->assertTrue(TesseractDataHelper::isLocalModel('deu_probe', $this->workDir));
        $this->assertFalse(TesseractDataHelper::isLocalModel('deu_probe'), 'nur im angegebenen Verzeichnis');
        $this->assertSame(['deu_probe', 'eng'], TesseractDataHelper::languageCodes('deu_probe+eng', $this->workDir));
        $this->assertNull(TesseractDataHelper::languageCodes('deu_probe+eng'));
        $this->assertTrue(TesseractDataHelper::isSupportedLanguage('deu_probe', $this->workDir));
        $this->assertTrue(TesseractDataHelper::hasLanguage($this->workDir, 'deu_probe'));
        $this->assertFalse(TesseractDataHelper::hasLanguage($this->workDir, 'deu_fehlt'));
        // Kein Modell ohne Datei, kein Name ausserhalb des tessdata-Musters
        $this->assertFalse(TesseractDataHelper::isLocalModel('deu_fehlt', $this->workDir));
        foreach (['Deu_probe', 'deu-probe', 'deu_probe.traineddata', '../deu_probe', 'deu probe'] as $name) {
            $this->assertFalse(TesseractDataHelper::isLocalModel($name, $this->workDir), "abgelehnt: $name");
        }
    }

    /** Ein lokales Modell hat keine Download-Quelle: fehlt die Datei, gibt es keinen Netzzugriff. */
    public function test_missing_local_model_is_refused_without_download(): void {
        mkdir($this->workDir, 0755, true);

        $this->assertFalse(TesseractDataHelper::ensureTrainedData($this->workDir, 'deu_fehlt'));
        $this->assertFileDoesNotExist($this->workDir . '/deu_fehlt.traineddata');
        $this->assertFileDoesNotExist($this->workDir . '/osd.traineddata');
    }

    public function test_models_replace_codes_only_when_the_file_exists(): void {
        mkdir($this->workDir, 0755, true);
        file_put_contents($this->workDir . '/deu_probe.traineddata', 'x');

        $models = ['deu' => 'deu_probe', 'eng' => 'eng_fehlt'];
        $this->assertSame(['deu_probe', 'eng'], TesseractDataHelper::applyModels(['deu', 'eng'], $models, $this->workDir));
        // Idempotent: ein Modellname bleibt ein Modellname
        $this->assertSame(['deu_probe'], TesseractDataHelper::applyModels(['deu_probe'], $models, $this->workDir));
        $this->assertSame(['deu', 'eng'], TesseractDataHelper::applyModels(['deu', 'eng'], [], $this->workDir));
        // resolveModels folgt der Einstellung; ungueltige Angaben bleiben unangetastet
        $expected = implode('+', TesseractDataHelper::applyModels(['deu', 'eng'], TesseractDataHelper::configuredModels()));
        $this->assertSame($expected, TesseractDataHelper::resolveModels('deu+eng'));
        $this->assertSame('../evil', TesseractDataHelper::resolveModels('../evil'));
        $this->assertSame('', TesseractDataHelper::resolveModels(''));
    }

    public function test_model_setting_accepts_object_and_text(): void {
        $this->assertSame(['deu' => 'deu_finetuned'], TesseractDataHelper::parseModels(['deu' => 'deu_finetuned']));
        $this->assertSame(['deu' => 'deu_finetuned', 'eng' => 'eng_best'], TesseractDataHelper::parseModels('deu=deu_finetuned, eng = eng_best'));
        $this->assertSame([], TesseractDataHelper::parseModels(null));
        $this->assertSame([], TesseractDataHelper::parseModels(''));
        $this->assertSame([], TesseractDataHelper::parseModels(['deu' => '../evil', 'DEU' => 'deu_x', 'fra' => 7]));
    }
}
