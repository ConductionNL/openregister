<?php

declare(strict_types=1);

/**
 * ExportHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Configuration ExportHandler
 *
 * Tests exporting configurations, registers, and schemas to OpenAPI format.
 */
class ExportHandlerTest extends TestCase {
	/** @var ExportHandler */
	private ExportHandler $handler;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	/** @var MagicMapper&MockObject */
	private MagicMapper $objectEntityMapper;

	/** @var ConfigurationMapper&MockObject */
	private ConfigurationMapper $configurationMapper;

	/** @var MappingMapper&MockObject */
	private MappingMapper $mappingMapper;

	/** @var FlowMapper&MockObject */
	private FlowMapper $flowMapper;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->configurationMapper = $this->createMock(ConfigurationMapper::class);
		$this->mappingMapper = $this->createMock(MappingMapper::class);
		$this->flowMapper = $this->createMock(FlowMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new ExportHandler(
			$this->schemaMapper,
			$this->registerMapper,
			$this->objectEntityMapper,
			$this->configurationMapper,
			$this->mappingMapper,
			$this->flowMapper,
			$this->logger
		);
	}

	/**
	 * Helper to create a Register entity with basic properties set via Reflection.
	 */
	private function createRegister(int $id, string $title, string $slug): Register {
		$register = new Register();
		$reflection = new \ReflectionProperty($register, 'id');
		$reflection->setAccessible(true);
		$reflection->setValue($register, $id);
		$register->setTitle($title);
		$register->setSlug($slug);
		$register->setDescription('Test register description');
		$register->setVersion('1.0.0');
		return $register;
	}

	/**
	 * Helper to create a Schema entity with basic properties set via Reflection.
	 */
	private function createSchema(int $id, string $title, string $slug): Schema {
		$schema = new Schema();
		$reflection = new \ReflectionProperty($schema, 'id');
		$reflection->setAccessible(true);
		$reflection->setValue($schema, $id);
		$schema->setTitle($title);
		$schema->setSlug($slug);
		return $schema;
	}

	// =========================================================================
	// exportConfig with Register input
	// =========================================================================

	#[Test]
	public function testExportConfigWithRegisterInput(): void {
		$register = $this->createRegister(1, 'My Register', 'my-register');

		// getSchemasByRegisterId returns schemas for this register
		$schema = $this->createSchema(10, 'Person', 'person');
		$this->registerMapper->method('getSchemasByRegisterId')
			->with(1)
			->willReturn([$schema]);

		$this->schemaMapper->method('getIdToSlugMap')
			->willReturn([10 => 'person']);

		$this->registerMapper->method('getIdToSlugMap')
			->willReturn([1 => 'my-register']);

		$result = $this->handler->exportConfig($register);

		// Verify OpenAPI structure
		$this->assertEquals('3.0.0', $result['openapi']);
		$this->assertArrayHasKey('info', $result);
		$this->assertEquals('My Register', $result['info']['title']);
		$this->assertEquals('1.0.0', $result['info']['version']);
		$this->assertArrayHasKey('components', $result);
		$this->assertArrayHasKey('registers', $result['components']);
		$this->assertArrayHasKey('my-register', $result['components']['registers']);
		$this->assertArrayHasKey('schemas', $result['components']);
		$this->assertArrayHasKey('person', $result['components']['schemas']);
	}

	#[Test]
	public function testExportConfigWithRegisterSetsTypeMetadata(): void {
		$register = $this->createRegister(1, 'My Register', 'my-register');

		$this->registerMapper->method('getSchemasByRegisterId')->willReturn([]);
		$this->schemaMapper->method('getIdToSlugMap')->willReturn([]);
		$this->registerMapper->method('getIdToSlugMap')->willReturn([]);

		$result = $this->handler->exportConfig($register);

		$this->assertArrayHasKey('x-openregister', $result);
		$this->assertEquals('register', $result['x-openregister']['type']);
	}

	// =========================================================================
	// exportConfig with Configuration input
	// =========================================================================

	#[Test]
	public function testExportConfigWithConfigurationInput(): void {
		$config = new Configuration();
		$config->setTitle('My Config');
		$config->setDescription('Config description');
		$config->setVersion('2.0.0');
		$config->setType('app');
		$config->setApp('myapp');
		$config->setRegisters([1]);

		$register = $this->createRegister(1, 'Reg One', 'reg-one');

		$this->registerMapper->method('find')
			->willReturn($register);

		$schema = $this->createSchema(5, 'Item', 'item');
		$this->registerMapper->method('getSchemasByRegisterId')
			->willReturn([$schema]);

		$this->schemaMapper->method('getIdToSlugMap')
			->willReturn([5 => 'item']);

		$this->registerMapper->method('getIdToSlugMap')
			->willReturn([1 => 'reg-one']);

		$result = $this->handler->exportConfig($config);

		$this->assertEquals('3.0.0', $result['openapi']);
		$this->assertEquals('My Config', $result['info']['title']);
		$this->assertEquals('2.0.0', $result['info']['version']);
		$this->assertArrayHasKey('x-openregister', $result);
		$this->assertEquals('app', $result['x-openregister']['type']);
		$this->assertEquals('myapp', $result['x-openregister']['app']);
	}

	// =========================================================================
	// exportConfig with array input
	// =========================================================================

	#[Test]
	public function testExportConfigWithArrayInput(): void {
		$inputArray = [
			'id' => 99,
			'title' => 'Array Config',
			'description' => 'From array',
			'version' => '3.0.0',
		];

		$config = new Configuration();
		$config->setTitle('Array Config');
		$config->setRegisters([]);

		$this->configurationMapper->method('find')
			->with(99)
			->willReturn($config);

		$result = $this->handler->exportConfig($inputArray);

		$this->assertEquals('3.0.0', $result['openapi']);
		$this->assertEquals('Array Config', $result['info']['title']);
	}

	// =========================================================================
	// exportConfig with includeObjects
	// =========================================================================

	#[Test]
	public function testExportConfigWithIncludeObjects(): void {
		$register = $this->createRegister(1, 'My Reg', 'my-reg');

		$this->registerMapper->method('getSchemasByRegisterId')->willReturn([]);
		$this->schemaMapper->method('getIdToSlugMap')->willReturn([]);
		$this->registerMapper->method('getIdToSlugMap')->willReturn([]);

		// Mock findAll to return objects
		$mockObject = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$mockObject->method('jsonSerialize')->willReturn([
			'@self' => ['register' => 1, 'schema' => 5],
			'name' => 'Test Object',
		]);
		$this->objectEntityMapper->method('findAll')
			->willReturn([$mockObject]);

		$result = $this->handler->exportConfig($register, true);

		$this->assertNotEmpty($result['components']['objects']);
	}

	// =========================================================================
	// exportConfig with empty registers
	// =========================================================================

	#[Test]
	public function testExportConfigWithNoRegistersReturnsEmptyComponents(): void {
		$config = new Configuration();
		$config->setTitle('Empty Config');
		$config->setDescription('No registers');
		$config->setVersion('1.0.0');
		$config->setRegisters([]);

		$result = $this->handler->exportConfig($config);

		$this->assertEquals('3.0.0', $result['openapi']);
		$this->assertEmpty($result['components']['registers']);
		$this->assertEmpty($result['components']['schemas']);
	}

	// =========================================================================
	// exportConfig with mappings
	// =========================================================================

	#[Test]
	public function testExportConfigExportsMappings(): void {
		$config = new Configuration();
		$config->setTitle('Config With Mappings');
		$config->setDescription('Has mappings');
		$config->setVersion('1.0.0');
		$config->setRegisters([]);
		$config->setMappings([42]);

		$mapping = new Mapping();
		$mapping->setSlug('my-mapping');
		$mapping->setName('My Mapping');

		$this->mappingMapper->method('find')
			->with(42)
			->willReturn($mapping);

		$result = $this->handler->exportConfig($config);

		$this->assertArrayHasKey('my-mapping', $result['components']['mappings']);
	}

	#[Test]
	public function testExportConfigHandlesMappingNotFound(): void {
		$config = new Configuration();
		$config->setTitle('Config');
		$config->setDescription('Desc');
		$config->setVersion('1.0.0');
		$config->setRegisters([]);
		$config->setMappings([999]);

		$this->mappingMapper->method('find')
			->with(999)
			->willThrowException(new Exception('Mapping not found'));

		// Should log warning but not throw
		$this->logger->expects($this->once())
			->method('warning');

		$result = $this->handler->exportConfig($config);

		$this->assertEmpty($result['components']['mappings']);
	}

	// =========================================================================
	// exportConfig with flows
	// =========================================================================

	#[Test]
	public function testExportConfigExportsFlowsKeyedByUuidWithEnvironmentFieldsStripped(): void {
		$config = new Configuration();
		$config->setTitle('Config With Flows');
		$config->setDescription('Has flows');
		$config->setVersion('1.0.0');
		$config->setRegisters([]);
		$config->setFlows(['flow-uuid-1']);
		$config->setOrganisation('org-1');

		$flow = new \OCA\OpenRegister\Db\Flow();
		$flow->setUuid('flow-uuid-1');
		$flow->setName('My Flow');
		$flow->setNodes([['id' => 'n1', 'type' => 'trigger.manual']]);
		$flow->setOwner('alice');
		$flow->setOrganisation('org-1');

		$this->flowMapper->method('findByUuid')
			->with('flow-uuid-1')
			->willReturn($flow);

		$result = $this->handler->exportConfig($config);

		$this->assertArrayHasKey('flow-uuid-1', $result['components']['flows']);
		$exported = $result['components']['flows']['flow-uuid-1'];
		// The one deliberate divergence from exportMappings(): uuid is KEPT,
		// because a flow has no slug to match on at import time.
		$this->assertSame('flow-uuid-1', $exported['uuid']);
		// The definition itself survives the export, not just the key.
		$this->assertSame('My Flow', $exported['name']);
		$this->assertSame([['id' => 'n1', 'type' => 'trigger.manual']], $exported['nodes']);
		// `id` in Flow::jsonSerialize() is a copy of the uuid, not a database id;
		// it is stripped all the same.
		$this->assertArrayNotHasKey('id', $exported);
		$this->assertArrayNotHasKey('owner', $exported);
		$this->assertArrayNotHasKey('organisation', $exported);
	}

	#[Test]
	public function testExportConfigLeavesOutAFlowOfAnotherOrganisation(): void {
		$config = new Configuration();
		$config->setTitle('Config');
		$config->setDescription('Desc');
		$config->setVersion('1.0.0');
		$config->setRegisters([]);
		$config->setFlows(['flow-uuid-1']);
		$config->setOrganisation('org-B');

		$flow = new \OCA\OpenRegister\Db\Flow();
		$flow->setUuid('flow-uuid-1');
		$flow->setName('Not yours');
		$flow->setOrganisation('org-A');

		$this->flowMapper->method('findByUuid')->with('flow-uuid-1')->willReturn($flow);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->handler->exportConfig($config);

		$this->assertEmpty($result['components']['flows']);
	}

	#[Test]
	public function testExportConfigHandlesFlowNotFound(): void {
		$config = new Configuration();
		$config->setTitle('Config');
		$config->setDescription('Desc');
		$config->setVersion('1.0.0');
		$config->setRegisters([]);
		$config->setFlows(['missing-uuid']);

		$this->flowMapper->method('findByUuid')
			->with('missing-uuid')
			->willThrowException(new Exception('Flow not found'));

		// Should log warning but not throw
		$this->logger->expects($this->once())
			->method('warning');

		$result = $this->handler->exportConfig($config);

		$this->assertEmpty($result['components']['flows']);
	}

}
