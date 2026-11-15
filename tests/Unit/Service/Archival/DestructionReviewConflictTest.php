<?php

/**
 * Destroying over a link conflict is a stated decision: an unacknowledged
 * destroy is refused with 422 before anything is applied to the record, an
 * acknowledged one records `overriddenConflicts` in the decision history and
 * their count in the audit row, and a retention needs no acknowledgement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-destroying-over-a-conflict-needs-an-explicit-acknowledgement
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Controller\Support\ArchivalControllerServices;
use OCA\OpenRegister\Controller\Support\ArchivalDecisions;
use OCA\OpenRegister\Controller\Support\ArchivalMappers;
use OCA\OpenRegister\Controller\Support\ArchivalNomination;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchivalNominationService;
use OCA\OpenRegister\Service\Archival\DestructionListConflictRefresh;
use OCA\OpenRegister\Service\Archival\DestructionListCreator;
use OCA\OpenRegister\Service\Archival\DestructionListLinkConflicts;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\LinkConflictsNotAcknowledgedException;
use OCA\OpenRegister\Service\Archival\LinkedRetentionConflictFinder;
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
 * @covers \OCA\OpenRegister\Service\Archival\DestructionReviewService
 * @covers \OCA\OpenRegister\Service\Archival\LinkConflictsNotAcknowledgedException
 * @covers \OCA\OpenRegister\Controller\ArchivalController
 * @uses   \OCA\OpenRegister\Service\Archival\DestructionListConflictRefresh
 * @uses   \OCA\OpenRegister\Service\Archival\DestructionListLinkConflicts
 * @uses   \OCA\OpenRegister\Service\Archival\ReviewerAnswer
 * @uses   \OCA\OpenRegister\Service\Archival\ReviewAnswer
 * @uses   \OCA\OpenRegister\Service\Archival\DecidedEntry
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalDecisions
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalMappers
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalNomination
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalReviewAnswer
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalReviewEntry
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class DestructionReviewConflictTest extends TestCase {

	private const CASE_UUID = 'zaak-2019-0042';

	private const CONFLICT = [
		'kind' => 'linked-kept-permanently',
		'direction' => 'incoming',
		'uuid' => 'besluit-2019-0007',
		'title' => 'B-2019-0007',
		'schema' => 14,
		'archiefnominatie' => 'bewaren',
		'archiefactiedatum' => null,
	];

	private LinkedRetentionConflictFinder&MockObject $finder;

	private MagicMapperInterface&MockObject $records;

	private DestructionReviewService $reviews;

	protected function setUp(): void {
		parent::setUp();
		$this->finder  = $this->createMock(LinkedRetentionConflictFinder::class);
		$this->records = $this->createMock(MagicMapperInterface::class);
		$this->records->method('findMultiple')->willReturnCallback(fn (array $uuids): array => array_map(fn (string $uuid): ObjectEntity => $this->record(uuid: $uuid), $uuids));

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn([]);
		$linkConflicts = new DestructionListLinkConflicts(
			finder: $this->finder,
			schemas: $schemas,
			registers: $this->createMock(RegisterMapperInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);
		$refresh       = new DestructionListConflictRefresh(linkConflicts: $linkConflicts, objects: $this->records, logger: $this->createMock(LoggerInterface::class));
		$this->reviews = new DestructionReviewService(conflicts: $refresh);
	}//end setUp()

	private function record(string $uuid): ObjectEntity {
		$record = new ObjectEntity();
		$record->setUuid($uuid);
		$record->setSchema('5');
		return $record;
	}//end record()

	/**
	 * The finder answers these conflicts for the case.
	 *
	 * @param array<int, array<string, mixed>> $conflicts The conflicts.
	 * @param bool                             $truncated Whether they are partial.
	 *
	 * @return void
	 */
	private function caseHas(array $conflicts, bool $truncated = false): void {
		$this->finder->method('forList')->willReturn([self::CASE_UUID => ['conflicts' => $conflicts, 'truncated' => $truncated]]);
	}//end caseHas()

	private function listData(): array {
		return [
			'status' => 'in_review',
			'objects' => [['uuid' => self::CASE_UUID, 'title' => 'Z-2019-0042', 'reviewer' => 'els']],
			'decisions' => [],
		];
	}//end listData()

	public function testAnUnacknowledgedDestroyOverAConflictIsRefusedWithTheConflicts(): void {
		$this->caseHas(conflicts: [self::CONFLICT]);

		try {
			$this->reviews->assertConflictsAcknowledged(entryUuid: self::CASE_UUID, answer: 'destroy', acknowledged: false);
			$this->fail('An unacknowledged destroy over a conflict was let through');
		} catch (LinkConflictsNotAcknowledgedException $refusal) {
			$this->assertSame([self::CONFLICT], $refusal->getConflicts());
			$this->assertFalse($refusal->isTruncated());
			$this->assertStringContainsString('acknowledgeConflicts', $refusal->getMessage());
		}
	}

	public function testAnAcknowledgedDestroyReturnsTheConflictsItOverrides(): void {
		$this->caseHas(conflicts: [self::CONFLICT]);

		$this->assertSame([self::CONFLICT], $this->reviews->assertConflictsAcknowledged(entryUuid: self::CASE_UUID, answer: 'destroy', acknowledged: true));
	}

	public function testADestroyWithoutConflictsNeedsNoAcknowledgement(): void {
		$this->caseHas(conflicts: []);

		$this->assertSame([], $this->reviews->assertConflictsAcknowledged(entryUuid: self::CASE_UUID, answer: 'destroy', acknowledged: false));
	}

	public function testRetainingNeedsNoAcknowledgementAndRunsNoCheck(): void {
		$this->finder->expects($this->never())->method('forList');
		$this->records->expects($this->never())->method('findMultiple');

		$this->assertSame([], $this->reviews->assertConflictsAcknowledged(entryUuid: self::CASE_UUID, answer: 'retain', acknowledged: false));
		$this->assertSame([], $this->reviews->assertConflictsAcknowledged(entryUuid: self::CASE_UUID, answer: 'transfer', acknowledged: false));
	}

	public function testConflictsThatCouldNotAllBeEstablishedNeedTheAcknowledgementToo(): void {
		$this->caseHas(conflicts: [], truncated: true);

		$this->expectException(LinkConflictsNotAcknowledgedException::class);
		$this->reviews->assertConflictsAcknowledged(entryUuid: self::CASE_UUID, answer: 'destroy', acknowledged: false);
	}

	public function testARecordThatCannotBeReadCountsAsPartial(): void {
		$records = $this->createMock(MagicMapperInterface::class);
		$records->method('findMultiple')->willThrowException(new \RuntimeException('table gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('table gone'));
		$refresh = new DestructionListConflictRefresh(
			linkConflicts: $this->createMock(DestructionListLinkConflicts::class),
			objects: $records,
			logger: $logger
		);

		try {
			(new DestructionReviewService(conflicts: $refresh))->assertConflictsAcknowledged(entryUuid: self::CASE_UUID, answer: 'destroy', acknowledged: false);
			$this->fail('A check that could not run read as "no conflicts"');
		} catch (LinkConflictsNotAcknowledgedException $refusal) {
			$this->assertSame([], $refusal->getConflicts());
			$this->assertTrue($refusal->isTruncated());
		}
	}

	public function testAnAcknowledgedDestroyRecordsTheOverriddenConflictsOnTheDecision(): void {
		$given = $this->reviews->reviewerAnswer(answer: 'destroy', reason: 'Besluit bevat de zaakgegevens zelf', newDate: null, reviewer: 'els');

		$listData = $this->reviews->recordAnswer(listData: $this->listData(), entryUuid: self::CASE_UUID, given: $given, overriddenConflicts: [self::CONFLICT]);

		$this->assertCount(1, $listData['decisions']);
		$this->assertSame([self::CONFLICT], $listData['decisions'][0]['overriddenConflicts']);
		$this->assertSame('destroy', $listData['decisions'][0]['answer']);
	}

	public function testADecisionWithoutConflictsCarriesNoOverriddenConflicts(): void {
		$given = $this->reviews->reviewerAnswer(answer: 'destroy', reason: 'Termijn verstreken', newDate: null, reviewer: 'els');

		$listData = $this->reviews->recordAnswer(listData: $this->listData(), entryUuid: self::CASE_UUID, given: $given);

		$this->assertArrayNotHasKey('overriddenConflicts', $listData['decisions'][0]);
	}

	/**
	 * The controller with these reviews, signed in as the entry's reviewer.
	 *
	 * @param array<string, mixed>         $params   The request params.
	 * @param ReviewOutcomeService         $outcomes The outcome service.
	 * @param DestructionListRepository    $lists    The list repository.
	 * @param AuditTrailMapper             $audit    The audit mapper.
	 *
	 * @return ArchivalController
	 */
	private function controller(array $params, ReviewOutcomeService $outcomes, DestructionListRepository $lists, AuditTrailMapper $audit): ArchivalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('els');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ArchivalController(
			$request,
			new ArchivalControllerServices(
				session: new ControllerSession(userSession: $session, groupManager: $this->createMock(IGroupManager::class), logger: $this->createMock(LoggerInterface::class)),
				mappers: new ArchivalMappers(
					objectMapper: $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock(),
					lists: $lists,
					auditMapper: $audit,
					schemaMapper: $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock()
				),
				decisions: new ArchivalDecisions(
					destructionService: $this->createMock(DestructionService::class),
					legalHoldService: $this->createMock(LegalHoldService::class),
					reviews: $this->reviews,
					outcomes: $outcomes
				),
				nomination: new ArchivalNomination(nominations: $this->createMock(ArchivalNominationService::class), creator: $this->createMock(DestructionListCreator::class))
			)
		);
	}//end controller()

	private function listObject(): ObjectEntity {
		$list = new ObjectEntity();
		$list->setUuid('dl-1');
		$list->setObject($this->listData());
		return $list;
	}//end listObject()

	public function testTheEndpointAnswers422AndAppliesNothingWhenTheConflictIsNotAcknowledged(): void {
		$this->caseHas(conflicts: [self::CONFLICT]);
		$outcomes = $this->createMock(ReviewOutcomeService::class);
		$outcomes->expects($this->never())->method('apply');
		$lists = $this->createMock(DestructionListRepository::class);
		$lists->method('find')->willReturn($this->listObject());
		$lists->expects($this->never())->method('save');
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->expects($this->never())->method('recordObjectEvent');

		$response = $this->controller(params: ['answer' => 'destroy', 'reason' => 'Termijn verstreken'], outcomes: $outcomes, lists: $lists, audit: $audit)
			->decideEntry('dl-1', self::CASE_UUID);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame([self::CONFLICT], $response->getData()['linkConflicts']);
		$this->assertFalse($response->getData()['linkConflictsTruncated']);
	}

	public function testAnAcknowledgedDestroyIsAppliedAndRecordsWhatItOverrode(): void {
		$this->caseHas(conflicts: [self::CONFLICT]);
		$outcomes = $this->createMock(ReviewOutcomeService::class);
		$outcomes->expects($this->once())->method('apply')->willReturn(null);
		$lists = $this->createMock(DestructionListRepository::class);
		$lists->method('find')->willReturn($this->listObject());
		$saved = null;
		$lists->expects($this->once())->method('save')->willReturnCallback(static function (ObjectEntity $list, array $listData) use (&$saved): void {
			$saved = $listData;
		});
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->expects($this->once())->method('recordObjectEvent')->with(
			$this->callback(static fn (ObjectAuditEvent $event): bool => $event->action === 'archival.review_decided' && $event->context['overriddenConflicts'] === 1)
		);

		$response = $this->controller(
			params: ['answer' => 'destroy', 'reason' => 'Besluit bevat de zaakgegevens zelf', 'acknowledgeConflicts' => true],
			outcomes: $outcomes,
			lists: $lists,
			audit: $audit
		)->decideEntry('dl-1', self::CASE_UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([self::CONFLICT], $response->getData()['decision']['overriddenConflicts']);
		$this->assertSame([self::CONFLICT], $saved['decisions'][0]['overriddenConflicts']);
	}

	public function testAStringTrueFromAFormCountsAsAnAcknowledgement(): void {
		$this->caseHas(conflicts: [self::CONFLICT]);
		$lists = $this->createMock(DestructionListRepository::class);
		$lists->method('find')->willReturn($this->listObject());

		$response = $this->controller(
			params: ['answer' => 'destroy', 'reason' => 'Gezien', 'acknowledgeConflicts' => 'true'],
			outcomes: $this->createMock(ReviewOutcomeService::class),
			lists: $lists,
			audit: $this->createMock(AuditTrailMapper::class)
		)->decideEntry('dl-1', self::CASE_UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testRetainingOverAConflictIsRecordedWithoutAnAcknowledgement(): void {
		$this->finder->expects($this->never())->method('forList');
		$outcomes = $this->createMock(ReviewOutcomeService::class);
		$outcomes->expects($this->once())->method('apply');
		$lists = $this->createMock(DestructionListRepository::class);
		$lists->method('find')->willReturn($this->listObject());

		$response = $this->controller(
			params: ['answer' => 'retain', 'reason' => 'Besluit blijft', 'newArchiefactiedatum' => '2035-01-01'],
			outcomes: $outcomes,
			lists: $lists,
			audit: $this->createMock(AuditTrailMapper::class)
		)->decideEntry('dl-1', self::CASE_UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayNotHasKey('overriddenConflicts', $response->getData()['decision']);
	}
}
