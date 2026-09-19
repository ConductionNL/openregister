<?php

declare(strict_types=1);

/**
 * A recorded retain or transfer keeps a record out of the destruction run.
 *
 * The defect these cover was silent and irreversible: a named reviewer could
 * answer `retain` on an entry, the answer was stamped onto it, and nothing
 * downstream read the stamp — so `approve_all` (the default) still handed the
 * entry to DestructionExecutionJob and the record was hard-deleted. A sign-off
 * step that does not bind is worse than none, because it leaves a record
 * suggesting review happened.
 *
 * These assert the binding at the point of approval, and the ordering that
 * makes it survive: handlePartialApproval() REPLACES `excludedObjects`, so
 * withholding has to run after it or its result is overwritten.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Withholding tests for DestructionService::approveList().
 */
class DestructionWithholdingTest extends TestCase {
	private DestructionService $service;

	protected function setUp(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('approver');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service = new DestructionService(
			objectMapper: $this->createMock(MagicMapper::class),
			appConfig: $this->createMock(IAppConfig::class),
			jobList: $this->createMock(IJobList::class),
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A list with one entry per decision kind.
	 *
	 * @return array<string, mixed> The list.
	 */
	private function listWithDecisions(): array {
		return [
			'status' => 'pending',
			'objectCount' => 3,
			'objects' => [
				['uuid' => 'keep-me', 'decision' => DestructionReviewService::ANSWER_RETAIN],
				['uuid' => 'move-me', 'decision' => DestructionReviewService::ANSWER_TRANSFER],
				['uuid' => 'destroy-me', 'decision' => 'destroy'],
			],
			'decisions' => [
				[
					'entry' => 'keep-me',
					'answer' => DestructionReviewService::ANSWER_RETAIN,
					'reason' => 'Still needed for the ongoing objection.',
				],
			],
			'approvals' => [],
		];
	}//end listWithDecisions()

	/**
	 * @return array<int, string> The uuids, in order.
	 */
	private function uuidsOf(array $entries): array {
		return array_map(static fn (array $e): string => (string)($e['uuid'] ?? ''), $entries);
	}//end uuidsOf()

	public function testApproveAllDestroysOnlyTheEntryNobodyWithheld(): void {
		$result = $this->service->approveList(
			destructionList: $this->listWithDecisions(),
			action: 'approve_all'
		);

		$this->assertSame(['destroy-me'], $this->uuidsOf($result['objects']));
		$this->assertSame(['keep-me', 'move-me'], $this->uuidsOf($result['excludedObjects']));
	}//end testApproveAllDestroysOnlyTheEntryNobodyWithheld()

	public function testObjectCountFollowsWhatIsActuallyLeft(): void {
		$result = $this->service->approveList(
			destructionList: $this->listWithDecisions(),
			action: 'approve_all'
		);

		// The certificate and the execution job both read this.
		$this->assertSame(1, $result['objectCount']);
	}//end testObjectCountFollowsWhatIsActuallyLeft()

	public function testTheRetentionRouteCanWithholdBeforeItCountsAnything(): void {
		// RetentionController::approveDestructionList() is a second, independent
		// approval implementation that never calls approveList(). It computes its
		// audit trail and its 200 response from the list BEFORE the execution job
		// runs, and never corrects them, so it calls this directly. The records
		// were always safe - the job refuses them - but a destruction certificate
		// reporting three objects where one was destroyed is its own defect.
		$result = $this->service->withholdDecidedEntries($this->listWithDecisions());

		$this->assertSame(['destroy-me'], $this->uuidsOf($result['objects']));
		$this->assertSame(1, $result['objectCount']);
		$this->assertSame(['keep-me', 'move-me'], $this->uuidsOf($result['excludedObjects']));
	}//end testTheRetentionRouteCanWithholdBeforeItCountsAnything()

	public function testAWithheldEntryCarriesTheReviewersOwnReason(): void {
		$result = $this->service->approveList(
			destructionList: $this->listWithDecisions(),
			action: 'approve_all'
		);

		$withheld = $result['excludedObjects'][0];
		$this->assertSame('uitgezonderd', $withheld['status']);
		$this->assertStringContainsString('ongoing objection', (string)$withheld['exclusionReason']);
	}//end testAWithheldEntryCarriesTheReviewersOwnReason()

	public function testTheLastRecordedDecisionWinsOverAnEarlierDraft(): void {
		$list = $this->listWithDecisions();
		$list['decisions'][] = [
			'entry' => 'keep-me',
			'answer' => DestructionReviewService::ANSWER_RETAIN,
			'reason' => 'Corrected: retained under the ten-year term.',
		];

		$result = $this->service->approveList(destructionList: $list, action: 'approve_all');

		$reason = (string)$result['excludedObjects'][0]['exclusionReason'];
		$this->assertStringContainsString('ten-year term', $reason);
		$this->assertStringNotContainsString('ongoing objection', $reason);
	}//end testTheLastRecordedDecisionWinsOverAnEarlierDraft()

	public function testWithholdingComposesWithAnExplicitPartialExclusion(): void {
		// The ordering bug this guards: handlePartialApproval() ASSIGNS
		// excludedObjects, so withholding must run after it or be overwritten.
		$result = $this->service->approveList(
			destructionList: $this->listWithDecisions(),
			action: 'approve_partial',
			excludedIds: ['destroy-me'],
			exclusionReasons: ['destroy-me' => 'Approver pulled this one.']
		);

		$this->assertSame([], $this->uuidsOf($result['objects']));
		$this->assertEqualsCanonicalizing(
			['destroy-me', 'keep-me', 'move-me'],
			$this->uuidsOf($result['excludedObjects'])
		);
	}//end testWithholdingComposesWithAnExplicitPartialExclusion()

	public function testApprovingTwiceWithholdsNothingTwice(): void {
		$once = $this->service->approveList(
			destructionList: $this->listWithDecisions(),
			action: 'approve_all'
		);
		$twice = $this->service->approveList(destructionList: $once, action: 'approve_all');

		$this->assertSame($this->uuidsOf($once['objects']), $this->uuidsOf($twice['objects']));
		$this->assertSame(
			$this->uuidsOf($once['excludedObjects']),
			$this->uuidsOf($twice['excludedObjects'])
		);
	}//end testApprovingTwiceWithholdsNothingTwice()

	public function testASecondPassDoesNotOverwriteTheFirstPassExclusionRecord(): void {
		// The dual sign-off sequence. handlePartialApproval() used to ASSIGN
		// excludedObjects rather than merge, so pass 2 replaced it with only its
		// own exclusions; withholdDecidedEntries() then found nothing left in
		// `objects` and returned early, and the retained and transferred entries
		// vanished from the record. The objects stayed safe - they were already
		// out of `objects` - but a destruction certificate is exactly the document
		// that must name what was withheld and why.
		$firstPass = $this->service->approveList(
			destructionList: $this->listWithDecisions(),
			action: 'approve_all'
		);

		$this->assertSame(['keep-me', 'move-me'], $this->uuidsOf($firstPass['excludedObjects']));

		$secondPass = $this->service->approveList(
			destructionList: $firstPass,
			action: 'approve_partial',
			excludedIds: ['destroy-me']
		);

		$this->assertContains('keep-me', $this->uuidsOf($secondPass['excludedObjects']));
		$this->assertContains('move-me', $this->uuidsOf($secondPass['excludedObjects']));
		$this->assertContains('destroy-me', $this->uuidsOf($secondPass['excludedObjects']));
	}//end testASecondPassDoesNotOverwriteTheFirstPassExclusionRecord()

	public function testAnEntryWithNoDecisionIsStillDestroyed(): void {
		$list = $this->listWithDecisions();
		$list['objects'] = [['uuid' => 'undecided']];

		$result = $this->service->approveList(destructionList: $list, action: 'approve_all');

		$this->assertSame(['undecided'], $this->uuidsOf($result['objects']));
		$this->assertSame([], ($result['excludedObjects'] ?? []));
	}//end testAnEntryWithNoDecisionIsStillDestroyed()
}//end class
