<?php

declare(strict_types=1);

/**
 * Golden record of every refusal of RepeatingGroupValidator
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\RepeatingGroupValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the validator answers per scenario, in order.
 */
final class RepeatingGroupValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/repeating-group-validator-golden.json';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$validator = new RepeatingGroupValidator();
		$actual = [];
		foreach ($this->scenarios() as $name => [$properties, $object]) {
			$schema = new Schema();
			$schema->setProperties($properties);
			$actual[$name] = $validator->validate($object, $schema);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertGreaterThan(5, count(array_filter($actual)));
		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), json_decode((string) json_encode($actual), true));
	}

	/**
	 * The scenarios: properties and an object.
	 *
	 * @return array<string, array{0: array, 1: array}>
	 */
	private function scenarios(): array {
		$group = [
			Schema::REPEATING_GROUP_PROPERTY_KEYWORD => true,
			'minItems' => 1,
			'maxItems' => '2',
			'items' => [
				'required' => ['naam', 5],
				'properties' => [
					'naam' => ['type' => 'string'],
					'aandeel' => ['type' => 'integer'],
					'getal' => ['type' => 'number'],
					'vlag' => ['type' => 'boolean'],
					'lijst' => ['type' => 'array'],
					'ding' => ['type' => 'object'],
					'vrij' => ['type' => 'color'],
					'kaal' => 'not a declaration',
					7 => ['type' => 'integer'],
				],
			],
		];
		$p = ['g' => $group, 'gewoon' => ['type' => 'string'], 'stuk' => 'x', 'uit' => [Schema::REPEATING_GROUP_PROPERTY_KEYWORD => false]];
		$five = [5 => 'x'];
		$bad = ['naam' => [], 'aandeel' => 1.5, 'getal' => 'x', 'vlag' => 'ja', 'lijst' => ['a' => 1], 'ding' => [1], 'vrij' => 3, 7 => 'x'] + $five;
		$good = ['naam' => 12, 'aandeel' => '3', 'getal' => '1.5', 'vlag' => 'true', 'lijst' => [], 'ding' => [], 'vrij' => [], 7 => 1] + $five;

		return [
			'no properties' => [[], ['g' => 1]],
			'not mentioned' => [$p, []],
			'null clears' => [$p, ['g' => null]],
			'empty string clears' => [$p, ['g' => '']],
			'empty list' => [$p, ['g' => []]],
			'a scalar' => [$p, ['g' => 3]],
			'a map' => [$p, ['g' => ['a' => ['naam' => 'x']]]],
			'too many' => [$p, ['g' => [$good, $good, $good]]],
			'a row that is no object' => [$p, ['g' => ['x', [1, 2]]]],
			'a good row' => [$p, ['g' => [$good]]],
			'a row with everything wrong' => [$p, ['g' => [$bad, $good]]],
			'missing members' => [$p, ['g' => [['naam' => ''], ['naam' => null, 'x' => 1]]]],
			'unknown bound text' => [['g' => array_merge($group, ['minItems' => 'many', 'maxItems' => null])], ['g' => []]],
			'broken items' => [['g' => array_merge($group, ['items' => ['properties' => 'x', 'required' => 'y']])], ['g' => [['a' => 1]]]],
			'no items' => [['g' => [Schema::REPEATING_GROUP_PROPERTY_KEYWORD => true]], ['g' => [['a' => 1]]]],
			'two groups' => [['a' => $group, 'b' => $group], ['a' => 1, 'b' => []]],
		];
	}
}
