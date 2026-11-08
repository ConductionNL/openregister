<?php

/**
 * Mapper for CalendarLink entities.
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
use OCP\IDBConnection;

/**
 * Class CalendarLinkMapper
 *
 * @template-extends QBMapper<CalendarLink>
 */
class CalendarLinkMapper extends QBMapper implements CalendarLinkMapperInterface {
	use SelectByColumnTrait;
	use DeleteLinksByObjectUuidTrait;
	use CountLinksByObjectUuidTrait;
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_calendar_links', entityClass: CalendarLink::class);
	}//end __construct()

	/**
	 * Find calendar links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return CalendarLink[] Array of calendar links.
	 */
	public function findByObjectUuid(string $objectUuid): array {
		$qb = $this->selectWhere(column: new LinkColumn(name: 'object_uuid', value: $objectUuid));
		$qb->orderBy('dtstart', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Find a single calendar link by object UUID + event UID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $eventUid The event UID.
	 *
	 * @return CalendarLink The link entity.
	 *
	 * @throws DoesNotExistException When no row matches.
	 */
	public function findByObjectAndEvent(string $objectUuid, string $eventUid): CalendarLink {
		$qb = $this->selectWhere(column: new LinkColumn(name: 'object_uuid', value: $objectUuid));
		$qb->andWhere($qb->expr()->eq('event_uid', $qb->createNamedParameter($eventUid)));

		return $this->findEntity(query: $qb);
	}//end findByObjectAndEvent()

	/**
	 * Delete a single calendar link by object UUID + event UID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $eventUid The event UID.
	 *
	 * @return int Number of deleted rows (0 or 1).
	 */
	public function deleteByObjectAndEvent(string $objectUuid, string $eventUid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('event_uid', $qb->createNamedParameter($eventUid)));

		return $qb->executeStatement();
	}//end deleteByObjectAndEvent()
}//end class
