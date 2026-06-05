<?php

declare(strict_types=1);

<<<<<<< HEAD
/*
 * Tests for StreamYieldChannel.
 *
 * Covers the four register/emit pairs plus the multi-callback +
 * late-registration semantics defined in tasks §1.3:
 *
 *  - single callback per event type fires
 *  - two callbacks for the same event fire in registration order
 *  - callback registered after a prior emit only sees subsequent events
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Chat
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/ai-chat-companion-streaming/tasks.md#1
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Chat;
=======
namespace Unit\Service\Chat;
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
use PHPUnit\Framework\TestCase;

<<<<<<< HEAD
class StreamYieldChannelTest extends TestCase
{

    public function testSingleTokenCallbackReceivesEachDelta(): void
    {
        $channel  = new StreamYieldChannel();
        $captured = [];
        $channel->onToken(static function (string $delta) use (&$captured): void {
            $captured[] = $delta;
        });

        $channel->emitToken(delta: 'Hel');
        $channel->emitToken(delta: 'lo');

        $this->assertSame(['Hel', 'lo'], $captured);
    }//end testSingleTokenCallbackReceivesEachDelta()

    public function testSingleToolCallCallbackReceivesAssembledPayload(): void
    {
        $channel  = new StreamYieldChannel();
        $captured = [];
        $channel->onToolCall(static function (array $payload) use (&$captured): void {
            $captured[] = $payload;
        });

        $payload = ['toolId' => 'decidesk.createMeeting', 'arguments' => ['title' => 'sync']];
        $channel->emitToolCall(payload: $payload);

        $this->assertCount(1, $captured);
        $this->assertSame($payload, $captured[0]);
    }//end testSingleToolCallCallbackReceivesAssembledPayload()

    public function testSingleToolResultCallbackReceivesPayload(): void
    {
        $channel  = new StreamYieldChannel();
        $captured = [];
        $channel->onToolResult(static function (array $payload) use (&$captured): void {
            $captured[] = $payload;
        });

        $payload = ['toolId' => 'decidesk.createMeeting', 'result' => ['id' => 42], 'isError' => false];
        $channel->emitToolResult(payload: $payload);

        $this->assertSame([$payload], $captured);
    }//end testSingleToolResultCallbackReceivesPayload()

    public function testSingleHeartbeatCallbackFires(): void
    {
        $channel = new StreamYieldChannel();
        $count   = 0;
        $channel->onHeartbeat(static function () use (&$count): void {
            $count++;
        });

        $channel->emitHeartbeat();
        $channel->emitHeartbeat();

        $this->assertSame(2, $count);
    }//end testSingleHeartbeatCallbackFires()

    public function testTwoTokenCallbacksFireInRegistrationOrder(): void
    {
        $channel = new StreamYieldChannel();
        $order   = [];
        $channel->onToken(static function (string $delta) use (&$order): void {
            $order[] = 'first:'.$delta;
        });
        $channel->onToken(static function (string $delta) use (&$order): void {
            $order[] = 'second:'.$delta;
        });

        $channel->emitToken(delta: 'X');

        $this->assertSame(['first:X', 'second:X'], $order);
    }//end testTwoTokenCallbacksFireInRegistrationOrder()

    public function testTwoToolCallCallbacksFireInRegistrationOrder(): void
    {
        $channel = new StreamYieldChannel();
        $order   = [];
        $channel->onToolCall(static function (array $p) use (&$order): void {
            $order[] = 'a';
        });
        $channel->onToolCall(static function (array $p) use (&$order): void {
            $order[] = 'b';
        });

        $channel->emitToolCall(payload: ['toolId' => 'x', 'arguments' => []]);

        $this->assertSame(['a', 'b'], $order);
    }//end testTwoToolCallCallbacksFireInRegistrationOrder()

    /**
     * Late-registration: a callback registered after a prior emit
     * MUST only see subsequent events (no replay).
     */
    public function testLateRegisteredTokenCallbackOnlySeesSubsequentEmits(): void
    {
        $channel  = new StreamYieldChannel();
        $early    = [];
        $late     = [];
        $channel->onToken(static function (string $delta) use (&$early): void {
            $early[] = $delta;
        });

        $channel->emitToken(delta: 'first');

        $channel->onToken(static function (string $delta) use (&$late): void {
            $late[] = $delta;
        });

        $channel->emitToken(delta: 'second');

        $this->assertSame(['first', 'second'], $early, 'early callback sees both emits');
        $this->assertSame(['second'], $late, 'late callback only sees post-registration emits');
    }//end testLateRegisteredTokenCallbackOnlySeesSubsequentEmits()

    public function testNoCallbackRegisteredEmitIsNoop(): void
    {
        // Channel with no registrations must not throw on emit. The
        // controller can choose not to register every event type.
        $channel = new StreamYieldChannel();
        $channel->emitToken(delta: 'x');
        $channel->emitToolCall(payload: []);
        $channel->emitToolResult(payload: []);
        $channel->emitHeartbeat();

        $this->assertTrue(true);
    }//end testNoCallbackRegisteredEmitIsNoop()
=======
/**
 * @covers \OCA\OpenRegister\Service\Chat\StreamYieldChannel
 */
class StreamYieldChannelTest extends TestCase
{

    private StreamYieldChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->channel = new StreamYieldChannel();

    }//end setUp()

    public function testSingleTokenCallbackFires(): void
    {
        $received = [];
        $this->channel->onToken(
                function (string $delta) use (&$received): void {
                    $received[] = $delta;
                }
                );

        $this->channel->emitToken('Hello');
        $this->channel->emitToken(' world');

        self::assertSame(['Hello', ' world'], $received);

    }//end testSingleTokenCallbackFires()

    public function testTwoTokenCallbacksBothFireInRegistrationOrder(): void
    {
        $log = [];
        $this->channel->onToken(
                function (string $delta) use (&$log): void {
                    $log[] = 'first:'.$delta;
                }
                );
        $this->channel->onToken(
                function (string $delta) use (&$log): void {
                    $log[] = 'second:'.$delta;
                }
                );

        $this->channel->emitToken('X');

        self::assertSame(['first:X', 'second:X'], $log);

    }//end testTwoTokenCallbacksBothFireInRegistrationOrder()

    public function testLateRegistrationDoesNotReplayPriorEmits(): void
    {
        $received = [];
        $this->channel->emitToken('early');

        $this->channel->onToken(
                function (string $delta) use (&$received): void {
                    $received[] = $delta;
                }
                );

        $this->channel->emitToken('late');

        // Only 'late' should appear — no replay of 'early'.
        self::assertSame(['late'], $received);

    }//end testLateRegistrationDoesNotReplayPriorEmits()

    public function testToolCallCallbackFires(): void
    {
        $captured = null;
        $this->channel->onToolCall(
                function (array $data) use (&$captured): void {
                    $captured = $data;
                }
                );

        $payload = ['toolId' => 'decidesk.createMeeting', 'arguments' => ['title' => 'Standup']];
        $this->channel->emitToolCall($payload);

        self::assertSame($payload, $captured);

    }//end testToolCallCallbackFires()

    public function testToolResultCallbackFires(): void
    {
        $captured = null;
        $this->channel->onToolResult(
                function (array $data) use (&$captured): void {
                    $captured = $data;
                }
                );

        $payload = ['toolId' => 'decidesk.createMeeting', 'result' => ['id' => 42], 'isError' => false];
        $this->channel->emitToolResult($payload);

        self::assertSame($payload, $captured);

    }//end testToolResultCallbackFires()

    public function testHeartbeatCallbackFires(): void
    {
        $fired = 0;
        $this->channel->onHeartbeat(
                function () use (&$fired): void {
                    $fired++;
                }
                );

        $this->channel->emitHeartbeat();
        $this->channel->emitHeartbeat();

        self::assertSame(2, $fired);

    }//end testHeartbeatCallbackFires()

    public function testTwoHeartbeatCallbacksBothFire(): void
    {
        $log = [];
        $this->channel->onHeartbeat(
                function () use (&$log): void {
                    $log[] = 'a';
                }
                );
        $this->channel->onHeartbeat(
                function () use (&$log): void {
                    $log[] = 'b';
                }
                );

        $this->channel->emitHeartbeat();

        self::assertSame(['a', 'b'], $log);

    }//end testTwoHeartbeatCallbacksBothFire()
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
}//end class
