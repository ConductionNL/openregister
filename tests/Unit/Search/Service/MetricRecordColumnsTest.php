<?php

/**
 * Each field of a MetricRecord lands in its own metrics column.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service;

use OCA\OpenRegister\Search\Service\MetricOutcome;
use OCA\OpenRegister\Search\Service\MetricRecord;
use OCA\OpenRegister\Search\Service\MetricsService;
use OCA\OpenRegister\Search\Service\MetricSubject;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The record's type, subject, outcome and metadata reach the INSERT unchanged.
 */
class MetricRecordColumnsTest extends TestCase {
	/**
	 * Values passed to IQueryBuilder::values(), keyed by column.
	 *
	 * @var array<string, mixed>
	 */
	private array $inserted = [];

	/**
	 * A metrics service whose query builder echoes each parameter back.
	 *
	 * @return MetricsService
	 */
	private function service(): MetricsService {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('insert')->willReturn($qb);
		$qb->method('values')->willReturnCallback(
			function (array $values) use ($qb): IQueryBuilder {
				$this->inserted = $values;
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturn(1);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new MetricsService($db, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Every field is written to its column.
	 *
	 * @return void
	 */
	public function testEveryFieldReachesItsColumn(): void {
		$this->service()->recordMetric(
			new MetricRecord(
				MetricsService::METRIC_EMBEDDING_GENERATED,
				new MetricSubject('object', 'obj-1', 'alice'),
				new MetricOutcome('failure', 250, 'Connection timeout'),
				['model' => 'test']
			)
		);

		$this->assertSame(MetricsService::METRIC_EMBEDDING_GENERATED, $this->inserted['metric_type']);
		$this->assertSame('object', $this->inserted['entity_type']);
		$this->assertSame('obj-1', $this->inserted['entity_id']);
		$this->assertSame('alice', $this->inserted['user_id']);
		$this->assertSame('failure', $this->inserted['status']);
		$this->assertSame(250, $this->inserted['duration_ms']);
		$this->assertSame('Connection timeout', $this->inserted['error_message']);
		$this->assertSame(['model' => 'test'], json_decode($this->inserted['metadata'], true));
	}//end testEveryFieldReachesItsColumn()

	/**
	 * A record naming only its type keeps the old defaults: no subject, success, no duration or error.
	 *
	 * @return void
	 */
	public function testTypeOnlyRecordKeepsTheDefaults(): void {
		$this->service()->recordMetric(new MetricRecord(MetricsService::METRIC_FILE_PROCESSED));

		$this->assertSame(MetricsService::METRIC_FILE_PROCESSED, $this->inserted['metric_type']);
		$this->assertNull($this->inserted['entity_type']);
		$this->assertNull($this->inserted['entity_id']);
		$this->assertNull($this->inserted['user_id']);
		$this->assertSame('success', $this->inserted['status']);
		$this->assertNull($this->inserted['duration_ms']);
		$this->assertNull($this->inserted['error_message']);
	}//end testTypeOnlyRecordKeepsTheDefaults()
}//end class
