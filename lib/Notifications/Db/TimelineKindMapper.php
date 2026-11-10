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

namespace OCA\OpenRegister\Notifications\Db;

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
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TimelineKindMapper extends QBMapper {
	use NotificationQueryTrait;


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
		$found = $this->findFirstOrNull(qb: $this->selectWhereEquals(columns: ['slug' => $slug]));
		if ($found instanceof TimelineKind) {
			return $found;
		}

		return null;
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

		$this->whereScopedOrUnscoped(qb: $qb, column: 'register', value: $register);
		$this->whereScopedOrUnscoped(qb: $qb, column: 'schema', value: $schema);

		return $this->findEntities(query: $qb);
	}//end findAll()
}//end class
