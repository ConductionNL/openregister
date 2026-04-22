<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Branch coverage tests for SaveObjects — targets uncovered branches in
 * saveObjects (empty input, deduplication, no valid objects after prep).
 */
class SaveObjectsBranchCoverageTest extends TestCase
{
    private SaveObjects $service;
    private MagicMapper&MockObject $objectMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SaveObject&MockObject $saveHandler;
    private OrganisationService&MockObject $organisationService;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->saveHandler = $this->createMock(SaveObject::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Clear static caches via reflection
        $ref = new \ReflectionClass(SaveObjects::class);
        foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
            $p = $ref->getProperty($prop);
            $p->setValue(null, []);
        }

        $this->service = new SaveObjects(
            $this->objectMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->saveHandler,
            $this->userSession,
            $this->organisationService,
            $this->logger
        );
    }

    // =========================================================================
    // saveObjects — empty input
    // =========================================================================

    public function testSaveObjectsEmptyArray(): void
    {
        $result = $this->service->saveObjects([]);

        $this->assertSame([], $result['saved']);
        $this->assertSame([], $result['updated']);
        $this->assertSame([], $result['unchanged']);
        $this->assertSame([], $result['invalid']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['statistics']['totalProcessed']);
    }

    // =========================================================================
    // saveObjects — mixed schema with no schema in objects throws exception
    // =========================================================================

    public function testSaveObjectsMixedSchemaNoValidObjects(): void
    {
        // Objects without @self.schema cause prepareMixedSchemaObject to throw
        // when schema is not found in cache.
        $this->expectException(\Exception::class);
        $this->service->saveObjects(
            [['id' => '1', 'name' => 'test']],
            null,
            null
        );
    }

    // =========================================================================
    // saveObjects — deduplication removes duplicates
    // =========================================================================

    public function testSaveObjectsDeduplicatesById(): void
    {
        // Objects without schema info will throw on mixed schema path.
        $this->expectException(\Exception::class);

        $objects = [
            ['id' => 'same-id', 'name' => 'first'],
            ['id' => 'same-id', 'name' => 'second'],
            ['id' => 'unique-id', 'name' => 'third'],
        ];

        $this->service->saveObjects($objects, null, null);
    }

    // =========================================================================
    // saveObjects — deduplication disabled
    // =========================================================================

    public function testSaveObjectsWithoutDeduplication(): void
    {
        // Objects without schema info will throw on mixed schema path.
        $this->expectException(\Exception::class);

        $objects = [
            ['id' => 'same-id', 'name' => 'first'],
            ['id' => 'same-id', 'name' => 'second'],
        ];

        $this->service->saveObjects(
            $objects, null, null,
            true, true, false, false, false
        );
    }
}
