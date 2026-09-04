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
	/**
	 * The service under test, resolved from the real container.
	 *
	 * @var ObjectService
	 */
	private ObjectService $objectService;

	/**
	 * Resolves the register+schema table name the raw rows land in.
	 *
	 * @var MagicMapper
	 */
	private MagicMapper $mapper;

	/**
	 * Creates the throwaway register each test appends into.
	 *
	 * @var RegisterMapper
	 */
	private RegisterMapper $registerMapper;

	/**
	 * Creates the throwaway schema each test appends into.
	 *
	 * @var SchemaMapper
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * Live connection used to read the table back and to clean up.
	 *
	 * @var IDBConnection
	 */
	private IDBConnection $db;

	/**
	 * IDs of schemas created during tests.
	 *
	 * @var int[]
	 */
	private array $createdSchemaIds = [];

	/**
	 * IDs of registers created during tests.
	 *
	 * @var int[]
	 */
	private array $createdRegisterIds = [];

	/**
	 * Prefixed names of magic tables created during tests.
	 *
	 * @var string[]
	 */
	private array $createdTables = [];

	/**
	 * Resolve the service and mappers from the real container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = \OC::$server->get(ObjectService::class);
		$this->mapper = \OC::$server->get(MagicMapper::class);
		$this->registerMapper = \OC::$server->get(RegisterMapper::class);
		$this->schemaMapper = \OC::$server->get(SchemaMapper::class);
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	/**
	 * Drop every table, schema and register a test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->createdTables as $tableName) {
			try {
				$this->db->prepare("DROP TABLE IF EXISTS $tableName")->execute();
			} catch (\Exception $e) {
				// The table may not exist.
			}
		}

		foreach ($this->createdSchemaIds as $id) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('openregister_schemas')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			} catch (\Exception $e) {
				// Already cleaned up.
			}
		}

		foreach ($this->createdRegisterIds as $id) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('openregister_registers')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			} catch (\Exception $e) {
				// Already cleaned up.
			}
		}

		parent::tearDown();
	}

	/**
	 * Create a schema with a couple of plain properties.
	 *
	 * @return Schema The persisted schema, tracked for cleanup.
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
	 *
	 * @param Schema $schema The schema the register carries.
	 *
	 * @return Register The persisted register, tracked for cleanup.
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

	/**
	 * Register the magic table for cleanup and hand back its unprefixed name.
	 *
	 * @param Register $register The register the rows land in.
	 * @param Schema   $schema   The schema the rows land in.
	 *
	 * @return string The table name without the `oc_` prefix.
	 */
	private function trackTable(Register $register, Schema $schema): string {
		$tableName = $this->mapper->getTableNameForRegisterSchema($register, $schema);
		$this->createdTables[] = 'oc_' . $tableName;

		return $tableName;
	}

	/**
	 * Read `_uuid` => `_expires` for every row in the table.
	 *
	 * @param string $tableName The unprefixed magic table name.
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

	/**
	 * Count the audit-trail rows written for the given object UUIDs.
	 *
	 * Filters on `object_uuid`: the `object` column is the integer object id.
	 *
	 * @param string[] $uuids The object UUIDs to look for.
	 *
	 * @return int The number of audit-trail rows.
	 */
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

	/**
	 * An expired row is purged; a future and an open-ended row survive; no audit trail is written.
	 *
	 * @return void
	 */
	public function testAppendThenPurgeRemovesOnlyTheExpiredRow(): void {
		$schema = $this->createTestSchema();
		$register = $this->createTestRegister(schema: $schema);
		$tableName = $this->trackTable(register: $register, schema: $schema);

		$expired = (new \DateTimeImmutable('-1 day'))->format(DATE_ATOM);
		$future = (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM);

		$written = $this->objectService->appendObjectsRaw(
			objects: [
				['uuid' => 'raw-expired', 'expires' => $expired, 'name' => 'page_view', 'pagePath' => '/old', 'hits' => 1],
				['uuid' => 'raw-future', 'expires' => $future, 'name' => 'page_view', 'pagePath' => '/new', 'hits' => 2],
				['uuid' => 'raw-forever', 'name' => 'scroll', 'pagePath' => '/keep', 'hits' => 3],
			],
			register: $register,
			schema: $schema
		);

		$this->assertSame(expected: 3, actual: $written);

		$rows = $this->rowsByUuid(tableName: $tableName);
		$uuids = array_keys($rows);
		sort($uuids);
		$this->assertSame(expected: ['raw-expired', 'raw-forever', 'raw-future'], actual: $uuids);
		$this->assertNotNull(actual: $rows['raw-expired'], message: 'An expiry passed by the caller lands in _expires.');
		$this->assertNotNull(actual: $rows['raw-future']);
		$this->assertNull(actual: $rows['raw-forever'], message: 'A row without an expiry keeps _expires NULL.');

		// No audit trail for raw rows: that is the contract.
		$this->assertSame(expected: 0, actual: $this->countAuditRowsFor(uuids: ['raw-expired', 'raw-future', 'raw-forever']));

		$purged = $this->objectService->purgeExpiredObjectsRaw(register: $register, schema: $schema);
		$this->assertSame(expected: 1, actual: $purged);

		$remaining = $this->rowsByUuid(tableName: $tableName);
		$this->assertArrayNotHasKey(key: 'raw-expired', array: $remaining);
		$this->assertArrayHasKey(key: 'raw-future', array: $remaining);
		$this->assertArrayHasKey(key: 'raw-forever', array: $remaining);

		// Idempotent: a second sweep finds nothing.
		$this->assertSame(expected: 0, actual: $this->objectService->purgeExpiredObjectsRaw(register: $register, schema: $schema));
	}

	/**
	 * Register and schema slugs resolve to the same table as the entities do.
	 *
	 * @return void
	 */
	public function testSlugsResolveWithinTheRegister(): void {
		$schema = $this->createTestSchema();
		$register = $this->createTestRegister(schema: $schema);
		$tableName = $this->trackTable(register: $register, schema: $schema);

		$written = $this->objectService->appendObjectsRaw(
			objects: [['name' => 'page_view', 'pagePath' => '/slug', 'hits' => 1]],
			register: $register->getSlug(),
			schema: $schema->getSlug()
		);

		$this->assertSame(expected: 1, actual: $written);
		$this->assertCount(expectedCount: 1, haystack: $this->rowsByUuid(tableName: $tableName));
	}

	/**
	 * Purging a schema nothing was ever appended to answers zero rather than failing.
	 *
	 * @return void
	 */
	public function testPurgeOnASchemaNothingWasAppendedToAnswersZero(): void {
		$schema = $this->createTestSchema();
		$register = $this->createTestRegister(schema: $schema);
		$this->trackTable(register: $register, schema: $schema);

		$this->assertSame(expected: 0, actual: $this->objectService->purgeExpiredObjectsRaw(register: $register, schema: $schema));
	}
}
