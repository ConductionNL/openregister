<?php

/**
 * Mapper for calendar feed tokens.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class CalendarFeedTokenMapper
 *
 * @template-extends QBMapper<CalendarFeedToken>
 */
class CalendarFeedTokenMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_calendar_feeds',
			entityClass: CalendarFeedToken::class
		);
	}//end __construct()

	/**
	 * Find a feed token by its opaque token string.
	 *
	 * Returns null on a miss so the public feed endpoint can fail closed
	 * without a DoesNotExistException distinguishing "unknown" from
	 * "revoked", which would make the endpoint an enumeration oracle.
	 *
	 * @param string $token The opaque token string.
	 *
	 * @return CalendarFeedToken|null The token row, or null when unknown.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function findByToken(string $token): ?CalendarFeedToken {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByToken()

	/**
	 * Find a feed token by its row id.
	 *
	 * @param int $id The token row id.
	 *
	 * @return CalendarFeedToken|null The token, or null when unknown.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function findById(int $id): ?CalendarFeedToken {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findById()

	/**
	 * Every token minted for one principal, newest first.
	 *
	 * @param string $userId The principal.
	 *
	 * @return array<int, CalendarFeedToken> The token rows.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByUser()
}//end class
