<?php
/*
 * Created on   : Sat Jan 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TesseractDataHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use CommonToolkit\Helper\FileSystem\Folder;
use PDFToolkit\Helper\TesseractDataHelper;
use Tests\Contracts\BaseTestCase;

final class TesseractDataHelperTest extends BaseTestCase {
    private string $tempDir;

    protected function setUp(): void {
        $this->tempDir = sys_get_temp_dir() . '/tesseract_test_' . uniqid();
    }

    protected function tearDown(): void {
        // Aufräumen (ensureConfigs legt Unterverzeichnisse an)
        if (is_dir($this->tempDir)) {
            Folder::delete($this->tempDir, true);
        }
    }

    public function test_get_local_data_path_returns_valid_path(): void {
        $path = TesseractDataHelper::getLocalDataPath();

        $this->assertNotEmpty($path);
        $this->assertStringContainsString('data/tesseract', $path);
    }

    public function test_has_trained_data_returns_false_for_non_existent_path(): void {
        $this->assertFalse(TesseractDataHelper::hasTrainedData('/nonexistent/path'));
    }

    public function test_has_trained_data_returns_false_for_empty_directory(): void {
        mkdir($this->tempDir, 0755, true);

        $this->assertFalse(TesseractDataHelper::hasTrainedData($this->tempDir));
    }

    public function test_has_trained_data_returns_true_when_files_exist(): void {
        mkdir($this->tempDir, 0755, true);
        // Erstelle eine Dummy-Traineddata-Datei
        file_put_contents($this->tempDir . '/deu.traineddata', 'dummy');

        $this->assertTrue(TesseractDataHelper::hasTrainedData($this->tempDir));
    }

    public function test_has_language_returns_true_for_existing_language(): void {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/deu.traineddata', 'dummy');

        $this->assertTrue(TesseractDataHelper::hasLanguage($this->tempDir, 'deu'));
    }

    public function test_has_language_returns_false_for_missing_language(): void {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/deu.traineddata', 'dummy');

        $this->assertFalse(TesseractDataHelper::hasLanguage($this->tempDir, 'eng'));
    }

    public function test_has_language_handles_multiple_languages(): void {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/deu.traineddata', 'dummy');
        file_put_contents($this->tempDir . '/eng.traineddata', 'dummy');

        // Beide vorhanden
        $this->assertTrue(TesseractDataHelper::hasLanguage($this->tempDir, 'deu+eng'));

        // Eine fehlt
        $this->assertFalse(TesseractDataHelper::hasLanguage($this->tempDir, 'deu+fra'));
    }

    public function test_has_language_handles_empty_language_string(): void {
        mkdir($this->tempDir, 0755, true);

        // Leere Sprache sollte true zurückgeben (keine Anforderung)
        $this->assertTrue(TesseractDataHelper::hasLanguage($this->tempDir, ''));
    }

    public function test_local_data_path_contains_trained_data(): void {
        $localPath = TesseractDataHelper::getLocalDataPath();

        // Wenn das lokale Verzeichnis Trainingsdaten enthält, sollte hasTrainedData true zurückgeben
        if (!is_dir($localPath) || !TesseractDataHelper::hasTrainedData($localPath)) {
            $this->markTestSkipped('Lokale Tesseract-Trainingsdaten nicht vorhanden (in .gitignore ausgeschlossen)');
        }

        $this->assertTrue(TesseractDataHelper::hasTrainedData($localPath));
    }

    public function test_get_usable_data_path_returns_path_when_data_exists(): void {
        $localPath = TesseractDataHelper::getLocalDataPath();

        if (!is_dir($localPath) || !TesseractDataHelper::hasTrainedData($localPath)) {
            $this->markTestSkipped('Keine lokalen Tesseract-Daten vorhanden');
        }

        $usablePath = TesseractDataHelper::getUsableDataPath();

        $this->assertNotNull($usablePath);
        $this->assertEquals($localPath, $usablePath);
    }

    public function test_get_usable_data_path_returns_null_when_language_missing(): void {
        $localPath = TesseractDataHelper::getLocalDataPath();

        if (!is_dir($localPath)) {
            $this->markTestSkipped('Lokales Tesseract-Datenverzeichnis nicht vorhanden');
        }

        // Angenommen "xyz" existiert nicht und kann nicht heruntergeladen werden
        // (Download würde fehlschlagen wegen ungültiger Sprache)
        $result = TesseractDataHelper::getUsableDataPath('xyz_nonexistent_lang');

        // Bei fehlgeschlagenem Download sollte null zurückgegeben werden
        // (Fallback auf System-Tesseract)
        $this->assertNull($result);
    }

    /**
     * Das mitgelieferte Verzeichnis wird TESSDATA_PREFIX und muss deshalb die
     * Parameterdateien tragen, die ocrmypdf per Namen aufruft ("pdf txt",
     * "hocr txt"). Ohne sie: "read_params_file: Can't open pdf", kein PDF.
     */
    public function test_bundled_data_path_ships_tesseract_configs(): void {
        $localPath = TesseractDataHelper::getLocalDataPath();

        $this->assertTrue(TesseractDataHelper::hasConfigs($localPath), 'configs/pdf, txt, hocr, tsv liegen im Toolkit');
        $this->assertStringContainsString('tessedit_create_pdf 1', (string) file_get_contents($localPath . '/configs/pdf'));
        $this->assertStringContainsString('tessedit_create_txt 1', (string) file_get_contents($localPath . '/configs/txt'));
        $this->assertStringContainsString('tessedit_create_hocr 1', (string) file_get_contents($localPath . '/configs/hocr'));
        $this->assertStringContainsString('tessedit_create_tsv 1', (string) file_get_contents($localPath . '/configs/tsv'));
        $this->assertFileExists($localPath . '/tessconfigs/batch.nochop');
        $this->assertFileExists($localPath . '/pdf.ttf');
    }

    public function test_has_configs_requires_every_output_config(): void {
        mkdir($this->tempDir . '/configs', 0755, true);
        file_put_contents($this->tempDir . '/configs/pdf', "tessedit_create_pdf 1\n");
        file_put_contents($this->tempDir . '/configs/txt', "tessedit_create_txt 1\n");

        $this->assertFalse(TesseractDataHelper::hasConfigs($this->tempDir), 'hocr und tsv fehlen noch');
        $this->assertFalse(TesseractDataHelper::hasConfigs('/nonexistent/path'));
    }

    /**
     * Ein fremdes Datenverzeichnis (tesseract_data_path in der Konfiguration,
     * nur Sprachdaten) bekommt die Konfigurationsdateien aus dem Toolkit.
     */
    public function test_ensure_configs_copies_into_foreign_data_path(): void {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/deu.traineddata', 'dummy');

        $this->assertFalse(TesseractDataHelper::hasConfigs($this->tempDir));
        $this->assertTrue(TesseractDataHelper::ensureConfigs($this->tempDir));
        $this->assertTrue(TesseractDataHelper::hasConfigs($this->tempDir));
        $this->assertFileExists($this->tempDir . '/configs/pdf');
        $this->assertFileExists($this->tempDir . '/tessconfigs/batch.nochop');
        $this->assertFileExists($this->tempDir . '/pdf.ttf');
        // Sprachdaten bleiben, wie sie waren
        $this->assertSame('dummy', file_get_contents($this->tempDir . '/deu.traineddata'));
    }

    public function test_ensure_configs_keeps_existing_files_and_is_idempotent(): void {
        mkdir($this->tempDir . '/configs', 0755, true);
        file_put_contents($this->tempDir . '/configs/pdf', "tessedit_create_pdf 1\ntextonly_pdf 1\n");

        $this->assertTrue(TesseractDataHelper::ensureConfigs($this->tempDir));
        $this->assertTrue(TesseractDataHelper::ensureConfigs($this->tempDir));
        $this->assertStringContainsString('textonly_pdf 1', (string) file_get_contents($this->tempDir . '/configs/pdf'), 'eigene Datei wird nicht ueberschrieben');
        $this->assertFileExists($this->tempDir . '/configs/hocr');
    }

    public function test_ensure_configs_fails_for_missing_directory(): void {
        $this->assertFalse(TesseractDataHelper::ensureConfigs($this->tempDir . '/missing'));
    }

    public function test_usable_data_path_carries_configs(): void {
        $localPath = TesseractDataHelper::getLocalDataPath();
        if (!TesseractDataHelper::hasTrainedData($localPath)) {
            $this->markTestSkipped('Keine lokalen Tesseract-Daten vorhanden');
        }

        $usablePath = TesseractDataHelper::getUsableDataPath();

        $this->assertNotNull($usablePath);
        $this->assertTrue(TesseractDataHelper::hasConfigs($usablePath), 'TESSDATA_PREFIX-Verzeichnis traegt configs/');
    }
}
