<?php

declare(strict_types=1);

/**
 * Tests for StreamingToolInstanceWrapper.
 *
 * Verifies the decorator surfaces tool_call BEFORE the wrapped call
 * runs and tool_result AFTER it returns, preserves the wrapped tool's
 * return value verbatim, and re-raises throwables after surfacing an
 * isError tool_result frame.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Tool
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/ai-chat-companion-streaming/specs/chat-ai/spec.md#tool-call-and-tool-result-sse-events
 */

namespace OCA\OpenRegister\Tests\Unit\Tool;

use OCA\OpenRegister\Service\Chat\StreamYieldChannel;
use OCA\OpenRegister\Tool\StreamingToolInstanceWrapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Fake tool with a couple of methods LLPhant would invoke as
 * `$instance->{$funcName}(...$args)`.
 */
class FakeMcpTool {

	/** @var array<int, array{name: string, args: array}> */
	public array $invocations = [];

	public function helloWorld(array $args): array {
		$this->invocations[] = ['name' => 'helloWorld', 'args' => $args];
		return ['ok' => true, 'echoed' => $args];
	}//end helloWorld()

	public function softFail(array $args): array {
		$this->invocations[] = ['name' => 'softFail', 'args' => $args];
		return ['isError' => true, 'error' => 'forbidden', 'message' => 'no'];
	}//end softFail()

	public function hardFail(array $args): never {
		$this->invocations[] = ['name' => 'hardFail', 'args' => $args];
		throw new RuntimeException(message: 'boom in hardFail');
	}//end hardFail()

	public function returnScalar(int $value): int {
		$this->invocations[] = ['name' => 'returnScalar', 'args' => ['value' => $value]];
		return $value * 2;
	}//end returnScalar()
}//end class

class StreamingToolInstanceWrapperTest extends TestCase {

	/** @var array<int, array{kind: string, toolId: string, payload: mixed, isError?: bool}> */
	private array $captured;

	private StreamYieldChannel $channel;

	protected function setUp(): void {
		parent::setUp();
		$this->captured = [];
		$this->channel = new StreamYieldChannel();
		$this->channel->onToolCall(
			function (array $payload): void {
				$this->captured[] = ['kind' => 'tool_call'] + $payload;
			}
		);
		$this->channel->onToolResult(
			function (array $payload): void {
				$this->captured[] = ['kind' => 'tool_result'] + $payload;
			}
		);

	}//end setUp()

	public function testSuccessfulCallEmitsToolCallThenToolResultThenReturns(): void {
		$tool = new FakeMcpTool();
		$wrapper = new StreamingToolInstanceWrapper(wrapped: $tool, channel: $this->channel);

		// LLPhant invokes `$instance->helloWorld($args)` with a single
		// array as the positional arg. The wrapper serialises the tool
		// return to JSON before forwarding to LLPhant — its
		// CalledFunction::__construct enforces ?string on the value.
		$result = $wrapper->helloWorld(['greet' => 'world']);

		$this->assertIsString($result);
		$decoded = json_decode($result, associative: true);
		$this->assertSame(['ok' => true, 'echoed' => ['greet' => 'world']], $decoded);
		$this->assertCount(1, $tool->invocations);

		// tool_call must be emitted BEFORE tool_result.
		$this->assertCount(2, $this->captured);
		$this->assertSame('tool_call', $this->captured[0]['kind']);
		$this->assertSame('helloWorld', $this->captured[0]['toolId']);
		$this->assertSame(['greet' => 'world'], $this->captured[0]['arguments']);

		$this->assertSame('tool_result', $this->captured[1]['kind']);
		$this->assertSame('helloWorld', $this->captured[1]['toolId']);
		$this->assertSame(['ok' => true, 'echoed' => ['greet' => 'world']], $this->captured[1]['result']);
		$this->assertFalse($this->captured[1]['isError']);

	}//end testSuccessfulCallEmitsToolCallThenToolResultThenReturns()

	public function testSoftFailureSurfacesIsErrorOnToolResult(): void {
		$tool = new FakeMcpTool();
		$wrapper = new StreamingToolInstanceWrapper(wrapped: $tool, channel: $this->channel);

		$result = $wrapper->softFail([]);

		$this->assertIsString($result);
		$decoded = json_decode($result, associative: true);
		$this->assertTrue($decoded['isError']);
		$this->assertSame('tool_result', $this->captured[1]['kind']);
		$this->assertTrue($this->captured[1]['isError'], 'isError envelope must propagate to the SSE frame');

	}//end testSoftFailureSurfacesIsErrorOnToolResult()

	public function testHardThrowEmitsErrorToolResultAndReraises(): void {
		$tool = new FakeMcpTool();
		$wrapper = new StreamingToolInstanceWrapper(wrapped: $tool, channel: $this->channel);

		try {
			$wrapper->hardFail([]);
			$this->fail('Expected RuntimeException to propagate');
		} catch (RuntimeException $e) {
			$this->assertSame('boom in hardFail', $e->getMessage());
		}

		// Both frames must be captured even when the wrapped call throws.
		$this->assertCount(2, $this->captured);
		$this->assertSame('tool_call', $this->captured[0]['kind']);
		$this->assertSame('tool_result', $this->captured[1]['kind']);
		$this->assertTrue($this->captured[1]['isError']);
		$this->assertSame(['error' => 'boom in hardFail'], $this->captured[1]['result']);

	}//end testHardThrowEmitsErrorToolResultAndReraises()

	public function testScalarReturnIsWrappedForSseConsumers(): void {
		$tool = new FakeMcpTool();
		$wrapper = new StreamingToolInstanceWrapper(wrapped: $tool, channel: $this->channel);

		$result = $wrapper->returnScalar(21);

		$this->assertSame(42, $result, 'wrapper must preserve scalar return verbatim to the caller');

		// SSE frame, by contrast, normalises the scalar into {value: 42}
		// so downstream consumers always see an object.
		$this->assertSame(['value' => 42], $this->captured[1]['result']);
		$this->assertFalse($this->captured[1]['isError']);

	}//end testScalarReturnIsWrappedForSseConsumers()

	public function testPositionalArgumentsArePreservedInToolCallPayload(): void {
		$tool = new FakeMcpTool();
		$wrapper = new StreamingToolInstanceWrapper(wrapped: $tool, channel: $this->channel);

		$wrapper->returnScalar(7);

		$this->assertSame([7], $this->captured[0]['arguments'], 'positional ints must pass through unflattened');

	}//end testPositionalArgumentsArePreservedInToolCallPayload()
}//end class
