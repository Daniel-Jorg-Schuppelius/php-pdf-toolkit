<?php
/*
 * Created on   : Wed Oct 14 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OcrCacheTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\OcrCache;
use ReflectionMethod;
use Tests\Contracts\BaseTestCase;

/**
 * Der OCR-Cache haelt Dokumenttext ueber Prozesse hinweg: Verzeichnis nur fuer
 * den eigenen Benutzer, Eintraege 0600 (Scan 13.10.2026 S4-03).
 */
class OcrCacheTest extends BaseTestCase {
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void {
        foreach (array_reverse($this->paths) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        OcrCache::reset();
        parent::tearDown();
    }

    public function test_eintrag_ist_nur_fuer_den_eigenen_benutzer_lesbar(): void {
        OcrCache::reset();
        $source = $this->paths[] = sys_get_temp_dir() . '/pdftk-ocrcache-' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($source, '%PDF-1.4 ' . bin2hex(random_bytes(16)));
        $parameters = ['test' => 'OcrCacheTest'];

        OcrCache::put($source, 'test', 'Kontoauszug 1234,56', $parameters);
        $entry = (new ReflectionMethod(OcrCache::class, 'path'))->invoke(null, $source, 'test', $parameters);
        if (!is_string($entry) || !is_file($entry)) {
            $this->markTestSkipped('OCR-Cache in dieser Umgebung deaktiviert');
        }
        $this->paths[] = $entry;

        $this->assertSame('Kontoauszug 1234,56', OcrCache::get($source, 'test', $parameters));
        $this->assertSame(0600, fileperms($entry) & 0777);
        $this->assertSame(0, fileperms(dirname($entry)) & 0077, 'Cache-Verzeichnis ohne Rechte fuer Gruppe und andere');
    }

    /** Kundentext bleibt standardmaessig nicht laenger als die Jobdateien (Entscheidung 14.10.2026). */
    public function test_vorgabe_ist_24_stunden(): void {
        OcrCache::reset();

        $this->assertSame(86400, (new ReflectionMethod(OcrCache::class, 'ttl'))->invoke(null));
    }

    /** Abgelaufene Eintraege verschwinden beim Schreiben, nicht erst beim erneuten Lesen derselben Datei. */
    public function test_schreiben_raeumt_abgelaufene_eintraege_weg(): void {
        $dir = $this->paths[] = sys_get_temp_dir() . '/pdftk-ocrcache-' . bin2hex(random_bytes(8));
        OcrCache::configure(enabled: true, ttl: 60, directory: $dir);

        $old = $this->paths[] = $dir . '/' . str_repeat('a', 40) . '.txt';
        $fresh = $this->paths[] = $dir . '/' . str_repeat('b', 40) . '.txt';
        (new ReflectionMethod(OcrCache::class, 'directory'))->invoke(null);
        file_put_contents($old, 'alter Kontoauszug');
        file_put_contents($fresh, 'neuer Kontoauszug');
        touch($old, time() - 3600);

        $source = $this->paths[] = sys_get_temp_dir() . '/pdftk-ocrcache-' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($source, '%PDF-1.4 ' . bin2hex(random_bytes(16)));
        OcrCache::put($source, 'test', 'Text', ['test' => 'prune']);
        $entry = (new ReflectionMethod(OcrCache::class, 'path'))->invoke(null, $source, 'test', ['test' => 'prune']);
        if (is_string($entry)) {
            array_splice($this->paths, 1, 0, [$entry]);
        }

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertSame(0, OcrCache::prune(), 'nichts mehr abgelaufen');
    }

    public function test_negative_lebensdauer_wird_abgelehnt(): void {
        $this->expectException(\InvalidArgumentException::class);
        OcrCache::configure(ttl: -1);
    }

    public function test_zu_offenes_eigenes_verzeichnis_wird_geschlossen(): void {
        $dir = $this->paths[] = sys_get_temp_dir() . '/pdftk-ocrcache-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        chmod($dir, 0775);

        $private = (new ReflectionMethod(OcrCache::class, 'isPrivateDirectory'))->invoke(null, $dir);

        $this->assertTrue($private);
        clearstatcache(true, $dir);
        $this->assertSame(0700, fileperms($dir) & 0777);
    }
}
