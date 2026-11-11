<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\Support\TransferExecutionPlatform;
use OCA\OpenRegister\BackgroundJob\Support\TransferExecutionRecords;
use OCA\OpenRegister\BackgroundJob\Support\TransferExecutionTransports;
use OCA\OpenRegister\BackgroundJob\TransferExecutionJob;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Branches of TransferExecutionJob the main test leaves out: invalid
 * arguments, a missing list, a throwing attempt, transport selection and a
 * non-positive attempt cap.
 */
class TransferExecutionJobBehaviourTest extends TestCase {

	private EdepotTransferService&MockObject $transferService;

	private TransferRecordService&MockObject $recordService;

	private TransferListService&MockObject $listService;

	private IJobList&MockObject $jobList;

	private LoggerInterface&MockObject $logger;

	private SftpTransport&MockObject $sftp;

	private RestApiTransport&MockObject $rest;

	private OpenConnectorTransport&MockObject $oc;

	/** @var array<string, string> */
	private array $config = [];

	protected function setUp(): void {
		parent::setUp();
		$this->transferService = $this->createMock(EdepotTransferService::class);
		$this->recordService = $this->createMock(TransferRecordService::class);
		$this->listService = $this->createMock(TransferListService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->sftp = $this->createMock(SftpTransport::class);
		$this->rest = $this->createMock(RestApiTransport::class);
		$this->oc = $this->createMock(OpenConnectorTransport::class);
		$this->config = [];
	}

	private function runJob(mixed $argument): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);

		$job = new TransferExecutionJob(
			time: $time,
			records: new TransferExecutionRecords(
				transferService: $this->transferService,
				recordService: $this->recordService,
				listService: $this->listService
			),
			transports: new TransferExecutionTransports(
				sftpTransport: $this->sftp,
				restTransport: $this->rest,
				ocTransport: $this->oc
			),
			platform: new TransferExecutionPlatform(
				jobList: $this->jobList,
				appConfig: $appConfig,
				logger: $this->logger
			)
		);
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, $argument);
	}

	public function testNonArrayArgumentIsRejectedWithoutLoading(): void {
		$this->recordService->expects($this->never())->method('loadTransferList');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('missing transferListId'));

		$this->runJob('t1');
	}

	public function testArgumentWithoutIdIsRejectedWithoutLoading(): void {
		$this->recordService->expects($this->never())->method('loadTransferList');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('missing transferListId'));

		$this->runJob(['attempt' => 2]);
	}

	public function testUnknownListIsLoggedAndNotAttempted(): void {
		$this->recordService->expects($this->once())->method('loadTransferList')->with('t9')->willReturn(null);
		$this->transferService->expects($this->never())->method('executeAttempt');
		$this->recordService->expects($this->never())->method('saveTransferList');
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('Transfer list not found: t9'));

		$this->runJob(['transferListId' => 't9']);
	}

	public function testThrowingAttemptIsRescheduledAsInProgress(): void {
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 't1', 'status' => 'approved']);
		$this->transferService->method('executeAttempt')->willThrowException(new \RuntimeException('boom'));

		$saved = null;
		$this->recordService->expects($this->once())->method('saveTransferList')->willReturnCallback(
			function (array $list) use (&$saved): array {
				$saved = $list;
				return $list;
			}
		);
		$this->jobList->expects($this->once())->method('scheduleAfter')->with(
			TransferExecutionJob::class,
			$this->greaterThan(1000000),
			['transferListId' => 't1', 'attempt' => 2]
		);
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('Attempt threw: boom'));

		$this->runJob(['transferListId' => 't1']);

		$this->assertSame('in_progress', $saved['status']);
	}

	public function testListWithoutStatusIsTreatedAsFailedAndRetried(): void {
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 't1']);
		$this->transferService->method('executeAttempt')->willReturn(['uuid' => 't1']);
		$this->jobList->expects($this->once())->method('scheduleAfter');
		$this->listService->expects($this->never())->method('notifyArchivists');

		$this->runJob(['transferListId' => 't1', 'attempt' => 1]);
	}

	public function testSftpTransportIsUsedWhenConfigured(): void {
		$this->config['edepot_transport'] = 'sftp';
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 't1']);
		$this->transferService->expects($this->once())->method('executeAttempt')
			->with(['uuid' => 't1'], $this->identicalTo($this->sftp), 1)
			->willReturn(['uuid' => 't1', 'status' => 'completed']);

		$this->runJob(['transferListId' => 't1']);
	}

	public function testOpenConnectorTransportIsUsedWhenConfigured(): void {
		$this->config['edepot_transport'] = 'openconnector';
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 't1']);
		$this->transferService->expects($this->once())->method('executeAttempt')
			->with(['uuid' => 't1'], $this->identicalTo($this->oc), 1)
			->willReturn(['uuid' => 't1', 'status' => 'completed']);

		$this->runJob(['transferListId' => 't1']);
	}

	public function testRestTransportIsTheDefault(): void {
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 't1']);
		$this->transferService->expects($this->once())->method('executeAttempt')
			->with(['uuid' => 't1'], $this->identicalTo($this->rest), 1)
			->willReturn(['uuid' => 't1', 'status' => 'completed']);

		$this->runJob(['transferListId' => 't1']);
	}

	public function testNonPositiveCapFallsBackToTenAttempts(): void {
		$this->config['edepot_transfer_max_attempts'] = '0';
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 't1']);
		$this->transferService->method('executeAttempt')->willReturn(['uuid' => 't1', 'status' => 'partially_failed']);

		// Attempt 9 is below the default cap of 10: retried, not escalated.
		$this->jobList->expects($this->once())->method('scheduleAfter');
		$this->listService->expects($this->never())->method('notifyArchivists');

		$this->runJob(['transferListId' => 't1', 'attempt' => 9]);
	}

	public function testNonPositiveCapStillEscalatesAtTenAttempts(): void {
		$this->config['edepot_transfer_max_attempts'] = '-3';
		$this->recordService->method('loadTransferList')->willReturn(['uuid' => 't1']);
		$this->transferService->method('executeAttempt')->willReturn(['uuid' => 't1', 'status' => 'partially_failed']);

		$saved = null;
		$this->recordService->method('saveTransferList')->willReturnCallback(
			function (array $list) use (&$saved): array {
				$saved = $list;
				return $list;
			}
		);
		$this->jobList->expects($this->never())->method('scheduleAfter');
		$this->listService->expects($this->once())->method('notifyArchivists');

		$this->runJob(['transferListId' => 't1', 'attempt' => 10]);

		$this->assertSame('failed', $saved['status']);
	}
}
