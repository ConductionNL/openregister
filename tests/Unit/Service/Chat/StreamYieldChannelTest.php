<?php

declare(strict_types=1);

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

use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
use PHPUnit\Framework\TestCase;

class StreamYieldChannelTest extends TestCase {

	public function testSingleTokenCallbackReceivesEachDelta(): void {
		$channel = new StreamYieldChannel();
		$captured = [];
		$channel->onToken(static function (string $delta) use (&$captured): void {
			$captured[] = $delta;
		});

		$channel->emitToken(delta: 'Hel');
		$channel->emitToken(delta: 'lo');

		$this->assertSame(['Hel', 'lo'], $captured);
	}//end testSingleTokenCallbackReceivesEachDelta()

	public function testSingleToolCallCallbackReceivesAssembledPayload(): void {
		$channel = new StreamYieldChannel();
		$captured = [];
		$channel->onToolCall(static function (array $payload) use (&$captured): void {
			$captured[] = $payload;
		});

		$payload = ['toolId' => 'decidesk.createMeeting', 'arguments' => ['title' => 'sync']];
		$channel->emitToolCall(payload: $payload);

		$this->assertCount(1, $captured);
		$this->assertSame($payload, $captured[0]);
	}//end testSingleToolCallCallbackReceivesAssembledPayload()

	public function testSingleToolResultCallbackReceivesPayload(): void {
		$channel = new StreamYieldChannel();
		$captured = [];
		$channel->onToolResult(static function (array $payload) use (&$captured): void {
			$captured[] = $payload;
		});

		$payload = ['toolId' => 'decidesk.createMeeting', 'result' => ['id' => 42], 'isError' => false];
		$channel->emitToolResult(payload: $payload);

		$this->assertSame([$payload], $captured);
	}//end testSingleToolResultCallbackReceivesPayload()

	public function testTwoTokenCallbacksFireInRegistrationOrder(): void {
		$channel = new StreamYieldChannel();
		$order = [];
		$channel->onToken(static function (string $delta) use (&$order): void {
			$order[] = 'first:' . $delta;
		});
		$channel->onToken(static function (string $delta) use (&$order): void {
			$order[] = 'second:' . $delta;
		});

		$channel->emitToken(delta: 'X');

		$this->assertSame(['first:X', 'second:X'], $order);
	}//end testTwoTokenCallbacksFireInRegistrationOrder()

	public function testTwoToolCallCallbacksFireInRegistrationOrder(): void {
		$channel = new StreamYieldChannel();
		$order = [];
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
	public function testLateRegisteredTokenCallbackOnlySeesSubsequentEmits(): void {
		$channel = new StreamYieldChannel();
		$early = [];
		$late = [];
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

	public function testNoCallbackRegisteredEmitIsNoop(): void {
		// Channel with no registrations must not throw on emit. The
		// controller can choose not to register every event type.
		$channel = new StreamYieldChannel();
		$channel->emitToken(delta: 'x');
		$channel->emitToolCall(payload: []);
		$channel->emitToolResult(payload: []);

		$this->assertTrue(true);
	}//end testNoCallbackRegisteredEmitIsNoop()
}//end class
