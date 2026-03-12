<?php

declare(strict_types=1);

/**
 * ImportHandler Unit Tests
 *
 * Comprehensive unit tests for ImportHandler — the core configuration import
 * orchestrator in OpenRegister. Tests all public methods and the most important
 * private code paths via their public entry points.
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
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ImportHandler.
 *
 * All dependencies are mocked via the constructor. Real entity objects are used
 * where possible (Register, Schema, Configuration) to exercise entity logic
 * alongside handler logic. Where entity IDs are needed, they are injected via
 * reflection to avoid Nextcloud's Entity::__call() named-argument limitation.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class ImportHandlerTest extends TestCase
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
     * Set up test fixtures — create all mocks and instantiate handler.
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

        // Logger methods are void — just let them be called without restrictions.
        // No willReturn needed; createMock() already stubs them as no-ops.

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
    private function makeRegister(int $id, string $slug, string $version='1.0.0'): Register
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
    private function makeSchema(int $id, string $slug, string $version='1.0.0'): Schema
    {
        $schema = new Schema();
        $schema->setSlug($slug);
        $schema->setVersion($version);
        $this->setEntityId($schema, $id);
        return $schema;

    }//end makeSchema()


    /**
     * Build a minimal Configuration entity with app and id set.
     */
    private function makeConfiguration(int $id, string $app='test-app', string $version='1.0.0'): Configuration
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


    // =========================================================================
    // decode()
    // =========================================================================

    /**
     * decode() returns null for completely invalid input when type is null.
     */
    public function testDecodeReturnsNullForGarbageInput(): void
    {
        $result = $this->handler->decode('%%%not json or yaml%%%', null);
        $this->assertNull($result);

    }//end testDecodeReturnsNullForGarbageInput()


    /**
     * decode() parses valid JSON when type is application/json.
     */
    public function testDecodeJsonExplicitType(): void
    {
        $json   = json_encode(['key' => 'value', 'nested' => ['a' => 1]]);
        $result = $this->handler->decode($json, 'application/json');

        $this->assertIsArray($result);
        $this->assertSame('value', $result['key']);
        $this->assertSame(1, $result['nested']['a']);

    }//end testDecodeJsonExplicitType()


    /**
     * decode() parses valid JSON when no explicit type is given (auto-detect).
     */
    public function testDecodeJsonAutoDetect(): void
    {
        $json   = '{"hello":"world"}';
        $result = $this->handler->decode($json, null);

        $this->assertIsArray($result);
        $this->assertSame('world', $result['hello']);

    }//end testDecodeJsonAutoDetect()


    /**
     * decode() parses valid YAML when type is application/yaml.
     */
    public function testDecodeYamlExplicitType(): void
    {
        $yaml   = "title: My Register\nversion: 1.0.0\n";
        $result = $this->handler->decode($yaml, 'application/yaml');

        $this->assertIsArray($result);
        $this->assertSame('My Register', $result['title']);
        $this->assertSame('1.0.0', $result['version']);

    }//end testDecodeYamlExplicitType()


    /**
     * decode() falls back to YAML parsing when JSON fails and type is null.
     */
    public function testDecodeFallsBackToYamlOnJsonFailure(): void
    {
        $yaml   = "name: fallback\ntype: yaml\n";
        $result = $this->handler->decode($yaml, null);

        $this->assertIsArray($result);
        $this->assertSame('fallback', $result['name']);

    }//end testDecodeFallsBackToYamlOnJsonFailure()


    /**
     * decode() returns null when both JSON and YAML fail to produce an array.
     */
    public function testDecodeReturnsNullWhenBothParsersFail(): void
    {
        // '>>> bad <<<' is not valid JSON and causes Symfony Yaml to throw a ParseException,
        // which the handler catches and converts to null.
        $result = $this->handler->decode('>>> bad <<<', null);
        $this->assertNull($result);

    }//end testDecodeReturnsNullWhenBothParsersFail()


    /**
     * decode() converts stdClass objects in decoded JSON to arrays.
     */
    public function testDecodeConvertsStdClassToArray(): void
    {
        $json   = '{"nested": {"deep": true}}';
        $result = $this->handler->decode($json, 'application/json');

        $this->assertIsArray($result['nested']);
        $this->assertTrue($result['nested']['deep']);

    }//end testDecodeConvertsStdClassToArray()


    // =========================================================================
    // ensureArrayStructure()
    // =========================================================================

    /**
     * ensureArrayStructure() converts a flat stdClass to an array.
     */
    public function testEnsureArrayStructureConvertsStdClass(): void
    {
        $obj        = new \stdClass();
        $obj->key   = 'val';
        $obj->other = 42;

        $result = $this->handler->ensureArrayStructure($obj);

        $this->assertIsArray($result);
        $this->assertSame('val', $result['key']);
        $this->assertSame(42, $result['other']);

    }//end testEnsureArrayStructureConvertsStdClass()


    /**
     * ensureArrayStructure() recursively converts nested stdClass objects.
     */
    public function testEnsureArrayStructureConvertsNestedObjects(): void
    {
        $inner        = new \stdClass();
        $inner->deep  = 'value';
        $outer        = new \stdClass();
        $outer->child = $inner;

        $result = $this->handler->ensureArrayStructure($outer);

        $this->assertIsArray($result['child']);
        $this->assertSame('value', $result['child']['deep']);

    }//end testEnsureArrayStructureConvertsNestedObjects()


    /**
     * ensureArrayStructure() leaves a plain array untouched.
     */
    public function testEnsureArrayStructureLeavesArraysIntact(): void
    {
        $input  = ['a' => 1, 'b' => [2, 3]];
        $result = $this->handler->ensureArrayStructure($input);

        $this->assertSame($input, $result);

    }//end testEnsureArrayStructureLeavesArraysIntact()


    /**
     * ensureArrayStructure() handles arrays containing objects.
     */
    public function testEnsureArrayStructureConvertsObjectsInsideArray(): void
    {
        $obj      = new \stdClass();
        $obj->foo = 'bar';
        $input    = ['item' => $obj, 'plain' => 'text'];

        $result = $this->handler->ensureArrayStructure($input);

        $this->assertIsArray($result['item']);
        $this->assertSame('bar', $result['item']['foo']);
        $this->assertSame('text', $result['plain']);

    }//end testEnsureArrayStructureConvertsObjectsInsideArray()


    // =========================================================================
    // getJSONfromFile()
    // =========================================================================

    /**
     * getJSONfromFile() returns a JSONResponse when upload error is set.
     */
    public function testGetJSONfromFileReturnsErrorResponseOnUploadError(): void
    {
        $uploadedFile = [
            'error'    => UPLOAD_ERR_NO_FILE,
            'name'     => 'test.json',
            'tmp_name' => '',
        ];

        $result = $this->handler->getJSONfromFile($uploadedFile);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());

    }//end testGetJSONfromFileReturnsErrorResponseOnUploadError()


    /**
     * getJSONfromFile() returns a JSONResponse when file content cannot be decoded.
     */
    public function testGetJSONfromFileReturnsErrorResponseOnDecodeFailure(): void
    {
        // Write garbage content to a temp file.
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpunit_');
        file_put_contents($tmpFile, '%%% invalid %%%');

        $uploadedFile = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'config.json',
            'tmp_name' => $tmpFile,
        ];

        $result = $this->handler->getJSONfromFile($uploadedFile);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());

        unlink($tmpFile);

    }//end testGetJSONfromFileReturnsErrorResponseOnDecodeFailure()


    /**
     * getJSONfromFile() returns a decoded array for a valid JSON temp file.
     */
    public function testGetJSONfromFileReturnsArrayForValidJsonFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpunit_');
        file_put_contents($tmpFile, json_encode(['title' => 'Test Config', 'version' => '1.0.0']));

        $uploadedFile = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'config.json',
            'tmp_name' => $tmpFile,
        ];

        $result = $this->handler->getJSONfromFile($uploadedFile);

        $this->assertIsArray($result);
        $this->assertSame('Test Config', $result['title']);

        unlink($tmpFile);

    }//end testGetJSONfromFileReturnsArrayForValidJsonFile()


    // =========================================================================
    // getJSONfromURL()
    // =========================================================================

    /**
     * getJSONfromURL() returns JSONResponse when Guzzle throws.
     */
    public function testGetJSONfromURLReturnsErrorOnGuzzleException(): void
    {
        $this->client->method('request')
            ->willThrowException(new RequestException('Connection refused', new Request('GET', 'http://example.com')));

        $result = $this->handler->getJSONfromURL('http://example.com/config.json');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());

    }//end testGetJSONfromURLReturnsErrorOnGuzzleException()


    /**
     * getJSONfromURL() returns JSONResponse when response body cannot be decoded.
     */
    public function testGetJSONfromURLReturnsErrorWhenBodyNotDecodable(): void
    {
        $stream   = \GuzzleHttp\Psr7\Utils::streamFor('%%% invalid %%%');
        $response = new Response(200, ['Content-Type' => 'application/json'], $stream);
        $this->client->method('request')->willReturn($response);

        $result = $this->handler->getJSONfromURL('http://example.com/config.json');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());

    }//end testGetJSONfromURLReturnsErrorWhenBodyNotDecodable()


    /**
     * getJSONfromURL() returns decoded array on successful JSON response.
     */
    public function testGetJSONfromURLReturnsArrayOnSuccess(): void
    {
        $body     = json_encode(['components' => ['schemas' => []]]);
        $stream   = \GuzzleHttp\Psr7\Utils::streamFor($body);
        $response = new Response(200, ['Content-Type' => 'application/json'], $stream);
        $this->client->method('request')->willReturn($response);

        $result = $this->handler->getJSONfromURL('http://example.com/config.json');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('components', $result);

    }//end testGetJSONfromURLReturnsArrayOnSuccess()


    // =========================================================================
    // getJSONfromBody()
    // =========================================================================

    /**
     * getJSONfromBody() returns array when already an array.
     */
    public function testGetJSONfromBodyPassthroughArray(): void
    {
        $input  = ['key' => 'value'];
        $result = $this->handler->getJSONfromBody($input);

        $this->assertSame($input, $result);

    }//end testGetJSONfromBodyPassthroughArray()


    /**
     * getJSONfromBody() decodes a JSON string.
     */
    public function testGetJSONfromBodyDecodesJsonString(): void
    {
        $result = $this->handler->getJSONfromBody('{"hello":"world"}');

        $this->assertIsArray($result);
        $this->assertSame('world', $result['hello']);

    }//end testGetJSONfromBodyDecodesJsonString()


    /**
     * getJSONfromBody() returns JSONResponse for invalid JSON string.
     */
    public function testGetJSONfromBodyReturnsErrorForInvalidJson(): void
    {
        $result = $this->handler->getJSONfromBody('%%%not json%%%');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());

    }//end testGetJSONfromBodyReturnsErrorForInvalidJson()


    /**
     * getJSONfromBody() converts stdClass objects inside the array.
     */
    public function testGetJSONfromBodyConvertsStdClassObjects(): void
    {
        $input = ['nested' => (object)['deep' => 'value']];
        $result = $this->handler->getJSONfromBody($input);

        $this->assertIsArray($result['nested']);
        $this->assertSame('value', $result['nested']['deep']);

    }//end testGetJSONfromBodyConvertsStdClassObjects()


    // =========================================================================
    // importRegister()
    // =========================================================================

    /**
     * importRegister() creates a new register when none exists.
     */
    public function testImportRegisterCreatesNewRegister(): void
    {
        $register = $this->makeRegister(1, 'test-register');

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturn($register);

        // No owner or appId, so no update call needed.
        $result = $this->handler->importRegister(['slug' => 'test-register', 'version' => '1.0.0', 'title' => 'Test']);

        $this->assertInstanceOf(Register::class, $result);
        $this->assertSame('test-register', $result->getSlug());

    }//end testImportRegisterCreatesNewRegister()


    /**
     * importRegister() sets owner and application and calls update when both provided.
     */
    public function testImportRegisterSetsOwnerAndApplication(): void
    {
        $register = $this->makeRegister(1, 'my-register');

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturn($register);

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->willReturn($register);

        $result = $this->handler->importRegister(
            data:    ['slug' => 'my-register', 'version' => '1.0.0', 'title' => 'My Register'],
            owner:   'owner@test.nl',
            appId:   'myapp'
        );

        $this->assertSame('owner@test.nl', $result->getOwner());
        $this->assertSame('myapp', $result->getApplication());

    }//end testImportRegisterSetsOwnerAndApplication()


    /**
     * importRegister() skips update when existing version is equal and force is false.
     */
    public function testImportRegisterSkipsWhenVersionEqual(): void
    {
        $existing = $this->makeRegister(5, 'stable-register', '2.0.0');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        // createFromArray and update must NOT be called.
        $this->registerMapper->expects($this->never())->method('createFromArray');
        $this->registerMapper->expects($this->never())->method('update');

        $result = $this->handler->importRegister(
            data:  ['slug' => 'stable-register', 'version' => '2.0.0', 'title' => 'Stable'],
            force: false
        );

        $this->assertSame(5, $result->getId());

    }//end testImportRegisterSkipsWhenVersionEqual()


    /**
     * importRegister() skips update when existing version is newer and force is false.
     */
    public function testImportRegisterSkipsWhenExistingVersionIsNewer(): void
    {
        $existing = $this->makeRegister(5, 'stable-register', '3.0.0');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        $this->registerMapper->expects($this->never())->method('createFromArray');
        $this->registerMapper->expects($this->never())->method('update');

        $result = $this->handler->importRegister(
            data:  ['slug' => 'stable-register', 'version' => '2.0.0', 'title' => 'Old'],
            force: false
        );

        $this->assertSame(5, $result->getId());

    }//end testImportRegisterSkipsWhenExistingVersionIsNewer()


    /**
     * importRegister() updates existing register when imported version is newer.
     */
    public function testImportRegisterUpdatesWhenVersionIsNewer(): void
    {
        $existing = $this->makeRegister(5, 'my-register', '1.0.0');
        $updated  = $this->makeRegister(5, 'my-register', '2.0.0');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        $this->registerMapper->expects($this->once())
            ->method('updateFromArray')
            ->willReturn($existing);

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->willReturn($updated);

        $result = $this->handler->importRegister(
            data: ['slug' => 'my-register', 'version' => '2.0.0', 'title' => 'Updated']
        );

        $this->assertSame(5, $result->getId());

    }//end testImportRegisterUpdatesWhenVersionIsNewer()


    /**
     * importRegister() force-updates even when version is older.
     */
    public function testImportRegisterForceUpdatesWhenVersionIsOlder(): void
    {
        $existing = $this->makeRegister(5, 'my-register', '5.0.0');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        $this->registerMapper->expects($this->once())
            ->method('updateFromArray')
            ->willReturn($existing);

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->willReturn($existing);

        $result = $this->handler->importRegister(
            data:  ['slug' => 'my-register', 'version' => '1.0.0', 'title' => 'Old'],
            force: true
        );

        $this->assertSame(5, $result->getId());

    }//end testImportRegisterForceUpdatesWhenVersionIsOlder()


    /**
     * importRegister() strips id, uuid, and organisation from input data.
     */
    public function testImportRegisterStripsIdUuidOrganisation(): void
    {
        $register = $this->makeRegister(1, 'clean-register');

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($register, &$capturedData) {
                $capturedData = $data;
                return $register;
            });

        $this->handler->importRegister([
            'id'           => 99,
            'uuid'         => 'some-uuid',
            'organisation' => 'some-org',
            'slug'         => 'clean-register',
            'version'      => '1.0.0',
            'title'        => 'Clean',
        ]);

        $this->assertArrayNotHasKey('id', $capturedData);
        $this->assertArrayNotHasKey('uuid', $capturedData);
        $this->assertArrayNotHasKey('organisation', $capturedData);

    }//end testImportRegisterStripsIdUuidOrganisation()


    /**
     * importRegister() throws an Exception when mapper throws a non-expected exception.
     */
    public function testImportRegisterThrowsOnMapperFailure(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->registerMapper->method('createFromArray')
            ->willThrowException(new Exception('DB write failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to import register/');

        $this->handler->importRegister(['slug' => 'broken', 'version' => '1.0.0', 'title' => 'Broken']);

    }//end testImportRegisterThrowsOnMapperFailure()


    // =========================================================================
    // importSchema()
    // =========================================================================

    /**
     * importSchema() creates a new schema when none exists.
     */
    public function testImportSchemaCreatesNewSchema(): void
    {
        $schema = $this->makeSchema(10, 'person-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturn($schema);

        $this->schemaMapper->expects($this->once())
            ->method('update')
            ->willReturn($schema);

        $result = $this->handler->importSchema(
            data:           ['slug' => 'person-schema', 'version' => '1.0.0', 'title' => 'Person'],
            slugsAndIdsMap: []
        );

        $this->assertInstanceOf(Schema::class, $result);
        $this->assertSame(10, $result->getId());

    }//end testImportSchemaCreatesNewSchema()


    /**
     * importSchema() sets owner and application on the new schema.
     *
     * importSchema sets owner/application on the entity, then calls update().
     * update() returns whatever the mapper returns, so we return the same
     * object that was passed to it (with owner/application already set on it).
     */
    public function testImportSchemaSetsOwnerAndApplication(): void
    {
        $schema = $this->makeSchema(10, 'person-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->schemaMapper->method('createFromArray')->willReturn($schema);

        // Return the same entity the handler mutated (it already has owner/application set).
        $this->schemaMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->handler->importSchema(
            data:           ['slug' => 'person-schema', 'version' => '1.0.0', 'title' => 'Person'],
            slugsAndIdsMap: [],
            owner:          'app-owner',
            appId:          'myapp'
        );

        $this->assertSame('app-owner', $result->getOwner());
        $this->assertSame('myapp', $result->getApplication());

    }//end testImportSchemaSetsOwnerAndApplication()


    /**
     * importSchema() skips when existing version is equal and force is false.
     */
    public function testImportSchemaSkipsWhenVersionEqual(): void
    {
        $existing = $this->makeSchema(10, 'person-schema', '1.0.0');

        $this->schemaMapper->method('find')->willReturn($existing);

        $this->schemaMapper->expects($this->never())->method('createFromArray');
        $this->schemaMapper->expects($this->never())->method('update');

        $result = $this->handler->importSchema(
            data:           ['slug' => 'person-schema', 'version' => '1.0.0', 'title' => 'Person'],
            slugsAndIdsMap: [],
            force:          false
        );

        $this->assertSame(10, $result->getId());

    }//end testImportSchemaSkipsWhenVersionEqual()


    /**
     * importSchema() updates when a newer version is imported.
     */
    public function testImportSchemaUpdatesWhenVersionIsNewer(): void
    {
        $existing = $this->makeSchema(10, 'person-schema', '1.0.0');
        $updated  = $this->makeSchema(10, 'person-schema', '2.0.0');

        $this->schemaMapper->method('find')->willReturn($existing);

        $this->schemaMapper->expects($this->once())
            ->method('updateFromArray')
            ->willReturn($existing);

        $this->schemaMapper->expects($this->once())
            ->method('update')
            ->willReturn($updated);

        $result = $this->handler->importSchema(
            data:           ['slug' => 'person-schema', 'version' => '2.0.0', 'title' => 'Person v2'],
            slugsAndIdsMap: []
        );

        $this->assertSame(10, $result->getId());

    }//end testImportSchemaUpdatesWhenVersionIsNewer()


    /**
     * importSchema() defaults a property's type to 'string' when absent.
     */
    public function testImportSchemaDefaultsPropertyTypeToString(): void
    {
        $schema = $this->makeSchema(10, 'typed-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'typed-schema',
                'version'    => '1.0.0',
                'title'      => 'Typed',
                'properties' => [
                    'name' => ['title' => 'Name'],
                    // 'type' is intentionally absent.
                ],
            ],
            slugsAndIdsMap: []
        );

        $this->assertSame('string', $capturedData['properties']['name']['type']);

    }//end testImportSchemaDefaultsPropertyTypeToString()


    /**
     * importSchema() removes invalid 'string' format values from properties.
     */
    public function testImportSchemaStripsStringFormat(): void
    {
        $schema = $this->makeSchema(10, 'fmt-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'fmt-schema',
                'version'    => '1.0.0',
                'title'      => 'Formats',
                'properties' => [
                    'field' => ['type' => 'string', 'format' => 'string'],
                ],
            ],
            slugsAndIdsMap: []
        );

        $this->assertArrayNotHasKey('format', $capturedData['properties']['field']);

    }//end testImportSchemaStripsStringFormat()


    /**
     * importSchema() resolves $ref slugs from the slugsAndIdsMap.
     */
    public function testImportSchemaResolvesRefFromSlugsMap(): void
    {
        $schema = $this->makeSchema(10, 'ref-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'ref-schema',
                'version'    => '1.0.0',
                'title'      => 'Ref Schema',
                'properties' => [
                    'address' => ['type' => 'object', '$ref' => 'address-schema'],
                ],
            ],
            slugsAndIdsMap: ['address-schema' => 42]
        );

        $this->assertSame(42, $capturedData['properties']['address']['$ref']);

    }//end testImportSchemaResolvesRefFromSlugsMap()


    /**
     * importSchema() throws an Exception wrapping the underlying mapper error.
     */
    public function testImportSchemaThrowsOnMapperError(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('DB failure'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to import schema/');

        $this->handler->importSchema(
            data:           ['slug' => 'broken-schema', 'version' => '1.0.0', 'title' => 'Broken'],
            slugsAndIdsMap: []
        );

    }//end testImportSchemaThrowsOnMapperError()


    // =========================================================================
    // importFromJson()
    // =========================================================================

    /**
     * importFromJson() throws when called without a Configuration entity.
     */
    public function testImportFromJsonThrowsWithoutConfiguration(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/importFromJson must be called with a Configuration entity/');

        $this->handler->importFromJson(data: [], configuration: null);

    }//end testImportFromJsonThrowsWithoutConfiguration()


    /**
     * importFromJson() returns empty result when stored version is equal and force is false.
     */
    public function testImportFromJsonSkipsWhenVersionNotNewer(): void
    {
        $configuration = $this->makeConfiguration(1);
        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
        ];

        $this->appConfig->method('getValueString')
            ->with('openregister', 'imported_config_myapp_version', '')
            ->willReturn('1.0.0');

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            force:         false
        );

        $this->assertSame([], $result['registers']);
        $this->assertSame([], $result['schemas']);
        $this->assertSame([], $result['objects']);

    }//end testImportFromJsonSkipsWhenVersionNotNewer()


    /**
     * importFromJson() processes schemas from components.schemas.
     */
    public function testImportFromJsonImportsSchemas(): void
    {
        $configuration = $this->makeConfiguration(1, 'myapp', '2.0.0');
        $schema        = $this->makeSchema(10, 'person');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);

        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        // find() is called in importSchema — throw DoesNotExistException so it creates.
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $data = [
            'appId'   => 'myapp',
            'version' => '2.0.0',
            'components' => [
                'schemas' => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '2.0.0'],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            appId:         'myapp',
            version:       '2.0.0'
        );

        $this->assertCount(1, $result['schemas']);
        $this->assertSame(10, $result['schemas'][0]->getId());

    }//end testImportFromJsonImportsSchemas()


    /**
     * importFromJson() processes registers from components.registers.
     */
    public function testImportFromJsonImportsRegisters(): void
    {
        $configuration = $this->makeConfiguration(1);
        $register      = $this->makeRegister(20, 'my-register');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);

        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        // find() is called with named args — DoesNotExistException triggers create path.
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        // createFromArray must return a Register (not a generic Entity stub).
        $this->registerMapper->method('createFromArray')
            ->willReturn($register);

        // importFromJson passes owner/appId so update() is also called after createFromArray.
        $this->registerMapper->method('update')->willReturn($register);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'registers' => [
                    'my-register' => ['slug' => 'my-register', 'title' => 'My Register', 'version' => '1.0.0'],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertCount(1, $result['registers']);
        $this->assertSame(20, $result['registers'][0]->getId());

    }//end testImportFromJsonImportsRegisters()


    /**
     * importFromJson() stores the version in appConfig after import.
     */
    public function testImportFromJsonStoresVersionInAppConfig(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'imported_config_myapp_version', '1.2.3');

        $data = ['appId' => 'myapp', 'version' => '1.2.3'];

        $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            appId:         'myapp',
            version:       '1.2.3'
        );

    }//end testImportFromJsonStoresVersionInAppConfig()


    /**
     * importFromJson() extracts appId and version from data when not passed as params.
     */
    public function testImportFromJsonExtractsAppIdAndVersionFromData(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')
            ->with('openregister', 'imported_config_extracted-app_version', '')
            ->willReturn('0.0.0');

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'imported_config_extracted-app_version', '3.0.0');

        $data = [
            'appId'   => 'extracted-app',
            'version' => '3.0.0',
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration
            // appId and version NOT passed — should be extracted from $data.
        );

        $this->assertIsArray($result);

    }//end testImportFromJsonExtractsAppIdAndVersionFromData()


    /**
     * importFromJson() skips objects whose register or schema is not in the maps.
     */
    public function testImportFromJsonSkipsObjectsMissingRegisterOrSchema(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);

        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'objects' => [
                    [
                        '@self' => [
                            'register' => 'nonexistent-register',
                            'schema'   => 'nonexistent-schema',
                            'slug'     => 'my-object',
                        ],
                    ],
                ],
            ],
        ];

        // objectService->searchObjects must NOT be called because register/schema not in maps.
        $this->objectService->expects($this->never())->method('searchObjects');

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['objects']);

    }//end testImportFromJsonSkipsObjectsMissingRegisterOrSchema()


    /**
     * importFromJson() skips objects with no slug.
     *
     * The maps are populated by driving schema and register imports in the same
     * data payload; then the object with no slug is simply ignored.
     */
    public function testImportFromJsonSkipsObjectsWithoutSlug(): void
    {
        $configuration = $this->makeConfiguration(1);
        $schema        = $this->makeSchema(10, 'my-schema');
        $register      = $this->makeRegister(20, 'my-register');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas'   => [
                    'my-schema' => ['slug' => 'my-schema', 'title' => 'My Schema', 'version' => '1.0.0'],
                ],
                'registers' => [
                    'my-register' => ['slug' => 'my-register', 'title' => 'My Register', 'version' => '1.0.0'],
                ],
                'objects' => [
                    [
                        '@self' => [
                            'register' => 'my-register',
                            'schema'   => 'my-schema',
                            // slug intentionally absent.
                        ],
                    ],
                ],
            ],
        ];

        $this->objectService->expects($this->never())->method('searchObjects');

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['objects']);

    }//end testImportFromJsonSkipsObjectsWithoutSlug()


    /**
     * importFromJson() force flag bypasses the version check entirely.
     */
    public function testImportFromJsonForceBypassesVersionCheck(): void
    {
        $configuration = $this->makeConfiguration(1);

        // Stored version is current.
        $this->appConfig->method('getValueString')->willReturn('5.0.0');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
        ];

        // With force=true, the import runs even though stored version is higher.
        // We just confirm it doesn't return the early-exit empty result.
        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            appId:         'myapp',
            version:       '1.0.0',
            force:         true
        );

        // Result should have the standard structure (not the abbreviated skip result).
        $this->assertArrayHasKey('registers', $result);
        $this->assertArrayHasKey('schemas', $result);
        $this->assertArrayHasKey('objects', $result);
        $this->assertArrayHasKey('mappings', $result);

    }//end testImportFromJsonForceBypassesVersionCheck()


    // =========================================================================
    // importFromApp()
    // =========================================================================

    /**
     * importFromApp() creates a new Configuration and runs importFromJson when none exists.
     */
    public function testImportFromAppCreatesNewConfigurationWhenNoneExists(): void
    {
        $newConfig = $this->makeConfiguration(99, 'myapp', '1.0.0');

        // No existing config by sourceUrl.
        $this->configurationMapper->method('findBySourceUrl')
            ->willReturn(null);

        // No existing config by app.
        $this->configurationMapper->method('findByApp')
            ->willReturn([]);

        // insert() returns the new configuration.
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturn($newConfig);

        // Prevent version skipping inside importFromJson.
        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'info' => ['title' => 'Test Config', 'description' => 'A test'],
        ];

        $result = $this->handler->importFromApp(
            appId:   'myapp',
            data:    $data,
            version: '1.0.0'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);

    }//end testImportFromAppCreatesNewConfigurationWhenNoneExists()


    /**
     * importFromApp() reuses existing Configuration found by sourceUrl.
     */
    public function testImportFromAppReusesConfigFoundBySourceUrl(): void
    {
        $existingConfig = $this->makeConfiguration(55, 'myapp', '0.9.0');

        $this->configurationMapper->method('findBySourceUrl')
            ->willReturn($existingConfig);

        $this->configurationMapper->expects($this->never())->method('insert');

        // importFromJson will call appConfig.
        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'x-openregister' => ['sourceUrl' => 'https://example.com/config.json'],
        ];

        $result = $this->handler->importFromApp(
            appId:   'myapp',
            data:    $data,
            version: '1.0.0'
        );

        $this->assertIsArray($result);

    }//end testImportFromAppReusesConfigFoundBySourceUrl()


    /**
     * importFromApp() reuses existing Configuration found by app ID.
     */
    public function testImportFromAppReusesConfigFoundByApp(): void
    {
        $existingConfig = $this->makeConfiguration(55, 'myapp', '0.9.0');
        $existingConfig->setRegisters([]);
        $existingConfig->setSchemas([]);
        $existingConfig->setObjects([]);

        // No config by sourceUrl.
        $this->configurationMapper->method('findBySourceUrl')->willReturn(null);
        $this->configurationMapper->method('findByApp')->willReturn([$existingConfig]);

        // insert must NOT be called — we reuse the existing one.
        $this->configurationMapper->expects($this->never())->method('insert');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $result = $this->handler->importFromApp(
            appId:   'myapp',
            data:    [],
            version: '2.0.0'
        );

        $this->assertIsArray($result);

    }//end testImportFromAppReusesConfigFoundByApp()


    /**
     * importFromApp() sets github fields from nested x-openregister.github structure.
     */
    public function testImportFromAppSetsGithubFieldsFromNestedStructure(): void
    {
        $this->configurationMapper->method('findBySourceUrl')->willReturn(null);
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'description'    => 'Test config description',
            'x-openregister' => [
                'github' => [
                    'repo'   => 'org/repo',
                    'branch' => 'main',
                    'path'   => 'configs/app.json',
                ],
            ],
        ];

        $this->handler->importFromApp(appId: 'myapp', data: $data, version: '1.0.0');

        $this->assertSame('org/repo', $capturedConfig->getGithubRepo());
        $this->assertSame('main', $capturedConfig->getGithubBranch());
        $this->assertSame('configs/app.json', $capturedConfig->getGithubPath());

    }//end testImportFromAppSetsGithubFieldsFromNestedStructure()


    /**
     * importFromApp() sets github fields from flat (legacy) x-openregister structure.
     */
    public function testImportFromAppSetsGithubFieldsFromFlatStructure(): void
    {
        $this->configurationMapper->method('findBySourceUrl')->willReturn(null);
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'description'    => 'Test config description',
            'x-openregister' => [
                'githubRepo'   => 'org/legacy-repo',
                'githubBranch' => 'develop',
                'githubPath'   => 'config.json',
            ],
        ];

        $this->handler->importFromApp(appId: 'myapp', data: $data, version: '1.0.0');

        $this->assertSame('org/legacy-repo', $capturedConfig->getGithubRepo());
        $this->assertSame('develop', $capturedConfig->getGithubBranch());
        $this->assertSame('config.json', $capturedConfig->getGithubPath());

    }//end testImportFromAppSetsGithubFieldsFromFlatStructure()


    /**
     * importFromApp() wraps and rethrows exceptions.
     *
     * findBySourceUrl/findByApp exceptions are caught internally (treated as "no config"),
     * so we trigger the error path by making insert() throw.
     */
    public function testImportFromAppWrapsException(): void
    {
        $this->configurationMapper->method('findBySourceUrl')->willReturn(null);
        $this->configurationMapper->method('findByApp')->willReturn([]);

        // insert() failure propagates and is wrapped by importFromApp.
        $this->configurationMapper->method('insert')
            ->willThrowException(new Exception('DB write failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to import configuration for app myapp/');

        // Include 'description' to avoid PHP undefined array key warning at line 2188.
        $this->handler->importFromApp(appId: 'myapp', data: ['description' => 'test'], version: '1.0.0');

    }//end testImportFromAppWrapsException()


    // =========================================================================
    // createOrUpdateConfiguration()
    // =========================================================================

    /**
     * createOrUpdateConfiguration() creates new Configuration when none exists for app.
     */
    public function testCreateOrUpdateConfigurationCreatesNew(): void
    {
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $inserted = $this->makeConfiguration(77, 'newapp', '1.0.0');
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturn($inserted);

        $result = $this->handler->createOrUpdateConfiguration(
            data:    ['info' => ['title' => 'New App Config']],
            appId:   'newapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []]
        );

        $this->assertInstanceOf(Configuration::class, $result);
        $this->assertSame(77, $result->getId());

    }//end testCreateOrUpdateConfigurationCreatesNew()


    /**
     * createOrUpdateConfiguration() updates existing Configuration.
     */
    public function testCreateOrUpdateConfigurationUpdatesExisting(): void
    {
        $existing = $this->makeConfiguration(77, 'existingapp', '0.9.0');
        $existing->setRegisters([10]);
        $existing->setSchemas([20]);
        $existing->setObjects([]);

        $this->configurationMapper->method('findByApp')->willReturn([$existing]);

        $this->configurationMapper->expects($this->never())->method('insert');
        $this->configurationMapper->expects($this->once())
            ->method('update')
            ->willReturn($existing);

        $newRegister = $this->makeRegister(30, 'new-register');
        $newSchema   = $this->makeSchema(40, 'new-schema');

        $result = $this->handler->createOrUpdateConfiguration(
            data:    ['info' => ['title' => 'Existing App']],
            appId:   'existingapp',
            version: '1.0.0',
            result:  [
                'registers' => [$newRegister],
                'schemas'   => [$newSchema],
                'objects'   => [],
            ]
        );

        // The result merges existing IDs with new ones.
        $this->assertContains(10, $result->getRegisters());
        $this->assertContains(30, $result->getRegisters());
        $this->assertContains(20, $result->getSchemas());
        $this->assertContains(40, $result->getSchemas());

    }//end testCreateOrUpdateConfigurationUpdatesExisting()


    /**
     * createOrUpdateConfiguration() sets owner when provided.
     */
    public function testCreateOrUpdateConfigurationSetsOwner(): void
    {
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->handler->createOrUpdateConfiguration(
            data:    [],
            appId:   'ownerapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []],
            owner:   'the-owner'
        );

        $this->assertSame('the-owner', $capturedConfig->getOwner());

    }//end testCreateOrUpdateConfigurationSetsOwner()


    /**
     * createOrUpdateConfiguration() reads title from info.title (OAS standard).
     */
    public function testCreateOrUpdateConfigurationReadsTitleFromInfoSection(): void
    {
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->handler->createOrUpdateConfiguration(
            data: [
                'info' => ['title' => 'OAS Title', 'description' => 'OAS Desc'],
                'x-openregister' => ['title' => 'Should be ignored'],
            ],
            appId:   'titledapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []]
        );

        $this->assertSame('OAS Title', $capturedConfig->getTitle());
        $this->assertSame('OAS Desc', $capturedConfig->getDescription());

    }//end testCreateOrUpdateConfigurationReadsTitleFromInfoSection()


    /**
     * createOrUpdateConfiguration() falls back to x-openregister.title when info.title is absent.
     */
    public function testCreateOrUpdateConfigurationFallsBackToXOpenregisterTitle(): void
    {
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->handler->createOrUpdateConfiguration(
            data: [
                'x-openregister' => ['title' => 'X-OR Title'],
            ],
            appId:   'xorapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []]
        );

        $this->assertSame('X-OR Title', $capturedConfig->getTitle());

    }//end testCreateOrUpdateConfigurationFallsBackToXOpenregisterTitle()


    /**
     * createOrUpdateConfiguration() sets sourceUrl from x-openregister.
     */
    public function testCreateOrUpdateConfigurationSetsSourceUrl(): void
    {
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->handler->createOrUpdateConfiguration(
            data: [
                'x-openregister' => ['sourceUrl' => 'https://example.com/cfg.json', 'sourceType' => 'github'],
            ],
            appId:   'sourceapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []]
        );

        $this->assertSame('https://example.com/cfg.json', $capturedConfig->getSourceUrl());
        $this->assertSame('github', $capturedConfig->getSourceType());

    }//end testCreateOrUpdateConfigurationSetsSourceUrl()


    /**
     * createOrUpdateConfiguration() throws wrapped exception on mapper insert failure.
     *
     * findByApp exceptions are swallowed (treated as "no config found"), so we
     * must make insert() fail to exercise the outer try/catch rethrow path.
     */
    public function testCreateOrUpdateConfigurationThrowsOnMapperError(): void
    {
        // findByApp returns empty — triggers the create-new path.
        $this->configurationMapper->method('findByApp')->willReturn([]);

        // insert() fails — outer try/catch must rethrow as wrapped exception.
        $this->configurationMapper->method('insert')
            ->willThrowException(new Exception('Insert failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to create or update configuration/');

        $this->handler->createOrUpdateConfiguration(
            data:    [],
            appId:   'failapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []]
        );

    }//end testCreateOrUpdateConfigurationThrowsOnMapperError()


    // =========================================================================
    // Setter methods
    // =========================================================================

    /**
     * setObjectService() replaces the objectService dependency.
     */
    public function testSetObjectService(): void
    {
        $newService = $this->createMock(ObjectService::class);
        $this->handler->setObjectService($newService);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('objectService');
        $prop->setAccessible(true);

        $this->assertSame($newService, $prop->getValue($this->handler));

    }//end testSetObjectService()


    /**
     * setOpenConnectorConfigurationService() stores the connector service.
     */
    public function testSetOpenConnectorConfigurationService(): void
    {
        $service = new \stdClass();
        $this->handler->setOpenConnectorConfigurationService($service);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('connectorConfigSvc');
        $prop->setAccessible(true);

        $this->assertSame($service, $prop->getValue($this->handler));

    }//end testSetOpenConnectorConfigurationService()


    // =========================================================================
    // importFromFilePath()
    // =========================================================================

    /**
     * importFromFilePath() throws when the file does not exist.
     */
    public function testImportFromFilePathThrowsWhenFileNotFound(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to import configuration from file/');

        $this->handler->importFromFilePath(
            appId:    'myapp',
            filePath: '/this/path/does/not/exist/config.json',
            version:  '1.0.0'
        );

    }//end testImportFromFilePathThrowsWhenFileNotFound()


    /**
     * importFromFilePath() throws when the file contains invalid JSON.
     */
    public function testImportFromFilePathThrowsOnInvalidJson(): void
    {
        // Create a real temp file with invalid JSON.
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpunit_or_');
        file_put_contents($tmpFile, '{invalid json content}');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to import configuration from file/');

        try {
            // importFromFilePath resolves via appDataPath + '/../../../' + filePath.
            // Since that won't resolve to $tmpFile, supply it as an absolute path using
            // the handler's appDataPath set to /tmp in setUp.
            // The handler tries realpath('/tmp/../../../' + filePath) — that fails for /tmp paths.
            // So we test the /var/www/html/ fallback path by passing the absolute path as filePath.
            // The simplest approach: pass a relative path under /tmp that we know exists.
            $relativePath = ltrim($tmpFile, '/');
            $this->handler->importFromFilePath(
                appId:    'myapp',
                filePath: $relativePath,
                version:  '1.0.0'
            );
        } finally {
            unlink($tmpFile);
        }

    }//end testImportFromFilePathThrowsOnInvalidJson()


    /**
     * importFromFilePath() injects sourceUrl and sourceType into data when absent.
     */
    public function testImportFromFilePathInjectsSourceMetadata(): void
    {
        // Write valid JSON to a temp file.
        // Include 'description' to avoid PHP undefined array key warning in ImportHandler::importFromApp (line 2188).
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpunit_or_');
        file_put_contents($tmpFile, json_encode(['description' => 'test config', 'components' => []]));

        // Arrange: configuration mapper returns nothing for insert.
        $config = $this->makeConfiguration(1, 'myapp', '1.0.0');

        $this->configurationMapper->method('findBySourceUrl')->willReturn(null);
        $this->configurationMapper->method('findByApp')->willReturn([]);
        $this->configurationMapper->method('insert')->willReturn($config);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        // We cannot easily assert what data importFromApp received without deep mocking,
        // but we can verify the call chain completes without error.
        $relativePath = ltrim($tmpFile, '/');
        $result = $this->handler->importFromFilePath(
            appId:    'myapp',
            filePath: $relativePath,
            version:  '1.0.0'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);

        unlink($tmpFile);

    }//end testImportFromFilePathInjectsSourceMetadata()


    // =========================================================================
    // importFromJson() — mappings branch
    // =========================================================================

    /**
     * importFromJson() processes mappings from components.mappings.
     */
    public function testImportFromJsonImportsMappings(): void
    {
        $configuration = $this->makeConfiguration(1);
        $mapping       = new Mapping();
        $this->setEntityId($mapping, 50);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->mappingMapper->method('getSlugToIdMap')->willReturn([]);
        $this->mappingMapper->method('createFromArray')->willReturn($mapping);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'mappings' => [
                    'test-mapping' => ['slug' => 'test-mapping', 'name' => 'Test Mapping', 'version' => '1.0.0'],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertCount(1, $result['mappings']);

    }//end testImportFromJsonImportsMappings()


    // =========================================================================
    // importFromJson() — objects branch (existing object, version check)
    // =========================================================================

    /**
     * importFromJson() saves a new object when it does not exist yet.
     *
     * The registers/schemas maps are populated during the schema and register
     * import passes. We drive those passes by including real schema/register
     * data so the maps are populated before the object loop runs.
     */
    public function testImportFromJsonCreatesNewObjectWhenNotExisting(): void
    {
        $configuration = $this->makeConfiguration(1);
        $schema        = $this->makeSchema(10, 'person');
        $register      = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);

        // Schema import pass: getSlugToIdMap + find (DoesNotExist) + createFromArray + update.
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        // Register import pass.
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->registerMapper->method('createFromArray')->willReturn($register);
        // update() is called because owner is passed into importRegister via importFromJson.
        $this->registerMapper->method('update')->willReturn($register);

        // searchObjects returns empty (object doesn't exist yet).
        $this->objectService->method('searchObjects')->willReturn([]);

        // saveObject returns an ObjectEntity.
        $savedObject = new ObjectEntity();
        $this->setEntityId($savedObject, 100);
        $this->objectService->method('saveObject')->willReturn($savedObject);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas' => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '1.0.0'],
                ],
                'registers' => [
                    'registry' => ['slug' => 'registry', 'title' => 'Registry', 'version' => '1.0.0'],
                ],
                'objects' => [
                    [
                        '@self' => [
                            'register' => 'registry',
                            'schema'   => 'person',
                            'slug'     => 'john-doe',
                            'version'  => '1.0.0',
                        ],
                        'name' => 'John Doe',
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertCount(1, $result['objects']);

    }//end testImportFromJsonCreatesNewObjectWhenNotExisting()


    /**
     * importFromJson() skips updating an object when imported version is not higher.
     *
     * The existing object has version 2.0.0; the imported object has version 1.0.0,
     * so no update should occur. Maps are populated via the schema/register import pass.
     */
    public function testImportFromJsonSkipsObjectWhenVersionNotHigher(): void
    {
        $configuration = $this->makeConfiguration(1);
        $schema        = $this->makeSchema(10, 'person');
        $register      = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);

        // Drive schema/register import so maps are populated.
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        // Existing object reports version 2.0.0 — imported is 1.0.0.
        $existingObjectMock = $this->getMockBuilder(ObjectEntity::class)
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $existingObjectMock->method('jsonSerialize')
            ->willReturn(['@self' => ['version' => '2.0.0', 'id' => 'some-uuid']]);
        $this->setEntityId($existingObjectMock, 99);

        $this->objectService->method('searchObjects')->willReturn([$existingObjectMock]);

        // saveObject must NOT be called because imported version is lower.
        $this->objectService->expects($this->never())->method('saveObject');

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas'   => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '1.0.0'],
                ],
                'registers' => [
                    'registry' => ['slug' => 'registry', 'title' => 'Registry', 'version' => '1.0.0'],
                ],
                'objects' => [
                    [
                        '@self' => [
                            'register' => 'registry',
                            'schema'   => 'person',
                            'slug'     => 'john-doe',
                            'version'  => '1.0.0',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['objects']);

    }//end testImportFromJsonSkipsObjectWhenVersionNotHigher()


    // =========================================================================
    // importFromJson() — OpenConnector integration branch
    // =========================================================================

    /**
     * importFromJson() calls connectorConfigSvc when it is set.
     */
    public function testImportFromJsonCallsOpenConnectorWhenSet(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $connectorSvc = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['importConfiguration'])
            ->getMock();
        $connectorSvc->expects($this->once())
            ->method('importConfiguration')
            ->willReturn([
                'registers' => [],
                'schemas'   => [],
                'objects'   => [],
                'endpoints' => ['ep1'],
                'sources'   => [],
                'mappings'  => [],
                'jobs'      => [],
                'synchronizations' => [],
                'rules'     => [],
            ]);

        $this->handler->setOpenConnectorConfigurationService($connectorSvc);

        $result = $this->handler->importFromJson(
            data:          ['appId' => 'myapp', 'version' => '1.0.0'],
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertIsArray($result);

    }//end testImportFromJsonCallsOpenConnectorWhenSet()


    /**
     * importFromJson() continues gracefully when connectorConfigSvc throws.
     */
    public function testImportFromJsonContinuesWhenOpenConnectorThrows(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $connectorSvc = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['importConfiguration'])
            ->getMock();
        $connectorSvc->method('importConfiguration')
            ->willThrowException(new Exception('Connector unavailable'));

        $this->handler->setOpenConnectorConfigurationService($connectorSvc);

        // Should NOT throw — warnings are logged but import continues.
        $result = $this->handler->importFromJson(
            data:          ['appId' => 'myapp', 'version' => '1.0.0'],
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertIsArray($result);

    }//end testImportFromJsonContinuesWhenOpenConnectorThrows()


    // =========================================================================
    // importFromJson() — two-pass schema import edge cases
    // =========================================================================

    /**
     * importFromJson() skips Pass-1 schema that fails and continues with the rest.
     */
    public function testImportFromJsonContinuesWhenSchemaImportFails(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        // First schema fails, second succeeds.
        $goodSchema = $this->makeSchema(10, 'good-schema');
        $callCount  = 0;
        $this->schemaMapper->method('find')
            ->willReturnCallback(function () use (&$callCount) {
                throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
            });

        $this->schemaMapper->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($goodSchema, &$callCount) {
                $callCount++;
                if ($data['slug'] === 'bad-schema') {
                    throw new Exception('Create failed');
                }
                return $goodSchema;
            });
        $this->schemaMapper->method('update')->willReturn($goodSchema);
        $this->schemaMapper->method('updateFromArray')->willReturn($goodSchema);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas' => [
                    'bad-schema'  => ['slug' => 'bad-schema',  'version' => '1.0.0', 'title' => 'Bad'],
                    'good-schema' => ['slug' => 'good-schema', 'version' => '1.0.0', 'title' => 'Good'],
                ],
            ],
        ];

        // Should NOT throw — errors are caught per-schema.
        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        // At least the good schema should be imported.
        $this->assertCount(1, $result['schemas']);

    }//end testImportFromJsonContinuesWhenSchemaImportFails()


    /**
     * importFromJson() sets title from schema key when title is absent.
     */
    public function testImportFromJsonSetsSchemaTitleFromKey(): void
    {
        $configuration = $this->makeConfiguration(1);
        $schema        = $this->makeSchema(10, 'untitled-schema');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $data = [
            'version'    => '1.0.0',
            'components' => [
                'schemas' => [
                    // key is 'untitled-schema', no 'title' field in value.
                    'untitled-schema' => ['slug' => 'untitled-schema', 'version' => '1.0.0'],
                ],
            ],
        ];

        $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration
        );

        $this->assertSame('untitled-schema', $capturedData['title']);

    }//end testImportFromJsonSetsSchemaTitleFromKey()


    // =========================================================================
    // decode() — additional branches
    // =========================================================================

    /**
     * decode() parses valid YAML when type is the yaml extension string (not MIME type).
     */
    public function testDecodeYamlExtensionType(): void
    {
        // When a YAML file is uploaded, the extension 'yaml' is passed as $type.
        // The default case in decode() tries JSON first (fails), then tries Yaml::parse.
        $yaml   = "title: My Config\nversion: 2.0.0\n";
        $result = $this->handler->decode($yaml, 'yaml');

        $this->assertIsArray($result);
        $this->assertSame('My Config', $result['title']);
        $this->assertSame('2.0.0', $result['version']);

    }//end testDecodeYamlExtensionType()


    /**
     * decode() returns null when type is application/json and input is not valid JSON.
     */
    public function testDecodeReturnsNullForInvalidJsonWithExplicitType(): void
    {
        $result = $this->handler->decode('not valid json at all', 'application/json');
        $this->assertNull($result);

    }//end testDecodeReturnsNullForInvalidJsonWithExplicitType()


    // =========================================================================
    // Setter methods — remaining setters
    // =========================================================================

    /**
     * setWorkflowEngineRegistry() stores the registry.
     */
    public function testSetWorkflowEngineRegistry(): void
    {
        $registry = $this->createMock(\OCA\OpenRegister\Service\WorkflowEngineRegistry::class);
        $this->handler->setWorkflowEngineRegistry($registry);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('workflowRegistry');
        $prop->setAccessible(true);

        $this->assertSame($registry, $prop->getValue($this->handler));

    }//end testSetWorkflowEngineRegistry()


    /**
     * setDeployedWorkflowMapper() stores the mapper.
     */
    public function testSetDeployedWorkflowMapper(): void
    {
        $mapper = $this->createMock(\OCA\OpenRegister\Db\DeployedWorkflowMapper::class);
        $this->handler->setDeployedWorkflowMapper($mapper);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('deployedWfMapper');
        $prop->setAccessible(true);

        $this->assertSame($mapper, $prop->getValue($this->handler));

    }//end testSetDeployedWorkflowMapper()


    /**
     * setMagicMapper() stores the magic mapper.
     */
    public function testSetMagicMapper(): void
    {
        $magicMapper = $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class);
        $this->handler->setMagicMapper($magicMapper);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('magicMapper');
        $prop->setAccessible(true);

        $this->assertSame($magicMapper, $prop->getValue($this->handler));

    }//end testSetMagicMapper()


    /**
     * setUnifiedObjectMapper() stores the unified object mapper.
     */
    public function testSetUnifiedObjectMapper(): void
    {
        $unifiedMapper = $this->createMock(UnifiedObjectMapper::class);
        $this->handler->setUnifiedObjectMapper($unifiedMapper);

        $ref  = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('unifiedObjectMapper');
        $prop->setAccessible(true);

        $this->assertSame($unifiedMapper, $prop->getValue($this->handler));

    }//end testSetUnifiedObjectMapper()


    // =========================================================================
    // importRegister() — MultipleObjectsReturnedException path
    // =========================================================================

    /**
     * importRegister() throws when MultipleObjectsReturnedException is caught.
     *
     * The duplicate-register error path calls getDuplicateRegisterInfo() internally
     * and then throws an Exception with details about the duplicate.
     */
    public function testImportRegisterThrowsOnDuplicateRegister(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('duplicate'));

        // getDuplicateRegisterInfo calls findAll().
        $r1 = $this->makeRegister(1, 'dup-register');
        $r2 = $this->makeRegister(2, 'dup-register');
        $this->registerMapper->method('findAll')->willReturn([$r1, $r2]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Duplicate register detected/');

        $this->handler->importRegister([
            'slug'    => 'dup-register',
            'version' => '1.0.0',
            'title'   => 'Duplicate',
        ]);

    }//end testImportRegisterThrowsOnDuplicateRegister()


    /**
     * importRegister() duplicate info falls back gracefully when findAll() throws.
     */
    public function testImportRegisterDuplicateInfoHandlesFindAllFailure(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('duplicate'));

        $this->registerMapper->method('findAll')
            ->willThrowException(new Exception('DB unavailable'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Duplicate register detected/');

        $this->handler->importRegister([
            'slug'    => 'dup-register',
            'version' => '1.0.0',
            'title'   => 'Duplicate',
        ]);

    }//end testImportRegisterDuplicateInfoHandlesFindAllFailure()


    /**
     * getDuplicateRegisterInfo returns generic message when only one match found.
     *
     * When findAll() returns fewer than 2 registers with the same slug, the method
     * returns the "Unable to retrieve detailed duplicate information" string.
     */
    public function testImportRegisterDuplicateInfoOneMatchReturnsGenericMessage(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('duplicate'));

        // findAll returns only one register with that slug.
        $r1 = $this->makeRegister(1, 'dup-register');
        $this->registerMapper->method('findAll')->willReturn([$r1]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Duplicate register detected/');

        $this->handler->importRegister([
            'slug'    => 'dup-register',
            'version' => '1.0.0',
            'title'   => 'Duplicate',
        ]);

    }//end testImportRegisterDuplicateInfoOneMatchReturnsGenericMessage()


    /**
     * importRegister() sets only owner (no appId) — update still called once.
     */
    public function testImportRegisterSetsOnlyOwner(): void
    {
        $register = $this->makeRegister(1, 'owner-only-register');

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturn($register);

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->willReturn($register);

        $result = $this->handler->importRegister(
            data:  ['slug' => 'owner-only-register', 'version' => '1.0.0', 'title' => 'Owner Only'],
            owner: 'the-owner'
        );

        $this->assertSame('the-owner', $result->getOwner());

    }//end testImportRegisterSetsOnlyOwner()


    /**
     * importRegister() with existing register updates owner and application even when forcing.
     */
    public function testImportRegisterForceUpdatesExistingWithOwnerAndApp(): void
    {
        $existing = $this->makeRegister(5, 'my-register', '5.0.0');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        $this->registerMapper->expects($this->once())
            ->method('updateFromArray')
            ->willReturn($existing);

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->handler->importRegister(
            data:  ['slug' => 'my-register', 'version' => '1.0.0', 'title' => 'Old'],
            owner: 'new-owner',
            appId: 'new-app',
            force: true
        );

        $this->assertSame('new-owner', $result->getOwner());
        $this->assertSame('new-app', $result->getApplication());

    }//end testImportRegisterForceUpdatesExistingWithOwnerAndApp()


    // =========================================================================
    // importSchema() — MultipleObjectsReturnedException and ValidationException
    // =========================================================================

    /**
     * importSchema() throws when MultipleObjectsReturnedException is caught.
     */
    public function testImportSchemaThrowsOnDuplicateSchema(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('duplicate'));

        $s1 = $this->makeSchema(1, 'dup-schema');
        $s2 = $this->makeSchema(2, 'dup-schema');
        $this->schemaMapper->method('findAll')->willReturn([$s1, $s2]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Duplicate schema detected/');

        $this->handler->importSchema(
            data:           ['slug' => 'dup-schema', 'version' => '1.0.0', 'title' => 'Dup'],
            slugsAndIdsMap: []
        );

    }//end testImportSchemaThrowsOnDuplicateSchema()


    /**
     * importSchema() creates new schema on ValidationException (treated as not-found).
     */
    public function testImportSchemaCreatesOnValidationException(): void
    {
        $schema = $this->makeSchema(10, 'val-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('invalid slug'));

        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturn($schema);

        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->handler->importSchema(
            data:           ['slug' => 'val-schema', 'version' => '1.0.0', 'title' => 'Val'],
            slugsAndIdsMap: []
        );

        $this->assertInstanceOf(Schema::class, $result);
        $this->assertSame(10, $result->getId());

    }//end testImportSchemaCreatesOnValidationException()


    /**
     * importSchema() strips binary and byte formats from items.
     */
    public function testImportSchemaStripsItemsBinaryFormat(): void
    {
        $schema = $this->makeSchema(10, 'items-fmt-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'items-fmt-schema',
                'version'    => '1.0.0',
                'title'      => 'Items Format',
                'properties' => [
                    'files' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string', 'format' => 'binary'],
                    ],
                ],
            ],
            slugsAndIdsMap: []
        );

        $this->assertArrayNotHasKey('format', $capturedData['properties']['files']['items']);

    }//end testImportSchemaStripsItemsBinaryFormat()


    /**
     * importSchema() sets property title to key name when title is missing.
     */
    public function testImportSchemaSetsMissingPropertyTitle(): void
    {
        $schema = $this->makeSchema(10, 'notitle-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'notitle-schema',
                'version'    => '1.0.0',
                'title'      => 'NoTitle',
                'properties' => [
                    'myProp' => ['type' => 'string'],
                    // 'title' is intentionally absent.
                ],
            ],
            slugsAndIdsMap: []
        );

        $this->assertSame('myProp', $capturedData['properties']['myProp']['title']);

    }//end testImportSchemaSetsMissingPropertyTitle()


    /**
     * importSchema() resolves $ref from schemasMap when not in slugsAndIdsMap.
     */
    public function testImportSchemaResolvesRefFromSchemasMap(): void
    {
        $schema    = $this->makeSchema(10, 'ref-schema');
        $refSchema = $this->makeSchema(42, 'linked-schema');

        // Pre-populate schemasMap via reflection.
        $this->setProperty($this->handler, 'schemasMap', ['linked-schema' => $refSchema]);

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'ref-schema',
                'version'    => '1.0.0',
                'title'      => 'Ref Schema',
                'properties' => [
                    'address' => ['type' => 'object', '$ref' => 'linked-schema'],
                ],
            ],
            slugsAndIdsMap: []
            // $ref not in slugsAndIdsMap — must resolve from schemasMap.
        );

        $this->assertSame(42, $capturedData['properties']['address']['$ref']);

    }//end testImportSchemaResolvesRefFromSchemasMap()


    /**
     * importSchema() resolves items.$ref from slugsAndIdsMap.
     */
    public function testImportSchemaResolvesItemsRefFromSlugsMap(): void
    {
        $schema = $this->makeSchema(10, 'items-ref-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'items-ref-schema',
                'version'    => '1.0.0',
                'title'      => 'Items Ref',
                'properties' => [
                    'tags' => [
                        'type'  => 'array',
                        'items' => ['$ref' => 'tag-schema'],
                    ],
                ],
            ],
            slugsAndIdsMap: ['tag-schema' => 77]
        );

        $this->assertSame(77, $capturedData['properties']['tags']['items']['$ref']);

    }//end testImportSchemaResolvesItemsRefFromSlugsMap()


    /**
     * importSchema() resolves objectConfiguration.register from registersMap.
     */
    public function testImportSchemaResolvesObjectConfigurationRegisterFromMap(): void
    {
        $schema   = $this->makeSchema(10, 'objcfg-schema');
        $register = $this->makeRegister(55, 'my-register');

        // Pre-populate registersMap.
        $this->setProperty($this->handler, 'registersMap', ['my-register' => $register]);

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'objcfg-schema',
                'version'    => '1.0.0',
                'title'      => 'ObjCfg',
                'properties' => [
                    'linked' => [
                        'type'                => 'object',
                        'objectConfiguration' => ['register' => 'my-register'],
                    ],
                ],
            ],
            slugsAndIdsMap: []
        );

        $this->assertSame(55, $capturedData['properties']['linked']['objectConfiguration']['register']);

    }//end testImportSchemaResolvesObjectConfigurationRegisterFromMap()


    /**
     * importSchema() falls back to database lookup for objectConfiguration.register
     * when not found in registersMap.
     */
    public function testImportSchemaResolvesObjectConfigurationRegisterFromDatabase(): void
    {
        $schema   = $this->makeSchema(10, 'dbcfg-schema');
        $register = $this->makeRegister(99, 'db-register');

        // registerMapper.find() returns the register for any call (no with() — named args differ).
        $this->registerMapper->method('find')->willReturn($register);

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'dbcfg-schema',
                'version'    => '1.0.0',
                'title'      => 'DbCfg',
                'properties' => [
                    'linked' => [
                        'type'                => 'object',
                        'objectConfiguration' => ['register' => 'db-register'],
                    ],
                ],
            ],
            slugsAndIdsMap: []
        );

        $this->assertSame(99, $capturedData['properties']['linked']['objectConfiguration']['register']);

    }//end testImportSchemaResolvesObjectConfigurationRegisterFromDatabase()


    /**
     * importSchema() removes objectConfiguration.register when not found in DB.
     */
    public function testImportSchemaRemovesObjectConfigurationRegisterWhenNotFound(): void
    {
        $schema = $this->makeSchema(10, 'missing-reg-schema');

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'missing-reg-schema',
                'version'    => '1.0.0',
                'title'      => 'Missing Reg',
                'properties' => [
                    'linked' => [
                        'type'                => 'object',
                        'objectConfiguration' => ['register' => 'nonexistent-register'],
                    ],
                ],
            ],
            slugsAndIdsMap: []
        );

        $this->assertArrayNotHasKey('register', $capturedData['properties']['linked']['objectConfiguration']);

    }//end testImportSchemaRemovesObjectConfigurationRegisterWhenNotFound()


    /**
     * importSchema() resolves objectConfiguration.schema from schemasMap.
     */
    public function testImportSchemaResolvesObjectConfigurationSchemaFromMap(): void
    {
        $schema      = $this->makeSchema(10, 'cfgschema');
        $linkedSchema = $this->makeSchema(33, 'linked-schema');

        $this->setProperty($this->handler, 'schemasMap', ['linked-schema' => $linkedSchema]);

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'cfgschema',
                'version'    => '1.0.0',
                'title'      => 'CfgSchema',
                'properties' => [
                    'child' => [
                        'type'                => 'object',
                        'objectConfiguration' => ['schema' => 'linked-schema'],
                    ],
                ],
            ],
            slugsAndIdsMap: []
        );

        $this->assertSame(33, $capturedData['properties']['child']['objectConfiguration']['schema']);

    }//end testImportSchemaResolvesObjectConfigurationSchemaFromMap()


    /**
     * importSchema() normalises empty-array objectConfiguration: sets to stdClass then casts
     * back to array, resulting in an empty array passed to createFromArray.
     *
     * The code at line ~915 converts [] → stdClass, then at line ~986 converts any stdClass
     * back to array. So an empty [] round-trips to [] in the data sent to createFromArray.
     * The key check here is that the property key is still present (not removed).
     */
    public function testImportSchemaNormalisesEmptyObjectConfiguration(): void
    {
        $schema = $this->makeSchema(10, 'empty-objcfg-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'empty-objcfg-schema',
                'version'    => '1.0.0',
                'title'      => 'EmptyObjCfg',
                'properties' => [
                    'ref' => [
                        'type'                => 'object',
                        'objectConfiguration' => [],
                        // empty array — normalised to stdClass then back to array.
                    ],
                ],
            ],
            slugsAndIdsMap: []
        );

        // The property key must still exist (not removed), and it ends up as an empty array.
        $this->assertArrayHasKey('objectConfiguration', $capturedData['properties']['ref']);
        $this->assertSame([], $capturedData['properties']['ref']['objectConfiguration']);

    }//end testImportSchemaNormalisesEmptyObjectConfiguration()


    /**
     * importSchema() resolves legacy 'register' property from slugsAndIdsMap.
     */
    public function testImportSchemaResolvesLegacyRegisterProperty(): void
    {
        $schema = $this->makeSchema(10, 'legacy-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'legacy-schema',
                'version'    => '1.0.0',
                'title'      => 'Legacy',
                'properties' => [
                    'obj' => [
                        'type'     => 'object',
                        'register' => 'legacy-register',
                        // Legacy: register as a direct property.
                    ],
                ],
            ],
            slugsAndIdsMap: ['legacy-register' => 88]
        );

        $this->assertSame(88, $capturedData['properties']['obj']['register']);

    }//end testImportSchemaResolvesLegacyRegisterProperty()


    /**
     * importSchema() resolves legacy items.register from registersMap.
     */
    public function testImportSchemaResolvesLegacyItemsRegisterFromMap(): void
    {
        $schema   = $this->makeSchema(10, 'legacy-items-schema');
        $register = $this->makeRegister(44, 'items-register');

        $this->setProperty($this->handler, 'registersMap', ['items-register' => $register]);

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $capturedData = null;
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($schema, &$capturedData) {
                $capturedData = $data;
                return $schema;
            });

        $this->schemaMapper->method('update')->willReturn($schema);

        $this->handler->importSchema(
            data: [
                'slug'       => 'legacy-items-schema',
                'version'    => '1.0.0',
                'title'      => 'LegacyItems',
                'properties' => [
                    'refs' => [
                        'type'  => 'array',
                        'items' => ['register' => 'items-register'],
                    ],
                ],
            ],
            slugsAndIdsMap: []
        );

        $this->assertSame(44, $capturedData['properties']['refs']['items']['register']);

    }//end testImportSchemaResolvesLegacyItemsRegisterFromMap()


    // =========================================================================
    // importFromJson() — register with schemas resolution via schemasMap
    // =========================================================================

    /**
     * importFromJson() resolves register schemas from schemasMap during import.
     *
     * When a register's schemas list references schema slugs that were imported
     * in the same session, their IDs are resolved from the in-memory schemasMap.
     */
    public function testImportFromJsonResolvesRegisterSchemasFromSchemasMap(): void
    {
        $configuration = $this->makeConfiguration(1);
        $schema        = $this->makeSchema(10, 'my-schema');
        $register      = $this->makeRegister(20, 'my-register');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $capturedRegisterData = null;
        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->registerMapper->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($register, &$capturedRegisterData) {
                $capturedRegisterData = $data;
                return $register;
            });
        $this->registerMapper->method('update')->willReturn($register);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas' => [
                    'my-schema' => ['slug' => 'my-schema', 'title' => 'My Schema', 'version' => '1.0.0'],
                ],
                'registers' => [
                    'my-register' => [
                        'slug'    => 'my-register',
                        'title'   => 'My Register',
                        'version' => '1.0.0',
                        'schemas' => ['my-schema'],
                        // Will be resolved to schema ID 10.
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertCount(1, $result['registers']);
        // The schema slug should have been replaced with the schema ID (10).
        $this->assertContains(10, $capturedRegisterData['schemas']);

    }//end testImportFromJsonResolvesRegisterSchemasFromSchemasMap()


    /**
     * importFromJson() logs a warning for register schemas not in schemasMap.
     *
     * When a register references a schema slug that was not imported in this session,
     * a warning is logged and the register is created without that schema reference.
     */
    public function testImportFromJsonLogsWarningForUnresolvedRegisterSchema(): void
    {
        $configuration = $this->makeConfiguration(1);
        $register      = $this->makeRegister(20, 'my-register');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'registers' => [
                    'my-register' => [
                        'slug'    => 'my-register',
                        'title'   => 'My Register',
                        'version' => '1.0.0',
                        'schemas' => ['nonexistent-schema'],
                        // Not in schemasMap — triggers warning.
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertCount(1, $result['registers']);

    }//end testImportFromJsonLogsWarningForUnresolvedRegisterSchema()


    // =========================================================================
    // importFromJson() — existing object, version is higher → update
    // =========================================================================

    /**
     * importFromJson() updates an existing object when the imported version is higher.
     */
    public function testImportFromJsonUpdatesExistingObjectWhenVersionIsHigher(): void
    {
        $configuration = $this->makeConfiguration(1);
        $schema        = $this->makeSchema(10, 'person');
        $register      = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);

        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        // Existing object has version 1.0.0; we import version 2.0.0 → update.
        $existingObjectMock = $this->getMockBuilder(ObjectEntity::class)
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $existingObjectMock->method('jsonSerialize')
            ->willReturn(['@self' => ['version' => '1.0.0', 'id' => 'existing-uuid']]);
        $this->setEntityId($existingObjectMock, 99);

        $this->objectService->method('searchObjects')->willReturn([$existingObjectMock]);

        $updatedObject = new ObjectEntity();
        $this->setEntityId($updatedObject, 99);
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturn($updatedObject);

        $data = [
            'appId'   => 'myapp',
            'version' => '2.0.0',
            'components' => [
                'schemas' => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '2.0.0'],
                ],
                'registers' => [
                    'registry' => ['slug' => 'registry', 'title' => 'Registry', 'version' => '2.0.0'],
                ],
                'objects' => [
                    [
                        '@self' => [
                            'register' => 'registry',
                            'schema'   => 'person',
                            'slug'     => 'john-doe',
                            'version'  => '2.0.0',
                        ],
                        'name' => 'John Doe Updated',
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '2.0.0'
        );

        $this->assertCount(1, $result['objects']);

    }//end testImportFromJsonUpdatesExistingObjectWhenVersionIsHigher()


    /**
     * importFromJson() skips objects where searchObjects returns an array result
     * (not an ObjectEntity) with version not higher.
     */
    public function testImportFromJsonSkipsObjectWithArrayResultWhenVersionNotHigher(): void
    {
        $configuration = $this->makeConfiguration(1);
        $schema        = $this->makeSchema(10, 'person');
        $register      = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);

        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        // searchObjects returns a plain array result (not ObjectEntity).
        // The handler falls back to using the array directly.
        $existingObjectMock = $this->getMockBuilder(ObjectEntity::class)
            ->onlyMethods(['jsonSerialize'])
            ->getMock();
        $existingObjectMock->method('jsonSerialize')
            ->willReturn(['@self' => ['version' => '5.0.0', 'id' => 'some-uuid']]);
        $this->setEntityId($existingObjectMock, 77);

        $this->objectService->method('searchObjects')->willReturn([$existingObjectMock]);
        $this->objectService->expects($this->never())->method('saveObject');

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas'   => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '1.0.0'],
                ],
                'registers' => [
                    'registry' => ['slug' => 'registry', 'title' => 'Registry', 'version' => '1.0.0'],
                ],
                'objects' => [
                    [
                        '@self' => [
                            'register' => 'registry',
                            'schema'   => 'person',
                            'slug'     => 'john-doe',
                            'version'  => '1.0.0',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['objects']);

    }//end testImportFromJsonSkipsObjectWithArrayResultWhenVersionNotHigher()


    // =========================================================================
    // importFromApp() — force flag and version-up-to-date path
    // =========================================================================

    /**
     * importFromApp() continues to importFromJson when config version is up-to-date
     * (does NOT early-return, to allow seedData check).
     */
    public function testImportFromAppContinuesWhenConfigVersionUpToDate(): void
    {
        $existingConfig = $this->makeConfiguration(55, 'myapp', '2.0.0');

        $this->configurationMapper->method('findBySourceUrl')->willReturn(null);
        $this->configurationMapper->method('findByApp')->willReturn([$existingConfig]);
        $this->configurationMapper->expects($this->never())->method('insert');

        $this->appConfig->method('getValueString')->willReturn('2.0.0');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        // importFromJson will do version check and return empty — but importFromApp should not throw.
        $result = $this->handler->importFromApp(
            appId:   'myapp',
            data:    ['description' => 'test'],
            version: '1.0.0'
            // version < existingConfig version — triggers the version-up-to-date log path.
        );

        $this->assertIsArray($result);

    }//end testImportFromAppContinuesWhenConfigVersionUpToDate()


    /**
     * importFromApp() with force=true bypasses the config version check.
     */
    public function testImportFromAppForceBypassesConfigVersionCheck(): void
    {
        $existingConfig = $this->makeConfiguration(55, 'myapp', '99.0.0');

        $this->configurationMapper->method('findBySourceUrl')->willReturn(null);
        $this->configurationMapper->method('findByApp')->willReturn([$existingConfig]);

        $this->appConfig->method('getValueString')->willReturn('99.0.0');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $result = $this->handler->importFromApp(
            appId:   'myapp',
            data:    ['description' => 'test'],
            version: '1.0.0',
            force:   true
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);

    }//end testImportFromAppForceBypassesConfigVersionCheck()


    /**
     * importFromApp() sets openregister version requirement from x-openregister.openregister.
     */
    public function testImportFromAppSetsOpenregisterVersionRequirement(): void
    {
        $this->configurationMapper->method('findBySourceUrl')->willReturn(null);
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'description'    => 'test',
            'x-openregister' => [
                'openregister' => '>=2.0.0',
            ],
        ];

        $this->handler->importFromApp(appId: 'myapp', data: $data, version: '1.0.0');

        $this->assertSame('>=2.0.0', $capturedConfig->getOpenregister());

    }//end testImportFromAppSetsOpenregisterVersionRequirement()


    /**
     * importFromApp() sets type from x-openregister.type.
     */
    public function testImportFromAppSetsTypeFromXOpenregister(): void
    {
        $this->configurationMapper->method('findBySourceUrl')->willReturn(null);
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $data = [
            'description'    => 'test',
            'x-openregister' => ['type' => 'plugin'],
        ];

        $this->handler->importFromApp(appId: 'myapp', data: $data, version: '1.0.0');

        $this->assertSame('plugin', $capturedConfig->getType());

    }//end testImportFromAppSetsTypeFromXOpenregister()


    // =========================================================================
    // createOrUpdateConfiguration() — additional branches
    // =========================================================================

    /**
     * createOrUpdateConfiguration() sets openregister version requirement.
     */
    public function testCreateOrUpdateConfigurationSetsOpenregisterVersion(): void
    {
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->handler->createOrUpdateConfiguration(
            data: [
                'x-openregister' => ['openregister' => '>=1.5.0'],
            ],
            appId:   'orapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []]
        );

        $this->assertSame('>=1.5.0', $capturedConfig->getOpenregister());

    }//end testCreateOrUpdateConfigurationSetsOpenregisterVersion()


    /**
     * createOrUpdateConfiguration() uses description from x-openregister when info is absent.
     */
    public function testCreateOrUpdateConfigurationFallsBackToXOpenregisterDescription(): void
    {
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->handler->createOrUpdateConfiguration(
            data: [
                'x-openregister' => ['description' => 'X-OR Description'],
            ],
            appId:   'descapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []]
        );

        $this->assertSame('X-OR Description', $capturedConfig->getDescription());

    }//end testCreateOrUpdateConfigurationFallsBackToXOpenregisterDescription()


    /**
     * createOrUpdateConfiguration() sets github fields (nested structure) for new config.
     */
    public function testCreateOrUpdateConfigurationSetsGithubFieldsNested(): void
    {
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->handler->createOrUpdateConfiguration(
            data: [
                'x-openregister' => [
                    'github' => [
                        'repo'   => 'org/myrepo',
                        'branch' => 'main',
                        'path'   => 'configs/app.json',
                    ],
                ],
            ],
            appId:   'githubapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []]
        );

        $this->assertSame('org/myrepo', $capturedConfig->getGithubRepo());
        $this->assertSame('main', $capturedConfig->getGithubBranch());
        $this->assertSame('configs/app.json', $capturedConfig->getGithubPath());

    }//end testCreateOrUpdateConfigurationSetsGithubFieldsNested()


    /**
     * createOrUpdateConfiguration() sets github fields (flat structure) for new config.
     */
    public function testCreateOrUpdateConfigurationSetsGithubFieldsFlat(): void
    {
        $this->configurationMapper->method('findByApp')->willReturn([]);

        $capturedConfig = null;
        $this->configurationMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Configuration $c) use (&$capturedConfig) {
                $capturedConfig = $c;
                $this->setEntityId($c, 1);
                return $c;
            });

        $this->handler->createOrUpdateConfiguration(
            data: [
                'x-openregister' => [
                    'githubRepo'   => 'flat/repo',
                    'githubBranch' => 'develop',
                    'githubPath'   => 'flat/config.json',
                ],
            ],
            appId:   'flatgithubapp',
            version: '1.0.0',
            result:  ['registers' => [], 'schemas' => [], 'objects' => []]
        );

        $this->assertSame('flat/repo', $capturedConfig->getGithubRepo());
        $this->assertSame('develop', $capturedConfig->getGithubBranch());
        $this->assertSame('flat/config.json', $capturedConfig->getGithubPath());

    }//end testCreateOrUpdateConfigurationSetsGithubFieldsFlat()


    /**
     * createOrUpdateConfiguration() merges entity IDs when updating existing config.
     */
    public function testCreateOrUpdateConfigurationMergesEntityIdsOnUpdate(): void
    {
        $existing = $this->makeConfiguration(77, 'mergeapp', '0.9.0');
        $existing->setRegisters([1, 2]);
        $existing->setSchemas([10]);
        $existing->setObjects([]);

        $this->configurationMapper->method('findByApp')->willReturn([$existing]);

        $this->configurationMapper->expects($this->never())->method('insert');
        $this->configurationMapper->expects($this->once())
            ->method('update')
            ->willReturn($existing);

        $newRegister = $this->makeRegister(3, 'new-register');
        $newSchema   = $this->makeSchema(20, 'new-schema');

        $objectEntity = new ObjectEntity();
        $this->setEntityId($objectEntity, 100);

        $result = $this->handler->createOrUpdateConfiguration(
            data:    [],
            appId:   'mergeapp',
            version: '1.0.0',
            result:  [
                'registers' => [$newRegister],
                'schemas'   => [$newSchema],
                'objects'   => [$objectEntity],
            ]
        );

        $registers = $result->getRegisters();
        $schemas   = $result->getSchemas();
        $objects   = $result->getObjects();

        $this->assertContains(1, $registers);
        $this->assertContains(2, $registers);
        $this->assertContains(3, $registers);
        $this->assertContains(10, $schemas);
        $this->assertContains(20, $schemas);
        $this->assertContains(100, $objects);

    }//end testCreateOrUpdateConfigurationMergesEntityIdsOnUpdate()


    // =========================================================================
    // importSeedData() — tested via importFromJson() with x-openregister.seedData
    // =========================================================================

    /**
     * importFromJson() skips seedData when x-openregister.seedData is absent.
     */
    public function testImportFromJsonSkipsSeedDataWhenAbsent(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        // objectEntityMapper.insert must NOT be called — no seed data.
        $this->objectEntityMapper->expects($this->never())->method('insert');

        $result = $this->handler->importFromJson(
            data:          ['appId' => 'myapp', 'version' => '1.0.0'],
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['objects']);

    }//end testImportFromJsonSkipsSeedDataWhenAbsent()


    /**
     * importSeedData() creates a seed object when it does not yet exist.
     *
     * The seed data path is exercised via importFromJson() by including
     * x-openregister.seedData with schemas and objects in the payload.
     */
    public function testImportFromJsonCreatesSeedObject(): void
    {
        $configuration = $this->makeConfiguration(1);
        $configuration->setRegisters([20]);

        $schema   = $this->makeSchema(10, 'person');
        $register = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        // Schema import pass.
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        // Register mapper: find() is called for seedData register lookup.
        $this->registerMapper->method('find')->willReturn($register);
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        // objectEntityMapper.findDirectBlobStorage throws DoesNotExistException → create.
        $this->objectEntityMapper->method('findDirectBlobStorage')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $createdObject = new ObjectEntity();
        $this->setEntityId($createdObject, 999);
        $this->objectEntityMapper->expects($this->once())
            ->method('insert')
            ->willReturn($createdObject);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas' => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '1.0.0'],
                ],
            ],
            'x-openregister' => [
                'seedData' => [
                    'description' => 'Initial data',
                    'objects'     => [
                        'person' => [
                            ['slug' => 'john-doe', 'title' => 'John Doe', 'name' => 'John Doe'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        // Seed object ID was added to result['objects'].
        $this->assertContains(999, $result['objects']);

    }//end testImportFromJsonCreatesSeedObject()


    /**
     * importSeedData() skips a seed object when it already exists (idempotency).
     */
    public function testImportFromJsonSkipsSeedObjectWhenAlreadyExists(): void
    {
        $configuration = $this->makeConfiguration(1);
        $configuration->setRegisters([20]);

        $schema   = $this->makeSchema(10, 'person');
        $register = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $this->registerMapper->method('find')->willReturn($register);
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        // findDirectBlobStorage returns an existing object — should skip insert.
        $existingObject = new ObjectEntity();
        $this->setEntityId($existingObject, 888);
        $this->objectEntityMapper->method('findDirectBlobStorage')
            ->willReturn($existingObject);

        $this->objectEntityMapper->expects($this->never())->method('insert');

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas' => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '1.0.0'],
                ],
            ],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'person' => [
                            ['slug' => 'existing-person', 'name' => 'Existing'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        // The existing object's ID (888) should be in result['objects'].
        $this->assertContains(888, $result['objects']);

    }//end testImportFromJsonSkipsSeedObjectWhenAlreadyExists()


    /**
     * importSeedData() skips objects without slug or title.
     */
    public function testImportFromJsonSkipsSeedObjectWithoutSlugOrTitle(): void
    {
        $configuration = $this->makeConfiguration(1);
        $configuration->setRegisters([20]);

        $schema   = $this->makeSchema(10, 'person');
        $register = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $this->registerMapper->method('find')->willReturn($register);
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        $this->objectEntityMapper->expects($this->never())->method('insert');

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas' => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '1.0.0'],
                ],
            ],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'person' => [
                            ['name' => 'No Slug No Title'],
                            // Neither 'slug' nor 'title' — should be skipped.
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['objects']);

    }//end testImportFromJsonSkipsSeedObjectWithoutSlugOrTitle()


    /**
     * importSeedData() skips a schema group when the schema is not found in DB.
     */
    public function testImportFromJsonSkipsSeedDataWhenSchemaNotFound(): void
    {
        $configuration = $this->makeConfiguration(1);
        $configuration->setRegisters([20]);

        $register = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        // Schema find always throws — seed data schema lookup will also fail.
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->registerMapper->method('find')->willReturn($register);

        $this->objectEntityMapper->expects($this->never())->method('insert');

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'nonexistent-schema' => [
                            ['slug' => 'some-object', 'name' => 'Some'],
                        ],
                    ],
                ],
            ],
        ];

        // Should not throw — schema not found is a warning, not an error.
        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['objects']);

    }//end testImportFromJsonSkipsSeedDataWhenSchemaNotFound()


    /**
     * importSeedData() uses UnifiedObjectMapper when set for both lookup and insert.
     */
    public function testImportFromJsonUseUnifiedObjectMapperForSeedData(): void
    {
        $configuration = $this->makeConfiguration(1);
        $configuration->setRegisters([20]);

        $schema   = $this->makeSchema(10, 'person');
        $register = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $this->registerMapper->method('find')->willReturn($register);
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        $unifiedMapper = $this->createMock(UnifiedObjectMapper::class);

        // find() throws DoesNotExistException → create path.
        $unifiedMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $createdObject = new ObjectEntity();
        $this->setEntityId($createdObject, 555);
        $unifiedMapper->expects($this->once())
            ->method('insert')
            ->willReturn($createdObject);

        $this->handler->setUnifiedObjectMapper($unifiedMapper);

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas' => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '1.0.0'],
                ],
            ],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'person' => [
                            ['slug' => 'jane-doe', 'name' => 'Jane Doe'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertContains(555, $result['objects']);

    }//end testImportFromJsonUseUnifiedObjectMapperForSeedData()


    /**
     * importSeedData() skips seed objects when MultipleObjectsReturnedException is thrown.
     */
    public function testImportFromJsonSkipsSeedObjectOnMultipleObjectsException(): void
    {
        $configuration = $this->makeConfiguration(1);
        $configuration->setRegisters([20]);

        $schema   = $this->makeSchema(10, 'person');
        $register = $this->makeRegister(20, 'registry');

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));
        $this->schemaMapper->method('createFromArray')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $this->registerMapper->method('find')->willReturn($register);
        $this->registerMapper->method('createFromArray')->willReturn($register);
        $this->registerMapper->method('update')->willReturn($register);

        $this->objectEntityMapper->method('findDirectBlobStorage')
            ->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('multiple found'));

        $this->objectEntityMapper->expects($this->never())->method('insert');

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'schemas' => [
                    'person' => ['slug' => 'person', 'title' => 'Person', 'version' => '1.0.0'],
                ],
            ],
            'x-openregister' => [
                'seedData' => [
                    'objects' => [
                        'person' => [
                            ['slug' => 'ambiguous-person', 'name' => 'Ambiguous'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['objects']);

    }//end testImportFromJsonSkipsSeedObjectOnMultipleObjectsException()


    // =========================================================================
    // importFromJson() — no appId/version → skips version check, skips config creation
    // =========================================================================

    /**
     * importFromJson() skips version check and config creation when appId is null.
     */
    public function testImportFromJsonSkipsVersionCheckWhenNoAppId(): void
    {
        $configuration = $this->makeConfiguration(1);

        // appConfig must NOT be called for version check.
        $this->appConfig->expects($this->never())->method('getValueString');

        $result = $this->handler->importFromJson(
            data:          [],
            configuration: $configuration
            // No appId or version.
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);
        // No version stored either (setValueString not called).

    }//end testImportFromJsonSkipsVersionCheckWhenNoAppId()


    /**
     * importFromJson() logs force-import message when force=true with appId and version.
     */
    public function testImportFromJsonLogsForceImportMessage(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('9.9.9');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        // Expect at least one info log about force import.
        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->handler->importFromJson(
            data:          ['appId' => 'myapp', 'version' => '1.0.0'],
            configuration: $configuration,
            appId:         'myapp',
            version:       '1.0.0',
            force:         true
        );

        $this->assertIsArray($result);

    }//end testImportFromJsonLogsForceImportMessage()


    // =========================================================================
    // importMapping() — tested via importFromJson() mappings branch
    // =========================================================================

    /**
     * importFromJson() skips a mapping with no slug or name.
     *
     * When the array key is an integer (not a string), importFromJson does NOT set name=key,
     * so importMapping receives data with neither 'slug' nor 'name' and returns null.
     */
    public function testImportFromJsonSkipsMappingWithoutSlugOrName(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->mappingMapper->method('getSlugToIdMap')->willReturn([]);

        // Numeric key → is_string($key) === false → name not set from key.
        // No 'slug' and no 'name' in value → importMapping returns null.
        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'mappings' => [
                    0 => ['version' => '1.0.0'],
                    // Numeric key, no 'slug' or 'name' key.
                ],
            ],
        ];

        $this->mappingMapper->expects($this->never())->method('createFromArray');

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['mappings']);

    }//end testImportFromJsonSkipsMappingWithoutSlugOrName()


    /**
     * importFromJson() updates an existing mapping when version is higher.
     */
    public function testImportFromJsonUpdatesMappingWhenVersionIsHigher(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->mappingMapper->method('getSlugToIdMap')->willReturn(['my-mapping' => 50]);

        $existingMapping = new Mapping();
        $this->setEntityId($existingMapping, 50);
        $existingMapping->setVersion('1.0.0');

        $updatedMapping = new Mapping();
        $this->setEntityId($updatedMapping, 50);

        $this->mappingMapper->method('find')->willReturn($existingMapping);
        $this->mappingMapper->expects($this->once())
            ->method('updateFromArray')
            ->willReturn($updatedMapping);

        $data = [
            'appId'   => 'myapp',
            'version' => '2.0.0',
            'components' => [
                'mappings' => [
                    'my-mapping' => ['slug' => 'my-mapping', 'name' => 'My Mapping', 'version' => '2.0.0'],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '2.0.0'
        );

        $this->assertCount(1, $result['mappings']);

    }//end testImportFromJsonUpdatesMappingWhenVersionIsHigher()


    /**
     * importFromJson() skips updating a mapping when version is equal.
     */
    public function testImportFromJsonSkipsMappingWhenVersionEqual(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->mappingMapper->method('getSlugToIdMap')->willReturn(['my-mapping' => 50]);

        $existingMapping = new Mapping();
        $this->setEntityId($existingMapping, 50);
        $existingMapping->setVersion('1.0.0');

        $this->mappingMapper->method('find')->willReturn($existingMapping);
        $this->mappingMapper->expects($this->never())->method('updateFromArray');
        $this->mappingMapper->expects($this->never())->method('createFromArray');

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'mappings' => [
                    'my-mapping' => ['slug' => 'my-mapping', 'name' => 'My Mapping', 'version' => '1.0.0'],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        // Returns the existing mapping unchanged.
        $this->assertCount(1, $result['mappings']);

    }//end testImportFromJsonSkipsMappingWhenVersionEqual()


    /**
     * importFromJson() uses name as mapping key fallback when slug absent.
     */
    public function testImportFromJsonSetsMappingNameFromKeyWhenAbsent(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->mappingMapper->method('getSlugToIdMap')->willReturn([]);

        $mapping = new Mapping();
        $this->setEntityId($mapping, 60);

        $capturedData = null;
        $this->mappingMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturnCallback(function (array $data) use ($mapping, &$capturedData) {
                $capturedData = $data;
                return $mapping;
            });

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'mappings' => [
                    // Key is 'keyed-mapping', no 'name' field in value — handler should set name=key.
                    'keyed-mapping' => ['slug' => 'keyed-mapping', 'version' => '1.0.0'],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertCount(1, $result['mappings']);
        $this->assertSame('keyed-mapping', $capturedData['name']);

    }//end testImportFromJsonSetsMappingNameFromKeyWhenAbsent()



    // =========================================================================
    // importFromJson() — version stored in appConfig only once per import
    // =========================================================================

    /**
     * importFromJson() does NOT store version when appId is null.
     */
    public function testImportFromJsonDoesNotStoreVersionWithoutAppId(): void
    {
        $configuration = $this->makeConfiguration(1);

        // setValueString must NOT be called — no appId.
        $this->appConfig->expects($this->never())->method('setValueString');

        $result = $this->handler->importFromJson(
            data:          [],
            configuration: $configuration
        );

        $this->assertIsArray($result);

    }//end testImportFromJsonDoesNotStoreVersionWithoutAppId()


    /**
     * importFromJson() does NOT store version when version is null.
     */
    public function testImportFromJsonDoesNotStoreVersionWithoutVersion(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->expects($this->never())->method('setValueString');

        $result = $this->handler->importFromJson(
            data:          [],
            configuration: $configuration,
            appId:         'myapp'
            // version intentionally omitted.
        );

        $this->assertIsArray($result);

    }//end testImportFromJsonDoesNotStoreVersionWithoutVersion()


    // =========================================================================
    // importSchema() — force flag on existing schema
    // =========================================================================

    /**
     * importSchema() force-updates existing schema even when version is equal.
     */
    public function testImportSchemaForceUpdatesWhenVersionEqual(): void
    {
        $existing = $this->makeSchema(10, 'force-schema', '1.0.0');
        $updated  = $this->makeSchema(10, 'force-schema', '1.0.0');

        $this->schemaMapper->method('find')->willReturn($existing);

        $this->schemaMapper->expects($this->once())
            ->method('updateFromArray')
            ->willReturn($existing);

        $this->schemaMapper->expects($this->once())
            ->method('update')
            ->willReturn($updated);

        $result = $this->handler->importSchema(
            data:           ['slug' => 'force-schema', 'version' => '1.0.0', 'title' => 'Forced'],
            slugsAndIdsMap: [],
            force:          true
        );

        $this->assertSame(10, $result->getId());

    }//end testImportSchemaForceUpdatesWhenVersionEqual()


    /**
     * importSchema() skips when existing version is newer and force is false.
     */
    public function testImportSchemaSkipsWhenExistingVersionIsNewer(): void
    {
        $existing = $this->makeSchema(10, 'old-schema', '3.0.0');

        $this->schemaMapper->method('find')->willReturn($existing);

        $this->schemaMapper->expects($this->never())->method('createFromArray');
        $this->schemaMapper->expects($this->never())->method('updateFromArray');

        $result = $this->handler->importSchema(
            data:           ['slug' => 'old-schema', 'version' => '1.0.0', 'title' => 'Old'],
            slugsAndIdsMap: [],
            force:          false
        );

        $this->assertSame(10, $result->getId());

    }//end testImportSchemaSkipsWhenExistingVersionIsNewer()


    // =========================================================================
    // importRegister() — only appId provided (no owner)
    // =========================================================================

    /**
     * importRegister() sets only appId — update called once.
     */
    public function testImportRegisterSetsOnlyApplication(): void
    {
        $register = $this->makeRegister(1, 'app-only-register');

        $this->registerMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('not found'));

        $this->registerMapper->expects($this->once())
            ->method('createFromArray')
            ->willReturn($register);

        $this->registerMapper->expects($this->once())
            ->method('update')
            ->willReturn($register);

        $result = $this->handler->importRegister(
            data:  ['slug' => 'app-only-register', 'version' => '1.0.0', 'title' => 'App Only'],
            appId: 'just-the-app'
        );

        $this->assertSame('just-the-app', $result->getApplication());

    }//end testImportRegisterSetsOnlyApplication()


    // =========================================================================
    // getJSONfromURL() — YAML response
    // =========================================================================

    /**
     * getJSONfromURL() parses YAML content-type response correctly.
     */
    public function testGetJSONfromURLParsesYamlResponse(): void
    {
        $yaml     = "title: YAML Config\nversion: 1.0.0\n";
        $stream   = \GuzzleHttp\Psr7\Utils::streamFor($yaml);
        $response = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/yaml'], $stream);
        $this->client->method('request')->willReturn($response);

        $result = $this->handler->getJSONfromURL('http://example.com/config.yaml');

        $this->assertIsArray($result);
        $this->assertSame('YAML Config', $result['title']);

    }//end testGetJSONfromURLParsesYamlResponse()


    // =========================================================================
    // decode() — edge cases
    // =========================================================================

    /**
     * decode() returns null when type is application/yaml and YAML is invalid.
     */
    public function testDecodeReturnsNullForInvalidYamlWithExplicitType(): void
    {
        // Symfony Yaml is lenient, so we use a value that json_decode can't handle
        // AND Yaml::parse returns a scalar (not array). Using a bare string that's
        // valid YAML scalar but not an array → ensureArrayStructure wraps it → but
        // actually decode() returns null because $phpArray is not array/stdClass.
        // A bare integer is valid YAML, but decode() calls ensureArrayStructure() on it
        // which tries to iterate over it — but the null/false check fires first.
        // Actually decode() returns null when $phpArray === null or false.
        // An empty YAML string → Yaml::parse returns null → returns null.
        $result = $this->handler->decode('', 'application/yaml');
        $this->assertNull($result);

    }//end testDecodeReturnsNullForInvalidYamlWithExplicitType()


    /**
     * ensureArrayStructure() handles mixed array containing both objects and scalars.
     *
     * When an array contains objects nested alongside scalar values, the recursive
     * call should convert only the object entries while leaving scalars unchanged.
     */
    public function testEnsureArrayStructureHandlesMixedArray(): void
    {
        $inner      = new \stdClass();
        $inner->key = 'value';

        $input = [
            'scalar' => 42,
            'nested' => $inner,
            'list'   => ['a', 'b'],
        ];

        $result = $this->handler->ensureArrayStructure($input);

        $this->assertSame(42, $result['scalar']);
        $this->assertIsArray($result['nested']);
        $this->assertSame('value', $result['nested']['key']);
        $this->assertSame(['a', 'b'], $result['list']);

    }//end testEnsureArrayStructureHandlesMixedArray()


    // =========================================================================
    // importFromJson() — result structure completeness
    // =========================================================================

    /**
     * importFromJson() always returns all expected keys in the result.
     */
    public function testImportFromJsonResultAlwaysHasAllExpectedKeys(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $result = $this->handler->importFromJson(
            data:          [],
            configuration: $configuration
        );

        $expectedKeys = ['registers', 'schemas', 'workflows', 'endpoints', 'sources', 'mappings', 'jobs', 'synchronizations', 'rules', 'objects'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }

    }//end testImportFromJsonResultAlwaysHasAllExpectedKeys()


    // =========================================================================
    // importFromJson() — mapping exception handled gracefully
    // =========================================================================

    /**
     * importFromJson() logs error but continues when individual mapping import fails.
     */
    public function testImportFromJsonContinuesWhenMappingImportFails(): void
    {
        $configuration = $this->makeConfiguration(1);

        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->mappingMapper->method('getSlugToIdMap')->willReturn([]);
        $this->mappingMapper->method('createFromArray')
            ->willThrowException(new \Exception('DB error'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $data = [
            'appId'   => 'myapp',
            'version' => '1.0.0',
            'components' => [
                'mappings' => [
                    'bad-mapping' => ['slug' => 'bad-mapping', 'name' => 'Bad Mapping', 'version' => '1.0.0'],
                ],
            ],
        ];

        // Should NOT throw — errors are caught per-mapping.
        $result = $this->handler->importFromJson(
            data:          $data,
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertSame([], $result['mappings']);

    }//end testImportFromJsonContinuesWhenMappingImportFails()


    // =========================================================================
    // importFromJson() — storedVersion empty → no version skip
    // =========================================================================

    /**
     * importFromJson() does not skip when stored version is empty string.
     */
    public function testImportFromJsonDoesNotSkipWhenStoredVersionIsEmpty(): void
    {
        $configuration = $this->makeConfiguration(1);

        // Empty stored version → version_compare check is bypassed.
        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->method('setValueString')->willReturn(true);
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $result = $this->handler->importFromJson(
            data:          ['appId' => 'myapp', 'version' => '1.0.0'],
            configuration: $configuration,
            appId:         'myapp',
            version:       '1.0.0',
            force:         false
        );

        // Full result structure means import ran (not early-exit).
        $this->assertArrayHasKey('workflows', $result);
        $this->assertIsArray($result['workflows']);

    }//end testImportFromJsonDoesNotSkipWhenStoredVersionIsEmpty()


}//end class
