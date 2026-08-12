<?php

/**
 * Mapper for poll link entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class PollLinkMapper
 *
 * @template-extends QBMapper<PollLink>
 */
class PollLinkMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_poll_links', entityClass: PollLink::class);
	}//end __construct()

	/**
	 * Find poll links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return PollLink[] Array of poll links.
	 */
	public function findByObjectUuid(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Find poll links by poll id.
	 *
	 * @param int $pollId The poll id.
	 *
	 * @return PollLink[] Array of poll links.
	 */
	public function findByPollId(int $pollId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByPollId()

	/**
	 * Find a specific poll link by object UUID and poll id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $pollId The poll id.
	 *
	 * @return PollLink|null The link or null if not found.
	 */
	public function findByObjectAndPoll(string $objectUuid, int $pollId): ?PollLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndPoll()

	/**
	 * Delete all poll links for an object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteByObjectUuid()

	/**
	 * Delete a poll link by object UUID + poll id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $pollId The poll id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndPoll(string $objectUuid, int $pollId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByObjectAndPoll()
}//end class
