<?php

/**
 * The storage growth threshold (production-observability, "Storage growth threshold").
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
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-alerting-threshold-configuration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Service\Observability\StorageGrowthMetricsProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Observability\StorageGrowthMetricsProvider
 * @uses \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class StorageGrowthMetricsProviderTest extends TestCase {

	private const NOW = 1_763_200_000;

	/**
	 * What the growth query asked.
	 *
	 * @var array<string, mixed>
	 */
	private array $asked = [];

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function provider(int|string|false $bytes, string $threshold, bool $fails = false): StorageGrowthMetricsProvider {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('gte')->willReturnCallback(
			function (string $column, string $parameter): string {
				$this->asked['column'] = $column;
				return $column.' >= '.$parameter;
			}
		);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnCallback(static fn (string $call): string => $call);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->asked['since'] = $value;
				return ':since';
			}
		);
		$qb->method('select')->willReturnCallback(
			function (string $select) use ($qb): IQueryBuilder {
				$this->asked['select'] = $select;
				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->asked['table'] = $table;
				return $qb;
			}
		);
		$qb->method('where')->willReturnSelf();
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($bytes);
		if ($fails === true) {
			$qb->method('executeQuery')->willThrowException(new RuntimeException('no such table'));
		} else {
			$qb->method('executeQuery')->willReturn($result);
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('openregister', 'storage_growth_alert_bytes', '')->willReturn($threshold);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new StorageGrowthMetricsProvider(db: $db, appConfig: $config, time: $time, logger: $this->logger);
	}//end provider()

	/**
	 * @param array<int, MetricSample> $metrics The published samples.
	 *
	 * @return array<string, mixed> Each metric's samples by name.
	 */
	private static function byName(array $metrics): array {
		$named = [];
		foreach ($metrics as $metric) {
			$named[$metric->name] = $metric->samples;
		}

		return $named;
	}//end byName()

	public function testGrowthOverTheThresholdIsFlaggedAndWarned(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'[Observability] vector storage grew 1500000000 bytes in 24 hours, over the alert threshold of 1073741824 bytes'
		);

		$metrics = self::byName(metrics: $this->provider(bytes: '1500000000', threshold: '1073741824')->metrics());

		$this->assertSame([['labels' => [], 'value' => 1500000000]], $metrics['storage_growth_bytes']);
		$this->assertSame([['labels' => [], 'value' => 1]], $metrics['storage_growth_exceeded']);
		$this->assertSame(
			['select' => 'SUM(LENGTH(embedding))', 'table' => 'openregister_vectors', 'since' => self::NOW - 86400, 'column' => 'created_at'],
			$this->asked
		);
	}//end testGrowthOverTheThresholdIsFlaggedAndWarned()

	public function testGrowthAtOrUnderTheThresholdIsNotFlagged(): void {
		$this->logger->expects($this->never())->method('warning');

		foreach (['1073741824', '500'] as $bytes) {
			$metrics = self::byName(metrics: $this->provider(bytes: $bytes, threshold: '1073741824')->metrics());
			$this->assertSame([['labels' => [], 'value' => 0]], $metrics['storage_growth_exceeded']);
		}
	}//end testGrowthAtOrUnderTheThresholdIsNotFlagged()

	public function testWithoutAThresholdTheGrowthIsPublishedAndNeverFlagged(): void {
		$this->logger->expects($this->never())->method('warning');

		foreach (['', '0', 'one gigabyte', '-5'] as $threshold) {
			$metrics = self::byName(metrics: $this->provider(bytes: '999999999999', threshold: $threshold)->metrics());
			$this->assertSame([['labels' => [], 'value' => 999999999999]], $metrics['storage_growth_bytes']);
			$this->assertSame([['labels' => [], 'value' => 0]], $metrics['storage_growth_exceeded']);
		}
	}//end testWithoutAThresholdTheGrowthIsPublishedAndNeverFlagged()

	public function testNoVectorsAddedIsZeroGrowth(): void {
		$metrics = self::byName(metrics: $this->provider(bytes: false, threshold: '1')->metrics());

		$this->assertSame([['labels' => [], 'value' => 0]], $metrics['storage_growth_bytes']);
		$this->assertSame([['labels' => [], 'value' => 0]], $metrics['storage_growth_exceeded']);
	}//end testNoVectorsAddedIsZeroGrowth()

	public function testAnUnreadableGrowthPublishesNoGrowthAndNoAlert(): void {
		$this->logger->expects($this->once())->method('warning')->with('[Observability] vector storage growth could not be read: no such table');

		$metrics = self::byName(metrics: $this->provider(bytes: 0, threshold: '1', fails: true)->metrics());

		$this->assertSame([], $metrics['storage_growth_bytes']);
		$this->assertSame([['labels' => [], 'value' => 0]], $metrics['storage_growth_exceeded']);
	}//end testAnUnreadableGrowthPublishesNoGrowthAndNoAlert()
}//end class
