<?php

/**
 * A geometry is validated on save, and the refusal names the property.
 *
 * The object-level check runs through the REAL ObjectService write-path
 * guard (enforceDeclaredShapes, which runs whether or not the schema has
 * hard validation), over a real Schema entity holding the property fragment.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Geo
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Geo;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Geo\GeoJsonGeometryValidator;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class GeometryValidatedOnSaveTest extends TestCase {

	/**
	 * A schema with a polygon `area` (declared by format) and a point `locatie` (declared by type).
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->hydrate(
			[
				'slug' => 'wijk',
				'title' => 'Wijk',
				'properties' => [
					'naam' => ['type' => 'string'],
					'area' => ['type' => 'object', 'format' => 'geo:polygon'],
					'locatie' => ['type' => 'geo:point'],
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * A closed square.
	 *
	 * @return array<string, mixed>
	 */
	private function square(): array {
		return ['type' => 'Polygon', 'coordinates' => [[[5.1, 52.0], [5.2, 52.0], [5.2, 52.1], [5.1, 52.1], [5.1, 52.0]]]];
	}//end square()

	/**
	 * Run the write-path guard of a real ObjectService on one object.
	 *
	 * @param array<string, mixed> $object The object as it would be saved.
	 *
	 * @return void
	 */
	private function guard(array $object): void {
		// The service's constructor wires forty collaborators; the guard reads
		// only the current schema and the logger.
		$service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(ObjectService::class, 'currentSchema'))->setValue($service, $this->schema());
		(new ReflectionProperty(ObjectService::class, 'logger'))->setValue($service, $this->createMock(LoggerInterface::class));

		(new ReflectionMethod(ObjectService::class, 'enforceDeclaredShapes'))->invoke($service, $object);
	}//end guard()

	public function testAnUnclosedRingIsRefusedNamingTheProperty(): void {
		$open = ['type' => 'Polygon', 'coordinates' => [[[5.1, 52.0], [5.2, 52.0], [5.2, 52.1], [5.1, 52.1]]]];

		try {
			$this->guard(['naam' => 'Centrum', 'area' => $open]);
			$this->fail('an unclosed ring must be refused');
		} catch (ValidationException $e) {
			$this->assertStringContainsString("'area'", $e->getMessage());
		}
	}//end testAnUnclosedRingIsRefusedNamingTheProperty()

	public function testAValidGeometrySaves(): void {
		$this->guard(['naam' => 'Centrum', 'area' => $this->square(), 'locatie' => ['type' => 'Point', 'coordinates' => [5.12, 52.09]]]);
		$this->addToAssertionCount(1);
	}//end testAValidGeometrySaves()

	public function testAPropertyDeclaredByTypeIsCheckedToo(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage("'locatie'");
		$this->guard(['locatie' => ['type' => 'Point', 'coordinates' => [200, 52.09]]]);
	}//end testAPropertyDeclaredByTypeIsCheckedToo()

	public function testAnAbsentOrNullGeometryIsLeftToTheRequiredRule(): void {
		$this->guard(['naam' => 'Centrum', 'area' => null]);
		$this->guard(['naam' => 'Centrum']);
		$this->addToAssertionCount(1);
	}//end testAnAbsentOrNullGeometryIsLeftToTheRequiredRule()

	public function testTheValidatorNamesEveryInvalidProperty(): void {
		$errors = (new GeoJsonGeometryValidator())->validateObject(
			object: ['area' => ['type' => 'Point', 'coordinates' => [1, 2]], 'locatie' => 'here'],
			schema: $this->schema()
		);

		$this->assertSame(['area', 'locatie'], array_keys($errors));
	}//end testTheValidatorNamesEveryInvalidProperty()
}//end class
