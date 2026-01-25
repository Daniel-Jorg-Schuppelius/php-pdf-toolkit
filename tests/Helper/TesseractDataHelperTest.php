<?php
/*
 * Created on   : Sat Jan 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TesseractDataHelperTest.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\TesseractDataHelper;
use PHPUnit\Framework\TestCase;

final class TesseractDataHelperTest extends TestCase {
    private string $tempDir;

    protected function setUp(): void {
        $this->tempDir = sys_get_temp_dir() . '/tesseract_test_' . uniqid();
    }

    protected function tearDown(): void {
        // Aufräumen
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testGetLocalDataPathReturnsValidPath(): void {
        $path = TesseractDataHelper::getLocalDataPath();

        $this->assertNotEmpty($path);
        $this->assertStringContainsString('data/tesseract', $path);
    }

    public function testHasTrainedDataReturnsFalseForNonExistentPath(): void {
        $this->assertFalse(TesseractDataHelper::hasTrainedData('/nonexistent/path'));
    }

    public function testHasTrainedDataReturnsFalseForEmptyDirectory(): void {
        mkdir($this->tempDir, 0755, true);

        $this->assertFalse(TesseractDataHelper::hasTrainedData($this->tempDir));
    }

    public function testHasTrainedDataReturnsTrueWhenFilesExist(): void {
        mkdir($this->tempDir, 0755, true);
        // Erstelle eine Dummy-Traineddata-Datei
        file_put_contents($this->tempDir . '/deu.traineddata', 'dummy');

        $this->assertTrue(TesseractDataHelper::hasTrainedData($this->tempDir));
    }

    public function testHasLanguageReturnsTrueForExistingLanguage(): void {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/deu.traineddata', 'dummy');

        $this->assertTrue(TesseractDataHelper::hasLanguage($this->tempDir, 'deu'));
    }

    public function testHasLanguageReturnsFalseForMissingLanguage(): void {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/deu.traineddata', 'dummy');

        $this->assertFalse(TesseractDataHelper::hasLanguage($this->tempDir, 'eng'));
    }

    public function testHasLanguageHandlesMultipleLanguages(): void {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/deu.traineddata', 'dummy');
        file_put_contents($this->tempDir . '/eng.traineddata', 'dummy');

        // Beide vorhanden
        $this->assertTrue(TesseractDataHelper::hasLanguage($this->tempDir, 'deu+eng'));

        // Eine fehlt
        $this->assertFalse(TesseractDataHelper::hasLanguage($this->tempDir, 'deu+fra'));
    }

    public function testHasLanguageHandlesEmptyLanguageString(): void {
        mkdir($this->tempDir, 0755, true);

        // Leere Sprache sollte true zurückgeben (keine Anforderung)
        $this->assertTrue(TesseractDataHelper::hasLanguage($this->tempDir, ''));
    }

    public function testLocalDataPathContainsTrainedData(): void {
        $localPath = TesseractDataHelper::getLocalDataPath();

        // Wenn das lokale Verzeichnis existiert, sollten Trainingsdaten vorhanden sein
        if (is_dir($localPath)) {
            $this->assertTrue(TesseractDataHelper::hasTrainedData($localPath));
        } else {
            $this->markTestSkipped('Lokales Tesseract-Datenverzeichnis nicht vorhanden');
        }
    }

    public function testGetUsableDataPathReturnsPathWhenDataExists(): void {
        $localPath = TesseractDataHelper::getLocalDataPath();

        if (!is_dir($localPath) || !TesseractDataHelper::hasTrainedData($localPath)) {
            $this->markTestSkipped('Keine lokalen Tesseract-Daten vorhanden');
        }

        $usablePath = TesseractDataHelper::getUsableDataPath();

        $this->assertNotNull($usablePath);
        $this->assertEquals($localPath, $usablePath);
    }

    public function testGetUsableDataPathReturnsNullWhenLanguageMissing(): void {
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
}
