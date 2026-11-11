<?php

/**
 * FacetHandler::getFacetableFieldsFromSchemas() discovers facetable and searchable fields exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\SchemaProperty;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\FacetLookups;
use OCA\OpenRegister\Service\Object\FacetResponseCache;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden record of facetable field discovery: the '@self' defaults, the searchable fields with
 * their match type and input control, the aggregated object fields merged across schemas, the
 * non-aggregated fields with their schema id, and the log lines. Covers boolean and object
 * facetable configs, empty and false configs, encrypted properties, governed properties that are
 * withheld or allowed, date and non-date types, declared match types and controls, entries that
 * are not schemas, and a schema whose properties cannot be read. Set OR_GOLDEN_RECORD=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\FacetHandler
 * @covers \OCA\OpenRegister\Service\Object\FacetCalculation
 * @covers \OCA\OpenRegister\Service\Object\FacetListing
 * @covers \OCA\OpenRegister\Service\Object\FacetEntry
 * @covers \OCA\OpenRegister\Service\Object\FacetableFieldDiscovery
 * @covers \OCA\OpenRegister\Service\Object\FacetRun
 * @covers \OCA\OpenRegister\Service\Object\NonAggregatedFacetKey
 * @uses   \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 * @uses   \OCA\OpenRegister\Search\Service\Search\PropertySearchProfile
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class FacetHandlerFacetableFieldsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/facet-handler-facetable-fields-golden.json';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $schemas) {
			$actual[$name] = $this->outcome(schemas: $schemas);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testScenariosMatchTheGolden()

	/**
	 * The scenarios: each a list of schema entries handed to the discovery.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private function scenarios(): array {
		$mixed = $this->schema(id: 3, properties: [
			'colour'  => ['type' => 'string', 'title' => 'Kleur', 'facetable' => true],
			'born'    => ['type' => 'string', 'format' => 'date', 'facetable' => ['title' => 'Born', 'order' => 2]],
			'stamp'   => ['type' => 'string', 'format' => 'date-time', 'facetable' => ['aggregated' => true]],
			'kind'    => ['type' => 'date', 'facetable' => true],
			'tag'     => ['type' => 'string', 'title' => 'Tag', 'facetable' => ['aggregated' => false, 'title' => 'Tag name', 'description' => 'd', 'order' => 1]],
			'loose'   => ['type' => 'integer', 'facetable' => ['aggregated' => false]],
			'plain'   => ['type' => 'string', 'title' => 'Plain'],
			'off'     => ['type' => 'string', 'facetable' => false],
			'emptyCf' => ['type' => 'string', 'facetable' => []],
			'secret'  => ['type' => 'string', 'facetable' => true, 'x-openregister-encrypted' => true],
			'notEnc'  => ['type' => 'string', 'facetable' => true, 'x-openregister-encrypted' => 'yes'],
			'match'   => ['type' => 'string', 'matchType' => 'Prefix', 'inputControl' => 'select', 'facetable' => true],
			'badMt'   => ['type' => 'string', 'matchType' => 'nonsense', 'inputControl' => 'nonsense'],
			'number'  => ['type' => 'number', 'facetable' => true],
		]);
		$governed = $this->schema(id: 4, properties: [
			'open'    => ['type' => 'string', 'facetable' => true],
			'hidden'  => ['type' => 'string', 'facetable' => true, 'authorization' => ['read' => ['admin']]],
			'allowed' => ['type' => 'string', 'facetable' => ['aggregated' => false], 'authorization' => ['read' => ['staff']]],
		]);
		$other = $this->schema(id: 5, properties: [
			'colour' => ['type' => 'string', 'title' => 'Colour', 'facetable' => ['aggregated' => true, 'title' => 'C']],
			'tag'    => ['type' => 'string', 'facetable' => ['aggregated' => false]],
		]);
		$failing = new class extends Schema {
			/**
			 * Refuse to hand out the properties.
			 *
			 * @return array<string, mixed>
			 */
			public function getProperties(): array {
				throw new Exception('properties unreadable');
			}//end getProperties()
		};
		$failing->setId(9);

		return [
			'no schemas'         => [],
			'not schemas'        => ['schema', 7, null, ['properties' => []]],
			'mixed properties'   => [$mixed],
			'governed'           => [$governed],
			'merged across'      => [$mixed, $other],
			'merged reversed'    => [$other, 'skip', $mixed],
			'failing schema'     => [$other, $failing, $governed],
			'schema without id'  => [$this->schema(id: null, properties: ['x' => ['type' => 'string', 'facetable' => ['aggregated' => false]]])],
		];
	}//end scenarios()

	/**
	 * Build a schema with the given id and properties.
	 *
	 * @param int|null             $id         The schema id.
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(?int $id, array $properties): Schema {
		$schema = new Schema();
		if ($id !== null) {
			$schema->setId($id);
		}

		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	/**
	 * Run the discovery over one list of schemas and record the result and the log lines.
	 *
	 * @param array<int, mixed> $schemas The schema entries.
	 *
	 * @return array<string, mixed> The recorded outcome.
	 */
	private function outcome(array $schemas): array {
		$record = ['logs' => []];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use (&$record, $level): void {
					unset($context['line'], $context['file']);
					$record['logs'][] = [$level, $message, $context];
				}
			);
		}

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willReturnCallback(
			fn (SchemaProperty $read, PropertyAccessRequest $request): bool => $read->name === 'allowed'
		);

		$handler = new FacetHandler(
			lookups: new FacetLookups($this->createMock(MagicMapper::class), $this->createMock(SchemaMapperInterface::class)),
			responseCache: $this->createMock(FacetResponseCache::class),
			logger: $logger,
			propertyRbac: $rbac
		);

		$method = new ReflectionMethod(FacetHandler::class, 'getFacetableFieldsFromSchemas');
		$record['result'] = $method->invoke($handler, $schemas);
		return $record;
	}//end outcome()
}//end class
