<?php

/**
 * Golden test pinning what HandoffService's write helpers do.
 *
 * Written against the service before the arguments of compensate(),
 * writeProvenanceRelations() and writeAuditRows() were grouped. It pins the
 * raw-row reads those helpers make with RBAC and the organisation filter OFF
 * and in which register/schema context, the provenance updates, every key of
 * both audit rows, both shapes of compensation (target removed; source
 * restored through the write path only when it was already mutated), and
 * that a caller refused `update` on the source causes no write at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Handoff;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\HandoffQueueEntry;
use OCA\OpenRegister\Db\HandoffQueueEntryMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\HandoffExecutedEvent;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Handoff\HandoffLedger;
use OCA\OpenRegister\Service\Handoff\HandoffMappingEvaluator;
use OCA\OpenRegister\Service\Handoff\HandoffObjectAccess;
use OCA\OpenRegister\Service\Handoff\HandoffProviders;
use OCA\OpenRegister\Service\Handoff\HandoffRequesters;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterSchemaMappers;
use OCA\OpenRegister\Service\SemanticTypeResolver;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class HandoffServiceHelpersGoldenTest extends TestCase {

	private const CASE_URI = 'https://openregister.app/ns#Case';

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private MagicMapper&MockObject $objectMapper;

	private AuditTrailMapper&MockObject $auditTrailMapper;

	private PermissionHandler&MockObject $permissionHandler;

	private HandoffQueueEntryMapper&MockObject $queueMapper;

	private IDBConnection&MockObject $db;

	private IEventDispatcher&MockObject $eventDispatcher;

	private IUserSession&MockObject $userSession;

	private IUserManager&MockObject $userManager;

	private INotificationManager&MockObject $notificationManager;

	private HandoffService $service;

	/**
	 * Every MagicMapper find(), by parameter name, with entities reduced to ids.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rawFinds = [];

	/**
	 * Every MagicMapper update(), as [uuid, register id, schema id].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $rawUpdates = [];

	/**
	 * Every MagicMapper delete(), as the uuid.
	 *
	 * @var array<int, string>
	 */
	private array $rawDeletes = [];

	/**
	 * Every ObjectService saveObject(), by parameter name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every audit entry, as [uuid, action, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $audits = [];

	public function testTheHappyPathReadsRawRowsUnfilteredAndWritesBothAuditRows(): void {
		$this->arrange();

		$result = $this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');

		$this->assertSame(
			[
				['identifier' => 'src-uuid', 'register' => null, 'schema' => 12, 'includeDeleted' => false, '_rbac' => false, '_multitenancy' => false],
				['identifier' => 'tgt-uuid', 'register' => 9, 'schema' => 42, 'includeDeleted' => false, '_rbac' => false, '_multitenancy' => false],
			],
			$this->rawFinds
		);
		$this->assertSame([['src-uuid', null, 12], ['tgt-uuid', 9, 42]], $this->rawUpdates);
		$this->assertSame([], $this->rawDeletes);

		$context = [
			'handoffId' => 'request-to-case',
			'targetKind' => self::CASE_URI,
			'correlationId' => $result['correlationId'],
			'resolvedSchema' => 'case',
			'deferred' => false,
			'drainedAt' => null,
		];
		$this->assertSame(
			[
				['src-uuid', HandoffService::AUDIT_EXECUTED, $context + ['direction' => 'handed-off-to', 'counterpart' => 'tgt-uuid']],
				['tgt-uuid', HandoffService::AUDIT_EXECUTED, $context + ['direction' => 'originated-from', 'counterpart' => 'src-uuid']],
			],
			$this->audits
		);
	}

	public function testADeferredRunStampsTheDrainMomentOnBothAuditRows(): void {
		$this->arrange();

		$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case', deferred: true, correlationId: 'corr-1');

		$this->assertCount(2, $this->audits);
		foreach ($this->audits as $audit) {
			$this->assertSame('corr-1', $audit[2]['correlationId']);
			$this->assertTrue($audit[2]['deferred']);
			$this->assertIsString($audit[2]['drainedAt']);
		}
	}

	public function testAnOnSuccessFailureRemovesTheTargetAndLeavesTheSourceAlone(): void {
		$this->arrange(sourceSaveFails: true);

		try {
			$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');
			$this->fail('The onSuccess failure did not surface.');
		} catch (\RuntimeException $e) {
			$this->assertSame('source write failed', $e->getMessage());
		}

		$this->assertSame(
			[['identifier' => 'tgt-uuid', 'register' => 9, 'schema' => 42, 'includeDeleted' => false, '_rbac' => false, '_multitenancy' => false]],
			$this->rawFinds
		);
		$this->assertSame(['tgt-uuid'], $this->rawDeletes);
		$this->assertSame([null, 'src-uuid'], array_column($this->saves, 'uuid'));
		$this->assertSame([], $this->audits);
	}

	public function testALateFailureRemovesTheTargetAndRestoresTheSourceThroughTheWritePath(): void {
		$this->arrange(relationWriteFails: true);

		try {
			$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');
			$this->fail('The relation-write failure did not surface.');
		} catch (\RuntimeException $e) {
			$this->assertSame('relations write failed', $e->getMessage());
		}

		$this->assertSame(['tgt-uuid'], $this->rawDeletes);
		$this->assertSame(['identifier' => 'tgt-uuid', 'register' => 9, 'schema' => 42, 'includeDeleted' => false, '_rbac' => false, '_multitenancy' => false], end($this->rawFinds));

		$restore = end($this->saves);
		$this->assertSame('new', $restore['object']['status']);
		$this->assertSame('7', $restore['register']);
		$this->assertSame('12', $restore['schema']);
		$this->assertSame('src-uuid', $restore['uuid']);
		$this->assertSame([], $this->audits);
	}

	public function testAnUpdateRefusalOnTheSourceWritesNothing(): void {
		$this->arrange();
		$this->permissionHandler->method('checkPermission')->willReturnCallback(
			static function (Schema $schema, string $action, ...$rest): void {
				if ($action === 'update') {
					throw new NotAuthorizedException(message: 'update denied');
				}
			}
		);

		try {
			$this->service->execute(register: '7', schema: '12', id: 'src-uuid', handoffId: 'request-to-case');
			$this->fail('A caller without update on the source was not refused.');
		} catch (NotAuthorizedException $e) {
			$this->assertSame('update denied', $e->getMessage());
		}

		$this->assertSame([], $this->saves);
		$this->assertSame([], $this->rawFinds);
		$this->assertSame([], $this->audits);
	}

	/**
	 * Wire one handoff and record every write it makes.
	 *
	 * @param bool $sourceSaveFails    Whether the onSuccess write of the source throws.
	 * @param bool $relationWriteFails Whether the provenance-relation write throws.
	 *
	 * @return void
	 */
	private function arrange(bool $sourceSaveFails = false, bool $relationWriteFails = false): void {
		$this->wireSourceSchema();
		$this->wireProvider();
		$source = $this->sourceObject();
		$this->objectService->method('find')->willReturn($source);

		$target = $this->targetObject();
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use ($target, $source, $sourceSaveFails): ObjectEntity {
				$call = $this->named(class: ObjectService::class, method: 'saveObject', args: $args);
				$this->saves[] = $call;
				if ($call['uuid'] === 'src-uuid') {
					if ($sourceSaveFails === true && count($this->saves) === 2) {
						throw new \RuntimeException('source write failed');
					}

					return $source;
				}

				return $target;
			}
		);

		$raw = ['src-uuid' => $this->rawEntity('src-uuid'), 'tgt-uuid' => $this->rawEntity('tgt-uuid')];
		$this->objectMapper->method('find')->willReturnCallback(
			function (...$args) use ($raw): ObjectEntity {
				$call = $this->named(class: MagicMapper::class, method: 'find', args: $args);
				$call['register'] = $call['register']?->getId();
				$call['schema'] = $call['schema']?->getId();
				$this->rawFinds[] = $call;

				return $raw[$call['identifier']];
			}
		);
		$this->objectMapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity, ?Register $register = null, ?Schema $schema = null) use ($relationWriteFails): ObjectEntity {
				if ($relationWriteFails === true) {
					throw new \RuntimeException('relations write failed');
				}

				$this->rawUpdates[] = [(string)$entity->getUuid(), $register?->getId(), $schema?->getId()];

				return $entity;
			}
		);
		$this->objectMapper->method('delete')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->rawDeletes[] = (string)$entity->getUuid();

				return $entity;
			}
		);
		$this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []) {
				$this->audits[] = [(string)$object->getUuid(), $action, $context];

				return $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
			}
		);
	}

	/**
	 * Name the positional arguments PHPUnit handed a double.
	 *
	 * @param string            $class  The doubled class.
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $class, string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $index => $param) {
			if ($index >= 6) {
				break;
			}

			$out[$param->getName()] = ($args[$index] ?? null);
		}

		return $out;
	}

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->queueMapper = $this->createMock(HandoffQueueEntryMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$appManager->method('isInstalled')->willReturn(true);

		$resolver = new SemanticTypeResolver(
			mappers: new RegisterSchemaMappers($this->registerMapper, $this->schemaMapper),
			jsonLdContextService: new JsonLdContextService($this->createMock(IURLGenerator::class)),
			logger: $this->createMock(LoggerInterface::class),
			appManager: $appManager
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->service = new HandoffService(
			objects: new HandoffObjectAccess(
				objectService: $this->objectService,
				schemaMapper: $this->schemaMapper,
				objectMapper: $this->objectMapper,
				permissionHandler: $this->permissionHandler,
			),
			providers: new HandoffProviders(
				semanticTypeResolver: $resolver,
				mappingEvaluator: new HandoffMappingEvaluator(),
			),
			ledger: new HandoffLedger(
				auditTrailMapper: $this->auditTrailMapper,
				queueMapper: $this->queueMapper,
				db: $this->db,
				logger: $this->createMock(LoggerInterface::class),
			),
			requesters: new HandoffRequesters(
				userSession: $this->userSession,
				userManager: $this->userManager,
				notificationManager: $this->notificationManager,
				eventDispatcher: $this->eventDispatcher,
			),
		);

	}//end setUp()

	/**
	 * Wire the source schema (id 12) into the schema mapper's find().
	 *
	 * @param string $whenUnavailable The degradation mode to declare.
	 *
	 * @return Schema The source schema.
	 */
	private function wireSourceSchema(string $whenUnavailable = 'hide'): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('request');
		$schema->setUuid('uuid-12');
		$schema->setApplication('pipelinq');
		$schema->setProperties(
			[
				'subject' => ['type' => 'string'],
				'details' => ['type' => 'string'],
				'client' => ['type' => 'string'],
				'channel' => ['type' => 'string'],
				'priority' => ['type' => 'string'],
				'status' => [
					'type' => 'string',
					'enum' => ['new', 'handed-off'],
				],
			]
		);
		$schema->setConfiguration(
			[
				'x-openregister-handoff' => [
					[
						'id' => 'request-to-case',
						'targetSemanticType' => self::CASE_URI,
						'trigger' => 'manual',
						'whenUnavailable' => $whenUnavailable,
						'mapping' => [
							'title' => ['from' => 'subject'],
							'summary' => ['template' => '{{subject}} — {{details}}'],
							'requester' => ['semanticRef' => 'client'],
							'channel' => ['from' => 'channel'],
							'priority' => [
								'from' => 'priority',
								'default' => 'normal',
							],
							'source' => ['provenance' => true],
						],
						'onSuccess' => ['set' => ['status' => 'handed-off']],
					],
				],
			]
		);

		$this->schemaMapper->method('find')->willReturn($schema);

		return $schema;
	}//end wireSourceSchema()

	/**
	 * Wire an installed ns#Case provider (schema 42 in register 9, app
	 * procest) with a complete handoffContract binding into the resolver's
	 * enumeration path.
	 *
	 * @return Schema The provider schema.
	 */
	private function wireProvider(): Schema {
		$provider = new Schema();
		$provider->setId(42);
		$provider->setSlug('case');
		$provider->setUuid('uuid-42');
		$provider->setTitle('Case');
		$provider->setApplication('procest');
		$provider->setProperties(
			[
				'onderwerp' => ['type' => 'string'],
				'omschrijving' => ['type' => 'string'],
				'aanvrager' => ['type' => 'string'],
				'kanaal' => ['type' => 'string'],
				'prioriteit' => ['type' => 'string'],
				'herkomst' => ['type' => 'object'],
			]
		);
		$provider->setConfiguration(
			[
				'implements' => [self::CASE_URI],
				'handoffContract' => [
					self::CASE_URI => [
						'title' => 'onderwerp',
						'summary' => 'omschrijving',
						'requester' => 'aanvrager',
						'channel' => 'kanaal',
						'priority' => 'prioriteit',
						'source' => 'herkomst',
					],
				],
			]
		);

		$this->schemaMapper->method('findAll')->willReturn([$provider]);

		$register = new Register();
		$register->setId(9);
		$register->setSlug('procest-register');
		$register->setApplication('procest');
		$register->setSchemas([42]);
		$this->registerMapper->method('findAll')->willReturn([$register]);

		return $provider;
	}//end wireProvider()

	/**
	 * The canonical source object (pipelinq request).
	 *
	 * @return ObjectEntity
	 */
	private function sourceObject(): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('src-uuid');
		$source->setRegister('7');
		$source->setSchema('12');
		$source->setOwner('alice');
		$source->setName('Kapotte lantaarnpaal');
		$source->setObject(
			[
				'subject' => 'Kapotte lantaarnpaal',
				'details' => 'Voor de deur',
				'client' => '11111111-2222-3333-4444-555555555555',
				'channel' => 'telefoon',
				'priority' => 'hoog',
				'status' => 'new',
			]
		);

		return $source;
	}//end sourceObject()

	/**
	 * The created target object (procest case).
	 *
	 * @return ObjectEntity
	 */
	private function targetObject(): ObjectEntity {
		$target = new ObjectEntity();
		$target->setUuid('tgt-uuid');
		$target->setRegister('9');
		$target->setSchema('42');

		return $target;
	}//end targetObject()

	/**
	 * A raw entity as MagicMapper returns it for relation writes.
	 *
	 * @param string $uuid The entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function rawEntity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('7');
		$entity->setSchema('12');
		$entity->setRelations([]);

		return $entity;
	}//end rawEntity()
}
