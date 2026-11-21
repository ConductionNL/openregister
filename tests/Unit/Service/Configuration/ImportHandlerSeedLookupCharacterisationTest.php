<?php

/**
 * Characterisation of three lookups of ImportHandler.
 *
 * Pins computeRegisterScopedSchemaIds() (which registers a schema slug is
 * declared under and their pre-import schema ids), resolveImportRegisterSchema()
 * (a seed's register and schema from the import maps or the mappers, with the
 * foreign-schema guard) and findExistingSeedUuid() (the stored uuid of a seed),
 * including every mapper call they make, in order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

class ImportHandlerSeedLookupCharacterisationTest extends TestCase {

	/** @var SchemaMapper&MockObject The schema mapper double. */
	private SchemaMapper&MockObject $schemaMapper;

	/** @var RegisterMapper&MockObject The register mapper double. */
	private RegisterMapper&MockObject $registerMapper;

	/** @var ObjectService&MockObject The object service double. */
	private ObjectService&MockObject $objectService;

	/** @var LoggerInterface&MockObject The logger double. */
	private LoggerInterface&MockObject $logger;

	/** @var ImportHandler The handler under test. */
	private ImportHandler $handler;

	/** @var array<string, mixed> What the register mapper answers per id; a Throwable is thrown. */
	private array $registers = [];

	/** @var array<string, mixed> What the schema mapper answers per id; a Throwable is thrown. */
	private array $schemas = [];

	/** @var array<int, string> Every mapper and search call, in order. */
	private array $calls = [];

	/** @var mixed What searchObjects answers; a Throwable is thrown. */
	private mixed $searchAnswer = [];

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->registerMapper->method('find')->willReturnCallback(
			fn ($id, ...$rest) => $this->answer('register', $this->registers, $id, $rest)
		);
		$this->schemaMapper->method('find')->willReturnCallback(
			fn ($id, ...$rest) => $this->answer('schema', $this->schemas, $id, $rest)
		);
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (...$args) {
				$this->calls[] = 'search ' . json_encode($args);
				if ($this->searchAnswer instanceof \Throwable) {
					throw $this->searchAnswer;
				}

				return $this->searchAnswer;
			}
		);

		$this->handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $this->registerMapper,
				schemas: $this->schemaMapper,
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger:              $this->logger,
			appDataPath:         '/tmp',
			objectService:       $this->objectService
		);
	}//end setUp()

	private function answer(string $kind, array $table, mixed $id, array $rest): mixed {
		$this->calls[] = $kind . ' ' . json_encode([$id, $rest]);
		$found = ($table[(string)$id] ?? new DoesNotExistException('not found'));
		if ($found instanceof \Throwable) {
			throw $found;
		}

		return $found;
	}//end answer()

	private function call(string $method, array $args): mixed {
		$reflection = new ReflectionMethod(ImportHandler::class, $method);
		return $reflection->invokeArgs($this->handler, $args);
	}//end call()

	private function setMap(string $property, array $map): void {
		(new ReflectionProperty(ImportHandler::class, $property))->setValue($this->handler, $map);
	}//end setMap()

	private function getMap(string $property): array {
		return (new ReflectionProperty(ImportHandler::class, $property))->getValue($this->handler);
	}//end getMap()

	private static function register(int $id, string $slug, array $schemas, ?string $application): Register {
		$register = new Register();
		$register->hydrate(['slug' => $slug, 'version' => '1.0.0']);
		$register->setSchemas($schemas);
		$register->setApplication($application);
		$register->setId($id);
		return $register;
	}//end register()

	private static function schema(int $id, string $slug, ?string $application): Schema {
		$schema = new Schema();
		$schema->hydrate(['slug' => $slug, 'title' => $slug, 'version' => '1.0.0', 'properties' => []]);
		$schema->setApplication($application);
		$schema->setId($id);
		return $schema;
	}//end schema()

	public function testNoRegisterComponentsGivesNothing(): void {
		$this->assertSame([], $this->call('computeRegisterScopedSchemaIds', [[]]));
		$this->assertSame([], $this->call('computeRegisterScopedSchemaIds', [['components' => ['registers' => 'x']]]));
		$this->assertSame([], $this->calls);
	}//end testNoRegisterComponentsGivesNothing()

	public function testRegistersWithoutUsableSchemaSlugsGiveNothing(): void {
		$data = ['components' => ['registers' => [
			'a' => 'not-an-array',
			'b' => ['title' => 'no schemas'],
			'c' => ['schemas' => 'not-a-list'],
			'd' => ['schemas' => ['', 5, null]],
		]]];

		$this->assertSame([], $this->call('computeRegisterScopedSchemaIds', [$data]));
		$this->assertSame([], $this->calls);
	}//end testRegistersWithoutUsableSchemaSlugsGiveNothing()

	public function testEachSlugGetsTheUnionOfItsRegistersCurrentSchemaIds(): void {
		$this->registers['pub'] = self::register(1, 'pub', [3, '4', 'x', 3.0], null);
		$this->registers['cat'] = self::register(2, 'cat', [4, 9], null);
		$this->registers['broken'] = new RuntimeException('boom');

		$data = ['components' => ['registers' => [
			'PUB' => ['schemas' => ['Page', 'menu', 'page', '']],
			'cat' => ['schemas' => ['page', 'Item']],
			'new' => ['schemas' => ['item']],
			'broken' => ['schemas' => ['menu']],
			'pub' => ['schemas' => ['menu']],
		]]];

		$this->assertSame(
			['page' => [3, 4, 9], 'menu' => [3, 4], 'item' => [4, 9]],
			$this->call('computeRegisterScopedSchemaIds', [$data])
		);
		$this->assertSame(
			[
				'register ["pub",[false,false]]',
				'register ["cat",[false,false]]',
				'register ["broken",[false,false]]',
				'register ["new",[false,false]]',
			],
			$this->calls
		);
	}//end testEachSlugGetsTheUnionOfItsRegistersCurrentSchemaIds()

	public function testBothComeFromTheImportMapsWithoutLookups(): void {
		$register = self::register(1, 'pub', [], 'app');
		$schema = self::schema(2, 'page', 'app');
		$this->setMap('registersMap', ['pub' => $register]);
		$this->setMap('schemasMap', ['page' => $schema]);

		$result = $this->call('resolveImportRegisterSchema', [['@self' => ['register' => 'pub', 'schema' => 'page']], 'app']);

		$this->assertSame([$register, $schema], $result);
		$this->assertSame([], $this->calls);
	}//end testBothComeFromTheImportMapsWithoutLookups()

	public function testMissesAreLookedUpAndRemembered(): void {
		$register = self::register(1, 'pub', [], null);
		$schema = self::schema(2, 'page', null);
		$this->registers['pub'] = $register;
		$this->schemas['page'] = $schema;
		$this->setMap('registersMap', ['pub' => 'not-a-register']);

		$seed = ['@self' => ['register' => 'pub', 'schema' => 'page']];
		$this->assertSame([$register, $schema], $this->call('resolveImportRegisterSchema', [$seed]));
		$this->assertSame([$register, $schema], $this->call('resolveImportRegisterSchema', [$seed]));

		$this->assertSame(['register ["pub",[false,false]]', 'schema ["page",[[],false,false]]'], $this->calls);
		$this->assertSame($register, $this->getMap('registersMap')['pub']);
		$this->assertSame($schema, $this->getMap('schemasMap')['page']);
	}//end testMissesAreLookedUpAndRemembered()

	public function testFailedLookupsAndUnusableSlugsGiveNulls(): void {
		$this->registers['pub'] = new RuntimeException('gone');
		$this->setMap('schemasMap', ['page' => 'stale']);

		$this->assertSame([null, null], $this->call('resolveImportRegisterSchema', [['@self' => ['register' => 'pub', 'schema' => 5]]]));
		$this->assertSame([null, null], $this->call('resolveImportRegisterSchema', [['@self' => ['register' => '', 'schema' => '']]]));
		$this->assertSame([null, null], $this->call('resolveImportRegisterSchema', [[]]));
		$this->assertSame([null, null], $this->call('resolveImportRegisterSchema', [['@self' => ['register' => 7, 'schema' => 'gone']]]));

		$this->assertSame(['register ["pub",[false,false]]', 'schema ["gone",[[],false,false]]'], $this->calls);
		$this->assertArrayNotHasKey('pub', $this->getMap('registersMap'));
		$this->assertSame(['page' => 'stale'], $this->getMap('schemasMap'));
	}//end testFailedLookupsAndUnusableSlugsGiveNulls()

	public function testARegisterWithoutASchemaKeepsTheRegister(): void {
		$register = self::register(1, 'pub', [99], 'app');
		$this->setMap('registersMap', ['pub' => $register]);

		$this->assertSame([$register, null], $this->call('resolveImportRegisterSchema', [['@self' => ['register' => 'pub', 'schema' => 'none']], 'app']));
	}//end testARegisterWithoutASchemaKeepsTheRegister()

	public function testAForeignSchemaIsDroppedAndLogged(): void {
		$register = self::register(1, 'pub', [99], 'opencatalogi');
		$schema = self::schema(2, 'organization', 'stackiq');
		$this->setMap('registersMap', ['pub' => $register]);
		$this->setMap('schemasMap', ['organization' => $schema]);
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(
			[$register, null],
			$this->call('resolveImportRegisterSchema', [['@self' => ['register' => 'pub', 'schema' => 'organization']], 'opencatalogi'])
		);
	}//end testAForeignSchemaIsDroppedAndLogged()

	public function testASchemaTheRegisterListsIsKept(): void {
		$register = self::register(1, 'pub', [2], 'opencatalogi');
		$schema = self::schema(2, 'organization', 'stackiq');
		$this->setMap('registersMap', ['pub' => $register]);
		$this->setMap('schemasMap', ['organization' => $schema]);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(
			[$register, $schema],
			$this->call('resolveImportRegisterSchema', [['@self' => ['register' => 'pub', 'schema' => 'organization']], 'opencatalogi'])
		);
	}//end testASchemaTheRegisterListsIsKept()

	private function seedUuid(mixed $answer): mixed {
		$this->searchAnswer = $answer;
		$table = new RegisterSchema(register: self::register(4, 'pub', [], null), schema: self::schema(5, 'page', null));
		return $this->call('findExistingSeedUuid', [$table, 'home']);
	}//end seedUuid()

	public function testTheSeedSearchIsScopedToRegisterSchemaAndSlug(): void {
		$this->seedUuid([]);
		$this->assertSame(
			['search [{"@self":{"register":4,"schema":5,"slug":"home"},"_limit":1},false,false,null,null,null,false]'],
			$this->calls
		);
	}//end testTheSeedSearchIsScopedToRegisterSchemaAndSlug()

	public function testNoStoredSeedGivesNull(): void {
		$this->assertNull($this->seedUuid(new RuntimeException('search down')));
		$this->assertNull($this->seedUuid('not-a-list'));
		$this->assertNull($this->seedUuid([]));
		$this->assertNull($this->seedUuid(['not-an-object']));
		$this->assertNull($this->seedUuid([['@self' => ['id' => '']]]));
		$this->assertNull($this->seedUuid([['@self' => ['id' => 12]]]));
		$this->assertNull($this->seedUuid([['title' => 'no id']]));
	}//end testNoStoredSeedGivesNull()

	public function testTheStoredUuidIsReturned(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('entity-uuid');
		$this->assertSame('entity-uuid', $this->seedUuid([$entity, ['@self' => ['id' => 'second']]]));
		$this->assertSame('self-id', $this->seedUuid([['@self' => ['id' => 'self-id'], 'id' => 'plain-id']]));
		$this->assertSame('plain-id', $this->seedUuid([['id' => 'plain-id']]));
		$this->assertSame('plain-id', $this->seedUuid([['@self' => ['id' => null], 'id' => 'plain-id']]));
	}//end testTheStoredUuidIsReturned()
}//end class
