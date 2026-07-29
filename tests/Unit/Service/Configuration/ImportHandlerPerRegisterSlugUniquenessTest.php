<?php
/**
 * Unit tests for per-register schema slug uniqueness during configuration import.
 *
 * openspec/changes/per-register-schema-slug-uniqueness. Proves the fix for the
 * OpenBuild `automation` incident: an importing register's schema resolution
 * must be scoped to the TARGET register's own schema set, not global and not
 * merely per-application, or an importer can silently reuse (or be shadowed
 * by) a same-slug schema owned by a completely different register/app.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * A small in-memory fake persistence layer stands in for SchemaMapper /
 * RegisterMapper so the same object identities survive across MULTIPLE
 * `importFromJson()` calls in one test — which is what proves (or disproves)
 * cross-import slug resolution. Plain stateless mocks cannot do this: the
 * whole point under test is behaviour that only shows up across two imports.
 */
class ImportHandlerPerRegisterSlugUniquenessTest extends TestCase
{

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    private ImportHandler $handler;

    /** @var array<int, Schema> id => Schema, the fake schemas "table". */
    private array $schemaStore = [];

    /** @var array<string, Register> slug(lower) => Register, the fake registers "table". */
    private array $registerStore = [];

    private int $nextSchemaId = 100;

    private int $nextRegisterId = 200;


    /**
     * Wire an ImportHandler whose SchemaMapper/RegisterMapper are backed by
     * simple in-memory arrays that behave like the real queries this change
     * relies on: findBySlugInIds() matches within an explicit id set,
     * RegisterMapper::find() looks up by slug, createFromArray()/updateFromArray()
     * mutate the SAME stored object identity so a later lookup sees the change.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

        $objectEntityMapper  = $this->createMock(MagicMapper::class);
        $configurationMapper = $this->createMock(ConfigurationMapper::class);
        $mappingMapper       = $this->createMock(MappingMapper::class);
        $client              = $this->createMock(Client::class);
        $appConfig           = $this->createMock(IAppConfig::class);
        $logger              = $this->createMock(LoggerInterface::class);
        $uploadHandler       = $this->createMock(UploadHandler::class);
        $objectService       = $this->createMock(ObjectService::class);

        $appConfig->method('getValueString')->willReturn('');
        $appConfig->method('setValueString')->willReturn(true);
        $mappingMapper->method('getSlugToIdMap')->willReturn([]);

        // --- SchemaMapper fake ---------------------------------------------------

        $this->schemaMapper->method('getSlugToIdMap')->willReturn([]);

        $this->schemaMapper->method('findBySlugInIds')->willReturnCallback(
            function (string $slug, array $schemaIds): ?Schema {
                foreach ($schemaIds as $id) {
                    $candidate = ($this->schemaStore[$id] ?? null);
                    if ($candidate !== null && strtolower((string) $candidate->getSlug()) === strtolower($slug)) {
                        return $candidate;
                    }
                }

                return null;
            }
        );

        // Org/global find(): first schema anywhere with a matching slug — used
        // only for the (non-binding) foreign-owner visibility log.
        $this->schemaMapper->method('find')->willReturnCallback(
            function (string $id): Schema {
                foreach ($this->schemaStore as $candidate) {
                    if (strtolower((string) $candidate->getSlug()) === strtolower($id)) {
                        return $candidate;
                    }
                }

                throw new DoesNotExistException('schema not found: '.$id);
            }
        );

        $this->schemaMapper->method('createFromArray')->willReturnCallback(
            function (array $data): Schema {
                $id     = $this->nextSchemaId++;
                $schema = new Schema();
                $schema->setSlug($data['slug']);
                $schema->setVersion($data['version'] ?? '1.0.0');
                $this->setEntityId($schema, $id);
                $this->schemaStore[$id] = $schema;
                return $schema;
            }
        );

        $this->schemaMapper->method('updateFromArray')->willReturnCallback(
            function (int $id, array $data): Schema {
                $schema = $this->schemaStore[$id];
                if (isset($data['version']) === true) {
                    $schema->setVersion($data['version']);
                }

                return $schema;
            }
        );

        $this->schemaMapper->method('update')->willReturnArgument(0);

        // --- RegisterMapper fake --------------------------------------------------

        $this->registerMapper->method('find')->willReturnCallback(
            function (string $id): Register {
                $existing = ($this->registerStore[strtolower($id)] ?? null);
                if ($existing !== null) {
                    return $existing;
                }

                throw new DoesNotExistException('register not found: '.$id);
            }
        );

        $this->registerMapper->method('createFromArray')->willReturnCallback(
            function (array $data): Register {
                $id       = $this->nextRegisterId++;
                $register = new Register();
                $register->setSlug($data['slug']);
                $register->setVersion($data['version'] ?? '1.0.0');
                $register->setSchemas($data['schemas'] ?? []);
                $this->setEntityId($register, $id);
                $this->registerStore[strtolower($data['slug'])] = $register;
                return $register;
            }
        );

        $this->registerMapper->method('updateFromArray')->willReturnCallback(
            function (int $id, array $data): Register {
                foreach ($this->registerStore as $register) {
                    if ($register->getId() === $id) {
                        if (isset($data['schemas']) === true) {
                            $register->setSchemas($data['schemas']);
                        }

                        if (isset($data['version']) === true) {
                            $register->setVersion($data['version']);
                        }

                        return $register;
                    }
                }

                throw new DoesNotExistException('register id not found: '.$id);
            }
        );

        $this->registerMapper->method('update')->willReturnArgument(0);

        $this->handler = new ImportHandler(
            schemaMapper:        $this->schemaMapper,
            registerMapper:      $this->registerMapper,
            objectEntityMapper:  $objectEntityMapper,
            configurationMapper: $configurationMapper,
            mappingMapper:       $mappingMapper,
            client:              $client,
            appConfig:           $appConfig,
            logger:              $logger,
            appDataPath:         '/tmp',
            uploadHandler:       $uploadHandler,
            objectService:       $objectService
        );

    }//end setUp()


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
     * Build a minimal Configuration entity.
     */
    private function makeConfiguration(int $id, string $app='test-app'): Configuration
    {
        $config = new Configuration();
        $config->setApp($app);
        $config->setRegisters([]);
        $config->setSchemas([]);
        $config->setObjects([]);
        $this->setEntityId($config, $id);
        return $config;

    }//end makeConfiguration()


    /**
     * Build a one-register, one-schema import payload.
     */
    private function makeImportPayload(
        string $appId,
        string $registerSlug,
        string $schemaSlug,
        string $version='1.0.0'
    ): array {
        return [
            'appId'   => $appId,
            'version' => $version,
            'components' => [
                'schemas' => [
                    $schemaSlug => ['slug' => $schemaSlug, 'title' => ucfirst($schemaSlug), 'version' => $version],
                ],
                'registers' => [
                    $registerSlug => [
                        'slug'    => $registerSlug,
                        'title'   => ucfirst($registerSlug),
                        'version' => $version,
                        'schemas' => [$schemaSlug],
                    ],
                ],
            ],
        ];

    }//end makeImportPayload()


    /**
     * CORE BUG FIX. Two different registers (different apps) each import a
     * schema under the SAME slug ('automation') → two distinct schema rows,
     * each attached to its own register — neither reuses, binds to, or
     * overwrites the other's.
     *
     * This is the OpenBuild-vs-CRM reproduction: prior to this change, the
     * second importer's schema resolution could find and reuse the first
     * importer's same-slug row instead of creating its own.
     */
    public function testTwoRegistersImportingSameSlugGetDistinctSchemas(): void
    {
        $configuration = $this->makeConfiguration(1);

        $resultA = $this->handler->importFromJson(
            data:          $this->makeImportPayload(appId: 'openbuild-app', registerSlug: 'openbuild-register', schemaSlug: 'automation'),
            configuration: $configuration,
            version:       '1.0.0'
        );

        $resultB = $this->handler->importFromJson(
            data:          $this->makeImportPayload(appId: 'crm-app', registerSlug: 'crm-register', schemaSlug: 'automation'),
            configuration: $configuration,
            version:       '1.0.0'
        );

        $this->assertCount(1, $resultA['schemas']);
        $this->assertCount(1, $resultB['schemas']);

        $schemaA = $resultA['schemas'][0];
        $schemaB = $resultB['schemas'][0];

        $this->assertSame('automation', $schemaA->getSlug());
        $this->assertSame('automation', $schemaB->getSlug());
        $this->assertNotSame($schemaA->getId(), $schemaB->getId());

        // Each register carries only its OWN schema's id.
        $registerA = $this->registerStore['openbuild-register'];
        $registerB = $this->registerStore['crm-register'];

        $this->assertSame([$schemaA->getId()], $registerA->getSchemas());
        $this->assertSame([$schemaB->getId()], $registerB->getSchemas());
        $this->assertNotContains($schemaB->getId(), $registerA->getSchemas());
        $this->assertNotContains($schemaA->getId(), $registerB->getSchemas());

        // Two distinct rows exist in the "table".
        $this->assertCount(2, $this->schemaStore);

    }//end testTwoRegistersImportingSameSlugGetDistinctSchemas()


    /**
     * Re-importing the same slug into the SAME register updates the existing
     * schema in place — no duplicate row is created for that register.
     */
    public function testReimportingSameSlugIntoSameRegisterUpdatesInPlace(): void
    {
        $configuration = $this->makeConfiguration(1);

        $first = $this->handler->importFromJson(
            data:          $this->makeImportPayload(appId: 'my-app', registerSlug: 'my-register', schemaSlug: 'automation', version: '1.0.0'),
            configuration: $configuration,
            version:       '1.0.0'
        );

        $originalSchema   = $first['schemas'][0];
        $originalSchemaId = $originalSchema->getId();

        // Re-import the SAME register/slug with a newer version (force also
        // exercised so the version-gate never masks the resolution behaviour
        // under test).
        $second = $this->handler->importFromJson(
            data:          $this->makeImportPayload(appId: 'my-app', registerSlug: 'my-register', schemaSlug: 'automation', version: '2.0.0'),
            configuration: $configuration,
            version:       '2.0.0',
            force:         true
        );

        $this->assertCount(1, $second['schemas']);
        $updatedSchema = $second['schemas'][0];

        $this->assertSame($originalSchemaId, $updatedSchema->getId());
        $this->assertSame('2.0.0', $updatedSchema->getVersion());

        // Still exactly ONE row for this slug — not duplicated.
        $this->assertCount(1, $this->schemaStore);

        // The register still references exactly one schema id for this slug.
        $register = $this->registerStore['my-register'];
        $this->assertSame([$originalSchemaId], $register->getSchemas());

    }//end testReimportingSameSlugIntoSameRegisterUpdatesInPlace()


    /**
     * REGRESSION GUARD (769-shared-schemas case). A schema legitimately
     * referenced by TWO registers in one import stays a single row, and a
     * later, unrelated re-import of ONE of those registers does not fork a
     * new row nor disturb the sibling register's reference to it.
     */
    public function testSchemaSharedAcrossMultipleRegistersIsUntouched(): void
    {
        $configuration = $this->makeConfiguration(1);

        // One import declares TWO registers that both reference the SAME
        // schema slug — the intentional many-to-many sharing case.
        $shared = [
            'appId'   => 'shared-app',
            'version' => '1.0.0',
            'components' => [
                'schemas' => [
                    'contact' => ['slug' => 'contact', 'title' => 'Contact', 'version' => '1.0.0'],
                ],
                'registers' => [
                    'register-c' => [
                        'slug' => 'register-c', 'title' => 'Register C', 'version' => '1.0.0', 'schemas' => ['contact'],
                    ],
                    'register-d' => [
                        'slug' => 'register-d', 'title' => 'Register D', 'version' => '1.0.0', 'schemas' => ['contact'],
                    ],
                ],
            ],
        ];

        $result = $this->handler->importFromJson(data: $shared, configuration: $configuration, version: '1.0.0');

        $this->assertCount(1, $result['schemas'], 'One shared schema, not one per register');
        $sharedSchemaId = $result['schemas'][0]->getId();

        $registerC = $this->registerStore['register-c'];
        $registerD = $this->registerStore['register-d'];
        $this->assertSame([$sharedSchemaId], $registerC->getSchemas());
        $this->assertSame([$sharedSchemaId], $registerD->getSchemas());

        // Now re-import register-C ALONE (register-D absent from this
        // import), with a newer version, forcing an update.
        $reimportC = [
            'appId'   => 'shared-app',
            'version' => '2.0.0',
            'components' => [
                'schemas' => [
                    'contact' => ['slug' => 'contact', 'title' => 'Contact Updated', 'version' => '2.0.0'],
                ],
                'registers' => [
                    'register-c' => [
                        'slug' => 'register-c', 'title' => 'Register C', 'version' => '2.0.0', 'schemas' => ['contact'],
                    ],
                ],
            ],
        ];

        $result2 = $this->handler->importFromJson(
            data:          $reimportC,
            configuration: $configuration,
            version:       '2.0.0',
            force:         true
        );

        $this->assertCount(1, $result2['schemas']);
        $this->assertSame($sharedSchemaId, $result2['schemas'][0]->getId(), 'Updated in place, not duplicated');
        $this->assertCount(1, $this->schemaStore, 'Still exactly one schema row for the shared slug');

        // register-D, untouched by the second import, still references the
        // SAME shared schema id — it was never touched or orphaned.
        $this->assertSame([$sharedSchemaId], $this->registerStore['register-d']->getSchemas());

    }//end testSchemaSharedAcrossMultipleRegistersIsUntouched()


    /**
     * importSchema() unit-level: a null $registerSchemaIds (schema not
     * declared by any register in this import) falls back to the previous
     * application-scoped resolution, unchanged.
     */
    public function testImportSchemaFallsBackToAppScopedResolutionWithoutRegisterContext(): void
    {
        $existing = new Schema();
        $existing->setSlug('standalone');
        $existing->setVersion('1.0.0');
        $this->setEntityId($existing, 555);

        $appScoped = $this->createMock(SchemaMapper::class);
        $appScoped->method('findByApplicationAndSlug')->willReturn($existing);
        $appScoped->method('updateFromArray')->willReturn($existing);
        $appScoped->method('update')->willReturnArgument(0);

        $handler = new ImportHandler(
            schemaMapper:        $appScoped,
            registerMapper:      $this->createMock(RegisterMapper::class),
            objectEntityMapper:  $this->createMock(MagicMapper::class),
            configurationMapper: $this->createMock(ConfigurationMapper::class),
            mappingMapper:       $this->createMock(MappingMapper::class),
            client:              $this->createMock(Client::class),
            appConfig:           $this->createMock(IAppConfig::class),
            logger:              $this->createMock(LoggerInterface::class),
            appDataPath:         '/tmp',
            uploadHandler:       $this->createMock(UploadHandler::class),
            objectService:       $this->createMock(ObjectService::class)
        );

        $result = $handler->importSchema(
            data:              ['slug' => 'standalone', 'title' => 'Standalone', 'version' => '2.0.0'],
            slugsAndIdsMap:    [],
            appId:             'my-app',
            version:           '2.0.0',
            force:             true,
            registerSchemaIds: null
        );

        // Resolved via the app-scoped fallback (id 555), not created fresh.
        $this->assertSame(555, $result->getId());

    }//end testImportSchemaFallsBackToAppScopedResolutionWithoutRegisterContext()


    /**
     * importSchema() unit-level: a non-null but EMPTY $registerSchemaIds (the
     * target register exists but does not yet own this slug) creates a new
     * schema rather than falling back to app/global resolution — it must NOT
     * reuse a foreign same-slug schema just because the register-scoped
     * lookup came back empty.
     */
    public function testImportSchemaWithEmptyRegisterScopeCreatesNewSchema(): void
    {
        $foreignOwned = new Schema();
        $foreignOwned->setSlug('automation');
        $foreignOwned->setVersion('1.0.0');
        $this->setEntityId($foreignOwned, 71);

        $created = new Schema();
        $created->setSlug('automation');
        $this->setEntityId($created, 999);

        $mapper = $this->createMock(SchemaMapper::class);
        $mapper->method('findBySlugInIds')->willReturn(null);
        // A foreign app owns this slug elsewhere — visible, but must not bind.
        $mapper->method('find')->willReturn($foreignOwned);
        $mapper->expects($this->never())->method('findByApplicationAndSlug');
        $mapper->method('createFromArray')->willReturn($created);
        $mapper->method('update')->willReturnArgument(0);

        $handler = new ImportHandler(
            schemaMapper:        $mapper,
            registerMapper:      $this->createMock(RegisterMapper::class),
            objectEntityMapper:  $this->createMock(MagicMapper::class),
            configurationMapper: $this->createMock(ConfigurationMapper::class),
            mappingMapper:       $this->createMock(MappingMapper::class),
            client:              $this->createMock(Client::class),
            appConfig:           $this->createMock(IAppConfig::class),
            logger:              $this->createMock(LoggerInterface::class),
            appDataPath:         '/tmp',
            uploadHandler:       $this->createMock(UploadHandler::class),
            objectService:       $this->createMock(ObjectService::class)
        );

        $result = $handler->importSchema(
            data:              ['slug' => 'automation', 'title' => 'Automation', 'version' => '1.0.0'],
            slugsAndIdsMap:    [],
            appId:             'openbuild-app',
            version:           '1.0.0',
            registerSchemaIds: []
        );

        // A NEW schema (999), not the foreign one (71).
        $this->assertSame(999, $result->getId());
        $this->assertNotSame(71, $result->getId());

    }//end testImportSchemaWithEmptyRegisterScopeCreatesNewSchema()


}//end class
