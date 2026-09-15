<?php

/**
 * TextBlockMapper: reads and writes the administered canned text blocks.
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
 * Class TextBlockMapper.
 *
 * @method TextBlock insert(Entity $entity)
 * @method TextBlock update(Entity $entity)
 * @method TextBlock delete(Entity $entity)
 *
 * @template-extends QBMapper<TextBlock>
 *
 * @psalm-suppress PossiblyUnusedMethod
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TextBlockMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_text_blocks',
			entityClass: TextBlock::class
		);

	}//end __construct()

	/**
	 * One block by its administered name.
	 *
	 * @param string $slug The block name.
	 *
	 * @return TextBlock|null The block, or null when none is administered.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findBySlug(string $slug): ?TextBlock {
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
	 * The blocks in scope for a register, a schema and a set of groups.
	 *
	 * A block scoped to nothing is in scope everywhere, so each narrowing is
	 * "mine or nobody's". A block scoped to a group the reader is not in is
	 * left out entirely, which is the whole point of the group scope.
	 *
	 * @param string|null       $register The register being written on.
	 * @param string|null       $schema   The schema being written on.
	 * @param array<int,string> $groups   The reader's groups.
	 *
	 * @return array<int, TextBlock> The blocks a handler may insert.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findInScope(?string $register = null, ?string $schema = null, array $groups = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('title', 'ASC');

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

		if ($groups === []) {
			$qb->andWhere($qb->expr()->isNull('group_id'));

			return $this->findEntities(query: $qb);
		}

		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->isNull('group_id'),
				$qb->expr()->in(
					'group_id',
					$qb->createNamedParameter($groups, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
		);

		return $this->findEntities(query: $qb);

	}//end findInScope()
}//end class
