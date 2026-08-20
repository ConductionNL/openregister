<?php

/**
 * Mapper for email link entities.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
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
 * Class EmailLinkMapper
 *
 * @template-extends QBMapper<EmailLink>
 */
class EmailLinkMapper extends QBMapper {

	/**
	 * Cache for tableExists() result. The schema doesn't change at
	 * runtime so we can memoise the check.
	 *
	 * @var boolean|null
	 */
	private ?bool $tableExistsCache = null;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_email_links', entityClass: EmailLink::class);
	}//end __construct()

	/**
	 * Whether the underlying link table is present in this deployment.
	 *
	 * Migration `Version1Date20260326100001` drops this table on systems
	 * that have moved to the `_mail` metadata column architecture.
	 * Methods on this mapper short-circuit (returning empty / 0 / null)
	 * when the table is missing, so callers don't have to wrap every
	 * lookup in a try/catch.
	 *
	 * @return bool True when the link table exists in this deployment.
	 */
	public function tableExists(): bool {
		if ($this->tableExistsCache !== null) {
			return $this->tableExistsCache;
		}

		// Doctrine's createSchema() can miss tables created outside the
		// migration framework. Fall back to a direct information_schema
		// query so manual CREATEs (e.g. recovery, dev sandboxes) are
		// honoured.
		try {
			$schema = $this->db->createSchema();
			$this->tableExistsCache = $schema->hasTable('*PREFIX*' . $this->getTableName())
				|| $schema->hasTable($this->getTableName());
		} catch (\Throwable $e) {
			$this->tableExistsCache = false;
		}

		if ($this->tableExistsCache === false) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->select($qb->func()->count('*'))
					->from('information_schema.tables')
					->where($qb->expr()->eq('table_name', $qb->createNamedParameter('oc_' . $this->getTableName())));
				$result = $qb->executeQuery();
				$row = $result->fetch();
				$this->tableExistsCache = $row !== false && (int)reset($row) > 0;
			} catch (\Throwable $e) {
				$this->tableExistsCache = false;
			}
		}

		return $this->tableExistsCache;
	}//end tableExists()

	/**
	 * Find a single email link by primary id.
	 *
	 * QBMapper exposes `find()` only via `@method` docblock; concrete mappers
	 * add it themselves when needed. Wraps the inherited protected
	 * `findEntity()` so callers get a typed 404 path
	 * (`DoesNotExistException`) for unknown ids.
	 *
	 * @param int $id The email link row id.
	 *
	 * @return EmailLink The matching row.
	 *
	 * @throws DoesNotExistException When $id does not resolve.
	 */
	public function find(int $id): EmailLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find email links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int|null $limit Maximum results.
	 * @param int|null $offset Results offset.
	 *
	 * @return EmailLink[] Array of email links.
	 */
	public function findByObjectUuid(string $objectUuid, ?int $limit = null, ?int $offset = null): array {
		// Note: tableExists() short-circuits removed — Doctrine's schema
		// cache can lag manual CREATE TABLE statements (e.g. sandboxes
		// that recreated the table outside the migration framework).
		// The query below catches a real "table missing" via the
		// try/catch wrapper at the call site (provider).
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('mail_date', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Count email links for an object.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Count of links.
	 */
	public function countByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countByObjectUuid()

	/**
	 * Find email links by sender address.
	 *
	 * @param string $sender The sender email address.
	 *
	 * @return EmailLink[] Array of email links.
	 */
	public function findBySender(string $sender): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('sender', $qb->createNamedParameter($sender)))
			->orderBy('mail_date', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findBySender()

	/**
	 * Find a specific email link by object UUID and mail message ID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $mailMessageId The mail message ID.
	 *
	 * @return EmailLink|null The link or null if not found.
	 */
	public function findByObjectAndMessage(string $objectUuid, int $mailMessageId): ?EmailLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere(
				$qb->expr()->eq(
					'mail_message_id',
					$qb->createNamedParameter($mailMessageId, IQueryBuilder::PARAM_INT)
				)
			);

		try {
			return $this->findEntity(query: $qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndMessage()

	/**
	 * Find a specific email link by the full composite tuple.
	 *
	 * Mirrors the unique constraint added in
	 * `Version1Date20260525100000`. Returns the row matching all four
	 * coordinates (UUID + accountId + messageId + messageUid) so the
	 * Tier-2 upsert in `EmailLinkService::linkEmail()` is idempotent
	 * across re-syncs where Mail bumps the UID for the same message.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $mailAccountId The mail account id.
	 * @param int $mailMessageId The mail message id.
	 * @param string|null $mailMessageUid The mail message UID (nullable).
	 *
	 * @return EmailLink|null The link or null if not found.
	 */
	public function findByObjectAccountMessageUid(
		string $objectUuid,
		int $mailAccountId,
		int $mailMessageId,
		?string $mailMessageUid,
	): ?EmailLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere(
				$qb->expr()->eq(
					'mail_account_id',
					$qb->createNamedParameter($mailAccountId, IQueryBuilder::PARAM_INT)
				)
			)
			->andWhere(
				$qb->expr()->eq(
					'mail_message_id',
					$qb->createNamedParameter($mailMessageId, IQueryBuilder::PARAM_INT)
				)
			);

		if ($mailMessageUid === null) {
			$qb->andWhere($qb->expr()->isNull('mail_message_uid'));
			try {
				return $this->findEntity(query: $qb);
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				return null;
			} catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
				return null;
			}
		}

		$qb->andWhere(
			$qb->expr()->eq(
				'mail_message_uid',
				$qb->createNamedParameter($mailMessageUid)
			)
		);

		try {
			return $this->findEntity(query: $qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		} catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
			return null;
		}
	}//end findByObjectAccountMessageUid()

	/**
	 * Delete a link by its primary key on a per-object basis.
	 *
	 * The object_uuid guard prevents a UI mistake (or a malicious
	 * client) from deleting a row that belongs to a different object.
	 *
	 * @param string $objectUuid The object UUID owning the link.
	 * @param int $linkId The link primary key.
	 *
	 * @return int Number of rows deleted (0 or 1).
	 */
	public function deleteByObjectAndId(string $objectUuid, int $linkId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteByObjectAndId()

	/**
	 * Delete all email links for an object UUID.
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
}//end class
