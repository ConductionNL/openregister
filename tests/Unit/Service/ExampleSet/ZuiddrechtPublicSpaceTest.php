<?php

/**
 * The Zuiddrecht example set fills a public-space register (openbare ruimte)
 * that cases link objects in: trees, street lights, play equipment, market
 * pitches and road sections. The register and its schemas come from
 * OpenRegister's mock descriptor, the objects from the cast file.
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
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped descriptor and cast file from disk.
 */
class ZuiddrechtPublicSpaceTest extends TestCase {

	private const CAST_FILE = __DIR__.'/../../../../lib/Settings/example-sets/zuiddrecht.json';

	private const DESCRIPTOR_FILE = __DIR__.'/../../../../lib/Settings/public_space_register.json';

	/**
	 * Decode one shipped JSON file.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string, mixed>
	 */
	private static function read(string $path): array {
		return (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}//end read()

	/**
	 * The descriptor is a mock, so no instance imports it unless a set asks.
	 *
	 * @return void
	 */
	public function testTheRegisterIsAMockTheSetRequires(): void {
		$descriptor = self::read(path: self::DESCRIPTOR_FILE);
		$set        = self::read(path: self::CAST_FILE);

		$this->assertSame(expected: 'mock', actual: $descriptor['x-openregister']['type']);
		$this->assertSame(expected: 'Openbare ruimte', actual: $descriptor['components']['registers']['public-space']['title']);
		$this->assertContains(needle: ['register' => 'public-space', 'mock' => true], haystack: $set['requires']);
	}//end testTheRegisterIsAMockTheSetRequires()

	/**
	 * Each schema's title, property count and whether it has a location,
	 * which is what dossiq's object type catalogue shows per type.
	 *
	 * @return void
	 */
	public function testTheSchemasCarryTheCatalogueColumns(): void {
		$schemas = self::read(path: self::DESCRIPTOR_FILE)['components']['schemas'];

		$shape = [];
		foreach ($schemas as $slug => $schema) {
			$geo = array_filter($schema['properties'], static fn (array $property): bool => $property['type'] === 'geo');
			$shape[$slug] = [$schema['title'], count($schema['properties']), $geo !== []];
		}

		$this->assertSame(
			expected: [
				'tree'           => ['Bomenregister', 9, true],
				'street-light'   => ['Openbare verlichting', 7, true],
				'play-equipment' => ['Buitenruimte', 11, true],
				'market-pitch'   => ['Markt', 6, false],
				'road-section'   => ['Wegen en paden', 6, true],
			],
			actual: $shape
		);
		$this->assertSame(
			expected: array_keys($schemas),
			actual: self::read(path: self::DESCRIPTOR_FILE)['components']['registers']['public-space']['schemas']
		);
	}//end testTheSchemasCarryTheCatalogueColumns()

	/**
	 * Every object is in a declared schema, fills its required fields, has a
	 * status the schema allows and an identification no other object uses.
	 *
	 * @return void
	 */
	public function testEveryObjectFitsItsSchema(): void {
		$schemas = self::read(path: self::DESCRIPTOR_FILE)['components']['schemas'];
		$objects = array_values(
			array_filter(
				self::read(path: self::CAST_FILE)['objects'],
				static fn (array $object): bool => $object['register'] === 'public-space'
			)
		);

		$this->assertCount(expectedCount: 13, haystack: $objects);
		foreach ($objects as $object) {
			$schema = ($schemas[$object['schema']] ?? null);
			$this->assertNotNull(actual: $schema, message: $object['key']);
			$this->assertSame(expected: [], actual: array_diff($schema['required'], array_keys($object['data'])), message: $object['key']);
			$this->assertSame(expected: [], actual: array_diff(array_keys($object['data']), array_keys($schema['properties'])), message: $object['key']);
			$this->assertContains(needle: $object['data']['status'], haystack: $schema['properties']['status']['enum'], message: $object['key']);
		}

		$identifications = array_map(static fn (array $object): string => $object['data']['identification'], $objects);
		$this->assertSame(expected: $identifications, actual: array_values(array_unique($identifications)));
	}//end testEveryObjectFitsItsSchema()

	/**
	 * The three objects the Lindelaan Woo case links: the cycle path in
	 * management and two masts still to be placed.
	 *
	 * @return void
	 */
	public function testTheLindelaanObjectsCarryTheirStatus(): void {
		$byIdentification = [];
		foreach (self::read(path: self::CAST_FILE)['objects'] as $object) {
			if ($object['register'] === 'public-space') {
				$byIdentification[$object['data']['identification']] = [$object['data']['name'], $object['data']['status']];
			}
		}

		$this->assertSame(
			expected: [['Fietspad Lindelaan', 'In beheer'], ['Lichtmast 14', 'Gepland'], ['Lichtmast 15', 'Gepland']],
			actual: [$byIdentification['BGT wgl.0505.1234'], $byIdentification['LM-0014'], $byIdentification['LM-0015']]
		);
	}//end testTheLindelaanObjectsCarryTheirStatus()
}//end class
