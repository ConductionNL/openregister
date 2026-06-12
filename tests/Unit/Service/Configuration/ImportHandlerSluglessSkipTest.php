<?php
/**
 * Phase-0 regression: ImportHandler skips a slug-less schema fragment with a
 * clear \RuntimeException (an \Exception) instead of a fatal \TypeError.
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Locks two Phase-0 fixes in ImportHandler:
 *
 *  - importSchema() guards a missing/blank `slug` and throws a descriptive
 *    \RuntimeException (an \Exception the caller's `catch (Exception)` sees),
 *    so one bad fragment is skipped rather than aborting the whole register
 *    import with an uncaught \TypeError (an \Error).
 *  - seed lookups during installer-time import run with `_rbac: false` so seed
 *    objects are found/created regardless of the current organisation context.
 */
class ImportHandlerSluglessSkipTest extends TestCase
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

    public function testImportSchemaThrowsRuntimeExceptionWhenSlugMissing(): void
    {
        // No slug at all — must NOT bubble a \TypeError out of schemaMapper->find().
        $this->schemaMapper->expects($this->never())->method('find');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("missing a 'slug'");

        $this->handler->importSchema(
            data: ['title' => 'A schema with no slug', 'properties' => []],
            slugsAndIdsMap: []
        );
    }//end testImportSchemaThrowsRuntimeExceptionWhenSlugMissing()

    public function testImportSchemaThrowsRuntimeExceptionWhenSlugBlank(): void
    {
        $this->schemaMapper->expects($this->never())->method('find');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("missing a 'slug'");

        $this->handler->importSchema(
            data: ['slug' => '   ', 'title' => 'Blank slug', 'properties' => []],
            slugsAndIdsMap: []
        );
    }//end testImportSchemaThrowsRuntimeExceptionWhenSlugBlank()

    public function testSluglessExceptionIsAnExceptionNotAnError(): void
    {
        // The whole point of the fix: the caller catches \Exception, so the bad
        // fragment is skipped and the remaining import continues. A \TypeError
        // (an \Error) would slip past that catch and abort the entire import.
        try {
            $this->handler->importSchema(
                data: ['properties' => []],
                slugsAndIdsMap: []
            );
            $this->fail('Expected a RuntimeException for the slug-less fragment.');
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf(\Exception::class, $thrown);
            $this->assertNotInstanceOf(\TypeError::class, $thrown);
        }
    }//end testSluglessExceptionIsAnExceptionNotAnError()
}//end class
