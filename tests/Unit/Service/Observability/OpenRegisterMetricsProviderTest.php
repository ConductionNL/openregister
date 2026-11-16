<?php

/**
 * OpenRegister's joined metrics provider.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

use DateTime;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Search\Service\SearchMetricsReader;
use OCA\OpenRegister\Service\Observability\DatabaseHealthMetricsProvider;
use OCA\OpenRegister\Service\Observability\OpenRegisterMetricsProvider;
use OCA\OpenRegister\Service\Observability\RequestMetricsProvider;
use OCA\OpenRegister\Service\Observability\SearchMetricsProvider;
use OCA\OpenRegister\Service\Observability\StorageGrowthMetricsProvider;
use OCA\OpenRegister\Service\Observability\WebhookDurationMetricsProvider;
use OCA\OpenRegister\Service\Observability\WebhookRetryMetricsProvider;
use OCA\OpenRegister\Service\Task\TaskMetricsProviderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Observability\OpenRegisterMetricsProvider
 * @uses   \OCA\OpenRegister\Service\Observability\WebhookRetryMetricsProvider
 * @uses   \OCA\OpenRegister\Service\Observability\SearchMetricsProvider
 * @uses   \OCA\OpenRegister\Service\Observability\WebhookDurationMetricsProvider
 * @uses   \OCA\OpenRegister\Service\Observability\HistogramPoints
 * @uses   \OCA\OpenRegister\Service\Observability\DatabaseHealthMetricsProvider
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class OpenRegisterMetricsProviderTest extends TestCase {

	/**
	 * Every joined family is published once: tasks, webhook retries, search, webhook durations, database health, storage growth, then requests and errors.
	 *
	 * @return void
	 */
	public function testPublishesTheTaskThenTheWebhookGauges(): void {
		$overdue = new MetricSample(name: 'tasks_overdue_total', type: 'gauge', help: 'h', samples: [['labels' => [], 'value' => 2]]);
		$tasks   = $this->createMock(TaskMetricsProviderInterface::class);
		$tasks->expects($this->once())->method('metrics')->willReturn([$overdue]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime());
		$logs = $this->createMock(WebhookLogMapperInterface::class);
		$logs->expects($this->once())->method('countPendingRetries')->willReturn(5);
		$logs->expects($this->once())->method('deliveryDurationStats')->willReturn(['timed' => 0, 'sumMs' => 0.0, 'buckets' => []]);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('executeQuery')->with('SELECT 1')->willReturn($this->createMock(IResult::class));

		$reader = $this->createMock(SearchMetricsReader::class);
		$reader->expects($this->once())->method('searchStats')->willReturn([]);
		$reader->expects($this->once())->method('embeddingCounts')->willReturn(['success' => 0, 'failure' => 0]);

		$growth = new MetricSample(name: 'storage_growth_exceeded', type: 'gauge', help: 'h', samples: [['labels' => [], 'value' => 0]]);
		$storage = $this->createMock(StorageGrowthMetricsProvider::class);
		$storage->expects($this->once())->method('metrics')->willReturn([$growth]);

		$errors   = new MetricSample(name: 'errors_total', type: 'counter', help: 'h', samples: [['labels' => ['type' => 'other'], 'value' => 0]]);
		$requests = $this->createMock(RequestMetricsProvider::class);
		$requests->expects($this->once())->method('metrics')->willReturn([$errors]);

		$metrics = (new OpenRegisterMetricsProvider(
			tasks: $tasks,
			webhooks: new WebhookRetryMetricsProvider(logs: $logs, time: $time),
			search: new SearchMetricsProvider(reader: $reader, logger: $this->createMock(LoggerInterface::class)),
			durations: new WebhookDurationMetricsProvider(logs: $logs, logger: $this->createMock(LoggerInterface::class)),
			database: new DatabaseHealthMetricsProvider(db: $db, time: $time, logger: $this->createMock(LoggerInterface::class)),
			storage: $storage,
			requests: $requests
		))->metrics();

		$this->assertSame(
			['tasks_overdue_total', 'webhook_retry_queue_depth', 'search_requests_total', 'search_duration_seconds', 'embeddings_generated_total', 'webhook_delivery_duration_seconds', 'db_connection_healthy', 'storage_growth_exceeded', 'errors_total'],
			array_map(static fn (MetricSample $m): string => $m->name, $metrics)
		);
		$this->assertSame($overdue, $metrics[0]);
		$this->assertSame([['labels' => [], 'value' => 5]], $metrics[1]->samples);
	}//end testPublishesTheTaskThenTheWebhookGauges()
}//end class
