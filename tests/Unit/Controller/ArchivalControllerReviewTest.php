<?php

declare(strict_types=1);

/**
 * ArchivalController review endpoint tests.
 *
 * The sign-off half of the archiving process: assigning an entry to a person,
 * answering your own entry, being refused somebody else's, and reading what is
 * waiting on you across every open list.
 *
 * 🔴 THE REFUSAL IS PROBED WITH AN ARCHIVIST, NOT A STRANGER. `decideEntry` is
 * `#[NoAdminRequired]`, so the question that matters is not "can an outsider
 * sign off a destruction" but "can a second archivist sign off the entry a
 * first one was made accountable for". A test that only refuses the outsider
 * would pass on a controller that let any archivist answer anything, which is
 * exactly the group-addressed approval this change replaces.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the review endpoints of ArchivalController.
 */
class ArchivalControllerReviewTest extends TestCase {

	private IRequest&MockObject $request;
	private DestructionListRepository&MockObject $lists;
	private ReviewOutcomeService&MockObject $outcomes;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private ArchivalController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->lists = $this->createMock(DestructionListRepository::class);
		$this->outcomes = $this->createMock(ReviewOutcomeService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new ArchivalController(
			'openregister',
			$this->request,
			$this->createMock(DestructionService::class),
			$this->createMock(LegalHoldService::class),
			$this->getMockBuilder(MagicMapper::class)
				->disableOriginalConstructor()
				->onlyMethods(['update', 'find'])
				->getMock(),
			$this->userSession,
			$this->groupManager,
			$this->createMock(LoggerInterface::class),
			$this->lists,
			new DestructionReviewService(),
			$this->outcomes,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ArchivalNominationService::class),
			$this->createMock(SchemaMapper::class)
		);
	}

	/**
	 * Sign somebody in, optionally as an archivist.
	 *
	 * @param string $uid         The user id.
	 * @param bool   $isArchivist Whether they are in the archivaris group.
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $isArchivist = true): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn($isArchivist);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}

	/**
	 * A destruction list object holding one assigned entry and one unassigned.
	 *
	 * @param string $uuid     The list uuid.
	 * @param string $reviewer Who obj-1 is assigned to.
	 *
	 * @return ObjectEntity The list.
	 */
	private function listObject(string $uuid = 'dl-1', string $reviewer = 'els'): ObjectEntity {
		$list = new ObjectEntity();
		$list->setUuid($uuid);
		$list->setObject(
			[
				'status' => 'in_review',
				'objects' => [
					['uuid' => 'obj-1', 'title' => 'Bezwaar 2019/114', 'reviewer' => $reviewer],
					['uuid' => 'obj-2', 'title' => 'Bezwaar 2019/115'],
				],
				'decisions' => [],
			]
		);

		return $list;
	}

	/**
	 * Answer the request params one review decision needs.
	 *
	 * @param array<string, mixed> $params The params.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')
			->willReturnCallback(
				static function (string $key, $default = null) use ($params) {
					return ($params[$key] ?? $default);
				}
			);
	}

	public function testTheNamedReviewerMayAnswerTheirOwnEntry(): void {
		$this->signIn('els');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['answer' => 'destroy', 'reason' => 'Past its date, nothing holds it']);

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$decision = $response->getData()['decision'];
		$this->assertSame('destroy', $decision['answer']);
		$this->assertSame('els', $decision['reviewer']);
		$this->assertSame('Past its date, nothing holds it', $decision['reason']);
	}

	public function testASecondArchivistMayNotAnswerSomebodyElsesEntry(): void {
		$this->signIn('joris');
		$this->lists->method('find')->willReturn($this->listObject(reviewer: 'els'));
		$this->withParams(['answer' => 'destroy', 'reason' => 'Past its date']);

		$this->lists->expects($this->never())->method('save');

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAnEntryNobodyIsAccountableForRefusesRatherThanFallingOpen(): void {
		$this->signIn('els');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['answer' => 'destroy', 'reason' => 'Past its date']);

		$response = $this->controller->decideEntry('dl-1', 'obj-2');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertMatchesRegularExpression('/assign one/', $response->getData()['error']);
	}

	public function testAFourthAnswerIsRefusedBeforeAnythingIsCarriedOut(): void {
		$this->signIn('els');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['answer' => 'approve', 'reason' => 'Looks fine']);

		$this->outcomes->expects($this->never())->method('apply');

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testATransferRecordsTheListItWasHandedTo(): void {
		$this->signIn('els');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['answer' => 'transfer', 'reason' => 'Blijvend bewaren']);

		$this->outcomes->method('apply')->willReturn('transfer-list-7');

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('transfer-list-7', $response->getData()['decision']['transferListUuid']);
	}

	public function testAnEntryTheListDoesNotHoldIsNotFound(): void {
		$this->signIn('els');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['answer' => 'destroy', 'reason' => 'Past its date']);

		$response = $this->controller->decideEntry('dl-1', 'obj-99');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAssigningAReviewerNeedsTheArchivistRole(): void {
		$this->signIn('anneke', isArchivist: false);

		$response = $this->controller->assignReviewer('dl-1', 'obj-2');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAssigningAReviewerNamesTheRemainingUnassignedEntries(): void {
		$this->signIn('archivaris');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['reviewer' => 'joris']);

		$response = $this->controller->assignReviewer('dl-1', 'obj-2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('joris', $response->getData()['entry']['reviewer']);
		$this->assertSame([], $response->getData()['unassignedEntries']);
	}

	public function testAReviewerReadsTheirOwnPendingItemsAcrossLists(): void {
		$this->signIn('els');

		$second = new ObjectEntity();
		$second->setUuid('dl-2');
		$second->setObject(
			[
				'status' => 'in_review',
				'objects' => [
					['uuid' => 'obj-4', 'title' => 'Subsidie 2017/3', 'reviewer' => 'els'],
					['uuid' => 'obj-5', 'title' => 'Subsidie 2017/4', 'reviewer' => 'joris'],
				],
			]
		);

		$this->lists->method('findLists')->willReturn([$this->listObject(), $second]);

		$response = $this->controller->myPendingReviews();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('els', $data['reviewer']);
		$this->assertSame(2, $data['total']);
		$this->assertSame(['dl-1', 'dl-2'], array_column($data['results'], 'list'));
		$this->assertSame(['obj-1', 'obj-4'], array_column($data['results'], 'uuid'));
	}

	public function testAnAnonymousCallerIsRefusedTheWorklist(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->controller->myPendingReviews()->getStatus()
		);
	}
}
