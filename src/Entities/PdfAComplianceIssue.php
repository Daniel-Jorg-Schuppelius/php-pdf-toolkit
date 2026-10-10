<?php
/*
 * Created on   : Fri Oct 10 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PdfAComplianceIssue.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Entities;

/**
 * Ein verletzter PDF/A-Prüfpunkt aus {@see PdfAComplianceResult}: die Klausel
 * der ISO 19005 (z. B. "6.2.11.4.1"), der Prüftext des Werkzeugs und, soweit
 * das Werkzeug es hergibt, Seite, Anzahl der Fundstellen und ob der Punkt
 * automatisch behebbar wäre.
 */
final readonly class PdfAComplianceIssue {
    public function __construct(
        public string $clause,
        public string $message,
        /** Zahl der fehlgeschlagenen Einzelprüfungen zu dieser Regel */
        public int $occurrences = 1,
        /** Seite (1-basiert) der ersten Fundstelle, falls bekannt */
        public ?int $page = null,
        /** Nur PdfCraft meldet, ob eine Umwandlung den Punkt beheben könnte */
        public ?bool $fixable = null,
        /** Norm, aus der die Klausel stammt (veraPDF: "ISO 19005-2:2011") */
        public ?string $specification = null,
        /** Laufende Nummer des Tests innerhalb der Klausel (veraPDF) */
        public ?int $test = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'clause' => $this->clause,
            'text' => $this->message,
            'occurrences' => $this->occurrences,
            'page' => $this->page,
            'fixable' => $this->fixable,
            'specification' => $this->specification,
            'test' => $this->test,
        ];
    }
}
