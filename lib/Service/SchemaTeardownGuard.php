<?php

/**
 * OpenRegister SchemaTeardownGuard
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
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuditTrail\NamedActor;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\IDBConnection;
use Throwable;

/**
 * Decides whether a schema's magic tables may be torn down, and records a forced teardown.
 *
 * A table entry has the shape `{registerId: int, register: Register|null, tableName: string}`.
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
 */
final class SchemaTeardownGuard {

	/**
	 * Actor of a forced teardown: only the command line can force one.
	 */
	private const ACTOR = 'occ';

	/**
	 * Reads the rows of a table with a register.
	 *
	 * @var WholeTableRows
	 */
	public readonly WholeTableRows $rows;

	/**
	 * @param MagicMapperInterface      $mapper Reads the magic tables.
	 * @param IDBConnection             $db     Counts the rows of a table without a register.
	 * @param AuditTrailMapperInterface $audit  Writes the forced entry.
	 */
	public function __construct(
		MagicMapperInterface $mapper,
		private readonly IDBConnection $db,
		private readonly AuditTrailMapperInterface $audit,
	) {
		$this->rows = new WholeTableRows(mapper: $mapper, db: $db);
	}//end __construct()

	/**
	 * How many rows of these tables a teardown must not take unasked.
	 *
	 * A table outliving its register counts in full, and a table that cannot be read
	 * as one row, so the count stays on the side of the data.
	 *
	 * @param array<int, array{registerId: int, register: Register|null, tableName: string}> $tables The tables.
	 * @param Schema                                                                         $schema The schema.
	 *
	 * @return int The number of frozen, archived, locked or unreadable rows.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	public function countKept(array $tables, Schema $schema): int {
		$total = 0;

		foreach ($tables as $entry) {
			$total += $this->keptRowsOfTable(entry: $entry, schema: $schema);
		}

		return $total;
	}//end countKept()

	/**
	 * Refuse when a row of these tables is kept, before any row is touched.
	 *
	 * @param array<int, array{registerId: int, register: Register|null, tableName: string}> $tables The tables.
	 * @param Schema                                                                         $schema The schema.
	 *
	 * @return void
	 *
	 * @throws ObjectStateWriteException If a row is frozen or archived.
	 * @throws LockedException If a row is locked.
	 * @throws ValidationException If a table outliving its register holds rows.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	public function assertNoneKept(array $tables, Schema $schema): void {
		foreach ($tables as $entry) {
			if ($entry['register'] === null) {
				$this->rejectUnreadableRows(tableName: $entry['tableName']);
				continue;
			}

			$this->rows->assertNoneKept(register: $entry['register'], schema: $schema);
		}
	}//end assertNoneKept()

	/**
	 * Write the one audit entry of a forced teardown, when it removes something protected.
	 *
	 * The entry is about the schema, so it carries the schema's uuid and no object id.
	 * Under `occ` there is no session; the actor says so instead of reading as `system`.
	 *
	 * @param Schema $schema   The schema being torn down.
	 * @param int    $keptRows The kept rows that will be destroyed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	public function recordForced(Schema $schema, int $keptRows): void {
		$archival = $schema->hasArchivalAnnotation();
		if ($keptRows === 0 && $archival === false) {
			return;
		}

		$this->audit->recordObjectEvent(
			event: new ObjectAuditEvent(
				object: self::auditSubject(schema: $schema),
				action: SchemaDeletionService::ACTION_FORCED_TEARDOWN,
				context: ['schemaSlug' => $schema->getSlug(), 'keptRows' => $keptRows, 'archivalSchema' => $archival],
				options: new ObjectAuditEventOptions(actor: new NamedActor(id: self::ACTOR, name: self::ACTOR))
			)
		);
	}//end recordForced()

	/**
	 * The object an entry about a schema hangs on.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return ObjectEntity An unsaved object carrying the schema's uuid and id.
	 */
	private static function auditSubject(Schema $schema): ObjectEntity {
		$subject = new ObjectEntity();
		$subject->setUuid($schema->getUuid());
		$subject->setSchema((string)$schema->getId());

		return $subject;
	}//end auditSubject()

	/**
	 * Count every row of a magic table, including soft-deleted ones.
	 *
	 * @param string $tableName The magic table name.
	 *
	 * @return int The row count (0 when the table does not exist).
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	public function countAllRows(string $tableName): int {
		if ($this->db->tableExists($tableName) === false) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($tableName);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countAllRows()

	/**
	 * The kept rows of one magic table.
	 *
	 * @param array{registerId: int, register: Register|null, tableName: string} $entry  The table.
	 * @param Schema                                                             $schema The schema.
	 *
	 * @return int The number of kept rows in that table.
	 */
	private function keptRowsOfTable(array $entry, Schema $schema): int {
		try {
			if ($entry['register'] === null) {
				return $this->countAllRows(tableName: $entry['tableName']);
			}

			return $this->rows->countKept(register: $entry['register'], schema: $schema);
		} catch (Throwable $e) {
			return 1;
		}
	}//end keptRowsOfTable()

	/**
	 * Refuse a table that outlives its register and still holds rows.
	 *
	 * Its rows cannot be read through a Register, so nothing can say they are not frozen.
	 *
	 * @param string $tableName The magic table.
	 *
	 * @return void
	 *
	 * @throws ValidationException If the table holds rows.
	 */
	private function rejectUnreadableRows(string $tableName): void {
		if ($this->countAllRows(tableName: $tableName) === 0) {
			return;
		}

		throw new ValidationException(
			message: 'Cannot remove this schema: magic table ' . $tableName . ' holds rows of a register that '
				. 'no longer exists, so they cannot be checked for a freeze, an archive or a lock.'
		);
	}//end rejectUnreadableRows()
}//end class
