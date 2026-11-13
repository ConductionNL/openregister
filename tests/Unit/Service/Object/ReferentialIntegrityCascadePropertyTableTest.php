<?php

/**
 * Tests for ReferentialIntegrityService::cascadePropertyTable().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Archival\ArchivalSchemaLookup;
use OCA\OpenRegister\Service\Object\IntegrityCascadeControls;
use OCA\OpenRegister\Service\Object\IntegrityRelationIndex;
use OCA\OpenRegister\Service\Object\IntegrityStorage;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The table a `cascade: true` property's references must live in.
 */
class ReferentialIntegrityCascadePropertyTableTest extends TestCase {

	private ReferentialIntegrityService $service;

	private RegisterSchema $owner;

	private Schema $kid;

	protected function setUp(): void {
		parent::setUp();

		$parent = new Schema();
		$parent->setId(10);
		$parent->setSlug('parent');
		$this->kid = new Schema();
		$this->kid->setId(20);
		$this->kid->setSlug('kid');
		$loose = new Schema();
		$loose->setId(30);
		$loose->setSlug('loose');

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$parent, $this->kid, $loose]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([]);

		$register = new Register();
		$register->setId(1);
		$register->setSchemas([10, 20]);
		$this->owner = new RegisterSchema(register: $register, schema: $parent);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new ReferentialIntegrityService(
			index: new IntegrityRelationIndex(
				catalog: new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $this->createMock(MagicMapperInterface::class)),
				cacheFactory: $cacheFactory
			),
			storage: new IntegrityStorage(objectEntityMapper: $this->createMock(MagicMapper::class), db: $this->createMock(IDBConnection::class)),
			controls: new IntegrityCascadeControls(
				auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				archivalGuard: new ArchivalRetentionGuard(new ArchivalSchemaLookup($schemaMapper, $logger))
			),
			logger: $logger
		);
	}//end setUp()

	/**
	 * A property that names no schema refers into the owner's own table.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutARefRefersIntoTheOwnersTable(): void {
		$this->assertSame($this->owner, $this->service->cascadePropertyTable(['cascade' => true], $this->owner));
	}//end testAPropertyWithoutARefRefersIntoTheOwnersTable()

	/**
	 * A `$ref` (or `items.$ref`) to a schema the owner's register holds names that table.
	 *
	 * @return void
	 */
	public function testARefNamesTheSchemaInTheOwnersRegister(): void {
		foreach ([['$ref' => '#/components/schemas/kid'], ['items' => ['$ref' => 'kid']], ['$ref' => '20']] as $property) {
			$table = $this->service->cascadePropertyTable(['cascade' => true] + $property, $this->owner);

			$this->assertNotNull($table);
			$this->assertSame($this->kid, $table->schema);
			$this->assertSame($this->owner->register, $table->register);
		}
	}//end testARefNamesTheSchemaInTheOwnersRegister()

	/**
	 * A `$ref` that does not resolve, or to a schema no register holds, names no table.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRefNamesNoTable(): void {
		$this->assertNull($this->service->cascadePropertyTable(['cascade' => true, '$ref' => 'unknown'], $this->owner));
		$this->assertNull($this->service->cascadePropertyTable(['cascade' => true, '$ref' => 'loose'], $this->owner));
	}//end testAnUnresolvableRefNamesNoTable()
}//end class
