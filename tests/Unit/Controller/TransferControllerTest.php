<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Controller\TransferController}.
 *
 * The former placeholder stubs are now real (archival-transfer-hardening,
 * OR-AD-2/OR-AD-3): index/show serve the durable records; create loads the
 * persisted list, refuses a non-approved one, and dispatches
 * TransferExecutionJob for an approved one.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\BackgroundJob\TransferExecutionJob;
use OCA\OpenRegister\Controller\TransferController;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TransferControllerTest.
 */
class TransferControllerTest extends TestCase {

	private TransferRecordService&MockObject $recordService;

	private IJobList&MockObject $jobList;

	private IRequest&MockObject $request;

	private TransferController $controller;

	private \OCA\OpenRegister\Service\Edepot\TransferListService&MockObject $listService;

	protected function setUp(): void {
		$this->recordService = $this->createMock(TransferRecordService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->request = $this->createMock(IRequest::class);
		$this->listService = $this->createMock(\OCA\OpenRegister\Service\Edepot\TransferListService::class);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('archivist-1');
		$session = $this->createMock(\OCP\IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = new TransferController(
			'openregister',
			$this->request,
			$this->recordService,
			$this->jobList,
			$this->createMock(LoggerInterface::class),
			$this->listService,
			$session,
		);

	}//end setUp()

	/**
	 * 🔴 THE DECISION THAT MAKES THE FLOW REACHABLE AT ALL.
	 *
	 * `create()` dispatches only an `approved` list, and nothing could set that
	 * status: the service method was implemented, specified, and called by
	 * nobody. A list could be built and then never moved.
	 *
	 * @return void
	 */
	public function testApproveStampsTheActingArchivist(): void {
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 'tl-1', 'status' => 'in_review']);
		$this->listService->expects($this->once())
			->method('approveTransferList')
			->with(['uuid' => 'tl-1', 'status' => 'in_review'], 'archivist-1')
			->willReturn(['uuid' => 'tl-1', 'status' => 'approved']);

		$response = $this->controller->approve(id: 'tl-1');

		$this->assertSame('approved', $response->getData()['status']);
	}//end testApproveStampsTheActingArchivist()

	/**
	 * Approving a list that is not in review is a REFUSAL the caller can act
	 * on, not a fault. 409, never 500.
	 *
	 * @return void
	 */
	public function testApprovingAListThatIsNotInReviewAnswers409(): void {
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 'tl-1', 'status' => 'approved']);
		$this->listService->method('approveTransferList')
			->willThrowException(new \InvalidArgumentException('Cannot approve transfer list with status'));

		$response = $this->controller->approve(id: 'tl-1');

		$this->assertSame(\OCP\AppFramework\Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('wrong-status', $response->getData()['reason']);
	}//end testApprovingAListThatIsNotInReviewAnswers409()

	/**
	 * A decision on a list that does not exist is a 404, and must not reach the
	 * service at all.
	 *
	 * @return void
	 */
	public function testDecidingOnAnUnknownListIs404(): void {
		$this->recordService->method('loadTransferList')->willReturn(null);
		$this->listService->expects($this->never())->method('approveTransferList');

		$this->assertSame(
			\OCP\AppFramework\Http::STATUS_NOT_FOUND,
			$this->controller->approve(id: 'ghost')->getStatus()
		);
	}//end testDecidingOnAnUnknownListIs404()

	/**
	 * 🔑 A REJECTION WITHOUT A REASON IS NOT A REJECTION. An archivist refusing
	 * a transfer is a records-management decision somebody will have to justify
	 * later, so the reason is required rather than defaulted to empty.
	 *
	 * @return void
	 */
	public function testRejectingWithoutAReasonIsRefused(): void {
		$this->request->method('getParam')->willReturn('');
		$this->listService->expects($this->never())->method('rejectTransferList');

		$response = $this->controller->reject(id: 'tl-1');

		$this->assertSame(\OCP\AppFramework\Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testRejectingWithoutAReasonIsRefused()

	/**
	 * A rejection carries its reason through to the service.
	 *
	 * @return void
	 */
	public function testRejectPassesTheReasonThrough(): void {
		$this->request->method('getParam')->willReturn('incomplete metadata');
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 'tl-1', 'status' => 'in_review']);
		$this->listService->expects($this->once())
			->method('rejectTransferList')
			->with($this->anything(), 'archivist-1', 'incomplete metadata')
			->willReturn(['uuid' => 'tl-1', 'status' => 'rejected']);

		$this->assertSame('rejected', $this->controller->reject(id: 'tl-1')->getData()['status']);
	}//end testRejectPassesTheReasonThrough()

	/**
	 * Index returns the persisted transfer lists with a total.
	 *
	 * @return void
	 */
	public function testIndexReturnsPersistedLists(): void {
		$lists = [['uuid' => 'a'], ['uuid' => 'b']];
		$this->recordService->method('listTransferLists')->willReturn($lists);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => $lists, 'total' => 2], $response->getData());

	}//end testIndexReturnsPersistedLists()

	/**
	 * Show returns a persisted list, or 404 when absent.
	 *
	 * @return void
	 */
	public function testShow(): void {
		$this->recordService->method('loadTransferList')->willReturnMap(
			[
				['t1', ['uuid' => 't1', 'status' => 'approved']],
				['ghost', null],
			]
		);

		$ok = $this->controller->show('t1');
		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame('t1', $ok->getData()['uuid']);

		$missing = $this->controller->show('ghost');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());

	}//end testShow()

	/**
	 * Create dispatches TransferExecutionJob for an approved list (202).
	 *
	 * @return void
	 */
	public function testCreateDispatchesApprovedTransfer(): void {
		$this->request->method('getParams')->willReturn(['transferListUuid' => 't1']);
		$this->recordService->method('loadTransferList')->willReturn(
			['uuid' => 't1', 'status' => TransferListService::STATUS_APPROVED]
		);

		$dispatched = null;
		$this->jobList->expects($this->once())->method('add')->willReturnCallback(
			function ($job, $argument) use (&$dispatched): void {
				$dispatched = ['job' => $job, 'argument' => $argument];
			}
		);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(TransferExecutionJob::class, $dispatched['job']);
		$this->assertSame('t1', $dispatched['argument']['transferListId']);
		$this->assertSame(1, $dispatched['argument']['attempt']);

	}//end testCreateDispatchesApprovedTransfer()

	/**
	 * Create refuses a non-approved list (409) and dispatches nothing.
	 *
	 * @return void
	 */
	public function testCreateRefusesNonApprovedList(): void {
		$this->request->method('getParams')->willReturn(['transferListUuid' => 't1']);
		$this->recordService->method('loadTransferList')->willReturn(
			['uuid' => 't1', 'status' => TransferListService::STATUS_IN_REVIEW]
		);

		$this->jobList->expects($this->never())->method('add');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testCreateRefusesNonApprovedList()

	/**
	 * Create validates the required uuid and 404s an unknown list.
	 *
	 * @return void
	 */
	public function testCreateValidatesInput(): void {
		$this->request->method('getParams')->willReturn([]);
		$bad = $this->controller->create();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $bad->getStatus());

		$this->setUp();
		$this->request->method('getParams')->willReturn(['transferListUuid' => 'ghost']);
		$this->recordService->method('loadTransferList')->willReturn(null);
		$missing = $this->controller->create();
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());

	}//end testCreateValidatesInput()
}//end class
