<?php

/**
 * Unit tests for OasValidationException.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-validation/spec.md "Validation Modes (Strict vs Lenient)"
 */

declare(strict_types=1);

namespace Unit\Exception;

use Exception;
use OCA\OpenRegister\Exception\OasValidationException;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use PHPUnit\Framework\TestCase;

class OasValidationExceptionTest extends TestCase
{
    public function testExceptionExtendsException(): void
    {
        $report = new OasValidationReport();
        $e      = new OasValidationException(message: 'Validation failed', report: $report);

        $this->assertInstanceOf(Exception::class, $e);

    }//end testExceptionExtendsException()

    public function testGetMessageReturnsConstructorMessage(): void
    {
        $report = new OasValidationReport();
        $e      = new OasValidationException(message: 'OAS has 2 errors', report: $report);

        $this->assertSame('OAS has 2 errors', $e->getMessage());

    }//end testGetMessageReturnsConstructorMessage()

    public function testDefaultCodeIs422(): void
    {
        $report = new OasValidationReport();
        $e      = new OasValidationException(message: 'msg', report: $report);

        $this->assertSame(422, $e->getCode());

    }//end testDefaultCodeIs422()

    public function testCustomCodeIsHonoured(): void
    {
        $report = new OasValidationReport();
        $e      = new OasValidationException(message: 'msg', report: $report, code: 400);

        $this->assertSame(400, $e->getCode());

    }//end testCustomCodeIsHonoured()

    public function testGetReportReturnsInjectedReport(): void
    {
        $report = new OasValidationReport();
        $report->addError(
            path: 'servers[0].url',
            message: 'relative URL',
            code: OasValidationReport::CODE_RELATIVE_SERVER_URL,
        );

        $e = new OasValidationException(message: 'msg', report: $report);

        $this->assertSame($report, $e->getReport());
        $this->assertTrue($e->getReport()->hasErrors());

    }//end testGetReportReturnsInjectedReport()

    public function testPreviousExceptionIsChained(): void
    {
        $prev   = new \RuntimeException('root cause');
        $report = new OasValidationReport();
        $e      = new OasValidationException(message: 'msg', report: $report, previous: $prev);

        $this->assertSame($prev, $e->getPrevious());

    }//end testPreviousExceptionIsChained()
}//end class
