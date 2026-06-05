<?php

declare(strict_types=1);

<<<<<<< HEAD
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
=======
namespace Unit\Controller;
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

use OCA\OpenRegister\Controller\ChatStreamController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Service\ChatService;
<<<<<<< HEAD
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
class HeartbeatTestableChatStreamController extends ChatStreamController
{

    /**
     * @var array<int, array{type: string, payload: array}>
     */
    public array $capturedEvents = [];

    /**
     * Controllable wall-clock value returned by now(). Tests advance it
     * between forwardWithHeartbeat() calls.
     */
    public float $fakeNow = 0.0;

    protected function now(): float
    {
        return $this->fakeNow;
    }//end now()

    protected function emitSseEvent(string $eventType, array $payload): void
    {
        $this->capturedEvents[] = ['type' => $eventType, 'payload' => $payload];
    }//end emitSseEvent()

    /**
     * Expose the protected forwardWithHeartbeat for direct testing.
     */
    public function forward(string $eventType, array $payload): void
    {
        $this->forwardWithHeartbeat(eventType: $eventType, payload: $payload);
    }//end forward()

    /**
     * Expose the private $lastEventAt so tests can seed it after the
     * "initial heartbeat" moment without going through the full stream()
     * setup.
     */
    public function seedLastEventAt(float $value): void
    {
        $reflection = new ReflectionClass(ChatStreamController::class);
        $prop       = $reflection->getProperty('lastEventAt');
        $prop->setAccessible(true);
        $prop->setValue($this, $value);
    }//end seedLastEventAt()
}//end class

class ChatStreamControllerHeartbeatTest extends TestCase
{

    private function makeController(): HeartbeatTestableChatStreamController
    {
        return new HeartbeatTestableChatStreamController(
            appName: 'openregister',
=======
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the heartbeat interleave logic of ChatStreamController via the now() hook.
 *
 * Driving a fake clock lets us verify the 15-second threshold without sleeping.
 *
 * @covers \OCA\OpenRegister\Controller\ChatStreamController
 */
class ChatStreamControllerHeartbeatTest extends TestCase
{
    /**
     * Build a testable subclass with controllable clock and SSE output capture.
     *
     * @param IRequest            $request             Request mock.
     * @param ChatService         $chatService         Chat service mock.
     * @param ConversationMapper  $conversationMapper  Conversation mapper mock.
     * @param AgentMapper         $agentMapper         Agent mapper mock.
     * @param OrganisationService $organisationService Organisation service mock.
     * @param SettingsService     $settingsService     Settings service mock.
     * @param LoggerInterface     $logger              Logger mock.
     * @param string              $userId              User ID.
     *
     * @return object Testable ChatStreamController subclass.
     */
    private function buildTestableChatStreamController(
        IRequest $request,
        ChatService $chatService,
        ConversationMapper $conversationMapper,
        AgentMapper $agentMapper,
        OrganisationService $organisationService,
        SettingsService $settingsService,
        LoggerInterface $logger,
        string $userId
    ): object {
        return new class (
            'openregister',
            $request,
            $chatService,
            $conversationMapper,
            $agentMapper,
            $organisationService,
            $settingsService,
            $logger,
            $userId
        ) extends ChatStreamController {

            /**
             * Sequence of timestamps returned by now().
             *
             * @var float[]
             */
            public array $nowSequence = [];

            /**
             * Current index into nowSequence.
             *
             * @var integer
             */
            private int $nowIndex = 0;

            /**
             * Captured SSE events.
             *
             * @var array[]
             */
            public array $emittedEvents = [];

            protected function now(): float
            {
                if (isset($this->nowSequence[$this->nowIndex]) === true) {
                    return $this->nowSequence[$this->nowIndex++];
                }

                return end($this->nowSequence) ?: 0.0;

            }//end now()

            protected function emitSseEvent(string $eventType, array $payload): void
            {
                $this->emittedEvents[] = ['type' => $eventType, 'payload' => $payload];

            }//end emitSseEvent()

            /**
             * Expose forwardWithHeartbeat for direct testing.
             *
             * @param string $eventType    SSE event type.
             * @param array  $payload      Payload.
             * @param float  &$lastEventAt Reference to last-event timestamp.
             *
             * @return void
             */
            public function callForwardWithHeartbeat(
                string $eventType,
                array $payload,
                float &$lastEventAt
            ): void {
                $this->forwardWithHeartbeat(
                    eventType: $eventType,
                    payload: $payload,
                    lastEventAt: $lastEventAt
                );

            }//end callForwardWithHeartbeat()
        };

    }//end buildTestableChatStreamController()

    /**
     * Build a controller with all dependencies mocked.
     *
     * @return object Testable ChatStreamController.
     */
    private function buildController(): object
    {
        return $this->buildTestableChatStreamController(
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
            request: $this->createMock(IRequest::class),
            chatService: $this->createMock(ChatService::class),
            conversationMapper: $this->createMock(ConversationMapper::class),
            agentMapper: $this->createMock(AgentMapper::class),
<<<<<<< HEAD
            logger: $this->createMock(LoggerInterface::class),
            userSession: $this->createMock(IUserSession::class),
            db: $this->createMock(IDBConnection::class)
        );
    }//end makeController()

    /**
     * §5.4 case A — sub-15s gaps never interleave a heartbeat.
     */
    public function testSubFifteenSecondGapsEmitNoInterleavedHeartbeat(): void
    {
        $controller          = $this->makeController();
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
    public function testTwentySecondGapTriggersOneInterleavedHeartbeat(): void
    {
        $controller          = $this->makeController();
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
    public function testFortySecondTotalElapsedTriggersTwoHeartbeats(): void
    {
        $controller          = $this->makeController();
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
    public function testToolCallFrameAlsoTriggersInterleavedHeartbeat(): void
    {
        $controller          = $this->makeController();
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
=======
            organisationService: $this->createMock(OrganisationService::class),
            settingsService: $this->createMock(SettingsService::class),
            logger: $this->createMock(LoggerInterface::class),
            userId: 'admin'
        );

    }//end buildController()

    /**
     * No heartbeat when all tokens arrive within the 15s window.
     *
     * @return void
     */
    public function testNoHeartbeatWhenGapUnder15Seconds(): void
    {
        $ctrl = $this->buildController();

        // All events arrive within 7s of each other — never crosses the 15s threshold.
        // now() is called twice per forwardWithHeartbeat: once in the while check, once after emit.
        $ctrl->nowSequence = [
            0.0,
        // token1: while check (gap 0-0=0 < 15 — no heartbeat).
            7.0,
        // token1: after emit.
            7.0,
        // token2: while check (gap 7-7=0 < 15 — no heartbeat).
            14.0,
        // token2: after emit.
            14.0,
        // token3: while check (gap 14-14=0 < 15 — no heartbeat).
            21.0,
        // token3: after emit.
        ];

        $lastEventAt = 0.0;

        $ctrl->callForwardWithHeartbeat(eventType: 'token', payload: ['delta' => 'A'], lastEventAt: $lastEventAt);
        $ctrl->callForwardWithHeartbeat(eventType: 'token', payload: ['delta' => 'B'], lastEventAt: $lastEventAt);
        $ctrl->callForwardWithHeartbeat(eventType: 'token', payload: ['delta' => 'C'], lastEventAt: $lastEventAt);

        $heartbeats = array_filter($ctrl->emittedEvents, fn($e) => $e['type'] === 'heartbeat');
        self::assertCount(
            expectedCount: 0,
            haystack: $heartbeats,
            message: 'No heartbeats expected when gap < 15s.'
        );

        $tokens = array_filter($ctrl->emittedEvents, fn($e) => $e['type'] === 'token');
        self::assertCount(
            expectedCount: 3,
            haystack: $tokens,
            message: 'All 3 token events must be emitted.'
        );

    }//end testNoHeartbeatWhenGapUnder15Seconds()

    /**
     * Exactly one heartbeat when the gap between events is 20 seconds.
     *
     * @return void
     */
    public function testOneHeartbeatWhenGapIs20Seconds(): void
    {
        $ctrl = $this->buildController();

        // Token arrives 20s after last event → 1 heartbeat interleaved before it.
        $ctrl->nowSequence = [
            20.0,
        // While check: gap 20-0=20 >= 15 → emit heartbeat.
            20.0,
        // Reset lastEventAt after heartbeat.
            20.0,
        // While check again: gap 0 < 15 → stop.
            20.0,
        // After token emit.
        ];

        $lastEventAt = 0.0;

        $ctrl->callForwardWithHeartbeat(eventType: 'token', payload: ['delta' => 'X'], lastEventAt: $lastEventAt);

        $heartbeats = array_filter($ctrl->emittedEvents, fn($e) => $e['type'] === 'heartbeat');
        self::assertCount(
            expectedCount: 1,
            haystack: $heartbeats,
            message: 'Exactly one heartbeat when gap is 20s.'
        );

        $tokens = array_filter($ctrl->emittedEvents, fn($e) => $e['type'] === 'token');
        self::assertCount(
            expectedCount: 1,
            haystack: $tokens,
            message: 'The token event must also be emitted.'
        );

    }//end testOneHeartbeatWhenGapIs20Seconds()

    /**
     * Two heartbeats when the stall is long enough to cross two 15s windows.
     *
     * @return void
     */
    public function testTwoHeartbeatsWhenGapIs35Seconds(): void
    {
        $ctrl = $this->buildController();

        // Fake clock advances: first check at t=35 (gap 35 >= 15) → heartbeat,
        // reset to t=35. Second check at t=50 (gap 15 >= 15) → heartbeat, reset to t=50.
        // Third check: gap 0 < 15 → stop. Then emit token.
        $ctrl->nowSequence = [
            35.0,
        // Iter 1: while check (gap 35-0=35 >= 15) → emit heartbeat.
            35.0,
        // Iter 1: reset lastEventAt.
            50.0,
        // Iter 2: while check (gap 50-35=15 >= 15) → emit heartbeat.
            50.0,
        // Iter 2: reset lastEventAt.
            50.0,
        // Iter 3: while check (gap 50-50=0 < 15) → stop.
            50.0,
        // After token emit.
        ];

        $lastEventAt = 0.0;

        $ctrl->callForwardWithHeartbeat(eventType: 'token', payload: ['delta' => 'Z'], lastEventAt: $lastEventAt);

        $heartbeats = array_filter($ctrl->emittedEvents, fn($e) => $e['type'] === 'heartbeat');
        self::assertCount(
            expectedCount: 2,
            haystack: $heartbeats,
            message: 'Exactly two heartbeats for a ~35s stall.'
        );

        $tokens = array_filter($ctrl->emittedEvents, fn($e) => $e['type'] === 'token');
        self::assertCount(
            expectedCount: 1,
            haystack: $tokens,
            message: 'The token event must be emitted after the heartbeats.'
        );

    }//end testTwoHeartbeatsWhenGapIs35Seconds()

    /**
     * Heartbeat events must appear before the token in the emitted sequence.
     *
     * @return void
     */
    public function testEventOrderIsHeartbeatsBeforeToken(): void
    {
        $ctrl = $this->buildController();

        $ctrl->nowSequence = [
            20.0,
            20.0,
            20.0,
            20.0,
        ];

        $lastEventAt = 0.0;
        $ctrl->callForwardWithHeartbeat(eventType: 'token', payload: ['delta' => 'Y'], lastEventAt: $lastEventAt);

        $types = array_column($ctrl->emittedEvents, 'type');
        self::assertSame(
            expected: ['heartbeat', 'token'],
            actual: $types,
            message: 'Heartbeat must precede the token.'
        );

    }//end testEventOrderIsHeartbeatsBeforeToken()
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
}//end class
