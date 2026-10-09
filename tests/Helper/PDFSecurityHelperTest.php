<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFSecurityHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use ERRORToolkit\LoggerRegistry;
use PDFToolkit\Enums\PdfDecryptResult;
use PDFToolkit\Helper\PDFSecurityHelper;
use Psr\Log\AbstractLogger;
use Tests\Contracts\BaseTestCase;

/**
 * Passwortschutz: prüfen, setzen, entfernen - und das Passwort taucht
 * nirgends im Protokoll auf.
 */
final class PDFSecurityHelperTest extends BaseTestCase {
    private const USER_PASSWORD = 'Nutzer Pass!7';
    private const OWNER_PASSWORD = 'Eigner=Pass';

    private string $workDir;
    private string $source;

    /** @var list<string> */
    private array $logLines = [];

    protected function setUp(): void {
        parent::setUp();

        if (!PDFSecurityHelper::isAvailable()) {
            $this->markTestSkipped('mutool (mutool-pdf-protect) nicht verfügbar');
        }
        foreach (['gs', 'pdftotext', 'pdfinfo'] as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                $this->markTestSkipped("$tool nicht verfügbar");
            }
        }

        $this->workDir = sys_get_temp_dir() . '/pdf-security-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
        $this->source = PdfProbe::labelled($this, $this->workDir, 2);

        // Alle Protokollzeilen samt Kontext einsammeln
        $lines = &$this->logLines;
        LoggerRegistry::setLogger(new class($lines) extends AbstractLogger {
            /** @param list<string> $lines */
            public function __construct(private array &$lines) {}

            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->lines[] = $message . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
            }
        });
    }

    protected function tearDown(): void {
        LoggerRegistry::resetLogger();
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    public function test_plain_pdf_is_not_encrypted(): void {
        $this->assertSame(['encrypted' => false, 'needsPassword' => false], PDFSecurityHelper::probe($this->source));
        $this->assertFalse(PDFSecurityHelper::isEncrypted($this->source));
        $this->assertNull(PDFSecurityHelper::probe('/nonexistent.pdf'));
    }

    public function test_encrypt_locks_the_file_with_rights(): void {
        $locked = $this->workDir . '/locked.pdf';

        $this->assertTrue(PDFSecurityHelper::encrypt($this->source, $locked, self::USER_PASSWORD, self::OWNER_PASSWORD, PDFSecurityHelper::PERMIT_PRINT));

        // Ohne Passwort nicht lesbar, mit Passwort lesbar und AES-256 mit nur Drucken
        $this->assertSame('', PdfProbe::encryptionLine($locked));
        $this->assertSame(0, PdfProbe::pageCount($locked));
        $this->assertSame(['encrypted' => true, 'needsPassword' => true], PDFSecurityHelper::probe($locked));
        $line = PdfProbe::encryptionLine($locked, self::USER_PASSWORD);
        $this->assertStringContainsString('print:yes', $line);
        $this->assertStringContainsString('copy:no', $line);
        $this->assertStringContainsString('change:no', $line);
        $this->assertStringContainsString('AES-256', $line);
        $this->assertStringStartsWith('P1-', PdfProbe::pageText($locked, 1, self::USER_PASSWORD));
    }

    public function test_decrypt_needs_the_right_password(): void {
        $locked = $this->workDir . '/locked.pdf';
        $open = $this->workDir . '/open.pdf';
        PDFSecurityHelper::encrypt($this->source, $locked, self::USER_PASSWORD);

        $this->assertSame(PdfDecryptResult::WrongPassword, PDFSecurityHelper::decrypt($locked, $open, 'falsch'));
        $this->assertFileDoesNotExist($open);
        $this->assertSame(PdfDecryptResult::WrongPassword, PDFSecurityHelper::decrypt($locked, $open, ''));

        $this->assertSame(PdfDecryptResult::Ok, PDFSecurityHelper::decrypt($locked, $open, self::USER_PASSWORD));
        $this->assertSame('no', PdfProbe::encryptionLine($open));
        $this->assertSame(2, PdfProbe::pageCount($open));
        $this->assertStringStartsWith('P2-', PdfProbe::pageText($open, 2));

        $this->assertSame(PdfDecryptResult::Failed, PDFSecurityHelper::decrypt('/nonexistent.pdf', $open, 'x'));
    }

    public function test_owner_password_only_restricts_without_locking(): void {
        $restricted = $this->workDir . '/restricted.pdf';
        $open = $this->workDir . '/open.pdf';

        $this->assertTrue(PDFSecurityHelper::encrypt($this->source, $restricted, '', self::OWNER_PASSWORD, PDFSecurityHelper::PERMIT_COPY));

        $this->assertSame(['encrypted' => true, 'needsPassword' => false], PDFSecurityHelper::probe($restricted));
        $this->assertStringContainsString('print:no', PdfProbe::encryptionLine($restricted));
        $this->assertStringContainsString('copy:yes', PdfProbe::encryptionLine($restricted));
        // Reiner Rechteschutz fällt ohne Passwort
        $this->assertSame(PdfDecryptResult::Ok, PDFSecurityHelper::decrypt($restricted, $open));
        $this->assertSame('no', PdfProbe::encryptionLine($open));
    }

    public function test_random_owner_password_keeps_rights_in_force(): void {
        $locked = $this->workDir . '/locked.pdf';

        $this->assertTrue(PDFSecurityHelper::encrypt($this->source, $locked, self::USER_PASSWORD, null, 0));

        // Das Benutzerpasswort öffnet, hebt aber die Rechte nicht auf
        $this->assertStringContainsString('print:no', PdfProbe::encryptionLine($locked, self::USER_PASSWORD));
    }

    public function test_rejects_bad_passwords(): void {
        $output = $this->workDir . '/x.pdf';

        $this->assertFalse(PDFSecurityHelper::encrypt($this->source, $output, 'mit,Komma'));
        $this->assertFalse(PDFSecurityHelper::encrypt($this->source, $output, "steuer\nzeichen"));
        $this->assertFalse(PDFSecurityHelper::encrypt($this->source, $output, str_repeat('a', 128)));
        $this->assertFalse(PDFSecurityHelper::encrypt($this->source, $output, ''));
        $this->assertFileDoesNotExist($output);

        $this->assertTrue(PDFSecurityHelper::isValidPassword('Umlaute äöü und Satzzeichen!?='));
        $this->assertTrue(PDFSecurityHelper::isValidPassword(str_repeat('a', 127)));
        $this->assertFalse(PDFSecurityHelper::isValidPassword(''));
    }

    public function test_permission_values(): void {
        $this->assertSame(-4, PDFSecurityHelper::permissionValue(PDFSecurityHelper::PERMIT_ALL));
        $this->assertSame(-3904, PDFSecurityHelper::permissionValue(0));
        $this->assertSame(-4 & ~(4 | 2048), PDFSecurityHelper::permissionValue(PDFSecurityHelper::PERMIT_COPY | PDFSecurityHelper::PERMIT_MODIFY | PDFSecurityHelper::PERMIT_ANNOTATE));
    }

    public function test_passwords_never_appear_in_the_log(): void {
        $locked = $this->workDir . '/locked.pdf';
        $open = $this->workDir . '/open.pdf';

        PDFSecurityHelper::encrypt($this->source, $locked, self::USER_PASSWORD, self::OWNER_PASSWORD);
        PDFSecurityHelper::decrypt($locked, $open, 'falsch');
        PDFSecurityHelper::decrypt($locked, $open, self::USER_PASSWORD);

        $this->assertNotEmpty($this->logLines);
        foreach ($this->logLines as $line) {
            $this->assertStringNotContainsString(self::USER_PASSWORD, $line);
            $this->assertStringNotContainsString(self::OWNER_PASSWORD, $line);
            $this->assertStringNotContainsString('falsch', $line);
        }
        // Die Passwortdateien sind weg
        $this->assertSame([], glob(sys_get_temp_dir() . '/pdfprotect_*') ?: []);
    }
}
