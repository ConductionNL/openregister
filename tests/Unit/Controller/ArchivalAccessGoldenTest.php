<?php

/**
 * Golden outcomes of the archival endpoints that place holds, build lists and record review acts.
 *
 * Pins, on the controller as it was before these route actions were split into
 * small units, who is let through and who is refused, and what each refusal
 * answers: the archivist gate on listing, creating a legal hold, creating a
 * destruction list, assigning a reviewer and recomputing a nomination; the
 * signed-in check on answering an entry; and every input refusal on the way,
 * with nothing written when a request is refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\DestructionListCreator;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ArchivalControllerFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Archival\NominationRecompute;

/**
 * Golden allowed / refused outcomes of ArchivalController.
 */
class ArchivalAccessGoldenTest extends TestCase {

	private IRequest&MockObject $request;

	private LegalHoldService&MockObject $holds;

	private MagicMapper&MockObject $objectMapper;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	private DestructionListRepository&MockObject $lists;

	private AuditTrailMapper&MockObject $audit;

	private ArchivalNominationService&MockObject $nominations;

	private SchemaMapper&MockObject $schemaMapper;

	private DestructionListCreator&MockObject $creator;

	private ArchivalController $controller;

	/**
	 * The request parameters getParam() and getParams() answer.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->holds = $this->createMock(LegalHoldService::class);
		$this->objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['update', 'find'])
			->getMock();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->lists = $this->createMock(DestructionListRepository::class);
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->nominations = $this->createMock(ArchivalNominationService::class);
		$this->schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$this->creator = $this->createMock(DestructionListCreator::class);

		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);

		$this->controller = ArchivalControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(DestructionService::class),
			$this->holds,
			$this->objectMapper,
			$this->userSession,
			$this->groupManager,
			$this->createMock(LoggerInterface::class),
			$this->lists,
			new DestructionReviewService(),
			$this->createMock(ReviewOutcomeService::class),
			$this->audit,
			$this->nominations,
			$this->schemaMapper,
			$this->creator
		);
	}

	/**
	 * Sign somebody in.
	 *
	 * @param string $uid         The user id.
	 * @param bool   $isArchivist Whether they are in the archivaris group.
	 * @param bool   $isAdmin     Whether they are an administrator.
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $isArchivist, bool $isAdmin = false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')->with($uid, 'archivaris')->willReturn($isArchivist);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
	}

	/**
	 * A destruction list with an entry assigned to `els` and one unassigned.
	 *
	 * @return ObjectEntity
	 */
	private function listObject(): ObjectEntity {
		$list = new ObjectEntity();
		$list->setUuid('dl-1');
		$list->setObject(
			[
				'status' => 'in_review',
				'createdAt' => '2026-01-01',
				'createdBy' => 'els',
				'objects' => [
					['uuid' => 'obj-1', 'reviewer' => 'els'],
					['uuid' => 'obj-2'],
				],
				'decisions' => [['entry' => 'obj-0']],
			]
		);

		return $list;
	}

	// --- archivist gate -------------------------------------------------

	public function testEveryArchivistEndpointRefusesANonArchivistAndWritesNothing(): void {
		$this->signIn('bob', false);
		$this->params = ['reason' => 'kept', 'objectId' => 'o-1', 'objects' => ['o-1'], 'reviewer' => 'els'];
		$this->holds->expects($this->never())->method($this->anything());
		$this->creator->expects($this->never())->method($this->anything());
		$this->lists->expects($this->never())->method($this->anything());
		$this->nominations->expects($this->never())->method($this->anything());
		$this->audit->expects($this->never())->method($this->anything());

		$this->assertSame(403, $this->controller->listDestructionLists()->getStatus());
		$this->assertSame(403, $this->controller->createLegalHold()->getStatus());
		$this->assertSame(403, $this->controller->createDestructionList()->getStatus());
		$this->assertSame(403, $this->controller->assignReviewer('dl-1', 'obj-1')->getStatus());
		$this->assertSame(403, $this->controller->recomputeNomination('o-1')->getStatus());
		$this->assertSame(
			['error' => 'Onvoldoende rechten: archivaris rol is vereist'],
			$this->controller->createLegalHold()->getData()
		);
	}

	public function testEveryArchivistEndpointRefusesAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(401, $this->controller->listDestructionLists()->getStatus());
		$this->assertSame(401, $this->controller->createLegalHold()->getStatus());
		$this->assertSame(401, $this->controller->createDestructionList()->getStatus());
		$this->assertSame(401, $this->controller->assignReviewer('dl-1', 'obj-1')->getStatus());
		$this->assertSame(401, $this->controller->recomputeNomination('o-1')->getStatus());
		$this->assertSame(401, $this->controller->decideEntry('dl-1', 'obj-1')->getStatus());
	}

	public function testAnAdministratorWhoIsNoArchivistIsLetThrough(): void {
		$this->signIn('root', false, true);
		$this->lists->method('isConfigured')->willReturn(false);

		$this->assertSame(200, $this->controller->listDestructionLists()->getStatus());
	}

	// --- listDestructionLists -------------------------------------------

	public function testListingSummarisesEveryListAndPassesTheStatusFilter(): void {
		$this->signIn('els', true);
		$this->params = ['status' => 'in_review'];
		$this->lists->method('isConfigured')->willReturn(true);
		$this->lists->expects($this->once())->method('findLists')->with(['in_review'])->willReturn([$this->listObject()]);

		$response = $this->controller->listDestructionLists();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'results' => [
					[
						'uuid' => 'dl-1',
						'status' => 'in_review',
						'createdAt' => '2026-01-01',
						'createdBy' => 'els',
						'entryCount' => 2,
						'unassignedCount' => 1,
						'decisionCount' => 1,
					],
				],
				'total' => 1,
				'configured' => true,
				'filter' => 'in_review',
			],
			$response->getData()
		);
	}

	public function testAnEmptyStatusFilterListsEveryStatus(): void {
		$this->signIn('els', true);
		$this->params = ['status' => ''];
		$this->lists->method('isConfigured')->willReturn(true);
		$this->lists->expects($this->once())->method('findLists')->with(null)->willReturn([]);

		$this->assertSame(0, $this->controller->listDestructionLists()->getData()['total']);
	}

	// --- createLegalHold ------------------------------------------------

	public function testAHoldWithABlankReasonIsRefused(): void {
		$this->signIn('els', true);
		$this->params = ['reason' => '   ', 'objectId' => 'o-1'];
		$this->holds->expects($this->never())->method($this->anything());

		$response = $this->controller->createLegalHold();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Een reden voor de bewaarplicht is verplicht'], $response->getData());
	}

	public function testASchemaWideHoldIsQueuedWithItsIdsCast(): void {
		$this->signIn('els', true);
		$this->params = ['reason' => 'WOO', 'schemaId' => '7', 'registerId' => '3', 'objectId' => 'ignored'];
		$this->holds->expects($this->once())->method('bulkPlaceHold')->with(7, 3, 'WOO');
		$this->holds->expects($this->never())->method('placeHold');

		$response = $this->controller->createLegalHold();

		$this->assertSame(202, $response->getStatus());
		$this->assertSame(['message' => 'Bulk bewaarplicht is ingepland als achtergrondtaak'], $response->getData());
	}

	public function testASchemaWideHoldWithoutARegisterIsRefused(): void {
		$this->signIn('els', true);
		$this->params = ['reason' => 'WOO', 'schemaId' => '7'];
		$this->holds->expects($this->never())->method($this->anything());

		$response = $this->controller->createLegalHold();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'registerId is verplicht voor schema-brede bewaarplicht'], $response->getData());
	}

	public function testAHoldNamingNoObjectNorSchemaIsRefused(): void {
		$this->signIn('els', true);
		$this->params = ['reason' => 'WOO'];

		$response = $this->controller->createLegalHold();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'objectId of schemaId is verplicht'], $response->getData());
	}

	public function testASingleHoldPassesTheTrimmedMatter(): void {
		$this->signIn('els', true);
		$this->params = ['reason' => 'WOO', 'objectId' => 'o-1', 'ownerKey' => '  case-9  '];
		$object = new ObjectEntity();
		$held = new ObjectEntity();
		$held->setUuid('o-1');
		$held->setRetention(['legalHold' => ['active' => true]]);
		$this->objectMapper->expects($this->once())->method('find')->with('o-1')->willReturn($object);
		$this->holds->expects($this->once())->method('placeHold')->with($object, 'WOO', 'case-9')->willReturn($held);

		$response = $this->controller->createLegalHold();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['message' => 'Bewaarplicht geplaatst', 'objectId' => 'o-1', 'legalHold' => ['active' => true]],
			$response->getData()
		);
	}

	public function testAFailingHoldIs500WithItsPrefix(): void {
		$this->signIn('els', true);
		$this->params = ['reason' => 'WOO', 'objectId' => 'o-1'];
		$this->objectMapper->method('find')->willThrowException(new \RuntimeException('gone'));

		$response = $this->controller->createLegalHold();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to create legal hold: gone'], $response->getData());
	}

	// --- createDestructionList ------------------------------------------

	/**
	 * Bodies that are no list of 1..1000 non-blank uuids.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function badObjectsProvider(): array {
		return [
			'missing' => [null],
			'not a list' => ['o-1'],
			'empty' => [[]],
			'a number' => [['o-1', 5]],
			'a blank' => [['o-1', '  ']],
			'over the limit' => [array_fill(0, 1001, 'o-1')],
		];
	}

	/**
	 * @dataProvider badObjectsProvider
	 *
	 * @param mixed $objects The posted objects.
	 *
	 * @return void
	 */
	public function testABadObjectsBodyIsRefusedBeforeAnythingIsJudged(mixed $objects): void {
		$this->signIn('els', true);
		$this->params = ['objects' => $objects];
		$this->creator->expects($this->never())->method('createFor');

		$response = $this->controller->createDestructionList();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Send "objects" as a list of 1 to 1000 object uuids.'], $response->getData());
	}

	public function testAListOfExactlyTheLimitIsJudged(): void {
		$this->signIn('els', true);
		$this->params = ['objects' => array_fill(0, 1000, 'o-1')];
		$this->creator->expects($this->once())->method('createFor')
			->willReturn(['list' => ['uuid' => 'dl-9', 'status' => 'new', 'objects' => [1, 2]], 'refused' => []]);

		$response = $this->controller->createDestructionList();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['uuid' => 'dl-9', 'status' => 'new', 'entryCount' => 2, 'refused' => []], $response->getData());
	}

	// --- assignReviewer -------------------------------------------------

	public function testAssigningOnAnUnknownListIs404(): void {
		$this->signIn('els', true);
		$this->lists->method('find')->willReturn(null);
		$this->lists->expects($this->never())->method('save');

		$response = $this->controller->assignReviewer('dl-x', 'obj-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Destruction list not found'], $response->getData());
	}

	public function testAReviewerThatIsNoUserIdIsRefused(): void {
		$this->signIn('els', true);
		$this->params = ['reviewer' => ['els']];
		$this->lists->method('find')->willReturn($this->listObject());
		$this->lists->expects($this->never())->method('save');
		$this->audit->expects($this->never())->method('createAuditTrailEntry');

		$response = $this->controller->assignReviewer('dl-1', 'obj-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'A reviewer is a user id, or null to unassign'], $response->getData());
	}

	public function testAssigningOnAnEntryTheListDoesNotHoldIsRefusedAndNotSaved(): void {
		$this->signIn('els', true);
		$this->params = ['reviewer' => 'jan'];
		$this->lists->method('find')->willReturn($this->listObject());
		$this->lists->expects($this->never())->method('save');
		$this->audit->expects($this->never())->method('createAuditTrailEntry');

		$this->assertSame(400, $this->controller->assignReviewer('dl-1', 'obj-404')->getStatus());
	}

	public function testUnassigningSavesAndRecordsIt(): void {
		$this->signIn('els', true);
		$this->params = [];
		$list = $this->listObject();
		$this->lists->method('find')->willReturn($list);
		$this->lists->expects($this->once())->method('save');
		$this->audit->expects($this->once())->method('createAuditTrailEntry')
			->with($list, 'archival.review_assigned', ['entry' => 'obj-1', 'reviewer' => null]);

		$response = $this->controller->assignReviewer('dl-1', 'obj-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(2, $response->getData()['unassignedEntries']);
	}

	// --- decideEntry ----------------------------------------------------

	public function testAnswerOnAListThatCannotBeReadIs500(): void {
		$this->signIn('els', true);
		$this->lists->method('find')->willThrowException(new \RuntimeException('db'));

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Destruction list could not be read'], $response->getData());
	}

	public function testAnswerOnAnUnknownListIs404(): void {
		$this->signIn('els', true);
		$this->lists->method('find')->willReturn(null);

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Destruction list not found'], $response->getData());
	}

	public function testAnArchivistWhoIsNotTheReviewerIsRefused(): void {
		$this->signIn('jan', true, true);
		$this->lists->method('find')->willReturn($this->listObject());
		$this->lists->expects($this->never())->method('save');

		$this->assertSame(403, $this->controller->decideEntry('dl-1', 'obj-1')->getStatus());
	}

	// --- recomputeNomination --------------------------------------------

	public function testRecomputingAnUnknownRecordIs404(): void {
		$this->signIn('els', true);
		$this->params = ['reason' => 'new list'];
		$this->objectMapper->method('find')->willThrowException(new \RuntimeException('none'));
		$this->nominations->expects($this->never())->method('recompute');

		$response = $this->controller->recomputeNomination('o-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Record not found'], $response->getData());
	}

	public function testAFailingRecomputeIs500AndNothingIsWritten(): void {
		$this->signIn('els', true);
		$this->params = ['reason' => 'new list'];
		$object = new ObjectEntity();
		$object->setSchema('5');
		$this->objectMapper->method('find')->willReturn($object);
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->nominations->method('recompute')->willThrowException(new \RuntimeException('rule'));
		$this->objectMapper->expects($this->never())->method('update');
		$this->audit->expects($this->never())->method('createAuditTrailEntry');

		$response = $this->controller->recomputeNomination('o-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'The nomination could not be recomputed'], $response->getData());
	}

	public function testARecomputeRecordsTheActorAndReason(): void {
		$this->signIn('els', true);
		$this->params = ['reason' => 'new list'];
		$object = new ObjectEntity();
		$object->setSchema('5');
		$this->objectMapper->method('find')->willReturn($object);
		$this->schemaMapper->method('find')->willReturn(new Schema());
		$this->nominations->expects($this->once())->method('recompute')
			->with($this->callback(static fn (NominationRecompute $request): bool => $request->object === $object && $request->schema instanceof Schema && $request->actor === 'els' && $request->reason === 'new list'))
			->willReturn(['status' => 'nominated', 'rule' => 'r-1']);
		$this->objectMapper->expects($this->once())->method('update')->with($object);
		$this->audit->expects($this->once())->method('createAuditTrailEntry')
			->with($object, 'archival.nomination_recomputed', ['status' => 'nominated', 'rule' => 'r-1', 'reason' => 'new list']);

		$response = $this->controller->recomputeNomination('o-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['nomination' => ['status' => 'nominated', 'rule' => 'r-1']], $response->getData());
	}
}
