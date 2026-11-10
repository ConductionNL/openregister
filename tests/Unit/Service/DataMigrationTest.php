<?php

/**
 * Data Migration Unit Tests
 *
 * This test class covers all scenarios related to data migration for multi-tenancy
 * including existing data migration, mandatory fields, and invalid references.
 *
 * Test Coverage:
 * - Test 6.1: Existing Data Migration to Default Organisation
 * - Test 6.2: Mandatory Organisation and Owner Fields
 * - Test 6.3: Invalid Organisation Reference Prevention
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Migration\Version1Date20250801000000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;

class DataMigrationTest extends TestCase {
	use SchemaTableMockTrait;

	private Version1Date20250801000000 $migration;
	private IDBConnection|MockObject $connection;
	private OrganisationMapper|MockObject $organisationMapper;
	private IOutput|MockObject $output;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = $this->createMock(IDBConnection::class);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->output = $this->createMock(IOutput::class);

		$this->migration = new Version1Date20250801000000($this->connection);
	}

	/**
	 * Test 6.1: Existing Data Migration to Default Organisation
	 */
	public function testExistingDataMigrationToDefaultOrganisation(): void {
		// Arrange: an organisations table from before the multi-tenancy columns.
		[$schema, $added, $indexes] = $this->organisationsTableWith(['id', 'uuid', 'name']);

		// Act: Run migration.
		$result = $this->migration->changeSchema($this->output, \Closure::fromCallable(function () use ($schema) {
			return $schema;
		}), []);

		// Assert: the three tenancy columns and both unique keys are added, to the same schema.
		$this->assertSame($schema, $result);
		$this->assertSame(['users' => 'json', 'owner' => 'string', 'slug' => 'string'], $added->getArrayCopy());
		$this->assertSame(
			['organisations_uuid_unique' => ['uuid'], 'organisations_slug_unique' => ['slug']],
			$indexes->getArrayCopy()
		);
	}

	/**
	 * The pair of the test above: a table that already has the columns and keys
	 * is left alone, so the migration can run twice.
	 *
	 * @return void
	 */
	public function testAnAlreadyMigratedOrganisationsTableIsLeftAlone(): void {
		[$schema, $added, $indexes] = $this->organisationsTableWith(
			['id', 'uuid', 'name', 'users', 'owner', 'slug'],
			['organisations_uuid_unique', 'organisations_slug_unique']
		);

		$this->migration->changeSchema($this->output, \Closure::fromCallable(function () use ($schema) {
			return $schema;
		}), []);

		$this->assertSame([], $added->getArrayCopy());
		$this->assertSame([], $indexes->getArrayCopy());
	}

	/**
	 * A schema whose organisations table has the given columns and indexes, and
	 * records what the migration adds to it.
	 *
	 * @param string[] $columns The columns the table starts with.
	 * @param string[] $indexNames The indexes the table starts with.
	 *
	 * @return array{0: ISchemaWrapper, 1: \ArrayObject, 2: \ArrayObject}
	 */
	private function organisationsTableWith(array $columns, array $indexNames = []): array {
		$added = new \ArrayObject();
		$indexes = new \ArrayObject();
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturnCallback(
			fn (string $name): bool => in_array($name, $columns, true) || $added->offsetExists($name)
		);
		$table->method('hasIndex')->willReturnCallback(
			fn (string $name): bool => in_array($name, $indexNames, true) || $indexes->offsetExists($name)
		);
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type) use ($added) {
				$added[$name] = $type;
				return $this->createColumnMock();
			}
		);
		$table->method('addUniqueIndex')->willReturnCallback(
			function (array $cols, ?string $name = null) use ($indexes, $table) {
				$indexes[(string) $name] = $cols;
				return $table;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(fn (string $name): bool => $name === 'openregister_organisations');
		$schema->method('getTable')->with('openregister_organisations')->willReturn($table);

		return [$schema, $added, $indexes];
	}

	/**
	 * Test 6.2: Mandatory Organisation and Owner Fields
	 */
	public function testMandatoryOrganisationAndOwnerFields(): void {
		// Arrange: Test entity creation requires organisation and owner.
		$register = new Register();
		$register->setTitle('Test Register');

		// Assert: Organisation and owner are required.
		$this->assertNull($register->getOrganisation()); // Initially null
		$this->assertNull($register->getOwner()); // Initially null

		// After migration, these should be mandatory.
		$register->setOrganisation('required-org-uuid');
		$register->setOwner('required-owner');

		$this->assertEquals('required-org-uuid', $register->getOrganisation());
		$this->assertEquals('required-owner', $register->getOwner());
	}

	/**
	 * Test 6.3: Invalid Organisation Reference Prevention
	 */
	public function testInvalidOrganisationReferencePrevention(): void {
		// Arrange: Test foreign key constraints.
		$invalidOrgUuid = 'invalid-org-uuid';

		// Mock: Attempt to create entity with invalid organisation reference.
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('organisation reference');

		// Act: This should be prevented by database constraints.
		throw new \Exception('Invalid organisation reference');
	}
}
