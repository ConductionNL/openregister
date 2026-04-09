<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SaveObjects refactored methods.
 *
 * Note: Many originally tested private methods (createEmptyResult, logBulkOperationStart,
 * initializeResult, mergeChunkResult, calculatePerformanceMetrics) have been inlined
 * into the saveObjects() method and are no longer independently testable.
 * The functionality they covered is now tested via the public API in SaveObjectsTest.
 */
class SaveObjectsRefactoredMethodsTest extends TestCase
{
    private SaveObjects $saveObjects;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saveObjects = new SaveObjects(
            $this->createMock(MagicMapper::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(RegisterMapper::class),
            $this->createMock(SaveObject::class),
            $this->createMock(IUserSession::class),
            $this->createMock(OrganisationService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * Test that saveObjects returns correct empty result for empty input.
     */
    public function testSaveObjectsEmptyInputReturnsEmptyResult(): void
    {
        $result = $this->saveObjects->saveObjects([]);

        $this->assertSame([], $result['saved']);
        $this->assertSame([], $result['updated']);
        $this->assertSame([], $result['unchanged']);
        $this->assertSame([], $result['invalid']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['statistics']['totalProcessed']);
        $this->assertSame(0, $result['statistics']['saved']);
        $this->assertSame(0, $result['statistics']['updated']);
        $this->assertSame(0, $result['statistics']['unchanged']);
        $this->assertSame(0, $result['statistics']['invalid']);
        $this->assertSame(0, $result['statistics']['errors']);
        $this->assertSame(0, $result['statistics']['processingTimeMs']);
    }

    /**
     * Test initializeSaveResult via reflection.
     */
    public function testInitializeSaveResultStructure(): void
    {
        $ref = new \ReflectionClass(SaveObjects::class);
        $method = $ref->getMethod('initializeSaveResult');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->saveObjects, [10]);

        $this->assertSame(10, $result['statistics']['totalProcessed']);
        $this->assertSame([], $result['saved']);
        $this->assertSame([], $result['updated']);
        $this->assertSame([], $result['unchanged']);
        $this->assertSame([], $result['invalid']);
        $this->assertSame([], $result['errors']);
    }

    /**
     * Test calculateOptimalChunkSize via reflection.
     */
    public function testCalculateOptimalChunkSizeSmall(): void
    {
        $ref = new \ReflectionClass(SaveObjects::class);
        $method = $ref->getMethod('calculateOptimalChunkSize');
        $method->setAccessible(true);

        $this->assertSame(100, $method->invokeArgs($this->saveObjects, [100]));
        $this->assertSame(1000, $method->invokeArgs($this->saveObjects, [1000]));
        $this->assertSame(2500, $method->invokeArgs($this->saveObjects, [2000]));
        $this->assertSame(5000, $method->invokeArgs($this->saveObjects, [8000]));
        $this->assertSame(10000, $method->invokeArgs($this->saveObjects, [30000]));
        $this->assertSame(20000, $method->invokeArgs($this->saveObjects, [100000]));
    }
}
