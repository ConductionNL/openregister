<?php

/**
 * Golden master for both FlowEngine walks: the single in-memory stream and
 * the per-stream walk.
 *
 * Every case runs a flow through FlowEngine::run() and records, in call
 * order, what the engine handed its collaborators (dispatcher, stream walk,
 * attribution frames, mootness, logger) together with the normalised run
 * result. The recording lives in `fixtures/flow-engine-walk-golden.json`.
 * Set `OR_GOLDEN_WRITE=1` to rewrite it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowFiringResult;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunMarkingStore;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\FlowStepReport;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowStreamWalk;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskMootness;
use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;

/**
 * A dispatcher whose behaviour per step id is configured by the case.
 */
class GoldenWalkDispatcher implements FlowStepDispatcher {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $behaviour Step id => suspend|fail|stop|stop-error|report|double.
	 * @param \Closure              $trace     Records one trace line.
	 */
	public function __construct(
		private readonly array $behaviour,
		private readonly \Closure $trace,
	) {
	}

	public function dispatch(array $step, array $items, array $context): array {
		$id = (string)($step['id'] ?? '');
		($this->trace)(['dispatch', $id, count($items)]);
		$report = ($context[FlowStepReport::CONTEXT_KEY] ?? null);
		if ($report instanceof FlowStepReport) {
			$report->put('ranAs', $id);
		}

		switch ($this->behaviour[$id] ?? '') {
			case 'suspend':
				throw new FlowSuspension(resumeAt: new DateTime('2030-01-01T00:00:00+00:00'), reason: 'waiting on ' . $id);
			case 'fail':
				throw new RuntimeException('boom at ' . $id);
			case 'stop':
				throw new FlowStop(reason: 'author stop at ' . $id);
			case 'stop-error':
				throw new FlowStop(reason: 'error stop at ' . $id, isError: true);
			case 'double':
				return array_merge($items, $items);
			default:
				return $items;
		}
	}
}//end class

/**
 * A logger that hands every record to the trace, without file and line.
 */
class GoldenWalkLogger extends AbstractLogger {

	public function __construct(private readonly \Closure $trace) {
	}

	public function log($level, string|\Stringable $message, array $context = []): void {
		unset($context['file'], $context['line']);
		($this->trace)(['log', (string)$level, (string)$message, $context]);
	}
}//end class

/**
 * The golden master.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowEngine
 * @uses   \OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItemPlacement
 * @uses   \OCA\OpenRegister\Service\Flow\FlowTokenRouter
 * @uses   \OCA\OpenRegister\Service\Flow\FlowExpression
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunMarkingStore
 * @uses   \OCA\OpenRegister\Service\Flow\FlowStepReport
 * @uses   \OCA\OpenRegister\Service\Flow\FlowStop
 * @uses   \OCA\OpenRegister\Service\Flow\FlowSuspension
 * @uses   \OCA\OpenRegister\Service\Flow\FlowOversightRegistry
 * @uses   \OCA\OpenRegister\Service\Flow\FlowFiringResult
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Db\FlowStream
 */
class FlowEngineWalkGoldenTest extends TestCase {

	/**
	 * The trace of the running case.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	private FlowRun $run;

	private static function goldenPath(): string {
		return __DIR__ . '/fixtures/flow-engine-walk-golden.json';
	}

	/**
	 * Flow documents by name.
	 *
	 * @return array<string, array>
	 */
	private static function flows(): array {
		$linear = [
			'id' => 'linear',
			'nodes' => [['id' => 'one', 'type' => 't.one'], ['id' => 'two', 'type' => 't.two'], ['id' => 'three', 'type' => 't.three']],
			'edges' => [['id' => 'e1', 'from' => 'one', 'to' => 'two'], ['id' => 'e2', 'from' => 'two', 'to' => 'three']],
		];
		$split = [
			'id' => 'split',
			'nodes' => [
				['id' => 'start', 'type' => 't.start'],
				['id' => 'advice', 'type' => 't.advice'],
				['id' => 'hearing', 'type' => 't.hearing'],
				['id' => 'done', 'type' => 't.done'],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'advice'],
				['id' => 'e2', 'from' => 'start', 'to' => 'hearing'],
				['id' => 'e3', 'from' => 'hearing', 'to' => 'done'],
			],
		];
		$switch = [
			'id' => 'switch',
			'nodes' => [
				['id' => 's', 'type' => 'switch', 'exits' => [['id' => 'high', 'condition' => ['>' => [['var' => 'json.n'], 10]]], ['id' => 'low']]],
				['id' => 'hi', 'type' => 't.hi'],
				['id' => 'lo', 'type' => 't.lo'],
			],
			'edges' => [
				['id' => 'toHigh', 'from' => 's', 'fromExit' => 'high', 'to' => 'hi'],
				['id' => 'toLow', 'from' => 's', 'fromExit' => 'low', 'to' => 'lo'],
			],
		];
		$gated = [
			'id' => 'gated',
			'nodes' => [['id' => 'a', 'type' => 't.a'], ['id' => 'b', 'type' => 't.b']],
			'edges' => [['id' => 'ab', 'from' => 'a', 'to' => 'b', 'condition' => ['>' => [['var' => 'json.n'], 100]]]],
		];
		$loop = [
			'id' => 'loop',
			'nodes' => [['id' => 'a', 'type' => 't.a'], ['id' => 'b', 'type' => 't.b']],
			'edges' => [['id' => 'there', 'from' => 'a', 'to' => 'b'], ['id' => 'back', 'from' => 'b', 'to' => 'a']],
		];

		return [
			'linear' => $linear,
			'split' => $split,
			'switch' => $switch,
			'gated' => $gated,
			'loop' => $loop,
			'malformed' => ['id' => 'bad', 'nodes' => [['id' => 'x', 'type' => 't.x']], 'edges' => [['id' => 'e', 'from' => 'x', 'to' => 'nowhere']]],
		];
	}//end flows()

	/**
	 * The corpus: name => case options.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function corpus(): array {
		$cases = [
			'linear' => ['flow' => 'linear'],
			'linear-double' => ['flow' => 'linear', 'behaviour' => ['two' => 'double']],
			'split-suspend' => ['flow' => 'split', 'behaviour' => ['advice' => 'suspend']],
			'split-clean' => ['flow' => 'split'],
			'stop-author' => ['flow' => 'linear', 'behaviour' => ['two' => 'stop']],
			'stop-error' => ['flow' => 'linear', 'behaviour' => ['two' => 'stop-error']],
			'fail-default' => ['flow' => 'linear', 'behaviour' => ['two' => 'fail']],
			'fail-continue' => ['flow' => 'linear', 'behaviour' => ['two' => 'fail'], 'onError' => ['two' => FlowEngine::ON_ERROR_CONTINUE]],
			'fail-dead-letter' => ['flow' => 'linear', 'behaviour' => ['two' => 'fail'], 'onError' => ['two' => FlowEngine::ON_ERROR_DEAD_LETTER]],
			'fail-continue-split' => ['flow' => 'split', 'behaviour' => ['hearing' => 'fail'], 'onError' => ['hearing' => FlowEngine::ON_ERROR_CONTINUE]],
			'fail-dead-letter-split' => ['flow' => 'split', 'behaviour' => ['hearing' => 'fail'], 'onError' => ['hearing' => FlowEngine::ON_ERROR_DEAD_LETTER]],
			'pinned' => ['flow' => 'linear', 'pins' => ['two' => [FlowItems::item(json: ['pinned' => true])]]],
			'pinned-split' => ['flow' => 'split', 'pins' => ['hearing' => [FlowItems::item(json: ['pinned' => 1]), FlowItems::item(json: ['pinned' => 2])]]],
			'report' => ['flow' => 'linear', 'report' => true, 'behaviour' => ['three' => 'fail']],
			'oversight' => ['flow' => 'split', 'oversight' => 2],
			'oversight-first' => ['flow' => 'linear', 'oversight' => 1],
			'oversight-off' => ['flow' => 'linear', 'oversight' => 1, 'context' => ['oversight' => false]],
			'switch-high' => ['flow' => 'switch', 'seed' => 42],
			'switch-low' => ['flow' => 'switch', 'seed' => 3],
			'gated-no-match' => ['flow' => 'gated', 'seed' => 3],
			'gated-match' => ['flow' => 'gated', 'seed' => 300],
			'loop-ceiling' => ['flow' => 'loop'],
			'malformed' => ['flow' => 'malformed'],
			'start-at' => ['flow' => 'linear', 'startAt' => 'two'],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out['single:' . $name] = $case + ['mode' => 'single'];
			if ($name !== 'loop-ceiling') {
				$out['stream:' . $name] = $case + ['mode' => 'stream'];
			}
		}

		$out['stream:refused-claim'] = ['mode' => 'stream', 'flow' => 'split', 'refuse' => ['hearing']];
		$out['stream:ceiling'] = ['mode' => 'stream', 'flow' => 'split', 'firings' => 1000];
		$out['stream:budget-spent'] = ['mode' => 'stream', 'flow' => 'linear', 'budget' => true];
		$out['stream:resumed-place-items'] = ['mode' => 'stream', 'flow' => 'resume', 'marking' => ['advice' => 1, 'hearing' => 1]];

		ksort($out);
		return $out;
	}//end corpus()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$out = [];
		foreach (array_keys(self::corpus()) as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}

	/**
	 * @dataProvider cases
	 */
	public function testWalkMatchesGolden(string $name): void {
		$actual = $this->runCase(case: self::corpus()[$name]);

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testWalkMatchesGolden()

	/**
	 * Run one case and return its normalised trace and result.
	 *
	 * @param array<string, mixed> $case The case options.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->trace = [];
		$trace = function (array $line): void {
			$this->trace[] = $line;
		};

		$this->run = new FlowRun();
		$this->run->setUuid('run-1');
		$this->run->setFirings((int)($case['firings'] ?? 0));

		$flow = (self::flows()[$case['flow']] ?? self::flows()['split']);
		if ($case['flow'] === 'resume') {
			$flow = ['id' => 'resume', 'nodes' => [['id' => 'advice', 'type' => 't.a'], ['id' => 'hearing', 'type' => 't.h']], 'edges' => []];
			$this->run->setMarking($case['marking']);
			$this->run->setPlaceItems(['advice' => [FlowItems::item(json: ['branch' => 'A'])], 'hearing' => [FlowItems::item(json: ['branch' => 'H'])]]);
		}

		foreach (($case['onError'] ?? []) as $nodeId => $policy) {
			foreach ($flow['nodes'] as $i => $node) {
				if ($node['id'] === $nodeId) {
					$flow['nodes'][$i]['onError'] = $policy;
				}
			}
		}

		$context = ($case['context'] ?? []);
		$context[FlowRunContext::CONTEXT_RUN] = 'run-1';
		$context[FlowRunContext::CONTEXT_BASE] = 10;
		if (isset($case['pins']) === true) {
			$context['pins'] = $case['pins'];
		}

		if (($case['report'] ?? false) === true) {
			$context[FlowStepReport::CONTEXT_KEY] = new FlowStepReport();
		}

		$engine = new FlowEngine(
			new FlowDefinitionBuilder(),
			new GoldenWalkLogger(trace: $trace),
			$this->oversight(refuseFrom: ($case['oversight'] ?? null), trace: $trace),
			null,
			null,
			$this->runContext(trace: $trace),
			$this->mootness(trace: $trace)
		);

		$streams = null;
		if ($case['mode'] === 'stream') {
			$streams = $this->fakeStreams(case: $case, trace: $trace);
		}

		$result = $engine->run(
			flow: $flow,
			store: new FlowRunMarkingStore(run: $this->run),
			subject: new stdClass(),
			dispatcher: new GoldenWalkDispatcher(behaviour: ($case['behaviour'] ?? []), trace: $trace),
			context: $context,
			items: [FlowItems::item(json: ['n' => ($case['seed'] ?? 1)])],
			startAt: ($case['startAt'] ?? null),
			streams: $streams
		);

		$result['log'] = self::clip(lines: $result['log']);

		return $this->normalise(value: [
			'result' => $result,
			'marking' => $this->run->getMarking(),
			'trace' => self::clip(lines: $this->trace),
		]);
	}//end runCase()

	/**
	 * Keep a long list readable in the recording: its head, its tail and its length.
	 *
	 * @param array<int, mixed> $lines The list.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function clip(array $lines): array {
		if (count($lines) <= 120) {
			return $lines;
		}

		return ['count' => count($lines), 'head' => array_slice($lines, 0, 12), 'tail' => array_slice($lines, -12)];
	}//end clip()

	/**
	 * Make a value JSON-stable: moments and durations are blanked, objects named.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if ($value instanceof DateTime) {
			return $value->format(DATE_ATOM);
		}

		if (is_object($value) === true) {
			return 'object:' . get_class($value);
		}

		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			if ($key === 'startedAt' || $key === 'durationMs') {
				$out[$key] = '~';
				continue;
			}

			$out[$key] = $this->normalise(value: $item);
		}

		return $out;
	}//end normalise()

	private function oversight(?int $refuseFrom, \Closure $trace): ?FlowOversightRegistry {
		if ($refuseFrom === null) {
			return null;
		}

		$check = new class($refuseFrom, $trace) implements IFlowOversightCheck {
			private int $asked = 0;

			public function __construct(private readonly int $refuseFrom, private readonly \Closure $trace) {
			}

			public function getId(): string {
				return 'golden.refuse';
			}

			public function veto(array $context): ?string {
				$this->asked++;
				($this->trace)(['oversight', $context['transition'], $context['nodeType']]);
				return ($this->asked >= $this->refuseFrom) ? 'kill switch' : null;
			}
		};
		$registry = new FlowOversightRegistry(logger: new GoldenWalkLogger(trace: $trace));
		$registry->register(check: $check);

		return $registry;
	}//end oversight()

	private function runContext(\Closure $trace): FlowRunContext {
		$context = $this->createMock(FlowRunContext::class);
		$context->method('push')->willReturnCallback(function (?string $runUuid, string $nodeId, int $sequence) use ($trace): void {
			$trace(['push', $runUuid, $nodeId, $sequence]);
		});
		$context->method('pop')->willReturnCallback(function () use ($trace): void {
			$trace(['pop']);
		});

		return $context;
	}//end runContext()

	private function mootness(\Closure $trace): FlowTaskMootness {
		$mootness = $this->createMock(FlowTaskMootness::class);
		$mootness->method('placesPruned')->willReturnCallback(function (array $context, array $places, string $byTransition) use ($trace): int {
			$trace(['pruned', $places, $byTransition]);
			return 0;
		});

		return $mootness;
	}//end mootness()

	/**
	 * A fake stream collaborator that tracks streams in memory and traces
	 * every protocol call with its arguments.
	 *
	 * @param array<string, mixed> $case  The case options.
	 * @param \Closure             $trace The trace.
	 *
	 * @return FlowStreamWalk
	 */
	private function fakeStreams(array $case, \Closure $trace): FlowStreamWalk {
		$walk = $this->createMock(FlowStreamWalk::class);
		$streams = [];
		$parked = [];
		$exhausted = [];
		$refused = [];
		$cursor = 0;
		$firings = (int)($case['firings'] ?? 0);
		$refuse = ($case['refuse'] ?? []);

		$walk->method('run')->willReturnCallback(fn (): FlowRun => $this->run);
		$walk->method('begin')->willReturnCallback(function (array $marking) use (&$streams, $trace): void {
			$trace(['begin', $marking]);
			$i = 1;
			foreach (array_keys($marking) as $place) {
				$streams['s' . $i] = ['place' => (string)$place, 'path' => ($i === 1) ? '0001' : FlowStream::childPath('0001', $i)];
				$i++;
			}
		});
		$walk->method('budgetSpent')->willReturnCallback(function () use ($case, $trace): bool {
			$trace(['budgetSpent']);
			return (($case['budget'] ?? false) === true);
		});
		$walk->method('nextStream')->willReturnCallback(function () use (&$streams, &$parked, &$exhausted, &$refused, &$cursor, $trace): ?string {
			$ids = array_keys($streams);
			$n = count($ids);
			for ($k = 0; $k < $n; $k++) {
				$id = $ids[(($cursor + $k) % $n)];
				if (isset($parked[$id]) === false && isset($exhausted[$id]) === false && isset($refused[$id]) === false) {
					$cursor = ((($cursor + $k) + 1) % max(1, $n));
					$trace(['nextStream', $id]);
					return $id;
				}
			}

			$trace(['nextStream', null]);
			return null;
		});
		$walk->method('firings')->willReturnCallback(function () use (&$firings): int {
			return $firings;
		});
		$walk->method('placeOf')->willReturnCallback(function (string $id) use (&$streams): ?string {
			return ($streams[$id]['place'] ?? null);
		});
		$walk->method('pathOf')->willReturnCallback(function (string $id) use (&$streams): string {
			return (string)($streams[$id]['path'] ?? 'gone');
		});
		$walk->method('exhaust')->willReturnCallback(function (string $id) use (&$exhausted, $trace): void {
			$trace(['exhaust', $id]);
			$exhausted[$id] = true;
		});
		$walk->method('claim')->willReturnCallback(function (string $id, string $transition, array $places) use (&$refused, $refuse, $trace): ?array {
			$trace(['claim', $id, $transition, $places]);
			if (in_array($transition, $refuse, true) === true) {
				$refused[$id] = true;
				return null;
			}

			sort($places, SORT_STRING);
			return $places;
		});
		$walk->method('release')->willReturnCallback(function (array $places) use ($trace): void {
			$trace(['release', $places]);
		});
		$walk->method('workRemains')->willReturnCallback(function (array $transitions, array $produced = [], ?string $settling = null) use (&$streams, &$parked, $trace): bool {
			$unparked = [];
			foreach ($streams as $id => $stream) {
				if (isset($parked[$id]) === false && $id !== $settling && $stream['place'] !== null) {
					$unparked[$stream['place']] = true;
				}
			}

			foreach ($produced as $place) {
				$unparked[$place] = true;
			}

			$answer = false;
			foreach ($transitions as $transition) {
				foreach ($transition->getFroms() as $from) {
					if (isset($unparked[(string)$from]) === true) {
						$answer = true;
					}
				}
			}

			$trace(['workRemains', array_map(static fn ($t): string => $t->getName(), $transitions), $settling, $produced, $answer]);
			return $answer;
		});
		$walk->method('commitFiring')->willReturnCallback(
			function (string $id, string $transition, array $froms, array $taken, array $placeItems, array $claimed, array $logEntry, bool $enabledAfter, string $streamStatus = 'running', ?string $streamError = null) use (&$streams, &$exhausted, &$firings, $trace): FlowFiringResult {
				$trace(['commitFiring', $id, $transition, $froms, $taken, array_map('count', $placeItems), $claimed, $this->normalise(value: $logEntry), $enabledAfter, $streamStatus, $streamError]);
				$firings++;
				$this->run->setFirings($firings);
				$marking = [];
				foreach ((array)($this->run->getMarking() ?? []) as $k => $v) {
					$marking[(string)(is_int($k) === true ? $v : $k)] = 1;
				}

				if (count($taken) <= 1) {
					$streams[$id]['place'] = ($taken[0] ?? null);
					if ($taken === []) {
						unset($streams[$id]);
					}
				} else {
					$parentPath = $streams[$id]['path'];
					unset($streams[$id]);
					$k = 1;
					foreach ($taken as $to) {
						$streams[$id . '.' . $k] = ['place' => $to, 'path' => FlowStream::childPath($parentPath, $k)];
						$k++;
					}
				}

				$exhausted = [];
				return new FlowFiringResult(marking: $marking, placeItems: $placeItems, streams: [], firings: $firings);
			}
		);
		$walk->method('park')->willReturnCallback(function (string $id, ?DateTime $resumeAt, string $reason, array $claimed, bool $enabled) use (&$parked, $trace): void {
			$trace(['park', $id, $resumeAt?->format(DATE_ATOM), $reason, $claimed, $enabled]);
			$parked[$id] = true;
			$this->run->setResumeAt($resumeAt);
		});
		$walk->method('endStream')->willReturnCallback(function (string $id, string $status, ?string $error, array $claimed, bool $enabled) use (&$streams, $trace): void {
			$trace(['endStream', $id, $status, $error, $claimed, $enabled]);
			unset($streams[$id]);
		});
		$walk->method('finalize')->willReturnCallback(function (bool $enabled, ?string $forcedTerminal = null) use (&$parked, $trace): string {
			$trace(['finalize', $enabled, $forcedTerminal]);
			if ($forcedTerminal !== null) {
				return $forcedTerminal;
			}

			if ($enabled === true) {
				return FlowRun::STATUS_QUEUED;
			}

			if ($parked !== []) {
				return FlowRun::STATUS_SUSPENDED;
			}

			return FlowRun::STATUS_COMPLETED;
		});

		return $walk;
	}//end fakeStreams()
}//end class
