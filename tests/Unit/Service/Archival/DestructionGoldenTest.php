<?php

declare(strict_types=1);

/**
 * Golden tests pinning the destruction workflow's withheld, refused and allowed outcomes.
 *
 * Written against the code before a maintainability refactor, so the refactor can
 * prove that what is destroyed, what is withheld, what is refused and what is
 * written to the record did not move. Moments that depend on the clock are
 * checked for their format and then replaced with a fixed marker before the
 * whole structure is compared.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\DestructionExecutionJob;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\DestructionListCreator;
use OCA\OpenRegister\Service\Archival\DestructionListWriter;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\ApprovalChoice;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\ReviewOutcomeService;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Object\SaveObjectInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of the destruction workflow.
 */
class DestructionGoldenTest extends TestCase {

	private const NOW = '<now>';

	/** @var array<string, ObjectEntity> Objects by uuid. */
	private array $objects = [];

	/** @var array<int, array<string, mixed>> Retention blocks written, in order. */
	private array $updates = [];

	/** @var array<int, array{0: string, 1: mixed}> Jobs queued. */
	private array $jobs = [];

	/** @var string The current user's uid. */
	private string $uid = 'approver-1';

	/**
	 * A mapper serving $this->objects and recording each update.
	 *
	 * @return MagicMapperInterface The mapper.
	 */
	private function mapper(): MagicMapperInterface {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function ($id): ObjectEntity {
				if (isset($this->objects[(string)$id]) === false) {
					throw new RuntimeException('no such object');
				}

				return $this->objects[(string)$id];
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (ObjectEntity $object): ObjectEntity {
				$this->updates[] = ['uuid' => $object->getUuid(), 'retention' => $object->getRetention()];
				return $object;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * An object with a retention block.
	 *
	 * @param string               $uuid      The uuid.
	 * @param array<string, mixed> $retention The retention block.
	 *
	 * @return ObjectEntity The object, registered in $this->objects.
	 */
	private function object(string $uuid, array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRetention($retention);
		$this->objects[$uuid] = $object;

		return $object;
	}//end object()

	/**
	 * The destruction service with a recording job list and a fixed approver.
	 *
	 * @param string|null $extension The configured extension period, or null for the default.
	 *
	 * @return DestructionService The service.
	 */
	private function destruction(?string $extension = null): DestructionService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($extension ?? $default)
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			function ($job, $argument = null): void {
				$this->jobs[] = [$job, $argument];
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->uid === '') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->uid);
				return $user;
			}
		);

		return new DestructionService(
			objectMapper: $this->mapper(),
			appConfig: $config,
			jobList: $jobs,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end destruction()

	/**
	 * Replace every ISO moment under the given keys with a marker, after checking it.
	 *
	 * @param mixed    $value The structure.
	 * @param string[] $keys  The keys holding clock-dependent moments.
	 *
	 * @return mixed The structure, with the moments replaced.
	 */
	private function stripNow(mixed $value, array $keys=['approvedAt', 'rejectedAt', 'date', 'at']): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			if (in_array($key, $keys, true) === true && is_string($item) === true) {
				$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $item);
				$value[$key] = self::NOW;
				continue;
			}

			$value[$key] = $this->stripNow($item, $keys);
		}

		return $value;
	}//end stripNow()

	/**
	 * A list with one entry per decision, plus undecided and unreadable entries.
	 *
	 * @return array<string, mixed> The list.
	 */
	private function decidedList(): array {
		return [
			'uuid' => 'list-1',
			'status' => 'in_review',
			'objectCount' => 5,
			'objects' => [
				['uuid' => 'keep', 'decision' => 'retain', 'decidedBy' => 'rev-a'],
				['uuid' => 'move', 'decision' => 'transfer'],
				['uuid' => 'gone', 'decision' => 'destroy'],
				['uuid' => 'open'],
				'not-an-entry',
			],
			'decisions' => [
				['entry' => 'keep', 'answer' => 'retain', 'reason' => 'first draft'],
				['entry' => 'keep', 'answer' => 'destroy', 'reason' => 'other answer'],
				'junk',
				['entry' => 'keep', 'answer' => 'retain', 'reason' => 'objection pending'],
				['entry' => 'move', 'answer' => 'transfer', 'reason' => ''],
			],
			'approvals' => [],
		];
	}//end decidedList()

	public function testApproveAllWithholdsDecidedEntriesAndQueuesTheJob(): void {
		$result = $this->stripNow($this->destruction()->approveList(destructionList: $this->decidedList()));

		$this->assertSame(
			[
				'uuid' => 'list-1',
				'status' => 'approved',
				'objectCount' => 3,
				'objects' => [
					['uuid' => 'gone', 'decision' => 'destroy'],
					['uuid' => 'open'],
					'not-an-entry',
				],
				'decisions' => $this->decidedList()['decisions'],
				'approvals' => [['userId' => 'approver-1', 'approvedAt' => self::NOW, 'action' => 'approve_all']],
				'excludedObjects' => [
					[
						'uuid' => 'keep',
						'decision' => 'retain',
						'decidedBy' => 'rev-a',
						'status' => 'uitgezonderd',
						'exclusionReason' => 'Review decision "retain" by rev-a: objection pending',
					],
					['uuid' => 'move', 'decision' => 'transfer', 'status' => 'uitgezonderd', 'exclusionReason' => 'Review decision "transfer"'],
				],
			],
			$result
		);
		$this->assertSame([[DestructionExecutionJob::class, ['destructionListUuid' => 'list-1']]], $this->jobs);
		$this->assertSame([], $this->updates);
	}//end testApproveAllWithholdsDecidedEntriesAndQueuesTheJob()

	public function testPartialApprovalExcludesExtendsAndMergesWithEarlierExclusions(): void {
		$this->object('a', ['archiefactiedatum' => '2020-02-29']);
		$this->object('b', []);
		$list = [
			'objects' => [['uuid' => 'a'], ['uuid' => 'b'], ['uuid' => 'c'], ['uuid' => 'keep', 'decision' => 'retain']],
			'excludedObjects' => [['uuid' => 'earlier']],
			'approvals' => [],
		];

		$result = $this->stripNow(
			$this->destruction(extension: 'P2M')->approveList(
				destructionList: $list,
				choice: new ApprovalChoice(action: 'approve_partial', excludedIds: ['a', 'b', 'missing'], exclusionReasons: ['a' => 'Nog in gebruik']),
				listUuid: 'given-uuid'
			)
		);

		$this->assertSame(
			[
				'objects' => [['uuid' => 'c', 'status' => 'approved']],
				'excludedObjects' => [
					['uuid' => 'earlier'],
					['uuid' => 'a', 'status' => 'uitgezonderd', 'exclusionReason' => 'Nog in gebruik'],
					['uuid' => 'b', 'status' => 'uitgezonderd', 'exclusionReason' => 'Geen reden opgegeven'],
					['uuid' => 'keep', 'decision' => 'retain', 'status' => 'uitgezonderd', 'exclusionReason' => 'Review decision "retain"'],
				],
				'approvals' => [['userId' => 'approver-1', 'approvedAt' => self::NOW, 'action' => 'approve_partial']],
				'objectCount' => 1,
				'status' => 'approved',
			],
			$result
		);
		$this->assertSame(
			[
				[
					'uuid' => 'a',
					'retention' => [
						'archiefactiedatum' => '2020-04-29',
						'exclusionHistory' => [['date' => self::NOW, 'reason' => 'Nog in gebruik', 'newArchiefactiedatum' => '2020-04-29']],
					],
				],
				[
					'uuid' => 'b',
					'retention' => [
						'exclusionHistory' => [['date' => self::NOW, 'reason' => 'Geen reden opgegeven', 'newArchiefactiedatum' => null]],
					],
				],
			],
			$this->stripNow($this->updates)
		);
		$this->assertSame([[DestructionExecutionJob::class, ['destructionListUuid' => 'given-uuid']]], $this->jobs);
	}//end testPartialApprovalExcludesExtendsAndMergesWithEarlierExclusions()

	public function testPartialActionWithoutExclusionsAndBadExcludedKeyBehaveAsBefore(): void {
		$result = $this->destruction()->approveList(
			destructionList: ['objects' => [['uuid' => 'x']], 'excludedObjects' => 'broken', 'approvals' => []],
			choice: new ApprovalChoice(action: 'approve_partial')
		);

		$this->assertSame([['uuid' => 'x']], $result['objects']);
		$this->assertSame('broken', $result['excludedObjects']);
		$this->assertSame('approved', $result['status']);
		$this->assertSame([[DestructionExecutionJob::class, ['destructionListUuid' => null]]], $this->jobs);

		$this->object('y', ['archiefactiedatum' => '2021-01-01']);
		$partial = $this->destruction()->approveList(
			destructionList: ['objects' => [['uuid' => 'y']], 'excludedObjects' => 'broken', 'approvals' => []],
			choice: new ApprovalChoice(action: 'approve_partial', excludedIds: ['y'])
		);
		$this->assertSame([], $partial['objects']);
		$this->assertSame('2022-01-01', $this->updates[0]['retention']['archiefactiedatum']);
		$this->assertSame(
			[['uuid' => 'y', 'status' => 'uitgezonderd', 'exclusionReason' => 'Geen reden opgegeven']],
			$partial['excludedObjects']
		);
	}//end testPartialActionWithoutExclusionsAndBadExcludedKeyBehaveAsBefore()

	public function testDualApprovalNeedsTwoDistinctArchivists(): void {
		$service = $this->destruction();

		$first = $service->approveList(destructionList: $this->decidedList(), requiresDual: true);
		$this->assertSame('awaiting_second_approval', $first['status']);
		$this->assertCount(2, $first['excludedObjects']);
		$this->assertSame([], $this->jobs);

		$same = $service->approveList(destructionList: $first, requiresDual: true);
		$this->assertSame('awaiting_second_approval', $same['status']);
		$this->assertCount(1, $same['approvals']);
		$this->assertCount(2, $same['excludedObjects']);
		$this->assertSame([], $this->jobs);

		$this->uid = '';
		$second = $this->stripNow($service->approveList(destructionList: $first, requiresDual: true));
		$this->assertSame('approved', $second['status']);
		$this->assertSame(
			[
				['userId' => 'approver-1', 'approvedAt' => self::NOW, 'action' => 'approve_all'],
				['userId' => 'system', 'approvedAt' => self::NOW, 'action' => 'approve_all'],
			],
			$second['approvals']
		);
		$this->assertCount(2, $second['excludedObjects']);
		$this->assertSame([[DestructionExecutionJob::class, ['destructionListUuid' => 'list-1']]], $this->jobs);
	}//end testDualApprovalNeedsTwoDistinctArchivists()

	public function testWithholdingLeavesAListWithoutDecisionsUntouched(): void {
		$service = $this->destruction();
		$plain = ['objects' => [['uuid' => 'p']], 'objectCount' => 9];
		$this->assertSame($plain, $service->withholdDecidedEntries($plain));
		$this->assertSame(['objects' => 'x'], $service->withholdDecidedEntries(['objects' => 'x']));
		$this->assertSame([], $service->withholdDecidedEntries([]));

		$weird = ['objects' => [['uuid' => 'q', 'decision' => 'retain']], 'excludedObjects' => 5, 'decisions' => 'no'];
		$this->assertSame(
			[
				'objects' => [],
				'excludedObjects' => [['uuid' => 'q', 'decision' => 'retain', 'status' => 'uitgezonderd', 'exclusionReason' => 'Review decision "retain"']],
				'decisions' => 'no',
				'objectCount' => 0,
			],
			$service->withholdDecidedEntries($weird)
		);
	}//end testWithholdingLeavesAListWithoutDecisionsUntouched()

	public function testRejectingExtendsEveryEntry(): void {
		$this->object('r1', ['archiefactiedatum' => '2024-05-05', 'exclusionHistory' => [['old' => true]]]);
		$result = $this->stripNow(
			$this->destruction()->rejectList(['objects' => [['uuid' => 'r1'], ['uuid' => 'absent']], 'rejections' => []], 'te vroeg')
		);

		$this->assertSame(
			[
				'objects' => [['uuid' => 'r1'], ['uuid' => 'absent']],
				'rejections' => [['rejectedBy' => 'approver-1', 'rejectedAt' => self::NOW, 'reason' => 'te vroeg']],
				'status' => 'rejected',
			],
			$result
		);
		$this->assertSame(
			[
				[
					'uuid' => 'r1',
					'retention' => [
						'archiefactiedatum' => '2025-05-05',
						'exclusionHistory' => [
							['old' => true],
							['date' => self::NOW, 'reason' => 'Destruction list rejected: te vroeg', 'newArchiefactiedatum' => '2025-05-05'],
						],
					],
				],
			],
			$this->stripNow($this->updates)
		);
		$this->assertSame([], $this->jobs);
	}//end testRejectingExtendsEveryEntry()

	public function testCreatingAListRefusesWhatTheRuleRefusesAndSavesTheRest(): void {
		$this->object('ok-1', []);
		$this->object('held', []);
		$this->object('ok-2', []);

		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('getObjectsOnPendingDestructionLists')->willReturn(['on-list']);
		$retention->method('destructionRefusal')->willReturnCallback(
			function (ObjectEntity $object, string $today, array $excludeUuids): ?string {
				$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $today);
				$this->assertSame(['on-list'], $excludeUuids);
				return ($object->getUuid() === 'held') ? 'legal_hold' : null;
			}
		);
		$retention->expects($this->once())->method('createDestructionList')->willReturnCallback(
			static fn (array $objects): array => ['objects' => array_map(static fn ($o) => ['uuid' => $o->getUuid()], $objects)]
		);

		$saved = new ObjectEntity();
		$saved->setUuid('new-list');
		$save = $this->createMock(SaveObjectInterface::class);
		$save->expects($this->once())->method('saveObject')
			->with(7, 9, ['objects' => [['uuid' => 'ok-1'], ['uuid' => 'ok-2']]], null, false, false, true, true)
			->willReturn($saved);

		$result = $this->creator(retention: $retention, save: $save)->createFor([' ok-1', 'ok-1 ', 'held', '', 'nope', 'ok-2']);

		$this->assertSame(
			[
				'list' => ['id' => 'new-list', 'uuid' => 'new-list'],
				'refused' => [
					['uuid' => 'held', 'reason' => 'legal_hold'],
					['uuid' => '', 'reason' => 'not_found'],
					['uuid' => 'nope', 'reason' => 'not_found'],
				],
			],
			$result
		);
	}//end testCreatingAListRefusesWhatTheRuleRefusesAndSavesTheRest()

	public function testCreatingAListWithNothingEligibleSavesNothing(): void {
		$this->object('held', []);
		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('getObjectsOnPendingDestructionLists')->willReturn([]);
		$retention->method('destructionRefusal')->willReturn('not_due');
		$retention->expects($this->never())->method('createDestructionList');
		$save = $this->createMock(SaveObjectInterface::class);
		$save->expects($this->never())->method('saveObject');

		$this->assertSame(
			['list' => null, 'refused' => [['uuid' => 'held', 'reason' => 'not_due']]],
			$this->creator(retention: $retention, save: $save)->createFor(['held'])
		);
	}//end testCreatingAListWithNothingEligibleSavesNothing()

	public function testCreatingAListRefusesWithoutConfigurationOrABuildableList(): void {
		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->expects($this->never())->method('destructionRefusal');
		$save = $this->createMock(SaveObjectInterface::class);
		$save->expects($this->never())->method('saveObject');

		try {
			$this->creator(retention: $retention, save: $save, settings: ['destructionListRegister' => 7])->createFor(['x']);
			$this->fail('expected a refusal');
		} catch (InvalidArgumentException $e) {
			$this->assertStringStartsWith('No destruction list register and schema are configured', $e->getMessage());
		}

		$this->object('ok', []);
		$builder = $this->createMock(RetentionServiceInterface::class);
		$builder->method('getObjectsOnPendingDestructionLists')->willReturn([]);
		$builder->method('destructionRefusal')->willReturn(null);
		$builder->method('createDestructionList')->willReturn(null);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The destruction list could not be built from the archival settings.');
		$this->creator(retention: $builder, save: $save)->createFor(['ok']);
	}//end testCreatingAListRefusesWithoutConfigurationOrABuildableList()

	/**
	 * A list creator over $this->objects.
	 *
	 * @param RetentionServiceInterface $retention The retention rule.
	 * @param SaveObjectInterface       $save      The save path.
	 * @param array<string, mixed>      $settings  The archival settings.
	 *
	 * @return DestructionListCreator The creator.
	 */
	private function creator(
		RetentionServiceInterface $retention,
		SaveObjectInterface $save,
		array $settings=['destructionListRegister' => 7, 'destructionListSchema' => 9],
	): DestructionListCreator {
		$handler = $this->createMock(ObjectRetentionHandler::class);
		$handler->method('getArchivalSettingsOnly')->willReturn($settings);

		return new DestructionListCreator(
			retention: $retention,
			writer: new DestructionListWriter(objectMapper: $this->mapper(), saveObject: $save),
			settingsHandler: $handler,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end creator()

	public function testRecordingAnAnswerWritesTheEntryAndTheHistory(): void {
		$review = new DestructionReviewService();
		$list = ['objects' => [['uuid' => 'e1', 'reviewer' => 'rev'], ['uuid' => 'e2', 'reviewer' => 'rev']], 'decisions' => 'junk'];
		$at = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');

		$retained = $review->recordAnswer($list, 'e1', 'retain', 'rev', 'nodig', '2030-01-01', 'ignored', $at);
		$this->assertSame(
			[
				'objects' => [
					['uuid' => 'e1', 'reviewer' => 'rev', 'decision' => 'retain', 'decidedBy' => 'rev', 'decidedAt' => '2026-01-02T03:04:05+00:00'],
					['uuid' => 'e2', 'reviewer' => 'rev'],
				],
				'decisions' => [
					['entry' => 'e1', 'answer' => 'retain', 'reviewer' => 'rev', 'decidedAt' => '2026-01-02T03:04:05+00:00', 'reason' => 'nodig', 'newArchiefactiedatum' => '2030-01-01'],
				],
			],
			$retained
		);

		$moved = $review->recordAnswer($retained, 'e2', 'transfer', 'rev', 'e-Depot', '2031-01-01', 'tl-1', $at);
		$this->assertSame(
			['entry' => 'e2', 'answer' => 'transfer', 'reviewer' => 'rev', 'decidedAt' => '2026-01-02T03:04:05+00:00', 'reason' => 'e-Depot', 'transferListUuid' => 'tl-1'],
			$moved['decisions'][1]
		);

		$destroyed = $review->recordAnswer($list, 'e2', 'destroy', 'rev', 'weg', null, 'tl-2', $at);
		$this->assertSame(
			[['entry' => 'e2', 'answer' => 'destroy', 'reviewer' => 'rev', 'decidedAt' => '2026-01-02T03:04:05+00:00', 'reason' => 'weg']],
			$destroyed['decisions']
		);
	}//end testRecordingAnAnswerWritesTheEntryAndTheHistory()

	public function testAssigningAndUnassigningAReviewer(): void {
		$review = new DestructionReviewService();
		$list = ['objects' => [['uuid' => 'a', 'reviewer' => 'old', 'assignedAt' => 'x'], ['uuid' => 'd', 'decision' => 'destroy']]];
		$at = new \DateTimeImmutable('2026-02-03T04:05:06+01:00');

		$this->assertSame(
			['objects' => [['uuid' => 'a', 'reviewer' => 'new', 'assignedAt' => '2026-02-03T04:05:06+01:00'], $list['objects'][1]]],
			$review->assignReviewer($list, 'a', 'new', $at)
		);
		$this->assertSame(['objects' => [['uuid' => 'a'], $list['objects'][1]]], $review->assignReviewer($list, 'a', null, $at));
		$this->assertSame(['objects' => [['uuid' => 'a'], $list['objects'][1]]], $review->assignReviewer($list, 'a', '  '));

		$messages = [];
		foreach (['d', 'zz'] as $uuid) {
			try {
				$review->assignReviewer($list, $uuid, 'new', $at);
			} catch (InvalidArgumentException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame(
			['Entry d has already been answered with "destroy" and cannot be reassigned', 'This destruction list has no entry for object zz'],
			$messages
		);
	}//end testAssigningAndUnassigningAReviewer()

	public function testPendingEntriesFilterByReviewerAndWaitingTime(): void {
		$review = new DestructionReviewService();
		$list = [
			'status' => 'in_review',
			'objects' => [
				['uuid' => 'early', 'title' => 'Early', 'reviewer' => 'ann', 'assignedAt' => '2026-01-01T00:00:00+00:00', 'archiefactiedatum' => '2020-01-01', 'classification' => 'B1'],
				['uuid' => 'late', 'reviewer' => 'ann', 'assignedAt' => '2026-03-01T00:00:00+00:00'],
				['uuid' => 'never', 'reviewer' => 'bob'],
				['uuid' => 'garbled', 'reviewer' => 'bob', 'assignedAt' => 'not a moment'],
				['uuid' => 'done', 'reviewer' => 'ann', 'decision' => 'destroy'],
				['uuid' => 'nobody', 'reviewer' => ' '],
				'junk',
			],
		];

		$row = static fn (string $uuid, string $title, string $reviewer, $assignedAt, $date = null, $class = null): array => [
			'list' => 'L', 'listStatus' => 'in_review', 'uuid' => $uuid, 'title' => $title, 'reviewer' => $reviewer,
			'assignedAt' => $assignedAt, 'archiefactiedatum' => $date, 'classification' => $class,
		];

		$all = $review->pendingEntries($list, 'L');
		$this->assertSame(
			[
				$row('early', 'Early', 'ann', '2026-01-01T00:00:00+00:00', '2020-01-01', 'B1'),
				$row('late', 'late', 'ann', '2026-03-01T00:00:00+00:00'),
				$row('never', 'never', 'bob', null),
				$row('garbled', 'garbled', 'bob', 'not a moment'),
			],
			$all
		);
		$this->assertSame(['ann' => 2, 'bob' => 2], $review->countByReviewer($all));

		$cutoff = new \DateTimeImmutable('2026-02-01T00:00:00+00:00');
		$this->assertSame(['early', 'never', 'garbled'], array_column($review->pendingEntries($list, 'L', null, $cutoff), 'uuid'));
		$this->assertSame(['early'], array_column($review->pendingEntries($list, 'L', 'ann', $cutoff), 'uuid'));
		$this->assertSame(['early'], array_column($review->pendingEntries($list, 'L', 'ann', new \DateTimeImmutable('2026-01-01T00:00:00+00:00')), 'uuid'));
		$this->assertSame([], $review->pendingEntries(['objects' => 'none'], 'L'));
		$this->assertSame([['uuid' => 'nobody', 'title' => 'nobody']], array_slice($review->unassignedEntries($list), 0));
	}//end testPendingEntriesFilterByReviewerAndWaitingTime()

	/**
	 * Every refusal recordAnswer() gives, and the message it gives it with.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, mixed>, 2: string}>
	 */
	public static function refusedAnswers(): array {
		$assigned = ['objects' => [['uuid' => 'e', 'reviewer' => 'rev']]];
		return [
			'fourth answer' => [$assigned, ['e', 'burn', 'rev', 'r'], '"burn" is not a review answer; the answers are destroy, retain, transfer'],
			'no reason' => [$assigned, ['e', 'destroy', 'rev', '  '], 'A review answer records why it was given, so a reason is required'],
			'retain no date' => [$assigned, ['e', 'retain', 'rev', 'r', ' '], 'Retaining a record moves its archiefactiedatum, so a new date is required'],
			'retain bad date' => [$assigned, ['e', 'retain', 'rev', 'r', 'not a date'], '"not a date" is not a date the archiefactiedatum can be moved to'],
			'no entry' => [$assigned, ['zz', 'destroy', 'rev', 'r'], 'This destruction list has no entry for object zz'],
			'unassigned' => [['objects' => [['uuid' => 'e', 'reviewer' => ' ']]], ['e', 'destroy', 'rev', 'r'], 'Entry e has no reviewer; assign one before it can be answered'],
			'someone else' => [$assigned, ['e', 'destroy', 'other', 'r'], 'Entry e is rev to answer, not other'],
			'already answered' => [['objects' => [['uuid' => 'e', 'reviewer' => 'rev', 'decision' => 'retain']]], ['e', 'destroy', 'rev', 'r'], 'Entry e was already answered with "retain"; a review decision is recorded once'],
		];
	}//end refusedAnswers()

	/**
	 * @dataProvider refusedAnswers
	 *
	 * @param array<string, mixed> $list      The list.
	 * @param array<int, mixed>    $arguments The positional arguments after the list.
	 * @param string               $message   The refusal.
	 */
	public function testRecordingAnAnswerRefuses(array $list, array $arguments, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		(new DestructionReviewService())->recordAnswer($list, ...$arguments);
	}//end testRecordingAnAnswerRefuses()

	/**
	 * The review outcome service over $this->objects.
	 *
	 * @param TransferListService|null $transfers The transfer path.
	 *
	 * @return ReviewOutcomeService The service.
	 */
	private function outcomes(?TransferListService $transfers = null): ReviewOutcomeService {
		return new ReviewOutcomeService(
			objectMapper: $this->mapper(),
			transferListService: ($transfers ?? $this->createMock(TransferListService::class))
		);
	}//end outcomes()

	public function testRetainingKeepsTheOriginalDateOnceAndAppendsHistory(): void {
		$this->object('r', ['archiefactiedatum' => '2025-01-01', 'retentionHistory' => 'bad']);
		$this->assertNull($this->outcomes()->apply('retain', 'r', 'why', '2030-06-01', 'rev'));
		$this->assertNull($this->outcomes()->apply('retain', 'r', 'again', '2031-06-01'));

		$this->assertSame(
			[
				'archiefactiedatum' => '2031-06-01',
				'retentionHistory' => [
					['date' => self::NOW, 'reason' => 'why', 'newArchiefactiedatum' => '2030-06-01'],
					['date' => self::NOW, 'reason' => 'again', 'newArchiefactiedatum' => '2031-06-01'],
				],
				'originalArchiefactiedatum' => '2025-01-01',
			],
			$this->stripNow($this->updates[1]['retention'])
		);

		$this->assertNull($this->outcomes()->apply('destroy', 'r', 'x'));
		$this->assertCount(2, $this->updates);
	}//end testRetainingKeepsTheOriginalDateOnceAndAppendsHistory()

	public function testTransferWritesTheOutcomeOntoTheRecord(): void {
		$this->object('t', ['archiefactiedatum' => '2025-01-01']);
		$transfers = $this->createMock(TransferListService::class);
		$transfers->method('createTransferList')->willReturn(['uuid' => 'tl-9']);

		$this->assertSame('tl-9', $this->outcomes(transfers: $transfers)->apply('transfer', 't', 'naar e-Depot', null, 'rev'));
		$this->assertSame(
			[
				'archiefactiedatum' => '2025-01-01',
				'outcome' => ['kind' => 'transfer', 'at' => self::NOW, 'by' => 'rev', 'reason' => 'naar e-Depot', 'transferListUuid' => 'tl-9'],
			],
			$this->stripNow($this->updates[0]['retention'])
		);
	}//end testTransferWritesTheOutcomeOntoTheRecord()

	public function testOutcomesRefuseWhenTheRecordOrTheListFails(): void {
		$messages = [];
		foreach (['retain', 'transfer'] as $answer) {
			try {
				$this->outcomes()->apply($answer, 'missing', 'r', '2030-01-01');
			} catch (RuntimeException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->object('t', []);
		$failing = $this->createMock(TransferListService::class);
		$failing->method('createTransferList')->willThrowException(new RuntimeException('boom'));
		$blank = $this->createMock(TransferListService::class);
		$blank->method('createTransferList')->willReturn(['uuid' => '']);
		foreach ([$failing, $blank] as $transfers) {
			try {
				$this->outcomes(transfers: $transfers)->apply('transfer', 't', 'r');
			} catch (RuntimeException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame(
			[
				'Cannot retain missing: the record could not be read (no such object)',
				'Cannot transfer missing: the record could not be read (no such object)',
				'Cannot transfer t: the transfer list could not be made (boom)',
				'Cannot transfer t: the transfer list came back without a uuid',
			],
			$messages
		);
		$this->assertSame([], $this->updates);
	}//end testOutcomesRefuseWhenTheRecordOrTheListFails()

	public function testOutcomesRefuseWhenTheRecordCannotBeWritten(): void {
		$object = new ObjectEntity();
		$object->setUuid('w');
		$object->setRetention([]);
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willReturn($object);
		$mapper->method('update')->willThrowException(new RuntimeException('disk'));
		$transfers = $this->createMock(TransferListService::class);
		$transfers->method('createTransferList')->willReturn(['uuid' => 'tl']);
		$service = new ReviewOutcomeService(objectMapper: $mapper, transferListService: $transfers);

		$messages = [];
		foreach (['retain', 'transfer'] as $answer) {
			try {
				$service->apply($answer, 'w', 'r', '2030-01-01');
			} catch (RuntimeException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame(
			['Cannot retain w: the new date could not be written (disk)', 'Cannot transfer w: the outcome could not be written (disk)'],
			$messages
		);
	}//end testOutcomesRefuseWhenTheRecordCannotBeWritten()

	public function testCertificatesAreNewestFirstAndMissingOnesAreNamed(): void {
		$lists = [];
		foreach ([['l1', 'c1'], ['l2', ''], ['l3', 'c3'], ['l4', 'gone']] as [$listUuid, $cert]) {
			$list = new ObjectEntity();
			$list->setUuid($listUuid);
			$list->setObject(['certificateUuid' => $cert]);
			$lists[] = $list;
		}

		foreach (['c1' => '2024-01-01', 'c3' => '2025-01-01'] as $uuid => $date) {
			$certificate = $this->object($uuid, []);
			$certificate->setObject(['destructionDate' => $date, 'uuid' => 'stale']);
		}

		$mapper = $this->mapper();
		$mapper->method('findAll')->willReturnCallback(
			function (...$args) use ($lists): array {
				$this->assertSame(['status' => ['executed']], $args[2]);
				return $lists;
			}
		);
		$handler = $this->createMock(ObjectRetentionHandler::class);
		$handler->method('getArchivalSettingsOnly')->willReturn(['destructionListRegister' => 1, 'destructionListSchema' => 2]);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn(new Register());
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		$repository = new DestructionListRepository(
			objectMapper: $mapper,
			registerMapper: $registers,
			schemaMapper: $schemas,
			settingsHandler: $handler,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertSame(
			[
				'results' => [
					['id' => 'c3', 'destructionDate' => '2025-01-01', 'uuid' => 'c3', 'destructionListUuid' => 'l3'],
					['id' => 'c1', 'destructionDate' => '2024-01-01', 'uuid' => 'c1', 'destructionListUuid' => 'l1'],
				],
				'missing' => ['l2', 'l4'],
			],
			$repository->findCertificates()
		);
		$this->assertSame(['results' => [], 'missing' => ['l2']], $repository->findCertificates(listUuid: 'l2'));
	}//end testCertificatesAreNewestFirstAndMissingOnesAreNamed()
}//end class
