<?php

/**
 * ReferencePatternMapper: reads and writes the administered reference patterns.
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
 * Class ReferencePatternMapper.
 *
 * @method ReferencePattern insert(Entity $entity)
 * @method ReferencePattern update(Entity $entity)
 * @method ReferencePattern delete(Entity $entity)
 *
 * @template-extends QBMapper<ReferencePattern>
 *
 * @psalm-suppress PossiblyUnusedMethod
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class ReferencePatternMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_reference_patterns',
			entityClass: ReferencePattern::class
		);

	}//end __construct()

	/**
	 * One pattern by its administered name.
	 *
	 * @param string $slug The pattern name.
	 *
	 * @return ReferencePattern|null The declaration, or null when none is declared.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findBySlug(string $slug): ?ReferencePattern {
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
	 * Every pattern, or only the ones that are applied.
	 *
	 * @param boolean $enabledOnly Whether to leave out the disabled ones.
	 *
	 * @return array<int, ReferencePattern> The declarations.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag narrows one query by
	 * one column; it selects no second behaviour. The admin panel lists every
	 * pattern including the switched-off ones, and the resolver lists only the
	 * ones that are applied, and those are the same read.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findAll(bool $enabledOnly = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('slug', 'ASC');

		if ($enabledOnly === true) {
			$qb->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));
		}

		return $this->findEntities(query: $qb);

	}//end findAll()
}//end class
