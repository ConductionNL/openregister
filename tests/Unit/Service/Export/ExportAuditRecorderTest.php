<?php

/**
 * Unit tests for ExportAuditRecorder — an export is the moment data leaves.
 *
 * Two facts are asserted: a completed export writes one entry naming the actor,
 * the profile and the row count, and a refusal writes one naming the verb and
 * the reason. The third is the one that would otherwise bite in production: a
 * ledger that throws must not fail the export.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Export\ExportAuditRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ExportAuditRecorderTest extends TestCase {
	public function testACompletedExportIsOneEntryNamingActorProfileAndRowCount(): void {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->expects(self::once())
			->method('createExportEntry')
			->with(
				self::equalTo(ExportAuditRecorder::OUTCOME_COMPLETED),
				self::callback(
					static function (array $summary): bool {
						return $summary['profile'] === 'Maandelijkse aanlevering'
							&& $summary['rowCount'] === 412
							&& $summary['format'] === 'csv'
							&& $summary['valueMode'] === 'rendered';
					}
				),
				self::equalTo(7),
				self::equalTo(19),
				self::equalTo('eigenaar-1')
			)
			->willReturn(new AuditTrail());

		(new ExportAuditRecorder($mapper, new NullLogger()))->recordCompleted(
			'Maandelijkse aanlevering',
			412,
			'csv',
			'rendered',
			7,
			19,
			'eigenaar-1'
		);
	}//end testACompletedExportIsOneEntryNamingActorProfileAndRowCount()

	public function testARefusalIsRecordedWithItsReason(): void {
		$captured = [];
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->expects(self::once())
			->method('createExportEntry')
			->willReturnCallback(
				function (...$args) use (&$captured): AuditTrail {
					$captured = ['outcome' => $args[0], 'summary' => $args[1]];

					return new AuditTrail();
				}
			);

		(new ExportAuditRecorder($mapper, new NullLogger()))->recordRefused(
			'Maandelijkse aanlevering',
			'export-right-missing',
			'User behandelaar-1 does not hold the export right on schema zaken.',
			7,
			19,
			'behandelaar-1'
		);

		self::assertSame(ExportAuditRecorder::OUTCOME_REFUSED, $captured['outcome']);
		self::assertSame('export', $captured['summary']['verb']);
		self::assertSame('export-right-missing', $captured['summary']['rule']);
		self::assertSame(0, $captured['summary']['rowCount']);
		self::assertStringContainsString('export right', $captured['summary']['reason']);
	}//end testARefusalIsRecordedWithItsReason()

	public function testALedgerThatThrowsDoesNotFailTheExport(): void {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createExportEntry')->willThrowException(new \RuntimeException('chain busy'));

		(new ExportAuditRecorder($mapper, new NullLogger()))->recordCompleted('p', 1, 'csv');

		// Reaching here is the assertion: a hash-chain hiccup must not turn a
		// delivered aanlevering into a failure.
		self::assertTrue(true);
	}//end testALedgerThatThrowsDoesNotFailTheExport()
}//end class
