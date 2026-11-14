<?php

/**
 * The list read and the reviewer's worklist recompute each entry's link
 * conflicts as they stand now, say when they checked, and save nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-conflicts-are-current-when-a-reviewer-reads-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Controller\ArchivalController;
use OCA\OpenRegister\Controller\Support\ArchivalControllerServices;
use OCA\OpenRegister\Controller\Support\ArchivalDecisions;
use OCA\OpenRegister\Controller\Support\ArchivalMappers;
use OCA\OpenRegister\Controller\Support\ArchivalNomination;
use OCA\OpenRegister\Controller\Support\ControllerSession;
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
use OCA\OpenRegister\Service\Archival\LinkedRetentionConflictFinder;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ArchivalController
 * @covers \OCA\OpenRegister\Service\Archival\DestructionListConflictRefresh
 * @uses   \OCA\OpenRegister\Service\Archival\DestructionListLinkConflicts
 * @uses   \OCA\OpenRegister\Service\Archival\DestructionReviewService
 * @uses   \OCA\OpenRegister\Service\Archival\PendingReviewQuery
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalDecisions
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalMappers
 * @uses   \OCA\OpenRegister\Controller\Support\ArchivalNomination
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ArchivalControllerLinkConflictsTest extends TestCase {

	private const CASE_UUID = 'zaak-2019-0042';

	private const CHECKED = '2026-11-18T09:30:00+00:00';

	/**
	 * The conflict that appeared after the list was made: a linked document
	 * retained on another list with a new date in 2035.
	 */
	private const LATER = [
		'kind' => 'linked-kept-longer',
		'direction' => 'incoming',
		'uuid' => 'document-1',
		'title' => 'Bijlage bij besluit',
		'schema' => 12,
		'archiefnominatie' => 'vernietigen',
		'archiefactiedatum' => '2035-01-01',
	];

	private LinkedRetentionConflictFinder&MockObject $finder;

	private DestructionListRepository&MockObject $lists;

	private ArchivalController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->finder = $this->createMock(LinkedRetentionConflictFinder::class);
		$this->lists  = $this->createMock(DestructionListRepository::class);

		$records = $this->createMock(MagicMapperInterface::class);
		$records->method('findMultiple')->willReturnCallback(
			static fn (array $uuids): array => array_map(
				static function (string $uuid): ObjectEntity {
					$record = new ObjectEntity();
					$record->setUuid($uuid);
					$record->setSchema('5');
					return $record;
				},
				$uuids
			)
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willReturn([]);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::CHECKED));
		$refresh = new DestructionListConflictRefresh(
			linkConflicts: new DestructionListLinkConflicts(
				finder: $this->finder,
				schemas: $schemas,
				registers: $this->createMock(RegisterMapperInterface::class),
				logger: $this->createMock(LoggerInterface::class)
			),
			objects: $records,
			logger: $this->createMock(LoggerInterface::class),
			clock: $clock
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('els');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(true);

		$this->controller = new ArchivalController(
			$this->createMock(IRequest::class),
			new ArchivalControllerServices(
				session: new ControllerSession(userSession: $session, groupManager: $groups, logger: $this->createMock(LoggerInterface::class)),
				mappers: new ArchivalMappers(
					objectMapper: $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock(),
					lists: $this->lists,
					auditMapper: $this->createMock(AuditTrailMapper::class),
					schemaMapper: $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock()
				),
				decisions: new ArchivalDecisions(
					destructionService: $this->createMock(DestructionService::class),
					legalHoldService: $this->createMock(LegalHoldService::class),
					reviews: new DestructionReviewService(),
					outcomes: $this->createMock(ReviewOutcomeService::class),
					conflictRefresh: $refresh
				),
				nomination: new ArchivalNomination(nominations: $this->createMock(ArchivalNominationService::class), creator: $this->createMock(DestructionListCreator::class))
			)
		);
	}//end setUp()

	/**
	 * The list as it was made on Monday: no conflict for the case.
	 *
	 * @return array<string, mixed>
	 */
	private static function mondayList(): array {
		return [
			'status' => 'in_review',
			'objects' => [
				[
					'uuid' => self::CASE_UUID,
					'title' => 'Z-2019-0042',
					'reviewer' => 'els',
					'assignedAt' => '2026-11-16T08:00:00+00:00',
					'archiefactiedatum' => '2026-09-01',
					'linkConflicts' => [],
					'linkConflictsTruncated' => false,
				],
			],
			'linkConflictCount' => 0,
			'decisions' => [],
		];
	}//end mondayList()

	private function listObject(): ObjectEntity {
		$list = new ObjectEntity();
		$list->setUuid('dl-1');
		$list->setObject(self::mondayList());
		return $list;
	}//end listObject()

	public function testTheListReadShowsAConflictThatAppearedAfterCreationAndSavesNothing(): void {
		$list = $this->listObject();
		$this->lists->method('find')->with('dl-1')->willReturn($list);
		$this->lists->expects($this->never())->method('save');
		$this->finder->expects($this->once())->method('forList')
			->with($this->callback(static fn (array $entries): bool => count($entries) === 1 && $entries[0]->getUuid() === self::CASE_UUID))
			->willReturn([self::CASE_UUID => ['conflicts' => [self::LATER], 'truncated' => false]]);

		$response = $this->controller->getDestructionList('dl-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([self::LATER], $data['objects'][0]['linkConflicts']);
		$this->assertFalse($data['objects'][0]['linkConflictsTruncated']);
		$this->assertSame(self::CHECKED, $data['objects'][0]['linkConflictsCheckedAt']);
		$this->assertSame('Z-2019-0042', $data['objects'][0]['title']);
		$this->assertSame(1, $data['linkConflictCount']);
		$this->assertSame(self::CHECKED, $data['linkConflictsCheckedAt']);

		// The stored list is what it looked like when it was made.
		$stored = $list->getObject();
		$this->assertSame(self::mondayList()['objects'], $stored['objects']);
		$this->assertSame(0, $stored['linkConflictCount']);
		$this->assertArrayNotHasKey('linkConflictsCheckedAt', $stored);
	}

	public function testTheWorklistShowsTheCurrentConflictsOfEachPendingEntry(): void {
		$this->lists->method('findLists')->willReturn([$this->listObject()]);
		$this->lists->expects($this->never())->method('save');
		$this->finder->expects($this->once())->method('forList')
			->willReturn([self::CASE_UUID => ['conflicts' => [self::LATER], 'truncated' => false]]);

		$response = $this->controller->myPendingReviews();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$rows = $response->getData()['results'];
		$this->assertCount(1, $rows);
		$this->assertSame('dl-1', $rows[0]['list']);
		$this->assertSame(self::CASE_UUID, $rows[0]['uuid']);
		$this->assertSame([self::LATER], $rows[0]['linkConflicts']);
		$this->assertSame(self::CHECKED, $rows[0]['linkConflictsCheckedAt']);
	}

	public function testAnEmptyWorklistRunsNoCheck(): void {
		$this->lists->method('findLists')->willReturn([]);
		$this->finder->expects($this->never())->method('forList');

		$response = $this->controller->myPendingReviews();

		$this->assertSame([], $response->getData()['results']);
	}

	public function testAnEntryWhoseCheckFailedIsShownPartialNotClean(): void {
		$this->lists->method('find')->willReturn($this->listObject());
		$this->finder->method('forList')->willThrowException(new \RuntimeException('index unreadable'));

		$entry = $this->controller->getDestructionList('dl-1')->getData()['objects'][0];

		$this->assertSame([], $entry['linkConflicts']);
		$this->assertTrue($entry['linkConflictsTruncated']);
	}
}
