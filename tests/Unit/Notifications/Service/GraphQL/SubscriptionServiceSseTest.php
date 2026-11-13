<?php

/**
 * Unit tests for SubscriptionService::formatAsSSE: one buffered event as a server-sent event.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\GraphQL;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\GraphQL\SubscriptionService;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\GraphQL\SubscriptionService
 */
final class SubscriptionServiceSseTest extends TestCase {

	public function testAnEventBecomesIdTypeAndJsonData(): void {
		$service = new SubscriptionService(
			$this->createMock(SchemaMapperInterface::class),
			$this->createMock(PermissionCheckInterface::class)
		);
		$event = ['id' => 'gql_1', 'action' => 'update', 'timestamp' => 1700000000, 'object' => ['uuid' => 'u-1', 'schema' => 4]];

		$this->assertSame(
			"id: gql_1\nevent: graphql.update\ndata: " . json_encode($event) . "\n\n",
			$service->formatAsSSE($event)
		);
	}

	public function testEachMessageEndsWithABlankLineSoClientsDispatchIt(): void {
		$service = new SubscriptionService(
			$this->createMock(SchemaMapperInterface::class),
			$this->createMock(PermissionCheckInterface::class)
		);
		$sse = $service->formatAsSSE(['id' => 'gql_2', 'action' => 'delete', 'object' => []]);

		$this->assertStringEndsWith("\n\n", $sse);
		$this->assertStringContainsString("event: graphql.delete\n", $sse);
	}
}
