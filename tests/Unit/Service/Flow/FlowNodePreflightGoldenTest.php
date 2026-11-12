<?php

/**
 * Golden test for FlowNodePreflight: looksLikeFlow(), inspect(),
 * assertRunnable() (its log lines and its refusal) and describe() over
 * every finding a flow document can produce.
 * Re-record with UPDATE_GOLDEN=1.
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
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodePreflight
 * @covers \OCA\OpenRegister\Service\Flow\FlowConnectivity
 */
class FlowNodePreflightGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/flow-node-preflight-golden.json';

	/**
	 * Every warning the preflight logged, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logged = [];

	/**
	 * @var FlowNodePreflight
	 */
	private FlowNodePreflight $preflight;

	protected function setUp(): void {
		$nodes = $this->nodes();

		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('get')->willReturnCallback(
			static function (string $type) use ($nodes): IFlowNode {
				if (isset($nodes[$type]) === false) {
					throw new UnexpectedValueException('no such type ' . $type);
				}

				return $nodes[$type];
			}
		);
		$registry->method('isEnd')->willReturnCallback(static fn (string $type): bool => str_ends_with($type, '.end'));
		$registry->method('isTrigger')->willReturnCallback(static fn (string $type): bool => str_ends_with($type, '.trigger'));

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(
			static function (string $appId): bool {
				if ($appId === 'flaky') {
					throw new RuntimeException('app manager down');
				}

				return in_array($appId, ['openregister', 'stale'], true);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				// The line number is where the log call sits in the source,
				// not behaviour; the checkout path and the mock's random class
				// suffix vary per run. Everything else is pinned.
				unset($context['line']);
				if (isset($context['file']) === true) {
					$context['file'] = basename((string)$context['file']);
				}

				if (isset($context['node']) === true) {
					$context['node'] = preg_replace('/(_[0-9a-f]{8}){2}$/', '', (string)$context['node']);
				}
				$this->logged[] = ['message' => $message, 'context' => $context];
			}
		);

		$this->preflight = new FlowNodePreflight($registry, $apps, $logger);
	}//end setUp()

	/**
	 * @return array<string, IFlowNode>
	 */
	private function nodes(): array {
		$plain = $this->createMock(IFlowNode::class);

		return [
			'openregister.plain' => $plain,
			'openregister.trigger' => $plain,
			'openregister.end' => $this->keyed([]),
			'openregister.keyed' => $this->keyed(['a', 'b', 7]),
			'openregister.rejects-ia' => $this->rejecting(new InvalidArgumentException('ia refusal')),
			'openregister.rejects-uv' => $this->rejecting(new UnexpectedValueException('uv refusal')),
			'openregister.breaks' => $this->rejecting(new RuntimeException('node broke')),
			'openregister.keys-throw' => $this->keysThrow(),
		];
	}//end nodes()

	private function keyed(array $keys): IFlowNode {
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeConfigKeys::class]);
		$node->method('configKeys')->willReturn($keys);

		return $node;
	}//end keyed()

	private function rejecting(Throwable $error): IFlowNode {
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeConfigKeys::class]);
		$node->method('validateConfig')->willThrowException($error);
		$node->method('configKeys')->willReturn(['x']);

		return $node;
	}//end rejecting()

	private function keysThrow(): IFlowNode {
		$node = $this->createMockForIntersectionOfInterfaces([IFlowNode::class, IFlowNodeConfigKeys::class]);
		$node->method('configKeys')->willThrowException(new RuntimeException('keys broke'));

		return $node;
	}//end keysThrow()

	private static function flow(array $nodes, array $edges = []): array {
		return ['name' => 'golden', 'nodes' => $nodes, 'edges' => $edges];
	}//end flow()

	private static function looksLikeFlowCases(): array {
		$edge = ['from' => 'a', 'to' => 'b'];

		return [
			'empty' => [],
			'nodes not array' => ['nodes' => 'x', 'edges' => [$edge]],
			'edges not array' => ['nodes' => [['id' => 'a']], 'edges' => 'x'],
			'nodes empty' => ['nodes' => [], 'edges' => [$edge]],
			'edges empty' => ['nodes' => [['id' => 'a']], 'edges' => []],
			'node without id' => ['nodes' => [['type' => 'x']], 'edges' => [$edge]],
			'node not array' => ['nodes' => ['a'], 'edges' => [$edge]],
			'edge not array' => ['nodes' => [['id' => 'a']], 'edges' => ['x']],
			'edge missing to' => ['nodes' => [['id' => 'a']], 'edges' => [['from' => 'a']]],
			'edge missing from' => ['nodes' => [['id' => 'a']], 'edges' => [['to' => 'a']]],
			'edge source/target' => ['nodes' => [['id' => 'a']], 'edges' => [['source' => 'a', 'target' => 'b']]],
			'edge mixed spelling' => ['nodes' => [['id' => 'a']], 'edges' => [['from' => 'a', 'target' => 'b']]],
			'valid' => ['nodes' => [['id' => 'a'], ['id' => 'b']], 'edges' => [$edge, ['source' => 'b', 'to' => 'a']]],
		];
	}//end looksLikeFlowCases()

	private static function inspectCases(): array {
		$trigger = ['id' => 't', 'type' => 'openregister.trigger'];
		$end = ['id' => 'z', 'type' => 'openregister.end'];
		$wire = [['from' => 't', 'to' => 'n'], ['from' => ['n'], 'to' => ['z']]];

		return [
			'empty document' => [],
			'nodes only plain' => self::flow([['id' => 'n', 'type' => 'openregister.plain']]),
			'pre-inversion edges' => self::flow(
				[['id' => 'a'], ['id' => 'b']],
				[
					['id' => 'e1', 'from' => 'a', 'to' => 'b', 'type' => 'openregister.plain'],
					['name' => 'named', 'from' => 'a', 'to' => 'b', 'type' => ' x.y '],
					['from' => 'a', 'to' => 'b', 'type' => 'nodot'],
					['from' => 'a', 'to' => 'b', 'type' => ''],
					'not-an-edge',
				]
			),
			'healthy' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.keyed', 'config' => ['a' => 1, '$why' => 'note']], $end], $wire),
			'untyped and junk nodes' => self::flow([$trigger, 'junk', ['id' => 'u'], ['id' => 'n', 'type' => '  '], $end], $wire),
			'index as step id' => self::flow([$trigger, ['type' => 'missing.thing'], ['name' => 'nm', 'type' => 'nodot'], $end], $wire),
			'unknown keys' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.keyed', 'config' => ['a' => 1, 'c' => 2, 'd' => 3]], $end], $wire),
			'empty vocabulary' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.end', 'config' => ['q' => 1]]], $wire),
			'onError default' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.keyed', 'config' => ['onError' => 'stop']], $end], $wire),
			'onError other' => self::flow(
				[$trigger, ['id' => 'n', 'type' => 'openregister.keyed', 'config' => ['onError' => 'continue', 'zz' => 1]], $end],
				$wire
			),
			'onError on plain node' => self::flow(
				[$trigger, ['id' => 'n', 'type' => 'openregister.plain', 'config' => ['onError' => 'continue']], $end],
				$wire
			),
			'config not array' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.keyed', 'config' => 'x'], $end], $wire),
			'config empty' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.keyed', 'config' => []], $end], $wire),
			'rejects invalid argument' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.rejects-ia', 'config' => ['y' => 1]], $end], $wire),
			'rejects unexpected value' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.rejects-uv'], $end], $wire),
			'node breaks' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.breaks', 'config' => ['x' => 1]], $end], $wire),
			'config keys throw' => self::flow([$trigger, ['id' => 'n', 'type' => 'openregister.keys-throw', 'config' => ['x' => 1]], $end], $wire),
			'missing from enabled app' => self::flow([$trigger, ['id' => 'n', 'type' => 'stale.step'], $end], $wire),
			'owner not enabled' => self::flow([$trigger, ['id' => 'n', 'type' => 'absent.step'], $end], $wire),
			'app manager throws' => self::flow([$trigger, ['id' => 'n', 'type' => 'flaky.step'], $end], $wire),
			'leading dot type' => self::flow([$trigger, ['id' => 'n', 'type' => '.step'], $end], $wire),
			'dead end and exit flag' => self::flow(
				[$trigger, ['id' => 'n', 'type' => 'openregister.plain'], ['id' => 'x', 'type' => 'openregister.plain', 'exit' => true], $end],
				[['from' => 't', 'to' => 'n'], ['from' => 'z', 'to' => 't'], 'junk']
			),
			'no trigger no end' => self::flow([['id' => 'n', 'type' => 'openregister.plain']], [['from' => 'n', 'to' => 'n']]),
			'mixed blocking and warnings' => self::flow(
				[
					$trigger,
					['id' => 'n1', 'type' => 'absent.step'],
					['id' => 'n2', 'type' => 'stale.step'],
					['id' => 'n3', 'type' => 'openregister.keyed', 'config' => ['onError' => 'stop', 'bad' => 1]],
					['id' => 'n4', 'type' => 'openregister.rejects-ia'],
					$end,
				],
				[['from' => ['t', 'n1', 'n2', 'n3', 'n4'], 'to' => 'z']]
			),
		];
	}//end inspectCases()

	private static function describeCases(): array {
		return [
			'none' => [],
			'every reason' => [
				['type' => 't.a', 'step' => 's1', 'reason' => FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY, 'detail' => 'k', 'reads' => ''],
				['type' => 't.a', 'step' => 's2', 'reason' => FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY, 'detail' => 'k', 'reads' => 'a, b'],
				['type' => 't.a', 'step' => 's3', 'reason' => FlowNodePreflight::REASON_CONFIG_ONERROR_MISPLACED, 'detail' => 'continue'],
				['type' => 't.a', 'step' => 's4', 'reason' => FlowNodePreflight::REASON_CONFIG_REJECTED],
				['type' => 't.a', 'step' => 's5', 'reason' => FlowNodePreflight::REASON_PRE_INVERSION_SHAPE],
				['type' => 'nodot', 'step' => 's6', 'reason' => FlowNodePreflight::REASON_NOT_NAMESPACED],
				['type' => 't.a', 'step' => 's7', 'reason' => FlowNodePreflight::REASON_MISSING_FROM_OWNER, 'app' => 't'],
				['type' => 't.a', 'step' => 's8', 'reason' => 'something-else'],
			],
		];
	}//end describeCases()

	private function assertRunnable(array $flow, ?string $label): array {
		$this->logged = [];
		$outcome = 'runnable';
		try {
			$this->preflight->assertRunnable($flow, $label);
		} catch (Throwable $e) {
			$outcome = get_class($e) . ': ' . $e->getMessage();
		}

		return ['outcome' => $outcome, 'logged' => $this->logged];
	}//end assertRunnable()

	public function testFlowNodePreflightMatchesGolden(): void {
		$actual = [];
		foreach (self::looksLikeFlowCases() as $name => $data) {
			$actual['looksLikeFlow: ' . $name] = $this->preflight->looksLikeFlow($data);
		}

		foreach (self::inspectCases() as $name => $flow) {
			$this->logged = [];
			$actual['inspect: ' . $name] = ['report' => $this->preflight->inspect($flow), 'logged' => $this->logged];
			$actual['assertRunnable: ' . $name] = $this->assertRunnable($flow, null);
		}

		$mixed = self::inspectCases()['mixed blocking and warnings'];
		$actual['assertRunnable: labelled'] = $this->assertRunnable($mixed, 'Label');
		$actual['assertRunnable: unnamed'] = $this->assertRunnable(['nodes' => $mixed['nodes'], 'edges' => $mixed['edges']], null);

		foreach (self::describeCases() as $name => $blocking) {
			$actual['describe: ' . $name] = $this->preflight->describe('Golden flow', $blocking);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testFlowNodePreflightMatchesGolden()
}//end class
