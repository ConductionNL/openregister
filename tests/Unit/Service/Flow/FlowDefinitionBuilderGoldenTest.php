<?php

/**
 * Golden test for FlowDefinitionBuilder: lowerSteps() and the places,
 * transitions and initial places build() lowers each document into, plus
 * every refusal message. Re-record with UPDATE_GOLDEN=1.
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

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Transition;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder
 * @uses \OCA\OpenRegister\Service\Flow\FlowGraph
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeDeclarations
 * @uses \OCA\OpenRegister\Service\Flow\LoweredFlow
 */
class FlowDefinitionBuilderGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/flow-definition-builder-golden.json';

	private static function node(string $id, array $extra = []): array {
		return ['id' => $id, 'type' => 'openregister.noop', 'config' => []] + $extra;
	}//end node()

	private static function edge(string $id, mixed $from, mixed $to): array {
		return ['id' => $id, 'from' => $from, 'to' => $to];
	}//end edge()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function documents(): array {
		$abc = [self::node('a'), self::node('b'), self::node('c')];
		$cond = ['condition' => ['==' => [1, 1]]];
		return [
			'empty' => [],
			'no nodes' => ['nodes' => []],
			'single node' => ['nodes' => [self::node('a')]],
			'line' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'b'), self::edge('e2', 'b', 'c')]],
			'edge without id' => ['nodes' => $abc, 'edges' => [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'c']]],
			'fan out list' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', ['b', 'c'])]],
			'merge' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'c'), self::edge('e2', 'b', 'c')]],
			'join' => ['nodes' => [self::node('a'), self::node('b'), self::node('c', ['join' => true])], 'edges' => [self::edge('e1', 'a', 'c'), self::edge('e2', 'b', 'c')]],
			'join without incoming' => ['nodes' => [self::node('a', ['join' => true]), self::node('b')], 'edges' => [self::edge('e1', 'a', 'b')]],
			'join truthy not true' => ['nodes' => [self::node('a'), self::node('b'), self::node('c', ['join' => 1])], 'edges' => [self::edge('e1', 'a', 'c'), self::edge('e2', 'b', 'c')]],
			'multi from' => ['nodes' => $abc, 'edges' => [self::edge('e1', ['a', 'b'], 'c')]],
			'duplicate edge targets' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', ['b', 'b']), self::edge('e2', 'a', 'b')]],
			'cycle' => ['nodes' => [self::node('x'), self::node('y')], 'edges' => [self::edge('e1', 'x', 'y'), self::edge('e2', 'y', 'x')]],
			'self loop plus source' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'b'), self::edge('e2', 'b', 'b')]],
			'initial string' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'b')], 'initial' => 'b'],
			'initial list' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'b')], 'initial' => ['c', 'a']],
			'initial empty list' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'b')], 'initial' => []],
			'initial unknown' => ['nodes' => $abc, 'initial' => ['a', 'zz']],
			'two sources' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'c')]],
			'node padded id' => ['nodes' => [self::node(' a ')]],
			'node not object' => ['nodes' => [self::node('a'), 'b']],
			'node no id' => ['nodes' => [['type' => 'x']]],
			'node blank id' => ['nodes' => [['id' => '  ', 'type' => 'x']]],
			'node duplicate id' => ['nodes' => [self::node('a'), self::node('a')]],
			'node no type' => ['nodes' => [['id' => 'a']]],
			'node blank type' => ['nodes' => [['id' => 'a', 'type' => ' ']]],
			'exits all conditioned' => ['nodes' => [self::node('a', ['exits' => [$cond, $cond]])]],
			'exit single conditioned' => ['nodes' => [self::node('a', ['exits' => [$cond]])]],
			'exits with else' => ['nodes' => [self::node('a', ['exits' => [$cond, ['to' => 'b']]])]],
			'exits with empty condition' => ['nodes' => [self::node('a', ['exits' => [$cond, ['condition' => []]]])]],
			'exits non-array entries' => ['nodes' => [self::node('a', ['exits' => ['x', $cond]])]],
			'exits only non-array' => ['nodes' => [self::node('a', ['exits' => ['x']])]],
			'exits not array' => ['nodes' => [self::node('a', ['exits' => 'x'])]],
			'exits empty' => ['nodes' => [self::node('a', ['exits' => []])]],
			'legacy edge type' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'b') + ['type' => 'openregister.noop']]],
			'legacy edge type by name' => ['nodes' => $abc, 'edges' => [['name' => 'n1', 'from' => 'a', 'to' => 'b', 'type' => 'x']]],
			'legacy edge type by index' => ['nodes' => $abc, 'edges' => ['junk', ['from' => 'a', 'to' => 'b', 'type' => 'x']]],
			'legacy blank type ok' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'b') + ['type' => '  ']]],
			'edge missing to' => ['nodes' => $abc, 'edges' => [['id' => 'e1', 'from' => 'a']]],
			'edge empty from' => ['nodes' => $abc, 'edges' => [self::edge('e1', [], 'b')]],
			'edge unknown node' => ['nodes' => $abc, 'edges' => [self::edge('e1', 'a', 'zz')]],
			'edge unknown node no id' => ['nodes' => $abc, 'edges' => [['from' => 'zz', 'to' => 'a']]],
			'fault order: legacy before nodes' => ['nodes' => [['id' => 'a']], 'edges' => [['from' => 'a', 'to' => 'b', 'type' => 'x']]],
			'fault order: nodes before edges' => ['nodes' => [['id' => 'a']], 'edges' => [['from' => 'a']]],
			'fault order: edges before initial' => ['nodes' => $abc, 'edges' => [['from' => 'a']], 'initial' => 'zz'],
			'numeric node ids' => ['nodes' => [self::node('1'), self::node('2')], 'edges' => [self::edge('e1', '1', '2')]],
			'numeric join node id' => ['nodes' => [self::node('a'), self::node('7', ['join' => true])], 'edges' => [self::edge('e1', 'a', '7')]],
			'fault order: type before exits' => ['nodes' => [['id' => 'a', 'exits' => [$cond]]]],
		];
	}//end documents()

	/**
	 * @return array<string, array{0: array, 1: string|null}>
	 */
	private static function stepCases(): array {
		return [
			'none' => [[], null],
			'none with next' => [[], 'view'],
			'steps' => [[['id' => 's', 'type' => 't', 'config' => ['a' => 1], 'label' => 'L', 'extra' => 1], ['type' => 'u'], 'k' => ['id' => 'z', 'config' => 'x']], 'list'],
		];
	}//end stepCases()

	public function testBuilderMatchesGolden(): void {
		$builder = new FlowDefinitionBuilder();
		$actual = [];
		foreach (self::documents() as $name => $flow) {
			try {
				$definition = $builder->build(flow: $flow);
				$actual['build / ' . $name] = [
					'places' => $definition->getPlaces(),
					'initial' => $definition->getInitialPlaces(),
					'transitions' => array_map(
						static fn (Transition $transition): array => [$transition->getName(), $transition->getFroms(), $transition->getTos()],
						$definition->getTransitions()
					),
				];
			} catch (Throwable $error) {
				// A TypeError names the line it was called from; only the part before that is behaviour.
				$message = explode(', called in', $error->getMessage())[0];
				$actual['build / ' . $name] = ['error' => get_class($error), 'message' => $message];
			}
		}

		foreach (self::stepCases() as $name => [$steps, $next]) {
			$actual['lowerSteps / ' . $name] = $builder->lowerSteps(steps: $steps, next: $next);
		}

		$actual = json_decode((string)json_encode($actual), true);
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
	}//end testBuilderMatchesGolden()
}//end class
