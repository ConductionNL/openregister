<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\ObjectsRouteScope;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Kernel\Contracts\ObjectExporterInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\SearchOptions;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RenderObjectInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden test for GET /api/objects: which branch a request takes and what each branch asks and answers.
 *
 * Set UPDATE_GOLDEN=1 to rewrite fixtures/objects-global-list-golden.json.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 * @uses   \OCA\OpenRegister\Controller\Support\EmptyValueStripper
 * @uses   \OCA\OpenRegister\Controller\Support\GeoServices
 * @uses   \OCA\OpenRegister\Controller\Support\LinkedDataServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectGovernanceServices
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectRequestParams
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectStore
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectTransfer
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsControllerServices
 * @covers \OCA\OpenRegister\Controller\Support\MagicMappedTable
 * @covers \OCA\OpenRegister\Controller\Support\ObjectsRouteScope
 * @covers \OCA\OpenRegister\Controller\Support\ObjectsTableRead
 * @uses   \OCA\OpenRegister\Controller\Support\ObjectsExtensions
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Exception\RegisterNotFoundException
 * @uses   \OCA\OpenRegister\Exception\SchemaNotFoundException
 */
class ObjectsControllerGlobalListGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-global-list-golden.json';

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
		$pair = ['register' => 'reg', 'schema' => 'sch'];
		$magic = ['enableMagicMapping' => true, 'magicMappingSchemas' => ['7']];
		return [
			'no scope' => [[], []],
			'register only' => [['register' => 'reg'], []],
			'schema only' => [['schema' => 'sch'], []],
			'pair, no magic' => [$pair, []],
			'pair in underscores' => [['_register' => 'reg', '_schema' => 'sch'], []],
			'plain beats underscore' => [['register' => 'reg', '_register' => 'other', 'schema' => 'sch'], []],
			'one schema in the list' => [['register' => 'reg', 'schemas' => ['sch']], []],
			'one register in the list' => [['registers' => 'reg', 'schema' => 'sch'], []],
			'entities missing' => [$pair, ['noEntities' => true]],
			'magic off' => [$pair, ['config' => ['enableMagicMapping' => false, 'magicMappingSchemas' => ['7']]]],
			'magic not true' => [$pair, ['config' => ['enableMagicMapping' => 1, 'magicMappingSchemas' => ['7']]]],
			'magic other schema' => [$pair, ['config' => ['enableMagicMapping' => true, 'magicMappingSchemas' => ['8']]]],
			'magic by id' => [$pair, ['config' => $magic, 'query' => ['_limit' => 2, '_offset' => 2]]],
			'magic by slug' => [$pair, ['config' => ['enableMagicMapping' => true, 'magicMappingSchemas' => ['slug-7']]]],
			'magic defaults' => [$pair, ['config' => $magic]],
			'magic zero limit' => [$pair, ['config' => $magic, 'query' => ['_limit' => 0]]],
			'magic limit one' => [$pair, ['config' => $magic, 'query' => ['_limit' => 1, '_offset' => 2]]],
			'magic no rows' => [$pair, ['config' => $magic, 'rows' => 0, 'query' => ['_limit' => 10]]],
			'magic keeps empties' => [$pair + ['_empty' => 'true'], ['config' => $magic]],
			'magic rbac off' => [$pair, ['config' => $magic, 'query' => ['_rbac' => false]]],
			'register not found' => [$pair, ['throw' => 'register']],
			'schema not found' => [$pair, ['throw' => 'schema']],
			'search refused' => [$pair, ['refuse' => true]],
			'keeps empties' => [$pair + ['_empty' => '1'], []],
			'results not a list' => [[], ['result' => ['results' => 'x', 'total' => 0]]],
			'no results key' => [[], ['result' => ['total' => 0]]],
		];
	}

	#[DataProvider('cases')]
	public function testBranchMatchesTheRecording(array $params, array $setup): void {
		$response = $this->controller($params, $setup)->objects($this->objectService($setup));

		$this->compare($this->dataName(), ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls]);
	}

	public function testScopeReadsListsAndSingles(): void {
		$single = new ObjectsRouteScope(params: ['register' => 'r', 'schema' => 's']);
		$this->assertSame(['r'], $single->registers);
		$this->assertSame(['s'], $single->schemas);
		$this->assertFalse($single->spansTables());
		$this->assertTrue((new ObjectsRouteScope(params: ['registers' => 'a,b']))->spansTables());
		$this->assertTrue((new ObjectsRouteScope(params: ['schemas' => 'a,b']))->spansTables());
		$this->assertSame([], (new ObjectsRouteScope(params: []))->registers);
	}

	private function objectService(array $setup): ObjectService {
		$register = new Register();
		$register->setConfiguration(($setup['config'] ?? []));
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('slug-7');

		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnCallback(function (...$args) use ($service, $setup) {
			$this->calls[] = ['setRegister' => $args];
			if (($setup['throw'] ?? '') === 'register') {
				throw new RegisterNotFoundException('reg');
			}

			return $service;
		});
		$service->method('setSchema')->willReturnCallback(function (...$args) use ($service, $setup) {
			$this->calls[] = ['setSchema' => $args];
			if (($setup['throw'] ?? '') === 'schema') {
				throw new SchemaNotFoundException('sch');
			}

			return $service;
		});
		$service->method('getRegister')->willReturn(3);
		$service->method('getSchema')->willReturn(7);
		$service->method('getCurrentRegisterEntity')->willReturn(($setup['noEntities'] ?? false) ? null : $register);
		$service->method('getCurrentSchemaEntity')->willReturn(($setup['noEntities'] ?? false) ? null : $schema);
		$service->method('buildSearchQuery')->willReturnCallback(function (...$args) use ($setup): array {
			$this->calls[] = ['buildSearchQuery' => $args];
			return ($setup['query'] ?? ['built' => true]);
		});
		$service->method('searchObjectsPaginated')->willReturnCallback(function (array $query = [], bool $_rbac = true, bool $_multitenancy = true, SearchOptions $options = new SearchOptions()) use ($setup): array {
			$args = [$query, $_rbac, $_multitenancy, $options->deleted, $options->selection->ids, $options->selection->uses, $options->views];
			$this->calls[] = ['searchObjectsPaginated' => $args];
			if (($setup['refuse'] ?? false) === true) {
				throw new NotAuthorizedException('no');
			}

			return ($setup['result'] ?? ['results' => [['a' => 1, 'b' => null, 'c' => '']], 'total' => 1]);
		});
		return $service;
	}

	private function controller(array $params, array $setup): ObjectsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->container($setup),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ObjectExporterInterface::class),
			$this->createMock(ObjectImporterInterface::class)
		);
	}

	private function container(array $setup): ContainerInterface {
		$rows = [];
		for ($i = 0; $i < ($setup['rows'] ?? 3); $i++) {
			$row = $this->createMock(ObjectEntity::class);
			$row->method('jsonSerialize')->willReturn(['n' => $i, 'gone' => null]);
			$rows[] = $row;
		}

		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(function (array $query, Register $register, Schema $schema) use ($rows): array {
			$this->calls[] = ['magicSearch' => $query, 'schema' => $schema->getId()];
			return $rows;
		});

		$render = $this->createMock(RenderObject::class);
		foreach (['redactWriteOnlyFromRows', 'applyLensesToRows', 'resolveTranslationsForRows', 'attachLightweightFilesToRows'] as $step) {
			$render->method($step)->willReturnCallback(function (array &$rows, ...$rest) use ($step): void {
				$this->calls[] = [$step => [count($rows), $rest]];
			});
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => match ($id) {
			MagicMapperInterface::class => $mapper,
			RenderObjectInterface::class => $render,
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
