<?php

/**
 * Golden test for FlowStateNode: validateConfig() and execute() over every
 * operation, slot-table shape, lease outcome and release path.
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

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowStateHandle;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode
 */
class FlowStateNodeGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/flow-state-node-golden.json';

	/**
	 * A `since` long past every lease in the cases.
	 *
	 * @var string
	 */
	private const LONG_AGO = '2000-01-01T00:00:00+00:00';

	/**
	 * A `since` that no lease in the cases can have run out from.
	 *
	 * @var string
	 */
	private const FAR_FUTURE = '2999-01-01T00:00:00+00:00';

	/**
	 * @var FlowStateNode
	 */
	private FlowStateNode $node;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->node = new FlowStateNode($l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	/**
	 * Replace a freshly stamped `since` with a marker so the fixture never
	 * depends on the clock. Inputs only ever carry LONG_AGO, FAR_FUTURE or
	 * values that are not timestamps at all.
	 *
	 * @param mixed $value Any output value.
	 *
	 * @return mixed The value with fresh stamps masked.
	 */
	private static function mask(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $inner) {
			if ($key === 'since' && is_string($inner) === true && $inner !== self::LONG_AGO && $inner !== self::FAR_FUTURE) {
				$parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $inner);
				$fresh  = ($parsed !== false && abs($parsed->getTimestamp() - time()) < 3600);
				if ($fresh === true) {
					$value[$key] = '<now>';
				}

				continue;
			}

			$value[$key] = self::mask($inner);
		}

		return $value;
	}//end mask()

	private function runCase(array $case): array {
		$handle = null;
		if (array_key_exists('state', $case) === true) {
			$handle = new FlowStateHandle($case['state']);
		}

		$context = [];
		if ($handle !== null) {
			$context[FlowStateHandle::CONTEXT_KEY] = $handle;
		}

		try {
			$out = $this->node->execute(new FlowNodeCall(items: $case['items'], config: $case['config'], context: $context));
		} catch (Throwable $e) {
			return ['error' => get_class($e) . ': ' . $e->getMessage()];
		}

		return self::mask(
			[
				'out'     => $out,
				'state'   => $handle?->all(),
				'written' => $handle?->writtenKeys(),
			]
		);
	}//end runCase()

	private function validate(array $config): string {
		try {
			$this->node->validateConfig($config);
		} catch (Throwable $e) {
			return get_class($e) . ': ' . $e->getMessage();
		}

		return 'ok';
	}//end validate()

	private static function validateCases(): array {
		return [
			'no operation'          => [],
			'unknown operation'     => ['operation' => 'bogus'],
			'get without key'       => ['operation' => 'get'],
			'get blank key'         => ['operation' => 'get', 'key' => '  '],
			'get ok'                => ['operation' => 'get', 'key' => 'k'],
			'set ok'                => ['operation' => 'set', 'key' => 'k'],
			'forget without key'    => ['operation' => 'forget'],
			'claim without slots'   => ['operation' => 'claim', 'capacity' => 2],
			'claim blank slots'     => ['operation' => 'claim', 'slots' => ' ', 'capacity' => 2],
			'claim no capacity'     => ['operation' => 'claim', 'slots' => 's'],
			'claim zero capacity'   => ['operation' => 'claim', 'slots' => 's', 'capacity' => 0],
			'claim ok'              => ['operation' => 'claim', 'slots' => 's', 'capacity' => 1],
			'claim ignores key'     => ['operation' => 'claim', 'slots' => 's', 'capacity' => '3'],
			'release without slots' => ['operation' => 'release'],
			'release ok no cap'     => ['operation' => 'release', 'slots' => 's'],
		];
	}//end validateCases()

	private static function executeCases(): array {
		$claim = ['operation' => 'claim', 'slots' => 'pool', 'capacity' => 3];
		$release = ['operation' => 'release', 'slots' => 'pool'];

		return [
			'no state handle'               => ['items' => [['json' => []]], 'config' => ['operation' => 'get', 'key' => 'k']],
			'get missing default null'      => ['state' => [], 'items' => [['json' => ['a' => 1]]], 'config' => ['operation' => 'get', 'key' => 'k']],
			'get with default and as' => [
				'state' => [],
				'items' => [['json' => []]],
				'config' => ['operation' => 'get', 'key' => 'k', 'as' => 'out', 'default' => 7],
			],
			'get present' => [
				'state' => ['k' => ['x' => 1]],
				'items' => [['json' => []], ['json' => ['b' => 2], 'binary' => ['f' => ['data' => 'x']]]],
				'config' => ['operation' => 'get', 'key' => 'k'],
			],
			'set literal' => [
				'state' => [],
				'items' => [['json' => []]],
				'config' => ['operation' => 'set', 'key' => 'k', 'value' => 5],
			],
			'set from item' => [
				'state' => [],
				'items' => [['json' => ['n' => 9]]],
				'config' => ['operation' => 'set', 'key' => 'k', 'value' => 5, 'from' => 'n'],
			],
			'set from missing field' => [
				'state' => ['k' => 1],
				'items' => [['json' => []]],
				'config' => ['operation' => 'set', 'key' => 'k', 'value' => 5, 'from' => 'n'],
			],
			'set no value'                  => ['state' => ['k' => 1], 'items' => [['json' => []]], 'config' => ['operation' => 'set', 'key' => 'k']],
			'forget' => [
				'state' => ['k' => 1, 'j' => 2],
				'items' => [['json' => []]],
				'config' => ['operation' => 'forget', 'key' => 'k'],
			],
			'unknown op passes through'     => ['state' => [], 'items' => [['json' => ['a' => 1]]], 'config' => ['operation' => 'bogus']],
			'no items'                      => ['state' => [], 'items' => [], 'config' => $claim],
			'claim empty pool'              => ['state' => [], 'items' => [['json' => ['holder' => 'h1']]], 'config' => $claim],
			'claim no holder'               => ['state' => [], 'items' => [['json' => []]], 'config' => $claim],
			'claim custom holder field' => [
				'state' => [],
				'items' => [['json' => ['who' => 'w1', 'holder' => 'ignored']]],
				'config' => $claim + ['holder' => 'who'],
			],
			'claim with record' => [
				'state' => [],
				'items' => [['json' => ['holder' => 'h1', 'stage' => 'build', 'since' => 'x']]],
				'config' => $claim + ['record' => ['stage', 'repo', '', 'holder', 'since', 3]],
			],
			'claim record scalar' => [
				'state' => [],
				'items' => [['json' => ['holder' => 'h1', 'stage' => 's']]],
				'config' => $claim + ['record' => 'stage'],
			],
			'claim skips taken' => [
				'state' => ['pool' => ['1' => ['holder' => 'a', 'since' => self::LONG_AGO], '2' => null]],
				'items' => [['json' => ['holder' => 'h']]],
				'config' => $claim,
			],
			'claim full' => [
				'state' => ['pool' => ['1' => 'a', '2' => true, '3' => ['holder' => 'c']]],
				'items' => [['json' => ['holder' => 'h']]],
				'config' => $claim,
			],
			'claim two items fill' => [
				'state' => ['pool' => ['1' => 'a']],
				'items' => [['json' => ['holder' => 'x']], ['json' => ['holder' => 'y']], ['json' => ['holder' => 'z']]],
				'config' => $claim,
			],
			'claim lease expired' => [
				'state' => ['pool' => ['1' => ['holder' => 'a', 'since' => self::LONG_AGO], '2' => 'b', '3' => true]],
				'items' => [['json' => ['holder' => 'h']]],
				'config' => $claim + ['leaseSeconds' => 60],
			],
			'claim lease not expired' => [
				'state' => ['pool' => ['1' => ['holder' => 'a', 'since' => self::FAR_FUTURE], '2' => 'b', '3' => true]],
				'items' => [['json' => ['holder' => 'h']]],
				'config' => $claim + ['leaseSeconds' => 60],
			],
			'claim lease no since' => [
				'state' => ['pool' => ['1' => ['holder' => 'a'], '2' => ['holder' => 'b', 'since' => ''], '3' => ['holder' => 'c', 'since' => 5]]],
				'items' => [['json' => ['holder' => 'h']]],
				'config' => $claim + ['leaseSeconds' => 60],
			],
			'claim lease garbage since' => [
				'state' => ['pool' => ['1' => ['holder' => 'a', 'since' => 'not a date'], '2' => 'b', '3' => 'c']],
				'items' => [['json' => ['holder' => 'h']]],
				'config' => $claim + ['leaseSeconds' => 60],
			],
			'claim lease zero' => [
				'state' => ['pool' => ['1' => ['holder' => 'a', 'since' => self::LONG_AGO], '2' => 'b', '3' => 'c']],
				'items' => [['json' => ['holder' => 'h']]],
				'config' => $claim + ['leaseSeconds' => 0],
			],
			'claim keeps over-capacity' => [
				'state' => ['pool' => ['1' => 'a', '5' => 'e', '6' => null, 'x' => true]],
				'items' => [['json' => ['holder' => 'h']]],
				'config' => ['operation' => 'claim', 'slots' => 'pool', 'capacity' => 2],
			],
			'claim capacity string' => [
				'state' => [],
				'items' => [['json' => ['holder' => 'h']]],
				'config' => ['operation' => 'claim', 'slots' => 'pool', 'capacity' => '1'],
			],
			'release by slot' => [
				'state' => ['pool' => ['1' => ['holder' => 'a'], '2' => 'b']],
				'items' => [['json' => ['slot' => 1]]],
				'config' => $release,
			],
			'release by custom slot field' => [
				'state' => ['pool' => ['1' => 'a', '2' => 'b']],
				'items' => [['json' => ['s' => '2']]],
				'config' => $release + ['slot' => 's'],
			],
			'release slot unknown'          => ['state' => ['pool' => ['1' => 'a']], 'items' => [['json' => ['slot' => 9]]], 'config' => $release],
			'release slot unknown holder' => [
				'state' => ['pool' => ['1' => 'a', '2' => ['holder' => 'b']]],
				'items' => [['json' => ['slot' => 9, 'holder' => 'b']]],
				'config' => $release,
			],
			'release by holder record' => [
				'state' => ['pool' => ['1' => ['holder' => 'a'], '2' => ['holder' => 'b', 'since' => self::LONG_AGO]]],
				'items' => [['json' => ['holder' => 'b']]],
				'config' => $release,
			],
			'release by holder legacy' => [
				'state' => ['pool' => ['1' => true, '2' => 'b', '3' => null]],
				'items' => [['json' => ['holder' => 'b']]],
				'config' => $release,
			],
			'release by holder int' => [
				'state' => ['pool' => ['1' => ['holder' => 7]]],
				'items' => [['json' => ['holder' => '7']]],
				'config' => $release,
			],
			'release holder not held' => [
				'state' => ['pool' => ['1' => ['holder' => 'a'], '2' => true, '3' => ['since' => 'x']]],
				'items' => [['json' => ['holder' => 'z']]],
				'config' => $release,
			],
			'release holder custom field' => [
				'state' => ['pool' => ['1' => 'a', '2' => 'b']],
				'items' => [['json' => ['who' => 'b']]],
				'config' => $release + ['holder' => 'who'],
			],
			'release nothing named'         => ['state' => ['pool' => ['1' => 'a']], 'items' => [['json' => []]], 'config' => $release],
			'release null slot by holder' => [
				'state' => ['pool' => ['1' => 'a', '2' => 'b']],
				'items' => [['json' => ['slot' => null, 'holder' => 'a']]],
				'config' => $release,
			],
			'release empty pool'            => ['state' => [], 'items' => [['json' => ['slot' => 1, 'holder' => 'a']]], 'config' => $release],
			'release first of two matches' => [
				'state' => ['pool' => ['1' => 'a', '2' => ['holder' => 'a']]],
				'items' => [['json' => ['holder' => 'a']], ['json' => ['holder' => 'a']]],
				'config' => $release,
			],
		];
	}//end executeCases()

	public function testFlowStateNodeMatchesGolden(): void {
		$actual = [];
		foreach (self::validateCases() as $name => $config) {
			$actual['validate: ' . $name] = $this->validate($config);
		}

		foreach (self::executeCases() as $name => $case) {
			$actual['execute: ' . $name] = $this->runCase($case);
		}

		$actual['configKeys'] = $this->node->configKeys();

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
	}//end testFlowStateNodeMatchesGolden()
}//end class
