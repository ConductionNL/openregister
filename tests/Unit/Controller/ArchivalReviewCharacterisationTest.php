<?php

declare(strict_types=1);

/**
 * ArchivalController review characterisation tests.
 *
 * Pins what the review answer, the reviewer assignment and the nomination
 * recompute write and answer on every branch, so their bodies can be split
 * into smaller units without the outcome moving: the arguments the outcome
 * service receives, the order "carry out, then save, then audit", the audit
 * payloads, and the status code and message of each refusal and failure.
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

use InvalidArgumentException;
use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Db\AuditTrail;
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
use OCA\OpenRegister\Service\Archival\ReviewerAnswer;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ArchivalControllerFactory;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Archival\NominationRecompute;

/**
 * Characterisation of ArchivalController's review and recompute writes.
 */
class ArchivalReviewCharacterisationTest extends TestCase {

	private IRequest&MockObject $request;
	private DestructionListRepository&MockObject $lists;
	private ReviewOutcomeService&MockObject $outcomes;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private MagicMapper&MockObject $objectMapper;
	private SchemaMapper&MockObject $schemaMapper;
	private ArchivalNominationService&MockObject $nominations;
	private AuditTrailMapper&MockObject $audit;
	private LoggerInterface&MockObject $logger;
	private ArchivalController $controller;

	/**
	 * The ordered side effects one call produced.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->lists = $this->createMock(DestructionListRepository::class);
		$this->outcomes = $this->createMock(ReviewOutcomeService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['update', 'find'])
			->getMock();
		$this->schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$this->nominations = $this->createMock(ArchivalNominationService::class);
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = ArchivalControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(DestructionService::class),
			$this->createMock(LegalHoldService::class),
			$this->objectMapper,
			$this->userSession,
			$this->groupManager,
			$this->logger,
			$this->lists,
			new DestructionReviewService(),
			$this->outcomes,
			$this->audit,
			$this->nominations,
			$this->schemaMapper,
			$this->createMock(DestructionListCreator::class)
		);
	}

	/**
	 * Sign an archivist in.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}

	/**
	 * Answer request params from a map.
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

	/**
	 * A list holding obj-1 (assigned to els) and obj-2 (unassigned).
	 *
	 * @return ObjectEntity The list.
	 */
	private function listObject(): ObjectEntity {
		$list = new ObjectEntity();
		$list->setUuid('dl-1');
		$list->setObject(
			[
				'status' => 'in_review',
				'objects' => [
					['uuid' => 'obj-1', 'title' => 'Bezwaar 2019/114', 'reviewer' => 'els'],
					['uuid' => 'obj-2', 'title' => 'Bezwaar 2019/115'],
				],
				'decisions' => [],
			]
		);

		return $list;
	}

	/**
	 * Record save and audit calls, in order.
	 *
	 * @return void
	 */
	private function recordWrites(): void {
		$this->lists->method('save')->willReturnCallback(
			function (ObjectEntity $list, array $listData): void {
				$this->calls[] = 'save';
			}
		);
		$this->audit->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []) {
				$this->calls[] = 'audit:' . $action . ':' . json_encode($context);
				return new AuditTrail();
			}
		);
	}

	public function testAnAnswerIsCarriedOutThenSavedThenAudited(): void {
		$this->signIn('els');
		$list = $this->listObject();
		$this->lists->method('find')->willReturn($list);
		$this->withParams(['answer' => 'retain', 'reason' => 'Still in use', 'newArchiefactiedatum' => 2031]);
		$this->recordWrites();

		$this->outcomes->expects($this->once())->method('apply')
			->with('obj-1', new ReviewerAnswer('retain', 'Still in use', '2031', 'els'))
			->willReturnCallback(
				function (): ?string {
					$this->calls[] = 'apply';
					return null;
				}
			);

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'apply',
				'save',
				'audit:archival.review_decided:{"entry":"obj-1","answer":"retain","reviewer":"els"}',
			],
			$this->calls
		);
		$data = $response->getData();
		$this->assertSame(['decision', 'entry'], array_keys($data));
		$this->assertSame('retain', $data['decision']['answer']);
		$this->assertSame('2031', $data['decision']['newArchiefactiedatum']);
		$this->assertSame('obj-1', $data['entry']['uuid']);
	}

	public function testAnAnswerWithoutADatePassesANullDate(): void {
		$this->signIn('els');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['answer' => 'destroy', 'reason' => 'Past its date']);

		$this->outcomes->expects($this->once())->method('apply')
			->with('obj-1', new ReviewerAnswer('destroy', 'Past its date', null, 'els'))
			->willReturn(null);

		$this->assertSame(Http::STATUS_OK, $this->controller->decideEntry('dl-1', 'obj-1')->getStatus());
	}

	public function testAnUnknownAnswerNamesTheAnswers(): void {
		$this->signIn('els');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['answer' => 'approve']);
		$this->lists->expects($this->never())->method('save');
		$this->audit->expects($this->never())->method('createAuditTrailEntry');

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => '"approve" is not a review answer; the answers are destroy, retain, transfer'],
			$response->getData()
		);
	}

	public function testAnOutcomeThatRefusesTheAnswerIs400AndWritesNothing(): void {
		$this->signIn('els');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['answer' => 'retain', 'reason' => 'x']);
		$this->outcomes->method('apply')->willThrowException(new InvalidArgumentException('A retained record needs a new date'));
		$this->lists->expects($this->never())->method('save');
		$this->audit->expects($this->never())->method('createAuditTrailEntry');

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A retained record needs a new date'], $response->getData());
	}

	public function testAnOutcomeThatFailsIs500LoggedAndWritesNothing(): void {
		$this->signIn('els');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['answer' => 'transfer', 'reason' => 'x']);
		$this->outcomes->method('apply')->willThrowException(new RuntimeException('e-Depot list could not be made'));
		$this->lists->expects($this->never())->method('save');
		$this->audit->expects($this->never())->method('createAuditTrailEntry');
		$this->logger->expects($this->once())->method('error')
			->with('[ArchivalController] Review answer could not be carried out: e-Depot list could not be made');

		$response = $this->controller->decideEntry('dl-1', 'obj-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'e-Depot list could not be made'], $response->getData());
	}

	public function testAssigningSavesThenAuditsAndNamesTheUnassigned(): void {
		$this->signIn('archivaris');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['reviewer' => 'joris']);
		$this->recordWrites();

		$response = $this->controller->assignReviewer('dl-1', 'obj-2');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['save', 'audit:archival.review_assigned:{"entry":"obj-2","reviewer":"joris"}'],
			$this->calls
		);
		$data = $response->getData();
		$this->assertSame(['entry', 'unassignedEntries'], array_keys($data));
		$this->assertSame('joris', $data['entry']['reviewer']);
		$this->assertSame([], $data['unassignedEntries']);
	}

	public function testAssigningANonStringReviewerIs400(): void {
		$this->signIn('archivaris');
		$this->lists->method('find')->willReturn($this->listObject());
		$this->withParams(['reviewer' => ['els']]);
		$this->lists->expects($this->never())->method('save');

		$response = $this->controller->assignReviewer('dl-1', 'obj-2');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A reviewer is a user id, or null to unassign'], $response->getData());
	}

	/**
	 * Point the mappers at one record and its schema.
	 *
	 * @return ObjectEntity The record.
	 */
	private function recordUnderNomination(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('7');

		$this->objectMapper->method('find')->willReturn($object);
		$this->schemaMapper->method('find')->willReturn(
			$this->getMockBuilder(Schema::class)->disableOriginalConstructor()->getMock()
		);

		return $object;
	}

	public function testARecomputeNominatesWithTheActorThenUpdatesThenAudits(): void {
		$this->signIn('archivaris');
		$this->withParams(['reason' => 'New selectielijst']);
		$object = $this->recordUnderNomination();
		$this->recordWrites();

		$this->nominations->expects($this->once())->method('recompute')
			->with($this->callback(static fn (NominationRecompute $request): bool => $request->object === $object && $request->actor === 'archivaris' && $request->reason === 'New selectielijst'))
			->willReturn(['status' => ArchivalNominationService::STATUS_NOMINATED, 'rule' => 'r-1']);
		$this->objectMapper->expects($this->once())->method('update')
			->willReturnCallback(
				function (ObjectEntity $entity) {
					$this->calls[] = 'update';
					return $entity;
				}
			);

		$response = $this->controller->recomputeNomination('obj-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['nomination' => ['status' => ArchivalNominationService::STATUS_NOMINATED, 'rule' => 'r-1']],
			$response->getData()
		);
		$this->assertSame(
			[
				'update',
				'audit:archival.nomination_recomputed:{"status":"' . ArchivalNominationService::STATUS_NOMINATED . '","rule":"r-1","reason":"New selectielijst"}',
			],
			$this->calls
		);
	}

	public function testANotApplicableRecomputeNamesTheReasonAndAuditsNothing(): void {
		$this->signIn('archivaris');
		$this->withParams(['reason' => 'Trying']);
		$this->recordUnderNomination();
		$this->audit->expects($this->never())->method('createAuditTrailEntry');
		$this->nominations->method('recompute')->willReturn(
			['status' => ArchivalNominationService::STATUS_NOT_APPLICABLE, 'unnominatableReason' => 'no archive block']
		);

		$response = $this->controller->recomputeNomination('obj-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'This schema declares no archiving, so there is nothing to nominate',
				'reason' => 'no archive block',
			],
			$response->getData()
		);
	}

	public function testAFailingUpdateIs500AndLogsTheRecord(): void {
		$this->signIn('archivaris');
		$this->withParams(['reason' => 'Trying']);
		$this->recordUnderNomination();
		$this->nominations->method('nominate')->willReturn(['status' => ArchivalNominationService::STATUS_NOMINATED]);
		$this->objectMapper->method('update')->willThrowException(new RuntimeException('db down'));
		$this->audit->expects($this->never())->method('createAuditTrailEntry');
		$this->logger->expects($this->once())->method('error')
			->with('[ArchivalController] Could not recompute the nomination for obj-1: db down');

		$response = $this->controller->recomputeNomination('obj-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'The nomination could not be recomputed'], $response->getData());
	}

	public function testARecordThatDoesNotLoadIs404(): void {
		$this->signIn('archivaris');
		$this->withParams(['reason' => 'Trying']);
		$this->objectMapper->method('find')->willThrowException(new RuntimeException('gone'));
		$this->nominations->expects($this->never())->method('nominate');

		$response = $this->controller->recomputeNomination('obj-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Record not found'], $response->getData());
	}
}
