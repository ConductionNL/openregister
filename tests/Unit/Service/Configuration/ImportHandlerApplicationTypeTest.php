<?php

/**
 * ImportHandler Application-Type Auto-Register Unit Tests
 *
 * Covers the data-import-export spec delta requirement:
 * "importFromApp auto-creates a Register for application-type configurations"
 *
 * Drives the private helper `autoCreateRegisterIfApplication()` directly via
 * reflection so the test stays focused on the spec contract without dragging
 * the full importFromJson orchestrator into scope.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ImportHandler::autoCreateRegisterIfApplication().
 *
 * Exercises the data-import-export spec delta:
 *  - First import with x-openregister.type=application creates Register
 *  - Re-import on same (slug, organisation) is idempotent
 *  - Resulting Register schemas[] is updated with imported schema IDs
 */
class ImportHandlerApplicationTypeTest extends TestCase
{

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
     * Construct the ImportHandler with every dependency mocked.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->objectEntityMapper  = $this->createMock(MagicMapper::class);
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


    /**
     * Invoke the private autoCreateRegisterIfApplication helper.
     *
     * @param array         $data          OAS document.
     * @param string        $appId         App identifier.
     * @param array         $schemas       Schema entities.
     * @param Configuration $configuration Configuration entity.
     * @param array         $result        Mutable result array (passed by ref).
     */
    private function invokeAutoCreate(
        array $data,
        string $appId,
        array $schemas,
        Configuration $configuration,
        array &$result
    ): void {
        $ref    = new ReflectionClass($this->handler);
        $method = $ref->getMethod('autoCreateRegisterIfApplication');
        $method->setAccessible(true);

        // Reflection cannot pass a non-array by reference through invokeArgs in
        // a way that mutates the caller; use the underlying closure binding to
        // preserve pass-by-reference semantics for $result.
        $closure = $method->getClosure($this->handler);
        $closure($data, $appId, $schemas, $configuration, $result);

    }//end invokeAutoCreate()


    /**
     * Build a Schema entity with an injected ID (Entity::__call cannot accept
     * named args reliably; reflection is the safe path).
     */
    private function makeSchema(int $id, string $slug): Schema
    {
        $schema = new Schema();
        $schema->setSlug($slug);
        $schema->setTitle($slug);

        $ref  = new ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);

        return $schema;

    }//end makeSchema()


    /**
     * Build a Register entity with an injected ID.
     */
    private function makeRegister(int $id, string $slug, array $schemaIds = []): Register
    {
        $register = new Register();
        $register->setSlug($slug);
        $register->setTitle($slug);
        $register->setSchemas($schemaIds);

        $ref  = new ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);

        return $register;

    }//end makeRegister()


    /**
     * REQ + SCENARIO: "Application import without an existing register".
     *
     * When importFromApp processes a config with x-openregister.type=application
     * and the (slug, org) tuple has no existing Register, a fresh row is
     * inserted via RegisterMapper::createFromArray and the schemas[] field
     * carries every imported schema ID.
     */
    public function testFirstImportCreatesRegisterWithSchemaIds(): void
    {
        $data = [
            'info'           => [
                'title'       => 'OpenBuilt',
                'description' => 'Citizen developer surface',
            ],
            'x-openregister' => [
                'type' => 'application',
                'app'  => 'openbuilt',
            ],
        ];

        $schemas = [
            $this->makeSchema(101, 'application'),
            $this->makeSchema(102, 'component'),
            $this->makeSchema(103, 'page'),
        ];

        // No existing Register on first import — lookup by slug only.
        $this->registerMapper
            ->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(1),
                $this->equalTo(0),
                $this->equalTo(['slug' => 'openbuilt'])
            )
            ->willReturn([]);

        // Insert is the canonical path on a fresh import.
        $newRegister = $this->makeRegister(7, 'openbuilt', [101, 102, 103]);
        $this->registerMapper
            ->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function (array $obj): bool {
                return $obj['slug'] === 'openbuilt'
                    && $obj['title'] === 'OpenBuilt'
                    && $obj['description'] === 'Citizen developer surface'
                    && $obj['schemas'] === [101, 102, 103]
                    && $obj['source'] === 'import';
            }))
            ->willReturn($newRegister);

        // No update on the fresh path.
        $this->registerMapper->expects($this->never())->method('update');

        $configuration = new Configuration();
        $configuration->setRegisters([]);
        $result        = ['registers' => [], 'schemas' => $schemas];

        $this->invokeAutoCreate($data, 'openbuilt', $schemas, $configuration, $result);

        // The new register is surfaced in the import result.
        $this->assertCount(1, $result['registers']);
        $this->assertSame($newRegister, $result['registers'][0]);

        // Configuration's registers[] now references the new Register ID.
        $this->assertSame([7], $configuration->getRegisters());

    }//end testFirstImportCreatesRegisterWithSchemaIds()


    /**
     * REQ + SCENARIO: "Application re-import on the same organisation".
     *
     * Re-running the same import MUST find the existing Register by slug
     * (idempotent on (slug, organisationId)), reconcile schemas[] without
     * inserting duplicates, and MUST NOT call createFromArray a second time.
     */
    public function testReimportIsIdempotentOnSlug(): void
    {
        $data = [
            'info'           => ['title' => 'OpenBuilt'],
            'x-openregister' => [
                'type' => 'application',
                'app'  => 'openbuilt',
            ],
        ];

        // Re-import surfaces the same 3 schema IDs as the first run.
        $schemas = [
            $this->makeSchema(101, 'application'),
            $this->makeSchema(102, 'component'),
            $this->makeSchema(103, 'page'),
        ];

        $existingRegister = $this->makeRegister(7, 'openbuilt', [101, 102, 103]);

        $this->registerMapper
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$existingRegister]);

        // Re-import path uses update, NEVER createFromArray.
        $this->registerMapper->expects($this->never())->method('createFromArray');

        $this->registerMapper
            ->expects($this->once())
            ->method('update')
            ->with($this->callback(function (Register $r): bool {
                // schemas[] must be unchanged in size (no duplicates).
                $schemaIds = $r->getSchemas();
                return $r->getId() === 7
                    && $r->getSlug() === 'openbuilt'
                    && count($schemaIds) === 3
                    && in_array(101, $schemaIds, true)
                    && in_array(102, $schemaIds, true)
                    && in_array(103, $schemaIds, true);
            }))
            ->willReturnArgument(0);

        $configuration = new Configuration();
        // Configuration already references the register from the first run.
        $configuration->setRegisters([7]);
        $result        = ['registers' => [], 'schemas' => $schemas];

        $this->invokeAutoCreate($data, 'openbuilt', $schemas, $configuration, $result);

        // The existing register surfaces in the result — no second row.
        $this->assertCount(1, $result['registers']);
        $this->assertSame(7, $result['registers'][0]->getId());

        // configuration->registers[] is still [7] — no duplication.
        $this->assertSame([7], $configuration->getRegisters());

    }//end testReimportIsIdempotentOnSlug()


    /**
     * REQ + SCENARIO: "Library or untyped import".
     *
     * Configs that do NOT declare x-openregister.type=application MUST be
     * skipped entirely — no Register lookup, no insert. Legacy library imports
     * keep their pre-spec behaviour.
     */
    public function testLibraryTypedConfigDoesNotCreateRegister(): void
    {
        $data = [
            'info'           => ['title' => 'OpenBuilt'],
            'x-openregister' => [
                'type' => 'library',
                'app'  => 'openbuilt',
            ],
        ];

        $schemas = [$this->makeSchema(101, 'application')];

        // ZERO mapper calls when type is not 'application'.
        $this->registerMapper->expects($this->never())->method('findAll');
        $this->registerMapper->expects($this->never())->method('createFromArray');
        $this->registerMapper->expects($this->never())->method('update');

        $configuration = new Configuration();
        $configuration->setRegisters([]);
        $result        = ['registers' => [], 'schemas' => $schemas];

        $this->invokeAutoCreate($data, 'openbuilt', $schemas, $configuration, $result);

        $this->assertSame([], $result['registers']);
        $this->assertSame([], $configuration->getRegisters() ?? []);

    }//end testLibraryTypedConfigDoesNotCreateRegister()


}//end class
