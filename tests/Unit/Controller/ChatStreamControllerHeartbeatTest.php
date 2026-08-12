<?php

declare(strict_types=1);

/*
 * Tests for the ChatStreamController heartbeat-interleave ticker.
 *
 * Extends the existing TestableChatStreamController pattern from
 * ChatStreamControllerTest.php: captures SSE frames in-memory instead of
 * echoing them. Adds a fake-clock override of the protected now() hook so
 * tests can drive controllable wall-clock advances without a real timer.
 *
 * Drives forwardWithHeartbeat() directly (it's protected → subclass exposes
 * a public proxy) so the test isolates the ticker logic from the full
 * stream() entry flow (auth, conversation resolution, channel wiring).
 *
 * Acceptance per tasks §5.4:
 *  - three token frames at +7s / +8s / +7s gaps → 0 interleaved heartbeats
 *  - one token at +20s gap → 1 interleaved heartbeat
 *  - one token at +40s gap → 2 interleaved heartbeats (15s + 15s)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#5
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ChatStreamController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Service\ChatService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Subclass capturing SSE frames + driving a fake clock for now(). Reuses
 * the existing capture pattern but adds a publicly settable $fakeNow.
 */
class HeartbeatTestableChatStreamController extends ChatStreamController {

	/**
	 * @var array<int, array{type: string, payload: array}>
	 */
	public array $capturedEvents = [];

	/**
	 * Controllable wall-clock value returned by now(). Tests advance it
	 * between forwardWithHeartbeat() calls.
	 */
	public float $fakeNow = 0.0;

	protected function now(): float {
		return $this->fakeNow;
	}//end now()

	protected function emitSseEvent(string $eventType, array $payload): void {
		$this->capturedEvents[] = ['type' => $eventType, 'payload' => $payload];
	}//end emitSseEvent()

	/**
	 * Expose the protected forwardWithHeartbeat for direct testing.
	 */
	public function forward(string $eventType, array $payload): void {
		$this->forwardWithHeartbeat(eventType: $eventType, payload: $payload);
	}//end forward()

	/**
	 * Expose the private $lastEventAt so tests can seed it after the
	 * "initial heartbeat" moment without going through the full stream()
	 * setup.
	 */
	public function seedLastEventAt(float $value): void {
		$reflection = new ReflectionClass(ChatStreamController::class);
		$prop = $reflection->getProperty('lastEventAt');
		$prop->setAccessible(true);
		$prop->setValue($this, $value);
	}//end seedLastEventAt()
}//end class

class ChatStreamControllerHeartbeatTest extends TestCase {

	private function makeController(): HeartbeatTestableChatStreamController {
		return new HeartbeatTestableChatStreamController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			chatService: $this->createMock(ChatService::class),
			conversationMapper: $this->createMock(ConversationMapper::class),
			agentMapper: $this->createMock(AgentMapper::class),
			logger: $this->createMock(LoggerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			db: $this->createMock(IDBConnection::class)
		);
	}//end makeController()

	/**
	 * §5.4 case A — sub-15s gaps never interleave a heartbeat.
	 */
	public function testSubFifteenSecondGapsEmitNoInterleavedHeartbeat(): void {
		$controller = $this->makeController();
		$controller->fakeNow = 100.0;
		$controller->seedLastEventAt(100.0);

		// +7s
		$controller->fakeNow = 107.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'a']);

		// +8s
		$controller->fakeNow = 115.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'b']);

		// +7s
		$controller->fakeNow = 122.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'c']);

		$heartbeats = array_values(
			array_filter(
				$controller->capturedEvents,
				static fn (array $e): bool => $e['type'] === 'heartbeat'
			)
		);
		$this->assertCount(0, $heartbeats, 'no heartbeats must interleave when each gap is under 15s');

		$tokens = array_values(
			array_filter(
				$controller->capturedEvents,
				static fn (array $e): bool => $e['type'] === 'token'
			)
		);
		$this->assertCount(3, $tokens);
	}//end testSubFifteenSecondGapsEmitNoInterleavedHeartbeat()

	/**
	 * §5.4 case B — a single 20s gap triggers exactly one interleaved
	 * heartbeat right before the token.
	 */
	public function testTwentySecondGapTriggersOneInterleavedHeartbeat(): void {
		$controller = $this->makeController();
		$controller->fakeNow = 200.0;
		$controller->seedLastEventAt(200.0);

		// +20s
		$controller->fakeNow = 220.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'late']);

		$this->assertSame(
			['heartbeat', 'token'],
			array_column($controller->capturedEvents, 'type'),
			'heartbeat must precede the late token frame'
		);
	}//end testTwentySecondGapTriggersOneInterleavedHeartbeat()

	/**
	 * §5.4 case C — a 40s gap triggers two heartbeats. The first interleave
	 * fires at the 15s threshold; the next forward() call still sees a
	 * >15s gap from the new $lastEventAt (which was reset by the first
	 * heartbeat emit) and triggers a second.
	 *
	 * To exercise this scenario without per-token simulation we drive two
	 * forward() invocations across the gap: the first at +20s and the
	 * second at +40s. The second should still trigger a heartbeat because
	 * the elapsed time from its own $lastEventAt is 20s.
	 */
	public function testFortySecondTotalElapsedTriggersTwoHeartbeats(): void {
		$controller = $this->makeController();
		$controller->fakeNow = 300.0;
		$controller->seedLastEventAt(300.0);

		// First forward at +20s → 1 heartbeat + 1 token.
		$controller->fakeNow = 320.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'one']);

		// Second forward at +40s from origin (+20s from previous token) → 1 heartbeat + 1 token.
		$controller->fakeNow = 340.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'two']);

		$heartbeats = array_values(
			array_filter(
				$controller->capturedEvents,
				static fn (array $e): bool => $e['type'] === 'heartbeat'
			)
		);
		$this->assertCount(2, $heartbeats, 'two interleaved heartbeats must fire across the gap');

		$this->assertSame(
			['heartbeat', 'token', 'heartbeat', 'token'],
			array_column($controller->capturedEvents, 'type'),
			'frames must interleave heartbeat-then-token twice'
		);
	}//end testFortySecondTotalElapsedTriggersTwoHeartbeats()

	/**
	 * Edge case — a non-token frame (tool_call) triggers the same interleave.
	 */
	public function testToolCallFrameAlsoTriggersInterleavedHeartbeat(): void {
		$controller = $this->makeController();
		$controller->fakeNow = 0.0;
		$controller->seedLastEventAt(0.0);

		$controller->fakeNow = 20.0;
		$controller->forward(
			eventType: 'tool_call',
			payload: ['toolId' => 'x.y', 'arguments' => []]
		);

		$this->assertSame(
			['heartbeat', 'tool_call'],
			array_column($controller->capturedEvents, 'type')
		);
	}//end testToolCallFrameAlsoTriggersInterleavedHeartbeat()
}//end class
