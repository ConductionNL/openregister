<?php

/**
 * A failed step with retry left suspends the run and tries again on resume; only the last try reaches onError.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunOrigin;
use OCA\OpenRegister\Service\Flow\FlowRunStart;
use OCA\OpenRegister\Service\Flow\FlowStepAttempts;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/**
 * Holds the marking across walks, as the run row does.
 */
class RetrySubject {
	public array $marking = [];
}//end class

/**
 * Fails step `call` a given number of times, then lets it through.
 */
class FlakyDispatcher implements FlowStepDispatcher {
	public array $ran = [];

	public function __construct(private int $failuresLeft) {
	}//end __construct()

	public function dispatch(array $step, array $items, array $context): array {
		$id = (string)($step['id'] ?? '');
		$this->ran[] = $id;
		if ($id === 'call' && $this->failuresLeft > 0) {
			$this->failuresLeft--;
			throw new RuntimeException('service unavailable');
		}

		return $items;
	}//end dispatch()
}//end class

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowEngine
 * @covers \OCA\OpenRegister\Service\Flow\FlowStepFailure
 * @covers \OCA\OpenRegister\Service\Flow\FlowStepAttempts
 */
class FlowEngineRetryTest extends TestCase {
	private FlowEngine $engine;
	private RetrySubject $subject;
	private MethodMarkingStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->engine  = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger());
		$this->subject = new RetrySubject();
		$this->store   = new MethodMarkingStore(false, 'marking');
	}//end setUp()

	private static function flow(array $retry, string $onError='stop'): array {
		return [
			'id' => 'retrying',
			'nodes' => [
				['id' => 'call', 'type' => 'openregister.http', 'retry' => $retry, 'onError' => $onError],
				['id' => 'after', 'type' => 'openregister.set-fields'],
			],
			'edges' => [['id' => 'e1', 'from' => 'call', 'to' => 'after']],
		];
	}//end flow()

	/**
	 * Walk the flow once, the way FlowRunService resumes it: the stored slots on a fresh resume state.
	 */
	private function walk(array $flow, FlakyDispatcher $dispatcher, ?array $previous): array {
		$context = [FlowResumeState::CONTEXT_KEY => new FlowResumeState()];
		$items   = [FlowItems::item(json: ['id' => 1])];
		if ($previous !== null) {
			$stored  = $previous['context'][FlowResumeState::CONTEXT_KEY];
			$context = ['resuming' => true, FlowResumeState::CONTEXT_KEY => FlowResumeState::fromArray($stored->all())];
			$items   = $previous['items'];
		}

		return $this->engine->run(
			start: (new FlowRunStart(flow: $flow, store: $this->store, subject: $this->subject, dispatcher: $dispatcher))->withContext(context: $context),
			origin: new FlowRunOrigin(items: $items)
		);
	}//end walk()

	/**
	 * @return list<array<string, mixed>> The log entries of the retried step.
	 */
	private static function entriesOf(array $result, string $step): array {
		return array_values(array_filter($result['log'], static fn (array $entry): bool => ($entry['transition'] ?? '') === $step));
	}//end entriesOf()

	public function testAStepThatFailsTwiceThenAnswersCompletesTheRun(): void {
		$flow       = self::flow(retry: ['attempts' => 3, 'delaySeconds' => 60, 'backoff' => 'fixed']);
		$dispatcher = new FlakyDispatcher(failuresLeft: 2);

		$first = $this->walk(flow: $flow, dispatcher: $dispatcher, previous: null);
		self::assertSame(FlowEngine::STATUS_SUSPENDED, $first['status']);
		self::assertInstanceOf(DateTime::class, $first['resumeAt']);
		$wait = ($first['resumeAt']->getTimestamp() - time());
		self::assertGreaterThanOrEqual(55, $wait);
		self::assertLessThanOrEqual(60, $wait);
		$failed = self::entriesOf(result: $first, step: 'call')[0];
		self::assertSame('failed', $failed['status']);
		self::assertSame(1, $failed['attempt']);
		self::assertSame('retrying', $failed['outcome']);
		self::assertSame('service unavailable', $failed['error']);

		$second = $this->walk(flow: $flow, dispatcher: $dispatcher, previous: $first);
		self::assertSame(FlowEngine::STATUS_SUSPENDED, $second['status']);
		self::assertSame(2, self::entriesOf(result: $second, step: 'call')[0]['attempt']);

		$third = $this->walk(flow: $flow, dispatcher: $dispatcher, previous: $second);
		self::assertSame(FlowEngine::STATUS_COMPLETED, $third['status']);
		self::assertSame(['call', 'call', 'call', 'after'], $dispatcher->ran);
		self::assertSame('completed', self::entriesOf(result: $third, step: 'call')[0]['status']);
		self::assertSame(0, FlowStepAttempts::failed(state: $third['context'][FlowResumeState::CONTEXT_KEY], step: 'call'), 'a success forgets the count');
	}//end testAStepThatFailsTwiceThenAnswersCompletesTheRun()

	public function testTheFourthFailureWithThreeAttemptsReachesOnError(): void {
		$flow       = self::flow(retry: ['attempts' => 3, 'delaySeconds' => 0]);
		$dispatcher = new FlakyDispatcher(failuresLeft: 10);

		$result = null;
		for ($walk = 1; $walk <= 4; $walk++) {
			$result = $this->walk(flow: $flow, dispatcher: $dispatcher, previous: $result);
			if ($walk < 4) {
				self::assertSame(FlowEngine::STATUS_SUSPENDED, $result['status'], 'walk '.$walk.' retries');
			}
		}

		self::assertSame(FlowEngine::STATUS_FAILED, $result['status']);
		self::assertSame('service unavailable', $result['error']);
		$last = self::entriesOf(result: $result, step: 'call')[0];
		self::assertSame(4, $last['attempt']);
		self::assertArrayNotHasKey('outcome', $last);
		self::assertSame(['call', 'call', 'call', 'call'], $dispatcher->ran);
	}//end testTheFourthFailureWithThreeAttemptsReachesOnError()

	public function testExponentialBackoffDoublesTheWait(): void {
		$flow       = self::flow(retry: ['attempts' => 3, 'delaySeconds' => 100, 'backoff' => 'exponential']);
		$dispatcher = new FlakyDispatcher(failuresLeft: 10);

		$first  = $this->walk(flow: $flow, dispatcher: $dispatcher, previous: null);
		$second = $this->walk(flow: $flow, dispatcher: $dispatcher, previous: $first);
		$third  = $this->walk(flow: $flow, dispatcher: $dispatcher, previous: $second);

		$waits = array_map(static fn (array $result): int => ($result['resumeAt']->getTimestamp() - time()), [$first, $second, $third]);
		self::assertEqualsWithDelta(100, $waits[0], 5);
		self::assertEqualsWithDelta(200, $waits[1], 5);
		self::assertEqualsWithDelta(400, $waits[2], 5);
	}//end testExponentialBackoffDoublesTheWait()

	public function testAStepWithoutRetryKeepsTodaysFailureEntry(): void {
		$flow       = self::flow(retry: []);
		unset($flow['nodes'][0]['retry']);
		$dispatcher = new FlakyDispatcher(failuresLeft: 1);

		$result = $this->walk(flow: $flow, dispatcher: $dispatcher, previous: null);

		self::assertSame(FlowEngine::STATUS_FAILED, $result['status']);
		$entry = self::entriesOf(result: $result, step: 'call')[0];
		self::assertArrayNotHasKey('attempt', $entry);
		self::assertArrayNotHasKey('outcome', $entry);
	}//end testAStepWithoutRetryKeepsTodaysFailureEntry()

	public function testARetryOverItsCapIsRefusedNamingTheSettingAndTheCap(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('retry.attempts is 50; the cap is 10');

		(new FlowDefinitionBuilder())->build(flow: self::flow(retry: ['attempts' => 50]));
	}//end testARetryOverItsCapIsRefusedNamingTheSettingAndTheCap()
}//end class
