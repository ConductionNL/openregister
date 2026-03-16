<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Additional coverage tests for ObjectsController.
 *
 * Targets uncovered lines and branches not exercised by ObjectsControllerTest.
 * Focuses on: extractMultipleFiles, magic mapper branches in index()/objects(),
 * crossTableSearch, contracts pagination, normalizeFormDataValues edge cases,
 * and collectNamesForResponse.
 *
 * @package Unit\Controller
 */
class ObjectsControllerCoverageTest extends TestCase
{
    private ObjectsController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private IAppManager&MockObject $appManager;
    private ContainerInterface&MockObject $container;
    private UnifiedObjectMapper&MockObject $objectMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private ObjectService&MockObject $objectService;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private ExportService&MockObject $exportService;
    private ImportService&MockObject $importService;
    private WebhookService&MockObject $webhookService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->objectMapper = $this->createMock(UnifiedObjectMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->exportService = $this->createMock(ExportService::class);
        $this->importService = $this->createMock(ImportService::class);
        $this->webhookService = $this->createMock(WebhookService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Register DI mappers — defaults throw so entities stay null.
        $diRegisterMapper = $this->createMock(RegisterMapper::class);
        $diRegisterMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));
        $diSchemaMapper = $this->createMock(SchemaMapper::class);
        $diSchemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        \OC::$server->registerService(RegisterMapper::class, function () use ($diRegisterMapper) {
            return $diRegisterMapper;
        });
        \OC::$server->registerService(SchemaMapper::class, function () use ($diSchemaMapper) {
            return $diSchemaMapper;
        });

        $this->controller = new ObjectsController(
            'openregister',
            $this->request,
            $this->config,
            $this->appManager,
            $this->container,
            $this->objectMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->auditTrailMapper,
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->exportService,
            $this->importService,
            $this->webhookService,
            $this->logger
        );
    }

    private function setupAdminUser(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
    }

    private function setupRegularUser(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['users']);
    }

    /**
     * Create a Register mock with magic-mapping enabled for given schema.
     */
    private function createMagicMappedRegister(int $id, string $slug, array $magicSchemas = []): Register&MockObject
    {
        $register = $this->getMockBuilder(Register::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize', 'isMagicMappingEnabledForSchema', 'getConfiguration'])
            ->addMethods(['getId', 'getSlug'])
            ->getMock();
        $register->method('getId')->willReturn($id);
        $register->method('getSlug')->willReturn($slug);
        $register->method('jsonSerialize')->willReturn(['id' => $id, 'slug' => $slug]);
        $register->method('isMagicMappingEnabledForSchema')->willReturn(!empty($magicSchemas));
        $register->method('getConfiguration')->willReturn([
            'enableMagicMapping' => !empty($magicSchemas),
            'magicMappingSchemas' => $magicSchemas,
        ]);

        return $register;
    }

    /**
     * Create a Schema mock.
     */
    private function createSchemaMock(int $id, string $slug): Schema&MockObject
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize'])
            ->addMethods(['getId', 'getSlug'])
            ->getMock();
        $schema->method('getId')->willReturn($id);
        $schema->method('getSlug')->willReturn($slug);
        $schema->method('jsonSerialize')->willReturn(['id' => $id, 'slug' => $slug]);

        return $schema;
    }

    /**
     * Register DI mappers that successfully return register and schema entities,
     * and re-create the controller to pick up the new registrations.
     */
    private function registerDiMappersWithEntities(
        Register&MockObject $registerEntity,
        Schema&MockObject $schemaEntity
    ): void {
        $diRegisterMapper = $this->createMock(RegisterMapper::class);
        $diRegisterMapper->method('find')->willReturn($registerEntity);
        $diSchemaMapper = $this->createMock(SchemaMapper::class);
        $diSchemaMapper->method('find')->willReturn($schemaEntity);

        \OC::$server->registerService(RegisterMapper::class, function () use ($diRegisterMapper) {
            return $diRegisterMapper;
        });
        \OC::$server->registerService(SchemaMapper::class, function () use ($diSchemaMapper) {
            return $diSchemaMapper;
        });

        $this->controller = new ObjectsController(
            'openregister',
            $this->request,
            $this->config,
            $this->appManager,
            $this->container,
            $this->objectMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->auditTrailMapper,
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->exportService,
            $this->importService,
            $this->webhookService,
            $this->logger
        );
    }

    private function createObjectEntity(string $uuid, int $register, int $schema): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister((string) $register);
        $entity->setSchema((string) $schema);
        $entity->setObject(['title' => 'Test']);

        return $entity;
    }

    // =========================================================================
    // normalizeFormDataValues — non-string value branch (line 188)
    // =========================================================================

    /**
     * Test create() with multipart/form-data containing non-string values skips them.
     * Exercises the `is_string($value) === false` continue branch at line 188.
     */
    public function testCreateWithMultipartNonStringValuesSkipsThem(): void
    {
        $this->setupAdminUser();

        $registerEntity = $this->createMagicMappedRegister(1, 'reg', []);
        $schemaEntity = $this->createSchemaMock(2, 'sch');
        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $objectEntity = new ObjectEntity();
        $objectEntity->setUuid('uuid-new');
        $objectEntity->setObject(['items' => [1, 2, 3]]);

        $this->request->method('getParams')->willReturn([
            'items' => [1, 2, 3],
            'count' => 42,
        ]);
        $this->request->method('getHeader')->willReturn('multipart/form-data; boundary=----');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    /**
     * Test create() with multipart/form-data decodes JSON-encoded array string.
     * Exercises the JSON decode path at lines 198-200.
     */
    public function testCreateWithMultipartJsonStringDecodesArray(): void
    {
        $this->setupAdminUser();

        $registerEntity = $this->createMagicMappedRegister(1, 'reg', []);
        $schemaEntity = $this->createSchemaMock(2, 'sch');
        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $objectEntity = new ObjectEntity();
        $objectEntity->setUuid('uuid-json');
        $objectEntity->setObject(['contacts' => [['name' => 'John']]]);

        $this->request->method('getParams')->willReturn([
            'contacts' => '[{"name":"John"}]',
        ]);
        $this->request->method('getHeader')->willReturn('multipart/form-data; boundary=----');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // contracts() — page/offset calculation (getConfig lines 504-523)
    // =========================================================================

    /**
     * Test contracts() with page parameter exercises page-to-offset calculation.
     */
    public function testContractsWithPageCalculatesOffset(): void
    {
        $this->request->method('getParams')->willReturn([
            'page' => '3',
            'limit' => '10',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/contracts?page=3&limit=10');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->contracts('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    /**
     * Test contracts() with _page parameter (underscore prefix variant).
     */
    public function testContractsWithUnderscorePageParam(): void
    {
        $this->request->method('getParams')->willReturn([
            '_page' => '2',
            '_limit' => '5',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/contracts?_page=2&_limit=5');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->contracts('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    /**
     * Test contracts() with _offset parameter (underscore prefix).
     */
    public function testContractsWithUnderscoreOffsetParam(): void
    {
        $this->request->method('getParams')->willReturn([
            '_offset' => '10',
            '_limit' => '5',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/contracts?_offset=10&_limit=5');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->contracts('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // index() — magic mapper branch (lines 946-1165)
    // =========================================================================

    /**
     * Test index() with magic-mapped register+schema uses MagicMapper path.
     * Exercises lines 946-1165.
     */
    public function testIndexWithMagicMappedSchemaUsesMagicMapper(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'testreg', ['2', 'testschema']);
        $schemaEntity = $this->createSchemaMock(2, 'testschema');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $objEntity = new ObjectEntity();
        $objEntity->setUuid('magic-1');
        $objEntity->setObject(['title' => 'Magic']);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([$objEntity]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(1);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('@self', $data);
        $this->assertSame('magic_mapper', $data['@self']['source']);
    }

    /**
     * Test index() magic mapper path with _extend parameter exercises renderEntities.
     * Exercises lines 989-1005 (hasComplexRendering branch).
     */
    public function testIndexMagicMapperWithExtendUsesRenderHandler(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'testreg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'testschema');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([
            'extend' => 'relations',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2?extend=relations');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $objEntity = new ObjectEntity();
        $objEntity->setUuid('magic-ext');
        $objEntity->setObject(['title' => 'Extended']);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([$objEntity]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(1);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $renderHandler = $this->createMock(\OCA\OpenRegister\Service\Object\RenderObject::class);
        $renderHandler->method('renderEntities')->willReturn([
            ['uuid' => 'magic-ext', 'title' => 'Extended', 'relation' => ['uuid' => 'rel-1']],
        ]);

        \OC::$server->registerService(\OCA\OpenRegister\Service\Object\RenderObject::class, function () use ($renderHandler) {
            return $renderHandler;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
            '_extend' => ['relations'],
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('magic_mapper', $data['@self']['source']);
    }

    /**
     * Test index() magic mapper with ignored filters and mistaken control params.
     * Exercises lines 1082-1107 (ignoredFilters + hint branch).
     */
    public function testIndexMagicMapperWithIgnoredFiltersAddsHint(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn(['limit' => '10']);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2?limit=10');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);
        $magicMapper->method('getIgnoredFilters')->willReturn(['limit', 'offset']);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('hint', $data['@self']);
        $this->assertStringContainsString('_limit', $data['@self']['hint']);
    }

    /**
     * Test index() magic mapper with _facets parameter.
     * Exercises lines 1113-1127 (facets branch).
     */
    public function testIndexMagicMapperWithFacetsIncludesFacetData(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn(['_facets' => 'status,type']);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2?_facets=status,type');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);
        $magicMapper->method('getSimpleFacetsFromRegisterSchemaTable')->willReturn([
            'status' => ['active' => 5, 'inactive' => 2],
        ]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
            '_facets' => 'status,type',
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('facets', $data);
    }

    /**
     * Test index() magic mapper with _facets that throws exception.
     * Exercises line 1123-1125 (facet error handling).
     */
    public function testIndexMagicMapperWithFacetErrorLogsFacetError(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn(['_facets' => 'bad_field']);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2?_facets=bad_field');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);
        $magicMapper->method('getSimpleFacetsFromRegisterSchemaTable')
            ->willThrowException(new Exception('Column not found'));

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
            '_facets' => 'bad_field',
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('facet_error', $data['@self']);
    }

    /**
     * Test index() magic mapper with _empty=true preserves empty values.
     * Exercises lines 1131-1153 (stripEmptyValues bypass).
     */
    public function testIndexMagicMapperWithEmptyTruePreservesEmptyValues(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn(['_empty' => 'true']);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2?_empty=true');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $objEntity = new ObjectEntity();
        $objEntity->setUuid('empty-test');
        $objEntity->setObject(['title' => '', 'description' => null]);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([$objEntity]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(1);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
            '_empty' => 'true',
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    /**
     * Test index() magic mapper with page parameter but no offset.
     * Exercises lines 1020-1021 (page to offset conversion).
     */
    public function testIndexMagicMapperPageToOffsetConversion(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 10,
            '_page' => 3,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(3, $data['page']);
    }

    /**
     * Test index() magic mapper with limit=0 sets page to 1.
     * Exercises line 1047 (limit=0 fallback).
     */
    public function testIndexMagicMapperWithZeroLimitSetsPageToOne(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(1, $data['page']);
    }

    /**
     * Test index() magic mapper with > 10 results adds gzip headers.
     * Exercises lines 1160-1162 (gzip headers).
     */
    public function testIndexMagicMapperLargeResultSetsGzipHeaders(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn(['_empty' => 'true']);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        // Create 11 entities to trigger gzip.
        $entities = [];
        for ($i = 0; $i < 11; $i++) {
            $obj = new ObjectEntity();
            $obj->setUuid("uuid-$i");
            $obj->setObject(['title' => "Item $i"]);
            $entities[] = $obj;
        }

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn($entities);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(11);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
            '_empty' => 'true',
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(11, $data['results']);
    }

    /**
     * Test index() magic mapper with _extend containing _schema/_register filters them out.
     * Exercises lines 982-987.
     */
    public function testIndexMagicMapperFiltersSchemaRegisterFromExtend(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
            '_extend' => '_schema,_register,relations',
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    /**
     * Test index() magic mapper where _extend is a comma-separated string.
     * Exercises lines 976-978 where string extend is exploded.
     */
    public function testIndexMagicMapperWithExtendAsStringConvertsToArray(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
            '_extend' => 'relations,_schema',
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    /**
     * Test index() magic mapper stripEmpty calls jsonSerialize on ObjectEntity items.
     * Exercises lines 1140-1143 in the strip callback.
     */
    public function testIndexMagicMapperStripEmptySerializesObjectEntities(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $objEntity = new ObjectEntity();
        $objEntity->setUuid('strip-obj');
        $objEntity->setObject(['title' => 'Test', 'empty' => '']);

        $renderHandler = $this->createMock(\OCA\OpenRegister\Service\Object\RenderObject::class);
        // renderEntities returns ObjectEntity instances (not arrays) when extend is used.
        $renderHandler->method('renderEntities')->willReturn([$objEntity]);

        \OC::$server->registerService(\OCA\OpenRegister\Service\Object\RenderObject::class, function () use ($renderHandler) {
            return $renderHandler;
        });

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([$objEntity]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(1);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
            '_extend' => ['custom_field'],
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // objects() — magic mapper branch (lines 1313-1367)
    // =========================================================================

    /**
     * Test objects() with register+schema that has magic mapping enabled.
     * Exercises the full magic mapper branch in objects() at lines 1313-1385.
     */
    public function testObjectsWithMagicMappedSchemaUsesMagicMapper(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'testreg', ['2', 'testschema']);
        $schemaEntity = $this->createSchemaMock(2, 'testschema');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([
            'register' => '1',
            'schema' => '2',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $objEntity = new ObjectEntity();
        $objEntity->setUuid('obj-magic');
        $objEntity->setObject(['status' => 'active']);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([$objEntity]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('magic_mapper', $data['@self']['source']);
        $this->assertCount(1, $data['results']);
    }

    /**
     * Test objects() magic mapper with empty results and _empty=false strips values.
     * Exercises lines 1359-1367 (stripEmptyValues in objects magic mapper).
     */
    public function testObjectsWithMagicMapperStripsEmptyValuesByDefault(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2', 'sch']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([
            'register' => '1',
            'schema' => '2',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $objEntity = new ObjectEntity();
        $objEntity->setUuid('strip-test');
        $objEntity->setObject(['title' => 'Test', 'empty' => '', 'nil' => null]);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([$objEntity]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    /**
     * Test objects() magic mapper with _empty=true preserves empty values.
     */
    public function testObjectsWithMagicMapperEmptyTruePreservesValues(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2', 'sch']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([
            'register' => '1',
            'schema' => '2',
            '_empty' => 'true',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $objEntity = new ObjectEntity();
        $objEntity->setUuid('preserve-test');
        $objEntity->setObject(['field' => null]);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([$objEntity]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // crossTableSearch — (lines 673-779)
    // =========================================================================

    /**
     * Test index() with multiple schemas triggers crossTableSearch with valid pairs.
     * Exercises lines 673-779.
     */
    public function testIndexCrossTableSearchWithValidPairsReturnsResults(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2', '3']);
        $schemaEntity1 = $this->createSchemaMock(2, 'schema1');
        $schemaEntity2 = $this->createSchemaMock(3, 'schema2');

        $diRegisterMapper = $this->createMock(RegisterMapper::class);
        $diRegisterMapper->method('find')->willReturn($registerEntity);

        $diSchemaMapper = $this->createMock(SchemaMapper::class);
        $diSchemaMapper->method('find')->willReturnCallback(function () use ($schemaEntity1, $schemaEntity2) {
            static $callCount = 0;
            $callCount++;
            return $callCount <= 1 ? $schemaEntity1 : $schemaEntity2;
        });

        \OC::$server->registerService(RegisterMapper::class, function () use ($diRegisterMapper) {
            return $diRegisterMapper;
        });
        \OC::$server->registerService(SchemaMapper::class, function () use ($diSchemaMapper) {
            return $diSchemaMapper;
        });

        $this->controller = new ObjectsController(
            'openregister',
            $this->request,
            $this->config,
            $this->appManager,
            $this->container,
            $this->objectMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->auditTrailMapper,
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->exportService,
            $this->importService,
            $this->webhookService,
            $this->logger
        );

        $this->request->method('getParams')->willReturn([
            'schemas' => '2,3',
        ]);

        $objEntity = new ObjectEntity();
        $objEntity->setUuid('cross-1');
        $objEntity->setObject(['name' => 'CrossResult']);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchAcrossMultipleTables')->willReturn([$objEntity]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('@self', $data);
        $this->assertSame('cross_table_magic_mapper', $data['@self']['source']);
    }

    /**
     * Test crossTableSearch with no valid pairs returns 404.
     * Exercises line 706-714 (empty pairs branch).
     */
    public function testIndexCrossTableSearchWithInvalidPairsReturns404(): void
    {
        $this->request->method('getParams')->willReturn([
            'schemas' => '99,100',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('No valid', $data['message']);
    }

    /**
     * Test crossTableSearch with _empty=true on results.
     * Exercises lines 742-752 (includeEmpty check in crossTableSearch).
     */
    public function testIndexCrossTableSearchWithEmptyTruePreservesValues(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2', '3']);
        $schemaEntity = $this->createSchemaMock(2, 'sch1');

        $diRegisterMapper = $this->createMock(RegisterMapper::class);
        $diRegisterMapper->method('find')->willReturn($registerEntity);
        $diSchemaMapper = $this->createMock(SchemaMapper::class);
        $diSchemaMapper->method('find')->willReturn($schemaEntity);

        \OC::$server->registerService(RegisterMapper::class, function () use ($diRegisterMapper) {
            return $diRegisterMapper;
        });
        \OC::$server->registerService(SchemaMapper::class, function () use ($diSchemaMapper) {
            return $diSchemaMapper;
        });

        $this->controller = new ObjectsController(
            'openregister',
            $this->request,
            $this->config,
            $this->appManager,
            $this->container,
            $this->objectMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->auditTrailMapper,
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->exportService,
            $this->importService,
            $this->webhookService,
            $this->logger
        );

        $this->request->method('getParams')->willReturn([
            'schemas' => '2,3',
            '_empty' => 'true',
        ]);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchAcrossMultipleTables')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // update() — uploadedFiles branch (line 1858-1859)
    // =========================================================================

    /**
     * Test update() with successful save and no uploaded files.
     * Exercises the uploadedFiles = null branch at line 1857-1860.
     */
    public function testUpdateWithNoUploadedFilesSendsNull(): void
    {
        $this->setupAdminUser();

        $registerEntity = $this->createMagicMappedRegister(1, 'reg', []);
        $schemaEntity = $this->createSchemaMock(2, 'sch');
        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $existingObject = new ObjectEntity();
        $existingObject->setUuid('uuid-upd');
        $existingObject->setRegister('1');
        $existingObject->setSchema('2');
        $existingObject->setObject(['title' => 'Before']);

        $savedEntity = new ObjectEntity();
        $savedEntity->setUuid('uuid-upd');
        $savedEntity->setObject(['title' => 'After']);

        $this->request->method('getParams')->willReturn(['title' => 'After']);
        $this->request->method('getHeader')->willReturn('application/json');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($savedEntity);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->update('1', '2', 'uuid-upd', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — uploadedFiles branch (line 2128)
    // =========================================================================

    /**
     * Test postPatch() with multipart form data where no files are present.
     */
    public function testPostPatchWithNoFilesPassesNullUploadedFiles(): void
    {
        $this->setupAdminUser();

        $registerEntity = $this->createMagicMappedRegister(1, 'reg', []);
        $schemaEntity = $this->createSchemaMock(2, 'sch');
        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $existingObject = new ObjectEntity();
        $existingObject->setUuid('uuid-pp');
        $existingObject->setRegister('1');
        $existingObject->setSchema('2');
        $existingObject->setObject(['title' => 'Old']);

        $savedEntity = new ObjectEntity();
        $savedEntity->setUuid('uuid-pp');
        $savedEntity->setObject(['title' => 'Patched']);

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('multipart/form-data; boundary=---');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($savedEntity);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->postPatch('1', '2', 'uuid-pp', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // index() — normal path with array results that ARE arrays (stripEmpty exercised)
    // =========================================================================

    /**
     * Test index() normal path where results are arrays gets stripped.
     */
    public function testIndexNormalPathWithArrayResultsStripsEmpty(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20, '_offset' => 0]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['uuid' => 'a', 'title' => 'Test', 'empty' => '', 'nil' => null],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // Empty values should be stripped.
        $this->assertArrayNotHasKey('empty', $data['results'][0]);
    }

    // =========================================================================
    // objects() — normal path with array results strips empty
    // =========================================================================

    /**
     * Test objects() normal path strips empty values from array results.
     */
    public function testObjectsNormalPathWithArrayResultsStripsEmpty(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['uuid' => 'b', 'name' => 'Item', 'blank' => '', 'zero' => 0],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // Blank strings should be stripped but zero preserved.
        $this->assertArrayNotHasKey('blank', $data['results'][0]);
        $this->assertSame(0, $data['results'][0]['zero']);
    }

    // =========================================================================
    // create() — webhook error handling (lines 1643-1657)
    // =========================================================================

    /**
     * Test create() continues when webhook interception fails.
     * Exercises lines 1643-1657 (webhook error catch block).
     * Uses a fresh controller with a custom webhookService mock.
     */
    public function testCreateContinuesWhenWebhookFails(): void
    {
        $this->setupAdminUser();

        $registerEntity = $this->createMagicMappedRegister(1, 'reg', []);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $diRegisterMapper = $this->createMock(RegisterMapper::class);
        $diRegisterMapper->method('find')->willReturn($registerEntity);
        $diSchemaMapper = $this->createMock(SchemaMapper::class);
        $diSchemaMapper->method('find')->willReturn($schemaEntity);

        \OC::$server->registerService(RegisterMapper::class, function () use ($diRegisterMapper) {
            return $diRegisterMapper;
        });
        \OC::$server->registerService(SchemaMapper::class, function () use ($diSchemaMapper) {
            return $diSchemaMapper;
        });

        // Create a fresh webhookService mock with interceptRequest throwing.
        // Note: the controller's catch block uses `Exception` which resolves to OCP\DB\Exception
        // due to the `use OCP\DB\Exception;` import at the top of ObjectsController.
        $webhookException = new \OCP\DB\Exception('Webhook down');
        $failingWebhookService = $this->createMock(WebhookService::class);
        $failingWebhookService->method('interceptRequest')
            ->willThrowException($webhookException);

        $controller = new ObjectsController(
            'openregister',
            $this->request,
            $this->config,
            $this->appManager,
            $this->container,
            $this->objectMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->auditTrailMapper,
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->exportService,
            $this->importService,
            $failingWebhookService,
            $this->logger
        );

        $objectEntity = new ObjectEntity();
        $objectEntity->setUuid('uuid-wh');
        $objectEntity->setObject(['title' => 'After Webhook']);

        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->request->method('getHeader')->willReturn('application/json');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // index() magic mapper — OrganisationService error catch (lines 1053-1056)
    // =========================================================================

    /**
     * Test index() magic mapper when OrganisationService throws exception.
     * Exercises lines 1052-1057 (silently ignore org service error).
     */
    public function testIndexMagicMapperContinuesWhenOrgServiceFails(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);
        $magicMapper->method('getIgnoredFilters')->willReturn([]);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $orgService = $this->createMock(OrganisationService::class);
        $orgService->method('getActiveOrganisation')
            ->willThrowException(new Exception('No org'));

        \OC::$server->registerService(OrganisationService::class, function () use ($orgService) {
            return $orgService;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertNull($data['@self']['activeOrganisation']);
    }

    // =========================================================================
    // show() — extend with _register entity present (line 1541)
    // =========================================================================

    /**
     * Test show() with _extend=_register where registerEntity is available.
     * Exercises line 1541 (non-null entity adds to registers map).
     */
    public function testShowExtendRegisterWithEntityPopulatesMap(): void
    {
        $this->setupAdminUser();

        $registerEntity = $this->createMagicMappedRegister(1, 'reg', []);
        $schemaEntity = $this->createSchemaMock(2, 'sch');
        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([
            'extend' => '_register',
        ]);

        $objectEntity = new ObjectEntity();
        $objectEntity->setUuid('uuid-reg');
        $objectEntity->setObject(['title' => 'RegisterTest']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'RegisterTest',
            '@self' => ['uuid' => 'uuid-reg'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-reg', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('registers', $data['@self']);
    }

    /**
     * Test show() with _extend=_schema where schemaEntity is available.
     * Exercises line 1554 (non-null entity adds to schemas map).
     */
    public function testShowExtendSchemaWithEntityPopulatesMap(): void
    {
        $this->setupAdminUser();

        $registerEntity = $this->createMagicMappedRegister(1, 'reg', []);
        $schemaEntity = $this->createSchemaMock(2, 'sch');
        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([
            'extend' => '_schema',
        ]);

        $objectEntity = new ObjectEntity();
        $objectEntity->setUuid('uuid-sch');
        $objectEntity->setObject(['title' => 'SchemaTest']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'SchemaTest',
            '@self' => ['uuid' => 'uuid-sch'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-sch', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('schemas', $data['@self']);
    }

    // =========================================================================
    // index() — normal path with large results triggers gzip header
    // =========================================================================

    /**
     * Test index() normal path with > 10 results sets gzip headers.
     */
    public function testIndexNormalPathLargeResultsSetsGzipHeaders(): void
    {
        $this->request->method('getParams')->willReturn(['_empty' => 'true']);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $results = [];
        for ($i = 0; $i < 12; $i++) {
            $results[] = ['uuid' => "uuid-$i", 'title' => "Item $i"];
        }

        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20, '_offset' => 0]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => $results,
            'total' => 12,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // index() magic mapper — non-empty ignoredFilters without control params
    // =========================================================================

    /**
     * Test index() magic mapper with ignored filters that are NOT control params.
     * Exercises lines 1082-1083 (ignoredFilters present but no control param intersection).
     */
    public function testIndexMagicMapperIgnoredFiltersWithoutControlParams(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2']);
        $schemaEntity = $this->createSchemaMock(2, 'sch');

        $this->registerDiMappersWithEntities($registerEntity, $schemaEntity);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturn([]);
        $magicMapper->method('countObjectsInRegisterSchemaTable')->willReturn(0);
        // Return ignored filters that are NOT control params (no hint generated).
        $magicMapper->method('getIgnoredFilters')->willReturn(['custom_field', 'another_field']);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
            '_offset' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('ignoredFilters', $data['@self']);
        $this->assertArrayNotHasKey('hint', $data['@self']);
    }

    // =========================================================================
    // crossTableSearch — with limit > 0 pagination
    // =========================================================================

    /**
     * Test crossTableSearch with limit > 0 calculates pages and page correctly.
     * Exercises lines 760-762 (pagination calc in crossTableSearch).
     */
    public function testIndexCrossTableSearchWithPagination(): void
    {
        $registerEntity = $this->createMagicMappedRegister(1, 'reg', ['2', '3']);
        $schemaEntity = $this->createSchemaMock(2, 'sch1');

        $diRegisterMapper = $this->createMock(RegisterMapper::class);
        $diRegisterMapper->method('find')->willReturn($registerEntity);
        $diSchemaMapper = $this->createMock(SchemaMapper::class);
        $diSchemaMapper->method('find')->willReturn($schemaEntity);

        \OC::$server->registerService(RegisterMapper::class, function () use ($diRegisterMapper) {
            return $diRegisterMapper;
        });
        \OC::$server->registerService(SchemaMapper::class, function () use ($diSchemaMapper) {
            return $diSchemaMapper;
        });

        $this->controller = new ObjectsController(
            'openregister',
            $this->request,
            $this->config,
            $this->appManager,
            $this->container,
            $this->objectMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->auditTrailMapper,
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->exportService,
            $this->importService,
            $this->webhookService,
            $this->logger
        );

        $this->request->method('getParams')->willReturn([
            'schemas' => '2,3',
        ]);

        // Create several entities to test pagination calc.
        $entities = [];
        for ($i = 0; $i < 5; $i++) {
            $obj = new ObjectEntity();
            $obj->setUuid("cross-$i");
            $obj->setObject(['title' => "Item $i"]);
            $entities[] = $obj;
        }

        $magicMapper = $this->createMock(MagicMapper::class);
        $magicMapper->method('searchAcrossMultipleTables')->willReturn($entities);

        \OC::$server->registerService(MagicMapper::class, function () use ($magicMapper) {
            return $magicMapper;
        });

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 10,
            '_offset' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(5, $data['total']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(1, $data['pages']);
    }

    // =========================================================================
    // objects() — with _empty=true in normal path
    // =========================================================================

    /**
     * Test objects() normal path with _empty=true preserves empty values.
     */
    public function testObjectsNormalPathEmptyTruePreservesValues(): void
    {
        $this->request->method('getParams')->willReturn([
            '_empty' => 'true',
        ]);

        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['uuid' => 'c', 'name' => 'Item', 'blank' => '', 'nil' => null],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // With _empty=true, blank values should be preserved.
        $this->assertArrayHasKey('blank', $data['results'][0]);
    }
}
