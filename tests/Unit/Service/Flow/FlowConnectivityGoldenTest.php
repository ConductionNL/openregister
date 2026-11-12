<?php

/**
 * Golden test for FlowConnectivity: deadEnds() and entryAndExit() over every
 * node and edge shape a flow document can carry.
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

use OCA\OpenRegister\Service\Flow\FlowConnectivity;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowConnectivity
 */
class FlowConnectivityGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/flow-connectivity-golden.json';

	/**
	 * Every type the registry is asked about, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * @var FlowConnectivity
	 */
	private FlowConnectivity $connectivity;

	protected function setUp(): void {
		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('isEnd')->willReturnCallback(
			function (string $type): bool {
				$this->asked[] = 'isEnd:' . $type;
				return in_array($type, ['openregister.end', 'other.finish'], true);
			}
		);
		$registry->method('isTrigger')->willReturnCallback(
			function (string $type): bool {
				$this->asked[] = 'isTrigger:' . $type;
				return in_array($type, ['openregister.trigger-manual', 'other.start'], true);
			}
		);

		$this->connectivity = new FlowConnectivity($registry);
	}//end setUp()

	private static function documents(): array {
		$trigger = ['id' => 't', 'type' => 'openregister.trigger-manual'];
		$end = ['id' => 'z', 'type' => 'openregister.end'];

		return [
			'empty' => [],
			'nodes not a list' => ['nodes' => 'x'],
			'nodes empty' => ['nodes' => [], 'edges' => [['from' => 'a', 'to' => 'b']]],
			'healthy' => [
				'nodes' => [$trigger, ['id' => 'a', 'type' => 'x.step'], $end],
				'edges' => [['from' => ['t'], 'to' => ['a']], ['from' => 'a', 'to' => 'z']],
			],
			'dead end' => ['nodes' => [$trigger, ['id' => ' a ', 'type' => ' x.step '], $end], 'edges' => [['from' => 't', 'to' => 'a']]],
			'exit flag' => [
				'nodes' => [$trigger, ['id' => 'a', 'type' => 'x.step', 'exit' => true], ['id' => 'b', 'type' => 'x.step', 'exit' => 'yes'], $end],
				'edges' => [['from' => 't', 'to' => 'a']],
			],
			'untyped and blank' => [
				'nodes' => [$trigger, ['id' => 'a'], ['id' => 'b', 'type' => '  '], ['id' => '', 'type' => 'x.step'], ['type' => 'x.step'], $end],
				'edges' => [],
			],
			'junk entries' => ['nodes' => ['junk', 7, null, $trigger, $end], 'edges' => ['junk', null, ['to' => 't'], ['from' => null]]],
			'unnamespaced and leading dot' => [
				'nodes' => [['id' => 'a', 'type' => 'step'], ['id' => 'b', 'type' => '.step'], ['id' => 'c', 'type' => 'app.']],
				'edges' => [],
			],
			'fan-out endpoints' => [
				'nodes' => [$trigger, ['id' => 'a', 'type' => 'x.step'], ['id' => 'b', 'type' => 'x.step'], $end],
				'edges' => [['from' => ['t', 'a', 'b'], 'to' => ['z']]],
			],
			'source spelling' => ['nodes' => [$trigger, $end], 'edges' => [['source' => 't', 'target' => 'z']]],
			'other app roles' => [
				'nodes' => [['id' => 's', 'type' => 'other.start'], ['id' => 'f', 'type' => 'other.finish']],
				'edges' => [['from' => 's', 'to' => 'f']],
			],
			'no trigger' => ['nodes' => [['id' => 'a', 'type' => 'x.step'], $end], 'edges' => [['from' => 'a', 'to' => 'z']]],
			'no end' => [
				'nodes' => [$trigger, ['id' => 'a', 'type' => 'x.step']],
				'edges' => [['from' => 't', 'to' => 'a'], ['from' => 'a', 'to' => 't']],
			],
			'neither' => ['nodes' => [['id' => 'a', 'type' => 'x.step']], 'edges' => [['from' => 'a', 'to' => 'a']]],
			'edges missing' => ['nodes' => [$trigger, ['id' => 'a', 'type' => 'x.step'], $end]],
			'numeric id' => ['nodes' => [$trigger, ['id' => 5, 'type' => 'x.step'], $end], 'edges' => [['from' => 't', 'to' => '5']]],
		];
	}//end documents()

	public function testFlowConnectivityMatchesGolden(): void {
		$actual = [];
		foreach (self::documents() as $name => $flow) {
			$this->asked = [];
			$actual['deadEnds: ' . $name] = ['findings' => $this->connectivity->deadEnds($flow), 'asked' => $this->asked];
			$this->asked = [];
			$actual['entryAndExit: ' . $name] = ['findings' => $this->connectivity->entryAndExit($flow), 'asked' => $this->asked];
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
	}//end testFlowConnectivityMatchesGolden()
}//end class
