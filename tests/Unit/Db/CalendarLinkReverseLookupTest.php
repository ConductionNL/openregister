<?php

/**
 * Unit tests for CalendarLinkMapper's reverse lookup and single-link read.
 *
 * The lookup findByEventUid() reads every row of one VEVENT, optionally scoped
 * to one calendar, oldest first; findLink() reads the oldest row of one object,
 * event and calendar, or null.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapper;
use PHPUnit\Framework\TestCase;

/**
 * The event-to-objects lookup and the idempotency read of the link table.
 */
class CalendarLinkReverseLookupTest extends TestCase {
	use RecordsQueries;

	/**
	 * A stored link row.
	 *
	 * @param int    $id         The row id.
	 * @param string $objectUuid The object.
	 *
	 * @return array<string, mixed>
	 */
	private function row(int $id, string $objectUuid): array {
		return ['id' => $id, 'object_uuid' => $objectUuid, 'event_uid' => 'E1', 'calendar_uri' => 'personal'];
	}//end row()

	/**
	 * Without a calendar the lookup reads every row of the event, oldest first.
	 *
	 * @return void
	 */
	public function testFindByEventUidReadsEveryCalendar(): void {
		$this->results = [[$this->row(3, 'obj-a'), $this->row(5, 'obj-b')]];

		$links = (new CalendarLinkMapper($this->recordingDb()))->findByEventUid('E1');

		$this->assertSame(['obj-a', 'obj-b'], array_map(static fn (CalendarLink $link): ?string => $link->getObjectUuid(), $links));
		$this->assertSame(
			'select(*) from(openregister_calendar_links) where(eq(event_uid, :p0)) orderBy(id, ASC) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['E1'], $this->boundValues());
	}//end testFindByEventUidReadsEveryCalendar()

	/**
	 * With a calendar the lookup is scoped to it.
	 *
	 * @return void
	 */
	public function testFindByEventUidScopesToTheCalendar(): void {
		$this->results = [[]];

		$links = (new CalendarLinkMapper($this->recordingDb()))->findByEventUid('E1', 'work');

		$this->assertSame([], $links);
		$this->assertSame(
			'select(*) from(openregister_calendar_links) where(eq(event_uid, :p0)) andWhere(eq(calendar_uri, :p1)) orderBy(id, ASC) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['E1', 'work'], $this->boundValues());
	}//end testFindByEventUidScopesToTheCalendar()

	/**
	 * findLink() reads the oldest row of the object, event and calendar.
	 *
	 * @return void
	 */
	public function testFindLinkReadsTheOldestRowOfTheTriple(): void {
		$this->results = [[$this->row(3, 'obj-a')]];

		$link = (new CalendarLinkMapper($this->recordingDb()))->findLink('obj-a', 'E1', 'personal');

		$this->assertSame(3, $link?->getId());
		$this->assertSame(
			'select(*) from(openregister_calendar_links) where(eq(object_uuid, :p0)) andWhere(eq(event_uid, :p1)) '
			.'andWhere(eq(calendar_uri, :p2)) orderBy(id, ASC) setMaxResults(1) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['obj-a', 'E1', 'personal'], $this->boundValues());
	}//end testFindLinkReadsTheOldestRowOfTheTriple()

	/**
	 * findLink() answers null when the triple has no row.
	 *
	 * @return void
	 */
	public function testFindLinkIsNullWithoutARow(): void {
		$this->results = [[]];

		$this->assertNull((new CalendarLinkMapper($this->recordingDb()))->findLink('obj-a', 'E1', 'personal'));
	}//end testFindLinkIsNullWithoutARow()
}//end class
