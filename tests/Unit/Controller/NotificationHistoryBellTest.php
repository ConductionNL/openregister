<?php

/**
 * Unit tests for the bell's own verbs: snooze, archive and the thread read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
 */

declare(strict_types=1);

namespace Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\NotificationHistoryController;
use OCA\OpenRegister\Db\NotificationHistory;
use OCA\OpenRegister\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Notification\NotificationClearingService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Three verbs and the subject axis, through the controller a client reaches.
 *
 * @coversDefaultClass \OCA\OpenRegister\Controller\NotificationHistoryController
 */
class NotificationHistoryBellTest extends TestCase {

	/**
	 * The dispatched notices, mocked.
	 *
	 * @var NotificationHistoryMapper
	 */
	private NotificationHistoryMapper $mapper;

	/**
	 * The one writer of read, snooze and archive, mocked.
	 *
	 * @var NotificationClearingService
	 */
	private NotificationClearingService $clearing;

	/**
	 * The request, mocked.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(originalClassName: NotificationHistoryMapper::class);
		$this->clearing = $this->createMock(originalClassName: NotificationClearingService::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);

	}//end setUp()

	/**
	 * A controller acting as a non-admin user.
	 *
	 * @return NotificationHistoryController
	 */
	private function controller(): NotificationHistoryController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new NotificationHistoryController(
			'openregister',
			$this->request,
			$this->mapper,
			$session,
			$groups,
			$this->clearing
		);

	}//end controller()

	/**
	 * A notice with the given archive stamp.
	 *
	 * @return NotificationHistory
	 */
	private function notice(): NotificationHistory {
		$row = new NotificationHistory();
		$row->setRuleId('case-updated');
		$row->setRecipient('alice');
		$row->setObjectUuid('uuid-case-1');
		$row->setChannel('nc-notification');
		$row->setStatus('dispatched');
		$row->setDispatchedAt(new DateTime('2026-09-10T09:00:00+00:00'));
		$row->setArchivedAt(new DateTime('2026-09-14T09:00:00+00:00'));

		return $row;

	}//end notice()

	/**
	 * Archiving answers the notice with the archive visible and the read
	 * state untouched.
	 *
	 * @return void
	 */
	public function testArchivingLeavesTheNoticeUnread(): void {
		$this->clearing->method('archive')->willReturn($this->notice());

		$data = $this->controller()->archive(id: 42)->getData();

		$this->assertNotNull($data['archivedAt']);
		$this->assertFalse($data['read']);
		$this->assertNull($data['readAt']);

	}//end testArchivingLeavesTheNoticeUnread()

	/**
	 * Archiving somebody else's notice is a 403.
	 *
	 * @return void
	 */
	public function testArchivingSomebodyElsesNoticeIsForbidden(): void {
		$this->clearing->method('archive')
			->willThrowException(new NotAuthorizedException(message: 'not yours'));

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$this->controller()->archive(id: 42)->getStatus()
		);

	}//end testArchivingSomebodyElsesNoticeIsForbidden()

	/**
	 * A snooze without a moment is refused, not silently applied as "now".
	 *
	 * @return void
	 */
	public function testSnoozingWithoutAMomentIsRefused(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->clearing->expects($this->never())->method('snooze');

		$response = $this->controller()->snooze(id: 42);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('snoozed-until-required', $response->getData()['error']);

	}//end testSnoozingWithoutAMomentIsRefused()

	/**
	 * A snooze with a moment reaches the one writer.
	 *
	 * @return void
	 */
	public function testSnoozingPassesTheMomentThrough(): void {
		$this->request->method('getParam')->willReturn('2026-09-15T09:00:00+00:00');
		$this->clearing->expects($this->once())
			->method('snooze')
			->with(
				$this->equalTo(42),
				$this->callback(
					static fn (DateTime $until): bool => $until->format('Y-m-d') === '2026-09-15'
				)
			)
			->willReturn($this->notice());

		$this->assertSame(Http::STATUS_OK, $this->controller()->snooze(id: 42)->getStatus());

	}//end testSnoozingPassesTheMomentThrough()

	/**
	 * A thread read without a subject is refused rather than clearing the bell.
	 *
	 * @return void
	 */
	public function testAThreadReadWithoutASubjectIsRefused(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->clearing->expects($this->never())->method('markThreadRead');

		$response = $this->controller()->markThreadRead();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('object-uuid-required', $response->getData()['error']);

	}//end testAThreadReadWithoutASubjectIsRefused()

	/**
	 * A thread read answers how many notices it cleared.
	 *
	 * @return void
	 */
	public function testAThreadReadAnswersWhatItCleared(): void {
		$this->request->method('getParam')->willReturn('uuid-case-1');
		$this->clearing->method('markThreadRead')->willReturn(4);

		$this->assertSame(4, $this->controller()->markThreadRead()->getData()['cleared']);

	}//end testAThreadReadAnswersWhatItCleared()

	/**
	 * A snoozed notice is absent today and back afterwards, still unread.
	 *
	 * The clock is a fixture rather than the wall clock, which is what lets both
	 * halves of the scenario be asserted in one run: a test that waited for the
	 * snooze to expire would either sleep through a day or assert only the half
	 * that happens to be true now.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function testASnoozedNoticeComesBack(): void {
		$row = new NotificationHistory();
		$row->setRecipient('alice');
		$row->setDispatchedAt(new DateTime('2026-09-10T09:00:00+00:00'));
		$row->setSnoozedUntil(new DateTime('2026-09-15T09:00:00+00:00'));

		$this->assertFalse(
			$row->isInUnreadListAt(new DateTime('2026-09-14T09:00:00+00:00')),
			'a notice snoozed until tomorrow is absent from the unread list today'
		);
		$this->assertTrue(
			$row->isInUnreadListAt(new DateTime('2026-09-16T09:00:00+00:00')),
			'and is back afterwards'
		);
		$this->assertNull($row->getReadAt(), 'a snooze never reads the notice');

	}//end testASnoozedNoticeComesBack()

	/**
	 * An archived notice leaves the list without being read, at any moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function testAnArchivedNoticeNeverComesBack(): void {
		$row = $this->notice();

		$this->assertFalse($row->isInUnreadListAt(new DateTime('2026-09-14T09:00:00+00:00')));
		$this->assertFalse($row->isInUnreadListAt(new DateTime('2027-09-14T09:00:00+00:00')));
		$this->assertNull($row->getReadAt());

	}//end testAnArchivedNoticeNeverComesBack()

	/**
	 * The bell is filtered by what a notice is about, and its count agrees.
	 *
	 * The assertion is on BOTH reads: a subject filter that narrows the page
	 * but not the total is the shape in which a filtered list reads as a paging
	 * bug, and it is why the filter map is one constant rather than two copies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-may-be-snoozed-or-archived-and-the-list-has-an-axis-req-ors-004
	 */
	public function testTheListIsNarrowedBySubjectTypeOnBothReads(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['subjectType', null, 'document'],
				['limit', null, null],
				['offset', null, null],
			]
		);

		$seen = [];
		$this->mapper->method('findFiltered')->willReturnCallback(
			static function (array $filters) use (&$seen): array {
				$seen['find'] = $filters;
				return [];
			}
		);
		$this->mapper->method('countFiltered')->willReturnCallback(
			static function (array $filters) use (&$seen): int {
				$seen['count'] = $filters;
				return 0;
			}
		);

		$this->controller()->index();

		$this->assertSame('document', ($seen['find']['subjectType'] ?? null));
		$this->assertSame($seen['find'], $seen['count']);

	}//end testTheListIsNarrowedBySubjectTypeOnBothReads()
}//end class
