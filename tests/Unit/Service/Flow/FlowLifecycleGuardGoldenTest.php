<?php

/**
 * Golden test for the flow lifecycle guard.
 *
 * Every transition check against every lifecycle state, and the dead-end
 * check against several preflight answers, with the refusal and its log line,
 * replayed against a recording. Record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowLifecycleGuard;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowLifecycleGuard
 * @uses   \OCA\OpenRegister\Service\Flow\FlowLifecycleRefused
 * @uses   \OCA\OpenRegister\Service\Flow\FlowDeadEndSteps
 */
class FlowLifecycleGuardGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/flow-lifecycle-guard-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $golden = null;

	/** @var array<string, mixed> */
	private static array $recorded = [];

	/**
	 * Transitions, the argument each is given, and the preflight answer.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['refuseEditUnlessDraft', 'refusePublishUnlessDraft', 'refuseDeprecateUnlessPublished'] as $method) {
			foreach ([null, 'draft', 'published', 'deprecated', 'odd'] as $state) {
				$cases[$method . ' ' . var_export($state, true)] = [$method, $state, []];
			}
		}

		$graphs = [
			'full graph' => ['nodes' => [['id' => 'a']], 'edges' => [['from' => 'a']], 'extra' => 1],
			'empty graph' => [],
			'scalar parts' => ['nodes' => 'x', 'edges' => 5],
		];
		$findings = [
			'sound' => ['warnings' => []],
			'no warnings key' => [],
			'other warnings' => ['warnings' => [['reason' => 'other', 'step' => 'x']]],
			'dead ends' => ['warnings' => [
				['reason' => 'node-dead-end', 'step' => 'b'],
				['reason' => 'other', 'step' => 'c'],
				['reason' => 'node-dead-end'],
				['step' => 'd'],
				['reason' => 'node-dead-end', 'step' => 'e'],
			]],
		];
		foreach ($graphs as $g => $graph) {
			foreach ($findings as $f => $finding) {
				$cases["refusePublishOnDeadEnd $g, $f"] = ['refusePublishOnDeadEnd', $graph, $finding];
			}
		}

		return $cases;
	}

	/**
	 * @param string               $method   The guard method.
	 * @param mixed                $argument The state, or the graph.
	 * @param array<string, mixed> $findings The preflight answer.
	 */
	#[DataProvider('cases')]
	public function testGuardMatchesTheRecording(string $method, mixed $argument, array $findings): void {
		$preflight = $this->createMock(FlowNodePreflight::class);
		$preflight->method('inspect')->willReturnCallback(function (array $flow) use ($findings): array {
			$this->calls[] = ['preflight.inspect' => $flow];
			return $findings;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			unset($context['file'], $context['line']);
			$this->calls[] = ['log.warning' => $message, 'context' => $context];
		});

		$guard = new FlowLifecycleGuard($preflight, $logger);
		$named = ($method === 'refusePublishOnDeadEnd') ? 'graph' : 'state';
		try {
			$guard->{$method}(...['flowId' => 'flow-1', $named => $argument]);
			$outcome = 'allowed';
		} catch (FlowLifecycleRefused $e) {
			$outcome = ['reason' => $e->getReason(), 'flow' => $e->getFlowId(), 'state' => $e->getState(), 'message' => $e->getMessage()];
		}

		$actual = ['outcome' => $outcome, 'calls' => $this->calls];
		$name = (string)$this->dataName();
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->assertTrue(true);
			return;
		}

		self::$golden ??= json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertArrayHasKey($name, self::$golden);
		$this->assertSame(self::$golden[$name], json_decode((string)json_encode($actual), true));
	}
}
