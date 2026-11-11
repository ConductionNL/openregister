<?php

/**
 * Golden test for ImportedSchemaShape::normalisedProperty().
 *
 * Records the JSON of the normalised property (so key order and empty
 * objects versus empty arrays are pinned) for object and array properties,
 * missing and empty titles, missing and null types, binary-ish and other
 * formats, empty and filled object/file configurations, and items given as
 * object, array, string or null. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ImportedSchemaShape;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportedSchemaShape
 */
class ImportHandlerPropertyShapeGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/import-property-shape-golden.json';

	private static function properties(): array {
		$object = new stdClass();
		$object->type = 'integer';
		$object->format = 'byte';
		$items = new stdClass();
		$items->format = 'binary';
		$items->objectConfiguration = [];

		return [
			'null' => ['n', null],
			'empty array' => ['e', []],
			'object' => ['obj', $object],
			'title kept' => ['k', ['title' => 'Kept', 'type' => 'number']],
			'title empty string' => ['k', ['title' => '', 'type' => 'string']],
			'title zero string' => ['k', ['title' => '0']],
			'title null' => ['k', ['title' => null]],
			'int key' => [7, ['type' => 'boolean']],
			'type null' => ['t', ['type' => null, 'title' => 'T']],
			'type after title' => ['t', ['description' => 'd']],
			'format string' => ['f', ['format' => 'string', 'type' => 'string']],
			'format binary' => ['f', ['format' => 'binary']],
			'format byte' => ['f', ['format' => 'byte']],
			'format date' => ['f', ['format' => 'date']],
			'format null' => ['f', ['format' => null]],
			'empty configurations' => ['c', ['objectConfiguration' => [], 'fileConfiguration' => [], 'type' => 'object']],
			'filled configurations' => ['c', ['objectConfiguration' => ['handling' => 'related'], 'fileConfiguration' => ['a']]],
			'null configurations' => ['c', ['objectConfiguration' => null, 'fileConfiguration' => null]],
			'items object' => ['i', ['type' => 'array', 'items' => $items]],
			'items array' => ['i', ['type' => 'array', 'items' => ['format' => 'byte', 'fileConfiguration' => [], 'items' => ['format' => 'binary']]]],
			'items empty array' => ['i', ['items' => []]],
			'items string' => ['i', ['items' => 'string']],
			'items null' => ['i', ['items' => null, 'format' => 'binary']],
		];
	}//end properties()

	public function testPropertyShapeMatchesGolden(): void {
		$handler = (new ReflectionClass(ImportedSchemaShape::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(ImportedSchemaShape::class, 'normalisedProperty');
		$method->setAccessible(true);

		$actual = [];
		foreach (self::properties() as $name => [$key, $property]) {
			if (is_object($property) === true) {
				$property = clone $property;
			}

			$actual[$name] = json_encode($method->invoke($handler, $key, $property), JSON_UNESCAPED_SLASHES);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testPropertyShapeMatchesGolden()
}//end class
