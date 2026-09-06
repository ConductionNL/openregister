<?php

/**
 * SchemaDeletionService archival immutability tests — the fourth delete door.
 *
 * ARCHIVAL IMMUTABILITY. openregister#3428 made `Schema::hasArchivalAnnotation()`
 * the single definition of "this schema holds legally retained records", and three
 * delete doors were made to read it: `DELETE /api/objects/.../{id}`,
 * `DELETE /api/deleted/{uuid}`, and `POST /api/bulk/.../delete`. `SchemaDeletionService`
 * was the fourth and it read nothing — `POST /api/bulk/{r}/{s}/delete-objects` with
 * `hardDelete: true` answered 200 `success: true` and destroyed every row of an
 * archival schema, and `DELETE /api/schemas/{id}?deleteObjects=true` destroyed the
 * rows AND dropped the magic table.
 *
 * These tests drive the REAL service and assert on the REAL predicate: the schema
 * double carries an actual `x-openregister-archival` configuration and the refusal
 * is the shipped gate's, not a copy of its condition. Breaking or deleting
 * `Schema::hasArchivalAnnotation()` fails them.
 *
 * Spec REQ (archival-annotation-vocabulary):
 *   "DELETE on an archival schema returns 403 SCHEMA_ARCHIVAL_IMMUTABLE"
 *   "An administrative CLI purge is the only path that can destroy an archival record"
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Service\SchemaDeletionService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The archival gate on schema-wide object deletion.
 */
class SchemaDeletionArchivalGateTest extends TestCase {

	private const REGISTER_ID = 7;

	private const SCHEMA_ID = 42;

	/**
	 * The real archival annotation, exactly as a schema descriptor declares it.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private const ARCHIVAL_CONFIGURATION = [
		'x-openregister-archival' => ['retention' => ['default' => 'P10Y']],
	];

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $magicMapper;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	private SchemaDeletionService $service;

	private Register $register;

	/**
	 * Wire the real service up with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SchemaDeletionService(
			$this->db,
			$this->magicMapper,
			$this->registerMapper,
			$this->schemaMapper,
			$this->auditTrailMapper,
			$this->logger
		);

		$this->register = $this->makeEntity(new Register(), self::REGISTER_ID);
		$this->registerMapper->method('find')->willReturn($this->register);

	}//end setUp()

	/**
	 * Inject an id into an entity (Entity::$id is protected).
	 *
	 * @param object $entity The entity.
	 * @param int    $id     The id to inject.
	 *
	 * @return mixed The same entity.
	 */
	private function makeEntity(object $entity, int $id): mixed {
		$property = (new ReflectionClass($entity))->getProperty('id');
		$property->setAccessible(true);
		$property->setValue($entity, $id);

		return $entity;
	}//end makeEntity()

	/**
	 * Build a schema, optionally declaring the archival annotation.
	 *
	 * The configuration is the real annotation shape, so the gate's answer comes
	 * from `Schema::hasArchivalAnnotation()` reading real data.
	 *
	 * @param bool $archival Whether the schema declares `x-openregister-archival`.
	 *
	 * @return Schema The schema.
	 */
	private function makeSchema(bool $archival): Schema {
		$schema = $this->makeEntity(new Schema(), self::SCHEMA_ID);
		$schema->setSlug('retained-case');
		$schema->setTitle('Retained Case');

		if ($archival === true) {
			$schema->setConfiguration(self::ARCHIVAL_CONFIGURATION);
		}

		return $schema;
	}//end makeSchema()

	/**
	 * Make the schema resolvable and give it one populated magic table.
	 *
	 * @param Schema $schema The schema the service will resolve.
	 *
	 * @return void
	 */
	private function stubOnePopulatedTable(Schema $schema): void {
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->magicMapper->method('getAllRegisterSchemaPairs')->willReturn(
			[['registerId' => self::REGISTER_ID, 'schemaId' => self::SCHEMA_ID]]
		);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturn(true);

		$object = new ObjectEntity();
		$object->setUuid('90f1e160-c2fc-4829-9833-13c07769734b');
		$object->setRegister((string)self::REGISTER_ID);
		$object->setSchema((string)self::SCHEMA_ID);
		$object->setObject(['reference' => 'CASE-001']);

		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturn([$object]);
		$this->magicMapper->method('deleteObjectsBySchema')->willReturn(1);

	}//end stubOnePopulatedTable()

	/**
	 * The bulk delete-objects endpoints refuse an archival schema and touch nothing.
	 *
	 * This is the reproduction: on `development` this call returned
	 * `deleted_count: 1` and the magic table came back empty.
	 *
	 * @return void
	 */
	public function testBulkSchemaDeleteRefusesAnArchivalSchema(): void {
		$this->stubOnePopulatedTable($this->makeSchema(archival: true));

		$this->magicMapper->expects($this->never())->method('deleteObjectsBySchema');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');

		$this->expectException(ArchivalImmutableException::class);

		$this->service->deleteObjectsBySchema(
			registerId: self::REGISTER_ID,
			schemaId: self::SCHEMA_ID,
			hardDelete: true
		);

	}//end testBulkSchemaDeleteRefusesAnArchivalSchema()

	/**
	 * The refusal is a 403 naming the schema, so a controller can return it verbatim.
	 *
	 * @return void
	 */
	public function testBulkRefusalCarriesThe403ContractBody(): void {
		$this->stubOnePopulatedTable($this->makeSchema(archival: true));

		try {
			$this->service->deleteObjectsBySchema(
				registerId: self::REGISTER_ID,
				schemaId: self::SCHEMA_ID,
				hardDelete: true
			);
			$this->fail('Expected ArchivalImmutableException');
		} catch (ArchivalImmutableException $e) {
			$body = $e->toResponseBody();

			$this->assertSame(403, $e->getCode());
			$this->assertSame('SCHEMA_ARCHIVAL_IMMUTABLE', $body['error']);
			$this->assertSame('retained-case', $body['schema']);
			$this->assertSame('delete', $body['operation']);
		}//end try

	}//end testBulkRefusalCarriesThe403ContractBody()

	/**
	 * A soft bulk delete is refused too — a tombstone is still a delete.
	 *
	 * @return void
	 */
	public function testBulkSoftDeleteIsRefusedAsWell(): void {
		$this->stubOnePopulatedTable($this->makeSchema(archival: true));

		$this->magicMapper->expects($this->never())->method('deleteObjectsBySchema');

		$this->expectException(ArchivalImmutableException::class);

		$this->service->deleteObjectsBySchema(
			registerId: self::REGISTER_ID,
			schemaId: self::SCHEMA_ID,
			hardDelete: false
		);

	}//end testBulkSoftDeleteIsRefusedAsWell()

	/**
	 * An ordinary schema is untouched by the gate.
	 *
	 * @return void
	 */
	public function testBulkDeleteStillWorksForANonArchivalSchema(): void {
		$this->stubOnePopulatedTable($this->makeSchema(archival: false));

		$result = $this->service->deleteObjectsBySchema(
			registerId: self::REGISTER_ID,
			schemaId: self::SCHEMA_ID,
			hardDelete: true
		);

		$this->assertSame(1, $result['deleted_count']);
		$this->assertSame(['90f1e160-c2fc-4829-9833-13c07769734b'], $result['deleted_uuids']);

	}//end testBulkDeleteStillWorksForANonArchivalSchema()

	/**
	 * The cascade refuses an archival schema, and refuses BEFORE opening a transaction.
	 *
	 * Nothing is audited, no row is removed, no table is dropped and the schema
	 * entity survives — the reproduction on `development` did all four.
	 *
	 * @return void
	 */
	public function testCascadeRefusesAnArchivalSchema(): void {
		$schema = $this->makeSchema(archival: true);
		$this->stubOnePopulatedTable($schema);

		$this->db->expects($this->never())->method('beginTransaction');
		$this->magicMapper->expects($this->never())->method('deleteObjectsBySchema');
		$this->magicMapper->expects($this->never())->method('dropTable');
		$this->schemaMapper->expects($this->never())->method('delete');

		$this->expectException(ArchivalImmutableException::class);

		$this->service->cascadeDeleteSchema(schema: $schema);

	}//end testCascadeRefusesAnArchivalSchema()

	/**
	 * The cascade proceeds when an operator authorises it explicitly.
	 *
	 * `occ openregister:schemas:prune-retired --force-archival` is the only caller
	 * that passes this; the HTTP cascade never does.
	 *
	 * @return void
	 */
	public function testCascadeProceedsWithAnExplicitArchivalOverride(): void {
		$schema = $this->makeSchema(archival: true);
		$this->stubOnePopulatedTable($schema);

		$this->magicMapper->expects($this->once())->method('dropTable');
		$this->schemaMapper->expects($this->once())->method('delete');

		$result = $this->service->cascadeDeleteSchema(schema: $schema, archivalOverride: true);

		$this->assertSame(1, $result['deletedCount']);
		$this->assertTrue($result['tableDropped']);

	}//end testCascadeProceedsWithAnExplicitArchivalOverride()

	/**
	 * The override defaults to off, so a caller that says nothing gets the refusal.
	 *
	 * A default-on override would make every future call site permissive by silence,
	 * which is exactly how this door came to be open.
	 *
	 * @return void
	 */
	public function testTheArchivalOverrideIsOffByDefault(): void {
		$reflection = new ReflectionClass(SchemaDeletionService::class);
		$parameters = $reflection->getMethod('cascadeDeleteSchema')->getParameters();

		$this->assertSame('archivalOverride', $parameters[1]->getName());
		$this->assertTrue($parameters[1]->isDefaultValueAvailable());
		$this->assertFalse($parameters[1]->getDefaultValue());

	}//end testTheArchivalOverrideIsOffByDefault()

	/**
	 * The bulk path has NO override parameter at all.
	 *
	 * Its only two callers are HTTP routes, and an override they could pass through
	 * would put archival destruction back on the network.
	 *
	 * @return void
	 */
	public function testTheBulkPathExposesNoArchivalOverride(): void {
		$reflection = new ReflectionClass(SchemaDeletionService::class);
		$names = array_map(
			static fn ($parameter) => $parameter->getName(),
			$reflection->getMethod('deleteObjectsBySchema')->getParameters()
		);

		$this->assertNotContains('archivalOverride', $names);

	}//end testTheBulkPathExposesNoArchivalOverride()

	/**
	 * The cascade still runs for an ordinary schema.
	 *
	 * @return void
	 */
	public function testCascadeStillWorksForANonArchivalSchema(): void {
		$schema = $this->makeSchema(archival: false);
		$this->stubOnePopulatedTable($schema);

		$result = $this->service->cascadeDeleteSchema(schema: $schema);

		$this->assertSame(1, $result['deletedCount']);
		$this->assertSame(['90f1e160-c2fc-4829-9833-13c07769734b'], $result['deletedUuids']);

	}//end testCascadeStillWorksForANonArchivalSchema()

	/**
	 * A malformed annotation is not an archival declaration, and must not become one.
	 *
	 * `Schema::hasArchivalAnnotation()` requires an ARRAY value; `true` is a typo, not
	 * a retention policy. Pinning it here keeps this gate from drifting into a looser
	 * reading than the other three doors use.
	 *
	 * @return void
	 */
	public function testAMalformedAnnotationDoesNotGateTheDelete(): void {
		$schema = $this->makeEntity(new Schema(), self::SCHEMA_ID);
		$schema->setSlug('retained-case');
		$schema->setConfiguration(['x-openregister-archival' => true]);
		$this->stubOnePopulatedTable($schema);

		$result = $this->service->deleteObjectsBySchema(
			registerId: self::REGISTER_ID,
			schemaId: self::SCHEMA_ID,
			hardDelete: true
		);

		$this->assertSame(1, $result['deleted_count']);

	}//end testAMalformedAnnotationDoesNotGateTheDelete()
}//end class
