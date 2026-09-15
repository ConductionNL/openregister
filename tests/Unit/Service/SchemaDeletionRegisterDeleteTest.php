<?php

/**
 * SchemaDeletionService register-wide delete tests.
 *
 * `POST /api/bulk/{register}/delete-register` called a stub that threw
 * "deleteObjectsByRegister needs reimplementation using MagicMapper" on every
 * request. The register-wide delete now runs on the magic tables, through the
 * same audited per-pair delete the schema-wide route uses, and it keeps the
 * two guarantees a destructive bulk route owes:
 *
 * - ARCHIVAL IMMUTABILITY is checked for EVERY schema of the register before
 *   ANY row is touched, so one retained schema refuses the whole request
 *   instead of leaving the register half emptied.
 * - It is ONE transaction, so a failure on the third table does not leave the
 *   first two deleted.
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * The register-wide delete on the magic tables.
 */
class SchemaDeletionRegisterDeleteTest extends TestCase {

	private const REGISTER_ID = 7;

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
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	private SchemaDeletionService $service;

	/**
	 * Schemas the schema mapper resolves, by id.
	 *
	 * @var array<int, Schema>
	 */
	private array $schemas = [];

	/**
	 * Objects each schema's magic table holds, by schema id.
	 *
	 * @var array<int, array<int, ObjectEntity>>
	 */
	private array $rows = [];

	/**
	 * Wire the real service up with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);

		$this->schemaMapper->method('find')->willReturnCallback(
			function (int|string $id): Schema {
				if (isset($this->schemas[(int)$id]) === false) {
					throw new DoesNotExistException('no schema ' . $id);
				}

				return $this->schemas[(int)$id];
			}
		);
		$this->magicMapper->method('tableExistsForRegisterSchema')->willReturnCallback(
			fn (Register $register, Schema $schema): bool => isset($this->rows[(int)$schema->getId()])
		);
		$this->magicMapper->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function (Register $register, Schema $schema, ?int $limit = null, ?int $offset = null): array {
				// One chunk per table: a second read (offset > 0) returns nothing.
				if ((int)$offset > 0) {
					return [];
				}

				return $this->rows[(int)$schema->getId()] ?? [];
			}
		);

		$this->service = new SchemaDeletionService(
			$this->db,
			$this->magicMapper,
			$this->createMock(RegisterMapper::class),
			$this->schemaMapper,
			$this->auditTrailMapper,
			$this->createMock(LoggerInterface::class)
		);

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
	 * Build the register, listing the given schema ids.
	 *
	 * @param array<int, int> $schemaIds The schema ids the register lists.
	 *
	 * @return Register The register.
	 */
	private function register(array $schemaIds): Register {
		$register = $this->makeEntity(new Register(), self::REGISTER_ID);
		$register->setSchemas($schemaIds);

		return $register;
	}//end register()

	/**
	 * Declare a schema, and the rows its magic table holds (null: no table).
	 *
	 * @param int                     $id       The schema id.
	 * @param array<int, string>|null $uuids    UUIDs of the rows, or null for no table.
	 * @param bool                    $archival Whether it declares `x-openregister-archival`.
	 *
	 * @return void
	 */
	private function givenSchema(int $id, ?array $uuids, bool $archival = false): void {
		$schema = $this->makeEntity(new Schema(), $id);
		$schema->setSlug('schema-' . $id);
		$schema->setTitle('Schema ' . $id);
		if ($archival === true) {
			$schema->setConfiguration(self::ARCHIVAL_CONFIGURATION);
		}

		$this->schemas[$id] = $schema;

		if ($uuids === null) {
			return;
		}

		$this->rows[$id] = array_map(
			static function (string $uuid) use ($id): ObjectEntity {
				$object = new ObjectEntity();
				$object->setUuid($uuid);
				$object->setRegister((string)self::REGISTER_ID);
				$object->setSchema((string)$id);
				$object->setObject(['name' => $uuid]);

				return $object;
			},
			$uuids
		);
	}//end givenSchema()

	/**
	 * Every schema of the register is emptied, every object audited, in one transaction.
	 *
	 * @return void
	 */
	public function testDeletesEveryPairOfTheRegisterAndAuditsEachObject(): void {
		$this->givenSchema(id: 42, uuids: ['a-1', 'a-2']);
		$this->givenSchema(id: 43, uuids: ['b-1']);
		// Listed but never materialised: an ordinary state, skipped rather than thrown on.
		$this->givenSchema(id: 44, uuids: null);

		$this->magicMapper->method('deleteObjectsBySchema')->willReturnCallback(
			fn (Register $register, Schema $schema, bool $hardDelete): int => count($this->rows[(int)$schema->getId()])
		);
		$this->auditTrailMapper->expects($this->exactly(3))->method('createAuditTrailEntry');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$result = $this->service->deleteObjectsByRegister(register: $this->register([42, 43, 44]));

		$this->assertSame(3, $result['deleted_count']);
		$this->assertSame(['a-1', 'a-2', 'b-1'], $result['deleted_uuids']);
		$this->assertSame(self::REGISTER_ID, $result['register_id']);

	}//end testDeletesEveryPairOfTheRegisterAndAuditsEachObject()

	/**
	 * One archival schema refuses the WHOLE request, before any row is touched.
	 *
	 * The plain schema is listed first on purpose: a per-schema check inside the
	 * delete loop would already have emptied it by the time it reached the
	 * archival one.
	 *
	 * @return void
	 */
	public function testOneArchivalSchemaRefusesTheWholeRegisterAndDeletesNothing(): void {
		$this->givenSchema(id: 42, uuids: ['a-1']);
		$this->givenSchema(id: 43, uuids: ['b-1'], archival: true);

		$this->magicMapper->expects($this->never())->method('deleteObjectsBySchema');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrailEntry');
		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->service->deleteObjectsByRegister(register: $this->register([42, 43]));
			$this->fail('Expected ArchivalImmutableException');
		} catch (ArchivalImmutableException $e) {
			$this->assertSame(403, $e->getCode());
			$this->assertSame('schema-43', $e->toResponseBody()['schema']);
		}

	}//end testOneArchivalSchemaRefusesTheWholeRegisterAndDeletesNothing()

	/**
	 * A failure on a later table rolls the earlier ones back.
	 *
	 * @return void
	 */
	public function testAFailureOnALaterTableRollsEverythingBack(): void {
		$this->givenSchema(id: 42, uuids: ['a-1']);
		$this->givenSchema(id: 43, uuids: ['b-1']);

		$this->magicMapper->method('deleteObjectsBySchema')->willReturnCallback(
			static function (Register $register, Schema $schema, bool $hardDelete): int {
				if ((int)$schema->getId() === 43) {
					throw new RuntimeException('disk full');
				}

				return 1;
			}
		);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('disk full');

		$this->service->deleteObjectsByRegister(register: $this->register([42, 43]));

	}//end testAFailureOnALaterTableRollsEverythingBack()

	/**
	 * A register that lists a schema id nothing answers to still deletes the rest.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIdIsSkipped(): void {
		$this->givenSchema(id: 42, uuids: ['a-1']);

		$this->magicMapper->method('deleteObjectsBySchema')->willReturn(1);

		$result = $this->service->deleteObjectsByRegister(register: $this->register([42, 999]));

		$this->assertSame(1, $result['deleted_count']);
		$this->assertSame(['a-1'], $result['deleted_uuids']);

	}//end testAnUnresolvableSchemaIdIsSkipped()
}//end class
