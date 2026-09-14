<?php

/**
 * EntryReferenceMapper: reads and writes recorded references.
 *
 * Read from either end. "What does this note point at" is a read by entry;
 * "which zaken mention this besluit" is a read by target, and both are the
 * same rows (D-6).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class EntryReferenceMapper.
 *
 * @method EntryReference insert(Entity $entity)
 * @method EntryReference update(Entity $entity)
 * @method EntryReference delete(Entity $entity)
 *
 * @template-extends QBMapper<EntryReference>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class EntryReferenceMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_entry_references',
			entityClass: EntryReference::class
		);

	}//end __construct()

	/**
	 * Every reference one entry's text produced.
	 *
	 * @param string $entryUuid The entry.
	 *
	 * @return array<int, EntryReference> The rows.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findForEntry(string $entryUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('entry_uuid', $qb->createNamedParameter($entryUuid)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);

	}//end findForEntry()

	/**
	 * Every reference pointing AT one object: the other end of the link.
	 *
	 * @param string $targetUuid The referenced object.
	 *
	 * @return array<int, EntryReference> The rows.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findForTarget(string $targetUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('target_uuid', $qb->createNamedParameter($targetUuid)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);

	}//end findForTarget()

	/**
	 * Drop every reference one entry's text produced.
	 *
	 * Called before the references of a rewritten entry are recorded again, so
	 * removing the code from the sentence removes the reference with it.
	 *
	 * @param string $entryUuid The entry being rewritten or removed.
	 *
	 * @return integer The number of rows removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function deleteForEntry(string $entryUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('entry_uuid', $qb->createNamedParameter($entryUuid)));

		return (int)$qb->executeStatement();

	}//end deleteForEntry()
}//end class
