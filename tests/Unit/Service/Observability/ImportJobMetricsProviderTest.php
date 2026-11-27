<?php

/**
 * The import job progress metrics (production-observability, "Import job progress tracking").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-performance-baseline-metrics
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Service\Observability\ImportJobMetricsProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\ImportJobMetricsProvider
 * @uses \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class ImportJobMetricsProviderTest extends TestCase {

	/**
	 * 2026-11-17 06:00:00 UTC.
	 */
	private const NOW = 1_794_895_200;

	/**
	 * What the query filtered on.
	 *
	 * @var array<string, mixed>
	 */
	private array $asked = [];

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	public function testAPreviewingJobReportsItsDecidedRowsAndItsAge(): void {
		$metrics = $this->provider(
			rows: [['uuid' => 'abc123', 'state' => 'previewing', 'processed' => '4000', 'applied' => '0', 'failed' => '0', 'created' => '2026-11-17 05:58:20']]
		)->metrics();

		$names = array_map(static fn (MetricSample $m): string => $m->name, $metrics);
		$this->assertSame(['import_objects_processed_total', 'import_duration_seconds'], $names);
		$this->assertSame(['counter', 'gauge'], array_map(static fn (MetricSample $m): string => $m->type, $metrics));
		$this->assertSame([['labels' => ['job_id' => 'abc123', 'phase' => 'preview'], 'value' => 4000]], $metrics[0]->samples);
		$this->assertSame([['labels' => ['job_id' => 'abc123'], 'value' => 100]], $metrics[1]->samples);
		$this->assertSame(['state', ['previewing', 'committing']], [$this->asked['column'], $this->asked['states']]);
	}//end testAPreviewingJobReportsItsDecidedRowsAndItsAge()

	public function testACommittingJobReportsItsWrittenAndFailedRows(): void {
		$metrics = $this->provider(
			rows: [
				['uuid' => 'def456', 'state' => 'committing', 'processed' => '10000', 'applied' => '2500', 'failed' => '3', 'created' => '2026-11-17 05:00:00'],
			]
		)->metrics();

		$this->assertSame([['labels' => ['job_id' => 'def456', 'phase' => 'commit'], 'value' => 2503]], $metrics[0]->samples);
		$this->assertSame([['labels' => ['job_id' => 'def456'], 'value' => 3600]], $metrics[1]->samples);
	}//end testACommittingJobReportsItsWrittenAndFailedRows()

	public function testAJobWithoutAUsableCreationTimeHasProgressButNoDuration(): void {
		$metrics = $this->provider(
			rows: [
				['uuid' => 'a', 'state' => 'previewing', 'processed' => '1', 'applied' => '0', 'failed' => '0', 'created' => null],
				['uuid' => 'b', 'state' => 'previewing', 'processed' => '2', 'applied' => '0', 'failed' => '0', 'created' => 'not a date'],
				['uuid' => 'c', 'state' => 'committed', 'processed' => '9', 'applied' => '9', 'failed' => '0', 'created' => '2026-11-17 05:00:00'],
				['uuid' => '', 'state' => 'previewing', 'processed' => '9', 'applied' => '0', 'failed' => '0', 'created' => '2026-11-17 05:00:00'],
			]
		)->metrics();

		$this->assertSame(
			[['labels' => ['job_id' => 'a', 'phase' => 'preview'], 'value' => 1], ['labels' => ['job_id' => 'b', 'phase' => 'preview'], 'value' => 2]],
			$metrics[0]->samples
		);
		$this->assertSame([], $metrics[1]->samples);
	}//end testAJobWithoutAUsableCreationTimeHasProgressButNoDuration()

	public function testNoRunningJobPublishesEmptyFamilies(): void {
		$metrics = $this->provider(rows: [])->metrics();

		$this->assertSame([], $metrics[0]->samples);
		$this->assertSame([], $metrics[1]->samples);
	}//end testNoRunningJobPublishesEmptyFamilies()

	public function testAnUnreadableTableWarnsAndPublishesNoSamples(): void {
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('import job progress could not be read'));

		$metrics = $this->provider(rows: [], fails: true)->metrics();

		$this->assertCount(2, $metrics);
		$this->assertSame([], $metrics[0]->samples);
	}//end testAnUnreadableTableWarnsAndPublishesNoSamples()

	/**
	 * @param array<int, array<string, mixed>> $rows  The job rows the query answers.
	 * @param bool                             $fails Whether the query throws.
	 *
	 * @return ImportJobMetricsProvider
	 */
	private function provider(array $rows, bool $fails = false): ImportJobMetricsProvider {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturnCallback(
			function (string $column, string $parameter): string {
				$this->asked['column'] = $column;
				return $column.' IN '.$parameter;
			}
		);
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->asked['states'] = $value;
				return ':states';
			}
		);
		foreach (['select', 'from', 'where', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		if ($fails === true) {
			$qb->method('executeQuery')->willThrowException(new RuntimeException('no table'));
		} else {
			$qb->method('executeQuery')->willReturn($result);
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new ImportJobMetricsProvider(db: $db, time: $time, logger: $this->logger);
	}//end provider()

	/**
	 * The restated running states are the import preview's own.
	 *
	 * @return void
	 */
	public function testTheRunningStatesMatchTheImportPreview(): void {
		$this->assertSame(\OCA\OpenRegister\Db\ImportPreview::STATE_PREVIEWING, \OCA\OpenRegister\Service\Observability\ImportJobMetricsProvider::STATE_PREVIEWING);
		$this->assertSame(\OCA\OpenRegister\Db\ImportPreview::STATE_COMMITTING, \OCA\OpenRegister\Service\Observability\ImportJobMetricsProvider::STATE_COMMITTING);
	}//end testTheRunningStatesMatchTheImportPreview()

}//end class
