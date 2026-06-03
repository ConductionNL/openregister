<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ChatStreamController;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Service\ChatService;
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
            request: $this->createMock(IRequest::class),
            chatService: $this->createMock(ChatService::class),
            conversationMapper: $this->createMock(ConversationMapper::class),
            agentMapper: $this->createMock(AgentMapper::class),
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
}//end class
