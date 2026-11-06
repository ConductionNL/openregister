<?php

declare(strict_types=1);

/**
 * ImportHandler Flow Import Tests
 *
 * Unit tests for the Flow component of the JSON config bundle: importFlow()
 * and its helpers (flowToMutate(), flowGraphUnchanged(), ensureFlowIsDraft(),
 * publishImportedFlow()).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ImportHandler's Flow import support.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class ImportHandlerFlowTest extends TestCase {

	/** @var FlowMapper&MockObject */
	private FlowMapper $flowMapper;

	/** @var FlowVersionService&MockObject */
	private FlowVersionService $flowVersionService;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private ImportHandler $handler;

	/**
	 * Set up a handler with mocked collaborators and the flow dependencies wired in.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->flowMapper = $this->createMock(FlowMapper::class);
		$this->flowVersionService = $this->createMock(FlowVersionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new ImportHandler(
			schemaMapper:        $this->createMock(SchemaMapper::class),
			registerMapper:      $this->createMock(RegisterMapper::class),
			objectEntityMapper:  $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper:       $this->createMock(MappingMapper::class),
			client:              $this->createMock(Client::class),
			appConfig:           $this->createMock(IAppConfig::class),
			logger:              $this->logger,
			appDataPath:         '/tmp',
			uploadHandler:       $this->createMock(UploadHandler::class),
			objectService:       $this->createMock(ObjectService::class)
		);

		$this->handler->setFlowMapper($this->flowMapper);
		$this->handler->setFlowVersionService($this->flowVersionService);

		$this->flowMapper->method('insert')->willReturnArgument(0);
		$this->flowMapper->method('update')->willReturnArgument(0);
	}//end setUp()

	/**
	 * Invoke a private method via reflection.
	 */
	private function invokeMethod(object $object, string $method, array $args = []): mixed {
		$ref = new ReflectionClass($object);
		$m = $ref->getMethod($method);
		$m->setAccessible(true);
		return $m->invokeArgs($object, $args);
	}//end invokeMethod()

	/**
	 * importFlow() returns null without touching the mapper when flowMapper was never wired.
	 */
	public function testImportFlowSkipsWhenNoFlowMapper(): void {
		$handler = new ImportHandler(
			schemaMapper:        $this->createMock(SchemaMapper::class),
			registerMapper:      $this->createMock(RegisterMapper::class),
			objectEntityMapper:  $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper:       $this->createMock(MappingMapper::class),
			client:              $this->createMock(Client::class),
			appConfig:           $this->createMock(IAppConfig::class),
			logger:              $this->createMock(LoggerInterface::class),
			appDataPath:         '/tmp',
			uploadHandler:       $this->createMock(UploadHandler::class),
			objectService:       $this->createMock(ObjectService::class)
		);

		$result = $this->invokeMethod($handler, 'importFlow', [['uuid' => 'abc'], null, null, false]);

		$this->assertNull($result);
	}//end testImportFlowSkipsWhenNoFlowMapper()

	/**
	 * importFlow() returns null and logs a warning when the data has no uuid.
	 */
	public function testImportFlowSkipsWhenUuidMissing(): void {
		$this->logger->expects($this->once())->method('warning');

		$result = $this->invokeMethod($this->handler, 'importFlow', [['name' => 'No uuid'], null, null, false]);

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

		$result = $this->invokeMethod($this->handler, 'importFlow', [
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

		$result = $this->invokeMethod($this->handler, 'importFlow', [
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

		$result = $this->invokeMethod($this->handler, 'importFlow', [
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

		$result = $this->invokeMethod($this->handler, 'importFlow', [
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

		$this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			null,
			null,
			false,
		]);
	}//end testImportFlowLeavesExistingDraftAlone()

	/**
	 * importFlow() falls back to a direct published patch when publish() refuses a dead end.
	 */
	public function testImportFlowFallsBackToDirectPublishOnDeadEndRefusal(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->method('publish')->willThrowException(
			new FlowLifecycleRefused(reason: FlowLifecycleRefused::REASON_DEAD_END, flowId: 'flow-uuid-1')
		);

		// Once for applyImportedFlowFields' persist, once for the fallback patch.
		$this->flowMapper->expects($this->exactly(2))->method('update')->willReturnArgument(0);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			null,
			null,
			false,
		]);

		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $result->getLifecycleStatus());
	}//end testImportFlowFallsBackToDirectPublishOnDeadEndRefusal()

	/**
	 * importFlow() logs and leaves the flow as a draft for any OTHER publish refusal — no fallback patch.
	 */
	public function testImportFlowDoesNotFallBackForNonDeadEndRefusal(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->method('publish')->willThrowException(
			new FlowLifecycleRefused(reason: FlowLifecycleRefused::REASON_NOT_A_DRAFT, flowId: 'flow-uuid-1')
		);

		// Only the main persist — no fallback patch for a non-dead-end refusal.
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			null,
			null,
			false,
		]);

		$this->assertInstanceOf(Flow::class, $result);
	}//end testImportFlowDoesNotFallBackForNonDeadEndRefusal()

	/**
	 * importFlow() leaves a new flow as a draft when no FlowVersionService was wired.
	 */
	public function testImportFlowWithoutFlowVersionServiceLeavesFlowUnpublished(): void {
		$this->handler->setFlowVersionService(null);

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));

		$result = $this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1']],
			null,
			null,
			false,
		]);

		$this->assertSame(FlowVersion::STATUS_DRAFT, $result->getLifecycleStatus());
	}//end testImportFlowWithoutFlowVersionServiceLeavesFlowUnpublished()
}//end class
