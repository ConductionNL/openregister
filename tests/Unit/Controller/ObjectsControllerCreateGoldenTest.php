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
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Exception\TranslationTargetConflictException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\Object\UpsertOnKeyException;
use OCA\OpenRegister\Service\Object\UpsertOnKeyHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\DbalWriteException;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultException;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
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
 * Golden test for POST /api/objects/{register}/{schema}: what reaches the save, and every refusal it can answer.
 *
 * Set UPDATE_GOLDEN=1 to rewrite fixtures/objects-create-golden.json.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses   \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @covers \OCA\OpenRegister\Controller\Support\ObjectCreate
 * @covers \OCA\OpenRegister\Controller\Support\ObjectCreateControls
 * @covers \OCA\OpenRegister\Controller\Support\WriteCaller
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
class ObjectsControllerCreateGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-create-golden.json';

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
			'json strings decode' => [['list' => '["a"]', 'map' => '{"a":1}', 'text' => 'plain'], []],
			'multipart decodes' => [['list' => '["a"]', 'text' => 'plain'], ['contentType' => 'multipart/form-data; boundary=x']],
			'insert only' => [['title' => 'x', '_failIfExists' => 'true'], []],
			'duplicate override' => [['title' => 'x', '_dedupOverride' => '1'], []],
			'blank upsert key' => [['title' => 'x', '_upsertOn' => '   '], []],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function failureCases(): array {
		return [
			'upsert refusal' => ['upsertRefusal'],
			'translation conflict' => ['translation'],
			'validation' => ['validation'],
			'custom validation' => ['customValidation'],
			'hook stopped' => ['hook'],
			'locked' => ['locked'],
			'folder denied' => ['folder'],
			'expression default' => ['expression'],
			'object exists' => ['exists'],
			'duplicate blocked' => ['duplicate'],
			'append only' => ['appendOnly'],
			'external write' => ['dbal'],
			'object state' => ['state'],
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
		$this->calls = [];
		$this->compareAnswer('schema not found', [], ['throw' => 'schema']);
	}

	public function testAWebhookRewritesTheBodyAndAFailureFallsBack(): void {
		$this->compareAnswer('webhook rewrites', ['title' => 'x'], ['webhook' => ['title' => 'rewritten']]);
		$this->calls = [];
		$this->compareAnswer('webhook fails', ['title' => 'x'], ['webhook' => 'fails']);
	}

	public function testUpsertAnswers(): void {
		foreach (['anonymous' => ['anonymous' => true], 'no handler' => [], 'updated' => ['upsert' => false], 'created' => ['upsert' => true]] as $name => $setup) {
			$this->calls = [];
			$this->compareAnswer('upsert ' . $name, ['title' => 'x', '_upsertOn' => 'code'], $setup);
		}

		$this->calls = [];
		$this->compareAnswer('upsert and insert only', ['title' => 'x', '_upsertOn' => 'code', '_failIfExists' => 'true'], []);
	}

	private function compareAnswer(string $key, array $params, array $setup): void {
		$response = $this->controller($params, $setup)->create('reg', 'sch', $this->routeService($setup));

		$this->compare($key, ['status' => $response->getStatus(), 'headers' => array_diff_key($response->getHeaders(), ['X-Robots-Tag' => 1, 'Cache-Control' => 1, 'Content-Security-Policy' => 1, 'Feature-Policy' => 1, 'X-Request-Id' => 1]), 'data' => $response->getData(), 'calls' => $this->calls]);
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

			$object = new ObjectEntity();
			$object->setUuid('new-uuid');
			return $object;
		});
		return $service;
	}

	private function controller(array $params, array $setup): ObjectsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturn(($setup['contentType'] ?? ''));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message): void {
			$this->calls[] = ['error' => $message];
		});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($setup['anonymous'] ?? false) ? null : $user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn((($setup['admin'] ?? false) === true) ? ['admin'] : ['staff']);

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectService::class),
			$session,
			$groups,
			$this->createMock(ObjectExporterInterface::class),
			$this->createMock(ObjectImporterInterface::class),
			$this->webhooks($setup),
			$logger,
			upsertOnKeyHandler: $this->upsertHandler($setup)
		);
	}

	private function webhooks(array $setup): ?WebhookService {
		if (isset($setup['webhook']) === false) {
			return null;
		}

		$service = $this->createMock(WebhookService::class);
		if ($setup['webhook'] === 'fails') {
			$service->method('interceptRequest')->willThrowException(new RuntimeException('no hook'));
			return $service;
		}

		$service->method('interceptRequest')->willReturn($setup['webhook']);
		return $service;
	}

	private function upsertHandler(array $setup): ?UpsertOnKeyHandler {
		if (isset($setup['upsert']) === false) {
			return null;
		}

		$handler = $this->createMock(UpsertOnKeyHandler::class);
		$handler->method('upsert')->willReturnCallback(function (...$args) use ($setup): array {
			$request = ($args['request'] ?? $args[0]);
			$this->calls[] = ['upsert' => [$request->constraintName, $request->object]];
			$object = new ObjectEntity();
			$object->setUuid('kept-uuid');
			return ['object' => $object, 'created' => $setup['upsert']];
		});
		return $handler;
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
