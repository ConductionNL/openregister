<?php

/**
 * Unit tests for the read-state endpoints.
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
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectReadStateController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Notification\NotificationClearingService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The contract of the three verbs, and the two refusals.
 *
 * @coversDefaultClass \OCA\OpenRegister\Controller\ObjectReadStateController
 */
class ObjectReadStateControllerTest extends TestCase {

	/**
	 * The read-state primitive, mocked.
	 *
	 * @var ReadStateService
	 */
	private ReadStateService $readState;

	/**
	 * The bell, mocked.
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
		$this->readState = $this->createMock(originalClassName: ReadStateService::class);
		$this->clearing = $this->createMock(originalClassName: NotificationClearingService::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);

	}//end setUp()

	/**
	 * A controller whose object service resolves, or refuses to.
	 *
	 * @param boolean $resolves Whether the object resolves through RBAC.
	 *
	 * @return ObjectReadStateController
	 */
	private function controller(bool $resolves = true): ObjectReadStateController {
		$objectService = $this->createMock(originalClassName: ObjectService::class);

		if ($resolves === true) {
			$object = $this->createMock(originalClassName: ObjectEntity::class);
			$object->method('getUuid')->willReturn('uuid-case-1');
			$objectService->method('getObject')->willReturn($object);
		} else {
			// An object the caller cannot READ never resolves, which is how the
			// endpoint refuses with 404 rather than leaking that it exists.
			$objectService->method('getObject')->willReturn(null);
		}

		return new ObjectReadStateController(
			appName: 'openregister',
			request: $this->request,
			objectService: $objectService,
			readState: $this->readState,
			clearing: $this->clearing,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end controller()

	/**
	 * A stored read state row.
	 *
	 * @return ObjectReadState
	 */
	private function row(): ObjectReadState {
		$row = new ObjectReadState();
		$row->setUserId('alice');
		$row->setObjectUuid('uuid-case-1');
		$row->setLastSeenAt(new \DateTime('2026-09-12T09:00:00+00:00'));
		$row->setSubSeen([]);

		return $row;

	}//end row()

	/**
	 * Marking read stores the state and empties the bell for that object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function testMarkingReadAlsoClearsTheNotices(): void {
		$this->readState->method('markRead')->willReturn($this->row());
		$this->clearing->expects($this->once())
			->method('clearForObject')
			->with($this->equalTo('uuid-case-1'))
			->willReturn(3);

		$response = $this->controller()->markRead(register: 'cases', schema: 'case', id: 'uuid-case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3, $response->getData()['notificationsCleared']);
		$this->assertFalse($response->getData()['unread']);

	}//end testMarkingReadAlsoClearsTheNotices()

	/**
	 * Naming a sub-resource clears that tab and no other.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function testOpeningATabClearsOnlyThatTabsNotices(): void {
		$this->request->method('getParam')->willReturn('files');
		$this->readState->method('markRead')->willReturn($this->row());

		$this->clearing->expects($this->never())->method('clearForObject');
		$this->clearing->expects($this->once())
			->method('clearForSubResource')
			->with($this->equalTo('uuid-case-1'), $this->equalTo('files'))
			->willReturn(1);

		$this->controller()->markRead(register: 'cases', schema: 'case', id: 'uuid-case-1');

	}//end testOpeningATabClearsOnlyThatTabsNotices()

	/**
	 * Marking back to unread answers the new marker.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testMarkingUnreadAnswersTheNewMarker(): void {
		$this->readState->expects($this->once())->method('markUnread')->willReturn(true);

		$response = $this->controller()->markUnread(register: 'cases', schema: 'case', id: 'uuid-case-1');

		$this->assertTrue($response->getData()['unread']);

	}//end testMarkingUnreadAnswersTheNewMarker()

	/**
	 * Asking about another user's read state is refused with 403.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testAnotherUsersReadStateIsForbidden(): void {
		$this->request->method('getParam')->willReturn('bob');
		$this->readState->method('readStateFor')
			->willThrowException(new NotAuthorizedException(message: 'not yours'));

		$response = $this->controller()->show(register: 'cases', schema: 'case', id: 'uuid-case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAnotherUsersReadStateIsForbidden()

	/**
	 * An object the caller cannot read is a 404, never a 403.
	 *
	 * Existence is not leaked: a 403 here would tell an unauthorised caller
	 * which object ids are real, which is the probe these endpoints must not
	 * become.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testAnUnreadableObjectIsNotFound(): void {
		$this->readState->expects($this->never())->method('markRead');

		$response = $this->controller(resolves: false)
			->markRead(register: 'cases', schema: 'case', id: 'uuid-case-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnUnreadableObjectIsNotFound()

	/**
	 * The read state answers the tab badges as one map.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function testTheReadStateCarriesTheTabBadges(): void {
		$this->readState->method('readStateFor')->willReturn($this->row());
		$this->readState->method('unreadCounts')->willReturn(['files' => 2, 'messages' => 1]);

		$data = $this->controller()->show(register: 'cases', schema: 'case', id: 'uuid-case-1')->getData();

		$this->assertFalse($data['unread']);
		$this->assertSame(['files' => 2, 'messages' => 1], $data['unreadCounts']);

	}//end testTheReadStateCarriesTheTabBadges()
}//end class
