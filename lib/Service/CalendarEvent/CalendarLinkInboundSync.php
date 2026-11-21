<?php

/**
 * Keeps calendar link rows in step with changes made on the calendar side.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\CalendarEvent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\CalendarEvent;

use DateTime;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapperInterface;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Throwable;

/**
 * A VEVENT is relevant when it carries the OpenRegister object tag or when a
 * link row names its UID; anything else costs one string check and, without
 * the tag, one indexed lookup by UID. Link rows are matched on the calendar
 * the event was on, so a UID reused on another calendar is left alone. Each
 * row is written on its own: one row that cannot be saved is logged and does
 * not stop the others.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Sabre\VObject\Reader::read() is a static factory.
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */
class CalendarLinkInboundSync {

	/**
	 * The linked-by stamp on rows this sync creates.
	 *
	 * @var string
	 */
	public const LINKED_BY = 'system:calendar';

	/**
	 * Constructor.
	 *
	 * @param CalendarLinkMapperInterface $linkMapper The link table.
	 * @param LoggerInterface    $logger     Reports rows that could not be written.
	 */
	public function __construct(
		private readonly CalendarLinkMapperInterface $linkMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refresh the cached fields of every link row of an updated or moved event.
	 *
	 * @param InboundCalendarObject $object The object as it now is (its calendar is the target).
	 * @param InboundCalendarObject $source The object where it was (same as $object unless it moved).
	 *
	 * @return int The number of rows refreshed.
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public function refresh(InboundCalendarObject $object, InboundCalendarObject $source): int {
		$rows = $this->rowsFor(object: $source);
		if ($rows === []) {
			return 0;
		}

		$vevent = $this->veventOf(object: $object);
		if ($vevent === null) {
			return 0;
		}

		$refreshed = 0;
		foreach ($rows as $row) {
			$this->applyEvent(link: $row, vevent: $vevent, object: $object);
			$refreshed += (int) $this->write(link: $row, operation: 'refresh');
		}

		return $refreshed;
	}//end refresh()

	/**
	 * Ensure a tagged event has its link row (create and restore).
	 *
	 * @param InboundCalendarObject $object The created object.
	 *
	 * @return bool Whether a row was inserted.
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public function ensureLinked(InboundCalendarObject $object): bool {
		if ($object->isTagged() === false) {
			return false;
		}

		$vevent = $this->veventOf(object: $object);
		if ($vevent === null) {
			return false;
		}

		$objectUuid = trim((string) ($vevent->{InboundCalendarObject::OBJECT_TAG} ?? ''));
		$eventUid   = trim((string) ($vevent->UID ?? ''));
		if ($objectUuid === '' || $eventUid === '') {
			return false;
		}

		// Idempotent (REQ-CAL-INB-003): the row may already be there.
		if ($this->linkMapper->findLink(objectUuid: $objectUuid, eventUid: $eventUid, calendarUri: $object->calendarUri) !== null) {
			return false;
		}

		$link = new CalendarLink();
		$link->setObjectUuid($objectUuid);
		$link->setRegisterId((int) (string) ($vevent->{'X-OPENREGISTER-REGISTER'} ?? '0'));
		$link->setSchemaId((int) (string) ($vevent->{'X-OPENREGISTER-SCHEMA'} ?? '0'));
		$link->setEventUid($eventUid);
		$link->setLinkedBy(self::LINKED_BY);
		$link->setLinkedAt(new DateTime());
		$link->setTaggedWithXor(true);
		$this->applyEvent(link: $link, vevent: $vevent, object: $object);

		try {
			$this->linkMapper->insert(entity: $link);
		} catch (Throwable $e) {
			// A concurrent writer may have inserted the same triple first; the
			// unique index refuses the second, which is the intended outcome.
			$this->logger->info(
				'Calendar link for event ' . $eventUid . ' not inserted: ' . $e->getMessage(),
				['exception' => $e]
			);
			return false;
		}

		return true;
	}//end ensureLinked()

	/**
	 * Delete every link row of a deleted event.
	 *
	 * @param InboundCalendarObject $object The deleted object.
	 *
	 * @return int The number of rows deleted.
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public function remove(InboundCalendarObject $object): int {
		$removed = 0;
		foreach ($this->rowsFor(object: $object) as $row) {
			try {
				$this->linkMapper->delete(entity: $row);
				$removed++;
			} catch (Throwable $e) {
				$this->logFailure(link: $row, operation: 'delete', error: $e);
			}
		}

		return $removed;
	}//end remove()

	/**
	 * The link rows of the object's UID that sit on the object's calendar.
	 *
	 * A row matches on its calendar id; a row without one (written before the id
	 * was recorded) matches on its calendar uri, or on an empty uri.
	 *
	 * @param InboundCalendarObject $object The object.
	 *
	 * @return array<int, CalendarLink>
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-reverse-event-to-objects-lookup-req-cal-inb-002
	 */
	private function rowsFor(InboundCalendarObject $object): array {
		$uid = $object->uid();
		if ($uid === null) {
			return [];
		}

		$rows = [];
		foreach ($this->linkMapper->findByEventUid(eventUid: $uid) as $row) {
			$calendarId = $row->getCalendarId();
			$onCalendar = ($calendarId === $object->calendarId);
			if ($calendarId === null || $calendarId === 0) {
				$uri        = (string) $row->getCalendarUri();
				$onCalendar = ($uri === '' || $uri === $object->calendarUri);
			}

			if ($onCalendar === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsFor()

	/**
	 * The object's first VEVENT, or null when it has none or cannot be parsed.
	 *
	 * @param InboundCalendarObject $object The object.
	 *
	 * @return mixed The VEVENT component, or null.
	 */
	private function veventOf(InboundCalendarObject $object): mixed {
		if ($object->calendarText === '') {
			return null;
		}

		try {
			$document = Reader::read($object->calendarText);
		} catch (Throwable $e) {
			$this->logger->info('Calendar object ' . $object->eventUri . ' could not be parsed: ' . $e->getMessage());
			return null;
		}

		if (($document instanceof VCalendar) === false || isset($document->VEVENT) === false) {
			return null;
		}

		return $document->VEVENT;
	}//end veventOf()

	/**
	 * Copy the event's cached fields and location onto a link row.
	 *
	 * @param CalendarLink          $link   The row.
	 * @param mixed                 $vevent The VEVENT.
	 * @param InboundCalendarObject $object Where the event now is.
	 *
	 * @return void
	 */
	private function applyEvent(CalendarLink $link, mixed $vevent, InboundCalendarObject $object): void {
		$link->setSummary(self::text(vevent: $vevent, property: 'SUMMARY'));
		$link->setLocation(self::text(vevent: $vevent, property: 'LOCATION'));
		$link->setDtstart(self::date(vevent: $vevent, property: 'DTSTART'));
		$link->setDtend(self::date(vevent: $vevent, property: 'DTEND'));
		$link->setCalendarId($object->calendarId);
		$link->setCalendarUri($object->calendarUri);
		$link->setEventUri($object->eventUri);
	}//end applyEvent()

	/**
	 * Save a refreshed row, logging a failure instead of throwing.
	 *
	 * @param CalendarLink $link      The row.
	 * @param string       $operation What was attempted, for the log.
	 *
	 * @return bool Whether it was saved.
	 */
	private function write(CalendarLink $link, string $operation): bool {
		try {
			$this->linkMapper->update(entity: $link);
			return true;
		} catch (Throwable $e) {
			$this->logFailure(link: $link, operation: $operation, error: $e);
			return false;
		}
	}//end write()

	/**
	 * Log a row that could not be written.
	 *
	 * @param CalendarLink $link      The row.
	 * @param string       $operation What was attempted.
	 * @param Throwable    $error     Why it failed.
	 *
	 * @return void
	 */
	private function logFailure(CalendarLink $link, string $operation, Throwable $error): void {
		$this->logger->warning(
			'Calendar link ' . $operation . ' failed for event ' . (string) $link->getEventUid() . ': ' . $error->getMessage(),
			['exception' => $error]
		);
	}//end logFailure()

	/**
	 * A text property, or null when absent or empty.
	 *
	 * @param mixed  $vevent   The VEVENT.
	 * @param string $property The property name.
	 *
	 * @return string|null
	 */
	private static function text(mixed $vevent, string $property): ?string {
		if (isset($vevent->$property) === false) {
			return null;
		}

		$value = (string) $vevent->$property;
		if ($value === '') {
			return null;
		}

		return $value;
	}//end text()

	/**
	 * A date property as a mutable DateTime, or null when absent.
	 *
	 * @param mixed  $vevent   The VEVENT.
	 * @param string $property The property name.
	 *
	 * @return DateTime|null
	 */
	private static function date(mixed $vevent, string $property): ?DateTime {
		if (isset($vevent->$property) === false) {
			return null;
		}

		return DateTime::createFromImmutable($vevent->$property->getDateTime());
	}//end date()
}//end class
