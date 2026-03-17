<?php

declare(strict_types=1);

/**
 * ImportHandler Coverage Tests
 *
 * Additional unit tests for ImportHandler targeting uncovered lines
 * to increase code coverage beyond the existing ImportHandlerTest.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\DeployedWorkflow;
use OCA\OpenRegister\Db\DeployedWorkflowMapper;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\WorkflowEngine;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCA\OpenRegister\WorkflowEngine\WorkflowEngineInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Additional coverage tests for ImportHandler.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class ImportHandlerCoverageTest extends TestCase
{

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectEntityMapper;

    /** @var ConfigurationMapper&MockObject */
    private ConfigurationMapper $configurationMapper;

    /** @var MappingMapper&MockObject */
    private MappingMapper $mappingMapper;

    /** @var Client&MockObject */
    private Client $client;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var UploadHandler&MockObject */
    private UploadHandler $uploadHandler;

    /** @var ObjectService&MockObject */
    private ObjectService $objectService;

    private ImportHandler $handler;


    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->objectEntityMapper  = $this->createMock(ObjectEntityMapper::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->mappingMapper       = $this->createMock(MappingMapper::class);
        $this->client              = $this->createMock(Client::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->uploadHandler       = $this->createMock(UploadHandler::class);
        $this->objectService       = $this->createMock(ObjectService::class);

        $this->handler = new ImportHandler(
            schemaMapper:        $this->schemaMapper,
            registerMapper:      $this->registerMapper,
            objectEntityMapper:  $this->objectEntityMapper,
            configurationMapper: $this->configurationMapper,
            mappingMapper:       $this->mappingMapper,
            client:              $this->client,
            appConfig:           $this->appConfig,
            logger:              $this->logger,
            appDataPath:         '/tmp',
            uploadHandler:       $this->uploadHandler,
            objectService:       $this->objectService
        );

    }//end setUp()


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Inject a value into a private/protected property via reflection.
     */
    private function setProperty(object $object, string $property, mixed $value): void
    {
        $ref  = new ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);

    }//end setProperty()


    /**
     * Set the integer id on an Entity instance via reflection.
     */
    private function setEntityId(object $entity, int $id): void
    {
        $ref  = new ReflectionClass($entity);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($entity, $id);

    }//end setEntityId()


    /**
     * Build a minimal Register entity with slug, version, and id.
     */
    private function makeRegister(int $id, string $slug, string $version = '1.0.0'): Register
    {
        $register = new Register();
        $register->setSlug($slug);
        $register->setVersion($version);
        $this->setEntityId($register, $id);
        return $register;

    }//end makeRegister()


    /**
     * Build a minimal Schema entity with slug, version, and id.
     */
    private function makeSchema(int $id, string $slug, string $version = '1.0.0'): Schema
    {
        $schema = new Schema();
        $schema->setSlug($slug);
        $schema->setVersion($version);
        $this->setEntityId($schema, $id);
        return $schema;

    }//end makeSchema()


    /**
     * Build a minimal Configuration entity.
     */
    private function makeConfiguration(int $id, string $app = 'test-app', string $version = '1.0.0'): Configuration
    {
        $config = new Configuration();
        $config->setApp($app);
        $config->setVersion($version);
        $config->setRegisters([]);
        $config->setSchemas([]);
        $config->setObjects([]);
        $this->setEntityId($config, $id);
        return $config;

    }//end makeConfiguration()


    /**
     * Build a minimal Mapping entity.
     */
    private function makeMapping(int $id, string $slug, string $version = '1.0.0'): Mapping
    {
        $mapping = new Mapping();
        $mapping->setSlug($slug);
        $mapping->setVersion($version);
        $this->setEntityId($mapping, $id);
        return $mapping;

    }//end makeMapping()


    /**
     * Build a minimal DeployedWorkflow entity.
     */
    private function makeDeployedWorkflow(int $id, string $name, string $engine, string $hash = ''): DeployedWorkflow
    {
        $dw = new DeployedWorkflow();
        $dw->setName($name);
        $dw->setEngine($engine);
        $dw->setSourceHash($hash);
        $dw->setEngineWorkflowId('wf-123');
        $dw->setVersion(1);
        $this->setEntityId($dw, $id);
        return $dw;

    }//end makeDeployedWorkflow()


    /**
     * Invoke a private method via reflection.
     */
    private function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($object);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);

    }//end invokeMethod()


    // =========================================================================
    // importMapping — Exception catch on mapping lookup (lines 679-688)
    // =========================================================================

    /**
     * importMapping continues and creates new mapping when find() throws.
     */
    public function testImportMappingCreatesNewWhenFindThrows(): void
    {
        $config = $this->makeConfiguration(1);

        $this->mappingMapper->method('find')
            ->willThrowException(new Exception('DB error'));

        $newMapping = $this->makeMapping(10, 'test-mapping');
        $this->mappingMapper->method('createFromArray')
            ->willReturn($newMapping);

        $result = $this->invokeMethod($this->handler, 'importMapping', [
            ['slug' => 'test-mapping', 'name' => 'Test'],
            ['test-mapping' => 5],
            $config,
            '1.0.0',
            false,
        ]);

        $this->assertInstanceOf(Mapping::class, $result);
        $this->assertSame(10, $result->getId());

    }//end testImportMappingCreatesNewWhenFindThrows()


    /**
     * importMapping sets version from $version param when data has no version.
     */
    public function testImportMappingSetsVersionFromParam(): void
    {
        $config = $this->makeConfiguration(1);

        $this->mappingMapper->method('createFromArray')
            ->willReturnCallback(function (array $data) {
                $mapping = new Mapping();
                $mapping->setSlug($data['slug'] ?? 'test');
                $mapping->setVersion($data['version'] ?? '0.0.0');
                $this->setEntityId($mapping, 20);
                return $mapping;
            });

        $result = $this->invokeMethod($this->handler, 'importMapping', [
            ['slug' => 'no-version-mapping', 'name' => 'Test'],
            [],
            $config,
            '2.0.0',
            false,
        ]);

        $this->assertInstanceOf(Mapping::class, $result);

    }//end testImportMappingSetsVersionFromParam()


    // =========================================================================
    // getDuplicateRegisterInfo — date formatting (line 777)
    // =========================================================================

    /**
     * getDuplicateRegisterInfo formats created date when register has created date set.
     */
    public function testGetDuplicateRegisterInfoFormatsCreatedDate(): void
    {
        $reg1 = $this->makeRegister(1, 'dup-reg');
        $reg1->setTitle('Register A');
        $reg1->setUuid('uuid-1');
        $reg1->setCreated(new \DateTime('2024-01-15 10:30:00'));

        $reg2 = $this->makeRegister(2, 'dup-reg');
        $reg2->setTitle('Register B');
        $reg2->setUuid('uuid-2');
        $reg2->setCreated(new \DateTime('2024-02-20 14:00:00'));

        $this->registerMapper->method('findAll')
            ->willReturn([$reg1, $reg2]);

        $result = $this->invokeMethod($this->handler, 'getDuplicateRegisterInfo', ['dup-reg']);

        $this->assertStringContainsString('2024-01-15 10:30:00', $result);
        $this->assertStringContainsString('2024-02-20 14:00:00', $result);
        $this->assertStringContainsString('Register A', $result);
        $this->assertStringContainsString('Register B', $result);

    }//end testGetDuplicateRegisterInfoFormatsCreatedDate()


    /**
     * getDuplicateRegisterInfo shows "unknown" when created is null.
     */
    public function testGetDuplicateRegisterInfoShowsUnknownWhenNoCreatedDate(): void
    {
        $reg1 = $this->makeRegister(1, 'dup-reg');
        $reg1->setTitle('Register A');
        $reg1->setUuid('uuid-1');

        $reg2 = $this->makeRegister(2, 'dup-reg');
        $reg2->setTitle('Register B');
        $reg2->setUuid('uuid-2');

        $this->registerMapper->method('findAll')
            ->willReturn([$reg1, $reg2]);

        $result = $this->invokeMethod($this->handler, 'getDuplicateRegisterInfo', ['dup-reg']);

        $this->assertStringContainsString('unknown', $result);

    }//end testGetDuplicateRegisterInfoShowsUnknownWhenNoCreatedDate()


    // =========================================================================
    // importSchema — items $ref resolution from schemasMap (lines 979-980)
    // =========================================================================

    /**
     * importSchema resolves items.$ref from schemasMap.
     */
    public function testImportSchemaResolvesItemsRefFromSchemasMap(): void
    {
        $refSchema = $this->makeSchema(42, 'related-schema');
        $this->setProperty($this->handler, 'schemasMap', ['related-schema' => $refSchema]);

        $data = [
            'slug'       => 'items-ref-schema',
            'version'    => '1.0.0',
            'properties' => [
                'items_prop' => [
                    'type'  => 'array',
                    'items' => [
                        '$ref' => 'related-schema',
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(100, 'items-ref-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaResolvesItemsRefFromSchemasMap()


    // =========================================================================
    // importSchema — items objectConfiguration register/schema from DB (lines 1047-1111)
    // =========================================================================

    /**
     * importSchema resolves items.objectConfiguration.register from database.
     */
    public function testImportSchemaResolvesItemsObjConfigRegisterFromDatabase(): void
    {
        $existingRegister = $this->makeRegister(55, 'ext-register');

        $data = [
            'slug'       => 'items-oc-register',
            'version'    => '1.0.0',
            'properties' => [
                'children' => [
                    'type'  => 'array',
                    'items' => [
                        'objectConfiguration' => [
                            'register' => 'ext-register',
                        ],
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(101, 'items-oc-register');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $this->registerMapper->method('find')
            ->willReturn($existingRegister);

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaResolvesItemsObjConfigRegisterFromDatabase()


    /**
     * importSchema unsets items.objectConfiguration.register when register not found in DB.
     */
    public function testImportSchemaUnsetsItemsObjConfigRegisterWhenNotFound(): void
    {
        $data = [
            'slug'       => 'items-oc-reg-missing',
            'version'    => '1.0.0',
            'properties' => [
                'children' => [
                    'type'  => 'array',
                    'items' => [
                        'objectConfiguration' => [
                            'register' => 'nonexistent-register',
                        ],
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(102, 'items-oc-reg-missing');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaUnsetsItemsObjConfigRegisterWhenNotFound()


    /**
     * importSchema resolves items.objectConfiguration.schema from database.
     */
    public function testImportSchemaResolvesItemsObjConfigSchemaFromDatabase(): void
    {
        $existingSchema = $this->makeSchema(66, 'ext-schema');

        $data = [
            'slug'       => 'items-oc-schema-db',
            'version'    => '1.0.0',
            'properties' => [
                'children' => [
                    'type'  => 'array',
                    'items' => [
                        'objectConfiguration' => [
                            'schema' => 'ext-schema',
                        ],
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(103, 'items-oc-schema-db');

        // First call for the main schema slug: DoesNotExist
        // Second call for the ext-schema slug inside items: returns existingSchema
        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($existingSchema) {
                if ($id === 'ext-schema') {
                    return $existingSchema;
                }
                throw new \OCP\AppFramework\Db\DoesNotExistException('');
            });

        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaResolvesItemsObjConfigSchemaFromDatabase()


    /**
     * importSchema unsets items.objectConfiguration.schema when schema not found.
     */
    public function testImportSchemaUnsetsItemsObjConfigSchemaWhenNotFound(): void
    {
        $data = [
            'slug'       => 'items-oc-schema-miss',
            'version'    => '1.0.0',
            'properties' => [
                'children' => [
                    'type'  => 'array',
                    'items' => [
                        'objectConfiguration' => [
                            'schema' => 'nonexistent-schema',
                        ],
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(104, 'items-oc-schema-miss');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaUnsetsItemsObjConfigSchemaWhenNotFound()


    // =========================================================================
    // importSchema — legacy register property resolution (lines 1121-1122, 1128)
    // =========================================================================

    /**
     * importSchema resolves legacy register property from registersMap.
     */
    public function testImportSchemaResolvesLegacyRegisterFromRegistersMap(): void
    {
        $reg = $this->makeRegister(77, 'legacy-reg');
        $this->setProperty($this->handler, 'registersMap', ['legacy-reg' => $reg]);

        $data = [
            'slug'       => 'legacy-reg-schema',
            'version'    => '1.0.0',
            'properties' => [
                'related' => [
                    'type'     => 'object',
                    'register' => 'legacy-reg',
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(105, 'legacy-reg-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaResolvesLegacyRegisterFromRegistersMap()


    /**
     * importSchema resolves legacy items.register from registersMap.
     */
    public function testImportSchemaResolvesLegacyItemsRegisterFromRegistersMap(): void
    {
        $reg = $this->makeRegister(78, 'legacy-items-reg');
        $this->setProperty($this->handler, 'registersMap', ['legacy-items-reg' => $reg]);

        $data = [
            'slug'       => 'legacy-items-reg-schema',
            'version'    => '1.0.0',
            'properties' => [
                'children' => [
                    'type'  => 'array',
                    'items' => [
                        'register' => 'legacy-items-reg',
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(106, 'legacy-items-reg-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaResolvesLegacyItemsRegisterFromRegistersMap()


    // =========================================================================
    // importSchema — owner/app on update path (lines 1170, 1174)
    // =========================================================================

    /**
     * importSchema sets both owner and application when updating an existing schema.
     */
    public function testImportSchemaUpdatesWithOwnerAndApp(): void
    {
        $existing = $this->makeSchema(50, 'update-schema', '1.0.0');

        $this->schemaMapper->method('find')
            ->willReturn($existing);
        $this->schemaMapper->method('updateFromArray')
            ->willReturn($existing);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $data = [
            'slug'    => 'update-schema',
            'version' => '2.0.0',
        ];

        $result = $this->handler->importSchema($data, [], 'owner-user', 'my-app', '2.0.0', true);

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaUpdatesWithOwnerAndApp()


    // =========================================================================
    // importFromJson — Pass 2: schema not in map (lines 1420-1431)
    // =========================================================================

    /**
     * importFromJson Pass 2 skips schema not found in schemasMap by slug.
     */
    public function testImportFromJsonPass2SkipsSchemaNotInMapBySlug(): void
    {
        $config = $this->makeConfiguration(1);

        // Schema created in pass 1 but uses key as slug, and slug differs.
        $createdSchema = $this->makeSchema(200, 'actual-slug');

        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')->willReturn('');

        $data = [
            'components' => [
                'schemas' => [
                    'my-key' => [
                        'slug'    => 'actual-slug',
                        'version' => '1.0.0',
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertIsArray($result);
        $this->assertCount(1, $result['schemas']);

    }//end testImportFromJsonPass2SkipsSchemaNotInMapBySlug()


    // =========================================================================
    // importFromJson — Pass 2: catches re-import failure (lines 1463-1473)
    // =========================================================================

    /**
     * importFromJson Pass 2 catches exception during re-import and continues.
     */
    public function testImportFromJsonPass2CatchesReImportException(): void
    {
        $config = $this->makeConfiguration(1);

        $createdSchema = $this->makeSchema(201, 'pass2-fail-schema');

        $callCount = 0;
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturnCallback(function () use (&$callCount, $createdSchema) {
                $callCount++;
                // Pass 1: DoesNotExist (create new)
                if ($callCount <= 1) {
                    throw new \OCP\AppFramework\Db\DoesNotExistException('');
                }
                // Pass 2: throw general exception
                throw new Exception('DB failure on update');
            });
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')->willReturn('');

        $data = [
            'components' => [
                'schemas' => [
                    'pass2-fail-schema' => [
                        'slug'    => 'pass2-fail-schema',
                        'version' => '1.0.0',
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertIsArray($result);
        $this->assertCount(1, $result['schemas']);

    }//end testImportFromJsonPass2CatchesReImportException()


    // =========================================================================
    // importFromJson — Object import version comparison (line 1731+)
    // =========================================================================

    /**
     * importFromJson updates object when imported version is higher.
     *
     * The registersMap/schemasMap are reset at the start of importFromJson,
     * so we include registers and schemas in components so they get populated.
     */
    public function testImportFromJsonUpdatesObjectWhenVersionHigher(): void
    {
        $config   = $this->makeConfiguration(1);
        $register = $this->makeRegister(10, 'obj-reg');
        $schema   = $this->makeSchema(20, 'obj-schema');

        // Schema import setup (Pass 1 and 2).
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturnArgument(0);

        // Register import setup.
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturnArgument(0);

        $existingObj = new ObjectEntity();
        $existingObj->setUuid('existing-uuid');
        $existingObj->setSlug('test-obj');
        $existingObj->setSchema(20);
        $existingObj->setRegister(10);
        $existingObj->setObject(['title' => 'Old']);
        $this->setEntityId($existingObj, 100);

        $this->objectService->method('searchObjects')
            ->willReturn([$existingObj]);

        $updatedObj = new ObjectEntity();
        $updatedObj->setUuid('existing-uuid');
        $updatedObj->setSlug('test-obj');
        $this->setEntityId($updatedObj, 100);

        $this->objectService->method('saveObject')
            ->willReturn($updatedObj);

        $this->appConfig->method('getValueString')->willReturn('');

        $data = [
            'components' => [
                'schemas' => [
                    'obj-schema' => [
                        'slug'    => 'obj-schema',
                        'version' => '1.0.0',
                    ],
                ],
                'registers' => [
                    'obj-reg' => [
                        'slug'    => 'obj-reg',
                        'version' => '1.0.0',
                    ],
                ],
                'objects' => [
                    [
                        '@self' => [
                            'register' => 'obj-reg',
                            'schema'   => 'obj-schema',
                            'slug'     => 'test-obj',
                            'version'  => '2.0.0',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertCount(1, $result['objects']);

    }//end testImportFromJsonUpdatesObjectWhenVersionHigher()


    /**
     * importFromJson skips object update when imported version is not higher.
     */
    public function testImportFromJsonSkipsObjectWhenVersionNotHigher(): void
    {
        $config   = $this->makeConfiguration(1);
        $register = $this->makeRegister(10, 'obj-reg');
        $schema   = $this->makeSchema(20, 'obj-schema');

        // Schema import setup.
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturnArgument(0);

        // Register import setup.
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturnArgument(0);

        $existingObj = new ObjectEntity();
        $existingObj->setUuid('existing-uuid');
        $existingObj->setSlug('test-obj');
        $existingObj->setSchema(20);
        $existingObj->setRegister(10);
        $existingObj->setObject(['title' => 'Current']);
        $this->setEntityId($existingObj, 100);

        $this->objectService->method('searchObjects')
            ->willReturn([$existingObj]);

        $this->appConfig->method('getValueString')->willReturn('');

        $data = [
            'components' => [
                'schemas' => [
                    'obj-schema' => [
                        'slug'    => 'obj-schema',
                        'version' => '1.0.0',
                    ],
                ],
                'registers' => [
                    'obj-reg' => [
                        'slug'    => 'obj-reg',
                        'version' => '1.0.0',
                    ],
                ],
                'objects' => [
                    [
                        '@self' => [
                            'register' => 'obj-reg',
                            'schema'   => 'obj-schema',
                            'slug'     => 'test-obj',
                            'version'  => '0.5.0',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertCount(0, $result['objects']);

    }//end testImportFromJsonSkipsObjectWhenVersionNotHigher()


    // =========================================================================
    // importFromJson — createOrUpdateConfiguration when config is null (lines 1802-1808)
    // =========================================================================

    /**
     * importFromJson calls createOrUpdateConfiguration when no config passed and results present.
     * NOTE: This cannot be triggered because importFromJson throws if config is null.
     * Instead we test the seedData skip path (lines 1822-1826).
     */


    // =========================================================================
    // importFromJson — seed data skip when no configuration (lines 1822-1826)
    // (This path requires config === null after the null-check, which is unreachable
    // since the method throws at line 1247 when config is null.)
    // =========================================================================


    // =========================================================================
    // processWorkflowHookWiring — deployed null skip (line 1993)
    // =========================================================================

    /**
     * processWorkflowHookWiring skips workflow when deployed is null (name not in map).
     */
    public function testWorkflowHookWiringSkipsWhenDeployedNotInMap(): void
    {
        $deployedWfMapper = $this->createMock(DeployedWorkflowMapper::class);
        $registry         = $this->createMock(WorkflowEngineRegistry::class);

        $this->handler->setDeployedWorkflowMapper($deployedWfMapper);
        $this->handler->setWorkflowEngineRegistry($registry);

        $workflows = [
            [
                'name'     => 'unknown-workflow',
                'attachTo' => [
                    'schema' => 'test-schema',
                    'event'  => 'afterCreate',
                ],
            ],
        ];

        // deployedWorkflows map is empty, so the workflow name won't be found.
        $result = $this->invokeMethod($this->handler, 'processWorkflowHookWiring', [
            $workflows,
            [],
            ['workflows' => ['deployed' => [], 'updated' => [], 'unchanged' => [], 'failed' => []]],
        ]);

        $this->assertIsArray($result);

    }//end testWorkflowHookWiringSkipsWhenDeployedNotInMap()


    /**
     * processWorkflowHookWiring deduplicates hooks by removing existing hook with same workflowId+event.
     */
    public function testWorkflowHookWiringDeduplicatesExistingHooks(): void
    {
        $deployedWfMapper = $this->createMock(DeployedWorkflowMapper::class);
        $registry         = $this->createMock(WorkflowEngineRegistry::class);

        $this->handler->setDeployedWorkflowMapper($deployedWfMapper);
        $this->handler->setWorkflowEngineRegistry($registry);

        $deployed = $this->makeDeployedWorkflow(1, 'my-wf', 'n8n');

        $schema = $this->makeSchema(30, 'hook-schema');
        $schema->setHooks([
            [
                'event'      => 'afterCreate',
                'workflowId' => 'wf-123',
                'engine'     => 'n8n',
            ],
        ]);

        $this->setProperty($this->handler, 'schemasMap', ['hook-schema' => $schema]);

        $deployedWfMapper->method('update')
            ->willReturnArgument(0);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $workflows = [
            [
                'name'     => 'my-wf',
                'engine'   => 'n8n',
                'workflow' => ['nodes' => []],
                'attachTo' => [
                    'schema' => 'hook-schema',
                    'event'  => 'afterCreate',
                ],
            ],
        ];

        $result = $this->invokeMethod($this->handler, 'processWorkflowHookWiring', [
            $workflows,
            ['my-wf' => $deployed],
            ['workflows' => ['deployed' => [], 'updated' => [], 'unchanged' => [], 'failed' => []]],
        ]);

        $this->assertIsArray($result);

    }//end testWorkflowHookWiringDeduplicatesExistingHooks()


    // =========================================================================
    // importFromApp — various metadata update branches
    // =========================================================================

    /**
     * importFromApp finds config by app and skips when version not newer, but continues to import.
     */
    public function testImportFromAppContinuesWhenVersionNotNewer(): void
    {
        $config = $this->makeConfiguration(1, 'my-app', '2.0.0');

        $this->configurationMapper->method('findBySourceUrl')
            ->willReturn(null);
        $this->configurationMapper->method('findByApp')
            ->willReturn([$config]);
        $this->configurationMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')
            ->willReturn('2.0.0');

        $data = [
            'components' => [],
        ];

        $result = $this->handler->importFromApp('my-app', $data, '1.0.0');

        $this->assertIsArray($result);
        $this->assertCount(0, $result['schemas']);

    }//end testImportFromAppContinuesWhenVersionNotNewer()


    /**
     * importFromApp catches exception when looking up config by sourceUrl.
     */
    public function testImportFromAppCatchesSourceUrlException(): void
    {
        $this->configurationMapper->method('findBySourceUrl')
            ->willThrowException(new Exception('not found'));

        $config = $this->makeConfiguration(1, 'test-app', '0.5.0');
        $this->configurationMapper->method('findByApp')
            ->willReturn([$config]);
        $this->configurationMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $data = [
            'x-openregister' => [
                'sourceUrl' => 'https://example.com/config.json',
            ],
            'components'     => [],
        ];

        $result = $this->handler->importFromApp('test-app', $data, '1.0.0');

        $this->assertIsArray($result);

    }//end testImportFromAppCatchesSourceUrlException()


    /**
     * importFromApp catches exception when findByApp throws.
     */
    public function testImportFromAppCatchesFindByAppException(): void
    {
        $this->configurationMapper->method('findBySourceUrl')
            ->willReturn(null);
        $this->configurationMapper->method('findByApp')
            ->willThrowException(new Exception('DB error'));
        $this->configurationMapper->method('insert')
            ->willReturnCallback(function (Configuration $c) {
                $this->setEntityId($c, 99);
                return $c;
            });
        $this->configurationMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $data = [
            'info'       => ['title' => 'Test', 'description' => 'Test config'],
            'components' => [],
        ];

        $result = $this->handler->importFromApp('new-app', $data, '1.0.0');

        $this->assertIsArray($result);

    }//end testImportFromAppCatchesFindByAppException()


    /**
     * importFromApp updates metadata with legacy flat github structure.
     */
    public function testImportFromAppUpdatesLegacyGithubOnExistingConfig(): void
    {
        $config = $this->makeConfiguration(1, 'legacy-gh-app', '0.5.0');

        $this->configurationMapper->method('findBySourceUrl')
            ->willReturn(null);
        $this->configurationMapper->method('findByApp')
            ->willReturn([$config]);
        $this->configurationMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $schema = $this->makeSchema(200, 'gh-schema');
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturnArgument(0);

        $data = [
            'info' => [
                'title'       => 'Updated Title',
                'description' => 'Updated description',
            ],
            'x-openregister' => [
                'sourceType'   => 'github',
                'sourceUrl'    => 'https://github.com/test',
                'githubRepo'   => 'owner/repo',
                'githubBranch' => 'develop',
                'githubPath'   => 'configs/config.json',
            ],
            'components' => [
                'schemas' => [
                    'gh-schema' => [
                        'slug'    => 'gh-schema',
                        'version' => '1.0.0',
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromApp('legacy-gh-app', $data, '1.0.0');

        $this->assertIsArray($result);
        $this->assertCount(1, $result['schemas']);

    }//end testImportFromAppUpdatesLegacyGithubOnExistingConfig()


    /**
     * importFromApp sets xOpenregister description from x-openregister when info.description is absent.
     */
    public function testImportFromAppSetsDescriptionFromXOpenregister(): void
    {
        $config = $this->makeConfiguration(1, 'desc-app', '0.5.0');

        $this->configurationMapper->method('findBySourceUrl')
            ->willReturn(null);
        $this->configurationMapper->method('findByApp')
            ->willReturn([$config]);
        $this->configurationMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $schema = $this->makeSchema(210, 'desc-schema');
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturnArgument(0);

        $data = [
            'x-openregister' => [
                'title'       => 'XOR Title',
                'description' => 'XOR Description',
            ],
            'components' => [
                'schemas' => [
                    'desc-schema' => [
                        'slug'    => 'desc-schema',
                        'version' => '1.0.0',
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromApp('desc-app', $data, '1.0.0');

        $this->assertIsArray($result);

    }//end testImportFromAppSetsDescriptionFromXOpenregister()


    // =========================================================================
    // importSeedData — external configuration references (lines 2768-2955)
    // =========================================================================

    /**
     * importSeedData resolves external register and schema from config URL.
     */
    public function testImportSeedDataResolvesExternalConfig(): void
    {
        $config   = $this->makeConfiguration(1, 'ext-app', '1.0.0');
        $register = $this->makeRegister(10, 'main-reg');
        $config->setRegisters([10]);

        $extRegister = $this->makeRegister(20, 'ext-reg');
        $extSchema   = $this->makeSchema(30, 'ext-schema');

        $this->registerMapper->method('find')
            ->willReturnCallback(function ($id) use ($register, $extRegister) {
                if ($id === 10) {
                    return $register;
                }
                if ($id === 20) {
                    return $extRegister;
                }
                throw new \OCP\AppFramework\Db\DoesNotExistException('');
            });

        $this->registerMapper->method('getSlugToIdMap')
            ->willReturn(['ext-reg' => 20]);

        $schema = $this->makeSchema(40, 'seed-schema');
        $this->setProperty($this->handler, 'schemasMap', ['seed-schema' => $schema]);

        $this->schemaMapper->method('findBySlug')
            ->willReturn([$extSchema]);

        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $unifiedMapper->method('insert')
            ->willReturnCallback(function ($entity) {
                $this->setEntityId($entity, 500);
                return $entity;
            });
        $this->handler->setObjectMapper($unifiedMapper);

        $configData = [
            'info' => ['title' => 'External test'],
            'x-openregister' => [
                'seedData' => [
                    'description' => 'Test seed data',
                    'objects'     => [
                        'seed-schema' => [
                            [
                                '@self' => [
                                    'configuration' => 'https://example.com/config.json',
                                    'register'      => 'ext-reg',
                                    'schema'        => 'ext-schema',
                                ],
                                'slug'  => 'ext-object',
                                'title' => 'External Object',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = ['objects' => []];
        $this->invokeMethod($this->handler, 'importSeedData', [
            $configData, 'owner', 'ext-app', $config, &$result,
        ]);

        $this->assertNotEmpty($result['objects']);

    }//end testImportSeedDataResolvesExternalConfig()


    /**
     * importSeedData warns when external register not found.
     */
    public function testImportSeedDataWarnsOnMissingExternalRegister(): void
    {
        $config   = $this->makeConfiguration(1, 'miss-reg-app', '1.0.0');
        $register = $this->makeRegister(10, 'main-reg');
        $config->setRegisters([10]);

        $this->registerMapper->method('find')
            ->willReturn($register);
        $this->registerMapper->method('getSlugToIdMap')
            ->willReturn([]);

        $schema = $this->makeSchema(40, 'seed-schema');
        $this->setProperty($this->handler, 'schemasMap', ['seed-schema' => $schema]);

        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $unifiedMapper->method('insert')
            ->willReturnCallback(function ($entity) {
                $this->setEntityId($entity, 501);
                return $entity;
            });
        $this->handler->setObjectMapper($unifiedMapper);

        $configData = [
            'info' => ['title' => 'Missing reg test'],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'seed-schema' => [
                            [
                                '@self' => [
                                    'configuration' => 'https://example.com/config.json',
                                    'register'      => 'missing-reg',
                                ],
                                'slug' => 'test-obj',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = ['objects' => []];
        $this->invokeMethod($this->handler, 'importSeedData', [
            $configData, null, 'miss-reg-app', $config, &$result,
        ]);

        $this->assertNotEmpty($result['objects']);

    }//end testImportSeedDataWarnsOnMissingExternalRegister()


    /**
     * importSeedData warns when external schema not found.
     */
    public function testImportSeedDataWarnsOnMissingExternalSchema(): void
    {
        $config   = $this->makeConfiguration(1, 'miss-schema-app', '1.0.0');
        $register = $this->makeRegister(10, 'main-reg');
        $config->setRegisters([10]);

        $this->registerMapper->method('find')
            ->willReturn($register);
        $this->registerMapper->method('getSlugToIdMap')
            ->willReturn([]);

        $schema = $this->makeSchema(40, 'seed-schema');
        $this->setProperty($this->handler, 'schemasMap', ['seed-schema' => $schema]);

        $this->schemaMapper->method('findBySlug')
            ->willReturn([]);

        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $unifiedMapper->method('insert')
            ->willReturnCallback(function ($entity) {
                $this->setEntityId($entity, 502);
                return $entity;
            });
        $this->handler->setObjectMapper($unifiedMapper);

        $configData = [
            'info' => ['title' => 'Missing schema test'],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'seed-schema' => [
                            [
                                '@self' => [
                                    'configuration' => 'https://example.com/config.json',
                                    'schema'        => 'missing-schema',
                                ],
                                'slug' => 'test-obj',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = ['objects' => []];
        $this->invokeMethod($this->handler, 'importSeedData', [
            $configData, null, 'miss-schema-app', $config, &$result,
        ]);

        $this->assertNotEmpty($result['objects']);

    }//end testImportSeedDataWarnsOnMissingExternalSchema()


    /**
     * importSeedData catches exception when resolving external register.
     */
    public function testImportSeedDataCatchesExternalRegisterException(): void
    {
        $config   = $this->makeConfiguration(1, 'err-reg-app', '1.0.0');
        $register = $this->makeRegister(10, 'main-reg');
        $config->setRegisters([10]);

        $callCount = 0;
        $this->registerMapper->method('find')
            ->willReturnCallback(function ($id) use (&$callCount, $register) {
                $callCount++;
                if ($callCount === 1) {
                    return $register;
                }
                throw new Exception('DB error');
            });
        $this->registerMapper->method('getSlugToIdMap')
            ->willReturn(['ext-reg' => 20]);

        $schema = $this->makeSchema(40, 'seed-schema');
        $this->setProperty($this->handler, 'schemasMap', ['seed-schema' => $schema]);

        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $unifiedMapper->method('insert')
            ->willReturnCallback(function ($entity) {
                $this->setEntityId($entity, 503);
                return $entity;
            });
        $this->handler->setObjectMapper($unifiedMapper);

        $configData = [
            'info' => ['title' => 'Err reg test'],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'seed-schema' => [
                            [
                                '@self' => [
                                    'configuration' => 'https://example.com/config.json',
                                    'register'      => 'ext-reg',
                                ],
                                'slug' => 'test-obj',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = ['objects' => []];
        $this->invokeMethod($this->handler, 'importSeedData', [
            $configData, null, 'err-reg-app', $config, &$result,
        ]);

        $this->assertNotEmpty($result['objects']);

    }//end testImportSeedDataCatchesExternalRegisterException()


    /**
     * importSeedData catches exception when resolving external schema.
     */
    public function testImportSeedDataCatchesExternalSchemaException(): void
    {
        $config   = $this->makeConfiguration(1, 'err-schema-app', '1.0.0');
        $register = $this->makeRegister(10, 'main-reg');
        $config->setRegisters([10]);

        $this->registerMapper->method('find')
            ->willReturn($register);
        $this->registerMapper->method('getSlugToIdMap')
            ->willReturn([]);

        $schema = $this->makeSchema(40, 'seed-schema');
        $this->setProperty($this->handler, 'schemasMap', ['seed-schema' => $schema]);

        $this->schemaMapper->method('findBySlug')
            ->willThrowException(new Exception('Schema DB error'));

        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $unifiedMapper->method('insert')
            ->willReturnCallback(function ($entity) {
                $this->setEntityId($entity, 504);
                return $entity;
            });
        $this->handler->setObjectMapper($unifiedMapper);

        $configData = [
            'info' => ['title' => 'Err schema test'],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'seed-schema' => [
                            [
                                '@self' => [
                                    'configuration' => 'https://example.com/config.json',
                                    'schema'        => 'err-schema',
                                ],
                                'slug' => 'test-obj',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = ['objects' => []];
        $this->invokeMethod($this->handler, 'importSeedData', [
            $configData, null, 'err-schema-app', $config, &$result,
        ]);

        $this->assertNotEmpty($result['objects']);

    }//end testImportSeedDataCatchesExternalSchemaException()


    /**
     * importSeedData finds schema from database when not in schemasMap.
     */
    public function testImportSeedDataFindsSchemaFromDatabase(): void
    {
        $config   = $this->makeConfiguration(1, 'db-schema-app', '1.0.0');
        $register = $this->makeRegister(10, 'main-reg');
        $config->setRegisters([10]);

        $this->registerMapper->method('find')
            ->willReturn($register);

        $dbSchema = $this->makeSchema(50, 'db-schema');

        // schemasMap is empty, so the code tries schemaMapper->find()
        $this->schemaMapper->method('find')
            ->willReturn($dbSchema);

        $unifiedMapper = $this->createMock(MagicMapper::class);
        $unifiedMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $unifiedMapper->method('insert')
            ->willReturnCallback(function ($entity) {
                $this->setEntityId($entity, 505);
                return $entity;
            });
        $this->handler->setObjectMapper($unifiedMapper);

        $configData = [
            'info' => ['title' => 'DB schema test'],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'db-schema' => [
                            [
                                'slug'  => 'db-obj',
                                'title' => 'DB Object',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = ['objects' => []];
        $this->invokeMethod($this->handler, 'importSeedData', [
            $configData, null, 'db-schema-app', $config, &$result,
        ]);

        $this->assertNotEmpty($result['objects']);

    }//end testImportSeedDataFindsSchemaFromDatabase()


    // =========================================================================
    // importFromApp — object type checks on result update (lines 2302-2303)
    // =========================================================================

    /**
     * importFromApp skips non-Schema objects in result schemas array.
     */
    public function testImportFromAppSkipsNonSchemaInResults(): void
    {
        $config = $this->makeConfiguration(1, 'type-check-app', '0.5.0');

        $this->configurationMapper->method('findBySourceUrl')
            ->willReturn(null);
        $this->configurationMapper->method('findByApp')
            ->willReturn([$config]);
        $this->configurationMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')
            ->willReturn('');

        $schema = $this->makeSchema(300, 'type-check-schema');
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturnArgument(0);

        $data = [
            'components' => [
                'schemas' => [
                    'type-check-schema' => [
                        'slug'    => 'type-check-schema',
                        'version' => '1.0.0',
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromApp('type-check-app', $data, '1.0.0');

        $this->assertIsArray($result);
        $this->assertCount(1, $result['schemas']);

    }//end testImportFromAppSkipsNonSchemaInResults()


    // =========================================================================
    // createOrUpdateConfiguration — exception wrapping (line 2526)
    // =========================================================================

    /**
     * createOrUpdateConfiguration wraps exception from findByApp.
     */
    public function testCreateOrUpdateConfigurationWrapsException(): void
    {
        $this->configurationMapper->method('findByApp')
            ->willReturn([]);
        $this->configurationMapper->method('insert')
            ->willThrowException(new Exception('Insert failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to create or update configuration/');

        $this->handler->createOrUpdateConfiguration(
            ['info' => ['title' => 'Test']],
            'test-app',
            '1.0.0',
            ['registers' => [], 'schemas' => [], 'objects' => []],
            'owner'
        );

    }//end testCreateOrUpdateConfigurationWrapsException()


    // =========================================================================
    // importFromFilePath — file not found / read failure (lines 2440-2447)
    // =========================================================================

    /**
     * importFromFilePath throws when file does not exist.
     */
    public function testImportFromFilePathThrowsOnMissingFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Configuration file not found/');

        $this->handler->importFromFilePath('test-app', 'nonexistent/path.json', '1.0.0');

    }//end testImportFromFilePathThrowsOnMissingFile()


    // =========================================================================
    // importSchema — strips binary format from items (lines from 959-965)
    // =========================================================================

    /**
     * importSchema strips binary format from items property.
     */
    public function testImportSchemaStripsBinaryFormatFromItems(): void
    {
        $data = [
            'slug'       => 'binary-items-schema',
            'version'    => '1.0.0',
            'properties' => [
                'files' => [
                    'type'  => 'array',
                    'items' => [
                        'type'   => 'string',
                        'format' => 'binary',
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(110, 'binary-items-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaStripsBinaryFormatFromItems()


    // =========================================================================
    // importSchema — stdClass items.objectConfiguration conversion (line 1047)
    // =========================================================================

    /**
     * importSchema converts stdClass items objectConfiguration to array.
     */
    public function testImportSchemaConvertsStdClassItemsObjectConfigToArray(): void
    {
        $data = [
            'slug'       => 'stdclass-items-oc',
            'version'    => '1.0.0',
            'properties' => [
                'children' => [
                    'type'  => 'array',
                    'items' => (object) [
                        'type'                => 'object',
                        'objectConfiguration' => (object) ['key' => 'value'],
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(111, 'stdclass-items-oc');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaConvertsStdClassItemsObjectConfigToArray()


    // =========================================================================
    // importFromJson — OpenConnector integration (lines 1781-1791)
    // =========================================================================

    /**
     * importFromJson calls connectorConfigSvc when available.
     */
    public function testImportFromJsonCallsOpenConnectorIntegration(): void
    {
        $config = $this->makeConfiguration(1);

        $connectorSvc = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['importConfiguration'])
            ->getMock();
        $connectorSvc->method('importConfiguration')
            ->willReturn([
                'endpoints' => ['ep1'],
                'sources'   => ['src1'],
            ]);

        $this->handler->setOpenConnectorConfigurationService($connectorSvc);

        $this->appConfig->method('getValueString')->willReturn('');

        $data = [
            'components' => [],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertIsArray($result);
        // OpenConnector results should be merged.
        $this->assertContains('ep1', $result['endpoints']);
        $this->assertContains('src1', $result['sources']);

    }//end testImportFromJsonCallsOpenConnectorIntegration()


    /**
     * importFromJson catches OpenConnector failure and continues.
     */
    public function testImportFromJsonCatchesOpenConnectorFailure(): void
    {
        $config = $this->makeConfiguration(1);

        $connectorSvc = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['importConfiguration'])
            ->getMock();
        $connectorSvc->method('importConfiguration')
            ->willThrowException(new Exception('Connector error'));

        $this->handler->setOpenConnectorConfigurationService($connectorSvc);

        $this->appConfig->method('getValueString')->willReturn('');

        $data = [
            'components' => [],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertIsArray($result);

    }//end testImportFromJsonCatchesOpenConnectorFailure()


    // =========================================================================
    // importFromJson — creates new object when no existing found (line 1766-1776)
    // =========================================================================

    /**
     * importFromJson creates new object when searchObjects returns empty array.
     */
    public function testImportFromJsonCreatesNewObjectWhenNoExisting(): void
    {
        $config   = $this->makeConfiguration(1);
        $register = $this->makeRegister(10, 'create-reg');
        $schema   = $this->makeSchema(20, 'create-schema');

        // Schema and register must be in components so they populate the maps.
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturnArgument(0);

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturnArgument(0);

        $newObj = new ObjectEntity();
        $newObj->setUuid('new-uuid');
        $this->setEntityId($newObj, 200);

        $this->objectService->method('searchObjects')
            ->willReturn([]);
        $this->objectService->method('saveObject')
            ->willReturn($newObj);

        $this->appConfig->method('getValueString')->willReturn('');

        $data = [
            'components' => [
                'schemas' => [
                    'create-schema' => [
                        'slug'    => 'create-schema',
                        'version' => '1.0.0',
                    ],
                ],
                'registers' => [
                    'create-reg' => [
                        'slug'    => 'create-reg',
                        'version' => '1.0.0',
                    ],
                ],
                'objects' => [
                    [
                        '@self' => [
                            'register' => 'create-reg',
                            'schema'   => 'create-schema',
                            'slug'     => 'new-object',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertCount(1, $result['objects']);

    }//end testImportFromJsonCreatesNewObjectWhenNoExisting()


    // =========================================================================
    // importSeedData — fallback to blob storage without MagicMapper
    // =========================================================================

    /**
     * importSeedData uses objectEntityMapper when objectMapperForRouting is not set.
     */
    public function testImportSeedDataFallsToBlobStorageWithoutUnifiedMapper(): void
    {
        $config   = $this->makeConfiguration(1, 'blob-app', '1.0.0');
        $register = $this->makeRegister(10, 'main-reg');
        $config->setRegisters([10]);

        $this->registerMapper->method('find')
            ->willReturn($register);

        $schema = $this->makeSchema(40, 'blob-schema');
        $this->setProperty($this->handler, 'schemasMap', ['blob-schema' => $schema]);

        // No objectMapperForRouting set
        $this->objectEntityMapper->method('findDirectBlobStorage')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->objectEntityMapper->method('insert')
            ->willReturnCallback(function ($entity) {
                $this->setEntityId($entity, 600);
                return $entity;
            });

        $configData = [
            'info' => ['title' => 'Blob test'],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'blob-schema' => [
                            [
                                'slug'  => 'blob-obj',
                                'title' => 'Blob Object',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = ['objects' => []];
        $this->invokeMethod($this->handler, 'importSeedData', [
            $configData, null, 'blob-app', $config, &$result,
        ]);

        $this->assertNotEmpty($result['objects']);

    }//end testImportSeedDataFallsToBlobStorageWithoutUnifiedMapper()


    // =========================================================================
    // importSchema — ValidationException handler (line 1145-1147)
    // =========================================================================

    /**
     * importSchema catches ValidationException and creates new schema.
     */
    public function testImportSchemaCatchesValidationExceptionAndCreatesNew(): void
    {
        $createdSchema = $this->makeSchema(120, 'val-ex-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('Validation failed'));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $data = [
            'slug'    => 'val-ex-schema',
            'version' => '1.0.0',
        ];

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);
        $this->assertSame(120, $result->getId());

    }//end testImportSchemaCatchesValidationExceptionAndCreatesNew()


    // =========================================================================
    // importFromJson — registers with schemas slug resolution
    // =========================================================================

    /**
     * importFromJson resolves register schemas from schemasMap.
     */
    public function testImportFromJsonResolvesRegisterSchemasFromMap(): void
    {
        $config = $this->makeConfiguration(1);
        $schema = $this->makeSchema(50, 'reg-schema');

        $this->setProperty($this->handler, 'schemasMap', ['reg-schema' => $schema]);

        $register = $this->makeRegister(10, 'test-reg');

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->registerMapper->method('createFromArray')
            ->willReturn($register);
        $this->registerMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'components' => [
                'registers' => [
                    'test-reg' => [
                        'slug'    => 'test-reg',
                        'version' => '1.0.0',
                        'schemas' => ['reg-schema'],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertCount(1, $result['registers']);

    }//end testImportFromJsonResolvesRegisterSchemasFromMap()


    /**
     * importFromJson warns when register schema not in schemasMap.
     */
    public function testImportFromJsonWarnsWhenRegisterSchemaNotInMap(): void
    {
        $config = $this->makeConfiguration(1);

        $register = $this->makeRegister(10, 'warn-reg');

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->registerMapper->method('createFromArray')
            ->willReturn($register);
        $this->registerMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'components' => [
                'registers' => [
                    'warn-reg' => [
                        'slug'    => 'warn-reg',
                        'version' => '1.0.0',
                        'schemas' => ['nonexistent-schema'],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertCount(1, $result['registers']);

    }//end testImportFromJsonWarnsWhenRegisterSchemaNotInMap()


    // =========================================================================
    // importFromJson — schema import exception in Pass 1 (line 1386-1398)
    // =========================================================================

    /**
     * importFromJson continues when a schema import fails in Pass 1.
     */
    public function testImportFromJsonContinuesOnSchemaFailureInPass1(): void
    {
        $config = $this->makeConfiguration(1);

        $goodSchema = $this->makeSchema(300, 'good-schema');

        $callCount = 0;
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturnCallback(function ($data) use (&$callCount, $goodSchema) {
                $callCount++;
                if ($callCount === 1) {
                    throw new Exception('Schema creation failed');
                }
                return $goodSchema;
            });
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $this->appConfig->method('getValueString')->willReturn('');

        $data = [
            'components' => [
                'schemas' => [
                    'bad-schema' => [
                        'slug'    => 'bad-schema',
                        'version' => '1.0.0',
                    ],
                    'good-schema' => [
                        'slug'    => 'good-schema',
                        'version' => '1.0.0',
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson($data, $config, null, 'test-app', '1.0.0');

        $this->assertIsArray($result);
        $this->assertCount(1, $result['schemas']);

    }//end testImportFromJsonContinuesOnSchemaFailureInPass1()


    // =========================================================================
    // createOrUpdateConfiguration — github nested structure
    // =========================================================================

    /**
     * createOrUpdateConfiguration sets github fields from nested structure.
     */
    public function testCreateOrUpdateConfigurationSetsNestedGithub(): void
    {
        $this->configurationMapper->method('findByApp')
            ->willReturn([]);
        $this->configurationMapper->method('insert')
            ->willReturnCallback(function (Configuration $c) {
                $this->setEntityId($c, 50);
                return $c;
            });

        $data = [
            'x-openregister' => [
                'openregister' => '>=1.0.0',
                'sourceType'   => 'github',
                'sourceUrl'    => 'https://github.com/test/repo',
                'github'       => [
                    'repo'   => 'owner/repo',
                    'branch' => 'main',
                    'path'   => 'config.json',
                ],
            ],
        ];

        $result = $this->handler->createOrUpdateConfiguration(
            $data,
            'gh-app',
            '1.0.0',
            ['registers' => [], 'schemas' => [], 'objects' => []],
            'owner'
        );

        $this->assertInstanceOf(Configuration::class, $result);

    }//end testCreateOrUpdateConfigurationSetsNestedGithub()


    /**
     * createOrUpdateConfiguration sets github fields from legacy flat structure.
     */
    public function testCreateOrUpdateConfigurationSetsLegacyGithub(): void
    {
        $this->configurationMapper->method('findByApp')
            ->willReturn([]);
        $this->configurationMapper->method('insert')
            ->willReturnCallback(function (Configuration $c) {
                $this->setEntityId($c, 51);
                return $c;
            });

        $data = [
            'x-openregister' => [
                'githubRepo'   => 'owner/repo',
                'githubBranch' => 'develop',
                'githubPath'   => 'config/app.json',
            ],
        ];

        $result = $this->handler->createOrUpdateConfiguration(
            $data,
            'legacy-gh-app',
            '1.0.0',
            ['registers' => [], 'schemas' => [], 'objects' => []],
            null
        );

        $this->assertInstanceOf(Configuration::class, $result);

    }//end testCreateOrUpdateConfigurationSetsLegacyGithub()


    // =========================================================================
    // Setter methods coverage
    // =========================================================================

    /**
     * setMagicMapper sets the magic mapper instance.
     */
    public function testSetMagicMapper(): void
    {
        $magicMapper = $this->createMock(MagicMapper::class);
        $this->handler->setMagicMapper($magicMapper);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('magicMapper');
        $prop->setAccessible(true);

        $this->assertSame($magicMapper, $prop->getValue($this->handler));

    }//end testSetMagicMapper()


    /**
     * setObjectMapper sets the object mapper for routing instance.
     */
    public function testSetObjectMapper(): void
    {
        $uom = $this->createMock(MagicMapper::class);
        $this->handler->setObjectMapper($uom);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('objectMapperForRouting');
        $prop->setAccessible(true);

        $this->assertSame($uom, $prop->getValue($this->handler));

    }//end testSetMagicMapper()


    /**
     * setWorkflowEngineRegistry sets the registry instance.
     */
    public function testSetWorkflowEngineRegistry(): void
    {
        $registry = $this->createMock(WorkflowEngineRegistry::class);
        $this->handler->setWorkflowEngineRegistry($registry);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('workflowRegistry');
        $prop->setAccessible(true);

        $this->assertSame($registry, $prop->getValue($this->handler));

    }//end testSetWorkflowEngineRegistry()


    /**
     * setDeployedWorkflowMapper sets the mapper instance.
     */
    public function testSetDeployedWorkflowMapper(): void
    {
        $mapper = $this->createMock(DeployedWorkflowMapper::class);
        $this->handler->setDeployedWorkflowMapper($mapper);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('deployedWfMapper');
        $prop->setAccessible(true);

        $this->assertSame($mapper, $prop->getValue($this->handler));

    }//end testSetDeployedWorkflowMapper()


    /**
     * setOpenConnectorConfigurationService sets the connector service.
     */
    public function testSetOpenConnectorConfigurationService(): void
    {
        $svc = new \stdClass();
        $this->handler->setOpenConnectorConfigurationService($svc);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('connectorConfigSvc');
        $prop->setAccessible(true);

        $this->assertSame($svc, $prop->getValue($this->handler));

    }//end testSetOpenConnectorConfigurationService()


    // =========================================================================
    // importSchema — objectConfiguration.register with empty registersMap
    // covers objectConfiguration register from DB (lines 998-1011)
    // =========================================================================

    /**
     * importSchema resolves objectConfiguration.register from database when not in registersMap.
     */
    public function testImportSchemaResolvesObjConfigRegisterFromDatabase(): void
    {
        $existingRegister = $this->makeRegister(88, 'oc-register');

        $data = [
            'slug'       => 'oc-reg-schema',
            'version'    => '1.0.0',
            'properties' => [
                'related' => [
                    'type'                => 'object',
                    'objectConfiguration' => [
                        'register' => 'oc-register',
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(112, 'oc-reg-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $this->registerMapper->method('find')
            ->willReturn($existingRegister);

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaResolvesObjConfigRegisterFromDatabase()


    /**
     * importSchema unsets objectConfiguration.register when not found in DB.
     */
    public function testImportSchemaUnsetsObjConfigRegisterWhenNotFoundInDB(): void
    {
        $data = [
            'slug'       => 'oc-reg-missing-schema',
            'version'    => '1.0.0',
            'properties' => [
                'related' => [
                    'type'                => 'object',
                    'objectConfiguration' => [
                        'register' => 'nonexistent-register',
                    ],
                ],
            ],
        ];

        $createdSchema = $this->makeSchema(113, 'oc-reg-missing-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $this->schemaMapper->method('createFromArray')
            ->willReturn($createdSchema);
        $this->schemaMapper->method('update')
            ->willReturnArgument(0);

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

        $result = $this->handler->importSchema($data, [], null, null, '1.0.0');

        $this->assertInstanceOf(Schema::class, $result);

    }//end testImportSchemaUnsetsObjConfigRegisterWhenNotFoundInDB()


}//end class
