<?php

/**
 * Behaviour tests for GraphQLSubscriptionController: events that arrive while polling.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed fixture properties are self-describing.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

// The poll loop only ends through connection_aborted(). The contract test next door
// shadows that function in the controller's namespace (returning 1, so one poll runs);
// loading it here, once, keeps that single definition and lets this file run alone.
require_once __DIR__ . '/../../Controller/GraphQLSubscriptionControllerTest.php';

use OCA\OpenRegister\Notifications\Controller\GraphQLSubscriptionController;
use OCA\OpenRegister\Notifications\Service\GraphQL\SubscriptionService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GraphQLSubscriptionController::class)]
class GraphQLSubscriptionControllerBehaviourTest extends TestCase {

	public function testEventsArrivingDuringThePollAreStreamedAfterTheFirstHeartbeat(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$request->method('getHeader')->willReturn('');
		$service = $this->createMock(SubscriptionService::class);
		$service->expects($this->exactly(2))->method('getEventsSince')
			->willReturnOnConsecutiveCalls([], [['id' => 'evt-9']]);
		$service->method('formatAsSSE')->willReturnCallback(static fn (array $event): string => 'id: ' . $event['id'] . "\n\n");

		$controller = new GraphQLSubscriptionController($request, $service);
		ob_start();
		ob_start();
		try {
			$controller->subscribe();
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertSame(": heartbeat\n\nid: evt-9\n\n: heartbeat\n\n", $output);
	}
}
