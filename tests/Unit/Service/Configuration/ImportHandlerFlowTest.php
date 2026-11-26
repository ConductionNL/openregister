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
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidator;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowTriggerNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use UnexpectedValueException;

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

	/** @var ConfigurationMapper&MockObject */
	private ConfigurationMapper $configurationMapper;

	private ImportHandler $handler;

	/**
	 * Set up a handler with mocked collaborators and the flow dependencies wired in.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->flowMapper = $this->createMock(FlowMapper::class);
		$this->flowVersionService = $this->createMock(FlowVersionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->configurationMapper = $this->createMock(ConfigurationMapper::class);

		$this->handler = new ImportHandler(
			schemaMapper:        $this->createMock(SchemaMapper::class),
			registerMapper:      $this->createMock(RegisterMapper::class),
			objectEntityMapper:  $this->createMock(MagicMapper::class),
			configurationMapper: $this->configurationMapper,
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
		$this->handler->setFlowTriggerValidator($this->triggerValidator());

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
	 * A real FlowTriggerValidator over a fake node registry, session and delegation store.
	 *
	 * @param string|null            $savedBy      The uid the session reports, or null for none.
	 * @param array<int, string>     $triggerTypes Node types the registry resolves to an accepting trigger node.
	 * @param DelegationVerdict|null $verdict      What the delegation store answers; refused by default.
	 */
	private function triggerValidator(
		?string $savedBy = null,
		array $triggerTypes = [],
		?DelegationVerdict $verdict = null,
	): FlowTriggerValidator {
		$trigger = new class implements IFlowNode, IFlowTriggerNode {
			public function getId(): string {
				return 'trigger.double';
			}

			public function getDisplayName(): string {
				return 'Trigger double';
			}

			public function getDescription(): string {
				return 'Accepts any config.';
			}

			public function getIcon(): string {
				return '/icon.svg';
			}

			public function isAvailableForScope(int $scope): bool {
				return true;
			}

			public function validateConfig(array $config): void {
			}

			public function execute(array $items, array $config, array $context): array {
				return $items;
			}
		};

		$registry = new class(array_fill_keys($triggerTypes, $trigger)) {
			public function __construct(private readonly array $nodes) {
			}

			public function get(string $type): object {
				if (isset($this->nodes[$type]) === false) {
					throw new UnexpectedValueException('Unknown node type: ' . $type);
				}

				return $this->nodes[$type];
			}
		};

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($savedBy !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($savedBy);
		}

		$session->method('getUser')->willReturn($user);

		$delegation = $this->createMock(DelegationService::class);
		$delegation->method('verdictFor')->willReturn(
			($verdict ?? DelegationVerdict::refused(DelegationVerdict::REASON_NONE, 'no grant'))
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registry, $session, $delegation): object {
				return match ($id) {
					IUserSession::class => $session,
					DelegationService::class => $delegation,
					default => $registry,
				};
			}
		);

		return new FlowTriggerValidator($container, $this->createMock(LoggerInterface::class));
	}//end triggerValidator()

	/**
	 * A configuration in the given organisation, the scope an import writes flows into.
	 */
	private function configuration(?string $organisation = 'org-1'): Configuration {
		$configuration = new Configuration();
		$configuration->setOrganisation($organisation);
		return $configuration;
	}//end configuration()

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
		$existing->setOrganisation('org-1');
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
			$this->configuration(),
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
		$existing->setOrganisation('org-1');
		$existing->setNodes(['n1']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->flowVersionService->expects($this->once())->method('publish');

		$result = $this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1']],
			$this->configuration(),
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
		$existing->setOrganisation('org-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->expects($this->once())->method('createDraft')->with($existing);
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->flowVersionService->expects($this->once())->method('publish');

		$result = $this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			$this->configuration(),
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
		$existing->setOrganisation('org-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->expects($this->never())->method('createDraft');
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			$this->configuration(),
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
		$existing->setOrganisation('org-1');
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
			$this->configuration(),
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
		$existing->setOrganisation('org-1');
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
			$this->configuration(),
			null,
			false,
		]);

		$this->assertInstanceOf(Flow::class, $result);
		$this->assertSame(FlowVersion::STATUS_DRAFT, $result->getLifecycleStatus());
	}//end testImportFlowDoesNotFallBackForNonDeadEndRefusal()

	/**
	 * importFlow() logs a generic publish() failure and returns the flow without the dead-end fallback patch.
	 */
	public function testImportFlowLogsGenericPublishFailureWithoutFallback(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setOrganisation('org-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->method('publish')->willThrowException(new \RuntimeException('deadlock'));

		// Only the main persist; the dead-end fallback must not run for this failure.
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('deadlock'));

		$result = $this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			$this->configuration(),
			null,
			false,
		]);

		$this->assertSame($existing, $result);
		$this->assertSame(FlowVersion::STATUS_DRAFT, $result->getLifecycleStatus());
	}//end testImportFlowLogsGenericPublishFailureWithoutFallback()

	/**
	 * importFlow() leaves a new flow as a draft when no FlowVersionService was wired.
	 */
	public function testImportFlowWithoutFlowVersionServiceLeavesFlowUnpublished(): void {
		$this->handler->setFlowVersionService(null);

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));

		// The degrade path is a deliberate early return, not a swallowed
		// Error from calling publish() on null: that would log a warning.
		$this->logger->expects($this->never())->method('warning');

		$result = $this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1']],
			$this->configuration(),
			'alice',
			false,
		]);

		$this->assertSame(FlowVersion::STATUS_DRAFT, $result->getLifecycleStatus());
	}//end testImportFlowWithoutFlowVersionServiceLeavesFlowUnpublished()

	/**
	 * importFlow() without a FlowVersionService demotes a changed published flow to a draft and does not re-publish it.
	 */
	public function testImportFlowWithoutFlowVersionServiceDemotesPublishedFlowToDraft(): void {
		$this->handler->setFlowVersionService(null);

		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setOrganisation('org-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowMapper->expects($this->once())->method('update')
			->with($this->callback(static fn (Flow $flow): bool => $flow->getLifecycleStatus() === FlowVersion::STATUS_DRAFT))
			->willReturnArgument(0);
		$this->logger->expects($this->never())->method('warning');

		$result = $this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			$this->configuration(),
			null,
			false,
		]);

		$this->assertSame(['new'], $result->getNodes());
		$this->assertSame(FlowVersion::STATUS_DRAFT, $result->getLifecycleStatus());
	}//end testImportFlowWithoutFlowVersionServiceDemotesPublishedFlowToDraft()

	/**
	 * importFlow() refuses a uuid that already belongs to another organisation instead of overwriting it.
	 */
	public function testImportFlowRefusesExistingFlowOfAnotherOrganisation(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setOrganisation('org-A');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowMapper->expects($this->never())->method('update');
		$this->flowMapper->expects($this->never())->method('insert');
		$this->flowVersionService->expects($this->never())->method('createDraft');
		$this->flowVersionService->expects($this->never())->method('publish');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('another organisation');

		$this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['new']],
			$this->configuration('org-B'),
			null,
			true,
		]);
	}//end testImportFlowRefusesExistingFlowOfAnotherOrganisation()

	/**
	 * importFromJson() skips (and counts) a new flow when the configuration has no organisation.
	 */
	public function testImportFromJsonSkipsNewFlowWithoutOrganisation(): void {
		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));
		$this->flowMapper->expects($this->never())->method('insert');
		$this->flowVersionService->expects($this->never())->method('publish');

		$result = $this->handler->importFromJson(
			data: ['components' => ['flows' => ['flow-uuid-1' => ['name' => 'Orphan', 'nodes' => ['n1']]]]],
			configuration: $this->configuration(null),
			owner: 'alice'
		);

		$this->assertSame(1, $result['skipped']['flows']);
		$this->assertSame([], $result['flows']);
	}//end testImportFromJsonSkipsNewFlowWithoutOrganisation()

	/**
	 * importFlow() stamps the signed-in user as owner when the caller passed none.
	 */
	public function testImportFlowFallsBackToSessionUserAsOwner(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->handler->setUserSession($session);

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));
		$this->flowMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (Flow $flow): bool => $flow->getOwner() === 'admin'));

		$this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1']],
			$this->configuration(),
			null,
			false,
		]);
	}//end testImportFlowFallsBackToSessionUserAsOwner()

	/**
	 * importFlow() refuses a new flow when there is neither an owner argument nor a session user.
	 */
	public function testImportFlowRefusesNewFlowWithoutAnyOwner(): void {
		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));
		$this->flowMapper->expects($this->never())->method('insert');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('belongs to nobody');

		$this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1']],
			$this->configuration(),
			null,
			false,
		]);
	}//end testImportFlowRefusesNewFlowWithoutAnyOwner()

	/**
	 * importFromJson() skips a published flow whose draft cannot be opened, instead of editing the published head.
	 */
	public function testImportFromJsonSkipsFlowWhenCreateDraftFails(): void {
		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setOrganisation('org-1');
		$existing->setNodes(['old']);
		$existing->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);

		$this->flowMapper->method('findByUuid')->willReturn($existing);
		$this->flowVersionService->method('createDraft')->willThrowException(new \RuntimeException('db down'));
		$this->flowMapper->expects($this->never())->method('update');
		$this->flowVersionService->expects($this->never())->method('publish');

		$result = $this->handler->importFromJson(
			data: ['components' => ['flows' => ['flow-uuid-1' => ['nodes' => ['new']]]]],
			configuration: $this->configuration(),
			owner: 'alice'
		);

		$this->assertSame(1, $result['skipped']['flows']);
		$this->assertSame([], $result['flows']);
		$this->assertSame(['old'], $existing->getNodes());
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $existing->getLifecycleStatus());
	}//end testImportFromJsonSkipsFlowWhenCreateDraftFails()

	/**
	 * importFromJson() refuses a schedule trigger naming a user the importing admin holds no grant for.
	 */
	public function testImportFromJsonRefusesRunAsWithoutDelegationGrant(): void {
		$this->handler->setFlowTriggerValidator(
			$this->triggerValidator(savedBy: 'admin', triggerTypes: ['trigger.schedule'])
		);
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($admin);
		$this->handler->setUserSession($session);

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));
		$this->flowMapper->expects($this->never())->method('insert');
		$this->flowVersionService->expects($this->never())->method('publish');

		$nodes = [[
			'id' => 'n1',
			'type' => 'trigger.schedule',
			'config' => ['cron' => '*/5 * * * *', 'runAs' => 'someone-else'],
		]];

		$result = $this->handler->importFromJson(
			data: ['components' => ['flows' => ['flow-uuid-1' => ['nodes' => $nodes]]]],
			configuration: $this->configuration(),
			owner: 'admin'
		);

		$this->assertSame(1, $result['skipped']['flows']);
		$this->assertSame([], $result['flows']);
	}//end testImportFromJsonRefusesRunAsWithoutDelegationGrant()

	/**
	 * A configuration synced from a remote source, as SyncConfigurationsJob and ConfigurationCheckJob import it.
	 */
	private function remoteConfiguration(): Configuration {
		$configuration = $this->configuration();
		$configuration->setSourceType('github');
		$configuration->setIsLocal(false);
		return $configuration;
	}//end remoteConfiguration()

	/**
	 * A schedule trigger node naming the given user.
	 */
	private function scheduleNode(string $runAs, ?string $declaredBy = null): array {
		$config = ['cron' => '*/5 * * * *', 'runAs' => $runAs];
		if ($declaredBy !== null) {
			$config[FlowTriggerValidator::CONFIG_DECLARED_BY] = $declaredBy;
		}

		return ['id' => 'n1', 'type' => 'trigger.schedule', 'config' => $config];
	}//end scheduleNode()

	/**
	 * A sessionless import from a remote source refuses a new flow that schedules runs as someone.
	 */
	public function testSessionlessRemoteImportRefusesNewRunAsFlow(): void {
		$this->handler->setFlowTriggerValidator($this->triggerValidator(triggerTypes: ['trigger.schedule']));

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));
		$this->flowMapper->expects($this->never())->method('insert');

		$result = $this->handler->importFromJson(
			data: ['components' => ['flows' => ['flow-uuid-1' => ['nodes' => [$this->scheduleNode('someone-else')]]]]],
			configuration: $this->remoteConfiguration(),
			owner: 'admin'
		);

		$this->assertSame(1, $result['skipped']['flows']);
		$this->assertSame([], $result['flows']);
	}//end testSessionlessRemoteImportRefusesNewRunAsFlow()

	/**
	 * A sessionless forced re-import keeps the stamp a user's save wrote on an unchanged runAs.
	 */
	public function testSessionlessForcedImportPreservesStoredDelegationStamp(): void {
		$this->handler->setFlowTriggerValidator($this->triggerValidator(triggerTypes: ['trigger.schedule']));

		$existing = new Flow();
		$existing->setUuid('flow-uuid-1');
		$existing->setOrganisation('org-1');
		$existing->setLifecycleStatus(FlowVersion::STATUS_DRAFT);
		$existing->setNodes([$this->scheduleNode('bob', 'alice')]);

		$this->flowMapper->method('findByUuid')->willReturn($existing);

		$stored = null;
		$this->flowMapper->expects($this->once())->method('update')
			->with($this->callback(static function (Flow $flow) use (&$stored): bool {
				$stored = $flow->getNodes();
				return true;
			}))
			->willReturnArgument(0);

		// The bundle carries no stamp (or a foreign one): it is never trusted.
		$this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => [$this->scheduleNode('bob', 'mallory')]],
			$this->remoteConfiguration(),
			null,
			true,
		]);

		$this->assertSame('alice', $stored[0]['config'][FlowTriggerValidator::CONFIG_DECLARED_BY]);
	}//end testSessionlessForcedImportPreservesStoredDelegationStamp()

	/**
	 * A sessionless import of a trusted local app bundle keeps the code-initiated behaviour: accepted, no stamp.
	 */
	public function testSessionlessLocalAppImportAcceptsRunAsFlow(): void {
		$this->handler->setFlowTriggerValidator($this->triggerValidator(triggerTypes: ['trigger.schedule']));

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));

		$stored = null;
		$this->flowMapper->expects($this->once())->method('insert')
			->with($this->callback(static function (Flow $flow) use (&$stored): bool {
				$stored = $flow->getNodes();
				return true;
			}));

		$configuration = $this->configuration();
		$configuration->setSourceType('local');
		$configuration->setIsLocal(true);

		$result = $this->handler->importFromJson(
			data: ['components' => ['flows' => ['flow-uuid-1' => ['nodes' => [$this->scheduleNode('someone-else', 'forged')]]]]],
			configuration: $configuration,
			owner: 'myapp'
		);

		$this->assertSame(0, $result['skipped']['flows']);
		$this->assertSame(['cron' => '*/5 * * * *', 'runAs' => 'someone-else'], $stored[0]['config']);
	}//end testSessionlessLocalAppImportAcceptsRunAsFlow()

	/**
	 * The cron sync of a remote source refuses a new runAs flow even when the bundle omits its sourceType.
	 *
	 * importFromApp() is called the way SyncConfigurationsJob calls it (force,
	 * no session) with the configuration being synced. Without that
	 * configuration the bundle resolves its own: here the app's trusted local
	 * configuration, which would accept the flow without a stamp.
	 */
	public function testCronSyncOfRemoteBundleWithoutSourceTypeRefusesNewRunAsFlow(): void {
		$this->handler->setFlowTriggerValidator($this->triggerValidator(triggerTypes: ['trigger.schedule']));

		$trustedLocal = $this->configuration();
		$trustedLocal->setApp('myapp');
		$trustedLocal->setIsLocal(true);
		$this->configurationMapper->method('findBySourceUrl')->willReturn($trustedLocal);
		$this->configurationMapper->method('findByApp')->willReturn([$trustedLocal]);
		$this->configurationMapper->method('insert')->willReturnArgument(0);

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));
		$this->flowMapper->expects($this->never())->method('insert');

		$bundle = [
			'x-openregister' => ['app' => 'myapp', 'sourceUrl' => 'https://example.org/config.json'],
			'info' => ['version' => '1.0.0'],
			'components' => ['flows' => ['flow-uuid-1' => ['nodes' => [$this->scheduleNode('someone-else')]]]],
		];

		$result = $this->handler->importFromApp(
			appId: 'myapp',
			data: $bundle,
			version: '1.0.0',
			force: true,
			configuration: $this->remoteConfiguration()
		);

		$this->assertSame(1, $result['skipped']['flows']);
		$this->assertSame([], $result['flows']);
	}//end testCronSyncOfRemoteBundleWithoutSourceTypeRefusesNewRunAsFlow()

	/**
	 * importFlow() drops a delegation stamp the bundle carried, even on a node this instance cannot resolve.
	 */
	public function testImportFlowStripsAnIncomingDelegationStamp(): void {
		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));

		$stored = null;
		$this->flowMapper->expects($this->once())->method('insert')
			->with($this->callback(static function (Flow $flow) use (&$stored): bool {
				$stored = $flow->getNodes();
				return true;
			}));

		$this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => [[
				'id' => 'n1',
				'type' => 'leafapp.trigger',
				'config' => ['runAs' => 'bob', FlowTriggerValidator::CONFIG_DECLARED_BY => 'bob'],
			]]],
			$this->configuration(),
			'admin',
			false,
		]);

		$this->assertSame(['runAs' => 'bob'], $stored[0]['config']);
	}//end testImportFlowStripsAnIncomingDelegationStamp()

	/**
	 * importFlow() refuses to write a flow when no trigger validator was wired.
	 */
	public function testImportFlowRefusedWithoutTriggerValidator(): void {
		$this->handler->setFlowTriggerValidator(null);

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));
		$this->flowMapper->expects($this->never())->method('insert');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('no trigger validator');

		$this->invokeMethod($this->handler, 'importFlow', [
			['uuid' => 'flow-uuid-1', 'nodes' => ['n1']],
			$this->configuration(),
			'admin',
			false,
		]);
	}//end testImportFlowRefusedWithoutTriggerValidator()

	/**
	 * importFromJson() records the imported flow's uuid on the configuration, next to the ones it already tracked.
	 */
	public function testImportFromJsonRecordsImportedFlowsOnTheConfiguration(): void {
		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));

		$configuration = $this->configuration();
		$configuration->setId(7);
		$configuration->setFlows(['earlier-flow']);

		$this->configurationMapper->expects($this->once())->method('update')
			->with($this->identicalTo($configuration))
			->willReturnArgument(0);

		$this->handler->importFromJson(
			data: ['components' => ['flows' => ['flow-uuid-1' => ['name' => 'My Flow', 'nodes' => ['n1']]]]],
			configuration: $configuration,
			owner: 'alice'
		);

		$this->assertSame(['earlier-flow', 'flow-uuid-1'], $configuration->getFlows());
	}//end testImportFromJsonRecordsImportedFlowsOnTheConfiguration()

	/**
	 * importFromJson() takes a flow's uuid from its map key when the entry carries none, and reports it in result['flows'].
	 */
	public function testImportFromJsonImportsFlowUnderItsMapKey(): void {
		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));
		$this->flowMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (Flow $flow): bool => $flow->getUuid() === 'keyed-uuid'));

		$result = $this->handler->importFromJson(
			data: ['components' => ['flows' => ['keyed-uuid' => ['name' => 'Keyed', 'nodes' => ['n1']]]]],
			configuration: $this->configuration(),
			owner: 'alice'
		);

		$this->assertCount(1, $result['flows']);
		$this->assertSame('keyed-uuid', $result['flows'][0]->getUuid());
		$this->assertSame(0, $result['skipped']['flows']);
	}//end testImportFromJsonImportsFlowUnderItsMapKey()

	/**
	 * importFromJson() counts a flow that fails in skipped['flows'] and still imports the next one.
	 */
	public function testImportFromJsonCountsAFailedFlowAndCarriesOn(): void {
		// A mapper of its own: setUp()'s insert stub returns its argument for every flow.
		$insert = $this->createMock(FlowMapper::class);
		$insert->method('findByUuid')->willThrowException(new DoesNotExistException('not found'));
		$insert->method('insert')->willReturnCallback(static function (Flow $flow): Flow {
			if ($flow->getUuid() === 'broken-uuid') {
				throw new \RuntimeException('insert failed');
			}

			return $flow;
		});
		$this->handler->setFlowMapper($insert);

		$result = $this->handler->importFromJson(
			data: ['components' => ['flows' => [
				'broken-uuid' => ['nodes' => ['n1']],
				'good-uuid' => ['nodes' => ['n1']],
			]]],
			configuration: $this->configuration(),
			owner: 'alice'
		);

		$this->assertSame(1, $result['skipped']['flows']);
		$this->assertCount(1, $result['flows']);
		$this->assertSame('good-uuid', $result['flows'][0]->getUuid());
	}//end testImportFromJsonCountsAFailedFlowAndCarriesOn()

	/**
	 * importFromJson() leaves components.flows alone when no FlowMapper was wired.
	 */
	public function testImportFromJsonIgnoresFlowsWithoutFlowMapper(): void {
		$this->handler->setFlowMapper(null);
		$this->flowMapper->expects($this->never())->method('insert');

		$result = $this->handler->importFromJson(
			data: ['components' => ['flows' => ['flow-uuid-1' => ['nodes' => ['n1']]]]],
			configuration: $this->configuration(),
			owner: 'alice'
		);

		$this->assertSame([], $result['flows']);
		$this->assertSame(0, $result['skipped']['flows']);
	}//end testImportFromJsonIgnoresFlowsWithoutFlowMapper()

	/**
	 * importFlow() takes only the allowlisted fields from an exported-shaped payload onto a new flow.
	 *
	 * The payload is what Flow::jsonSerialize() emits for a published, previously
	 * run flow on the source instance, plus owner and organisation as a
	 * hand-built bundle could carry them.
	 */
	public function testImportFlowTakesOnlyAllowlistedFieldsFromAnExportedPayload(): void {
		$source = new Flow();
		$source->setUuid('flow-uuid-1');
		$source->setName('Exported');
		$source->setDescription('From the source instance');
		$source->setTrigger('object.created');
		$source->setCron('0 3 * * *');
		$source->setNodes([['id' => 'n1', 'type' => 'step']]);
		$source->setVersion(7);
		$source->setSemver('1.2.0');
		$source->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
		$source->setStatus('error');
		$source->setStatusMessage('boom on the source');
		$source->setLastRunStatus('failed');

		$payload = $source->jsonSerialize();
		$payload['owner'] = 'mallory';
		$payload['organisation'] = 'org-evil';
		$payload['retentionDays'] = 0;
		$payload['auditEnabled'] = false;

		$this->flowMapper->method('findByUuid')
			->willThrowException(new DoesNotExistException('not found'));

		$stored = null;
		$this->flowMapper->expects($this->once())->method('insert')
			->with($this->callback(static function (Flow $flow) use (&$stored): bool {
				$stored = clone $flow;
				return true;
			}))
			->willReturnArgument(0);

		$this->invokeMethod($this->handler, 'importFlow', [$payload, $this->configuration(), 'alice', false]);

		// Carried: the definition and its settings.
		$this->assertSame('Exported', $stored->getName());
		$this->assertSame('From the source instance', $stored->getDescription());
		$this->assertSame('object.created', $stored->getTrigger());
		$this->assertSame('0 3 * * *', $stored->getCron());
		$this->assertSame([['id' => 'n1', 'type' => 'step']], $stored->getNodes());
		$this->assertSame(1, $stored->getRetentionDays());
		$this->assertFalse($stored->getAuditEnabled());

		// Not carried: the source's version state, runtime state and identity.
		// The target derives its own ordinal; a new flow keeps the entity default, never the source's 7.
		$this->assertSame((new Flow())->getVersion(), $stored->getVersion());
		$this->assertNotSame(7, $stored->getVersion());
		$this->assertNull($stored->getSemver());
		$this->assertSame(FlowVersion::STATUS_DRAFT, $stored->getLifecycleStatus());
		$this->assertNull($stored->getStatus());
		$this->assertNull($stored->getStatusMessage());
		$this->assertNull($stored->getLastRunStatus());
		$this->assertSame('alice', $stored->getOwner());
		$this->assertSame('org-1', $stored->getOrganisation());
	}//end testImportFlowTakesOnlyAllowlistedFieldsFromAnExportedPayload()

	/**
	 * applyImportedFlowFields() carries every inheritable setting FlowService does, oversightEnabled included.
	 */
	public function testApplyImportedFlowFieldsCarriesOversightEnabled(): void {
		$flow = new Flow();

		$this->invokeMethod($this->handler, 'applyImportedFlowFields', [$flow, ['oversightEnabled' => true]]);
		$this->assertTrue($flow->getOversightEnabled());

		// An explicit opt-out is a choice too, not "inherit the default".
		$this->invokeMethod($this->handler, 'applyImportedFlowFields', [$flow, ['oversightEnabled' => false]]);
		$this->assertFalse($flow->getOversightEnabled());

		$this->invokeMethod($this->handler, 'applyImportedFlowFields', [$flow, ['oversightEnabled' => null]]);
		$this->assertNull($flow->getOversightEnabled());
	}//end testApplyImportedFlowFieldsCarriesOversightEnabled()
}//end class
