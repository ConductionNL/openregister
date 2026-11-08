<?php

/**
 * Golden outcomes of TransferController's access paths, pinned before a refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\TransferExecutionJob;
use OCA\OpenRegister\Controller\TransferController;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\TransferControllerFactory;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the admin-only posture and every allowed/refused transfer outcome.
 */
class TransferControllerA24GoldenTest extends TestCase {

	/**
	 * records.
	 *
	 * @var TransferRecordService&MockObject
	 */
	private TransferRecordService&MockObject $records;

	/**
	 * lists.
	 *
	 * @var TransferListService&MockObject
	 */
	private TransferListService&MockObject $lists;

	/**
	 * jobList.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobList;

	/**
	 * logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * controller.
	 *
	 * @var TransferController
	 */
	private TransferController $controller;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->records = $this->createMock(TransferRecordService::class);
		$this->lists = $this->createMock(TransferListService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivist-9');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = TransferControllerFactory::create(
			'openregister',
			$this->request,
			$this->records,
			$this->jobList,
			$this->logger,
			$this->lists,
			$session
		);
	}//end setUp()

	/**
	 * Every action is admin only and csrf posture is unchanged.
	 *
	 * @return void
	 */
	public function testEveryActionIsAdminOnlyAndCsrfPostureIsUnchanged(): void {
		$expected = [
			'index' => true,
			'show' => true,
			'approve' => true,
			'reject' => true,
			'create' => true,
		];
		$actual = [];
		$class = new ReflectionClass(TransferController::class);
		foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== TransferController::class || $method->isConstructor() === true) {
				continue;
			}

			$this->assertSame([], $method->getAttributes(NoAdminRequired::class), $method->getName());
			$this->assertSame([], $method->getAttributes(PublicPage::class), $method->getName());
			$this->assertStringNotContainsString('@NoAdminRequired', (string)$method->getDocComment(), $method->getName());
			$this->assertStringNotContainsString('@PublicPage', (string)$method->getDocComment(), $method->getName());
			$actual[$method->getName()] = ($method->getAttributes(NoCSRFRequired::class) !== []);
		}

		ksort($expected);
		ksort($actual);
		$this->assertSame($expected, $actual);
	}

	/**
	 * Approve allowed stamps archivist.
	 *
	 * @return void
	 */
	public function testApproveAllowedStampsArchivist(): void {
		$list = ['uuid' => 'tl-1', 'status' => 'in_review'];
		$this->records->method('loadTransferList')->with('tl-1')->willReturn($list);
		$this->lists->expects($this->once())->method('approveTransferList')->with($list, 'archivist-9')->willReturn(['status' => 'approved']);
		$r = $this->controller->approve('tl-1');
		$this->assertSame([200, ['status' => 'approved']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Approve refused wrong status is409.
	 *
	 * @return void
	 */
	public function testApproveRefusedWrongStatusIs409(): void {
		$this->records->method('loadTransferList')->willReturn(['uuid' => 'tl-1']);
		$this->lists->method('approveTransferList')->willThrowException(new InvalidArgumentException('not in review'));
		$r = $this->controller->approve('tl-1');
		$this->assertSame([409, ['error' => 'not in review', 'reason' => 'wrong-status']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Approve missing is404.
	 *
	 * @return void
	 */
	public function testApproveMissingIs404(): void {
		$this->records->method('loadTransferList')->willReturn(null);
		$this->lists->expects($this->never())->method('approveTransferList');
		$r = $this->controller->approve('tl-x');
		$this->assertSame([404, ['error' => "Transfer list 'tl-x' not found"]], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Reject outcomes.
	 *
	 * @return void
	 */
	public function testRejectOutcomes(): void {
		$this->request->method('getParam')->with('reason', '')->willReturnOnConsecutiveCalls('  ', ' too early ', 'x');
		$list = ['uuid' => 'tl-1'];
		$this->records->method('loadTransferList')->willReturn($list);
		$this->lists->expects($this->exactly(2))->method('rejectTransferList')
			->willReturnCallback(
				function (array $transferList, string $archivistId, string $reason) use ($list): array {
					$this->assertSame([$list, 'archivist-9'], [$transferList, $archivistId]);
					if ($reason === 'x') {
						throw new InvalidArgumentException('done');
					}

					return ['status' => 'rejected', 'reason' => $reason];
				}
			);
		$r = $this->controller->reject('tl-1');
		$this->assertSame([400, ['error' => 'A rejection needs a reason.']], [$r->getStatus(), $r->getData()]);
		$r = $this->controller->reject('tl-1');
		$this->assertSame([200, ['status' => 'rejected', 'reason' => 'too early']], [$r->getStatus(), $r->getData()]);
		$r = $this->controller->reject('tl-1');
		$this->assertSame([409, ['error' => 'done', 'reason' => 'wrong-status']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Index and show.
	 *
	 * @return void
	 */
	public function testIndexAndShow(): void {
		$this->records->method('listTransferLists')->willReturn([['uuid' => 'a'], ['uuid' => 'b']]);
		$this->records->method('loadTransferList')->willReturnOnConsecutiveCalls(['uuid' => 'a'], null);
		$r = $this->controller->index();
		$this->assertSame([200, ['results' => [['uuid' => 'a'], ['uuid' => 'b']], 'total' => 2]], [$r->getStatus(), $r->getData()]);
		$r = $this->controller->show('a');
		$this->assertSame([200, ['uuid' => 'a']], [$r->getStatus(), $r->getData()]);
		$r = $this->controller->show('z');
		$this->assertSame([404, ['error' => "Transfer list 'z' not found"]], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Create refusals.
	 *
	 * @return void
	 */
	public function testCreateRefusals(): void {
		$this->request->method('getParams')->willReturnOnConsecutiveCalls([], ['transferListUuid' => 'tl-1'], ['transferListUuid' => 'tl-1']);
		$this->records->method('loadTransferList')->willReturnOnConsecutiveCalls(null, ['status' => 'in_review']);
		$this->jobList->expects($this->never())->method('add');
		$r = $this->controller->create();
		$this->assertSame([400, ['error' => 'transferListUuid is required']], [$r->getStatus(), $r->getData()]);
		$r = $this->controller->create();
		$this->assertSame([404, ['error' => "Transfer list 'tl-1' not found"]], [$r->getStatus(), $r->getData()]);
		$r = $this->controller->create();
		$this->assertSame(409, $r->getStatus());
		$this->assertSame(
			['error' => "Transfer list must be 'approved' to initiate; current status 'in_review'", 'status' => 'in_review'],
			$r->getData()
		);
	}

	/**
	 * Create allowed queues and logs.
	 *
	 * @return void
	 */
	public function testCreateAllowedQueuesAndLogs(): void {
		$this->request->method('getParams')->willReturn(['transferListUuid' => 'tl-1']);
		$this->records->method('loadTransferList')->willReturn(['status' => TransferListService::STATUS_APPROVED]);
		$this->jobList->expects($this->once())->method('add')->with(TransferExecutionJob::class, ['transferListId' => 'tl-1', 'attempt' => 1]);
		$this->logger->expects($this->once())->method('info')->with(
			'[TransferController] Dispatched transfer execution',
			$this->callback(fn (array $c): bool => $c['transferListUuid'] === 'tl-1' && isset($c['file'], $c['line']) === true)
		);
		$r = $this->controller->create();
		$this->assertSame(
			[202, ['message' => 'Transfer queued for execution', 'transferListUuid' => 'tl-1', 'status' => 'queued']],
			[$r->getStatus(), $r->getData()]
		);
	}

	/**
	 * Create failure is500 and logged.
	 *
	 * @return void
	 */
	public function testCreateFailureIs500AndLogged(): void {
		$this->request->method('getParams')->willReturn(['transferListUuid' => 'tl-1']);
		$this->records->method('loadTransferList')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('[TransferController] Failed to initiate transfer: db down');
		$r = $this->controller->create();
		$this->assertSame([500, ['error' => 'Failed to initiate transfer.']], [$r->getStatus(), $r->getData()]);
	}
}//end class
