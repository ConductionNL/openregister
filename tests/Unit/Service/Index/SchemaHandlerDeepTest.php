<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Index;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Index\SchemaHandler;
use OCA\OpenRegister\Service\Index\SearchBackendInterface;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class SchemaHandlerDeepTest extends TestCase
{
    private SchemaHandler $handler;
    private SchemaMapper|MockObject $schemaMapper;
    private LoggerInterface|MockObject $logger;
    private IConfig|MockObject $config;
    private SearchBackendInterface|MockObject $searchBackend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(IConfig::class);
        $this->searchBackend = $this->createMock(SearchBackendInterface::class);

        $this->handler = new SchemaHandler(
            $this->schemaMapper,
            $this->logger,
            $this->config,
            $this->searchBackend
        );
    }

    public function testEnsureVectorFieldTypeAlreadyExists(): void
    {
        $this->searchBackend->method('getFieldTypes')
            ->willReturn(['knn_vector' => ['class' => 'solr.DenseVectorField']]);

        $result = $this->handler->ensureVectorFieldType('test_collection');

        $this->assertTrue($result);
    }

    public function testEnsureVectorFieldTypeCreatesNew(): void
    {
        $this->searchBackend->method('getFieldTypes')->willReturn([]);
        $this->searchBackend->method('addFieldType')->willReturn(true);

        $result = $this->handler->ensureVectorFieldType('test_collection', 768, 'dot_product');

        $this->assertTrue($result);
    }

    public function testEnsureVectorFieldTypeException(): void
    {
        $this->searchBackend->method('getFieldTypes')
            ->willThrowException(new Exception('connection error'));

        $result = $this->handler->ensureVectorFieldType('test');

        $this->assertFalse($result);
    }

    public function testGetCollectionFieldStatusSuccess(): void
    {
        $this->searchBackend->method('getFields')->willReturn([
            'id' => ['type' => 'string'],
            'uuid' => ['type' => 'string'],
            'name' => ['type' => 'text'],
        ]);

        $result = $this->handler->getCollectionFieldStatus('test');

        $this->assertEquals('test', $result['collection']);
        $this->assertArrayHasKey('existing_fields', $result);
        $this->assertArrayHasKey('missing_fields', $result);
    }

    public function testGetCollectionFieldStatusException(): void
    {
        $this->searchBackend->method('getFields')
            ->willThrowException(new Exception('connection error'));

        $result = $this->handler->getCollectionFieldStatus('test');

        $this->assertArrayHasKey('error', $result);
    }

    public function testCreateMissingFieldsDryRun(): void
    {
        $missing = ['test_field' => ['name' => 'test_field', 'type' => 'string']];

        $result = $this->handler->createMissingFields('test', $missing, true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['dry_run']);
    }

    public function testCreateMissingFieldsActual(): void
    {
        $this->searchBackend->method('addOrUpdateField')->willReturn('created');

        $missing = ['test_field' => ['name' => 'test_field', 'type' => 'string']];
        $result = $this->handler->createMissingFields('test', $missing, false);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['created']);
    }

    public function testFixMismatchedFieldsException(): void
    {
        $this->searchBackend->method('fixMismatchedFields')
            ->willThrowException(new Exception('fix error'));

        $result = $this->handler->fixMismatchedFields(['field1' => []]);

        $this->assertFalse($result['success']);
    }

    public function testDetermineSolrFieldType(): void
    {
        $ref = new ReflectionClass(SchemaHandler::class);
        $method = $ref->getMethod('determineSolrFieldType');
        $method->setAccessible(true);

        $this->assertEquals('integer', $method->invoke($this->handler, ['type' => 'integer']));
        $this->assertEquals('integer', $method->invoke($this->handler, ['type' => 'int']));
        $this->assertEquals('float', $method->invoke($this->handler, ['type' => 'number']));
        $this->assertEquals('boolean', $method->invoke($this->handler, ['type' => 'boolean']));
        $this->assertEquals('date', $method->invoke($this->handler, ['type' => 'date']));
        $this->assertEquals('string', $method->invoke($this->handler, ['type' => 'object']));
        $this->assertEquals('string', $method->invoke($this->handler, []));
    }

    public function testIsMultiValued(): void
    {
        $ref = new ReflectionClass(SchemaHandler::class);
        $method = $ref->getMethod('isMultiValued');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->handler, ['type' => 'array']));
        $this->assertTrue($method->invoke($this->handler, ['maxItems' => 5]));
        $this->assertFalse($method->invoke($this->handler, ['type' => 'string']));
        $this->assertFalse($method->invoke($this->handler, ['maxItems' => 1]));
    }

    public function testGetMostPermissiveType(): void
    {
        $ref = new ReflectionClass(SchemaHandler::class);
        $method = $ref->getMethod('getMostPermissiveType');
        $method->setAccessible(true);

        $this->assertEquals('string', $method->invoke($this->handler, ['string', 'integer']));
        $this->assertEquals('text', $method->invoke($this->handler, ['text', 'boolean']));
        $this->assertEquals('float', $method->invoke($this->handler, ['float', 'integer']));
    }

    public function testGenerateSolrFieldName(): void
    {
        $ref = new ReflectionClass(SchemaHandler::class);
        $method = $ref->getMethod('generateSolrFieldName');
        $method->setAccessible(true);

        $this->assertEquals('my_field', $method->invoke($this->handler, 'My Field'));
        $this->assertEquals('test_name', $method->invoke($this->handler, 'test-name'));
    }
}
