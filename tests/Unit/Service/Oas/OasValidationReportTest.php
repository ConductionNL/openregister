<?php

/**
 * Unit tests for OasValidationReport.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
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
 * @spec openspec/changes/oas-validation/tasks.md#task-validation-error-reporting
 */

declare(strict_types=1);

namespace Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasValidationReport;
use PHPUnit\Framework\TestCase;

class OasValidationReportTest extends TestCase
{

    private OasValidationReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = new OasValidationReport();

    }//end setUp()

    public function testNewReportIsEmpty(): void
    {
        $this->assertTrue($this->report->isEmpty());
        $this->assertSame([], $this->report->getIssues());
        $this->assertSame([], $this->report->getErrors());
        $this->assertSame([], $this->report->getWarnings());
        $this->assertSame([], $this->report->getAutoCorrections());

    }//end testNewReportIsEmpty()

    public function testNewReportPassedAndHasNoErrors(): void
    {
        $this->assertTrue($this->report->passed());
        $this->assertFalse($this->report->hasErrors());

    }//end testNewReportPassedAndHasNoErrors()

    public function testAddErrorRecordsIssueWithErrorSeverity(): void
    {
        $this->report->addError(
            path: 'paths./foo.get',
            message: 'operationId collision',
            code: OasValidationReport::CODE_DUPLICATE_OPERATION_ID,
        );

        $this->assertFalse($this->report->isEmpty());
        $this->assertTrue($this->report->hasErrors());
        $this->assertFalse($this->report->passed());

        $errors = $this->report->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('paths./foo.get', $errors[0]['path']);
        $this->assertSame('operationId collision', $errors[0]['message']);
        $this->assertSame(OasValidationReport::CODE_DUPLICATE_OPERATION_ID, $errors[0]['code']);
        $this->assertSame(OasValidationReport::SEVERITY_ERROR, $errors[0]['severity']);

    }//end testAddErrorRecordsIssueWithErrorSeverity()

    public function testAddWarningRecordsIssueWithWarningSeverity(): void
    {
        $this->report->addWarning(
            path: 'tags[0]',
            message: 'unused tag',
            code: OasValidationReport::CODE_UNUSED_TAG,
        );

        $this->assertFalse($this->report->isEmpty());
        $this->assertFalse($this->report->hasErrors());
        $this->assertTrue($this->report->passed());

        $warnings = $this->report->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame(OasValidationReport::SEVERITY_WARNING, $warnings[0]['severity']);
        $this->assertSame(OasValidationReport::CODE_UNUSED_TAG, $warnings[0]['code']);

    }//end testAddWarningRecordsIssueWithWarningSeverity()

    public function testAddAutoCorrectionRecordsIssueWithAutoCorrectedSeverity(): void
    {
        $this->report->addAutoCorrection(
            path: 'servers[0].url',
            message: 'relative URL corrected to absolute',
            code: OasValidationReport::CODE_RELATIVE_SERVER_URL,
        );

        $this->assertFalse($this->report->isEmpty());
        $this->assertFalse($this->report->hasErrors());

        $corrections = $this->report->getAutoCorrections();
        $this->assertCount(1, $corrections);
        $this->assertSame(OasValidationReport::SEVERITY_AUTO_CORRECTED, $corrections[0]['severity']);

    }//end testAddAutoCorrectionRecordsIssueWithAutoCorrectedSeverity()

    public function testGetIssuesReturnsAllSeverities(): void
    {
        $this->report->addError(path: 'p1', message: 'e1', code: OasValidationReport::CODE_DANGLING_REF);
        $this->report->addWarning(path: 'p2', message: 'w1', code: OasValidationReport::CODE_UNUSED_TAG);
        $this->report->addAutoCorrection(path: 'p3', message: 'a1', code: OasValidationReport::CODE_ORPHAN_TAG);

        $all = $this->report->getIssues();
        $this->assertCount(3, $all);

        $this->assertCount(1, $this->report->getErrors());
        $this->assertCount(1, $this->report->getWarnings());
        $this->assertCount(1, $this->report->getAutoCorrections());

    }//end testGetIssuesReturnsAllSeverities()

    public function testToSummaryContractsShape(): void
    {
        $this->report->addError(path: 'p', message: 'm', code: OasValidationReport::CODE_DANGLING_REF);
        $this->report->addWarning(path: 'p', message: 'm', code: OasValidationReport::CODE_UNUSED_TAG);
        $this->report->addAutoCorrection(path: 'p', message: 'm', code: OasValidationReport::CODE_ORPHAN_TAG);

        $summary = $this->report->toSummary();

        $this->assertArrayHasKey('passed', $summary);
        $this->assertArrayHasKey('errors', $summary);
        $this->assertArrayHasKey('warnings', $summary);
        $this->assertArrayHasKey('autoCorrected', $summary);
        $this->assertArrayHasKey('issues', $summary);

        $this->assertFalse($summary['passed']);
        $this->assertSame(1, $summary['errors']);
        $this->assertSame(1, $summary['warnings']);
        $this->assertSame(1, $summary['autoCorrected']);
        $this->assertCount(3, $summary['issues']);

    }//end testToSummaryContractsShape()

    public function testToSummaryPassedTrueWhenNoErrors(): void
    {
        $this->report->addWarning(path: 'p', message: 'm', code: OasValidationReport::CODE_UNUSED_TAG);

        $summary = $this->report->toSummary();
        $this->assertTrue($summary['passed']);
        $this->assertSame(0, $summary['errors']);
        $this->assertSame(1, $summary['warnings']);

    }//end testToSummaryPassedTrueWhenNoErrors()

    public function testEmptySummaryOnFreshReport(): void
    {
        $summary = $this->report->toSummary();
        $this->assertTrue($summary['passed']);
        $this->assertSame(0, $summary['errors']);
        $this->assertSame(0, $summary['warnings']);
        $this->assertSame(0, $summary['autoCorrected']);
        $this->assertSame([], $summary['issues']);

    }//end testEmptySummaryOnFreshReport()
}//end class
