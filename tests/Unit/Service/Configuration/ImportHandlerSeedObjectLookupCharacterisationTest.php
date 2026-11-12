<?php

/**
 * Characterisation of the seed-object lookups of ImportHandler.
 *
 * Pins findStoredSeedObject() (no routing mapper, no register, found, not
 * found, ambiguous), listedRegister() / listedSchema() (import map, lookup,
 * failed lookup, unusable slugs) and orSuffixedSeed() (when it falls back to
 * the suffixed-slug lookup), with every mapper and log call.
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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SeedDataObject;
use OCA\OpenRegister\Service\Configuration\SeedImport;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

class ImportHandlerSeedObjectLookupCharacterisationTest extends TestCase {

	/** @var array<int, string> Every recorded call, in order. */
	private array $calls = [];

	/** @var ImportHandler The handler under test. */
	private ImportHandler $handler;

	/** @var MagicMapper The routing mapper double. */
	private MagicMapper $routingMapper;

	/** @var array<string, mixed> What the register and schema mappers answer per id; a Throwable is thrown. */
	private array $found = [];

	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				unset($context['line'], $context['file']);
				$this->calls[] = 'warning ' . $message . ' ' . json_encode($context);
			}
		);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(fn ($id, ...$rest) => $this->answer('register', $id, $rest));
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(fn ($id, ...$rest) => $this->answer('schema', $id, $rest));

		$this->handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $registerMapper,
				schemas: $schemaMapper,
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger:              $logger,
			appDataPath:         '/tmp',
			objectService:       $this->createMock(ObjectService::class)
		);
		$this->routingMapper = $this->createMock(MagicMapper::class);
	}//end setUp()

	private function answer(string $kind, mixed $id, array $rest): mixed {
		$this->calls[] = $kind . ' ' . json_encode([$id, $rest]);
		$found = ($this->found[$kind . ':' . $id] ?? new DoesNotExistException('not found'));
		if ($found instanceof \Throwable) {
			throw $found;
		}

		return $found;
	}//end answer()

	private function call(string $method, array $args): mixed {
		return (new ReflectionMethod(ImportHandler::class, $method))->invokeArgs($this->handler, $args);
	}//end call()

	private static function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}//end register()

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}//end schema()

	private function seedObject(array $data, ?Register $register): array {
		$result = [];
		$seed = new SeedImport(configData: [], result: $result);
		$seed->schemaSlug = 'page';
		$seed->schema = self::schema(5);
		$seed->targetRegister = $register;
		$object = new SeedDataObject(data: $data, seed: $seed);
		$object->identifier = 'home';
		return [$seed, $object];
	}//end seedObject()

	public function testWithoutARoutingMapperOrRegisterNothingIsStored(): void {
		[$seed, $object] = $this->seedObject([], self::register(4));
		$this->assertNull($this->call('findStoredSeedObject', [$seed, $object]));

		$this->handler->setObjectMapper($this->routingMapper);
		$this->routingMapper->expects($this->never())->method('find');
		[$seed, $object] = $this->seedObject([], null);
		$this->assertNull($this->call('findStoredSeedObject', [$seed, $object]));
	}//end testWithoutARoutingMapperOrRegisterNothingIsStored()

	public function testTheStoredSeedIsFoundByIdentifierInItsTable(): void {
		$stored = new ObjectEntity();
		$this->routingMapper->expects($this->once())->method('find')
			->with('home', $this->isInstanceOf(Register::class), $this->isInstanceOf(Schema::class), false, false, false)
			->willReturn($stored);
		$this->handler->setObjectMapper($this->routingMapper);
		[$seed, $object] = $this->seedObject([], self::register(4));

		$this->assertSame($stored, $this->call('findStoredSeedObject', [$seed, $object]));
		$this->assertFalse($object->ambiguous);
	}//end testTheStoredSeedIsFoundByIdentifierInItsTable()

	public function testAMissingSeedIsNotStored(): void {
		$this->routingMapper->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->handler->setObjectMapper($this->routingMapper);
		[$seed, $object] = $this->seedObject([], self::register(4));

		$this->assertNull($this->call('findStoredSeedObject', [$seed, $object]));
		$this->assertFalse($object->ambiguous);
		$this->assertSame([], $this->calls);
	}//end testAMissingSeedIsNotStored()

	public function testAnAmbiguousSeedIsMarkedAndWarned(): void {
		$this->routingMapper->method('find')->willThrowException(new MultipleObjectsReturnedException('two'));
		$this->handler->setObjectMapper($this->routingMapper);
		[$seed, $object] = $this->seedObject([], self::register(4));

		$this->assertNull($this->call('findStoredSeedObject', [$seed, $object]));
		$this->assertTrue($object->ambiguous);
		$this->assertSame(
			[
				"warning [ImportHandler] Multiple seed objects found with identifier 'home' - skipping to avoid duplication "
				. '{"schema":"page","identifier":"home"}',
			],
			$this->calls
		);
	}//end testAnAmbiguousSeedIsMarkedAndWarned()

	public function testOtherFailuresPropagate(): void {
		$this->routingMapper->method('find')->willThrowException(new \RuntimeException('db down'));
		$this->handler->setObjectMapper($this->routingMapper);
		[$seed, $object] = $this->seedObject([], self::register(4));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');
		$this->call('findStoredSeedObject', [$seed, $object]);
	}//end testOtherFailuresPropagate()

	public function testListedRegisterAndSchemaComeFromTheMapsOrALookup(): void {
		$mapped = self::register(1);
		(new ReflectionProperty(ImportHandler::class, 'registersMap'))->setValue($this->handler, ['pub' => $mapped]);
		$this->found['register:cat'] = self::register(2);
		$this->found['register:gone'] = new \RuntimeException('boom');
		$this->found['schema:page'] = self::schema(3);

		$this->assertSame($mapped, $this->call('listedRegister', ['pub']));
		$this->assertSame($this->found['register:cat'], $this->call('listedRegister', ['cat']));
		$this->assertSame($this->found['register:cat'], $this->call('listedRegister', ['cat']));
		$this->assertNull($this->call('listedRegister', ['gone']));
		$this->assertNull($this->call('listedRegister', ['missing']));
		$this->assertNull($this->call('listedRegister', ['']));
		$this->assertNull($this->call('listedRegister', [7]));
		$this->assertNull($this->call('listedRegister', [null]));
		$this->assertSame($this->found['schema:page'], $this->call('listedSchema', ['page']));
		$this->assertSame($this->found['schema:page'], $this->call('listedSchema', ['page']));
		$this->assertNull($this->call('listedSchema', ['missing']));
		$this->assertNull($this->call('listedSchema', ['']));
		$this->assertNull($this->call('listedSchema', [3]));

		$this->assertSame(
			[
				'register ["cat",[false,false]]',
				'register ["gone",[false,false]]',
				'register ["missing",[false,false]]',
				'schema ["page",[[],false,false]]',
				'schema ["missing",[[],false,false]]',
			],
			$this->calls
		);
		$this->assertSame(['pub', 'cat'], array_keys((new ReflectionProperty(ImportHandler::class, 'registersMap'))->getValue($this->handler)));
		$this->assertSame(['page'], array_keys((new ReflectionProperty(ImportHandler::class, 'schemasMap'))->getValue($this->handler)));
	}//end testListedRegisterAndSchemaComeFromTheMapsOrALookup()

	public function testOnlyAnUnfoundSeedWithoutUuidInARegisterTriesTheSuffixedSlug(): void {
		$found = new ObjectEntity();
		[, $inRegister] = $this->seedObject(['slug' => 'home'], self::register(4));
		[, $withUuid] = $this->seedObject(['uuid' => 'u-1'], self::register(4));
		[, $noRegister] = $this->seedObject(['slug' => 'home'], null);

		$this->assertSame($found, $this->call('orSuffixedSeed', [$found, $inRegister]));
		$this->assertNull($this->call('orSuffixedSeed', [null, $withUuid]));
		$this->assertNull($this->call('orSuffixedSeed', [null, $noRegister]));
		$this->assertSame([], $this->calls);

		// Only the last case reaches the suffixed lookup, which asks the slug handler first.
		$slugHandler = $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler::class);
		$slugHandler->method('isManaged')->willReturnCallback(
			function (): bool {
				$this->calls[] = 'slugHandler.isManaged';
				return false;
			}
		);
		$this->handler->setSlugHandler($slugHandler);
		$this->call('orSuffixedSeed', [$found, $inRegister]);
		$this->call('orSuffixedSeed', [null, $withUuid]);
		$this->call('orSuffixedSeed', [null, $noRegister]);
		$this->assertSame([], $this->calls);
		$this->assertNull($this->call('orSuffixedSeed', [null, $inRegister]));
		$this->assertSame(['slugHandler.isManaged'], $this->calls);
	}//end testOnlyAnUnfoundSeedWithoutUuidInARegisterTriesTheSuffixedSlug()
}//end class
