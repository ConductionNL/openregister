<?php

/**
 * TimelineKindMapper: reads and writes the administered entry kinds.
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class TimelineKindMapper.
 *
 * @method TimelineKind insert(Entity $entity)
 * @method TimelineKind update(Entity $entity)
 * @method TimelineKind delete(Entity $entity)
 *
 * @template-extends QBMapper<TimelineKind>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class TimelineKindMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_timeline_kinds',
			entityClass: TimelineKind::class
		);

	}//end __construct()

	/**
	 * One kind by the name entries carry.
	 *
	 * @param string $slug The kind name.
	 *
	 * @return TimelineKind|null The declaration, or null when none is declared.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findBySlug(string $slug): ?TimelineKind {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}

	}//end findBySlug()

	/**
	 * Every kind, optionally only the ones in scope for a register and schema.
	 *
	 * A kind scoped to nothing is in scope everywhere, which is why the
	 * narrowing is "mine or nobody's" rather than an equality.
	 *
	 * @param string|null $register Narrow to kinds declared for this register, plus the unscoped ones.
	 * @param string|null $schema   Narrow to kinds declared for this schema, plus the unscoped ones.
	 *
	 * @return array<int, TimelineKind> The declarations.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findAll(?string $register = null, ?string $schema = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('slug', 'ASC');

		if ($register !== null) {
			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('register'),
					$qb->expr()->eq('register', $qb->createNamedParameter($register))
				)
			);
		}

		if ($schema !== null) {
			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('schema'),
					$qb->expr()->eq('schema', $qb->createNamedParameter($schema))
				)
			);
		}

		return $this->findEntities(query: $qb);

	}//end findAll()
}//end class
