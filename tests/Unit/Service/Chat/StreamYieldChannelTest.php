<?php

declare(strict_types=1);

namespace Unit\Service\Chat;

use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
use PHPUnit\Framework\TestCase;

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
}//end class
