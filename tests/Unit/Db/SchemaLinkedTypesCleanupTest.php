<?php

/**
 * SchemaLinkedTypesCleanupTest
 *
 * Acceptance tests confirming that Schema::validateLinkedTypesValue()
 * uses only the IntegrationRegistry (VALID_LINKED_TYPES constant removed).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-3
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests confirming Schema::VALID_LINKED_TYPES has been removed and
 * validateLinkedTypesValue() works through the registry path.
 *
 * @coversDefaultClass \OCA\OpenRegister\Db\Schema
 */
class SchemaLinkedTypesCleanupTest extends TestCase {

	private Schema $schema;

	protected function setUp(): void {
		$this->schema = new Schema();
	}//end setUp()

	/**
	 * VALID_LINKED_TYPES constant must no longer exist on Schema.
	 *
	 * @covers \OCA\OpenRegister\Db\Schema
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-3
	 */
	public function testValidLinkedTypesConstantIsRemoved(): void {
		$rc = new \ReflectionClass(Schema::class);
		$this->assertFalse(
			$rc->hasConstant('VALID_LINKED_TYPES'),
			'VALID_LINKED_TYPES constant must be removed from Schema'
		);
	}//end testValidLinkedTypesConstantIsRemoved()

	/**
	 * When registry is unavailable (no \OC::$server), setConfiguration
	 * with any linkedTypes value succeeds (validation skipped).
	 *
	 * This preserves backward compatibility for unit tests that build
	 * Schema instances outside a booted NC container.
	 *
	 * @covers \OCA\OpenRegister\Db\Schema::setConfiguration
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-3
	 */
	public function testLinkedTypesAcceptedWhenRegistryUnavailable(): void {
		// No \OC::$server in unit-test context → resolveIntegrationRegistryIds() returns []
		// → validation is skipped → any string value is accepted.
		$this->schema->setConfiguration(['linkedTypes' => ['files', 'notes', 'mail']]);
		$config = $this->schema->getConfiguration();

		$this->assertIsArray($config);
		$this->assertSame(['files', 'notes', 'mail'], $config['linkedTypes']);
	}//end testLinkedTypesAcceptedWhenRegistryUnavailable()

	/**
	 * setConfiguration with null linkedTypes succeeds (no validation needed).
	 *
	 * @covers \OCA\OpenRegister\Db\Schema::setConfiguration
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-3
	 */
	public function testLinkedTypesNullIsAccepted(): void {
		$this->schema->setConfiguration(['linkedTypes' => null]);
		$config = $this->schema->getConfiguration();
		// null linkedTypes is stored as null in the config array.
		$this->assertIsArray($config);
		$this->assertNull($config['linkedTypes']);
	}//end testLinkedTypesNullIsAccepted()

	/**
	 * validateLinkedTypesValue() (invoked directly — see NOTE) throws when
	 * linkedTypes is not an array.
	 *
	 * NOTE: as of the #419 per-key configuration isolation fix,
	 * `setConfiguration()` no longer lets this exception escape — it catches
	 * it, drops just the `linkedTypes` key, and keeps the rest of the
	 * configuration (see `testLinkedTypesNotArrayDroppedNotThrown()`). This
	 * test exercises the validator directly via reflection so the rejection
	 * itself stays covered.
	 *
	 * @covers \OCA\OpenRegister\Db\Schema::validateLinkedTypesValue
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-3
	 */
	public function testLinkedTypesThrowsWhenNotArray(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/linkedTypes.*must be an array/');

		$method = new \ReflectionMethod(Schema::class, 'validateLinkedTypesValue');
		$method->setAccessible(true);
		$method->invoke($this->schema, 'not-an-array');
	}//end testLinkedTypesThrowsWhenNotArray()

	/**
	 * setConfiguration() drops (rather than throws for) a non-array
	 * linkedTypes value — public-surface counterpart to
	 * {@see testLinkedTypesThrowsWhenNotArray()}.
	 *
	 * @covers \OCA\OpenRegister\Db\Schema::setConfiguration
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-3
	 */
	public function testLinkedTypesNotArrayDroppedNotThrown(): void {
		$this->schema->setConfiguration(['linkedTypes' => 'not-an-array', 'objectNameField' => 'name']);
		$config = $this->schema->getConfiguration();

		$this->assertIsArray($config);
		$this->assertArrayNotHasKey('linkedTypes', $config);
		$this->assertSame('name', $config['objectNameField']);
		$this->assertContains('linkedTypes', $this->schema->consumeDroppedAnnotationKeys());
	}//end testLinkedTypesNotArrayDroppedNotThrown()

	/**
	 * validateLinkedTypesValue() (invoked directly — see NOTE) throws when
	 * linkedTypes contains a non-string value.
	 *
	 * NOTE: as of the #419 per-key configuration isolation fix,
	 * `setConfiguration()` no longer lets this exception escape — see
	 * {@see testLinkedTypesThrowsWhenNotArray()} for the rationale.
	 *
	 * @covers \OCA\OpenRegister\Db\Schema::validateLinkedTypesValue
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-3
	 */
	public function testLinkedTypesThrowsWhenContainsNonString(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/linkedTypes.*must be strings/');

		$method = new \ReflectionMethod(Schema::class, 'validateLinkedTypesValue');
		$method->setAccessible(true);
		$method->invoke($this->schema, ['files', 42]);
	}//end testLinkedTypesThrowsWhenContainsNonString()

	/**
	 * getLinkedTypes returns the configuration linkedTypes array.
	 *
	 * @covers \OCA\OpenRegister\Db\Schema::getLinkedTypes
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-3
	 */
	public function testGetLinkedTypesReturnsConfigurationArray(): void {
		$this->schema->setConfiguration(['linkedTypes' => ['files', 'notes']]);
		$this->assertSame(['files', 'notes'], $this->schema->getLinkedTypes());
	}//end testGetLinkedTypesReturnsConfigurationArray()

	/**
	 * getLinkedTypes returns empty array when no configuration is set.
	 *
	 * @covers \OCA\OpenRegister\Db\Schema::getLinkedTypes
	 *
	 * @spec openspec/changes/cleanup-linked-entity-type-map/tasks.md#task-3
	 */
	public function testGetLinkedTypesReturnsEmptyArrayWhenNotConfigured(): void {
		$this->assertSame([], $this->schema->getLinkedTypes());
	}//end testGetLinkedTypesReturnsEmptyArrayWhenNotConfigured()
}//end class
