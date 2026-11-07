<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Exception\TranslationTargetConflictException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\UpsertOnKeyException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultException;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for PATCH and POST .../{id}/patch: the read before the write, the merge, the save, every refusal.
 *
 * Set UPDATE_GOLDEN=1 to rewrite fixtures/objects-patch-golden.json.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses   \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectTarget
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectUpdate
 * @uses   \OCA\OpenRegister\Controller\Support\WriteCaller
 * @uses   \OCA\OpenRegister\Controller\Support\StringTypedPatch
 * @uses   \OCA\OpenRegister\Controller\Support\ConflictTrail
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectVersionGuard
 * @uses   \OCA\OpenRegister\Controller\Support\SentWrite
 * @uses   \OCA\OpenRegister\Controller\Support\VersionClaim
 * @uses   \OCA\OpenRegister\Controller\Support\VersionMismatch
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectRequestParams
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\AppendOnlyException
 * @uses   \OCA\OpenRegister\Exception\CustomValidationException
 * @uses   \OCA\OpenRegister\Exception\DuplicateBlockedException
 * @uses   \OCA\OpenRegister\Exception\FolderAccessDeniedException
 * @uses   \OCA\OpenRegister\Exception\HookStoppedException
 * @uses   \OCA\OpenRegister\Exception\LockedException
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Exception\ObjectExistsException
 * @uses   \OCA\OpenRegister\Exception\ObjectStateWriteException
 * @uses   \OCA\OpenRegister\Exception\RegisterNotFoundException
 * @uses   \OCA\OpenRegister\Exception\SchemaNotFoundException
 * @uses   \OCA\OpenRegister\Exception\TranslationTargetConflictException
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 * @uses   \OCA\OpenRegister\Service\Object\UpsertOnKeyException
 * @uses   \OCA\OpenRegister\Service\ObjectSource\DbalWriteException
 * @uses   \OCA\OpenRegister\Service\Rules\ExpressionDefaultException
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 */
class ObjectsControllerPatchGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-patch-golden.json';

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var list<mixed> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function saveCases(): array {
		return [
			'plain body' => [['title' => 'x'], []],
			'admin' => [['title' => 'x'], ['admin' => true]],
			'reserved keys go' => [['title' => 'x', '_extend' => 'a', '@self' => ['slug' => 's', 'owner' => 'o'], '@type' => 't', 'uuid' => 'u', 'register' => 'r', 'schema' => 's'], []],
			'multipart decodes' => [['list' => '["a"]', 'text' => 'plain'], ['contentType' => 'multipart/form-data; boundary=x']],
			'releases own lock' => [['title' => 'x'], ['saved' => ['lockedBy' => 'anna']]],
			'keeps a lock held by another' => [['title' => 'x'], ['saved' => ['lockedBy' => 'bob']]],
			'stored uuid missing' => [['title' => 'x'], ['stored' => ['uuid' => null]]],
			'an id key goes on post only' => [['id' => 'n', 'title' => 'x'], []],
			'keeps what was not sent' => [['title' => 'x'], ['stored' => ['data' => ['title' => 'old', 'kept' => 1]]]],
			'string typed value' => [['title' => 'x'], ['converter' => true, 'stored' => ['data' => ['raw' => '{"a":1}']]]],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function failureCases(): array {
		return [
			'translation conflict' => ['translation'],
			'validation' => ['validation'],
			'custom validation' => ['customValidation'],
			'hook stopped' => ['hook'],
			'locked' => ['locked'],
			'folder denied' => ['folder'],
			'append only' => ['appendOnly'],
			'external write' => ['dbal'],
			'object state' => ['state'],
			'refused by the rules' => ['rules'],
			'anything else' => ['other'],
		];
	}

	#[DataProvider('saveCases')]
	public function testSaveMatchesTheRecording(array $params, array $setup): void {
		$this->compareAnswer('save ' . $this->dataName(), $params, $setup);
	}

	#[DataProvider('failureCases')]
	public function testFailureMatchesTheRecording(string $failure): void {
		$this->compareAnswer('failure ' . $this->dataName(), ['title' => 'x'], ['fails' => $failure]);
	}

	public function testUnknownRegisterAndSchemaAnswer404(): void {
		$this->compareAnswer('register not found', [], ['throw' => 'register']);
		$this->compareAnswer('schema not found', [], ['throw' => 'schema']);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function readCases(): array {
		return [
			'gone' => [['read' => 'gone']],
			'denied' => [['read' => 'denied']],
			'ambiguous' => [['read' => 'ambiguous']],
			'read fails' => [['read' => 'fails']],
			'other register' => [['stored' => ['register' => 9]]],
			'other schema' => [['stored' => ['schema' => 9]]],
			'locked by another' => [['stored' => ['lockedBy' => 'bob']]],
			'own lock' => [['stored' => ['lockedBy' => 'anna']]],
		];
	}

	#[DataProvider('readCases')]
	public function testReadBeforeTheWriteMatchesTheRecording(array $setup): void {
		$this->compareAnswer('read ' . $this->dataName(), ['title' => 'x'], $setup);
	}

	public function testAStaleVersionIsRefused(): void {
		$this->compareRoute('patch stale version', 'patch', ['title' => 'x', '_expectedUpdated' => '2026-01-01T00:00:00+00:00'], []);
	}

	public function testPostPatchRefusesAStaleVersionBeforeItSaves(): void {
		$params = ['title' => 'x', '_expectedUpdated' => '2026-01-01T00:00:00+00:00'];
		$response = $this->controller($params, [])->postPatch('reg', 'sch', 'object-1', $this->routeService([]));

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('2026-01-01T00:00:00+00:00', $response->getData()['expectedUpdated']);
		$this->assertSame([], array_filter($this->calls, static fn ($call): bool => isset($call['saveObject'])));
	}

	private function compareAnswer(string $key, array $params, array $setup): void {
		foreach (['patch', 'postPatch'] as $route) {
			$this->calls = [];
			$this->compareRoute($route . ' ' . $key, $route, $params, $setup);
		}
	}

	private function compareRoute(string $key, string $route, array $params, array $setup): void {
		$response = $this->controller($params, $setup)->$route('reg', 'sch', 'object-1', $this->routeService($setup));

		$calls = $this->calls;
		$this->compare($key, ['status' => $response->getStatus(), 'headers' => array_diff_key($response->getHeaders(), ['X-Robots-Tag' => 1, 'Cache-Control' => 1, 'Content-Security-Policy' => 1, 'Feature-Policy' => 1, 'X-Request-Id' => 1]), 'data' => $response->getData(), 'calls' => $calls]);
	}

	private function failure(string $name): \Exception {
		return match ($name) {
			'upsertRefusal' => new UpsertOnKeyException(409, ['error' => 'taken'], ['Retry-After' => '1']),
			'translation' => new TranslationTargetConflictException('title', 'nl'),
			'validation' => new ValidationException('bad'),
			'customValidation' => new CustomValidationException('bad', ['a' => 'b']),
			'hook' => new HookStoppedException('stopped', ['e' => 1]),
			'locked' => new LockedException('held'),
			'folder' => new FolderAccessDeniedException('f1'),
			'expression' => new ExpressionDefaultException('title', 'cannot derive'),
			'exists' => new ObjectExistsException('taken', 'u1'),
			'duplicate' => new DuplicateBlockedException('twin', ['m1']),
			'appendOnly' => new AppendOnlyException('sch', 'create'),
			'dbal' => new DbalWriteException('external', 418),
			'state' => new ObjectStateWriteException('frozen'),
			'rules' => new NotAuthorizedException('refused by a rule'),
			'other' => new RuntimeException('no'),
		};
	}

	private function routeService(array $setup): ObjectService {
		$register = new Register();
		$schema = new Schema();
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnCallback(function () use ($service, $setup) {
			if (($setup['throw'] ?? '') === 'register') {
				throw new RegisterNotFoundException('reg');
			}

			return $service;
		});
		$service->method('setSchema')->willReturnCallback(function () use ($service, $setup) {
			if (($setup['throw'] ?? '') === 'schema') {
				throw new SchemaNotFoundException('sch');
			}

			return $service;
		});
		$service->method('getRegister')->willReturn(3);
		$service->method('getSchema')->willReturn(7);
		$service->method('getCurrentRegisterEntity')->willReturn($register);
		$service->method('getCurrentSchemaEntity')->willReturn($schema);
		$service->method('clearCreatedSubObjects')->willReturnCallback(function (): void {
			$this->calls[] = 'clearCreatedSubObjects';
		});
		$service->method('handleValidationException')->willReturnCallback(fn (...$args) => new JSONResponse(['validation' => ($args['exception'] ?? $args[0])->getMessage()], 400));
		$service->method('saveObject')->willReturnCallback(function (...$args) use ($setup): ObjectEntity {
			$this->calls[] = ['saveObject' => $args];
			if (isset($setup['fails']) === true) {
				throw $this->failure($setup['fails']);
			}

			return $this->entity(($setup['saved'] ?? []));
		});
		return $service;
	}

	private function controller(array $params, array $setup): ObjectsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturn(($setup['contentType'] ?? ''));
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				unset($context['file'], $context['line'], $context['trace']);
				$this->calls[] = [$level => [$message, $context]];
			});
		}
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($setup['anonymous'] ?? false) ? null : $user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn((($setup['admin'] ?? false) === true) ? ['admin'] : ['staff']);

		$container = $this->createMock(ContainerInterface::class);
		$converter = $this->createMock(SchemaTypeConverter::class);
		$converter->method('restoreStringTypedValues')->willReturnCallback(fn (...$args) => ['restored' => $args]);
		$container->method('get')->willReturnCallback(fn (string $id) => match ($id) {
			'userId' => 'anna',
			SchemaTypeConverter::class => (($setup['converter'] ?? false) ? $converter : null),
			default => null,
		});

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->storeService($setup),
			$session,
			$groups,
			$this->createMock(ObjectExporterInterface::class),
			$this->createMock(ObjectImporterInterface::class),
			null,
			$logger
		);
	}

	private function entity(array $fields): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(array_key_exists('uuid', $fields) ? $fields['uuid'] : 'stored-uuid');
		$object->setRegister((string)($fields['register'] ?? 3));
		$object->setSchema((string)($fields['schema'] ?? 7));
		$object->setUpdated(new \DateTime('2026-05-05T10:00:00+00:00'));
		$object->setObject(($fields['data'] ?? []));
		if (isset($fields['lockedBy']) === true) {
			$object->setLocked(['user' => $fields['lockedBy'], 'expiration' => '2099-01-01T00:00:00+00:00']);
		}

		return $object;
	}

	private function storeService(array $setup): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('findSilent')->willReturnCallback(function (...$args) use ($setup): ObjectEntity {
			$this->calls[] = ['findSilent' => $args];
			return match ($setup['read'] ?? 'found') {
				'found' => $this->entity(($setup['stored'] ?? [])),
				'gone' => throw new DoesNotExistException('gone'),
				'denied' => throw new NotAuthorizedException('denied'),
				'ambiguous' => throw new MultipleObjectsReturnedException('two'),
				'fails' => throw new RuntimeException('secret detail'),
			};
		});
		$service->method('unlockObject')->willReturnCallback(function (...$args) {
			$this->calls[] = ['unlockObject' => $args];
			return true;
		});
		return $service;
	}

	private function compare(string $key, mixed $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded ??= (json_decode((string)@file_get_contents(self::FIXTURE), true) ?? []);
			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
