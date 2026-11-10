<?php

/**
 * OpenRegister WholeTableRows
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
 */

namespace OCA\OpenRegister\Service;

use Generator;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCP\IDBConnection;
use PDO;

/**
 * Reads every row a whole-table delete is about to remove.
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
 */
final class WholeTableRows {

	/**
	 * Rows read per query (ADR-009).
	 */
	private const CHUNK_SIZE = 500;

	/**
	 * How long the delete waits for a row another writer holds, on PostgreSQL.
	 *
	 * PostgreSQL waits without limit by default; MariaDB and MySQL give up after
	 * innodb_lock_wait_timeout (50 s). Past it the delete fails and rolls back whole.
	 */
	public const LOCK_TIMEOUT = '50s';

	/**
	 * @param MagicMapperInterface $mapper Reads the magic table.
	 * @param IDBConnection        $db     Holds the rows of the table.
	 */
	public function __construct(
		private readonly MagicMapperInterface $mapper,
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Refuse when a row of the table is frozen, archived or locked, before any row is touched.
	 *
	 * The mapper primitive empties the table in one statement, so the object deleting
	 * event that refuses such a row on every other delete never fires there. A lock of
	 * the caller's own refuses too: this door takes rows of every holder at once.
	 *
	 * Call it inside the transaction of the delete: the rows stay held until that
	 * transaction ends, so what this check saw is what the delete removes.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return void
	 *
	 * @throws ObjectStateWriteException When a row is frozen or archived.
	 * @throws LockedException When a row is locked.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function assertNoneKept(Register $register, Schema $schema): void {
		if ($this->mapper->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
			return;
		}

		$this->holdRows(register: $register, schema: $schema);
		foreach ($this->all(register: $register, schema: $schema) as $object) {
			(new FileWriteGuard())->assertWritable(object: $object);
			if ($object->isLocked() === true) {
				throw (new LockedException())->forObject($object);
			}
		}
	}//end assertNoneKept()

	/**
	 * Take a row lock on every row of the table, until the transaction ends.
	 *
	 * A plain read holds nothing: a lock or freeze committed after it would be missed.
	 * SQLite has no row locks and no FOR UPDATE, and it runs one writer at a time.
	 * On PostgreSQL the wait for a held row is bounded for the rest of the transaction.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return void
	 */
	private function holdRows(Register $register, Schema $schema): void {
		$provider = $this->db->getDatabaseProvider();
		if ($provider === IDBConnection::PLATFORM_SQLITE) {
			return;
		}

		if ($provider === IDBConnection::PLATFORM_POSTGRES) {
			$this->db->executeStatement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");
		}

		$query = $this->db->getQueryBuilder();
		$query->select('_uuid')
			->from($this->mapper->getTableNameForRegisterSchema(register: $register, schema: $schema))
			->where($query->expr()->eq('_register', $query->createNamedParameter($register->getId(), PDO::PARAM_INT)))
			->andWhere($query->expr()->eq('_schema', $query->createNamedParameter($schema->getId(), PDO::PARAM_INT)));

		$this->db->executeQuery($query->getSQL().' FOR UPDATE', $query->getParameters(), $query->getParameterTypes())->closeCursor();
	}//end holdRows()

	/**
	 * How many rows of the table are frozen, archived or locked.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return int The number of rows a whole-table delete must not take unasked.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	public function countKept(Register $register, Schema $schema): int {
		if ($this->mapper->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
			return 0;
		}

		$kept = 0;
		foreach ($this->all(register: $register, schema: $schema) as $object) {
			if ($this->isKept(object: $object) === true) {
				$kept++;
			}
		}

		return $kept;
	}//end countKept()

	/**
	 * Whether a whole-table delete must not take this row unasked: it is archived, frozen or locked.
	 *
	 * @param ObjectEntity $object The row.
	 *
	 * @return bool True when the row is kept.
	 */
	private function isKept(ObjectEntity $object): bool {
		return $object->isArchived() === true || $object->isFrozen() === true || $object->isLocked() === true;
	}//end isKept()

	/**
	 * Every row of one table, soft-deleted ones included, chunk by chunk.
	 *
	 * RBAC and multitenancy filters are off: the delete that follows removes EVERY
	 * row, so a narrowed read would see fewer objects than it destroys.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return Generator<int, ObjectEntity>
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function all(Register $register, Schema $schema): Generator {
		$filters = ['_includeDeleted' => true, '_rbac' => false, '_multitenancy' => false];
		$offset = 0;
		do {
			$objects = $this->mapper->findAllInRegisterSchemaTable(
				table: new RegisterSchema(register: $register, schema: $schema),
				listing: new ListQuery(limit: self::CHUNK_SIZE, offset: $offset, filters: $filters)
			);
			$full = (count($objects) === self::CHUNK_SIZE);
			yield from $objects;
			$offset += self::CHUNK_SIZE;
		} while ($full === true);
	}//end all()
}//end class
