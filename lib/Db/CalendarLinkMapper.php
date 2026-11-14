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
	 * The link rows attached to a VEVENT, oldest first.
	 *
	 * Scoped to one calendar when `$calendarUri` is given, so a UID reused on
	 * another calendar does not match (REQ-CAL-INB-002).
	 *
	 * @param string      $eventUid    The VEVENT UID.
	 * @param string|null $calendarUri The calendar URI, or null for every calendar.
	 *
	 * @return CalendarLink[] The matching rows.
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-reverse-event-to-objects-lookup-req-cal-inb-002
	 */
	public function findByEventUid(string $eventUid, ?string $calendarUri=null): array {
		$qb = $this->selectWhere(column: new LinkColumn(name: 'event_uid', value: $eventUid));
		if ($calendarUri !== null) {
			$qb->andWhere($qb->expr()->eq('calendar_uri', $qb->createNamedParameter($calendarUri)));
		}

		$qb->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByEventUid()

	/**
	 * The oldest link row for an object, event and calendar, or null when there is none.
	 *
	 * Reads the oldest row rather than requiring exactly one, so rows duplicated
	 * before linking became idempotent still resolve (REQ-CAL-INB-003).
	 *
	 * @param string $objectUuid  The object UUID.
	 * @param string $eventUid    The VEVENT UID.
	 * @param string $calendarUri The calendar URI.
	 *
	 * @return CalendarLink|null The row.
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-linking-is-idempotent-req-cal-inb-003
	 */
	public function findLink(string $objectUuid, string $eventUid, string $calendarUri): ?CalendarLink {
		$qb = $this->selectWhere(column: new LinkColumn(name: 'object_uuid', value: $objectUuid));
		$qb->andWhere($qb->expr()->eq('event_uid', $qb->createNamedParameter($eventUid)))
			->andWhere($qb->expr()->eq('calendar_uri', $qb->createNamedParameter($calendarUri)))
			->orderBy('id', 'ASC')
			->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);
		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end findLink()

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
