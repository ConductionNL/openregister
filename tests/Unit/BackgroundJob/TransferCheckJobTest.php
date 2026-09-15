<?php

declare(strict_types=1);

/**
 * TransferCheckJob unit tests.
 *
 * 🔴 `findEligibleObjects()` WAS A STUB THAT RETURNED `[]`, behind a comment
 * saying a real implementation would query JSON field conditions. The job ran
 * daily on every install with an e-Depot configured and produced no transfer
 * list, ever, while logging "No objects eligible for transfer" as though it had
 * looked. These tests hold the wiring in place.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\TransferCheckJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for the e-Depot transfer check job.
 */
class TransferCheckJobTest extends TestCase {

	private RetentionService&MockObject $retentionService;
	private TransferListService&MockObject $transferListService;
	private TransferRecordService&MockObject $transferRecordService;
	private IAppConfig&MockObject $appConfig;
	private TransferCheckJob $job;

	protected function setUp(): void {
		parent::setUp();

		$this->retentionService = $this->createMock(RetentionService::class);
		$this->transferListService = $this->createMock(TransferListService::class);
		$this->transferRecordService = $this->createMock(TransferRecordService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'edepot_endpoint_url') {
					return 'https://edepot.example.org/sip';
				}

				return $default;
			}
		);

		$this->job = new TransferCheckJob(
			$this->createMock(ITimeFactory::class),
			$this->retentionService,
			$this->transferListService,
			$this->transferRecordService,
			$this->appConfig,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The job asks the retention sweep and puts what it gets on a transfer list.
	 *
	 * Before this change the sweep was never asked: the method returned `[]`
	 * unconditionally, so `createTransferList()` was never reached.
	 */
	public function testEligibleObjectsReachTheTransferList(): void {
		$object = new ObjectEntity();
		$object->setUuid('keep-uuid');

		$this->transferRecordService->method('listTransferLists')->willReturn([]);
		$this->transferListService->method('getObjectsOnActiveTransferLists')->willReturn([]);

		$this->retentionService->expects($this->once())
			->method('findEligibleForTransfer')
			->with([])
			->willReturn([$object]);

		$this->transferListService->expects($this->once())
			->method('createTransferList')
			->with([$object])
			->willReturn(['uuid' => 'list-uuid', 'objectCount' => 1]);

		$this->transferListService->expects($this->once())->method('notifyArchivists');

		$this->runJob();
	}//end testEligibleObjectsReachTheTransferList()

	/**
	 * Objects already on an active transfer list are excluded, the way the
	 * destruction sweep excludes objects on a pending destruction list.
	 */
	public function testObjectsOnActiveTransferListsAreExcluded(): void {
		$this->transferRecordService->method('listTransferLists')->willReturn(
			[['status' => 'in_review', 'objectReferences' => [['uuid' => 'listed-uuid']]]]
		);
		$this->transferListService->method('getObjectsOnActiveTransferLists')->willReturn(['listed-uuid']);

		$this->retentionService->expects($this->once())
			->method('findEligibleForTransfer')
			->with(['listed-uuid'])
			->willReturn([]);

		$this->transferListService->expects($this->never())->method('createTransferList');

		$this->runJob();
	}//end testObjectsOnActiveTransferListsAreExcluded()

	/**
	 * With no e-Depot configured the job does nothing at all, which is the
	 * self-limit it had before and must keep.
	 */
	public function testNothingHappensWithoutAnEdepot(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$job = new TransferCheckJob(
			$this->createMock(ITimeFactory::class),
			$this->retentionService,
			$this->transferListService,
			$this->transferRecordService,
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);

		$this->retentionService->expects($this->never())->method('findEligibleForTransfer');

		$method = (new ReflectionClass(TransferCheckJob::class))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end testNothingHappensWithoutAnEdepot()

	/**
	 * Invoke the job's protected run() method.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$method = (new ReflectionClass(TransferCheckJob::class))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, null);
	}//end runJob()
}//end class
