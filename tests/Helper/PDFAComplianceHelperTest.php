<?php
/*
 * Created on   : Fri Oct 10 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFAComplianceHelperTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use PDFToolkit\Helper\{PDFAComplianceHelper, PDFOptimizeHelper};
use Tests\Contracts\BaseTestCase;

/**
 * PDF/A-Prüfung: Berichte beider Prüfer werden gleich gelesen; mit
 * installiertem Prüfer wird eine echte PDF/A bestätigt und eine schlichte
 * PDF mit Gründen abgelehnt.
 */
final class PDFAComplianceHelperTest extends BaseTestCase {
    private string $workDir;

    protected function setUp(): void {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/pdfa-check-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            PdfProbe::removeDir($this->workDir);
        }
    }

    private function requireTools(string ...$tools): void {
        foreach ($tools as $tool) {
            if (trim((string) shell_exec('command -v ' . $tool)) === '') {
                $this->markTestSkipped("$tool nicht verfügbar");
            }
        }
    }

    public function test_verapdf_report_is_normalised(): void {
        $report = [
            'report' => [
                'buildInformation' => ['releaseDetails' => [
                    ['id' => 'gui', 'version' => '1.30.3'],
                    ['id' => 'core', 'version' => '1.30.3'],
                ]],
                'jobs' => [[
                    'itemDetails' => ['name' => 'x.pdf'],
                    'validationResult' => [[
                        'details' => [
                            'passedRules' => 120,
                            'failedRules' => 2,
                            'passedChecks' => 900,
                            'failedChecks' => 3,
                            'ruleSummaries' => [
                                [
                                    'specification' => 'ISO 19005-2:2011',
                                    'clause' => '6.2.11.4.1',
                                    'testNumber' => 1,
                                    'status' => 'failed',
                                    'failedChecks' => 2,
                                    'description' => 'The font programs for all fonts shall be embedded',
                                    'checks' => [['status' => 'failed', 'context' => 'root/document[0]/pages[2](9 0 obj PDPage)/font[0]']],
                                ],
                                [
                                    'specification' => 'ISO 19005-2:2011',
                                    'clause' => '6.6.4',
                                    'testNumber' => 1,
                                    'status' => 'failed',
                                    'failedChecks' => 1,
                                    'description' => 'The PDF/A version and conformance level shall be specified',
                                    'checks' => [['status' => 'failed', 'context' => 'root/document[0]/metadata[0]']],
                                ],
                                ['clause' => '6.1.2', 'status' => 'passed', 'description' => 'ignored'],
                            ],
                        ],
                        'jobEndStatus' => 'normal',
                        'profileName' => 'PDF/A-2b validation profile',
                        'statement' => 'PDF file is not compliant with Validation Profile requirements.',
                        'compliant' => false,
                    ]],
                ]],
            ],
        ];

        $result = PDFAComplianceHelper::fromVeraPdfReport($report, '2b');

        $this->assertNotNull($result);
        $this->assertSame(PDFAComplianceHelper::ENGINE_VERAPDF, $result->engine);
        $this->assertSame('veraPDF 1.30.3', $result->engineVersion);
        $this->assertSame('2b', $result->level);
        $this->assertSame('2b', $result->declaredLevel);
        $this->assertTrue($result->declarationChecked());
        $this->assertFalse($result->compliant);
        $this->assertSame(120, $result->passedRules);
        $this->assertSame(2, $result->failedRules);
        $this->assertSame(3, $result->failedChecks);
        $this->assertCount(2, $result->issues);
        $this->assertSame('6.2.11.4.1', $result->issues[0]->clause);
        $this->assertSame(2, $result->issues[0]->occurrences);
        $this->assertSame(3, $result->issues[0]->page);
        $this->assertSame('ISO 19005-2:2011', $result->issues[0]->specification);
        $this->assertNull($result->issues[1]->page);
        $this->assertSame('6.6.4', $result->toArray()['issues'][1]['clause']);
    }

    public function test_verapdf_report_without_validation_result_is_no_verdict(): void {
        $report = ['report' => ['jobs' => [['itemDetails' => ['name' => 'x.pdf'], 'taskResult' => ['type' => 'PARSE', 'isExecuted' => false]]]]];

        $this->assertNull(PDFAComplianceHelper::fromVeraPdfReport($report));
        $this->assertNull(PDFAComplianceHelper::fromVeraPdfReport([]));
    }

    public function test_pdfcraft_report_is_normalised(): void {
        $report = [
            'compliant' => false,
            'declared' => ['output_intents' => [], 'pdfa' => 'PDF/A-3b', 'pdfua' => null],
            'issues' => [
                ['clause' => '6.6.4', 'fixable' => true, 'message' => 'The metadata does not identify the file as PDF/A-3b', 'page' => null],
                ['clause' => '6.2.11.4', 'fixable' => false, 'message' => 'The font Helvetica is not embedded', 'page' => 2],
            ],
            'level' => 'PDF/A-3b',
        ];

        $result = PDFAComplianceHelper::fromPdfCraftReport($report, null, 'pdfcraft-cli 0.5.0');

        $this->assertNotNull($result);
        $this->assertSame(PDFAComplianceHelper::ENGINE_PDFCRAFT, $result->engine);
        $this->assertSame('pdfcraft-cli 0.5.0', $result->engineVersion);
        $this->assertSame('3b', $result->level);
        $this->assertSame('3b', $result->declaredLevel);
        $this->assertFalse($result->compliant);
        $this->assertNull($result->passedRules);
        $this->assertSame(2, $result->failedRules);
        $this->assertTrue($result->issues[0]->fixable);
        $this->assertSame(2, $result->issues[1]->page);
        $this->assertNull(PDFAComplianceHelper::fromPdfCraftReport(['error' => 'x']));
    }

    public function test_declared_level_is_read_from_the_xmp_packet(): void {
        $cases = [
            '<pdfaid:part>2</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance>' => '2b',
            'pdfaid:part="3" pdfaid:conformance="U"' => '3u',
            '<pdfaid:part>4</pdfaid:part>' => '4',
            "<pdfaid:part>4</pdfaid:part>\n<pdfaid:conformance>F</pdfaid:conformance>" => '4f',
            '<pdfaid:part>1</pdfaid:part><pdfaid:conformance>Z</pdfaid:conformance>' => null,
            'kein PDF/A' => null,
        ];
        foreach ($cases as $xmp => $expected) {
            $file = $this->workDir . '/' . md5($xmp) . '.pdf';
            file_put_contents($file, "%PDF-1.7\n1 0 obj << /Type /Metadata >> stream\n<x:xmpmeta>" . $xmp . "</x:xmpmeta>\nendstream\n%%EOF\n");
            $this->assertSame($expected, PDFAComplianceHelper::declaredLevel($file), $xmp);
        }

        // Inkrementelle Speicherung: die letzte Kennung gilt
        $file = $this->workDir . '/zweimal.pdf';
        file_put_contents($file, "%PDF-1.7\n<pdfaid:part>1</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance>\n" . str_repeat('x', 5000) . "\n<pdfaid:part>2</pdfaid:part><pdfaid:conformance>U</pdfaid:conformance>\n%%EOF");
        $this->assertSame('2u', PDFAComplianceHelper::declaredLevel($file));
        $this->assertNull(PDFAComplianceHelper::declaredLevel($this->workDir . '/fehlt.pdf'));
    }

    public function test_json_stream_is_split_into_documents(): void {
        $stream = "{\n  \"doc\": 1,\n  \"text\": \"a } b { \\\" c\"\n}\n{\"compliant\": true, \"issues\": []}\n[1, 2]\nRest";

        $documents = PDFAComplianceHelper::splitJsonDocuments($stream);

        $this->assertCount(3, $documents);
        $this->assertSame('a } b { " c', $documents[0]['text']);
        $this->assertTrue($documents[1]['compliant']);
        $this->assertSame([1, 2], $documents[2]);
        $this->assertSame([], PDFAComplianceHelper::splitJsonDocuments('nur Text'));
    }

    public function test_supported_levels_depend_on_the_engine(): void {
        $this->assertTrue(PDFAComplianceHelper::supportsLevel('2b', PDFAComplianceHelper::ENGINE_VERAPDF));
        $this->assertTrue(PDFAComplianceHelper::supportsLevel('4F', PDFAComplianceHelper::ENGINE_VERAPDF));
        $this->assertTrue(PDFAComplianceHelper::supportsLevel('3b', PDFAComplianceHelper::ENGINE_PDFCRAFT));
        $this->assertFalse(PDFAComplianceHelper::supportsLevel('1b', PDFAComplianceHelper::ENGINE_PDFCRAFT));
        $this->assertFalse(PDFAComplianceHelper::supportsLevel('2b', 'unbekannt'));
    }

    public function test_a_real_pdfa_is_confirmed_and_a_plain_pdf_is_rejected(): void {
        $this->requireTools('gs', 'pdfinfo', 'ocrmypdf');
        if (!PDFAComplianceHelper::isAvailable()) {
            $this->markTestSkipped('Kein PDF/A-Prüfer (verapdf oder pdfcraft-cli) verfügbar');
        }

        $plain = PdfProbe::labelled($this, $this->workDir, 2);
        $archive = $this->workDir . '/archiv.pdf';
        $this->assertTrue(PDFOptimizeHelper::toPdfA($plain, $archive));

        $confirmed = PDFAComplianceHelper::verify($archive);
        $this->assertNotNull($confirmed);
        $this->assertSame(PDFAComplianceHelper::engine(), $confirmed->engine);
        $this->assertNotSame('', $confirmed->engineVersion);
        $this->assertSame('2b', $confirmed->declaredLevel, 'OCRmyPDF kennzeichnet als PDF/A-2b');
        $this->assertSame('2b', $confirmed->level);
        $this->assertTrue($confirmed->compliant, json_encode($confirmed->toArray()));
        $this->assertSame([], $confirmed->issues);

        $rejected = PDFAComplianceHelper::verify($plain);
        $this->assertNotNull($rejected);
        $this->assertNull($rejected->declaredLevel);
        $this->assertSame('2b', $rejected->level, 'ohne Kennung gilt das Konverterziel');
        $this->assertFalse($rejected->compliant);
        $this->assertNotEmpty($rejected->issues);
        $clauses = array_map(static fn ($issue) => $issue->clause, $rejected->issues);
        $this->assertContains('6.6.4', $clauses, 'fehlende PDF/A-Kennung: ' . implode(', ', $clauses));
        foreach ($rejected->issues as $issue) {
            $this->assertNotSame('', $issue->message);
        }

        // Ausdrücklich eine andere Stufe: die Kennung bleibt sichtbar, geprüft wird die Vorgabe
        if (PDFAComplianceHelper::supportsLevel('3b')) {
            $other = PDFAComplianceHelper::verify($archive, '3b');
            $this->assertNotNull($other);
            $this->assertSame('3b', $other->level);
            $this->assertSame('2b', $other->declaredLevel);
        }
    }

    public function test_unknown_level_and_broken_file_give_no_verdict(): void {
        $this->requireTools('gs', 'pdfinfo');
        $plain = PdfProbe::labelled($this, $this->workDir, 1);

        $this->assertNull(PDFAComplianceHelper::verify($plain, '9z'));
        file_put_contents($this->workDir . '/kaputt.pdf', 'kein pdf');
        $this->assertNull(PDFAComplianceHelper::verify($this->workDir . '/kaputt.pdf'));
    }
}
