<?php

declare(strict_types=1);

/**
 * DestructionReviewService tests.
 *
 * Pins the rules that make a destruction list something a person signs off
 * rather than something a group waves through: who is accountable for an
 * entry, who may answer it, what the three answers are, and what a reviewer
 * is still holding.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DestructionReviewService.
 */
class DestructionReviewServiceTest extends TestCase {

	private DestructionReviewService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new DestructionReviewService();
	}

	/**
	 * A list of three entries, one of them already assigned.
	 *
	 * @return array<string, mixed> The list data.
	 */
	private function list(): array {
		return [
			'status' => 'in_review',
			'objects' => [
				['uuid' => 'obj-1', 'title' => 'Bezwaar 2019/114', 'reviewer' => 'els'],
				['uuid' => 'obj-2', 'title' => 'Bezwaar 2019/115'],
				['uuid' => 'obj-3', 'title' => 'Vergunning 2018/7'],
			],
		];
	}

	public function testAnUnassignedEntryIsNamedAndNotMerelyCounted(): void {
		$unassigned = $this->service->unassignedEntries(listData: $this->list());

		$this->assertCount(2, $unassigned);
		$this->assertSame(
			['obj-2', 'obj-3'],
			array_column($unassigned, 'uuid')
		);
		$this->assertSame('Bezwaar 2019/115', $unassigned[0]['title']);
	}

	public function testAssignmentNamesThePersonAndTheMoment(): void {
		$moment = new DateTimeImmutable('2026-03-01T09:00:00+00:00');

		$listData = $this->service->assignReviewer(
			listData: $this->list(),
			entryUuid: 'obj-2',
			reviewer: 'joris',
			assignedAt: $moment
		);

		$entry = $this->service->entry(listData: $listData, entryUuid: 'obj-2');
		$this->assertSame('joris', $entry['reviewer']);
		$this->assertSame($moment->format('c'), $entry['assignedAt']);
		$this->assertCount(1, $this->service->unassignedEntries(listData: $listData));
	}

	public function testUnassigningLeavesTheEntryOnTheListAndUnassigned(): void {
		$listData = $this->service->assignReviewer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			reviewer: null
		);

		$entry = $this->service->entry(listData: $listData, entryUuid: 'obj-1');
		$this->assertArrayNotHasKey('reviewer', $entry);
		$this->assertCount(3, $this->service->unassignedEntries(listData: $listData));
	}

	public function testAssigningAnEntryTheListDoesNotHoldIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/no entry for object obj-99/');

		$this->service->assignReviewer(
			listData: $this->list(),
			entryUuid: 'obj-99',
			reviewer: 'joris'
		);
	}

	/**
	 * The three answers, each recorded in the same history.
	 *
	 * @return array<string, array{0: string, 1: string|null}> answer and the new date it needs.
	 */
	public static function answerProvider(): array {
		return [
			'destroy' => [DestructionReviewService::ANSWER_DESTROY, null],
			'retain' => [DestructionReviewService::ANSWER_RETAIN, '2029-03-01'],
			'transfer' => [DestructionReviewService::ANSWER_TRANSFER, null],
		];
	}

	/**
	 * @dataProvider answerProvider
	 */
	public function testEveryAnswerLandsInOneDecisionHistory(string $answer, ?string $newDate): void {
		$listData = $this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: $answer,
			reviewer: 'els',
			reason: 'Reviewed against selectielijst 2020, row 11.1.2',
			newDate: $newDate,
			decidedAt: new DateTimeImmutable('2026-03-02T11:30:00+00:00')
		);

		$this->assertCount(1, $listData['decisions']);
		$decision = $listData['decisions'][0];

		$this->assertSame('obj-1', $decision['entry']);
		$this->assertSame($answer, $decision['answer']);
		$this->assertSame('els', $decision['reviewer']);
		$this->assertSame('2026-03-02T11:30:00+00:00', $decision['decidedAt']);
		$this->assertSame('Reviewed against selectielijst 2020, row 11.1.2', $decision['reason']);

		$entry = $this->service->entry(listData: $listData, entryUuid: 'obj-1');
		$this->assertSame($answer, $entry['decision']);
		$this->assertSame('els', $entry['decidedBy']);
	}

	public function testATransferRecordsTheListItWasHandedTo(): void {
		$listData = $this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: DestructionReviewService::ANSWER_TRANSFER,
			reviewer: 'els',
			reason: 'Blijvend bewaren, naar het e-depot',
			transferRef: 'transfer-list-7'
		);

		$this->assertSame('transfer-list-7', $listData['decisions'][0]['transferListUuid']);
	}

	public function testRetainingCarriesTheNewDateIntoTheRecord(): void {
		$listData = $this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: DestructionReviewService::ANSWER_RETAIN,
			reviewer: 'els',
			reason: 'Lopende bezwaarprocedure',
			newDate: '2028-03-01'
		);

		$this->assertSame('2028-03-01', $listData['decisions'][0]['newArchiefactiedatum']);
	}

	public function testRetainingWithoutANewDateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/new date is required/');

		$this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: DestructionReviewService::ANSWER_RETAIN,
			reviewer: 'els',
			reason: 'Lopende bezwaarprocedure'
		);
	}

	public function testAnAnswerWithoutAReasonIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/a reason is required/');

		$this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: DestructionReviewService::ANSWER_DESTROY,
			reviewer: 'els',
			reason: '   '
		);
	}

	public function testAFourthAnswerIsRefusedAndTheThreeAreNamed(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/destroy, retain, transfer/');

		$this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: 'approve',
			reviewer: 'els',
			reason: 'Looks fine'
		);
	}

	public function testAnEntryNobodyIsAccountableForRefusesRatherThanFallingOpen(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/has no reviewer/');

		$this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-2',
			answer: DestructionReviewService::ANSWER_DESTROY,
			reviewer: 'joris',
			reason: 'Past its date'
		);
	}

	public function testSomebodyElsesEntryIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/is els to answer, not joris/');

		$this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: DestructionReviewService::ANSWER_DESTROY,
			reviewer: 'joris',
			reason: 'Past its date'
		);
	}

	public function testAnEntryIsAnsweredOnce(): void {
		$listData = $this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: DestructionReviewService::ANSWER_DESTROY,
			reviewer: 'els',
			reason: 'Past its date'
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/already answered with "destroy"/');

		$this->service->recordAnswer(
			listData: $listData,
			entryUuid: 'obj-1',
			answer: DestructionReviewService::ANSWER_RETAIN,
			reviewer: 'els',
			reason: 'Changed my mind',
			newDate: '2030-01-01'
		);
	}

	public function testADecidedEntryCannotBeReassigned(): void {
		$listData = $this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: DestructionReviewService::ANSWER_DESTROY,
			reviewer: 'els',
			reason: 'Past its date'
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/cannot be reassigned/');

		$this->service->assignReviewer(listData: $listData, entryUuid: 'obj-1', reviewer: 'joris');
	}

	public function testAReviewerSeesOnlyTheirOwnUndecidedEntries(): void {
		$listData = $this->service->assignReviewer(
			listData: $this->list(),
			entryUuid: 'obj-2',
			reviewer: 'joris'
		);

		$pending = $this->service->pendingEntries(
			listData: $listData,
			listUuid: 'list-a',
			reviewer: 'els'
		);

		$this->assertCount(1, $pending);
		$this->assertSame('obj-1', $pending[0]['uuid']);
		$this->assertSame('list-a', $pending[0]['list']);
	}

	public function testAnAnsweredEntryLeavesTheWorklist(): void {
		$listData = $this->service->recordAnswer(
			listData: $this->list(),
			entryUuid: 'obj-1',
			answer: DestructionReviewService::ANSWER_DESTROY,
			reviewer: 'els',
			reason: 'Past its date'
		);

		$this->assertSame(
			[],
			$this->service->pendingEntries(listData: $listData, listUuid: 'list-a', reviewer: 'els')
		);
	}

	public function testOnlyEntriesThatHaveWaitedLongEnoughCountForAReminder(): void {
		$listData = $this->service->assignReviewer(
			listData: $this->list(),
			entryUuid: 'obj-2',
			reviewer: 'els',
			assignedAt: new DateTimeImmutable('2026-03-01T09:00:00+00:00')
		);
		$listData = $this->service->assignReviewer(
			listData: $listData,
			entryUuid: 'obj-3',
			reviewer: 'els',
			assignedAt: new DateTimeImmutable('2026-03-20T09:00:00+00:00')
		);

		$pending = $this->service->pendingEntries(
			listData: $listData,
			listUuid: 'list-a',
			reviewer: 'els',
			waitingSince: new DateTimeImmutable('2026-03-10T09:00:00+00:00')
		);

		// obj-1 carries a reviewer and no assignment time, which is what a list
		// written before assignment existed looks like; it counts as waiting.
		$this->assertSame(['obj-1', 'obj-2'], array_column($pending, 'uuid'));
	}

	public function testTheReminderPassCountsPerReviewerAcrossLists(): void {
		$first = $this->service->pendingEntries(
			listData: $this->list(),
			listUuid: 'list-a'
		);

		$second = $this->service->pendingEntries(
			listData: [
				'objects' => [
					['uuid' => 'obj-4', 'title' => 'Subsidie 2017/3', 'reviewer' => 'els'],
					['uuid' => 'obj-5', 'title' => 'Subsidie 2017/4', 'reviewer' => 'joris'],
				],
			],
			listUuid: 'list-b'
		);

		$counts = $this->service->countByReviewer(entries: array_merge($first, $second));

		$this->assertSame(['els' => 2, 'joris' => 1], $counts);
	}

	public function testAListWithNoEntriesAnswersEmptyRatherThanThrowing(): void {
		$this->assertSame([], $this->service->entries(listData: []));
		$this->assertSame([], $this->service->unassignedEntries(listData: ['objects' => 'not a list']));
		$this->assertNull($this->service->entry(listData: [], entryUuid: 'obj-1'));
	}
}
