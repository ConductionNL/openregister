<?php

/**
 * Unit test for BulkSaveReport.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\BulkSaveCounts;
use OCA\OpenRegister\Controller\Support\BulkSaveReport;
use OCA\OpenRegister\Controller\Support\BulkSaveSummary;
use OCA\OpenRegister\Dto\BulkSaveOutcomeInterface;
use PHPUnit\Framework\TestCase;

/**
 * A bulk save answers 200 when complete or partial was asked, 422 otherwise, and says what was rejected.
 *
 * @covers \OCA\OpenRegister\Controller\Support\BulkSaveReport
 * @covers \OCA\OpenRegister\Controller\Support\BulkSaveSummary
 * @uses \OCA\OpenRegister\Controller\Support\BulkSaveCounts
 */
class BulkSaveReportTest extends TestCase {
	/**
	 * An outcome with the given failures.
	 *
	 * @param array<int, mixed> $failures The rejected objects.
	 *
	 * @return BulkSaveOutcomeInterface The outcome.
	 */
	private function outcome(array $failures): BulkSaveOutcomeInterface {
		$outcome = $this->createMock(BulkSaveOutcomeInterface::class);
		$outcome->method('failures')->willReturn($failures);
		$outcome->method('failedCount')->willReturn(count($failures));
		$outcome->method('isComplete')->willReturn($failures === []);

		return $outcome;
	}

	private function summary(int $requested, int $saved, array $extra=[]): BulkSaveSummary {
		return new BulkSaveSummary(
			successMessage: 'All saved',
			counts: new BulkSaveCounts(requested: $requested, saved: $saved, accounted: $requested),
			extra: $extra
		);
	}

	public function testACompleteSaveIs200WithTheSuccessMessage(): void {
		$report = new BulkSaveReport(summary: $this->summary(3, 3), outcome: $this->outcome([]), partial: false);

		$this->assertSame(200, $report->status());
		$this->assertSame('All saved', $report->message());
	}

	public function testAnIncompleteSaveIs422AndCountsTheRejected(): void {
		$report = new BulkSaveReport(summary: $this->summary(5, 3), outcome: $this->outcome(['a', 'b']), partial: false);

		$this->assertSame(422, $report->status());
		$this->assertSame(
			'Bulk save incomplete: 2 of 5 objects were rejected and NOT written. See "failures" for the reason per object.',
			$report->message()
		);
	}

	public function testAnIncompleteSaveIs200WhenPartialWasAsked(): void {
		$report = new BulkSaveReport(summary: $this->summary(5, 3), outcome: $this->outcome(['a', 'b']), partial: true);

		$this->assertSame(200, $report->status());
		$this->assertStringStartsWith('Bulk save incomplete: 2 of 5', $report->message());
	}

	public function testBodyCarriesTheCountsFailuresAndExtraKeys(): void {
		$report = new BulkSaveReport(
			summary: $this->summary(4, 3, ['register' => 'r1']),
			outcome: $this->outcome([['index' => 2, 'reason' => 'invalid']]),
			partial: true
		);

		$this->assertSame(
			[
				'success'         => false,
				'message'         => $report->message(),
				'saved_count'     => 3,
				'failed_count'    => 1,
				'requested_count' => 4,
				'failures'        => [['index' => 2, 'reason' => 'invalid']],
				'partial'         => true,
				'register'        => 'r1',
			],
			$report->body()
		);
	}

	public function testSummaryCopiesTheCounts(): void {
		$summary = new BulkSaveSummary(successMessage: 'm', counts: new BulkSaveCounts(requested: 9, saved: 7, accounted: 8), extra: []);

		$this->assertSame(9, $summary->requestedCount);
		$this->assertSame(7, $summary->savedCount);
		$this->assertSame(8, $summary->accountedCount);
	}
}
