<?php

/**
 * DestructionService::approveList(): the approval records `destroyedOverConflict`,
 * the number of entries a reviewer destroyed over an acknowledged link conflict.
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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Archival\ApprovalChoice;
use OCA\OpenRegister\Service\Archival\ApprovalRequest;
use OCA\OpenRegister\Service\Archival\DestructionInfrastructure;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Archival\DestructionService
 * @uses   \OCA\OpenRegister\Service\Archival\ApprovalChoice
 * @uses   \OCA\OpenRegister\Service\Archival\ApprovalRequest
 * @uses   \OCA\OpenRegister\Service\Archival\DestructionApproval
 * @uses   \OCA\OpenRegister\Service\Archival\DestructionInfrastructure
 */
class DestructionServiceApproveTest extends TestCase {

	private IJobList&MockObject $jobList;

	private DestructionService $service;

	protected function setUp(): void {
		parent::setUp();
		$objects = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['update', 'find'])->getMock();
		$this->jobList = $this->createMock(IJobList::class);
		$appConfig     = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service = new DestructionService(
			new DestructionInfrastructure($objects, $appConfig, $this->jobList),
			$session,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A list in review whose history holds the given decisions.
	 *
	 * @param array<int, array<string, mixed>> $decisions The decision history.
	 *
	 * @return array<string, mixed>
	 */
	private function listWith(array $decisions): array {
		return [
			'status' => DestructionService::STATUS_IN_REVIEW,
			'objects' => [
				['uuid' => 'a', 'decision' => 'destroy'],
				['uuid' => 'b', 'decision' => 'destroy'],
				['uuid' => 'c', 'decision' => 'destroy'],
			],
			'decisions' => $decisions,
			'approvals' => [],
		];
	}//end listWith()

	private function approve(array $list): array {
		return $this->service->approveList(
			$list,
			new ApprovalRequest(choice: new ApprovalChoice('approve_all'), requiresDual: false, listUuid: 'dl-1')
		);
	}//end approve()

	public function testTheApprovalCountsTwoEntriesDestroyedOverAConflict(): void {
		$conflict = ['kind' => 'linked-kept-permanently', 'direction' => 'incoming', 'uuid' => 'besluit-1'];
		$list     = $this->listWith(
			[
				['entry' => 'a', 'answer' => 'destroy', 'reason' => 'r', 'overriddenConflicts' => [$conflict]],
				['entry' => 'b', 'answer' => 'destroy', 'reason' => 'r', 'overriddenConflicts' => [$conflict, $conflict]],
				['entry' => 'c', 'answer' => 'destroy', 'reason' => 'r'],
			]
		);
		$this->jobList->expects($this->once())->method('add');

		$approved = $this->approve(list: $list);

		$this->assertSame(DestructionService::STATUS_APPROVED, $approved['status']);
		$this->assertCount(1, $approved['approvals']);
		$this->assertSame(2, $approved['approvals'][0]['destroyedOverConflict']);
		$this->assertSame('archivaris-1', $approved['approvals'][0]['userId']);
	}

	public function testAListWithoutOverriddenConflictsRecordsZero(): void {
		$approved = $this->approve(list: $this->listWith([['entry' => 'a', 'answer' => 'destroy', 'reason' => 'r', 'overriddenConflicts' => []]]));

		$this->assertSame(0, $approved['approvals'][0]['destroyedOverConflict']);
	}

	public function testOnlyDestroyAnswersCountAndAnEntryCountsOnce(): void {
		$conflict = ['kind' => 'linked-on-hold'];
		$approved = $this->approve(
			list: $this->listWith(
				[
					['entry' => 'a', 'answer' => 'destroy', 'overriddenConflicts' => [$conflict]],
					['entry' => 'a', 'answer' => 'destroy', 'overriddenConflicts' => [$conflict]],
					['entry' => 'b', 'answer' => 'retain', 'overriddenConflicts' => [$conflict]],
					'not a decision',
				]
			)
		);

		$this->assertSame(1, $approved['approvals'][0]['destroyedOverConflict']);
	}

	public function testAListWithoutAHistoryRecordsZero(): void {
		$list = $this->listWith([]);
		unset($list['decisions']);

		$this->assertSame(0, $this->approve(list: $list)['approvals'][0]['destroyedOverConflict']);
	}
}
