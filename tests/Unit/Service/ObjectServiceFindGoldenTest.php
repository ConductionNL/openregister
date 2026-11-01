<?php

/**
 * ObjectService::find() resolves its scope, retrieves, re-anchors, checks read permission and renders exactly as recorded.
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
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
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
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Golden record of find() over every branch: the scope each retrieval runs in, the cross-schema
 * fallback, the re-anchoring, the request-scoped uuid cache, the read-permission check (schema,
 * owner and object it runs for), the processing-read hook, the render call, the returned value or
 * exception, and the service context afterwards. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses   \OCA\OpenRegister\Service\Object\ObjectFindRequest
 * @uses   \OCA\OpenRegister\Service\Object\ObjectFindScope
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class ObjectServiceFindGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-service-find-golden.json';

	private const UUID = '0b3f2c1a-1111-4222-8333-944455556666';

	private const OTHER_UUID = '0b3f2c1a-1111-4222-8333-000000000000';

	/** @var array<int, array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, Register> */
	private array $registers = [];

	/** @var array<string, Schema> */
	private array $schemas = [];

	/**
	 * How the get handler answers, keyed "<id>|<schema id or ->": 'obj', 'other' (another uuid) or 'miss'.
	 *
	 * @var array<string, string>
	 */
	private array $answers = [];

	/** @var bool */
	private bool $denyRead = false;

	/** @var bool */
	private bool $readHookFails = false;

	/** @var string|null */
	private ?string $objectRegister = '1';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [
			'no scope, found'                  => $this->runScenario(steps: [['id' => self::UUID]], answers: [self::UUID . '|-' => 'obj']),
			'register and schema entities'     => $this->runScenario(steps: [['id' => self::UUID, 'register' => 'R1', 'schema' => 'S10']], answers: [self::UUID . '|10' => 'obj']),
			'register only'                    => $this->runScenario(steps: [['id' => self::UUID, 'register' => 'R1']], answers: [self::UUID . '|-' => 'obj']),
			'schema only'                      => $this->runScenario(steps: [['id' => self::UUID, 'schema' => 'S10']], answers: [self::UUID . '|10' => 'obj']),
			'string refs'                      => $this->runScenario(steps: [['id' => self::UUID, 'register' => '1', 'schema' => '10']], answers: [self::UUID . '|10' => 'obj']),
			'render false'                     => $this->runScenario(steps: [['id' => self::UUID, 'register' => 'R1', 'schema' => 'S10', 'render' => false]], answers: [self::UUID . '|10' => 'obj']),
			'flags passed through'             => $this->runScenario(steps: [['id' => self::UUID, 'schema' => 'S10', 'extend' => ['a', 'b'], 'files' => true, 'rbac' => false, 'multitenancy' => false, 'audit' => false]], answers: [self::UUID . '|10' => 'obj']),
			'rbac off, multitenancy on'        => $this->runScenario(steps: [['id' => self::UUID, 'schema' => 'S10', 'rbac' => false]], answers: [self::UUID . '|10' => 'obj']),
			'scoped miss, uuid falls back'     => $this->runScenario(steps: [['id' => self::UUID, 'register' => 'R1', 'schema' => 'S11']], answers: [self::UUID . '|11' => 'miss', self::UUID . '|-' => 'obj']),
			'scoped miss, schema only'         => $this->runScenario(steps: [['id' => self::UUID, 'schema' => 'S11']], answers: [self::UUID . '|11' => 'miss', self::UUID . '|-' => 'obj']),
			'scoped miss, slug rethrows'       => $this->runScenario(steps: [['id' => 'my-slug', 'register' => 'R1', 'schema' => 'S11']], answers: ['my-slug|11' => 'miss']),
			'unscoped miss rethrows'           => $this->runScenario(steps: [['id' => self::UUID]], answers: [self::UUID . '|-' => 'miss']),
			'fallback miss rethrows'           => $this->runScenario(steps: [['id' => self::UUID, 'schema' => 'S11']], answers: [self::UUID . '|11' => 'miss', self::UUID . '|-' => 'miss']),
			'repeat uses the uuid cache'       => $this->runScenario(steps: [['id' => self::UUID, 'register' => 'R1', 'schema' => 'S11'], ['id' => self::UUID, 'register' => 'R2', 'schema' => 'S20']], answers: [self::UUID . '|11' => 'miss', self::UUID . '|-' => 'obj', self::UUID . '|10' => 'obj']),
			'cached scope that misses'         => $this->runScenario(steps: [['id' => self::UUID], ['id' => self::UUID]], answers: [self::UUID . '|-' => 'obj', self::UUID . '|10' => 'miss'], flipAfterFirst: [self::UUID . '|-' => 'other']),
			'cached scope, fallback misses'    => $this->runScenario(steps: [['id' => self::UUID], ['id' => self::UUID]], answers: [self::UUID . '|-' => 'obj', self::UUID . '|10' => 'miss'], flipAfterFirst: [self::UUID . '|-' => 'miss']),
			'register only, scoped miss'       => $this->runScenario(steps: [['id' => self::UUID, 'register' => 'R1']], answers: [self::UUID . '|-' => 'miss']),
			'slug is not cached'               => $this->runScenario(steps: [['id' => 'my-slug', 'schema' => 'S10'], ['id' => 'my-slug', 'schema' => 'S10']], answers: ['my-slug|10' => 'obj']),
			'read denied'                      => $this->runScenario(steps: [['id' => self::UUID, 'schema' => 'S10']], answers: [self::UUID . '|10' => 'obj'], denyRead: true),
			'read hook fails'                  => $this->runScenario(steps: [['id' => self::UUID, 'schema' => 'S10']], answers: [self::UUID . '|10' => 'obj'], readHookFails: true),
			'object without register'          => $this->runScenario(steps: [['id' => self::UUID, 'schema' => 'S10']], answers: [self::UUID . '|10' => 'obj'], objectRegister: ''),
			'object with null register'        => $this->runScenario(steps: [['id' => self::UUID]], answers: [self::UUID . '|-' => 'obj'], objectRegister: null),
			'prior context restored'           => $this->runScenario(steps: [['id' => self::UUID, 'register' => 'R1', 'schema' => 'S10']], answers: [self::UUID . '|10' => 'obj'], prior: true),
			'prior context restored on throw'  => $this->runScenario(steps: [['id' => self::UUID, 'register' => 'R1', 'schema' => 'S10']], answers: [self::UUID . '|10' => 'obj'], prior: true, denyRead: true),
			'pending ref is not inherited'     => $this->runScenario(steps: [['id' => self::UUID, 'register' => 'R1']], answers: [self::UUID . '|-' => 'obj'], pendingRef: '20'),
			'integer id'                       => $this->runScenario(steps: [['id' => 42, 'schema' => 'S10']], answers: ['42|10' => 'obj']),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run one or more find() calls on one service and record what happened.
	 *
	 * @param array<int, array<string, mixed>> $steps          The find() calls.
	 * @param array<string, string>            $answers        How the get handler answers.
	 * @param array<string, string>            $flipAfterFirst Answers that replace the first ones after the first call.
	 * @param bool                             $denyRead       Whether the read permission check throws.
	 * @param bool                             $readHookFails  Whether the processing-read hook cannot be resolved.
	 * @param string|null                      $objectRegister The register id stored on the object.
	 * @param bool                             $prior          Whether the service already has a register/schema context.
	 * @param string|null                      $pendingRef     A schema ref left pending by an earlier caller.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	private function runScenario(
		array $steps,
		array $answers,
		array $flipAfterFirst = [],
		bool $denyRead = false,
		bool $readHookFails = false,
		?string $objectRegister = '1',
		bool $prior = false,
		?string $pendingRef = null,
	): array {
		$this->calls = [];
		$this->answers = $answers;
		$this->denyRead = $denyRead;
		$this->readHookFails = $readHookFails;
		$this->objectRegister = $objectRegister;
		$service = $this->service();

		if ($prior === true) {
			$service->setRegister(register: $this->registers['2']);
			$service->setSchema(schema: $this->schemas['20']);
		}

		if ($pendingRef !== null) {
			$service->setSchema(schema: $pendingRef);
		}

		$this->calls[] = ['setup done'];

		$results = [];
		foreach ($steps as $index => $step) {
			if ($index === 1 && $flipAfterFirst !== []) {
				$this->answers = array_merge($this->answers, $flipAfterFirst);
			}

			$results[] = $this->callFind(service: $service, step: $step);
		}

		$cache = new ReflectionProperty(ObjectService::class, 'uuidScopeCache');
		$cached = [];
		foreach ($cache->getValue($service) as $key => $scope) {
			$cached[$key] = ['register' => $scope['register']?->getId(), 'schema' => $scope['schema']?->getId()];
		}

		return [
			'results' => $results,
			'calls'   => $this->calls,
			'after'   => [
				'register' => $service->getCurrentRegisterEntity()?->getId(),
				'schema'   => $service->getCurrentSchemaEntity()?->getId(),
				'cache'    => $cached,
			],
		];
	}//end runScenario()

	/**
	 * Call find() for one step.
	 *
	 * @param ObjectService        $service The service.
	 * @param array<string, mixed> $step    The call's arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function callFind(ObjectService $service, array $step): array {
		$resolve = function (mixed $ref, array $pool): mixed {
			if (is_string($ref) === true && preg_match('/^[RS](\d+)$/', $ref, $m) === 1) {
				return $pool[$m[1]];
			}

			return $ref;
		};

		try {
			$found = $service->find(
				id: $step['id'],
				_extend: ($step['extend'] ?? []),
				files: ($step['files'] ?? false),
				register: $resolve($step['register'] ?? null, $this->registers),
				schema: $resolve($step['schema'] ?? null, $this->schemas),
				_rbac: ($step['rbac'] ?? true),
				_multitenancy: ($step['multitenancy'] ?? true),
				_render: ($step['render'] ?? true),
				_audit: ($step['audit'] ?? true)
			);
			return ['returned' => $found?->getUuid(), 'rendered' => $found?->getObject()['rendered'] ?? false];
		} catch (\Throwable $e) {
			$message = preg_replace('/MockObject_\w+/', 'MockObject', $e->getMessage());
			$message = preg_replace('/, called in .*$/', '', (string) $message);
			return ['exception' => [get_class($e), $message]];
		}
	}//end callFind()

	/**
	 * A service over recording doubles.
	 *
	 * @return ObjectService
	 */
	private function service(): ObjectService {
		$this->registers = [];
		$this->schemas = [];
		foreach ([10, 11, 20] as $id) {
			$schema = new Schema();
			$schema->setId($id);
			$schema->setSlug('schema-' . $id);
			$this->schemas[(string) $id] = $schema;
		}

		foreach ([1 => [10, 11], 2 => [20]] as $id => $schemaIds) {
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

		$getHandler = $this->createMock(GetObject::class);
		$getHandler->method('find')->willReturnCallback(
			function (string $id, ?Register $register = null, ?Schema $schema = null, ?array $_extend = [], bool $files = false, bool $_rbac = true, bool $_multitenancy = true, bool $_audit = true): ObjectEntity {
				$this->calls[] = ['get' => $id, 'register' => $register?->getId(), 'schema' => $schema?->getId(), 'extend' => $_extend, 'files' => $files, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'audit' => $_audit];
				$answer = ($this->answers[$id . '|' . ($schema?->getId() ?? '-')] ?? 'miss');
				if ($answer === 'miss') {
					throw new DoesNotExistException('not found: ' . $id);
				}

				$object = new ObjectEntity();
				$object->setUuid($answer === 'other' ? self::OTHER_UUID : $id);
				$object->setRegister($this->objectRegister);
				$object->setSchema('10');
				$object->setOwner('owner-1');
				$object->setObject(['title' => 'x']);
				return $object;
			}
		);

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('checkPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null): void {
				$this->calls[] = ['checkPermission' => $action, 'schema' => $schema->getId(), 'userId' => $userId, 'owner' => $objectOwner, 'rbac' => $_rbac, 'object' => $object?->getUuid()];
				if ($this->denyRead === true) {
					throw new Exception('read denied');
				}
			}
		);

		$renderHandler = $this->createMock(RenderObject::class);
		$renderHandler->method('renderEntity')->willReturnCallback(
			function (ObjectEntity $entity, array|string|null $_extend = [], int $depth = 0, ?array $filter = [], ?array $fields = [], ?array $unset = [], ?array $registers = [], ?array $schemas = [], ?array $objects = [], ?array $visitedIds = [], bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->calls[] = ['render' => $entity->getUuid(), 'extend' => $_extend, 'registers' => $registers === null ? null : array_keys($registers), 'schemas' => $schemas === null ? null : array_keys($schemas), 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$entity->setObject(['title' => 'x', 'rendered' => true]);
				return $entity;
			}
		);

		$readHistory = $this->createMock(ReadHistoryService::class);
		$readHistory->method('registerProcessingRead')->willReturnCallback(
			function (ObjectEntity $object): void {
				$this->calls[] = ['processingRead' => $object->getUuid()];
			}
		);
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($readHistory) {
				$this->calls[] = ['container.get' => $id];
				if ($this->readHookFails === true) {
					throw new Exception('no read history');
				}

				return $readHistory;
			}
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
			deleteHandler: $this->createMock(DeleteObject::class),
			getHandler: $getHandler,
			permissionHandler: $permissionHandler,
			renderHandler: $renderHandler,
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
			objectMapper: $this->createMock(MagicMapper::class),
			fileService: $this->createMock(FileService::class),
			userSession: $this->createMock(IUserSession::class),
			searchTrailService: $this->createMock(SearchTrailService::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			organisationService: $this->createMock(OrganisationService::class),
			logger: $logger,
			cacheHandler: $this->createMock(CacheHandler::class),
			settingsService: $this->createMock(SettingsService::class),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizer::class),
			container: $container,
			objectSourceRegistry: $this->createMock(ObjectSourceRegistry::class)
		);
	}//end service()
}//end class
