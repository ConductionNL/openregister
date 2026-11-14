<?php

/**
 * DraftVersionMapper — the draft store of named object versions.
 *
 * Drafts live in `openregister_object_drafts`, apart from every object table,
 * so no object list, count, facet, search or relation query can return one.
 * This mapper applies no access rule of its own: who may see or change a draft
 * is decided by `DraftService`, which every caller goes through.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes draft rows.
 *
 * @template-extends QBMapper<DraftVersion>
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */
class DraftVersionMapper extends QBMapper {

	/**
	 * The draft table.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_object_drafts';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: DraftVersion::class);
	}//end __construct()

	/**
	 * Every draft of one object, oldest first.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return array<int, DraftVersion> The drafts.
	 *
	 * @spec openspec/changes/records-draft-versions/design.md
	 */
	public function findForObject(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findForObject()

	/**
	 * One draft of an object by its key, or null.
	 *
	 * @param string $objectUuid The object's uuid.
	 * @param string $key        The draft key.
	 *
	 * @return DraftVersion|null The draft.
	 *
	 * @spec openspec/changes/records-draft-versions/design.md
	 */
	public function findByKey(string $objectUuid, string $key): ?DraftVersion {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('draft_key', $qb->createNamedParameter($key)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByKey()

	/**
	 * Every draft of the given objects, oldest first, in one read per chunk.
	 *
	 * @param array<int, string> $objectUuids The objects' uuids.
	 *
	 * @return array<int, DraftVersion> The drafts.
	 *
	 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-never-appear-as-objects-in-lists-or-search
	 */
	public function findForObjects(array $objectUuids): array {
		$objectUuids = array_values(array_unique(array_filter($objectUuids, static fn (mixed $uuid): bool => is_string($uuid) === true && $uuid !== '')));
		$drafts      = [];
		foreach (array_chunk($objectUuids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from(self::TABLE)
				->where($qb->expr()->in('object_uuid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
				->orderBy('id', 'ASC');
			array_push($drafts, ...$this->findEntities(query: $qb));
		}

		return $drafts;
	}//end findForObjects()
}//end class
