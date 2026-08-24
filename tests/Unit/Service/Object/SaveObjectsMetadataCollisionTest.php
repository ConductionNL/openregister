<?php

declare(strict_types=1);

/**
 * Bulk save: a schema property must not be eaten by the magic-table column of
 * the same name.
 *
 * openregister#2781. `extractBusinessData()` stripped `name`, `description`,
 * `summary`, `image` and `slug` from every legacy-structure payload before
 * validation, on the assumption that those keys are always metadata. They are
 * also five of the most ordinary property names a schema can declare, and when
 * one was declared its incoming value was silently dropped -- then, if the
 * property was `required`, validation refused the WHOLE batch for "the required
 * property (name) is missing", over a field the caller had supplied.
 *
 * The single-object endpoint never stripped them, so the same payload behaved
 * differently depending on which endpoint received it. That asymmetry is what
 * these tests pin.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for the metadata/business-data split in bulk save.
 */
class SaveObjectsMetadataCollisionTest extends TestCase {
	/** @var SaveObjects */
	private SaveObjects $service;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new SaveObjects(
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SaveObject::class),
			$this->createMock(IUserSession::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Invoke the private splitter.
	 *
	 * @param array       $object The payload.
	 * @param Schema|null $schema The schema, or null for the old behaviour.
	 *
	 * @return array The extracted business data.
	 */
	private function extract(array $object, mixed $schema = null): array {
		$method = new ReflectionMethod(SaveObjects::class, 'extractBusinessData');
		$method->setAccessible(true);
		return $method->invoke($this->service, $object, $schema);
	}

	/**
	 * A schema declaring the given property names.
	 *
	 * @param string[] $names Property names to declare.
	 *
	 * @return Schema&MockObject
	 */
	private function schemaDeclaring(array $names): Schema {
		$schema = $this->createMock(Schema::class);
		$properties = [];
		foreach ($names as $name) {
			$properties[$name] = ['type' => 'string'];
		}
		$schema->method('getProperties')->willReturn($properties);
		return $schema;
	}

	/**
	 * The regression: a declared `name` survives instead of being stripped.
	 *
	 * @return void
	 */
	public function testADeclaredNamePropertySurvives(): void {
		$business = $this->extract(
			[
				'uuid' => 'u-1',
				'name' => 'Ada Lovelace',
				'age' => 36,
			],
			$this->schemaDeclaring(['name', 'age'])
		);

		$this->assertSame('Ada Lovelace', $business['name'] ?? null);
		$this->assertSame(36, $business['age'] ?? null);
		// Still not business data: it locates the object, it does not describe it.
		$this->assertArrayNotHasKey('uuid', $business);
	}

	/**
	 * Every overlapping column name is honoured, not just `name`.
	 *
	 * @return void
	 */
	public function testAllFiveOverlappingNamesSurviveWhenDeclared(): void {
		$payload = [
			'name' => 'n',
			'description' => 'd',
			'summary' => 's',
			'image' => 'i',
			'slug' => 'sl',
		];

		$business = $this->extract(
			$payload + ['uuid' => 'u-2'],
			$this->schemaDeclaring(array_keys($payload))
		);

		foreach ($payload as $key => $value) {
			$this->assertSame($value, $business[$key] ?? null, "property '$key' was dropped");
		}
	}

	/**
	 * An UNDECLARED overlapping name is still metadata and still stripped.
	 *
	 * This is the half of the old behaviour that was correct, and it has to stay
	 * -- otherwise a plain `{"name": ...}` payload would start writing a stray
	 * business field on every schema that does not declare one.
	 *
	 * @return void
	 */
	public function testAnUndeclaredOverlappingNameIsStillStripped(): void {
		$business = $this->extract(
			['name' => 'display only', 'age' => 1],
			$this->schemaDeclaring(['age'])
		);

		$this->assertArrayNotHasKey('name', $business);
		$this->assertSame(1, $business['age'] ?? null);
	}

	/**
	 * A schema declaring only some of them splits them correctly.
	 *
	 * @return void
	 */
	public function testAPartiallyDeclaredSchemaSplitsPerProperty(): void {
		$business = $this->extract(
			[
				'name' => 'kept',
				'description' => 'dropped',
				'slug' => 'kept-too',
			],
			$this->schemaDeclaring(['name', 'slug'])
		);

		$this->assertSame('kept', $business['name'] ?? null);
		$this->assertSame('kept-too', $business['slug'] ?? null);
		$this->assertArrayNotHasKey('description', $business);
	}

	/**
	 * Structural envelope keys are stripped even if a schema declares them.
	 *
	 * These identify or locate the object rather than describe it, so honouring
	 * a same-named property would collide with the storage envelope itself.
	 *
	 * @return void
	 */
	public function testStructuralFieldsAreStrippedEvenWhenDeclared(): void {
		$structural = [
			'@self' => ['x' => 1],
			'register' => 1,
			'schema' => 2,
			'organisation' => 'org',
			'uuid' => 'u-3',
			'owner' => 'admin',
			'created' => '2026-01-01T00:00:00+00:00',
			'updated' => '2026-01-02T00:00:00+00:00',
			'id' => 99,
		];

		$business = $this->extract(
			$structural + ['real' => 'value'],
			$this->schemaDeclaring(array_keys($structural))
		);

		foreach (array_keys($structural) as $key) {
			$this->assertArrayNotHasKey($key, $business, "structural '$key' leaked into business data");
		}
		$this->assertSame('value', $business['real'] ?? null);
	}

	/**
	 * With no schema the previous behaviour is unchanged.
	 *
	 * Callers that cannot resolve a schema must not start behaving differently;
	 * the fix adds information, it does not change the default.
	 *
	 * @return void
	 */
	public function testWithoutASchemaTheOldStrippingStands(): void {
		$business = $this->extract(['name' => 'x', 'age' => 2]);

		$this->assertArrayNotHasKey('name', $business);
		$this->assertSame(2, $business['age'] ?? null);
	}

	/**
	 * The new-structure payload is passed through untouched.
	 *
	 * When business data arrives under `object`, there is no ambiguity to
	 * resolve and nothing should be stripped from it.
	 *
	 * @return void
	 */
	public function testTheObjectPropertyStructureIsReturnedAsIs(): void {
		$business = $this->extract(
			[
				'@self' => ['uuid' => 'u-4'],
				'object' => ['name' => 'inner', 'uuid' => 'kept-inside'],
			],
			$this->schemaDeclaring(['name'])
		);

		$this->assertSame(['name' => 'inner', 'uuid' => 'kept-inside'], $business);
	}

	/**
	 * A schema-cache MISS is treated as "no declaration information".
	 *
	 * The mixed-schema call site passes `$schemaCache[$id] ?? null` straight
	 * through rather than guarding with `instanceof` itself, so this method has
	 * to judge it. A miss must fall back to the old stripping, never fatal on a
	 * method call against null.
	 *
	 * @return void
	 */
	public function testACacheMissIsTreatedAsNoSchema(): void {
		$business = $this->extract(['name' => 'x', 'age' => 4], null);

		$this->assertArrayNotHasKey('name', $business);
		$this->assertSame(4, $business['age'] ?? null);
	}

	/**
	 * A stale or unexpected cache value is refused, not called.
	 *
	 * `$schemaCache` is keyed by whatever `$selfData['schema']` holds, so an
	 * unexpected entry is reachable. Anything that is not a Schema must degrade
	 * to the old behaviour rather than reach getProperties() on it.
	 *
	 * @return void
	 */
	public function testANonSchemaCacheValueDoesNotReachGetProperties(): void {
		foreach ([['not' => 'a schema'], 'planix', 42, new \stdClass()] as $value) {
			$business = $this->extract(['name' => 'x', 'age' => 5], $value);
			$this->assertArrayNotHasKey('name', $business);
			$this->assertSame(5, $business['age'] ?? null);
		}
	}

	/**
	 * A schema with no properties at all does not crash the splitter.
	 *
	 * @return void
	 */
	public function testASchemaWithNoPropertiesFallsBackToStripping(): void {
		$business = $this->extract(['name' => 'x', 'age' => 3], $this->schemaDeclaring([]));

		$this->assertArrayNotHasKey('name', $business);
		$this->assertSame(3, $business['age'] ?? null);
	}
}
