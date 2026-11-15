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
use OCA\OpenRegister\Service\Observability\OpenRegisterMetricsProvider;
use OCA\OpenRegister\Service\Observability\WebhookRetryMetricsProvider;
use OCA\OpenRegister\Service\Task\TaskMetricsProviderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Observability\OpenRegisterMetricsProvider
 * @uses   \OCA\OpenRegister\Service\Observability\WebhookRetryMetricsProvider
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricSample
 */
final class OpenRegisterMetricsProviderTest extends TestCase {

	/**
	 * Every joined gauge is published once, tasks first.
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

		$metrics = (new OpenRegisterMetricsProvider(
			tasks: $tasks,
			webhooks: new WebhookRetryMetricsProvider(logs: $logs, time: $time)
		))->metrics();

		$this->assertSame(['tasks_overdue_total', 'webhook_retry_queue_depth'], array_map(static fn (MetricSample $m): string => $m->name, $metrics));
		$this->assertSame($overdue, $metrics[0]);
		$this->assertSame([['labels' => [], 'value' => 5]], $metrics[1]->samples);
	}//end testPublishesTheTaskThenTheWebhookGauges()
}//end class
