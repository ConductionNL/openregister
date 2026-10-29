<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\AuthorizationBlockException;
use OCA\OpenRegister\Exception\BreakingSchemaChangeException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Exception\InvalidAuthorizationRuleException;
use OCA\OpenRegister\Exception\UniqueHintException;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\BulkJob\ReversibilityDeclarationException;
use OCA\OpenRegister\Service\Calculation\CalculationDeclarationException;
use OCA\OpenRegister\Service\Consent\ConsentDeclarationException;
use OCA\OpenRegister\Service\Decision\ReviewDeclarationException;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Relation\RelationDeclarationException;
use OCA\OpenRegister\Service\Rules\DependentValueDeclarationException;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DBException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the schema write endpoints: create(), update() (applyUpdate())
 * and upload().
 *
 * Every case records each collaborator call (method and arguments, log lines
 * included) and the outcome, and compares the recording with
 * fixtures/schemas-write-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class SchemasControllerWriteGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/schemas-write-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/**
	 * The exceptions a schema write can throw, by label.
	 *
	 * @return array<string, Exception>
	 */
	private static function writeErrors(): array {
		$errors = [['code' => 'x', 'message' => 'bad value']];
		return [
			'unique hint' => new UniqueHintException('Unique hint names nothing', $errors),
			'property vocabulary' => new PropertyVocabularyException('Unknown type', $errors),
			'authorization block' => new AuthorizationBlockException('Deny contradicts grant'),
			'reversibility' => new ReversibilityDeclarationException($errors),
			'review' => new ReviewDeclarationException($errors),
			'relation' => new RelationDeclarationException($errors),
			'calculation' => new CalculationDeclarationException($errors),
			'consent' => new ConsentDeclarationException($errors),
			'dependent value' => new DependentValueDeclarationException($errors),
			'invalid authorization rule' => new InvalidAuthorizationRuleException('Rule read.x has no operator'),
			'db exception' => new DBException('Duplicate entry for key unique_slug'),
			'database constraint' => new DatabaseConstraintException('Slug taken', 0, 418),
			'message invalid' => new Exception('Invalid property type'),
			'message must be' => new Exception('Title must be a string'),
			'message required' => new Exception('Field required'),
			'message translatable' => new Exception('Property requires translatable flag'),
			'message format' => new Exception('Unknown format'),
			'message property at' => new Exception('Property at /a is wrong'),
			'message authorization' => new Exception('Bad authorization'),
			'message handoff' => new Exception('Broken handoff'),
			'message constraint' => new Exception('Violates constraint'),
			'message duplicate' => new Exception('A duplicate row'),
			'message unique' => new Exception('Not unique'),
			'message other' => new Exception('Something else broke'),
			'message other with code' => new \RuntimeException('Gone', 404),
		];
	}//end writeErrors()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$props = ['properties' => ['name' => ['type' => 'string']], 'required' => ['name']];
		$cases = [
			// create().
			'create as member' => ['call' => 'create', 'user' => 'member'],
			'create plain' => ['call' => 'create', 'params' => ['title' => 'T', '_route' => 'x', 'id' => 5, 'organisation' => 'org']],
			'create without title' => ['call' => 'create', 'params' => ['id' => null]],
			'create unknown register' => ['call' => 'create', 'params' => ['title' => 'T', 'register' => 'nope']],
			'create empty register' => ['call' => 'create', 'params' => ['title' => 'T', 'register' => '']],
			'create links register' => ['call' => 'create', 'params' => ['title' => 'T', 'register' => 'reg']],
			'create register already linked' => ['call' => 'create', 'params' => ['title' => 'T', 'register' => 'reg'], 'registerSchemas' => [77]],
			'create link fails' => ['call' => 'create', 'params' => ['title' => 'T', 'register' => 'reg'], 'linkThrows' => true],
			'create bad semantic role' => [
				'call' => 'create',
				'params' => ['title' => 'T', 'properties' => [
					'a' => ['type' => 'string', 'x-openregister-role' => 'title'],
					'b' => ['type' => 'string', 'x-openregister-role' => 'title'],
				]],
			],
			// update() / applyUpdate().
			'update missing schema' => ['call' => 'update', 'schema' => 'missing'],
			'update as member without manage rule' => ['call' => 'update', 'user' => 'member'],
			'update as member with manage rule' => ['call' => 'update', 'user' => 'member', 'manage' => ['editors'], 'params' => ['title' => 'New']],
			'update as member with manage object rule' => ['call' => 'update', 'user' => 'member', 'manage' => [['group' => 'editors']], 'params' => ['title' => 'New']],
			'update anonymous' => ['call' => 'update', 'user' => 'none'],
			'update strips immutable fields' => [
				'call' => 'update',
				'params' => ['title' => 'New', 'id' => 9, 'organisation' => 'o', 'owner' => 'u', 'created' => 'now', '_x' => 1, 'renames' => ['a' => 'b'], 'acknowledgeBreaking' => true],
			],
			'update as draft' => ['call' => 'update', 'params' => ['title' => 'Draft', 'draft' => 'true']],
			'update definition, no changes' => ['call' => 'update', 'params' => $props, 'changes' => false],
			'update definition bumps version' => ['call' => 'update', 'params' => $props, 'changes' => true, 'query' => ['renames' => ['old' => 'name']]],
			'update definition keeps pinned version' => ['call' => 'update', 'params' => $props + ['version' => '9.9.9'], 'changes' => true],
			'update only required' => ['call' => 'update', 'params' => ['required' => ['name']], 'changes' => true, 'query' => ['renames' => 'not-a-list']],
			'update acknowledged bool' => ['call' => 'update', 'params' => $props, 'changes' => true, 'query' => ['acknowledgeBreaking' => true]],
			'update acknowledged string' => ['call' => 'update', 'params' => $props, 'changes' => true, 'query' => ['acknowledgeBreaking' => 'true']],
			'update acknowledged other' => ['call' => 'update', 'params' => $props, 'changes' => true, 'query' => ['acknowledgeBreaking' => '1']],
			'update breaking refused' => ['call' => 'update', 'params' => $props, 'changes' => true, 'gate' => 'breaking'],
			'update external grant without end' => [
				'call' => 'update',
				'params' => ['authorization' => ['read' => [['group' => 'partners', 'external' => true]]]],
			],
			'update authorization not a list' => ['call' => 'update', 'params' => ['authorization' => 'admin']],
			'update authorization audited' => ['call' => 'update', 'params' => ['authorization' => ['read' => ['staff']]], 'oldAuthorization' => ['read' => ['all']]],
			'update authorization audit fails' => ['call' => 'update', 'params' => ['authorization' => ['read' => ['staff']]], 'auditThrows' => true],
			'update forms refused' => ['call' => 'update', 'params' => $props, 'changes' => true, 'forms' => 'refused'],
			'update forms affected' => ['call' => 'update', 'params' => $props, 'changes' => true, 'forms' => 'affected'],
			'update forms unaffected' => ['call' => 'update', 'params' => $props, 'changes' => false, 'forms' => 'clean'],
			'update forms check missing' => ['call' => 'update', 'params' => $props, 'changes' => false, 'forms' => 'missing'],
			'update forms check throws' => ['call' => 'update', 'params' => $props, 'changes' => false, 'forms' => 'throws'],
			'update bad semantic role' => [
				'call' => 'update',
				'params' => ['properties' => [
					'a' => ['type' => 'string', 'x-openregister-role' => 'title'],
					'b' => ['type' => 'string', 'x-openregister-role' => 'title'],
				]],
			],
			// upload().
			'upload new as member' => ['call' => 'upload', 'user' => 'member'],
			'upload existing as member' => ['call' => 'upload', 'id' => 3, 'user' => 'member'],
			'upload existing missing' => ['call' => 'upload', 'id' => 3, 'schema' => 'missing'],
			'upload json error' => ['call' => 'upload', 'uploaded' => 'error'],
			'upload new without title' => ['call' => 'upload', 'uploaded' => ['description' => 'D']],
			'upload new with organisation' => ['call' => 'upload', 'uploaded' => ['title' => 'U', 'organisation' => 'org-1']],
			'upload new, organisation from service' => ['call' => 'upload', 'uploaded' => ['title' => 'U']],
			'upload existing' => ['call' => 'upload', 'id' => 3, 'uploaded' => ['title' => 'Up']],
			'upload existing as member with manage rule' => ['call' => 'upload', 'id' => 3, 'user' => 'member', 'manage' => ['editors'], 'uploaded' => ['title' => 'Up']],
		];

		foreach (array_keys(self::writeErrors()) as $label) {
			$cases['create throws ' . $label] = ['call' => 'create', 'params' => ['title' => 'T'], 'writeThrows' => $label];
			$cases['update throws ' . $label] = ['call' => 'update', 'params' => ['title' => 'T'], 'writeThrows' => $label];
			$cases['upload new throws ' . $label] = ['call' => 'upload', 'uploaded' => ['title' => 'U'], 'writeThrows' => $label];
			$cases['upload existing throws ' . $label] = ['call' => 'upload', 'id' => 3, 'uploaded' => ['title' => 'U'], 'writeThrows' => $label];
		}

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testWriteMatchesGolden(array $case): void {
		$name = $this->dataName();
		$controller = $this->buildController(case: $case);

		try {
			$response = match ($case['call']) {
				'create' => $controller->create(),
				'update' => $controller->update($case['id'] ?? 3),
				'upload' => $controller->upload($case['id'] ?? null),
			};
			$outcome = ['status' => $response->getStatus(), 'data' => $this->describe(value: $response->getData())];
		} catch (\Throwable $e) {
			$outcome = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = json_decode(json_encode(['calls' => $this->calls] + $outcome), true);

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
	}//end testWriteMatchesGolden()

	/**
	 * @param array<string, mixed> $case
	 */
	private function buildController(array $case): SchemasController {
		$this->calls = [];
		$params = ($case['params'] ?? []);
		$query = ($case['query'] ?? []);
		$writeError = null;
		if (isset($case['writeThrows']) === true) {
			$writeError = self::writeErrors()[$case['writeThrows']];
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists($key, $query) ? $query[$key] : (array_key_exists($key, $params) ? $params[$key] : $default)
		);

		$userKind = ($case['user'] ?? 'admin');
		$user = null;
		if ($userKind !== 'none') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userKind);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid) => $uid === 'admin');
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $u) => $u->getUID() === 'admin' ? ['admin'] : ['editors']
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level) {
				unset($context['file'], $context['line'], $context['trace']);
				$this->record(method: 'logger.' . $level, args: [$message, $context]);
			});
		}

		$audit = $this->createMock(AuthorizationAuditService::class);
		$audit->method('logSchemaAuthorizationChange')->willReturnCallback(function () use ($case) {
			$this->record(method: 'audit.logSchemaAuthorizationChange', args: func_get_args());
			if (($case['auditThrows'] ?? false) === true) {
				throw new Exception('Audit store down');
			}
		});

		$formCheck = $this->formCheck(case: $case);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($userSession, $groupManager, $audit, $formCheck, $case) {
			if ($id === IUserSession::class) {
				return $userSession;
			}

			if ($id === IGroupManager::class) {
				return $groupManager;
			}

			if ($id === AuthorizationAuditService::class) {
				return $audit;
			}

			if ($id === FormDependentsCheck::class) {
				$this->record(method: 'container.get', args: ['FormDependentsCheck']);
				if (($case['forms'] ?? null) === 'throws') {
					throw new Exception('No form check');
				}

				return $formCheck;
			}

			return null;
		});

		$schemaMapper = $this->schemaMapper(case: $case, writeError: $writeError);
		$registerMapper = $this->registerMapper(case: $case);

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('getOrganisationForNewEntity')->willReturnCallback(function () {
			$this->record(method: 'organisation.getOrganisationForNewEntity', args: []);
			return 'org-active';
		});

		$schemaCache = $this->createMock(SchemaCacheHandler::class);
		foreach (['invalidate', 'invalidateForSchemaChange'] as $method) {
			$schemaCache->method($method)->willReturnCallback(function () use ($method) {
				$this->record(method: 'schemaCache.' . $method, args: func_get_args());
			});
		}

		$facetCache = $this->createMock(FacetCacheHandler::class);
		$facetCache->method('invalidateForSchemaChange')->willReturnCallback(function () {
			$this->record(method: 'facetCache.invalidateForSchemaChange', args: func_get_args());
		});

		$uploadService = $this->createMock(UploadService::class);
		$uploadService->method('getUploadedJson')->willReturnCallback(function () use ($case) {
			$this->record(method: 'upload.getUploadedJson', args: func_get_args());
			$uploaded = ($case['uploaded'] ?? ['title' => 'Uploaded']);
			if ($uploaded === 'error') {
				return new JSONResponse(data: ['error' => 'No JSON'], statusCode: 400);
			}

			return $uploaded;
		});

		return new SchemasController(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$schemaMapper,
			$registerMapper,
			$this->createMock(MagicMapper::class),
			$uploadService,
			$this->createMock(AuditTrailMapper::class),
			$organisationService,
			$schemaCache,
			$facetCache,
			$this->createMock(SchemaService::class),
			$logger,
			$container,
			$this->versioning(case: $case)
		);
	}//end buildController()

	/**
	 * @param array<string, mixed> $case
	 */
	private function schemaMapper(array $case, ?Exception $writeError): SchemaMapper {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(function ($id) use ($case) {
			$this->record(method: 'schemaMapper.find', args: func_get_args());
			if (($case['schema'] ?? 'exists') === 'missing') {
				throw new DoesNotExistException('Schema not found');
			}

			return $this->schema(id: (int)$id, case: $case);
		});
		$mapper->method('createFromArray')->willReturnCallback(function (array $object) use ($writeError) {
			$this->record(method: 'schemaMapper.createFromArray', args: func_get_args());
			if ($writeError !== null) {
				throw $writeError;
			}

			$schema = $this->schema(id: 77, case: []);
			$schema->setTitle($object['title'] ?? null);
			return $schema;
		});
		$mapper->method('updateFromArray')->willReturnCallback(function (int $id, array $object) use ($case, $writeError) {
			$this->record(method: 'schemaMapper.updateFromArray', args: func_get_args());
			if ($writeError !== null) {
				throw $writeError;
			}

			$schema = $this->schema(id: $id, case: $case);
			$schema->setTitle($object['title'] ?? 'Stored');
			$schema->setVersion($object['version'] ?? '1.0.0');
			if (isset($object['authorization']) === true && is_array($object['authorization']) === true) {
				$schema->setAuthorization($object['authorization']);
			}

			return $schema;
		});
		$mapper->method('insert')->willReturnCallback(function (Schema $schema) use ($writeError) {
			$this->record(method: 'schemaMapper.insert', args: func_get_args());
			if ($writeError !== null) {
				throw $writeError;
			}

			$schema->setId(88);
			return $schema;
		});
		$mapper->method('update')->willReturnCallback(function (Schema $schema) use ($writeError) {
			$this->record(method: 'schemaMapper.update', args: func_get_args());
			if ($writeError !== null) {
				throw $writeError;
			}

			return $schema;
		});
		return $mapper;
	}//end schemaMapper()

	/**
	 * @param array<string, mixed> $case
	 */
	private function registerMapper(array $case): RegisterMapper {
		$register = new Register();
		$register->setId(12);
		$register->setTitle('Register 12');
		$register->setSchemas($case['registerSchemas'] ?? [1]);

		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willReturnCallback(function ($id) use ($register) {
			$this->record(method: 'registerMapper.find', args: func_get_args());
			if ($id === 'nope') {
				throw new DoesNotExistException('No register');
			}

			return $register;
		});
		$mapper->method('update')->willReturnCallback(function ($entity) use ($case) {
			$this->record(method: 'registerMapper.update', args: func_get_args());
			if (($case['linkThrows'] ?? false) === true) {
				throw new Exception('Register belongs to another organisation');
			}

			return $entity;
		});
		$mapper->method('clearFindCache')->willReturnCallback(function () {
			$this->record(method: 'registerMapper.clearFindCache', args: func_get_args());
		});

		return $mapper;
	}//end registerMapper()

	/**
	 * @param array<string, mixed> $case
	 */
	private function versioning(array $case): SchemaVersioningService {
		$changes = [];
		if (($case['changes'] ?? false) === true) {
			$changes = [['property' => 'name', 'kind' => 'added']];
		}

		$changeSet = new SchemaChangeSet($changes, 'minor', 'minor');

		$versioning = $this->createMock(SchemaVersioningService::class);
		$versioning->method('classify')->willReturnCallback(function () use ($changeSet) {
			$this->record(method: 'versioning.classify', args: func_get_args());
			return $changeSet;
		});
		$versioning->method('enforceGate')->willReturnCallback(function () use ($case) {
			$this->record(method: 'versioning.enforceGate', args: func_get_args());
			if (($case['gate'] ?? null) === 'breaking') {
				throw new BreakingSchemaChangeException([['property' => 'name', 'kind' => 'removed']], 4);
			}
		});
		$versioning->method('nextVersion')->willReturnCallback(function () {
			$this->record(method: 'versioning.nextVersion', args: func_get_args());
			return '2.0.0';
		});
		$versioning->method('recordChangelog')->willReturnCallback(function () {
			$this->record(method: 'versioning.recordChangelog', args: func_get_args());
		});

		return $versioning;
	}//end versioning()

	/**
	 * @param array<string, mixed> $case
	 */
	private function formCheck(array $case): ?FormDependentsCheck {
		$mode = ($case['forms'] ?? 'clean');
		if ($mode === 'missing') {
			return null;
		}

		$check = $this->createMock(FormDependentsCheck::class);
		$check->method('assess')->willReturnCallback(function () use ($mode) {
			$this->record(method: 'forms.assess', args: func_get_args());
			return [
				'refused' => $mode === 'refused',
				'affected' => in_array($mode, ['refused', 'affected'], true) ? [['form' => 'f1']] : [],
			];
		});
		$check->method('claim')->willReturnCallback(function () {
			$this->record(method: 'forms.claim', args: func_get_args());
		});
		$check->method('apply')->willReturnCallback(function () {
			$this->record(method: 'forms.apply', args: func_get_args());
			return [['form' => 'f1', 'unpublished' => true]];
		});

		return $check;
	}//end formCheck()

	/**
	 * @param array<string, mixed> $case
	 */
	private function schema(int $id, array $case): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setUuid('schema-uuid-' . $id);
		$schema->setTitle('Schema ' . $id);
		$schema->setProperties(['old' => ['type' => 'string']]);
		$schema->setRequired([]);
		if (isset($case['manage']) === true) {
			$schema->setAuthorization(['manage' => $case['manage']]);
		}

		if (isset($case['oldAuthorization']) === true) {
			$schema->setAuthorization($case['oldAuthorization']);
		}

		return $schema;
	}//end schema()

	/**
	 * @param list<mixed> $args
	 */
	private function record(string $method, array $args): void {
		$this->calls[] = ['method' => $method, 'args' => array_map(fn ($arg) => $this->describe(value: $arg), $args)];
	}//end record()

	private function describe(mixed $value): mixed {
		if ($value instanceof Schema) {
			return ['schema' => $this->describe(value: $value->jsonSerialize())];
		}

		if ($value instanceof Register) {
			return ['register' => $value->getId(), 'schemas' => $value->getSchemas()];
		}

		if ($value instanceof \JsonSerializable) {
			return $this->describe(value: $value->jsonSerialize());
		}

		if ($value instanceof \DateTimeInterface) {
			return '<datetime>';
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->describe(value: $item), $value);
		}

		if (is_object($value) === true) {
			return ['object' => preg_replace('/^MockObject_(\w+?)_[0-9a-f]{8}$/', '$1', get_class($value))];
		}

		if (is_string($value) === true) {
			return preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '<uuid>', $value);
		}

		return $value;
	}//end describe()
}//end class
