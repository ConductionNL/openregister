<?php

/**
 * OpenRegister DecisionMapper
 *
 * Stores decisions in `openregister_decisions`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Stores and finds decisions. Who may supersede one is decided by
 * DecisionOwnership, not here.
 *
 * @method Decision findEntity(IQueryBuilder $query)
 * @method list<Decision> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Decision>
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */
class DecisionMapper extends QBMapper {

	/**
	 * The table.
	 */
	public const TABLE = 'openregister_decisions';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: Decision::class);
	}//end __construct()

	/**
	 * One decision by its uuid.
	 *
	 * @param string $uuid The decision uuid.
	 *
	 * @return Decision The decision.
	 *
	 * @throws DoesNotExistException When no decision has that uuid.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function findByUuid(string $uuid): Decision {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * Every decision taken on one subject, newest first.
	 *
	 * @param string $subjectType The subject type.
	 * @param string $subjectId   The subject id.
	 *
	 * @return Decision[] The decisions.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function findForSubject(string $subjectType, string $subjectId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('subject_type', $qb->createNamedParameter($subjectType, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('subject_id', $qb->createNamedParameter($subjectId, IQueryBuilder::PARAM_STR)))
			->orderBy('decided_at', 'DESC')
			->addOrderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findForSubject()

	/**
	 * Store a new decision with its uuid and, when unset, the moment of deciding.
	 *
	 * @param Entity $entity The decision.
	 *
	 * @return Decision The stored decision.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
	 */
	public function insert(Entity $entity): Decision {
		if ($entity instanceof Decision === false) {
			throw new InvalidArgumentException('DecisionMapper stores decisions only.');
		}

		if (empty($entity->getUuid()) === true) {
			$entity->setUuid((string)Uuid::v4());
		}

		if ($entity->getDecidedAt() === null) {
			$entity->setDecidedAt(new DateTime());
		}

		return parent::insert(entity: $entity);
	}//end insert()
}//end class
