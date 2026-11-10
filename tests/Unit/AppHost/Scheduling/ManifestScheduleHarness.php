<?php

/**
 * AppHost scheduling: the real publish-to-queue path over an in-memory flow table.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\FlowPublishOptions;
use OCA\OpenRegister\AppHost\Listener\ApplicationPublishListener;
use OCA\OpenRegister\AppHost\Scheduling\ApplicationRegister;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleAuditTrail;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowStore;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleFlowSync;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleRefusals;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleReleaseReader;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowLocatorStores;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunStores;
use OCA\OpenRegister\Service\Flow\FlowRunWalkServices;
use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTenantMembership;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidator;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Publishes applications and ticks the scheduler, with only the database faked.
 */
abstract class ManifestScheduleHarness extends TestCase {
	protected const ORGANISATION = 'org-a';

	protected const VERSION = 'version-1';

	/**
	 * Flows by uuid.
	 *
	 * @var array<string, Flow>
	 */
	protected array $flows = [];

	/**
	 * Writes to the flow table.
	 */
	protected int $flowWrites = 0;

	/**
	 * Publishes of a flow version, as [flow uuid, publishedBy].
	 *
	 * @var array<int, array{0: string, 1: string|null}>
	 */
	protected array $published = [];

	/**
	 * Runs queued.
	 *
	 * @var array<int, FlowRun>
	 */
	protected array $runs = [];

	/**
	 * Audit entries.
	 *
	 * @var array<int, AuditTrail>
	 */
	protected array $audit = [];

	/**
	 * Organisation members.
	 *
	 * @var array<int, string>
	 */
	protected array $members = ['pieter', 'quinten'];

	/**
	 * The signed-in user.
	 */
	protected ?string $session = 'pieter';

	/**
	 * The slug the instance carries for the applications register.
	 */
	protected string $slugsAnswer = 'buildiq';

	/**
	 * Whether the audit table refuses a write.
	 */
	protected bool $auditFails = false;

	/**
	 * The version object the application's production version points at.
	 */
	protected ?ObjectEntity $version;

	protected LoggerInterface&MockObject $logger;

	/**
	 * Warnings logged.
	 *
	 * @var array<int, string>
	 */
	protected array $warnings = [];

	protected ApplicationPublishListener $listener;

	protected ScheduleRefusals $refusals;

	protected FlowLocator $locator;

	protected FlowScheduleService $scheduler;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$this->version = $this->object(['manifest' => []], self::VERSION);

		$flowMapper = $this->flowMapper();
		$versions = $this->flowVersionService();
		$flowService = $this->createMock(FlowService::class);
		$flowService->method('deleteStored')->willReturnCallback(
			function (Flow $flow): void {
				unset($this->flows[$flow->getUuid()]);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->user());

		$this->refusals = new ScheduleRefusals($this->logger);
		$membership = new FlowTenantMembership($this->organisations());
		$container = $this->container(flowMapper: $flowMapper, membership: $membership);
		$sync = new ScheduleFlowSync(
			new ScheduleFlowStore($flowMapper, $versions, $flowService, new FlowTriggerValidator($container, $this->logger)),
			$membership,
			new ScheduleAuditTrail($this->auditMapper()),
			$this->refusals
		);
		$listenerContainer = $this->createConfiguredMock(ContainerInterface::class, ['get' => $sync]);
		$this->listener = new ApplicationPublishListener($this->reader($session), $listenerContainer, $this->logger);

		$this->locator = $this->flowLocator(flowMapper: $flowMapper);
		$this->scheduler = new FlowScheduleService(
			$this->locator,
			$this->flowRunService(container: $container),
			$this->appConfig(),
			$this->logger
		);
	}

	/**
	 * Publish a manifest: the application's production version moves to it.
	 *
	 * @param array<int, array<string, mixed>> $schedules The manifest's schedules.
	 * @param string|null $organisation The application's organisation.
	 *
	 * @return void
	 */
	protected function publish(array $schedules, ?string $organisation = self::ORGANISATION): void {
		$this->version = $this->object(['application' => 'app-1', 'manifest' => ['schedules' => $schedules]], self::VERSION);

		$before = $this->object(['slug' => 'crm'], 'app-1');
		$after = $this->object(['slug' => 'crm', 'productionVersion' => self::VERSION], 'app-1');
		$after->setOrganisation($organisation);
		$this->listener->handle(new ObjectUpdatedEvent($after, $before));
	}

	/**
	 * A cron schedule running a synchronization.
	 *
	 * @param array<string, mixed> $overrides Keys to change.
	 *
	 * @return array<string, mixed> The manifest entry.
	 */
	protected function schedule(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'nightly',
				'cron' => '0 3 * * *',
				'action' => 'openconnector:synchronization',
				'arguments' => ['synchronizationId' => 'sync-1'],
			],
			$overrides
		);
	}

	/**
	 * Tick the scheduler a day after everything was saved.
	 *
	 * @return array<int, string> The flows fired.
	 */
	protected function tick(): array {
		return $this->scheduler->fireDueFlows(new DateTimeImmutable('+1 day'));
	}

	/**
	 * The only compiled flow.
	 *
	 * @return Flow The flow.
	 */
	protected function flow(): Flow {
		$this->assertCount(1, $this->flows);

		return array_values($this->flows)[0];
	}

	/**
	 * An object entity in the applications register.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	protected function object(array $data, string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema(isset($data['manifest']) === true ? '9' : '8');
		$object->setObject($data);

		return $object;
	}

	/**
	 * The signed-in user.
	 *
	 * @return IUser|null The user.
	 */
	private function user(): ?IUser {
		if ($this->session === null) {
			return null;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($this->session);

		return $user;
	}

	/**
	 * The in-memory flow table.
	 *
	 * @return FlowMapper&MockObject The mapper.
	 */
	private function flowMapper(): FlowMapper&MockObject {
		$mapper = $this->createMock(FlowMapper::class);
		$store = function (Flow $flow): Flow {
			$this->flowWrites++;
			$this->flows[$flow->getUuid()] = $flow;
			return $flow;
		};
		$mapper->method('insert')->willReturnCallback($store);
		$mapper->method('update')->willReturnCallback($store);
		$mapper->method('findAllFlows')->willReturnCallback(
			fn (?string $app = null, ?string $applicationSlug = null) => array_values(
				array_filter(
					$this->flows,
					static fn (Flow $flow) => ($app === null || $flow->getApp() === $app)
						&& ($applicationSlug === null || $flow->getApplicationSlug() === $applicationSlug)
				)
			)
		);
		$mapper->method('findScheduled')->willReturnCallback(
			fn () => array_values(array_filter($this->flows, static fn (Flow $flow) => $flow->getEnabled() === true))
		);
		$mapper->method('findByUuid')->willReturnCallback(
			fn (string $uuid) => $this->flows[$uuid] ?? throw new DoesNotExistException('no flow')
		);

		return $mapper;
	}

	/**
	 * Publishing a flow marks it published.
	 *
	 * @return FlowVersionService&MockObject The service.
	 */
	private function flowVersionService(): FlowVersionService&MockObject {
		$versions = $this->createMock(FlowVersionService::class);
		$versions->method('publish')->willReturnCallback(
			function (Flow $flow, FlowPublishOptions $options): FlowVersion {
				$by = $options->publishedBy;
				$flow->setLifecycleStatus(FlowVersion::STATUS_PUBLISHED);
				$this->published[] = [(string)$flow->getUuid(), $by];
				return new FlowVersion();
			}
		);
		$versions->method('createDraft')->willReturnCallback(
			function (Flow $flow): FlowVersion {
				$flow->setLifecycleStatus(FlowVersion::STATUS_DRAFT);
				return new FlowVersion();
			}
		);

		return $versions;
	}

	/**
	 * The organisation holding the members.
	 *
	 * @return OrganisationMapper&MockObject The mapper.
	 */
	private function organisations(): OrganisationMapper&MockObject {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			function (): Organisation {
				$organisation = new Organisation();
				$organisation->setUsers($this->members);
				return $organisation;
			}
		);

		return $mapper;
	}

	/**
	 * The audit table.
	 *
	 * @return AuditTrailMapper&MockObject The mapper.
	 */
	private function auditMapper(): AuditTrailMapper&MockObject {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('applyRetentionExpiry')->willReturnArgument(0);
		$mapper->method('insert')->willReturnCallback(
			function (AuditTrail $entry): AuditTrail {
				if ($this->auditFails === true) {
					throw new RuntimeException('audit down');
				}

				$this->audit[] = $entry;
				return $entry;
			}
		);

		return $mapper;
	}

	/**
	 * The reader, in a register `buildiq` with schemas `application` (8) and `applicationVersion` (9).
	 *
	 * @param IUserSession $session The session.
	 *
	 * @return ScheduleReleaseReader The reader.
	 */
	private function reader(IUserSession $session): ScheduleReleaseReader {
		$register = new Register();
		$register->setSlug('buildiq');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id): Schema {
				$schema = new Schema();
				$schema->setSlug((string)$id === '8' ? 'application' : 'applicationVersion');
				return $schema;
			}
		);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(fn () => $this->version);

		$slugs = $this->createMock(RegisterSlugResolverInterface::class);
		$slugs->method('slugOrNull')->willReturnCallback(fn () => $this->slugsAnswer);

		return new ScheduleReleaseReader($objects, new ApplicationRegister(new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class)), $slugs), $session);
	}

	/**
	 * The locator over the flow table.
	 *
	 * @param FlowMapper $flowMapper The table.
	 *
	 * @return FlowLocator The locator.
	 */
	private function flowLocator(FlowMapper $flowMapper): FlowLocator {
		return new FlowLocator(
			new FlowLocatorStores($flowMapper, $this->createMock(FlowTriggerMapper::class), $this->createMock(FlowVersionMapper::class)),
			$this->createMock(ObjectOperationsInterface::class),
			$this->logger
		);
	}

	/**
	 * The real queue: version pin, attribution and delegation check included.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return FlowRunService The service.
	 */
	private function flowRunService(ContainerInterface $container): FlowRunService {
		$runs = $this->createMock(FlowRunMapperInterface::class);
		$runs->method('insert')->willReturnCallback(
			function (FlowRun $run): FlowRun {
				$this->runs[] = $run;
				return $run;
			}
		);

		return new FlowRunService(
			stores: new FlowRunStores(
				mapper: $runs,
				stateMapper: $this->createMock(FlowStateMapper::class)
			),
			walk: new FlowRunWalkServices(engine: $this->createMock(FlowEngine::class), registry: $this->createMock(FlowNodeRegistry::class)),
			logger: $this->logger,
			container: $container
		);
	}

	/**
	 * The container the queue and the trigger validator resolve from.
	 *
	 * @param FlowMapper $flowMapper The table.
	 * @param FlowTenantMembership $membership The membership check.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(FlowMapper $flowMapper, FlowTenantMembership $membership): ContainerInterface {
		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('findPublished')->willReturnCallback(
			fn (string $uuid) => isset($this->flows[$uuid]) ? new FlowVersion() : null
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($flowMapper, $versions, $membership) {
				return match ($id) {
					\OCA\OpenRegister\Db\FlowVersionMapperInterface::class => $versions,
					FlowTenantMembership::class => $membership,
					'OCA\OpenRegister\Db\FlowMapper' => $flowMapper,
					IUserSession::class => $this->createConfiguredMock(IUserSession::class, ['getUser' => $this->user()]),
					default => throw new DoesNotExistException($id),
				};
			}
		);

		return $container;
	}

	/**
	 * Last-fire markers in memory.
	 *
	 * @return IAppConfig&MockObject The config.
	 */
	private function appConfig(): IAppConfig&MockObject {
		$store = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		return $config;
	}
}
