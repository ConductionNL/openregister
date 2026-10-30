<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use DateTime;
use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
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
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\Collaboration\Reference\IReferenceManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Invocation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for ObjectService::saveObject().
 *
 * The service runs with its own private steps (context, permission checks, the
 * append-only, transfer and view gates, defaults, date normalisation, shape,
 * read-only and lens checks, folder, cache invalidation, render, scope restore).
 * Every collaborator is a recording stub: each call, with its arguments, is kept
 * in order and the scripted collaborators answer per method. The calls and the
 * result (or the exception) are compared with fixtures/object-service-save-object-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class ObjectServiceSaveObjectGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/object-service-save-object-golden.json';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'create' => [['object' => ['title' => 'New', 'when' => '2024-01-15T10:30:00+02:00']]],
			'create with generated uuid' => [['object' => ['title' => 'New'], 'generateUuid' => 'gen-1']],
			'create with generated uuid and scalar self' => [['object' => ['title' => 'New', '@self' => 'x'], 'generateUuid' => 'gen-1']],
			'create with chosen id' => [['object' => ['id' => 'chosen-1', 'title' => 'Mine']]],
			'update existing' => [['object' => ['title' => 'Changed'], 'uuid' => 'u-1', 'existing' => ['title' => 'Old']]],
			'update from entity' => [['entity' => ['uuid' => 'u-1', 'title' => 'Entity'], 'existing' => ['title' => 'Old']]],
			'scope by id' => [['object' => ['title' => 'x'], 'register' => 5, 'schema' => 6]],
			'every flag' => [[
				'object' => ['title' => 'x'],
				'uuid' => 'u-1',
				'existing' => ['title' => 'Old'],
				'extend' => ['owner'],
				'rbac' => false,
				'multi' => false,
				'silent' => true,
				'validation' => false,
				'files' => ['file' => ['name' => 'a.txt']],
				'user' => 'alice',
				'failIfExists' => true,
				'unowned' => true,
				'dedup' => true,
				'owner' => 'portal:abc',
			]],
			'null extend' => [['object' => ['title' => 'x'], 'extend' => null]],
			'schema not in register' => [['object' => ['title' => 'x'], 'schema' => 'none']],
			'no schema' => [['object' => ['title' => 'x'], 'noSchema' => true]],
			'invalid' => [['object' => ['title' => 'x'], 'invalid' => true]],
			'soft validation' => [['object' => ['title' => 'x'], 'invalid' => true, 'hardValidation' => false]],
			'view backed' => [['object' => ['title' => 'x'], 'schemaSource' => ['provider' => 'view']]],
			'append-only update' => [['object' => ['title' => 'x'], 'uuid' => 'u-1', 'existing' => ['title' => 'Old'], 'appendOnly' => true]],
			'append-only insert with chosen uuid' => [['object' => ['title' => 'x'], 'uuid' => 'u-new', 'appendOnly' => true]],
			'transferred' => [['object' => ['title' => 'x'], 'uuid' => 'u-1', 'existing' => ['title' => 'Old'], 'transferred' => true]],
			'save fails' => [['object' => ['title' => 'x'], 'saveThrows' => true]],
			'contact cache fails' => [['object' => ['title' => 'x'], 'contactThrows' => true]],
			'reference cache fails' => [['object' => ['title' => 'x'], 'referenceThrows' => true]],
			'deep link' => [['object' => ['title' => 'x'], 'deepLink' => 'https://app/deep/1']],
			'explicit reveal on update' => [['object' => ['title' => 'x'], 'uuid' => 'u-1', 'existing' => ['title' => 'Old', 'secret' => 's'], 'explicit' => true]],
			'explicit reveal on create with generated uuid' => [['object' => ['title' => 'x'], 'generateUuid' => 'gen-1', 'explicit' => true]],
			'auto transition' => [['object' => ['title' => 'x'], 'autoTransitions' => true]],
			'real uuid update (dedup observable)' => [['object' => ['title' => 'x'], 'uuid' => '3f2b8c1e-4d5a-4b6c-8d7e-9f0a1b2c3d4e', 'existing' => ['title' => 'Old']]],
			'real uuid create via cascade' => [['object' => ['title' => 'x'], 'generateUuid' => '3f2b8c1e-4d5a-4b6c-8d7e-9f0a1b2c3d4e', 'deepLink' => 'https://app/deep/2']],
			'real uuid, no register' => [['object' => ['title' => 'x'], 'uuid' => '3f2b8c1e-4d5a-4b6c-8d7e-9f0a1b2c3d4e', 'existing' => ['title' => 'Old'], 'register' => null]],
			'append-only create without uuid' => [['object' => ['title' => 'x'], 'appendOnly' => true]],
			'append-only create with generated uuid' => [['object' => ['title' => 'x'], 'appendOnly' => true, 'generateUuid' => 'gen-2']],
			'append-only insert, caller failIfExists false' => [['object' => ['id' => 'chosen-2', 'title' => 'x'], 'appendOnly' => true]],
			'append-only and transferred' => [['object' => ['title' => 'x'], 'uuid' => 'u-1', 'existing' => ['title' => 'Old'], 'appendOnly' => true, 'transferred' => true]],
			'view backed update' => [['object' => ['title' => 'x'], 'uuid' => 'u-1', 'existing' => ['title' => 'Old'], 'schemaSource' => ['provider' => 'view']]],
			'no schema with uuid' => [['object' => ['title' => 'x'], 'uuid' => 'u-1', 'existing' => ['title' => 'Old'], 'noSchema' => true]],
			'cascade swaps uuid on update' => [['object' => ['title' => 'x'], 'uuid' => 'u-1', 'existing' => ['title' => 'Old', 'secret' => 's'], 'cascadeUuid' => 'u-2', 'explicit' => true]],
			'self id' => [['object' => ['@self' => ['id' => 'sid-1'], 'title' => 'x']]],
			'whitespace id' => [['object' => ['id' => '   ', 'title' => 'x']]],
			'entity with explicit uuid param' => [['entity' => ['uuid' => 'u-1', 'title' => 'Entity'], 'uuid' => 'u-9', 'existing' => ['title' => 'Old']]],
			'save fails with auto transitions' => [['object' => ['title' => 'x'], 'saveThrows' => true, 'autoTransitions' => true]],
			'render fails' => [['object' => ['title' => 'x'], 'renderThrows' => true]],
			'contact and reference fail' => [['object' => ['title' => 'x'], 'contactThrows' => true, 'referenceThrows' => true]],
			'preset scope restored' => [['object' => ['title' => 'x'], 'preset' => true]],
			'preset scope restored on failure' => [['object' => ['title' => 'x'], 'preset' => true, 'invalid' => true]],
			'invalid on update' => [['object' => ['title' => 'x'], 'uuid' => 'u-1', 'existing' => ['title' => 'Old'], 'invalid' => true]],
			'generated uuid with failIfExists and every flag off' => [['object' => ['title' => 'x'], 'generateUuid' => 'gen-3', 'failIfExists' => true, 'rbac' => false, 'multi' => false, 'validation' => false, 'silent' => true]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testSaveObjectMatchesGolden(array $case): void {
		$name = $this->dataName();
		$service = $this->service(case: $case);
		$scope = new \ReflectionClass(ObjectService::class);
		if (($case['preset'] ?? false) === true) {
			$scope->getProperty('currentRegister')->setValue($service, $this->register(id: 77));
			$scope->getProperty('currentSchema')->setValue($service, $this->schema(case: [], id: 78));
		}

		$object = ($case['object'] ?? []);
		if (isset($case['entity']) === true) {
			$object = $this->entity(uuid: $case['entity']['uuid'], data: ['title' => $case['entity']['title']]);
		}

		$user = null;
		if (isset($case['user']) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		try {
			$saved = $service->saveObject(
				$object,
				array_key_exists('extend', $case) === true ? $case['extend'] : [],
				(array_key_exists('register', $case) === true ? $case['register'] : $this->register()),
				(($case['noSchema'] ?? false) === true) ? null : ($case['schema'] ?? $this->schema(case: $case)),
				($case['uuid'] ?? null),
				($case['rbac'] ?? true),
				($case['multi'] ?? true),
				($case['silent'] ?? false),
				($case['validation'] ?? true),
				($case['files'] ?? null),
				$user,
				($case['failIfExists'] ?? false),
				($case['unowned'] ?? false),
				($case['dedup'] ?? false),
				($case['owner'] ?? null)
			);
			$actual = ['result' => $this->describe($saved)];
		} catch (\Throwable $e) {
			$actual = ['threw' => get_class($e), 'message' => preg_replace('/, called in .*$/', '', (string)$this->describe($e->getMessage()))];
		}

		$actual['calls'] = $this->calls;
		foreach (['currentRegister', 'currentSchema', 'currentSchemaRef'] as $property) {
			$actual['after'][$property] = $this->describe($scope->getProperty($property)->getValue($service));
		}
		$actual = json_decode(json_encode($actual), true);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testSaveObjectMatchesGolden()

	/**
	 * Build the service with every collaborator a recording stub.
	 *
	 * @param array<string, mixed> $case
	 */
	private function service(array $case): ObjectService {
		$existing = null;
		if (isset($case['existing']) === true) {
			$existing = $this->entity(uuid: (string)($case['uuid'] ?? $case['entity']['uuid']), data: $case['existing']);
			if (($case['transferred'] ?? false) === true) {
				$existing->setRetention(['archiefstatus' => 'overgebracht']);
			}
		}

		$find = function (array $args) use ($existing): ObjectEntity {
			if ($existing === null) {
				throw new DoesNotExistException('not found');
			}

			return $existing;
		};

		$container = $this->recorder(IAppContainer::class, 'container', [
			'get' => function (array $args) use ($case) {
				if ($args[0] === ContactMatchingService::class) {
					if (($case['contactThrows'] ?? false) === true) {
						throw new Exception('contacts down');
					}

					return $this->recorder(ContactMatchingService::class, 'contacts', []);
				}

				if (($case['referenceThrows'] ?? false) === true) {
					throw new Exception('references down');
				}

				return match ($args[0]) {
					IReferenceManager::class => $this->recorder(IReferenceManager::class, 'references', []),
					ObjectPreviewFormatter::class => new ObjectPreviewFormatter(
						$this->recorder(IURLGenerator::class, 'urls', [
							'getAbsoluteURL' => fn (array $a): string => 'https://cloud.test' . $a[0],
						]),
						$this->createStub(IL10N::class),
						$this->createStub(ObjectService::class),
						$this->createStub(DeepLinkRegistryService::class),
						$this->createStub(SchemaMapper::class),
						$this->createStub(RegisterMapper::class),
						$this->createStub(LoggerInterface::class)
					),
					DeepLinkRegistryService::class => $this->recorder(DeepLinkRegistryService::class, 'deepLinks', [
						'resolveUrl' => fn (array $a): ?string => ($case['deepLink'] ?? null),
					]),
					default => null,
				};
			},
		]);

		$autoTransitions = null;
		if (($case['autoTransitions'] ?? false) === true) {
			$autoTransitions = $this->recorder(AutoTransitionPass::class, 'autoTransitions', [
				'leave' => fn (array $a): array => [['applied' => 'close']],
				'preferAutomatic' => fn (array $a): ObjectEntity => $this->entity(uuid: 'auto-1', data: ['state' => 'closed']),
			]);
		}

		return new ObjectService(
			dataManipHandler: $this->recorder(DataManipulationHandler::class, 'dataManip', []),
			deleteHandler: $this->recorder(DeleteObject::class, 'deleteHandler', []),
			getHandler: $this->recorder(GetObject::class, 'getHandler', []),
			permissionHandler: $this->recorder(PermissionHandler::class, 'permissions', []),
			renderHandler: $this->recorder(RenderObject::class, 'render', [
				'renderEntity' => function (array $a) use ($case): ObjectEntity {
					if (($case['renderThrows'] ?? false) === true) {
						throw new Exception('render broke');
					}

					return $a[0];
				},
			]),
			saveHandler: $this->recorder(SaveObject::class, 'saveHandler', [
				'applyAlwaysDefaults' => fn (array $a): array => $a[1] + ['always' => 1],
				'applyExpressionDefaults' => fn (array $a): array => $a[1] + ['expression' => 1],
				'seedLifecycleFieldOnCreate' => fn (array $a): array => $a[1] + ['seeded' => 1],
				'saveObject' => function (array $a) use ($case): ObjectEntity {
					if (($case['saveThrows'] ?? false) === true) {
						throw new Exception('database refused');
					}

					return $this->entity(uuid: (string)($a[3] ?? 'created-1'), data: $a[2]);
				},
			]),
			saveObjectsHandler: $this->recorder(SaveObjects::class, 'saveObjects', []),
			searchQueryHandler: $this->recorder(SearchQueryHandler::class, 'searchQuery', []),
			validateHandler: $this->recorder(ValidateObject::class, 'validator', [
				'validateObject' => function (array $a) use ($case): ValidationResult {
					$result = $this->createStub(ValidationResult::class);
					$result->method('isValid')->willReturn(($case['invalid'] ?? false) === false);
					return $result;
				},
				'generateErrorMessage' => fn (array $a): string => 'title is wrong',
				'validateReadOnlyConstraints' => fn (array $a): array => [],
				'validateImmutableConstraints' => fn (array $a): array => [],
			]),
			lockHandler: $this->recorder(LockHandler::class, 'lock', []),
			auditHandler: $this->recorder(AuditHandler::class, 'audit', []),
			relationHandler: $this->recorder(RelationHandler::class, 'relations', []),
			mergeHandler: $this->recorder(MergeHandler::class, 'merge', []),
			facetHandler: $this->recorder(FacetHandler::class, 'facets', []),
			metadataHandler: $this->recorder(MetadataHandler::class, 'metadata', []),
			perfOptHandler: $this->recorder(PerformanceOptimizationHandler::class, 'perf', []),
			queryHandler: $this->recorder(QueryHandler::class, 'query', []),
			revertHandler: $this->recorder(RevertHandler::class, 'revert', []),
			utilityHandler: $this->recorder(UtilityHandler::class, 'utility', []),
			validationHandler: $this->recorder(ValidationHandler::class, 'validation', []),
			cascadingHandler: $this->recorder(CascadingHandler::class, 'cascading', [
				'handlePreValidationCascading' => fn (array $a): array => [$a[0], ($case['cascadeUuid'] ?? ($a[2] ?? ($case['generateUuid'] ?? null)))],
			]),
			migrationHandler: $this->recorder(MigrationHandler::class, 'migration', []),
			registerMapper: $this->recorder(RegisterMapper::class, 'registerMapper', [
				'find' => fn (array $a): Register => $this->register(id: (int)$a[0]),
			]),
			schemaMapper: $this->recorder(SchemaMapper::class, 'schemaMapper', [
				'find' => function (array $a) use ($case): Schema {
					if ($a[0] === 'none') {
						throw new DoesNotExistException('no schema');
					}

					return $this->schema(case: $case, id: (int)$a[0]);
				},
				'findInIds' => fn (array $a): ?Schema => $a[0] === 'none' ? null : $this->schema(case: $case, id: (int)$a[0]),
			]),
			viewMapper: $this->recorder(ViewMapper::class, 'viewMapper', []),
			objectMapper: $this->recorder(MagicMapper::class, 'objectMapper', ['find' => $find]),
			fileService: $this->recorder(FileService::class, 'files', []),
			userSession: $this->recorder(IUserSession::class, 'session', []),
			searchTrailService: $this->recorder(SearchTrailService::class, 'searchTrail', []),
			groupManager: $this->recorder(IGroupManager::class, 'groups', []),
			userManager: $this->recorder(IUserManager::class, 'users', []),
			organisationService: $this->recorder(OrganisationService::class, 'organisations', []),
			logger: $this->recorder(LoggerInterface::class, 'logger', []),
			cacheHandler: $this->recorder(CacheHandler::class, 'cache', []),
			settingsService: $this->recorder(SettingsService::class, 'settings', []),
			dateTimeNormalizer: $this->recorder(DateTimeNormalizer::class, 'dates', [
				'normalize' => fn (array $a): ?DateTimeImmutable => is_string($a[0]) && str_contains($a[0], 'T') ? new DateTimeImmutable($a[0]) : null,
			]),
			container: $container,
			objectSourceRegistry: $this->recorder(ObjectSourceRegistry::class, 'objectSources', []),
			autoTransitions: $autoTransitions
		);
	}//end service()

	/**
	 * A mock whose every call is recorded; scripted methods answer through their callback.
	 *
	 * @param class-string                    $class
	 * @param array<string, callable(array): mixed> $script
	 */
	private function recorder(string $class, string $label, array $script): MockObject {
		$mock = $this->createMock($class);
		$test = $this;
		$mock->method($this->anything())->will(
			new class($test, $label, $script) implements Stub {
				public function __construct(private object $test, private string $label, private array $script) {
				}

				public function invoke(Invocation $invocation): mixed {
					$method = $invocation->methodName();
					$args = $invocation->parameters();
					$this->test->record($this->label . '.' . $method, $args);
					if (isset($this->script[$method]) === true) {
						return ($this->script[$method])($args);
					}

					return $invocation->generateReturnValue();
				}
			}
		);
		return $mock;
	}//end recorder()

	public function record(string $method, array $args): void {
		if (in_array($method, ['logger.debug', 'logger.info', 'logger.warning', 'logger.error'], true) === true && is_array($args[1] ?? null) === true) {
			unset($args[1]['file'], $args[1]['line']);
		}

		$this->calls[] = [$method, $this->describe($args)];
	}//end record()

	private function describe(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return ['entity' => $value->getUuid(), 'data' => $value->getObject()];
		}

		if ($value instanceof Schema) {
			return ['schema' => $value->getId()];
		}

		if ($value instanceof Register) {
			return ['register' => $value->getId()];
		}

		if ($value instanceof IUser) {
			return ['user' => $value->getUID()];
		}

		if (is_object($value) === true) {
			return ['object' => preg_replace('/^(MockObject|TestStub)_(\w+?)_[0-9a-f]{8}$/', '$2', get_class($value))];
		}

		if (is_string($value) === true) {
			return preg_replace('/(MockObject|TestStub)_(\w+?)_[0-9a-f]{8}/', '$2', $value);
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->describe($item), $value);
		}

		return $value;
	}//end describe()

	private function register(int $id = 4): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug('reg-' . $id);
		$register->setSchemas([9, 6]);
		return $register;
	}//end register()

	/**
	 * @param array<string, mixed> $case
	 */
	private function schema(array $case, int $id = 9): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('schema-' . $id);
		$properties = ['title' => ['type' => 'string'], 'when' => ['type' => 'string', 'format' => 'date']];
		if (($case['explicit'] ?? false) === true) {
			$properties['secret'] = ['type' => 'string', 'authorization' => [ExplicitReveal::KEY => ExplicitReveal::EXPLICIT]];
		}

		$schema->setProperties($properties);
		$schema->setHardValidation(($case['hardValidation'] ?? true));
		$schema->setAppendOnly(($case['appendOnly'] ?? false));
		$configuration = [];

		if (isset($case['schemaSource']) === true) {
			$configuration['x-openregister-object-source'] = $case['schemaSource'];
		}

		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	/**
	 * @param array<string, mixed> $data
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('4');
		$entity->setSchema('9');
		$entity->setOwner('owner');
		$entity->setCreated(new DateTime('2026-01-02T03:04:05+00:00'));
		$entity->setObject($data);
		return $entity;
	}//end entity()
}//end class
