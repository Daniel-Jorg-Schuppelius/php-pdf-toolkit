<?php
/*
 * Created on   : Fri Oct 10 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PdfAComplianceResult.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Entities;

/**
 * Ergebnis einer PDF/A-Prüfung ({@see \PDFToolkit\Helper\PDFAComplianceHelper::verify()}),
 * unabhängig vom Prüfwerkzeug: geprüfte Stufe, die in der Datei erklärte Stufe,
 * das Urteil und die verletzten Prüfpunkte. Zahlen, die nur veraPDF liefert
 * (bestandene Regeln und Einzelprüfungen), sind bei PdfCraft null.
 */
final readonly class PdfAComplianceResult {
    /**
     * @param string $engine {@see \PDFToolkit\Helper\PDFAComplianceHelper::ENGINE_VERAPDF} oder ENGINE_PDFCRAFT
     * @param string $level geprüfte Stufe, z. B. "2b"
     * @param string|null $declaredLevel Stufe aus der XMP-Kennung der Datei (pdfaid), null = die Datei erklärt sich nicht als PDF/A
     * @param list<PdfAComplianceIssue> $issues
     */
    public function __construct(
        public string $engine,
        public string $engineVersion,
        public string $level,
        public ?string $declaredLevel,
        public bool $compliant,
        public array $issues = [],
        public ?int $passedRules = null,
        public ?int $failedRules = null,
        public ?int $passedChecks = null,
        public ?int $failedChecks = null,
        /** Wortlaut des Werkzeugs, z. B. "PDF file is compliant with Validation Profile requirements." */
        public ?string $statement = null,
    ) {}

    /** Die Datei trägt eine PDF/A-Kennung. */
    public function isDeclared(): bool {
        return $this->declaredLevel !== null;
    }

    /** Geprüft wurde genau die Stufe, die die Datei für sich erklärt. */
    public function declarationChecked(): bool {
        return $this->declaredLevel !== null && $this->declaredLevel === $this->level;
    }

    public function issueCount(): int {
        return count($this->issues);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'engine' => $this->engine,
            'engineVersion' => $this->engineVersion,
            'level' => $this->level,
            'declaredLevel' => $this->declaredLevel,
            'compliant' => $this->compliant,
            'passedRules' => $this->passedRules,
            'failedRules' => $this->failedRules,
            'passedChecks' => $this->passedChecks,
            'failedChecks' => $this->failedChecks,
            'statement' => $this->statement,
            'issues' => array_map(static fn (PdfAComplianceIssue $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
