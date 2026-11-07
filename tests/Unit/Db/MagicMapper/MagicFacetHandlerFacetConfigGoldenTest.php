<?php

/**
 * Golden test for MagicFacetHandler::expandFacetConfigFromAllSchemas() and
 * determineFacetTypeFromProperty().
 *
 * Records the merged facet configuration of table sets with and without
 * schemas, for "extend" and comma-separated configs, with overlapping fields
 * and titles that only some schemas carry, and the facet type of property
 * definitions covering every branch. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\Schema;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class MagicFacetHandlerFacetConfigGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/facet-config-golden.json';

	private static function propertyDefinitions(): array {
		return [
			'empty' => [],
			'string' => ['type' => 'string'],
			'date format' => ['type' => 'string', 'format' => 'date'],
			'date-time format' => ['format' => 'date-time'],
			'datetime format' => ['format' => 'datetime'],
			'time format' => ['format' => 'time'],
			'integer' => ['type' => 'integer'],
			'integer with facet_type' => ['type' => 'integer', 'facet_type' => 'range'],
			'number with facet_type' => ['type' => 'number', 'facet_type' => 'histogram'],
			'string with facet_type' => ['type' => 'string', 'facet_type' => 'range'],
			'integer with date format' => ['type' => 'integer', 'format' => 'date', 'facet_type' => 'range'],
			'facetable true' => ['facetable' => true, 'format' => 'date'],
			'facetable terms on date' => ['facetable' => ['type' => 'terms'], 'format' => 'date'],
			'facetable date_histogram' => ['facetable' => ['type' => 'date_histogram']],
			'facetable date_range' => ['facetable' => ['type' => 'date_range'], 'type' => 'integer', 'facet_type' => 'x'],
			'facetable unknown type' => ['facetable' => ['type' => 'range'], 'format' => 'date-time'],
			'facetable null type' => ['facetable' => ['type' => null], 'type' => 'number', 'facet_type' => 'range'],
			'facetable without type' => ['facetable' => ['options' => []]],
			'facetable string' => ['facetable' => 'terms'],
		];
	}//end propertyDefinitions()

	private static function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	private static function tableSets(): array {
		$first = self::schema([
			'name' => ['type' => 'string', 'facetable' => true],
			'kind' => ['type' => 'string', 'facetable' => true],
			'born' => ['type' => 'string', 'format' => 'date', 'facetable' => ['options' => ['interval' => 'year']]],
			'hidden' => ['type' => 'string'],
		]);
		$second = self::schema([
			'kind' => ['type' => 'string', 'title' => 'Kind', 'facetable' => true],
			'name' => ['type' => 'string', 'title' => 'Name', 'facetable' => ['type' => 'terms']],
			'size' => ['type' => 'integer', 'facetable' => true, 'facet_type' => 'range'],
			'7' => ['type' => 'string', 'facetable' => true],
		]);
		$third = self::schema([
			'kind' => ['type' => 'string', 'title' => 'Other kind', 'facetable' => true],
			'born' => ['type' => 'string', 'title' => 'Born', 'facetable' => true],
		]);
		$empty = self::schema([]);

		return [
			'no tables' => ['extend', []],
			'tables without schema' => ['extend', [['tableName' => 'a'], ['tableName' => 'b', 'schema' => null]]],
			'extend one schema' => ['extend', [['schema' => $first]]],
			'extend three schemas' => ['extend', [['schema' => $first], ['schema' => $second], ['schema' => $third]]],
			'extend reversed' => ['extend', [['schema' => $third], ['schema' => $second], ['schema' => $first]]],
			'extend with empty schema' => ['extend', [['schema' => $empty], ['schema' => $second]]],
			'comma list' => ['name, created,register ,owner,updated,schema,organisation,kind', [['schema' => $first], ['schema' => $second]]],
			'empty string' => ['', [['schema' => $first]]],
			'comma list without schema' => ['name', [['tableName' => 'a']]],
		];
	}//end tableSets()

	public function testFacetConfigMatchesGolden(): void {
		$db = $this->createMock(IDBConnection::class);
		$handler = new MagicFacetHandler($db, $this->createMock(LoggerInterface::class));
		$type = new ReflectionMethod(MagicFacetHandler::class, 'determineFacetTypeFromProperty');
		$type->setAccessible(true);
		$merge = new ReflectionMethod(MagicFacetHandler::class, 'expandFacetConfigFromAllSchemas');
		$merge->setAccessible(true);

		$actual = ['types' => [], 'merged' => []];
		foreach (self::propertyDefinitions() as $name => $property) {
			$actual['types'][$name] = $type->invoke($handler, $property);
		}

		foreach (self::tableSets() as $name => [$configString, $tables]) {
			$actual['merged'][$name] = $merge->invoke($handler, $configString, $tables);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $section => $outcomes) {
			foreach ($outcomes as $name => $outcome) {
				$this->assertSame($outcome, $actual[$section][$name] ?? null, $section . ': ' . $name);
			}

			$this->assertSame(array_keys($outcomes), array_keys($actual[$section]), $section);
		}
	}//end testFacetConfigMatchesGolden()
}//end class
