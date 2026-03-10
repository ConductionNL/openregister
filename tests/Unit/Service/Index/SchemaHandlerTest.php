<?php

declare(strict_types=1);

/**
 * SchemaHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Index
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Index;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Index\SchemaHandler;
use OCA\OpenRegister\Service\Index\SearchBackendInterface;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Index SchemaHandler
 *
 * Tests vector field type management and collection field status.
 */
class SchemaHandlerTest extends TestCase
{
    /** @var SchemaHandler */
    private SchemaHandler $handler;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var SearchBackendInterface&MockObject */
    private SearchBackendInterface $searchBackend;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        /** @var IConfig&MockObject $config */
        $config = $this->createMock(IConfig::class);
        $this->searchBackend = $this->createMock(SearchBackendInterface::class);

        $this->handler = new SchemaHandler(
            $this->schemaMapper,
            $this->logger,
            $config,
            $this->searchBackend
        );
    }

    // =========================================================================
    // ensureVectorFieldType
    // =========================================================================

    public function testEnsureVectorFieldTypeAlreadyExists(): void
    {
        $this->searchBackend->method('getFieldTypes')
            ->willReturn(['knn_vector' => ['name' => 'knn_vector']]);

        $result = $this->handler->ensureVectorFieldType('my-collection');

        $this->assertTrue($result);
    }

    public function testEnsureVectorFieldTypeCreatesNew(): void
    {
        $this->searchBackend->method('getFieldTypes')
            ->willReturn([]);

        $this->searchBackend->expects($this->once())
            ->method('addFieldType')
            ->willReturn(true);

        $result = $this->handler->ensureVectorFieldType('my-collection', 768, 'cosine');

        $this->assertTrue($result);
    }

    public function testEnsureVectorFieldTypeHandlesException(): void
    {
        $this->searchBackend->method('getFieldTypes')
            ->willThrowException(new \Exception('Connection failed'));

        $result = $this->handler->ensureVectorFieldType('my-collection');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getCollectionFieldStatus
    // =========================================================================

    public function testGetCollectionFieldStatusAllPresent(): void
    {
        $currentFields = [
            'id'           => ['name' => 'id'],
            'uuid'         => ['name' => 'uuid'],
            'name'         => ['name' => 'name'],
            'title'        => ['name' => 'title'],
            'summary'      => ['name' => 'summary'],
            'description'  => ['name' => 'description'],
            'created'      => ['name' => 'created'],
            'updated'      => ['name' => 'updated'],
            'published'    => ['name' => 'published'],
            'deleted'      => ['name' => 'deleted'],
            'owner'        => ['name' => 'owner'],
            'organisation' => ['name' => 'organisation'],
            'register'     => ['name' => 'register'],
            'schema'       => ['name' => 'schema'],
        ];

        $this->searchBackend->method('getFields')
            ->willReturn($currentFields);

        $result = $this->handler->getCollectionFieldStatus('test-collection');

        $this->assertSame('test-collection', $result['collection']);
        $this->assertSame(14, $result['total_fields']);
        $this->assertSame(14, $result['expected_fields']);
        $this->assertEmpty($result['missing_fields']);
    }

    public function testGetCollectionFieldStatusWithMissingFields(): void
    {
        $this->searchBackend->method('getFields')
            ->willReturn(['id' => ['name' => 'id']]);

        $result = $this->handler->getCollectionFieldStatus('test-collection');

        $this->assertNotEmpty($result['missing_fields']);
        $this->assertArrayHasKey('uuid', $result['missing_fields']);
    }

    public function testGetCollectionFieldStatusHandlesException(): void
    {
        $this->searchBackend->method('getFields')
            ->willThrowException(new \Exception('Backend unavailable'));

        $result = $this->handler->getCollectionFieldStatus('test-collection');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('test-collection', $result['collection']);
    }

    // =========================================================================
    // createMissingFields
    // =========================================================================

    public function testCreateMissingFieldsDryRun(): void
    {
        $missingFields = [
            'uuid'  => ['name' => 'uuid', 'type' => 'string'],
            'title' => ['name' => 'title', 'type' => 'text'],
        ];

        $result = $this->handler->createMissingFields('test-collection', $missingFields, true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['dry_run']);
        $this->assertContains('uuid', $result['fields_to_add']);
        $this->assertContains('title', $result['fields_to_add']);
    }

    // =========================================================================
    // fixMismatchedFields
    // =========================================================================

    public function testFixMismatchedFieldsDelegates(): void
    {
        $mismatchedFields = ['field1' => ['expected' => 'string', 'actual' => 'pint']];

        $this->searchBackend->method('fixMismatchedFields')
            ->willReturn(['success' => true, 'fixed' => 1]);

        $result = $this->handler->fixMismatchedFields($mismatchedFields);

        $this->assertTrue($result['success']);
    }

    public function testFixMismatchedFieldsHandlesException(): void
    {
        $this->searchBackend->method('fixMismatchedFields')
            ->willThrowException(new \Exception('Fix failed'));

        $result = $this->handler->fixMismatchedFields([]);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
}
