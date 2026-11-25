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
use OCA\OpenRegister\Service\Observability\CacheMetricsProvider;
use OCA\OpenRegister\Service\Observability\DatabaseHealthMetricsProvider;
use OCA\OpenRegister\Service\Observability\ImportJobMetricsProvider;
use OCA\OpenRegister\Service\Observability\ObjectCountMetricsProvider;
use OCA\OpenRegister\Service\Observability\OpenRegisterMetricsProvider;
use OCA\OpenRegister\Service\Observability\RequestAlertMetricsProvider;
use OCA\OpenRegister\Service\Observability\RequestMetricsProvider;
use OCA\OpenRegister\Service\Observability\SearchMetricsProvider;
use OCA\OpenRegister\Service\Observability\StorageGrowthMetricsProvider;
use OCA\OpenRegister\Service\Observability\WebhookDurationMetricsProvider;
use OCA\OpenRegister\Service\Observability\WebhookRetryMetricsProvider;
use OCA\OpenRegister\Kernel\Contracts\Integrations\TaskGaugeInterface;
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
	 * Every joined family is published once: tasks, webhook retries, search, webhook durations, database health, storage growth, requests and errors, object counts, cache hits and misses, import progress, then the request alerts.
	 *
	 * @return void
	 */
	public function testPublishesTheTaskThenTheWebhookGauges(): void {
		$overdue = new MetricSample(name: 'tasks_overdue_total', type: 'gauge', help: 'h', samples: [['labels' => [], 'value' => 2]]);
		$tasks   = $this->createMock(TaskGaugeInterface::class);
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

		$total   = new MetricSample(name: 'objects_total', type: 'gauge', help: 'h', samples: [['labels' => ['register' => '1', 'schema' => '2'], 'value' => 3]]);
		$objects = $this->createMock(ObjectCountMetricsProvider::class);
		$objects->expects($this->once())->method('metrics')->willReturn([$total]);

		$hits   = new MetricSample(name: 'cache_hits_total', type: 'counter', help: 'h', samples: [['labels' => ['cache' => 'name'], 'value' => 1]]);
		$caches = $this->createMock(CacheMetricsProvider::class);
		$caches->expects($this->once())->method('metrics')->willReturn([$hits]);

		$progress = new MetricSample(name: 'import_duration_seconds', type: 'gauge', help: 'h', samples: []);
		$imports  = $this->createMock(ImportJobMetricsProvider::class);
		$imports->expects($this->once())->method('metrics')->willReturn([$progress]);

		$alert  = new MetricSample(name: 'error_rate_exceeded', type: 'gauge', help: 'h', samples: [['labels' => [], 'value' => 0]]);
		$alerts = $this->createMock(RequestAlertMetricsProvider::class);
		$alerts->expects($this->once())->method('metrics')->willReturn([$alert]);

		$metrics = (new OpenRegisterMetricsProvider(
			tasks: $tasks,
			webhooks: new WebhookRetryMetricsProvider(logs: $logs, time: $time),
			search: new SearchMetricsProvider(reader: $reader, logger: $this->createMock(LoggerInterface::class)),
			durations: new WebhookDurationMetricsProvider(logs: $logs, logger: $this->createMock(LoggerInterface::class)),
			database: new DatabaseHealthMetricsProvider(db: $db, time: $time, logger: $this->createMock(LoggerInterface::class)),
			storage: $storage,
			requests: $requests,
			objects: $objects,
			caches: $caches,
			imports: $imports,
			alerts: $alerts
		))->metrics();

		$this->assertSame(
			['tasks_overdue_total', 'webhook_retry_queue_depth', 'search_requests_total', 'search_duration_seconds', 'embeddings_generated_total', 'webhook_delivery_duration_seconds', 'db_connection_healthy', 'storage_growth_exceeded', 'errors_total', 'objects_total', 'cache_hits_total', 'import_duration_seconds', 'error_rate_exceeded'],
			array_map(static fn (MetricSample $m): string => $m->name, $metrics)
		);
		$this->assertSame($overdue, $metrics[0]);
		$this->assertSame([['labels' => [], 'value' => 5]], $metrics[1]->samples);
		$this->assertSame($total, $metrics[9]);
		$this->assertSame($hits, $metrics[10]);
		$this->assertSame($progress, $metrics[11]);
		$this->assertSame($alert, $metrics[12]);
	}//end testPublishesTheTaskThenTheWebhookGauges()
}//end class
