<?php

/**
 * Integration tests for the raw append and expiry purge on ObjectService.
 *
 * Runs against a live database: rows really land in the register+schema
 * table, `_expires` really holds the expiry, and the purge really removes
 * only what has expired, with no audit trail written for any of it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class RawAppendIntegrationTest extends TestCase {
	private ObjectService $objectService;
	private MagicMapper $mapper;
	private RegisterMapper $registerMapper;
	private SchemaMapper $schemaMapper;
	private IDBConnection $db;

	/** @var int[] IDs of schemas created during tests */
	private array $createdSchemaIds = [];
	/** @var int[] IDs of registers created during tests */
	private array $createdRegisterIds = [];
	/** @var string[] Prefixed names of magic tables created during tests */
	private array $createdTables = [];

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = \OC::$server->get(ObjectService::class);
		$this->mapper = \OC::$server->get(MagicMapper::class);
		$this->registerMapper = \OC::$server->get(RegisterMapper::class);
		$this->schemaMapper = \OC::$server->get(SchemaMapper::class);
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	protected function tearDown(): void {
		foreach ($this->createdTables as $tableName) {
			try {
				$this->db->prepare("DROP TABLE IF EXISTS $tableName")->execute();
			} catch (\Exception $e) {
				// Table may not exist
			}
		}

		foreach ($this->createdSchemaIds as $id) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('openregister_schemas')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			} catch (\Exception $e) {
				// Already cleaned up
			}
		}

		foreach ($this->createdRegisterIds as $id) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('openregister_registers')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			} catch (\Exception $e) {
				// Already cleaned up
			}
		}

		parent::tearDown();
	}

	/**
	 * Create a schema with a couple of plain properties.
	 */
	private function createTestSchema(): Schema {
		$schema = $this->schemaMapper->createFromArray([
			'title' => 'PHPUnit Raw Append Schema ' . uniqid(),
			'description' => 'Schema for raw append integration tests',
			'properties' => [
				'name' => ['type' => 'string', 'title' => 'Name', 'maxLength' => 255],
				'pagePath' => ['type' => 'string', 'title' => 'Page path', 'maxLength' => 512],
				'hits' => ['type' => 'integer', 'title' => 'Hits'],
			],
		]);
		$this->createdSchemaIds[] = $schema->getId();

		return $schema;
	}

	/**
	 * Create a register that carries the given schema, so slugs resolve within it.
	 */
	private function createTestRegister(Schema $schema): Register {
		$register = $this->registerMapper->createFromArray([
			'title' => 'PHPUnit Raw Append Register ' . uniqid(),
			'description' => 'Register for raw append integration tests',
			'schemas' => [$schema->getId()],
		]);
		$this->createdRegisterIds[] = $register->getId();

		return $register;
	}

	private function trackTable(Register $register, Schema $schema): string {
		$tableName = $this->mapper->getTableNameForRegisterSchema($register, $schema);
		$this->createdTables[] = 'oc_' . $tableName;

		return $tableName;
	}

	/**
	 * Read `_uuid` => `_expires` for every row in the table.
	 *
	 * @return array<string, string|null>
	 */
	private function rowsByUuid(string $tableName): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('_uuid', '_expires')->from($tableName);
		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetch()) !== false) {
			$rows[$row['_uuid']] = $row['_expires'];
		}

		$result->closeCursor();

		return $rows;
	}

	private function countAuditRowsFor(array $uuids): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))
			->from('openregister_audit_trails')
			->where($qb->expr()->in('object_uuid', $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function testAppendThenPurgeRemovesOnlyTheExpiredRow(): void {
		$schema = $this->createTestSchema();
		$register = $this->createTestRegister($schema);
		$tableName = $this->trackTable($register, $schema);

		$written = $this->objectService->appendObjectsRaw(
			objects: [
				['uuid' => 'raw-expired', 'expires' => (new \DateTimeImmutable('-1 day'))->format(DATE_ATOM), 'name' => 'page_view', 'pagePath' => '/old', 'hits' => 1],
				['uuid' => 'raw-future', 'expires' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM), 'name' => 'page_view', 'pagePath' => '/new', 'hits' => 2],
				['uuid' => 'raw-forever', 'name' => 'scroll', 'pagePath' => '/keep', 'hits' => 3],
			],
			register: $register,
			schema: $schema
		);

		$this->assertSame(3, $written);

		$rows = $this->rowsByUuid($tableName);
		$uuids = array_keys($rows);
		sort($uuids);
		$this->assertSame(['raw-expired', 'raw-forever', 'raw-future'], $uuids);
		$this->assertNotNull($rows['raw-expired'], 'An expiry passed by the caller lands in _expires.');
		$this->assertNotNull($rows['raw-future']);
		$this->assertNull($rows['raw-forever'], 'A row without an expiry keeps _expires NULL.');

		// No audit trail for raw rows: that is the contract.
		$this->assertSame(0, $this->countAuditRowsFor(['raw-expired', 'raw-future', 'raw-forever']));

		$purged = $this->objectService->purgeExpiredObjectsRaw(register: $register, schema: $schema);
		$this->assertSame(1, $purged);

		$remaining = $this->rowsByUuid($tableName);
		$this->assertArrayNotHasKey('raw-expired', $remaining);
		$this->assertArrayHasKey('raw-future', $remaining);
		$this->assertArrayHasKey('raw-forever', $remaining);

		// Idempotent: a second sweep finds nothing.
		$this->assertSame(0, $this->objectService->purgeExpiredObjectsRaw(register: $register, schema: $schema));
	}

	public function testSlugsResolveWithinTheRegister(): void {
		$schema = $this->createTestSchema();
		$register = $this->createTestRegister($schema);
		$tableName = $this->trackTable($register, $schema);

		$written = $this->objectService->appendObjectsRaw(
			objects: [['name' => 'page_view', 'pagePath' => '/slug', 'hits' => 1]],
			register: $register->getSlug(),
			schema: $schema->getSlug()
		);

		$this->assertSame(1, $written);
		$this->assertCount(1, $this->rowsByUuid($tableName));
	}

	public function testPurgeOnASchemaNothingWasAppendedToAnswersZero(): void {
		$schema = $this->createTestSchema();
		$register = $this->createTestRegister($schema);
		$this->trackTable($register, $schema);

		$this->assertSame(0, $this->objectService->purgeExpiredObjectsRaw(register: $register, schema: $schema));
	}
}
