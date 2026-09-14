<?php

/**
 * Unit tests for clearing, snoozing and archiving a user's own notices.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use DateTime;
use OCA\OpenRegister\Db\NotificationHistory;
use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Notification\NotificationClearingService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Doing the work empties the bell, and archiving is never reading.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Notification\NotificationClearingService
 */
class NotificationClearingServiceTest extends TestCase {

	/**
	 * The dispatched notices, mocked.
	 *
	 * @var NotificationHistoryMapper
	 */
	private NotificationHistoryMapper $mapper;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(originalClassName: NotificationHistoryMapper::class);

	}//end setUp()

	/**
	 * A service acting as the given user.
	 *
	 * @param string|null $uid The acting user's uid, or null for anonymous.
	 *
	 * @return NotificationClearingService
	 */
	private function serviceAs(?string $uid): NotificationClearingService {
		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new NotificationClearingService(
			mapper: $this->mapper,
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end serviceAs()

	/**
	 * A notice addressed to the given uid.
	 *
	 * @param string $recipient The uid it is addressed to.
	 *
	 * @return NotificationHistory
	 */
	private function notice(string $recipient = 'alice'): NotificationHistory {
		$row = new NotificationHistory();
		$row->setRuleId('case-updated');
		$row->setRecipient($recipient);
		$row->setObjectUuid('uuid-case-1');
		$row->setChannel('nc-notification');
		$row->setStatus('dispatched');
		$row->setDispatchedAt(new DateTime('2026-09-10T09:00:00+00:00'));

		return $row;

	}//end notice()

	/**
	 * Opening an object clears every notice this user holds about it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function testOpeningTheObjectClearsItsNotices(): void {
		$this->mapper->expects($this->once())
			->method('markReadForSubject')
			->with(
				$this->equalTo('alice'),
				$this->equalTo('uuid-case-1'),
				$this->equalTo(null)
			)
			->willReturn(3);

		$this->assertSame(3, $this->serviceAs('alice')->clearForObject(objectUuid: 'uuid-case-1'));

	}//end testOpeningTheObjectClearsItsNotices()

	/**
	 * Opening a sub-resource clears only that sub-resource's notices.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function testOpeningATabClearsOnlyThatTab(): void {
		$this->mapper->expects($this->once())
			->method('markReadForSubject')
			->with(
				$this->equalTo('alice'),
				$this->equalTo('uuid-case-1'),
				$this->equalTo('files')
			)
			->willReturn(1);

		$this->serviceAs('alice')->clearForSubResource(objectUuid: 'uuid-case-1', subjectId: 'files');

	}//end testOpeningATabClearsOnlyThatTab()

	/**
	 * An anonymous read clears nothing, and asks nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function testAnAnonymousReadClearsNothing(): void {
		$this->mapper->expects($this->never())->method('markReadForSubject');

		$this->assertSame(0, $this->serviceAs(null)->clearForObject(objectUuid: 'uuid-case-1'));

	}//end testAnAnonymousReadClearsNothing()

	/**
	 * Archiving touches the archive stamp and never the read stamp.
	 *
	 * This is the scenario in one assertion: `markRead` is forbidden on the
	 * mapper for the whole call. Without it, an archive that also wrote
	 * `read_at` would pass every other test here and quietly turn "I do not
	 * want to see this" into "I have dealt with this".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function testArchivingIsNotReading(): void {
		$this->mapper->method('findOwn')->willReturn($this->notice());
		$this->mapper->expects($this->never())->method('markRead');
		$this->mapper->expects($this->never())->method('markReadForSubject');
		$this->mapper->expects($this->once())
			->method('archive')
			->with($this->equalTo(42), $this->equalTo('alice'));

		$row = $this->serviceAs('alice')->archive(id: 42);

		$this->assertNull($row->getReadAt());
		$this->assertFalse($row->jsonSerialize()['read']);

	}//end testArchivingIsNotReading()

	/**
	 * Archiving somebody else's notice is refused, not silently ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function testArchivingSomebodyElsesNoticeIsRefused(): void {
		// The mapper scopes by recipient, so another user's notice does not
		// come back at all and the caller learns nothing about it.
		$this->mapper->method('findOwn')->willReturn(null);
		$this->mapper->expects($this->never())->method('archive');

		$this->expectException(NotAuthorizedException::class);

		$this->serviceAs('bob')->archive(id: 42);

	}//end testArchivingSomebodyElsesNoticeIsRefused()

	/**
	 * A snooze writes the moment and leaves the read state alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function testSnoozingLeavesTheNoticeUnread(): void {
		$this->mapper->method('findOwn')->willReturn($this->notice());
		$this->mapper->expects($this->never())->method('markRead');
		$this->mapper->expects($this->once())->method('snooze');

		$row = $this->serviceAs('alice')->snooze(id: 42, until: new DateTime('2026-09-15T09:00:00+00:00'));

		$this->assertNull($row->getReadAt());

	}//end testSnoozingLeavesTheNoticeUnread()

	/**
	 * A thread is marked read as a whole, by the same write as opening the work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function testAThreadIsMarkedReadAsAWhole(): void {
		$this->mapper->expects($this->once())
			->method('markReadForSubject')
			->with($this->equalTo('alice'), $this->equalTo('uuid-case-1'), $this->equalTo('files'))
			->willReturn(4);

		$this->assertSame(
			4,
			$this->serviceAs('alice')->markThreadRead(objectUuid: 'uuid-case-1', subjectId: 'files')
		);

	}//end testAThreadIsMarkedReadAsAWhole()

	/**
	 * A notice whose subject was deleted is archived, not marked read.
	 *
	 * Nobody read it, and nobody can, because the thing it points at is gone.
	 * Marking it read would be a claim about a person.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function testADeletedSubjectArchivesRatherThanReads(): void {
		$this->mapper->expects($this->never())->method('markReadForSubject');
		$this->mapper->expects($this->once())
			->method('archiveByObject')
			->with($this->equalTo('uuid-case-1'))
			->willReturn(2);

		$this->assertSame(2, $this->serviceAs('alice')->archiveOrphaned(objectUuid: 'uuid-case-1'));

	}//end testADeletedSubjectArchivesRatherThanReads()
}//end class
