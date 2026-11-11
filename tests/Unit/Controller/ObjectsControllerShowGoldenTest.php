<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\ObjectShow;
use OCA\OpenRegister\Controller\Support\ShowServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\RenderSelection;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\ReadStateServiceInterface;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
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
 * Golden test for GET /api/objects/{register}/{schema}/{id}: refusals, render arguments and the @self extras.
 *
 * Set UPDATE_GOLDEN=1 to rewrite fixtures/objects-show-golden.json.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Controller\Support\EmptyValueStripper
 * @uses   \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses   \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @covers \OCA\OpenRegister\Controller\Support\ObjectReadOptions
 * @covers \OCA\OpenRegister\Controller\Support\ObjectShow
 * @covers \OCA\OpenRegister\Controller\Support\ShowServices
 * @covers \OCA\OpenRegister\Controller\Support\ShownEntity
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectRequestParams
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 * @uses   \OCA\OpenRegister\Controller\Support\RenderedObjectUuids
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Exception\RegisterNotFoundException
 * @uses   \OCA\OpenRegister\Exception\SchemaNotFoundException
 * @uses   \OCA\OpenRegister\Service\Audit\PurposeRefusedException
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 */
class ObjectsControllerShowGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-show-golden.json';

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var list<mixed> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'plain read' => [[], []],
			'admin read' => [[], ['admin' => true]],
			'keeps empties' => [['_empty' => 'true'], []],
			'register not found' => [[], ['throw' => 'register']],
			'schema not found' => [[], ['throw' => 'schema']],
			'absent object' => [[], ['find' => 'null']],
			'does not exist' => [[], ['find' => 'gone']],
			'not authorized' => [[], ['find' => 'denied']],
			'ambiguous' => [[], ['find' => 'ambiguous']],
			'denied at render' => [[], ['render' => 'denied']],
			'strings become lists' => [['_fields' => 'a,b', '_filter' => 'c,d', '_unset' => 'e,f'], []],
			'plain names win' => [['fields' => 'x', '_fields' => 'y', 'filter' => 'x', '_filter' => 'y', 'unset' => 'x', '_unset' => 'y'], []],
			'lists stay lists' => [['_fields' => ['a'], '_filter' => ['b'], '_unset' => ['c']], []],
			'extend string' => [['_extend' => 'owner,@self.schema'], []],
			'extend plain name' => [['extend' => ['owner']], []],
			'one register' => [['_extend' => ['_register']], []],
			'many registers' => [['_extend' => ['_registers']], []],
			'one schema' => [['_extend' => ['_schema']], []],
			'many schemas' => [['_extend' => ['_schemas']], []],
			'names' => [['_extend' => ['_names']], []],
			'no entities' => [['_extend' => ['_registers', '_schemas']], ['noEntities' => true]],
			'render without self' => [['_extend' => ['_registers']], ['render' => 'noSelf']],
			'reveal' => [['_reveal' => 'bsn'], []],
			'reveal container answers else' => [['_reveal' => 'bsn'], ['noReveal' => true]],
			'actions fail' => [[], ['actions' => 'fails']],
			'unread counts' => [[], ['unread' => ['notes' => 1]]],
		];
	}

	#[DataProvider('cases')]
	public function testReadMatchesTheRecording(array $params, array $setup): void {
		$response = $this->controller($params, $setup)->show('object-1', 'reg', 'sch', $this->routeService($setup));

		$this->compare($this->dataName(), ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls]);
	}

	public function testAPurposeRefusalStopsTheReadBeforeTheObjectIsFetched(): void {
		$guard = $this->createMock(PurposeGuard::class);
		$guard->method('enforce')->willThrowException(new PurposeRefusedException(rule: 'doelbinding', reason: 'No purpose.', purpose: null));
		$response = $this->controller([], [], $guard)->show('object-1', 'reg', 'sch', $this->routeService([]));

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->calls);
	}

	public function testHasEntitiesNeedsBoth(): void {
		$service = new ShowServices($this->createMock(ObjectService::class), $this->createMock(PermissionHandler::class), $this->createMock(CacheHandlerInterface::class));
		$both = ['registerEntity' => new Register(), 'schemaEntity' => new Schema()];
		$this->assertTrue((new ObjectShow('1', ['r', 's'], $both, $service))->hasEntities());
		$this->assertFalse((new ObjectShow('1', ['r', 's'], ['schemaEntity' => null] + $both, $service))->hasEntities());
		$this->assertFalse((new ObjectShow('1', ['r', 's'], ['registerEntity' => null] + $both, $service))->hasEntities());
	}

	private function routeService(array $setup): ObjectService {
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(7);

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
		$service->method('getCurrentRegisterEntity')->willReturn(($setup['noEntities'] ?? false) ? null : $register);
		$service->method('getCurrentSchemaEntity')->willReturn(($setup['noEntities'] ?? false) ? null : $schema);
		$service->method('getExtendedObjects')->willReturn(['u1' => ['id' => 'u1']]);
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->method('getMultipleObjectNames')->willReturnCallback(function (array $ids): array {
			$this->calls[] = ['names' => $ids];
			return ['11111111-1111-4111-8111-111111111111' => 'Name'];
		});

		$service->method('getCacheHandler')->willReturn($cache);
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('permittedActionsFor')->willReturnCallback(function () use ($setup): array {
			if (($setup['actions'] ?? '') === 'fails') {
				throw new RuntimeException('no actions');
			}

			return ['read'];
		});
		$service->method('getPermissionHandler')->willReturn($permissions);
		return $service;
	}

	private function storeService(array $setup): ObjectService {
		$entity = new ObjectEntity();
		$entity->setUuid('object-uuid');
		$service = $this->createMock(ObjectService::class);
		$service->method('find')->willReturnCallback(function (...$args) use ($entity, $setup) {
			$this->calls[] = ['find' => FindCallShape::find(args: $args)];
			return match ($setup['find'] ?? 'found') {
				'found' => $entity,
				'null' => null,
				'gone' => throw new DoesNotExistException('gone'),
				'denied' => throw new NotAuthorizedException('denied'),
				'ambiguous' => throw new MultipleObjectsReturnedException('two'),
			};
		});
		$service->method('renderEntity')->willReturnCallback(function (ObjectEntity $entity, ?RenderSelection $selection, bool $_rbac, bool $_multitenancy) use ($setup): array {
			$args = [1 => $selection?->extend, 2 => 0, 3 => $selection?->filter, 4 => $selection?->fields, 5 => $selection?->unset, 6 => $_rbac, 7 => $_multitenancy];
			$this->calls[] = ['render' => $args];
			if (($setup['render'] ?? '') === 'denied') {
				throw new NotAuthorizedException('denied');
			}

			if (($setup['render'] ?? '') === 'noSelf') {
				return ['a' => 1];
			}

			return ['a' => 1, 'gone' => null, 'rel' => '11111111-1111-4111-8111-111111111111', '@self' => ['id' => 'object-uuid']];
		});
		return $service;
	}

	private function controller(array $params, array $setup, ?PurposeGuard $purposeGuard = null): ObjectsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->calls[] = ['warning' => $message];
		});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn((($setup['admin'] ?? false) === true) ? ['admin'] : ['staff']);

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->container($setup),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->storeService($setup),
			$session,
			$groups,
			$this->createMock(ObjectExporterInterface::class),
			$this->createMock(ObjectImporterInterface::class),
			null,
			$logger,
			purposeGuard: $purposeGuard
		);
	}

	private function container(array $setup): ContainerInterface {
		$reveal = $this->createMock(ExplicitReveal::class);
		$reveal->method('grantFor')->willReturnCallback(function (...$args): void {
			$this->calls[] = ['grantFor' => $args];
		});
		$readState = $this->createMock(ReadStateService::class);
		$readState->method('callerUid')->willReturn('anna');
		$readState->method('unreadCounts')->willReturn(($setup['unread'] ?? []));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => match ($id) {
			ExplicitRevealInterface::class => (($setup['noReveal'] ?? false) ? new \stdClass() : $reveal),
			ReadStateServiceInterface::class => $readState,
		});
		return $container;
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
