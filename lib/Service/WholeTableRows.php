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
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Service\Object\FileWriteGuard;

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
	 * @param MagicMapperInterface $mapper Reads the magic table.
	 */
	public function __construct(
		private readonly MagicMapperInterface $mapper,
	) {
	}//end __construct()

	/**
	 * Refuse when a row of the table is frozen, before any row is touched.
	 *
	 * The mapper primitive empties the table in one statement, so the object deleting
	 * event that refuses a frozen row on every other delete never fires there.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @return void
	 *
	 * @throws ObjectStateWriteException When a row is frozen.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
	 */
	public function assertNoneFrozen(Register $register, Schema $schema): void {
		if ($this->mapper->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
			return;
		}

		foreach ($this->all(register: $register, schema: $schema) as $object) {
			if ($object->isFrozen() === true) {
				(new FileWriteGuard())->assertWritable(object: $object);
			}
		}
	}//end assertNoneFrozen()

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
				register: $register,
				schema: $schema,
				limit: self::CHUNK_SIZE,
				offset: $offset,
				filters: $filters
			);
			$full = (count($objects) === self::CHUNK_SIZE);
			yield from $objects;
			$offset += self::CHUNK_SIZE;
		} while ($full === true);
	}//end all()
}//end class
