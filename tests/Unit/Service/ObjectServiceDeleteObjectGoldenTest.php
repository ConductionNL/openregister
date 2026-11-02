<?php

/**
 * ObjectService::deleteObject() scopes, guards, checks the delete right for the acting user and hands over exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of deleteObject() over every branch: the scope it resolves, every object lookup
 * the guards make (transferred, archival draft, the owner lookup) with the flags they pass, each
 * refusal, the delete-right check (acting user, owner, object, rbac), the hand-over to the delete
 * handler, and the service context afterwards. The permission double grants only to the user a
 * scenario names, so a check run for anyone else fails. Set OR_GOLDEN_RECORD=1 to rewrite the
 * fixture.
 *
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Exception\AppendOnlyException
 * @uses   \OCA\OpenRegister\Exception\ArchivalImmutableException
 * @uses   \OCA\OpenRegister\Exception\ReadOnlyTypeException
 * @uses   \OCA\OpenRegister\Service\Object\ObjectDeleteRequest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class ObjectServiceDeleteObjectGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-service-delete-object-golden.json';

	private const U1 = '0b3f2c1a-1111-4222-8333-944455556666';

	/** @var array<int, mixed> */
	private array $calls = [];

	/** @var array<string, Register> */
	private array $registers = [];

	/** @var array<string, Schema> */
	private array $schemas = [];

	/** @var array<string, array<string, mixed>> */
	private array $stored = [];

	/** @var string|null */
	private ?string $grantTo = 'alice';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$plain = ['owner' => 'owner-1', 'schema' => '10'];
		$actual = [
			'scoped'                          => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10'], stored: [self::U1 => $plain]),
			'unscoped'                        => $this->scenario(call: [], stored: [self::U1 => $plain]),
			'schema only'                     => $this->scenario(call: ['schema' => 'S10'], stored: [self::U1 => $plain]),
			'register only'                   => $this->scenario(call: ['register' => 'R1'], stored: [self::U1 => $plain]),
			'string refs'                     => $this->scenario(call: ['register' => '1', 'schema' => '10'], stored: [self::U1 => $plain]),
			'flags off'                       => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10', 'rbac' => false, 'multitenancy' => false], stored: [self::U1 => $plain]),
			'rbac off only'                   => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10', 'rbac' => false], stored: [self::U1 => $plain]),
			'permanent asked'                 => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10', 'permanent' => true], stored: [self::U1 => $plain]),
			'explicit acting user'            => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10', 'user' => 'bob'], stored: [self::U1 => $plain], grantTo: 'bob'),
			'explicit user, session granted'  => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10', 'user' => 'bob'], stored: [self::U1 => $plain], grantTo: 'alice'),
			'permission denied'               => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10'], stored: [self::U1 => $plain], grantTo: null),
			'transferred'                     => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10'], stored: [self::U1 => ['retention' => ['archiefstatus' => 'overgebracht']] + $plain]),
			'append-only'                     => $this->scenario(call: ['register' => 'R1', 'schema' => 'S11'], stored: [self::U1 => ['schema' => '11'] + $plain]),
			'archival record'                 => $this->scenario(call: ['register' => 'R1', 'schema' => 'S12'], stored: [self::U1 => ['schema' => '12'] + $plain]),
			'archival, retention sweep'       => $this->scenario(call: ['register' => 'R1', 'schema' => 'S12', 'sweep' => true], stored: [self::U1 => ['schema' => '12'] + $plain]),
			'archival, own draft'             => $this->scenario(call: ['register' => 'R1', 'schema' => 'S12'], stored: [self::U1 => ['schema' => '12', 'draft' => true, 'owner' => 'alice']]),
			'archival, own draft, flags off'  => $this->scenario(call: ['register' => 'R1', 'schema' => 'S12', 'rbac' => false, 'multitenancy' => false], stored: [self::U1 => ['schema' => '12', 'draft' => true, 'owner' => 'alice']]),
			'archival, draft of another'      => $this->scenario(call: ['register' => 'R1', 'schema' => 'S12'], stored: [self::U1 => ['schema' => '12', 'draft' => true, 'owner' => 'mallory']]),
			'draft of another'                => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10'], stored: [self::U1 => ['draft' => true, 'owner' => 'mallory'] + $plain]),
			'draft of another, rbac off'      => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10', 'rbac' => false], stored: [self::U1 => ['draft' => true, 'owner' => 'mallory'] + $plain]),
			'draft of another, by admin'      => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10', 'user' => 'admin'], stored: [self::U1 => ['draft' => true, 'owner' => 'mallory'] + $plain], grantTo: 'admin'),
			'draft of acting user, not session' => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10', 'user' => 'bob'], stored: [self::U1 => ['draft' => true, 'owner' => 'bob'] + $plain], grantTo: 'bob'),
			'own draft'                       => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10'], stored: [self::U1 => ['draft' => true, 'owner' => 'alice'] + $plain]),
			'object source'                   => $this->scenario(call: ['register' => 'R1', 'schema' => 'S13'], stored: []),
			'object source, schema only'      => $this->scenario(call: ['schema' => 'S13', 'user' => 'bob'], stored: [], grantTo: 'bob'),
			'object source, denied'           => $this->scenario(call: ['register' => 'R1', 'schema' => 'S13'], stored: [], grantTo: null),
			'view-backed'                     => $this->scenario(call: ['register' => 'R1', 'schema' => 'S14'], stored: []),
			'scoped miss'                     => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10'], stored: []),
			'schema-only miss'                => $this->scenario(call: ['schema' => 'S10'], stored: []),
			'schema-only miss, denied'        => $this->scenario(call: ['schema' => 'S10'], stored: [], grantTo: null),
			'schema-only miss, acting user'   => $this->scenario(call: ['schema' => 'S10', 'user' => 'bob'], stored: [], grantTo: 'bob'),
			'unscoped miss'                   => $this->scenario(call: [], stored: []),
			'prior context restored'          => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10'], stored: [self::U1 => $plain], prior: true),
			'prior context restored on throw' => $this->scenario(call: ['register' => 'R1', 'schema' => 'S10'], stored: [self::U1 => $plain], prior: true, grantTo: null),
			'pending ref is not inherited'    => $this->scenario(call: ['register' => 'R1'], stored: [self::U1 => $plain], pendingRef: '20'),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run deleteObject() once on a fresh service and record what happened.
	 *
	 * @param array<string, mixed>                $call       The call's arguments.
	 * @param array<string, array<string, mixed>> $stored     The stored objects by uuid.
	 * @param string|null                         $grantTo    The only user the delete right is granted to; null grants nobody.
	 * @param bool                                $prior      Whether the service already has a register/schema context.
	 * @param string|null                         $pendingRef A schema ref left pending by an earlier caller.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	private function scenario(array $call, array $stored, ?string $grantTo = 'alice', bool $prior = false, ?string $pendingRef = null): array {
		$this->calls = [];
		$this->stored = $stored;
		$this->grantTo = $grantTo;
		$service = $this->service();

		if ($prior === true) {
			$service->setRegister(register: $this->registers['2']);
			$service->setSchema(schema: $this->schemas['20']);
		}

		if ($pendingRef !== null) {
			$service->setSchema(schema: $pendingRef);
		}

		$this->calls[] = ['setup done'];

		$resolve = function (mixed $ref, array $pool): mixed {
			if (is_string($ref) === true && preg_match('/^[RS](\d+)$/', $ref, $m) === 1) {
				return $pool[$m[1]];
			}

			return $ref;
		};
		$currentUser = null;
		if (isset($call['user']) === true) {
			$currentUser = $this->user(uid: $call['user']);
		}

		try {
			$result = ['returned' => $service->deleteObject(
				uuid: self::U1,
				register: $resolve($call['register'] ?? null, $this->registers),
				schema: $resolve($call['schema'] ?? null, $this->schemas),
				_rbac: ($call['rbac'] ?? true),
				_multitenancy: ($call['multitenancy'] ?? true),
				_retentionSweep: ($call['sweep'] ?? false),
				currentUser: $currentUser,
				permanent: ($call['permanent'] ?? false)
			)];
		} catch (\Throwable $e) {
			$result = ['exception' => [get_class($e), $e->getMessage()]];
		}

		return [
			'result' => $result,
			'calls'  => $this->calls,
			'after'  => [
				'register' => $service->getCurrentRegisterEntity()?->getId(),
				'schema'   => $service->getCurrentSchemaEntity()?->getId(),
			],
		];
	}//end scenario()

	/**
	 * A user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A service over recording doubles.
	 *
	 * @return ObjectService
	 */
	private function service(): ObjectService {
		$this->registers = [];
		$this->schemas = [];
		$configs = [
			10 => [null, false],
			11 => [null, true],
			12 => [['x-openregister-archival' => ['term' => 'P10Y']], false],
			13 => [['x-openregister-object-source' => ['provider' => 'database']], false],
			14 => [['x-openregister-view' => ['view' => 'v-1']], false],
			20 => [null, false],
		];
		foreach ($configs as $id => [$configuration, $appendOnly]) {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setSlug('schema-' . $id);
			$schema->setConfiguration($configuration);
			$schema->setAppendOnly($appendOnly);
			$this->schemas[(string) $id] = $schema;
		}

		foreach ([1 => [10, 11, 12, 13, 14], 2 => [20]] as $id => $schemaIds) {
			$register = new Register();
			$register->setId($id);
			$register->setSlug('register-' . $id);
			$register->setSchemas($schemaIds);
			$this->registers[(string) $id] = $register;
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function ($id, ...$rest): Register {
				$this->calls[] = ['registerMapper.find' => $id];
				if (isset($this->registers[(string) $id]) === false) {
					throw new DoesNotExistException('no such register: ' . $id);
				}

				return $this->registers[(string) $id];
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id, ...$rest): Schema {
				$this->calls[] = ['schemaMapper.find' => $id];
				if (isset($this->schemas[(string) $id]) === false) {
					throw new DoesNotExistException('no such schema: ' . $id);
				}

				return $this->schemas[(string) $id];
			}
		);
		$schemaMapper->method('findInIds')->willReturnCallback(
			function ($id, array $schemaIds): ?Schema {
				$this->calls[] = ['schemaMapper.findInIds' => $id, 'in' => $schemaIds];
				$schema = ($this->schemas[(string) $id] ?? null);
				if ($schema === null || in_array($schema->getId(), $schemaIds, true) === false) {
					return null;
				}

				return $schema;
			}
		);

		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('find')->willReturnCallback(
			function (string|int $identifier, ?Register $register = null, ?Schema $schema = null, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->calls[] = ['objectMapper.find' => $identifier, 'register' => $register?->getId(), 'schema' => $schema?->getId(), 'includeDeleted' => $includeDeleted, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$spec = ($this->stored[(string) $identifier] ?? null);
				if ($spec === null || ($schema !== null && (string) $schema->getId() !== $spec['schema'])) {
					throw new DoesNotExistException('Object not found');
				}

				$object = new ObjectEntity();
				$object->setUuid((string) $identifier);
				$object->setRegister('1');
				$object->setSchema($spec['schema']);
				$object->setOwner($spec['owner']);
				$object->setRetention($spec['retention'] ?? null);
				if (($spec['draft'] ?? false) === true) {
					$object->setStatus(ObjectEntity::STATUS_DRAFT);
				}

				return $object;
			}
		);

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('checkPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null): void {
				$this->calls[] = ['checkPermission' => $action, 'schema' => $schema->getId(), 'userId' => $userId, 'owner' => $objectOwner, 'rbac' => $_rbac, 'object' => $object?->getUuid()];
				$actor = ($userId ?? 'alice');
				if ($this->grantTo === null || $actor !== $this->grantTo) {
					throw new Exception('delete denied for ' . $actor);
				}
			}
		);

		$deleteHandler = $this->createMock(DeleteObject::class);
		$deleteHandler->method('deleteObject')->willReturnCallback(
			function (Register|int|string|null $register, Schema|int|string|null $schema, string $uuid, ?string $originalObjectId = null, bool $_rbac = true, bool $_multitenancy = true, bool $scoped = false, ?ObjectEntity $preResolved = null, bool $permanent = false): bool {
				$this->calls[] = [
					'deleteHandler' => $uuid,
					'register'      => $register instanceof Register ? $register->getId() : $register,
					'schema'        => $schema instanceof Schema ? $schema->getId() : $schema,
					'original'      => $originalObjectId,
					'rbac'          => $_rbac,
					'multitenancy'  => $_multitenancy,
					'scoped'        => $scoped,
					'preResolved'   => $preResolved?->getUuid(),
					'permanent'     => $permanent,
				];
				return true;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user(uid: 'alice'));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			fn (IUser $user): array => $user->getUID() === 'admin' ? ['admin'] : ['users']
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['trace']);
					$this->calls[] = ['log' => $level, 'message' => (string) $message, 'context' => $context];
				}
			);
		}

		return new ObjectService(
			dataManipHandler: $this->createMock(DataManipulationHandler::class),
			deleteHandler: $deleteHandler,
			getHandler: $this->createMock(GetObject::class),
			permissionHandler: $permissionHandler,
			renderHandler: $this->createMock(RenderObject::class),
			saveHandler: $this->createMock(SaveObject::class),
			saveObjectsHandler: $this->createMock(SaveObjects::class),
			searchQueryHandler: $this->createMock(SearchQueryHandler::class),
			validateHandler: $this->createMock(ValidateObject::class),
			lockHandler: $this->createMock(LockHandler::class),
			auditHandler: $this->createMock(AuditHandler::class),
			relationHandler: $this->createMock(RelationHandler::class),
			mergeHandler: $this->createMock(MergeHandler::class),
			facetHandler: $this->createMock(FacetHandler::class),
			metadataHandler: $this->createMock(MetadataHandler::class),
			perfOptHandler: $this->createMock(PerformanceOptimizationHandler::class),
			queryHandler: $this->createMock(QueryHandler::class),
			revertHandler: $this->createMock(RevertHandler::class),
			utilityHandler: $this->createMock(UtilityHandler::class),
			validationHandler: $this->createMock(ValidationHandler::class),
			cascadingHandler: $this->createMock(CascadingHandler::class),
			migrationHandler: $this->createMock(MigrationHandler::class),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			viewMapper: $this->createMock(ViewMapper::class),
			objectMapper: $objectMapper,
			fileService: $this->createMock(FileService::class),
			userSession: $session,
			searchTrailService: $this->createMock(SearchTrailService::class),
			groupManager: $groupManager,
			userManager: $this->createMock(IUserManager::class),
			organisationService: $this->createMock(OrganisationService::class),
			logger: $logger,
			cacheHandler: $this->createMock(CacheHandler::class),
			settingsService: $this->createMock(SettingsService::class),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizer::class),
			container: $this->createMock(IAppContainer::class),
			objectSourceRegistry: $this->createMock(ObjectSourceRegistry::class)
		);
	}//end service()
}//end class
