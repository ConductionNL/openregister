<?php

/**
 * Golden test for ExampleSetOrder: the order and the skipped apps order()
 * answers, or the cycle it refuses, for a fixed table of `after` lists.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\FragmentOrdering;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 */
class ExampleSetOrderGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/example-set-order-golden.json';

	public function testOrderMatchesGolden(): void {
		$order  = new ExampleSetOrder();
		$actual = [];
		foreach ($this->cases() as $name => [$after, $enabled, $expected]) {
			try {
				$actual[$name] = $order->order(new FragmentOrdering($after, $enabled, $expected));
			} catch (Throwable $e) {
				$actual[$name] = ['threw' => get_class($e).': '.$e->getMessage()];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame(json_encode($outcome), json_encode($actual[$name]), $name);
		}
	}

	/**
	 * @return array<string, array{0: array<string, array<int, string>>, 1: array<int, string>, 2: array<int, string>}>
	 */
	private function cases(): array {
		$all = ['openregister', 'zaak', 'decidesk', 'pipelinq', 'mydash', 'larpingapp', 'opencatalogi'];
		return [
			'none'                => [[], $all, []],
			'cast only'           => [['openregister' => []], $all, []],
			'no cast'             => [['zaak' => [], 'decidesk' => []], $all, []],
			'ties by id'          => [['zaak' => [], 'decidesk' => [], 'openregister' => [], 'mydash' => []], $all, []],
			'chain'               => [['openregister' => [], 'zaak' => ['decidesk'], 'decidesk' => ['pipelinq'], 'pipelinq' => []], $all, []],
			'cast waits'          => [['openregister' => ['zaak'], 'zaak' => []], $all, []],
			'cast waits chain'    => [['openregister' => ['zaak'], 'zaak' => ['mydash'], 'mydash' => []], $all, []],
			'after disabled'      => [['openregister' => [], 'zaak' => ['ghostapp', 'decidesk', 'mydash']], $all, []],
			'after enabled absent' => [['zaak' => ['mydash', 'pipelinq']], $all, []],
			'expected skipped'    => [['openregister' => []], ['openregister'], ['zaak', 'openregister', 'zaak', 'betaapp']],
			'skipped sorted'      => [['zaak' => ['zz', 'aa'], 'decidesk' => ['mm', 'aa']], $all, ['bb', 'zz']],
			'self loop'           => [['zaak' => ['zaak']], $all, []],
			'two cycle'           => [['openregister' => [], 'zaak' => ['decidesk'], 'decidesk' => ['zaak'], 'mydash' => []], $all, []],
			'cycle with tail'     => [['zaak' => ['decidesk'], 'decidesk' => ['pipelinq'], 'pipelinq' => ['zaak'], 'mydash' => ['zaak']], $all, []],
			'cycle via cast'      => [['openregister' => ['zaak'], 'zaak' => []], ['zaak'], []],
			'cast cycle'          => [['openregister' => ['zaak'], 'zaak' => ['openregister']], $all, []],
			'diamond'             => [
				['a' => ['b', 'c'], 'b' => ['d'], 'c' => ['d'], 'd' => [], 'openregister' => []],
				['a', 'b', 'c', 'd', 'openregister'],
				[],
			],
			'duplicate after'     => [['zaak' => ['decidesk', 'decidesk'], 'decidesk' => []], $all, []],
		];
	}
}
