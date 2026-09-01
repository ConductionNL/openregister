<?php

declare(strict_types=1);

/**
 * DestructionService Unit Tests
 *
 * Tests the destruction workflow orchestration service.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\BackgroundJob\DestructionExecutionJob;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for DestructionService
 */
class DestructionServiceTest extends TestCase {
	private MagicMapper&MockObject $objectMapper;
	private LegalHoldService&MockObject $legalHoldService;
	private IAppConfig&MockObject $appConfig;
	private IJobList&MockObject $jobList;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private DestructionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['update'])
			->addMethods(['findByUuid'])
			->getMock();
		$this->legalHoldService = $this->createMock(LegalHoldService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new DestructionService(
			$this->objectMapper,
			$this->legalHoldService,
			$this->appConfig,
			$this->jobList,
			$this->userSession,
			$this->logger
		);

		// Default app config behavior.
		$this->appConfig->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
				return $default;
			});
	}

	private function setupMockUser(string $uid = 'archivaris-1'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * Test creating a destruction list from eligible objects.
	 */
	public function testCreateDestructionList(): void {
		$objects = [
			[
				'uuid' => 'uuid-1',
				'title' => 'Zaak 1',
				'schema' => 5,
				'register' => 1,
				'archiefactiedatum' => '2025-01-01',
				'classification' => 'B1',
			],
			[
				'uuid' => 'uuid-2',
				'title' => 'Zaak 2',
				'schema' => 5,
				'register' => 1,
				'archiefactiedatum' => '2025-06-01',
				'classification' => 'B2',
			],
		];

		$result = $this->service->createDestructionList($objects);

		$this->assertNotEmpty($result);
		$this->assertEquals(DestructionService::STATUS_IN_REVIEW, $result['status']);
		$this->assertEquals(2, $result['objectCount']);
		$this->assertCount(2, $result['objects']);
	}

	/**
	 * Test creating an empty destruction list returns empty.
	 */
	public function testCreateDestructionListEmpty(): void {
		$result = $this->service->createDestructionList([]);
		$this->assertEmpty($result);
	}

	/**
	 * Test full approval queues execution job.
	 */
	public function testApproveListFull(): void {
		$this->setupMockUser('archivaris-1');

		$list = [
			'status' => DestructionService::STATUS_IN_REVIEW,
			'objects' => [['uuid' => 'uuid-1']],
			'approvals' => [],
		];

		$this->jobList->expects($this->once())->method('add');

		$result = $this->service->approveList($list, 'approve_all');

		$this->assertEquals(DestructionService::STATUS_APPROVED, $result['status']);
		$this->assertCount(1, $result['approvals']);
	}

	/**
	 * Test partial approval excludes specified objects.
	 */
	public function testApproveListPartial(): void {
		$this->setupMockUser('archivaris-1');

		// Mock finding the excluded object for date extension.
		$mockObject = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->addMethods(['getRetention', 'setRetention'])
			->getMock();
		$mockObject->method('getRetention')->willReturn([
			'archiefactiedatum' => '2025-01-01',
		]);
		$mockObject->expects($this->once())->method('setRetention');
		$this->objectMapper->method('findByUuid')->willReturn($mockObject);
		$this->objectMapper->method('update')->willReturn($mockObject);

		$list = [
			'status' => DestructionService::STATUS_IN_REVIEW,
			'objects' => [
				['uuid' => 'uuid-1', 'status' => 'pending'],
				['uuid' => 'uuid-2', 'status' => 'pending'],
			],
			'approvals' => [],
		];

		$this->jobList->expects($this->once())->method('add');

		$result = $this->service->approveList(
			$list,
			'approve_partial',
			['uuid-2'],
			['uuid-2' => 'Verkeerde classificatie']
		);

		$this->assertEquals(DestructionService::STATUS_APPROVED, $result['status']);
		$this->assertEquals(1, $result['objectCount']);
		$this->assertCount(1, $result['excludedObjects']);
	}

	/**
	 * Test rejection extends archiefactiedatum for all objects.
	 */
	public function testRejectList(): void {
		$this->setupMockUser('archivaris-1');

		$mockObject = $this->getMockBuilder(ObjectEntity::class)
			->disableOriginalConstructor()
			->onlyMethods(['jsonSerialize'])
			->addMethods(['getRetention', 'setRetention'])
			->getMock();
		$mockObject->method('getRetention')->willReturn(['archiefactiedatum' => '2025-01-01']);
		$mockObject->expects($this->once())->method('setRetention');
		$this->objectMapper->method('findByUuid')->willReturn($mockObject);
		$this->objectMapper->method('update')->willReturn($mockObject);

		$list = [
			'status' => DestructionService::STATUS_IN_REVIEW,
			'objects' => [['uuid' => 'uuid-1']],
			'approvals' => [],
			'rejections' => [],
		];

		$result = $this->service->rejectList($list, 'Selectielijst niet actueel');

		$this->assertEquals(DestructionService::STATUS_REJECTED, $result['status']);
		$this->assertCount(1, $result['rejections']);
		$this->assertEquals('Selectielijst niet actueel', $result['rejections'][0]['reason']);
	}

	/**
	 * Test dual-approval requires two different approvers.
	 */
	public function testDualApprovalFirstStep(): void {
		$this->setupMockUser('archivaris-1');

		$list = [
			'status' => DestructionService::STATUS_IN_REVIEW,
			'objects' => [['uuid' => 'uuid-1']],
			'approvals' => [],
		];

		$result = $this->service->approveList($list, 'approve_all', [], [], true);

		$this->assertEquals(DestructionService::STATUS_AWAITING_SECOND, $result['status']);
		$this->assertCount(1, $result['approvals']);
	}

	/**
	 * D1 (openregister#393): approval MUST queue DestructionExecutionJob with the
	 * argument key the job actually reads — `destructionListUuid`.
	 *
	 * The job does `$argument['destructionListUuid'] ?? null` and returns early when
	 * absent. approveList() previously queued `['destructionList' => <array>, ...]`,
	 * so the job bailed out on every single run: no object was ever destroyed and no
	 * verklaring van vernietiging was ever produced through the /api/archival route.
	 * Asserting on the queued payload is what makes that regression impossible to
	 * reintroduce silently.
	 */
	public function testApproveListQueuesJobWithUuidTheJobCanActuallyRead(): void {
		$this->setupMockUser('archivaris-1');

		$list = [
			'status' => DestructionService::STATUS_IN_REVIEW,
			'objects' => [['uuid' => 'uuid-1']],
			'approvals' => [],
		];

		$captured = null;
		$this->jobList->expects($this->once())
			->method('add')
			->willReturnCallback(
				function (string $job, $argument) use (&$captured): void {
					$this->assertSame(DestructionExecutionJob::class, $job);
					$captured = $argument;
				}
			);

		$this->service->approveList($list, 'approve_all', [], [], false, 'list-uuid-42');

		$this->assertIsArray($captured);
		$this->assertArrayHasKey(
			'destructionListUuid',
			$captured,
			'DestructionExecutionJob reads $argument["destructionListUuid"]; any other key makes it a no-op.'
		);
		$this->assertSame('list-uuid-42', $captured['destructionListUuid']);
	}

	/**
	 * D2 (openregister#393): the approval MUST be recorded under the canonical
	 * `userId` key.
	 *
	 * Both readers of the approvals list — RetentionService::generateDestructionCertificate()
	 * and DestructionExecutionJob — do array_column($approvals, 'userId'). approveList()
	 * previously wrote `approvedBy`, so the certificate's approver list came out EMPTY:
	 * a legally worthless "verklaring van vernietiging" that does not record who
	 * authorised the destruction.
	 */
	public function testApprovalIsRecordedUnderCanonicalUserIdKey(): void {
		$this->setupMockUser('archivaris-1');

		$list = [
			'status' => DestructionService::STATUS_IN_REVIEW,
			'objects' => [['uuid' => 'uuid-1']],
			'approvals' => [],
		];

		$result = $this->service->approveList($list, 'approve_all', [], [], false, 'list-uuid-42');

		$this->assertSame('archivaris-1', $result['approvals'][0]['userId']);

		// The exact projection the certificate generator performs must be non-empty.
		$approvers = array_column($result['approvals'], 'userId');
		$this->assertSame(
			['archivaris-1'],
			$approvers,
			'array_column($approvals, "userId") is what lands in the certificate; it must not be empty.'
		);
	}
}
