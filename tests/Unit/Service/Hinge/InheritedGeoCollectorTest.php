<?php

/**
 * Unit tests for InheritedGeoCollector.
 *
 * Covers a case showing the address it is about, the provenance every inherited
 * feature carries, a corrected location winning over the registry it came from,
 * and a schema declaring no inheritance behaving as today.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Hinge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Hinge\InheritedGeoCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InheritedGeoCollectorTest extends TestCase {

	private const POINT_ADDRESS = ['type' => 'Point', 'coordinates' => [5.12, 52.09]];
	private const POINT_CORRECTED = ['type' => 'Point', 'coordinates' => [5.13, 52.10]];

	private function schema(?array $configuration = null): Schema {
		$schema = new Schema();
		$schema->setProperties(['adres' => ['type' => 'string'], 'aanvrager' => ['type' => 'string']]);
		if ($configuration !== null) {
			$schema->setConfiguration($configuration);
		}

		return $schema;
	}

	private function object(string $uuid, array $data = [], ?array $geo = null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);
		$object->setGeo($geo);
		return $object;
	}

	private function collector(array $referencedByUuid): InheritedGeoCollector {
		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('find')->willReturnCallback(
			static function (string|int $identifier) use ($referencedByUuid): ObjectEntity {
				if (isset($referencedByUuid[(string)$identifier]) === false) {
					throw new \RuntimeException('not found or not readable');
				}

				return $referencedByUuid[(string)$identifier];
			}
		);

		return new InheritedGeoCollector(magicMapper: $magicMapper, logger: new NullLogger());
	}

	public function testTheCaseShowsTheAddressItIsAbout(): void {
		$address = $this->object('uuid-adres', [], self::POINT_ADDRESS);
		$case = $this->object('uuid-zaak', ['adres' => 'uuid-adres']);

		$collected = $this->collector(['uuid-adres' => $address])->collect(
			$case,
			$this->schema([Schema::GEO_INHERITANCE_ANNOTATION => ['from' => ['adres']]])
		);

		$this->assertSame('FeatureCollection', $collected['type']);
		$this->assertCount(1, $collected['features']);

		$feature = $collected['features'][0];
		$this->assertSame(self::POINT_ADDRESS, $feature['geometry']);
		$this->assertSame('inherited', $feature['properties']['_source']);
		$this->assertSame('adres', $feature['properties']['_through']);
		$this->assertSame('uuid-adres', $feature['properties']['_fromObject']);
		$this->assertFalse($feature['properties']['_superseded']);
	}

	public function testACorrectedLocationWins(): void {
		$address = $this->object('uuid-adres', [], self::POINT_ADDRESS);
		$case = $this->object('uuid-zaak', ['adres' => 'uuid-adres'], self::POINT_CORRECTED);

		$collected = $this->collector(['uuid-adres' => $address])->collect(
			$case,
			$this->schema([Schema::GEO_INHERITANCE_ANNOTATION => ['from' => ['adres']]])
		);

		$this->assertCount(2, $collected['features']);

		$own = $collected['features'][0];
		$inherited = $collected['features'][1];

		$this->assertSame('own', $own['properties']['_source']);
		$this->assertSame(self::POINT_CORRECTED, $own['geometry']);
		$this->assertFalse($own['properties']['_superseded']);

		$this->assertSame('inherited', $inherited['properties']['_source']);
		$this->assertTrue($inherited['properties']['_superseded']);
	}

	public function testAFeatureForAnotherPurposeIsNotSuperseded(): void {
		$area = [
			'type' => 'Feature',
			'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [0, 1], [1, 1], [0, 0]]]],
			'properties' => ['purpose' => 'werkgebied'],
		];
		$address = $this->object('uuid-adres', [], $area);
		$case = $this->object('uuid-zaak', ['adres' => 'uuid-adres'], self::POINT_CORRECTED);

		$collected = $this->collector(['uuid-adres' => $address])->collect(
			$case,
			$this->schema([Schema::GEO_INHERITANCE_ANNOTATION => ['from' => ['adres']]])
		);

		$this->assertFalse($collected['features'][1]['properties']['_superseded']);
		$this->assertSame('werkgebied', $collected['features'][1]['properties']['_purpose']);
	}

	public function testASchemaDeclaringNoInheritanceBehavesAsToday(): void {
		$address = $this->object('uuid-adres', [], self::POINT_ADDRESS);
		$case = $this->object('uuid-zaak', ['adres' => 'uuid-adres'], self::POINT_CORRECTED);

		$collected = $this->collector(['uuid-adres' => $address])->collect($case, $this->schema());

		$this->assertCount(1, $collected['features']);
		$this->assertSame('own', $collected['features'][0]['properties']['_source']);
	}

	public function testAReferenceTheCallerCannotReadContributesNothing(): void {
		$case = $this->object('uuid-zaak', ['adres' => 'uuid-adres']);

		$collected = $this->collector([])->collect(
			$case,
			$this->schema([Schema::GEO_INHERITANCE_ANNOTATION => ['from' => ['adres']]])
		);

		$this->assertSame([], $collected['features']);
	}

	public function testSeveralReferencesEachNameTheirOwnRelation(): void {
		$address = $this->object('uuid-adres', [], self::POINT_ADDRESS);
		$party = $this->object('uuid-partij', [], ['type' => 'Point', 'coordinates' => [4.9, 52.37]]);
		$case = $this->object('uuid-zaak', ['adres' => 'uuid-adres', 'aanvrager' => ['id' => 'uuid-partij']]);

		$collected = $this->collector(['uuid-adres' => $address, 'uuid-partij' => $party])->collect(
			$case,
			$this->schema([
				Schema::GEO_INHERITANCE_ANNOTATION => [
					'from' => ['adres', ['through' => 'aanvrager', 'label' => 'Aanvrager']],
				],
			])
		);

		$relations = array_column(array_column($collected['features'], 'properties'), '_through');
		$this->assertSame(['adres', 'aanvrager'], $relations);
		$this->assertSame('Aanvrager', $collected['features'][1]['properties']['_relationLabel']);
	}

	public function testAFeatureCollectionOnTheReferencedRecordIsFlattened(): void {
		$address = $this->object('uuid-adres', [], [
			'type' => 'FeatureCollection',
			'features' => [
				['type' => 'Feature', 'geometry' => self::POINT_ADDRESS, 'properties' => []],
				['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [1, 1]], 'properties' => ['purpose' => 'ingang']],
			],
		]);
		$case = $this->object('uuid-zaak', ['adres' => 'uuid-adres']);

		$collected = $this->collector(['uuid-adres' => $address])->collect(
			$case,
			$this->schema([Schema::GEO_INHERITANCE_ANNOTATION => ['from' => ['adres']]])
		);

		$this->assertCount(2, $collected['features']);
		$this->assertSame(['Point', 'ingang'], array_column(array_column($collected['features'], 'properties'), '_purpose'));
	}
}
