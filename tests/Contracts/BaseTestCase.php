<?php
/*
 * Created on   : Mon Apr 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BaseTestCase.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

namespace Tests\Contracts;

use ERRORToolkit\Factories\ConsoleLoggerFactory;
use ERRORToolkit\LoggerRegistry;
use ERRORToolkit\Traits\ErrorLog;
use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase {
    use ErrorLog;

    protected function setUp(): void {
        parent::setUp();

        LoggerRegistry::setLogger(ConsoleLoggerFactory::getLogger());
    }
}
