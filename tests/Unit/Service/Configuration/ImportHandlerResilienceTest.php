<?php
/**
 * Per-entity import resilience + no-user-context acting-user fallback.
 *
 * Locks the import-resilient-per-entity-and-no-user-context change:
 *  - one bad register/object never aborts sibling registers/schemas/objects;
 *  - a name-missing entity is skipped (logged warning), not fatal;
 *  - import without a logged-in user still creates registers + schemas and
 *    resolves a fallback admin acting user for object/folder operations.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resilience + no-user-context fallback regression tests for ImportHandler.
 */
class ImportHandlerResilienceTest extends TestCase
{

    private SchemaMapper&MockObject $schemaMapper;

    private RegisterMapper&MockObject $registerMapper;

    private MagicMapper&MockObject $objectEntityMapper;

    private ConfigurationMapper&MockObject $configurationMapper;

    private MappingMapper&MockObject $mappingMapper;

    private Client&MockObject $client;

    private IAppConfig&MockObject $appConfig;

    private LoggerInterface&MockObject $logger;

    private UploadHandler&MockObject $uploadHandler;

    private ObjectService&MockObject $objectService;

    private ImportHandler $handler;

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

        // No previously-imported version -> never skip on version check.
        $this->appConfig->method('getValueString')->willReturn('');
        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
        $this->registerMapper->method('getSlugToIdMap')->willReturn([]);
        $this->mappingMapper->method('getSlugToIdMap')->willReturn([]);

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
     * Build a hydrated Register entity with a given id + slug.
     */
    private function makeRegister(int $id, string $slug): Register
    {
        $register = new Register();
        $register->hydrate(['slug' => $slug, 'version' => '1.0.0']);
        $register->setId($id);
        return $register;
    }//end makeRegister()

    /**
     * Build a hydrated Schema entity with a given id + slug.
     */
    private function makeSchema(int $id, string $slug): Schema
    {
        $schema = new Schema();
        $schema->hydrate(['slug' => $slug, 'title' => $slug, 'version' => '1.0.0', 'properties' => []]);
        $schema->setId($id);
        return $schema;
    }//end makeSchema()

    /**
     * A failing register MUST be skipped (logged warning + counter) while the
     * valid register AND all schemas are still created.
     */
    public function testOneBadRegisterDoesNotAbortSiblingRegisterOrSchemas(): void
    {
        // Both registers are new (find throws DoesNotExist).
        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        // Schema creation succeeds for both schemas.
        $this->schemaMapper->method('createFromArray')
            ->willReturnCallback(fn(array $d) => $this->makeSchema(10, $d['slug']));
        $this->schemaMapper->method('update')->willReturnArgument(0);

        // 'good' register persists, 'bad' register throws on create.
        $this->registerMapper->method('createFromArray')
            ->willReturnCallback(function (array $d) {
                if (($d['slug'] ?? '') === 'bad') {
                    throw new RuntimeException('The required property (name) is missing');
                }

                return $this->makeRegister(1, $d['slug']);
            });
        $this->registerMapper->method('update')->willReturnArgument(0);

        $data = [
            'appId'      => 'pipelinq',
            'version'    => '9.9.9',
            'components' => [
                'schemas'   => [
                    'thing' => ['slug' => 'thing', 'title' => 'Thing', 'properties' => []],
                ],
                'registers' => [
                    'good' => ['slug' => 'good', 'version' => '1.0.0', 'schemas' => ['thing']],
                    'bad'  => ['slug' => 'bad', 'version' => '1.0.0', 'schemas' => ['thing']],
                ],
            ],
        ];

        $config = new Configuration();
        $result = $this->handler->importFromJson(data: $data, configuration: $config, appId: 'pipelinq', version: '9.9.9');

        // The good register is created; the bad one is skipped, not fatal.
        $this->assertCount(1, $result['registers'], 'valid register must still be created');
        $this->assertSame('good', $result['registers'][0]->getSlug());
        $this->assertSame(1, $result['skipped']['registers'], 'bad register counted as skipped');

        // Schemas are unaffected by the register failure.
        $this->assertCount(1, $result['schemas'], 'schema must be created despite the bad register');
    }//end testOneBadRegisterDoesNotAbortSiblingRegisterOrSchemas()

    /**
     * A name-missing object MUST be skipped (logged warning + counter) while the
     * valid object is still saved, and the import returns instead of throwing.
     */
    public function testNameMissingObjectIsSkippedNotFatal(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->registerMapper->method('createFromArray')
            ->willReturnCallback(fn(array $d) => $this->makeRegister(1, $d['slug']));
        $this->registerMapper->method('update')->willReturnArgument(0);

        $this->schemaMapper->method('createFromArray')
            ->willReturnCallback(fn(array $d) => $this->makeSchema(10, $d['slug']));
        $this->schemaMapper->method('update')->willReturnArgument(0);

        // No existing objects.
        $this->objectService->method('searchObjects')->willReturn([]);

        // 'bad' object throws a validation failure; 'good' saves fine.
        $this->objectService->method('saveObject')
            ->willReturnCallback(function (array $object) {
                $slug = $object['@self']['slug'] ?? null;
                if ($slug === 'bad') {
                    throw new RuntimeException('The required property (name) is missing');
                }

                $entity = new \OCA\OpenRegister\Db\ObjectEntity();
                $entity->setUuid('uuid-'.$slug);
                return $entity;
            });

        $data = [
            'appId'      => 'pipelinq',
            'version'    => '9.9.9',
            'components' => [
                'schemas'   => [
                    'thing' => ['slug' => 'thing', 'title' => 'Thing', 'properties' => []],
                ],
                'registers' => [
                    'reg' => ['slug' => 'reg', 'version' => '1.0.0', 'schemas' => ['thing']],
                ],
                'objects'   => [
                    ['@self' => ['register' => 'reg', 'schema' => 'thing', 'slug' => 'good']],
                    ['@self' => ['register' => 'reg', 'schema' => 'thing', 'slug' => 'bad']],
                ],
            ],
        ];

        $config = new Configuration();
        $result = $this->handler->importFromJson(data: $data, configuration: $config, appId: 'pipelinq', version: '9.9.9');

        $this->assertCount(1, $result['objects'], 'valid object must still be saved');
        $this->assertSame(1, $result['skipped']['objects'], 'name-missing object counted as skipped');
        // Registers + schemas unaffected.
        $this->assertCount(1, $result['registers']);
        $this->assertCount(1, $result['schemas']);
    }//end testNameMissingObjectIsSkippedNotFatal()

    /**
     * Without a logged-in user the import still creates registers + schemas and
     * forwards a resolved fallback admin as the acting user to saveObject().
     */
    public function testImportUnderNoUserSessionStillCreatesRegistersAndSchemas(): void
    {
        // No session user (occ path).
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $this->handler->setUserSession($session);

        // Fallback admin available via the admin group.
        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('admin');
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$admin]);
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->with('admin')->willReturn($group);
        $this->handler->setGroupManager($groupManager);
        $this->handler->setUserManager($this->createMock(IUserManager::class));

        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->registerMapper->method('createFromArray')
            ->willReturnCallback(fn(array $d) => $this->makeRegister(1, $d['slug']));
        $this->registerMapper->method('update')->willReturnArgument(0);

        $this->schemaMapper->method('createFromArray')
            ->willReturnCallback(fn(array $d) => $this->makeSchema(10, $d['slug']));
        $this->schemaMapper->method('update')->willReturnArgument(0);

        $this->objectService->method('searchObjects')->willReturn([]);

        // Assert the resolved admin is forwarded as the acting user. saveObject()'s
        // 10th parameter is $currentUser; the production code passes it by name and
        // PHP maps it onto that positional slot.
        $capturedUser = null;
        $this->objectService->method('saveObject')
            ->willReturnCallback(function (
                $object,
                $extend = [],
                $register = null,
                $schema = null,
                $uuid = null,
                $rbac = true,
                $multitenancy = true,
                $silent = false,
                $uploadedFiles = null,
                $currentUser = null
            ) use (&$capturedUser) {
                $capturedUser = $currentUser;
                $entity = new \OCA\OpenRegister\Db\ObjectEntity();
                $entity->setUuid('uuid');
                return $entity;
            });

        $data = [
            'appId'      => 'shillinq',
            'version'    => '9.9.9',
            'components' => [
                'schemas'   => [
                    'thing' => ['slug' => 'thing', 'title' => 'Thing', 'properties' => []],
                ],
                'registers' => [
                    'reg' => ['slug' => 'reg', 'version' => '1.0.0', 'schemas' => ['thing']],
                ],
                'objects'   => [
                    ['@self' => ['register' => 'reg', 'schema' => 'thing', 'slug' => 'one']],
                ],
            ],
        ];

        $config = new Configuration();
        $result = $this->handler->importFromJson(data: $data, configuration: $config, appId: 'shillinq', version: '9.9.9');

        $this->assertCount(1, $result['registers'], 'register created without a user session');
        $this->assertCount(1, $result['schemas'], 'schema created without a user session');
        $this->assertCount(1, $result['objects'], 'object created using the fallback admin acting user');
        $this->assertSame($admin, $capturedUser, 'fallback admin forwarded as the acting user to saveObject');
    }//end testImportUnderNoUserSessionStillCreatesRegistersAndSchemas()
}//end class
