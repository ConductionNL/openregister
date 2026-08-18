<?php

/**
 * Contract tests for GraphQLSubscriptionController::subscribe().
 *
 * Covers GET /api/graphql/subscribe — the Server-Sent Events stream that
 * carries GraphQL subscription events.
 *
 * SSE is the one controller shape whose contract is NOT the returned Response:
 * `subscribe()` returns an empty Response and delivers everything by echoing
 * frames. The tests below therefore capture the echoed bytes and assert on
 * them — the replay window taken from `Last-Event-ID`, the schema/register
 * filters, the frames themselves, and the keep-alive heartbeat.
 *
 * The poll loop only leaves through `connection_aborted()`, which is hard-wired
 * to 0 under the CLI SAPI and would pin every run to the controller's 30-second
 * ceiling. The namespace-scoped shadow below makes the client "disconnect"
 * after the first poll: PHP resolves an unqualified call to
 * `connection_aborted()` inside `namespace OCA\OpenRegister\Controller` against
 * that namespace first. `GraphQLSubscriptionController` is the only class in
 * the app that calls it, so the shadow reaches nothing else.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller {

	/**
	 * Test-only shadow of the global `connection_aborted()`.
	 *
	 * Returning 1 ends the controller's keep-alive poll after a single
	 * iteration instead of after its 30-second wall-clock ceiling.
	 *
	 * @return int 1 — "the client has gone away".
	 */
	function connection_aborted(): int {
		return 1;
	}//end connection_aborted()

}

namespace OCA\OpenRegister\Tests\Unit\Controller {

	// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
	// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

	use OCA\OpenRegister\Controller\GraphQLSubscriptionController;
	use OCA\OpenRegister\Service\GraphQL\SubscriptionService;
	use OCP\AppFramework\Http\Response;
	use OCP\IRequest;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;

	class GraphQLSubscriptionControllerTest extends TestCase {
		/**
		 * The controller under test.
		 *
		 * @var GraphQLSubscriptionController
		 */
		private GraphQLSubscriptionController $controller;

		/**
		 * The mocked HTTP request.
		 *
		 * @var IRequest&MockObject
		 */
		private IRequest&MockObject $request;

		/**
		 * The mocked subscription event source.
		 *
		 * @var SubscriptionService&MockObject
		 */
		private SubscriptionService&MockObject $subscriptionService;

		protected function setUp(): void {
			parent::setUp();

			$this->request = $this->createMock(IRequest::class);
			$this->subscriptionService = $this->createMock(SubscriptionService::class);

			$this->controller = new GraphQLSubscriptionController(
				'openregister',
				$this->request,
				$this->subscriptionService
			);
		}

		/**
		 * Wire the request query parameters and the reconnection header.
		 *
		 * @param array<string, string|null> $params Query parameters by name.
		 * @param string|null $lastEventId The `Last-Event-ID` reconnection header.
		 *
		 * @return void
		 */
		private function givenRequest(array $params, ?string $lastEventId): void {
			$this->request->method('getParam')
				->willReturnCallback(
					static function (string $key, $default = null) use ($params) {
						return ($params[$key] ?? $default);
					}
				);
			$this->request->method('getHeader')
				->willReturn((string)$lastEventId);
		}

		/**
		 * Run subscribe() while capturing the frames it echoes.
		 *
		 * Two buffers are opened on purpose: the controller unconditionally
		 * ends one output-buffer level of its own, and it must end OURS rather
		 * than the one PHPUnit uses to capture test output.
		 *
		 * @return array{0: Response, 1: string} The response and the echoed bytes.
		 */
		private function runSubscribe(): array {
			ob_start();
			ob_start();
			try {
				$response = $this->controller->subscribe();
			} finally {
				$output = ob_get_clean();
			}

			return [$response, (string)$output];
		}

		/**
		 * The stream must replay everything after `Last-Event-ID` and then keep
		 * polling from the LAST replayed id — not from the reconnect id again,
		 * which would resend the same frames forever.
		 *
		 * @return void
		 */
		public function testSubscribeReplaysBufferedEventsAndAdvancesTheCursor(): void {
			$this->givenRequest(['schema' => '7', 'register' => '3'], 'evt-41');

			$events = [
				['id' => 'evt-42', 'type' => 'objectUpdated'],
				['id' => 'evt-43', 'type' => 'objectCreated'],
			];

			$calls = [];
			$this->subscriptionService->method('getEventsSince')
				->willReturnCallback(
					static function ($lastId, $schemaId, $registerId) use (&$calls, $events) {
						$calls[] = [$lastId, $schemaId, $registerId];
						if (count($calls) === 1) {
							return $events;
						}

						return [];
					}
				);
			$this->subscriptionService->method('formatAsSSE')
				->willReturnCallback(
					static function (array $event): string {
						return 'id: ' . $event['id'] . "\ndata: {\"type\":\"" . $event['type'] . "\"}\n\n";
					}
				);

			[$response, $output] = $this->runSubscribe();

			$this->assertInstanceOf(Response::class, $response);

			// Both buffered frames were written, in order.
			$this->assertStringContainsString('id: evt-42', $output);
			$this->assertStringContainsString('id: evt-43', $output);
			$this->assertLessThan(
				strpos($output, 'id: evt-43'),
				strpos($output, 'id: evt-42'),
				'SSE frames must be emitted in buffer order'
			);

			// Replay window, then the poll resumed from the LAST replayed id.
			$this->assertCount(2, $calls);
			$this->assertSame(['evt-41', 7, 3], $calls[0]);
			$this->assertSame(['evt-43', 7, 3], $calls[1]);
		}

		/**
		 * Both filters are optional; when absent they must stay null so the
		 * service streams across every register/schema the caller may read,
		 * rather than being coerced to the integer 0 (which matches nothing).
		 *
		 * @return void
		 */
		public function testSubscribeLeavesAbsentFiltersNull(): void {
			$this->givenRequest([], null);

			$calls = [];
			$this->subscriptionService->method('getEventsSince')
				->willReturnCallback(
					static function ($lastId, $schemaId, $registerId) use (&$calls) {
						$calls[] = [$lastId, $schemaId, $registerId];
						return [];
					}
				);

			[$response, $output] = $this->runSubscribe();

			$this->assertInstanceOf(Response::class, $response);
			$this->assertNotEmpty($calls);
			$this->assertSame([null, null, null], $calls[0]);
			// With no events at all the stream must still say something, or an
			// idle connection is indistinguishable from a hung one.
			$this->assertStringContainsString(': heartbeat', $output);
		}

		/**
		 * A stream with nothing to send must still emit keep-alive comments —
		 * once immediately and once per poll — or proxies drop the connection.
		 *
		 * @return void
		 */
		public function testSubscribeEmitsKeepAliveHeartbeats(): void {
			$this->givenRequest(['schema' => '1'], null);

			$this->subscriptionService->method('getEventsSince')->willReturn([]);
			$this->subscriptionService->expects($this->never())->method('formatAsSSE');

			[, $output] = $this->runSubscribe();

			$this->assertSame(2, substr_count($output, ': heartbeat'));
		}
	}

}
