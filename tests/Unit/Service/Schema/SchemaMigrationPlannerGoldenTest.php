<?php

/**
 * Golden test for SchemaMigrationPlanner.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Runs every plan below through validatePlan() and every (data, plan) pair
 * through apply(), and compares the full outcome grid with
 * fixtures/schema-migration-planner-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture. Every date carries its own offset or format so the
 * grid depends neither on today's date nor on the default timezone.
 *
 * @covers \OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner
 */
class SchemaMigrationPlannerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/schema-migration-planner-golden.json';

	/**
	 * Plans for validatePlan().
	 *
	 * @return array<string, array<int|string, mixed>>
	 */
	private static function plans(): array {
		return [
			'empty' => [],
			'not an array' => ['rename'],
			'no op' => [['from' => 'a']],
			'null op' => [['op' => null]],
			'unknown op' => [['op' => 'merge'], ['op' => 5]],
			'keyed steps' => ['first' => ['op' => 'merge'], 'second' => 'x'],
			'rename' => [
				['op' => 'rename'],
				['op' => 'rename', 'from' => 'a'],
				['op' => 'rename', 'from' => 'a', 'to' => '0'],
				['op' => 'rename', 'from' => 'a', 'to' => 'b'],
			],
			'setDefault' => [
				['op' => 'setDefault', 'value' => 1],
				['op' => 'setDefault', 'field' => 'a'],
				['op' => 'setDefault', 'field' => 'a', 'value' => null],
			],
			'cast' => [
				['op' => 'cast', 'field' => 'a'],
				['op' => 'cast', 'to' => 'string'],
				['op' => 'cast', 'field' => 'a', 'to' => 'float'],
				['op' => 'cast', 'field' => 'a', 'to' => 'date'],
				['op' => 'cast', 'field' => 'a', 'to' => 'string'],
				['op' => 'cast', 'field' => 'a', 'to' => 'integer'],
				['op' => 'cast', 'field' => 'a', 'to' => 'number'],
				['op' => 'cast', 'field' => 'a', 'to' => 'boolean'],
			],
			'drop' => [['op' => 'drop'], ['op' => 'drop', 'field' => ''], ['op' => 'drop', 'field' => 'a']],
			'compute' => [['op' => 'compute', 'field' => 'a'], ['op' => 'compute', 'template' => ''], ['op' => 'compute', 'field' => 'a', 'template' => '']],
			'all problems at once' => [['op' => 'rename'], 'x', ['op' => 'nope'], ['op' => 'drop']],
		];
	}//end plans()

	/**
	 * Data and plan pairs for apply().
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, mixed>}>
	 */
	private static function applications(): array {
		$data = [
			'name' => 'Jan',
			'age' => '42',
			'active' => 'Yes',
			'empty' => null,
			'tags' => ['a'],
			'born' => '05/03/2024 10:00:00 +02:00',
		];
		$cast = static fn (mixed $value, string $to): array => [['v' => $value], [['op' => 'cast', 'field' => 'v', 'to' => $to]]];
		return [
			'empty plan' => [$data, []],
			'rename present' => [$data, [['op' => 'rename', 'from' => 'name', 'to' => 'naam']]],
			'rename missing' => [$data, [['op' => 'rename', 'from' => 'nope', 'to' => 'x']]],
			'rename onto existing' => [$data, [['op' => 'rename', 'from' => 'name', 'to' => 'age']]],
			'rename null value' => [$data, [['op' => 'rename', 'from' => 'empty', 'to' => 'leeg']]],
			'setDefault missing' => [$data, [['op' => 'setDefault', 'field' => 'city', 'value' => 'Utrecht']]],
			'setDefault null' => [$data, [['op' => 'setDefault', 'field' => 'empty', 'value' => 0]]],
			'setDefault present' => [$data, [['op' => 'setDefault', 'field' => 'name', 'value' => 'Piet']]],
			'drop present' => [$data, [['op' => 'drop', 'field' => 'tags']]],
			'drop missing' => [$data, [['op' => 'drop', 'field' => 'nope']]],
			'compute' => [
				$data,
				[['op' => 'compute', 'field' => 'label', 'template' => '{{ name }} ({{age}}) {{ tags }} {{ empty }} {{ nope }} {{ bad-key }}']],
			],
			'compute active true' => [
				['flag' => true, 'off' => false, 'n' => 1.5],
				[['op' => 'compute', 'field' => 'x', 'template' => '{{flag}}|{{off}}|{{n}}']],
			],
			'chain' => [$data, [
				['op' => 'rename', 'from' => 'age', 'to' => 'leeftijd'],
				['op' => 'cast', 'field' => 'leeftijd', 'to' => 'integer'],
				['op' => 'cast', 'field' => 'active', 'to' => 'boolean'],
				['op' => 'drop', 'field' => 'tags'],
				['op' => 'compute', 'field' => 'label', 'template' => '{{name}}-{{leeftijd}}'],
				['op' => 'setDefault', 'field' => 'empty', 'value' => 'x'],
			]],
			'failure keeps the original' => [$data, [
				['op' => 'rename', 'from' => 'name', 'to' => 'naam'],
				['op' => 'cast', 'field' => 'tags', 'to' => 'string'],
				['op' => 'drop', 'field' => 'age'],
			]],
			'unknown op after a step' => [$data, [['op' => 'drop', 'field' => 'tags'], ['op' => 'merge']]],
			'step without op' => [$data, [['field' => 'x']]],
			'non array step' => [$data, ['drop']],
			'boolean true op' => [$data, [['op' => true, 'from' => 'name', 'to' => 'x']]],
			'boolean false op' => [$data, [['op' => false]]],
			'integer zero op' => [$data, [['op' => 0]]],
			'cast missing field' => [$data, [['op' => 'cast', 'field' => 'nope', 'to' => 'integer']]],
			'cast null field' => [$data, [['op' => 'cast', 'field' => 'empty', 'to' => 'integer']]],
			'cast unsupported type' => $cast('1', 'float'),
			'string from int' => $cast(5, 'string'),
			'string from float' => $cast(1.5, 'string'),
			'string from bool' => $cast(false, 'string'),
			'string from array' => $cast(['a'], 'string'),
			'integer from numeric string' => $cast(' 12', 'integer'),
			'integer from float string' => $cast('12.9', 'integer'),
			'integer from bool' => $cast(true, 'integer'),
			'integer from word' => $cast('twelve', 'integer'),
			'integer from array' => $cast(['a'], 'integer'),
			'number from string' => $cast('1e3', 'number'),
			'number from int' => $cast(7, 'number'),
			'number from bool' => $cast(false, 'number'),
			'number from word' => $cast('x', 'number'),
			'boolean from bool' => $cast(false, 'boolean'),
			'boolean from one' => $cast(1, 'boolean'),
			'boolean from zero' => $cast(0, 'boolean'),
			'boolean from two' => $cast(2, 'boolean'),
			'boolean from float one' => $cast(1.0, 'boolean'),
			'boolean from word on' => $cast(' ON ', 'boolean'),
			'boolean from word no' => $cast('No', 'boolean'),
			'boolean from string one' => $cast('1', 'boolean'),
			'boolean from string zero' => $cast('0', 'boolean'),
			'boolean from word maybe' => $cast('maybe', 'boolean'),
			'boolean from array' => $cast([], 'boolean'),
			'date with offset' => $cast('2024-03-05T10:00:00+02:00', 'date'),
			'date from timestamp string' => $cast('@1700000000', 'date'),
			'date unparseable' => $cast('not a date', 'date'),
			'date from array' => $cast(['x'], 'date'),
			'date from bool' => $cast(true, 'date'),
			'date with format' => [$data, [['op' => 'cast', 'field' => 'born', 'to' => 'date', 'format' => 'd/m/Y H:i:s P']]],
			'date with wrong format' => [$data, [['op' => 'cast', 'field' => 'born', 'to' => 'date', 'format' => 'Y-m-d']]],
			'date with empty format' => [['v' => '2024-03-05T10:00:00+02:00'], [['op' => 'cast', 'field' => 'v', 'to' => 'date', 'format' => '']]],
		];
	}//end applications()

	/**
	 * @return void
	 */
	public function testOutcomesMatchTheGoldenGrid(): void {
		$planner = new SchemaMigrationPlanner();
		$actual = ['validatePlan' => [], 'apply' => []];
		foreach (self::plans() as $name => $plan) {
			$actual['validatePlan'][$name] = $planner->validatePlan($plan);
		}

		foreach (self::applications() as $name => [$data, $plan]) {
			$result = $planner->apply($data, $plan);
			$actual['apply'][$name] = [
				'data' => $result->getData(),
				'changed' => $result->isChanged(),
				'failed' => $result->isFailed(),
				'failure' => $result->getFailure(),
				'applied' => $result->getApplied(),
			];
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}//end testOutcomesMatchTheGoldenGrid()

	/**
	 * @return void
	 */
	public function testAnInjectedRendererComputesTheField(): void {
		$calls = [];
		$planner = new SchemaMigrationPlanner(
			static function (string $template, array $context) use (&$calls): string {
				$calls[] = [$template, $context];
				return 'rendered';
			}
		);

		$result = $planner->apply(['a' => 1], [['op' => 'compute', 'field' => 'b', 'template' => '{{ a }}']]);

		$this->assertSame(['a' => 1, 'b' => 'rendered'], $result->getData());
		$this->assertSame([['{{ a }}', ['a' => 1]]], $calls);
		$this->assertSame(['compute b'], $result->getApplied());
	}//end testAnInjectedRendererComputesTheField()
}//end class
