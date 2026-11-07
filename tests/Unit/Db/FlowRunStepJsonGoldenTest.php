<?php

/**
 * Golden test for the FlowRunStep entity: field types and API serialisation.
 *
 * Pins the field types the constructor registers and the exact
 * jsonSerialize() output (keys, order, values, PHP types) for an empty step,
 * a step hydrated from database strings, and a step with a null sequence and
 * partial timestamps. Dates carry an explicit offset so nothing depends on the
 * PHP build's default timezone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowRunStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowRunStep
 */
class FlowRunStepJsonGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/flow-run-step-json-golden.json';

	public function testFieldTypesAndJsonMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $step) {
			$actual[$name] = [
				'types' => $step->getFieldTypes(),
				'json' => $this->typed(value: $step->jsonSerialize()),
			];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertNotEmpty($expected);
		foreach ($expected as $name => $outcome) {
			$this->assertNotEmpty($outcome['json'], $name);
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testFieldTypesAndJsonMatchGolden()

	/**
	 * @return array<string, FlowRunStep>
	 */
	private function cases(): array {
		$partial = new FlowRunStep();
		$partial->setSequence(null);
		$partial->setStarted(new DateTime('2001-02-03T04:05:06+02:00'));
		$partial->setOutput([]);
		$partial->setStatus(FlowRunStep::STATUS_SUSPENDED);

		return [
			'empty' => new FlowRunStep(),
			'full' => FlowRunStep::fromRow($this->row()),
			'null sequence, started only' => $partial,
		];
	}//end cases()

	private function row(): array {
		return [
			'id' => 11,
			'run_uuid' => 'run-1',
			'flow_id' => 'flow-1',
			'node_id' => 'n1',
			'node_type' => 'http',
			'sequence' => '3',
			'stream_id' => 's-1',
			'ordinal_path' => '0.2',
			'status' => 'ok',
			'started' => '2001-02-03T04:05:06+00:00',
			'finished' => '2001-02-03T04:05:07+00:00',
			'duration_ms' => '1000',
			'output' => '{"status":200,"body":["a"]}',
			'error' => 'none',
			'created' => '2001-02-03T04:05:00+00:00',
		];
	}//end row()

	/**
	 * Values with their PHP type, so `'3'` and `3` do not pin the same.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, each scalar as `type:value`.
	 */
	private function typed(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->typed(value: $item), $value);
		}

		return get_debug_type($value) . ':' . var_export($value, true);
	}//end typed()
}//end class
