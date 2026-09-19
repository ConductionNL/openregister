<?php

/**
 * Who has an object open, on a clock the test controls.
 *
 * 🔴 THE PROPERTY THAT MATTERS IS WHICH BEATS ARE SILENT. A push on every
 * heartbeat would make twenty readers on one page twenty pushes a minute to
 * twenty clients, so `heartbeat()` reports whether the beat was an ARRIVAL and
 * the endpoint pushes only then. A test that only checked "the row was written"
 * passes in both worlds, so the assertions here are about the `arrived` flag.
 *
 * 🔴 AN EXPIRED ROW IS AN ARRIVAL, NOT A RENEWAL. A reader whose laptop slept
 * for an hour had gone from everybody else's list, and is now back on it. Read
 * as a renewal they would be permanently invisible to every client that was
 * pushed their departure, which looks exactly like working.
 *
 * 🔑 THE WINDOW IS ASSERTED AGAINST THE CONSTANT, NOT AGAINST 90. Writing the
 * number down twice is how a tuned window leaves a test asserting the old one
 * while still passing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectPresence;
use OCA\OpenRegister\Db\ObjectPresenceMapper;
use OCA\OpenRegister\Service\PresenceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\PresenceService
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */
final class PresenceServiceTest extends TestCase {

	private const OBJ = 'obj-1111';

	private ObjectPresenceMapper&MockObject $mapper;

	/**
	 * The rows the fake store holds, keyed by "user|object".
	 *
	 * @var array<string, ObjectPresence>
	 */
	private array $rows = [];

	/**
	 * A mapper backed by an in-memory row set.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->rows = [];
		$this->mapper = $this->createMock(ObjectPresenceMapper::class);

		$this->mapper->method('findOne')->willReturnCallback(
			fn (string $userId, string $objectUuid): ?ObjectPresence
				=> ($this->rows[$userId . '|' . $objectUuid] ?? null)
		);
		$this->mapper->method('insert')->willReturnCallback(
			function (ObjectPresence $row): ObjectPresence {
				$this->rows[$row->getUserId() . '|' . $row->getObjectUuid()] = $row;
				return $row;
			}
		);
		$this->mapper->method('update')->willReturnCallback(
			function (ObjectPresence $row): ObjectPresence {
				$this->rows[$row->getUserId() . '|' . $row->getObjectUuid()] = $row;
				return $row;
			}
		);
	}

	/**
	 * The service under test.
	 *
	 * @return PresenceService The service.
	 */
	private function service(): PresenceService {
		return new PresenceService($this->mapper, new NullLogger());
	}

	/**
	 * A moment, as an immutable clock the tests advance by hand.
	 *
	 * @param int $offsetSeconds Seconds from the fixed base.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function at(int $offsetSeconds): DateTimeImmutable {
		$base = new DateTimeImmutable('2026-09-18T12:00:00+00:00', new \DateTimeZone('UTC'));

		return $base->modify('+' . $offsetSeconds . ' seconds');
	}

	/**
	 * 🔴 The first beat is an arrival.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function testTheFirstBeatIsAnArrival(): void {
		$beat = $this->service()->heartbeat(userId: 'anna', objectUuid: self::OBJ, now: $this->at(0));

		self::assertTrue($beat['arrived']);
		self::assertSame('anna', $beat['presence']->getUserId());
	}

	/**
	 * 🔴 A beat inside the window is SILENT: it is not an arrival.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
	 */
	public function testABeatInsideTheWindowIsNotAnArrival(): void {
		$service = $this->service();
		$service->heartbeat(userId: 'anna', objectUuid: self::OBJ, now: $this->at(0));

		$renewal = $service->heartbeat(
			userId: 'anna',
			objectUuid: self::OBJ,
			now: $this->at(PresenceService::BEAT_SECONDS)
		);

		self::assertFalse(
			$renewal['arrived'],
			'a renewal that changed nothing must not be pushed'
		);
	}

	/**
	 * 🔴 The arrival time survives a renewal.
	 *
	 * A reader with the page open for an hour and one who just opened it are
	 * different facts to whoever is deciding whether to start typing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function testTheArrivalTimeSurvivesARenewal(): void {
		$service = $this->service();
		$first = $service->heartbeat(userId: 'anna', objectUuid: self::OBJ, now: $this->at(0));
		$arrived = $first['presence']->getArrivedAt()->getTimestamp();

		$renewal = $service->heartbeat(
			userId: 'anna',
			objectUuid: self::OBJ,
			now: $this->at(PresenceService::BEAT_SECONDS)
		);

		self::assertSame($arrived, $renewal['presence']->getArrivedAt()->getTimestamp());
		self::assertNotSame(
			$arrived,
			$renewal['presence']->getLastSeen()->getTimestamp(),
			'but the last beat moved'
		);
	}

	/**
	 * 🔴 A beat after the window is an arrival again, not a renewal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
	 */
	public function testABeatAfterTheWindowIsAnArrivalAgain(): void {
		$service = $this->service();
		$service->heartbeat(userId: 'anna', objectUuid: self::OBJ, now: $this->at(0));

		$back = $service->heartbeat(
			userId: 'anna',
			objectUuid: self::OBJ,
			now: $this->at(PresenceService::WINDOW_SECONDS + 1)
		);

		self::assertTrue(
			$back['arrived'],
			'they had gone from everybody else\'s list, so coming back is an arrival'
		);
	}

	/**
	 * The window is exactly the constant, at its edge.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function testTheEdgeOfTheWindowIsStillPresent(): void {
		$service = $this->service();
		$service->heartbeat(userId: 'anna', objectUuid: self::OBJ, now: $this->at(0));

		$edge = $service->heartbeat(
			userId: 'anna',
			objectUuid: self::OBJ,
			now: $this->at(PresenceService::WINDOW_SECONDS)
		);

		self::assertFalse($edge['arrived'], 'the window is inclusive at its edge');
	}

	/**
	 * The cutoff is the window behind the clock, read from the constant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function testTheCutoffIsTheWindowBehindTheClock(): void {
		$now = $this->at(0);

		self::assertSame(
			($now->getTimestamp() - PresenceService::WINDOW_SECONDS),
			$this->service()->cutoff(now: $now)->getTimestamp()
		);
	}

	/**
	 * 🔴 The caller is left out of their own list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function testTheCallerIsLeftOutOfTheirOwnList(): void {
		$this->mapper->method('findPresent')->willReturn(
			[$this->row(user: 'anna'), $this->row(user: 'bram')]
		);

		$present = $this->service()->present(objectUuid: self::OBJ, exceptUser: 'anna', now: $this->at(0));

		self::assertSame(['bram'], array_column($present, 'user'));
	}

	/**
	 * 🔴 The expiry READS the stale rows before it deletes them.
	 *
	 * A departure has to be pushed, and a row already gone cannot say who to
	 * push about. That is the whole reason this is not a one-line DELETE.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
	 */
	public function testExpiryNamesWhoWentBeforeDeletingThem(): void {
		$this->mapper->method('findStale')->willReturn([$this->row(user: 'anna')]);
		$this->mapper->expects(self::once())->method('pruneStale');

		$gone = $this->service()->expire(now: $this->at(0));

		self::assertSame([['user' => 'anna', 'object' => self::OBJ]], $gone);
	}

	/**
	 * Nothing stale means nothing is deleted and nothing is pushed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
	 */
	public function testNothingStaleSweepsNothing(): void {
		$this->mapper->method('findStale')->willReturn([]);
		$this->mapper->expects(self::never())->method('pruneStale');

		self::assertSame([], $this->service()->expire(now: $this->at(0)));
	}

	/**
	 * A departure that removed nothing reports false, so nothing is pushed.
	 *
	 * A page unmounting twice is ordinary, and there is no change to tell
	 * anybody about.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
	 */
	public function testADepartureBySomebodyWhoWasNotThereIsNotAChange(): void {
		$this->mapper->method('removeOne')->willReturn(false);

		self::assertFalse($this->service()->depart(userId: 'anna', objectUuid: self::OBJ));
	}

	/**
	 * 🔴 A beat that cannot be written drops the reader, and does not fail the page.
	 *
	 * They fall off the list in one window, which is the same outcome as a lost
	 * network, and a detail page must not 500 because a presence table is full.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-an-object-knows-who-has-it-open
	 */
	public function testAnUnwritableBeatIsNotAnArrivalAndDoesNotThrow(): void {
		$mapper = $this->createMock(ObjectPresenceMapper::class);
		$mapper->method('findOne')->willReturn(null);
		$mapper->method('insert')->willThrowException(new RuntimeException('table is gone'));

		$beat = (new PresenceService($mapper, new NullLogger()))
			->heartbeat(userId: 'anna', objectUuid: self::OBJ, now: $this->at(0));

		self::assertFalse($beat['arrived'], 'nothing changed, so nothing is pushed');
		self::assertNull($beat['presence']);
	}

	/**
	 * A row for one reader.
	 *
	 * @param string $user The reader.
	 *
	 * @return ObjectPresence The row.
	 */
	private function row(string $user): ObjectPresence {
		$row = new ObjectPresence();
		$row->setUserId($user);
		$row->setObjectUuid(self::OBJ);
		$row->setArrivedAt(new DateTime());
		$row->setLastSeen(new DateTime());

		return $row;
	}
}//end class
