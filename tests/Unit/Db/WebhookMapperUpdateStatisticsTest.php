<?php

/**
 * Characterisation of WebhookMapper::updateStatistics().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use PHPUnit\Framework\TestCase;

/**
 * The counters and timestamps one delivery outcome writes, and that it is persisted.
 */
final class WebhookMapperUpdateStatisticsTest extends TestCase {

	/**
	 * A success counts the delivery and the success and stamps triggered and success.
	 *
	 * @return void
	 */
	public function testSuccessCountsAndStampsSuccess(): void {
		$webhook = $this->webhook();
		$saved = $this->mapper()->updateStatistics(webhook: $webhook, success: true);

		$this->assertSame($webhook, $saved);
		$this->assertSame([5, 3, 1], [$webhook->getTotalDeliveries(), $webhook->getSuccessfulDeliveries(), $webhook->getFailedDeliveries()]);
		$this->assertNotNull($webhook->getLastTriggeredAt());
		$this->assertNotNull($webhook->getLastSuccessAt());
		$this->assertNull($webhook->getLastFailureAt());
	}//end testSuccessCountsAndStampsSuccess()

	/**
	 * A failure counts the delivery and the failure and stamps triggered and failure.
	 *
	 * @return void
	 */
	public function testFailureCountsAndStampsFailure(): void {
		$webhook = $this->webhook();
		$saved = $this->mapper()->updateStatistics(webhook: $webhook, success: false);

		$this->assertSame($webhook, $saved);
		$this->assertSame([5, 2, 2], [$webhook->getTotalDeliveries(), $webhook->getSuccessfulDeliveries(), $webhook->getFailedDeliveries()]);
		$this->assertNotNull($webhook->getLastTriggeredAt());
		$this->assertNull($webhook->getLastSuccessAt());
		$this->assertNotNull($webhook->getLastFailureAt());
	}//end testFailureCountsAndStampsFailure()

	/**
	 * A webhook with 4 deliveries, 2 successful and 1 failed, never triggered.
	 *
	 * @return Webhook The webhook.
	 */
	private function webhook(): Webhook {
		$webhook = new Webhook();
		$webhook->setTotalDeliveries(4);
		$webhook->setSuccessfulDeliveries(2);
		$webhook->setFailedDeliveries(1);
		return $webhook;
	}//end webhook()

	/**
	 * A mapper whose update() hands the entity back once.
	 *
	 * @return WebhookMapper The mapper.
	 */
	private function mapper(): WebhookMapper {
		$mapper = $this->getMockBuilder(WebhookMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['update'])
			->getMock();
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);
		return $mapper;
	}//end mapper()
}//end class
