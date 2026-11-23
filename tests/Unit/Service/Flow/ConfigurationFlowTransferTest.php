<?php

declare(strict_types=1);

/**
 * ConfigurationFlowTransfer Tests
 *
 * Unit tests for the Flow component of the JSON config bundle: importFlow()
 * and its helpers (flowToMutate(), flowGraphUnchanged(), ensureFlowIsDraft(),
 * publishImportedFlow()), and exportFlows().
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\ConfigurationFlowTransfer;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the flow engine's side of configuration import and export.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
#[CoversClass(ConfigurationFlowTransfer::class)]
#[UsesClass(Flow::class)]
#[UsesClass(Configuration::class)]
#[UsesClass(FlowLifecycleRefused::class)]
class ConfigurationFlowTransferTest extends TestCase {

	/** @var FlowMapper&MockObject */
	private FlowMapper $flowMapper;

	/** @var FlowVersionService&MockObject */
	private FlowVersionService $flowVersionService;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private ConfigurationFlowTransfer $transfer;

	/**
	 * Set up the transfer with a mocked mapper, version service and logger.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->flowMapper = $this->createMock(FlowMapper::class);
		$this->flowVersionService = $this->createMock(FlowVersionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->transfer = new ConfigurationFlowTransfer(
			flowMapper: $this->flowMapper,
			logger: $this->logger,
			flowVersionService: $this->flowVersionService
		);

		$this->flowMapper->method('insert')->willReturnArgument(0);
		$this->flowMapper->method('update')->willReturnArgument(0);
	}//end setUp()

	/**
	 * Import one flow entry the way ImportHandler does: the organisation comes from the configuration.
	 *
	 * @param array              $data          The flow entry.
	 * @param Configuration|null $configuration The configuration supplying the organisation.
	 * @param string|null        $owner         The owner of a new flow.
	 * @param bool               $force         Rewrite an unchanged graph.
	 */
	private function importFlow(array $data, ?Configuration $configuration, ?string $owner, bool $force): ?Flow {
		return $this->transfer->importFlow(
			data: $data,
			owner: $owner,
			organisation: $configuration?->getOrganisation(),
			force: $force
		);
	}//end importFlow()

	/**
	 * importFlow() returns null and logs a warning when the data has no uuid.
	 */
	public function testImportFlowSkipsWhenUuidMissing(): void {
		$this->logger->expects($this->once())->method('warning');

		$result = $this->importFlow(['name' => 'No uuid'], null, null, false);

		$this->assertNull($result);
	}//end testImportFlowSkipsWhenUuidMissing()

	/**
	 * importFlow() creates and publishes a brand new flow, stamping owner and organisation.
	 */
	public function testImportFlowCreatesNewFlowAndPublishes(): void {
		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));

		$this->flowMapper->expects($this->once())->method('insert')
			->with($this->callback(function (Flow $flow): bool {
				return $flow->getUuid() === 'flow-uuid-1'
					&& $flow->getOwner() === 'alice'
					&& $flow->getOrganisation() === 'org-1'
					&& $flow->getName() === 'My Flow'
					&& $flow->getNodes() === ['n1'];
			}));

		$this->flowVersionService->expects($this->once())->method('publish')
			->with($this->callback(static fn (Flow $flow): bool => $flow->getUuid() === 'flow-uuid-1'));

		$configuration = new Configuration();
		$configuration->setOrganisation('org-1');

		$result = $this->importFlow(...[
			['uuid' => 'flow-uuid-1', 'name' => 'My Flow', 'nodes' => ['n1']],
			$configuration,
			'alice',
			false,
		]);

		$this->assertInstanceOf(Flow::class, $result);
		$this->assertSame('flow-uuid-1', $result->getUuid());
	}//end testImportFlowCreatesNewFlowAndPublishes()

	/**
	 * importFlow() leaves an existing flow alone when its graph already matches the import.
	 */
	public function testImportFlowSkipsUpdateWhenGraphUnchanged(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setNodes(['n1']);
		$existing->setEdges([]);
		$existing->setLimits([]);
		$existing->setExecutionMode(Flow::MODE_ASYNC);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowMapper->expects($this->never())->method('update');
		$this->flowVersionService->expects($this->never())->method('createDraft');
		$this->flowVersionService->expects($this->never())->method('publish');

		$result = $this->importFlow(...[
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1'], 'edges' => [], 'limits' => [], 'executionMode' => Flow::MODE_ASYNC],
			null,
			null,
			false,
		]);

		$this->assertSame($existing, $result);
	}//end testImportFlowSkipsUpdateWhenGraphUnchanged()

	/**
	 * importFlow() re-publishes an unchanged graph when $force is true.
	 */
	public function testImportFlowForcesUpdateEvenWhenGraphUnchanged(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setNodes(['n1']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->flowVersionService->expects($this->once())->method('publish');

		$result = $this->importFlow(...[
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1']],
			null,
			null,
			true,
		]);

		$this->assertInstanceOf(Flow::class, $result);
	}//end testImportFlowForcesUpdateEvenWhenGraphUnchanged()

	/**
	 * importFlow() opens a draft before editing a published flow whose graph changed.
	 */
	public function testImportFlowOpensDraftWhenExistingIsPublished(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->expects($this->once())->method('createDraft')->with($existing);
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->flowVersionService->expects($this->once())->method('publish');

		$result = $this->importFlow(...[
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			null,
			null,
			false,
		]);

		$this->assertSame(['new'], $result->getNodes());
	}//end testImportFlowOpensDraftWhenExistingIsPublished()

	/**
	 * importFlow() does not open a second draft layer when the head is already a draft.
	 */
	public function testImportFlowLeavesExistingDraftAlone(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->expects($this->never())->method('createDraft');
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->importFlow(...[
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			null,
			null,
			false,
		]);
	}//end testImportFlowLeavesExistingDraftAlone()

	/**
	 * A flow the publish guard refuses for a dead-end node is imported as a draft, never marked published.
	 */
	public function testImportFlowWithADeadEndStaysADraft(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->expects($this->once())->method('publish')->willThrowException(
			new FlowLifecycleRefused(reason: FlowLifecycleRefused::REASON_DEAD_END, flowId: 'flow-uuid-1')
		);

		// Only the persist of the imported fields; no write that sets the status.
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->importFlow(...[
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			null,
			null,
			false,
		]);

		$this->assertSame(FlowVersion::STATUS_DRAFT, $result->getLifecycleStatus());
	}//end testImportFlowWithADeadEndStaysADraft()

	/**
	 * A new flow with a dead-end node is inserted as a draft and never updated to published.
	 */
	public function testANewFlowWithADeadEndIsImportedAsADraft(): void {
		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));
		$this->flowMapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$this->flowMapper->expects($this->never())->method('update');
		$this->flowVersionService->method('publish')->willThrowException(
			new FlowLifecycleRefused(reason: FlowLifecycleRefused::REASON_DEAD_END, flowId: 'flow-uuid-1')
		);

		$result = $this->importFlow(...[
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1']],
			null,
			null,
			false,
		]);

		$this->assertNotSame(FlowVersion::STATUS_PUBLISHED, $result->getLifecycleStatus());
	}//end testANewFlowWithADeadEndIsImportedAsADraft()

	/**
	 * importFlow() logs and leaves the flow as a draft for any other publish refusal.
	 */
	public function testImportFlowStaysADraftForAnotherRefusal(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->method('publish')->willThrowException(
			new FlowLifecycleRefused(reason: FlowLifecycleRefused::REASON_NOT_A_DRAFT, flowId: 'flow-uuid-1')
		);

		// Only the main persist.
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->importFlow(...[
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			null,
			null,
			false,
		]);

		$this->assertInstanceOf(Flow::class, $result);
	}//end testImportFlowStaysADraftForAnotherRefusal()

	/**
	 * importFlow() leaves a new flow as a draft when no FlowVersionService was wired.
	 */
	public function testImportFlowWithoutFlowVersionServiceLeavesFlowUnpublished(): void {
		$this->transfer = new ConfigurationFlowTransfer(flowMapper: $this->flowMapper, logger: $this->logger);

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->importFlow(...[
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1']],
			null,
			null,
			false,
		]);

		$this->assertSame(FlowVersion::STATUS_DRAFT, $result->getLifecycleStatus());
	}//end testImportFlowWithoutFlowVersionServiceLeavesFlowUnpublished()

	/**
	 * exportFlows() keys flows by uuid, strips instance-specific fields and leaves out a flow that cannot be loaded.
	 */
	public function testExportFlowsStripsInstanceFieldsAndSkipsMissing(): void {
		$flow = new Flow();
		$flow->setUuid('flow-uuid-1');
		$flow->setName('My Flow');
		$flow->setOwner('alice');
		$flow->setOrganisation('org-1');

		$this->flowMapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($flow): Flow {
				if ($uuid === 'flow-uuid-1') {
					return $flow;
				}

				throw new DoesNotExistException('not found');
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[ExportHandler] Failed to export flow', $this->callback(static fn (array $c): bool => $c['flowUuid'] === 'missing'));

		$result = $this->transfer->exportFlows(flowUuids: ['flow-uuid-1', 'missing']);

		$this->assertSame(['flow-uuid-1'], array_keys($result));
		$this->assertSame('My Flow', $result['flow-uuid-1']['name']);
		$this->assertArrayNotHasKey('id', $result['flow-uuid-1']);
		$this->assertArrayNotHasKey('owner', $result['flow-uuid-1']);
		$this->assertArrayNotHasKey('organisation', $result['flow-uuid-1']);
	}//end testExportFlowsStripsInstanceFieldsAndSkipsMissing()
}//end class
