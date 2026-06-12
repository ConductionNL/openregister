<?php
/**
 * Unit tests for the `@ref:<slug>` seed-reference resolver in ImportHandler.
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

/**
 * Locks the behaviour of resolveSeedReferenceTokens(): seed objects reference
 * siblings by `@ref:<slug>` (or `@ref:<schema>:<slug>`), and the resolver must
 * rewrite those tokens to the target object's UUID before validation. Covers
 * bare/qualified/nested resolution, idempotent reuse of a persisted UUID,
 * ambiguity, unresolved tokens, and the dangling-reference guard.
 */
class ImportHandlerRefResolverTest extends TestCase
{

    /**
     * The schema mapper mock.
     *
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper&MockObject $schemaMapper;

    /**
     * The register mapper mock.
     *
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper&MockObject $registerMapper;

    /**
     * The object entity (magic) mapper mock.
     *
     * @var MagicMapper&MockObject
     */
    private MagicMapper&MockObject $objectEntityMapper;

    /**
     * The configuration mapper mock.
     *
     * @var ConfigurationMapper&MockObject
     */
    private ConfigurationMapper&MockObject $configurationMapper;

    /**
     * The mapping mapper mock.
     *
     * @var MappingMapper&MockObject
     */
    private MappingMapper&MockObject $mappingMapper;

    /**
     * The HTTP client mock.
     *
     * @var Client&MockObject
     */
    private Client&MockObject $client;

    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The upload handler mock.
     *
     * @var UploadHandler&MockObject
     */
    private UploadHandler&MockObject $uploadHandler;

    /**
     * The object service mock.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * The handler under test.
     *
     * @var ImportHandler
     */
    private ImportHandler $handler;

    /**
     * Build the handler with mocked dependencies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->objectEntityMapper  = $this->createMock(MagicMapper::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->mappingMapper       = $this->createMock(MappingMapper::class);
        $this->client        = $this->createMock(Client::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->uploadHandler = $this->createMock(UploadHandler::class);
        $this->objectService = $this->createMock(ObjectService::class);

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
     * Make register + schema lookups succeed (so targets are "importable").
     *
     * @return void
     */
    private function stubResolvableRegisterAndSchema(): void
    {
        // Real entities: getId() is a magic Entity method and cannot be stubbed
        // on a mock, so build concrete instances with an id set.
        $register = new Register();
        $register->setId(1);
        $schema = new Schema();
        $schema->setId(2);

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
    }//end stubResolvableRegisterAndSchema()

    /**
     * Invoke the private resolver and return the resolved objects list.
     *
     * @param array $objects The seed objects (components.objects).
     *
     * @return array The resolved objects.
     */
    private function resolve(array $objects): array
    {
        $reflection = new \ReflectionMethod(ImportHandler::class, 'resolveSeedReferenceTokens');
        $reflection->setAccessible(true);
        $out = $reflection->invoke($this->handler, ['components' => ['objects' => $objects]]);
        return $out['components']['objects'];
    }//end resolve()

    /**
     * A bare `@ref:<slug>` resolves to the target object's UUID.
     *
     * @return void
     */
    public function testBareRefResolvesToTargetUuid(): void
    {
        $this->stubResolvableRegisterAndSchema();
        $this->objectService->method('searchObjects')->willReturn([]);

        $objects = [
            ['@self' => ['register' => 'r', 'schema' => 'cashShift', 'slug' => 'shift-1', 'id' => 'UUID-SHIFT-1']],
            ['@self' => ['register' => 'r', 'schema' => 'cashDrop', 'slug' => 'drop-1'], 'shift' => '@ref:shift-1'],
        ];

        $out = $this->resolve($objects);
        $this->assertSame('UUID-SHIFT-1', $out[1]['shift']);
    }//end testBareRefResolvesToTargetUuid()

    /**
     * A schema-qualified `@ref:<schema>:<slug>` resolves to the target UUID.
     *
     * @return void
     */
    public function testSchemaQualifiedRefResolves(): void
    {
        $this->stubResolvableRegisterAndSchema();
        $this->objectService->method('searchObjects')->willReturn([]);

        $objects = [
            ['@self' => ['register' => 'r', 'schema' => 'cashShift', 'slug' => 'shift-1', 'id' => 'UUID-SHIFT-1']],
            ['@self' => ['register' => 'r', 'schema' => 'cashDrop', 'slug' => 'drop-1'], 'shift' => '@ref:cashShift:shift-1'],
        ];

        $out = $this->resolve($objects);
        $this->assertSame('UUID-SHIFT-1', $out[1]['shift']);
    }//end testSchemaQualifiedRefResolves()

    /**
     * Tokens nested inside arrays resolve; non-token strings are untouched.
     *
     * @return void
     */
    public function testRefInsideNestedListResolves(): void
    {
        $this->stubResolvableRegisterAndSchema();
        $this->objectService->method('searchObjects')->willReturn([]);

        $objects = [
            ['@self' => ['register' => 'r', 'schema' => 'cashShift', 'slug' => 'shift-1', 'id' => 'UUID-SHIFT-1']],
            [
                '@self'  => ['register' => 'r', 'schema' => 'report', 'slug' => 'rep-1'],
                'nested' => ['list' => ['@ref:shift-1', 'plain-string']],
            ],
        ];

        $out = $this->resolve($objects);
        $this->assertSame('UUID-SHIFT-1', $out[1]['nested']['list'][0]);
        $this->assertSame('plain-string', $out[1]['nested']['list'][1]);
    }//end testRefInsideNestedListResolves()

    /**
     * An `@ref:` to an unknown slug is left verbatim (so validation surfaces it).
     *
     * @return void
     */
    public function testUnresolvedRefLeftVerbatim(): void
    {
        $this->stubResolvableRegisterAndSchema();
        $this->objectService->method('searchObjects')->willReturn([]);

        $objects = [
            ['@self' => ['register' => 'r', 'schema' => 'cashDrop', 'slug' => 'drop-1'], 'shift' => '@ref:does-not-exist'],
        ];

        $out = $this->resolve($objects);
        $this->assertSame('@ref:does-not-exist', $out[0]['shift']);
    }//end testUnresolvedRefLeftVerbatim()

    /**
     * A bare ref to a slug under multiple schemas is ambiguous (left verbatim);
     * the schema-qualified form still resolves.
     *
     * @return void
     */
    public function testAmbiguousBareRefLeftVerbatim(): void
    {
        $this->stubResolvableRegisterAndSchema();
        $this->objectService->method('searchObjects')->willReturn([]);

        // Same slug under two schemas → bare @ref is ambiguous → left verbatim.
        $objects = [
            ['@self' => ['register' => 'r', 'schema' => 'schemaA', 'slug' => 'dup', 'id' => 'UUID-A']],
            ['@self' => ['register' => 'r', 'schema' => 'schemaB', 'slug' => 'dup', 'id' => 'UUID-B']],
            ['@self' => ['register' => 'r', 'schema' => 'src', 'slug' => 'src-1'], 'ref' => '@ref:dup'],
            ['@self' => ['register' => 'r', 'schema' => 'src', 'slug' => 'src-2'], 'ref' => '@ref:schemaA:dup'],
        ];

        $out = $this->resolve($objects);
        // Bare ambiguous reference is not resolved.
        $this->assertSame('@ref:dup', $out[2]['ref']);
        // Schema-qualified disambiguation still resolves.
        $this->assertSame('UUID-A', $out[3]['ref']);
    }//end testAmbiguousBareRefLeftVerbatim()

    /**
     * A persisted target's stored UUID wins over an explicit seed id, so
     * re-imports resolve references to a stable identity.
     *
     * @return void
     */
    public function testIdempotentReuseOfPersistedUuid(): void
    {
        $this->stubResolvableRegisterAndSchema();
        // Target already persisted: searchObjects returns it with its stored id,
        // which must win over any minted/explicit id so re-imports are stable.
        $this->objectService->method('searchObjects')->willReturn([['@self' => ['id' => 'PERSISTED-UUID']]]);

        $objects = [
            ['@self' => ['register' => 'r', 'schema' => 'cashShift', 'slug' => 'shift-1', 'id' => 'EXPLICIT-DIFFERENT']],
            ['@self' => ['register' => 'r', 'schema' => 'cashDrop', 'slug' => 'drop-1'], 'shift' => '@ref:shift-1'],
        ];

        $out = $this->resolve($objects);
        $this->assertSame('PERSISTED-UUID', $out[1]['shift']);
        $this->assertSame('PERSISTED-UUID', $out[0]['@self']['id']);
    }//end testIdempotentReuseOfPersistedUuid()

    /**
     * A target whose register/schema is unresolvable is not pre-assigned an id,
     * so the referring token stays unresolved instead of dangling.
     *
     * @return void
     */
    public function testDanglingRefGuardWhenRegisterUnresolvable(): void
    {
        // Register/schema cannot be resolved → target will be skipped by the
        // import loop, so it must NOT be pre-assigned an id; the token stays
        // unresolved rather than pointing at a fabricated, never-stored UUID.
        $this->registerMapper->method('find')->willThrowException(new \RuntimeException('no such register'));
        $this->schemaMapper->method('find')->willThrowException(new \RuntimeException('no such schema'));

        $objects = [
            ['@self' => ['register' => 'missing', 'schema' => 'cashShift', 'slug' => 'shift-1', 'id' => 'UUID-SHIFT-1']],
            ['@self' => ['register' => 'missing', 'schema' => 'cashDrop', 'slug' => 'drop-1'], 'shift' => '@ref:shift-1'],
        ];

        $out = $this->resolve($objects);
        $this->assertSame('@ref:shift-1', $out[1]['shift']);
    }//end testDanglingRefGuardWhenRegisterUnresolvable()

    /**
     * With no `@ref:` tokens, the resolver is a no-op and touches no services.
     *
     * @return void
     */
    public function testNoOpWhenNoTokens(): void
    {
        // No @ref tokens anywhere → resolver returns data untouched and never
        // hits the mappers/object service.
        $this->registerMapper->expects($this->never())->method('find');
        $this->objectService->expects($this->never())->method('searchObjects');

        $objects = [
            ['@self' => ['register' => 'r', 'schema' => 's', 'slug' => 'a'], 'value' => 'plain'],
        ];

        $out = $this->resolve($objects);
        $this->assertSame('plain', $out[0]['value']);
    }//end testNoOpWhenNoTokens()
}//end class
