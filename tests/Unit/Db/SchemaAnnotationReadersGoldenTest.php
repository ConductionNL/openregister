<?php

/**
 * SchemaAnnotationReadersGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins what the annotation readers of the entity return for every shape of declaration.
 *
 * The fixture was written from the entity before the readers moved into
 * collaborators: getLenses(), getListPresentation(), getGeoInheritance(),
 * getObjectSource() and notSuppliedReasons(), each over a configuration that
 * is absent, malformed, partly valid and valid.
 *
 * Regenerate only on purpose: UPDATE_GOLDEN=1 vendor/bin/phpunit <this file>.
 */
class SchemaAnnotationReadersGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/schema-annotation-readers-golden.json';

	public function testReadersMatchGolden(): void {
		$actual = [];
		foreach ($this->configurations() as $name => $configuration) {
			$schema = new Schema();
			(new ReflectionProperty(Schema::class, 'configuration'))->setValue($schema, $configuration);
			$actual[$name] = [
				'lenses'       => $schema->getLenses(),
				'list'         => $schema->getListPresentation(),
				'geo'          => $schema->getGeoInheritance(),
				'objectSource' => $schema->getObjectSource(),
				'notSupplied'  => $schema->notSuppliedReasons(),
			];
		}

		foreach ($this->facetProperties() as $name => [$properties, $version]) {
			$actual['facets '.$name] = $this->regeneratedFacets($properties, $version);
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

	private function regeneratedFacets(array $properties, ?string $version): ?array {
		$schema = new Schema();
		$schema->setProperties($properties);
		$schema->setVersion($version);
		$before = time();
		$schema->regenerateFacetsFromProperties();
		$facets = $schema->getFacets();
		if ($facets !== null) {
			$this->assertGreaterThanOrEqual($before, $facets['generated_at']);
			$this->assertLessThanOrEqual(time(), $facets['generated_at']);
			unset($facets['generated_at']);
		}

		return $facets;
	}

	private function facetProperties(): array {
		$all = [
			'plain'    => ['type' => 'string', 'facetable' => true],
			'titled'   => ['type' => 'string', 'facetable' => true, 'title' => 'Titled', 'description' => 'D'],
			'enum'     => ['type' => 'string', 'facetable' => true, 'enum' => ['a', 'b']],
			'date'     => ['type' => 'string', 'format' => 'date', 'facetable' => true],
			'datetime' => ['type' => 'string', 'format' => 'date-time', 'facetable' => true],
			'number'   => ['type' => 'number', 'facetable' => true],
			'integer'  => ['type' => 'integer', 'facetable' => true, 'enum' => [1, 2]],
			'boolean'  => ['type' => 'boolean', 'facetable' => true],
			'array'    => ['type' => 'array', 'facetable' => true, 'enum' => ['x']],
			'object'   => ['type' => 'object', 'facetable' => true],
			'untyped'  => ['facetable' => true],
			'text flag' => ['type' => 'string', 'facetable' => 'true'],
			'off'      => ['type' => 'string', 'facetable' => false],
			'unflagged' => ['type' => 'string'],
		];

		return [
			'none'       => [[], '2.0'],
			'all'        => [$all, '3.1'],
			'no version' => [['p' => ['type' => 'string', 'facetable' => true]], null],
			'none set'   => [['p' => ['type' => 'string']], '1.0'],
		];
	}

	private function configurations(): array {
		return [
			'absent'              => null,
			'json text'           => '{"x-openregister-lenses":{"a":{"through":"t","property":"p"}}}',
			'empty'               => [],
			'not arrays'          => [
				'x-openregister-lenses'               => 'x',
				'x-openregister-list'                 => 'x',
				'x-openregister-geo-inheritance'      => 'x',
				'x-openregister-object-source'        => 'x',
				'x-openregister-not-supplied-reasons' => 'x',
			],
			'mixed'               => [
				'x-openregister-lenses'               => [
					'full'     => ['through' => 'zaak', 'property' => 'status', 'label' => 'Status'],
					'nolabel'  => ['through' => 'zaak', 'property' => 'a.b', 'label' => 5],
					'half'     => ['through' => 'zaak'],
					'empty'    => ['through' => '', 'property' => 'x'],
					'text'     => 'zaak.status',
					''         => ['through' => 't', 'property' => 'p'],
					7          => ['through' => 't', 'property' => 'p'],
				],
				'x-openregister-list'                 => [
					'columns'      => [
						'title',
						'',
						['property' => 'status', 'label' => 'Status', 'width' => 3],
						['label' => 'x'],
						['property' => ''],
						5,
						['property' => 'n', 'label' => 1],
					],
					'searchFields' => ['title', '', 'title', 3, 'summary'],
				],
				'x-openregister-geo-inheritance'      => [
					'from' => [
						'address',
						'',
						['through' => 'parcel', 'label' => 'Parcel'],
						['through' => ''],
						['label' => 'x'],
						4,
						['through' => 'p', 'label' => []],
					],
				],
				'x-openregister-object-source'        => ['provider' => 'ldap', 'readOnly' => true],
				'x-openregister-not-supplied-reasons' => [
					'unknown' => 'Not known',
					''        => 'blank',
					'empty'   => '',
					3         => 'number',
					'bad'     => 4,
					'refused' => 'Refused',
				],
			],
			'list without keys'   => ['x-openregister-list' => []],
			'geo without from'    => ['x-openregister-geo-inheritance' => ['from' => 'address']],
			'source no provider'  => ['x-openregister-object-source' => ['readOnly' => true]],
			'source empty'        => ['x-openregister-object-source' => ['provider' => '']],
			'view by uuid'        => ['x-openregister-view' => ['view' => 'abc-123']],
			'view by id'          => ['x-openregister-view' => ['view' => 12]],
			'view empty'          => ['x-openregister-view' => ['view' => '']],
			'view float'          => ['x-openregister-view' => ['view' => 1.5]],
			'view not a map'      => ['x-openregister-view' => 'abc'],
			'source wins on view' => ['x-openregister-object-source' => ['provider' => 'dbal'], 'x-openregister-view' => ['view' => 3]],
			'bad source and view' => ['x-openregister-object-source' => ['provider' => 3], 'x-openregister-view' => ['view' => 3]],
		];
	}
}
