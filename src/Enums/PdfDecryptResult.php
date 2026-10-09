<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PdfDecryptResult.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Enums;

/**
 * Ausgang von {@see \PDFToolkit\Helper\PDFSecurityHelper::decrypt()}.
 */
enum PdfDecryptResult: string {
    /** Schutz entfernt, Ergebnis geschrieben */
    case Ok = 'ok';

    /** Das Passwort passt nicht; nichts geschrieben */
    case WrongPassword = 'wrong_password';

    /** Anderer Fehler (Datei, Werkzeug) */
    case Failed = 'failed';
}
