<?php

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SolrObjectService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorEmbeddingService;
use OCA\OpenRegister\Db\Mapper\SchemaMapper;
use OCA\OpenRegister\Db\Mapper\RegisterMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaEntity;
use OCA\OpenRegister\Db\RegisterEntity;
use Test\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SolrObjectService
 *
 * @group DB
 */
class SolrObjectServiceTest extends TestCase
{
	/** @var GuzzleSolrService|MockObject */
	private $guzzleSolrService;
	
	/** @var SettingsService|MockObject */
	private $settingsService;
	
	/** @var SchemaMapper|MockObject */
	private $schemaMapper;
	
	/** @var RegisterMapper|MockObject */
	private $registerMapper;
	
	/** @var LoggerInterface|MockObject */
	private $logger;
	
	/** @var SolrObjectService */
	private $service;
	
	protected function setUp(): void
	{
		parent::setUp();
		
		$this->guzzleSolrService = $this->createMock(GuzzleSolrService::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		
		// Mock settings to return a default object collection.
		$this->settingsService
			->method('getSolrSettingsOnly')
			->willReturn(['objectCollection' => 'test_objects']);
		
		$this->service = new SolrObjectService(
			$this->guzzleSolrService,
			$this->settingsService,
			$this->schemaMapper,
			$this->registerMapper,
			$this->logger
		);
	}
	
	public function testConvertObjectToTextSimple()
	{
		$object = new ObjectEntity();
		$object->setUuid('test-uuid-123');
		$object->setData([
			'title' => 'Test Object',
			'description' => 'A test description'
		]);
		
		$text = $this->service->convertObjectToText($object);
		
		$this->assertIsString($text);
		$this->assertStringContainsString('test-uuid-123', $text);
		$this->assertStringContainsString('Test Object', $text);
		$this->assertStringContainsString('A test description', $text);
	}
	
	public function testConvertObjectToTextWithNestedData()
	{
		$object = new ObjectEntity();
		$object->setUuid('nested-uuid');
		$object->setData([
			'title' => 'Nested Object',
			'metadata' => [
				'author' => 'John Doe',
				'tags' => ['test', 'example', 'nested'],
				'details' => [
					'level2' => [
						'level3' => 'Deep value'
					]
				]
			]
		]);
		
		$text = $this->service->convertObjectToText($object);
		
		$this->assertStringContainsString('Nested Object', $text);
		$this->assertStringContainsString('John Doe', $text);
		$this->assertStringContainsString('test', $text);
		$this->assertStringContainsString('example', $text);
		$this->assertStringContainsString('Deep value', $text);
	}
	
	public function testConvertObjectToTextWithBooleans()
	{
		$object = new ObjectEntity();
		$object->setUuid('bool-test');
		$object->setData([
			'active' => true,
			'deleted' => false,
			'verified' => true
		]);
		
		$text = $this->service->convertObjectToText($object);
		
		$this->assertStringContainsString('active true', $text);
		$this->assertStringContainsString('deleted false', $text);
		$this->assertStringContainsString('verified true', $text);
	}
	
	public function testConvertObjectToTextWithNumbers()
	{
		$object = new ObjectEntity();
		$object->setUuid('number-test');
		$object->setData([
			'count' => 42,
			'price' => 19.99,
			'percentage' => 75.5
		]);
		
		$text = $this->service->convertObjectToText($object);
		
		$this->assertStringContainsString('42', $text);
		$this->assertStringContainsString('19.99', $text);
		$this->assertStringContainsString('75.5', $text);
	}
	
	public function testConvertObjectToTextSkipsNullAndEmpty()
	{
		$object = new ObjectEntity();
		$object->setUuid('empty-test');
		$object->setData([
			'title' => 'Valid Title',
			'null_field' => null,
			'empty_string' => '',
			'empty_array' => [],
			'description' => 'Valid Description'
		]);
		
		$text = $this->service->convertObjectToText($object);
		
		$this->assertStringContainsString('Valid Title', $text);
		$this->assertStringContainsString('Valid Description', $text);
		$this->assertStringNotContainsString('null_field', $text);
		$this->assertStringNotContainsString('empty_string', $text);
	}
	
	public function testConvertObjectsToText()
	{
		$objects = [
			$this->createObjectWithTitle('Object 1'),
			$this->createObjectWithTitle('Object 2'),
			$this->createObjectWithTitle('Object 3'),
		];
		
		$texts = $this->service->convertObjectsToText($objects);
		
		$this->assertCount(3, $texts);
		$this->assertStringContainsString('Object 1', $texts[0]);
		$this->assertStringContainsString('Object 2', $texts[1]);
		$this->assertStringContainsString('Object 3', $texts[2]);
	}
	
	public function testConvertObjectsToTextEmptyArray()
	{
		$texts = $this->service->convertObjectsToText([]);
		
		$this->assertIsArray($texts);
		$this->assertEmpty($texts);
	}
	
	public function testConvertObjectToTextWithSchemaAndRegister()
	{
		$schema = new SchemaEntity();
		$schema->setTitle('Test Schema');
		$schema->setDescription('Schema Description');
		
		$register = new RegisterEntity();
		$register->setTitle('Test Register');
		$register->setDescription('Register Description');
		
		$this->schemaMapper
			->method('find')
			->willReturn($schema);
			
		$this->registerMapper
			->method('find')
			->willReturn($register);
		
		$object = new ObjectEntity();
		$object->setUuid('with-schema-register');
		$object->setSchema(1);
		$object->setRegister(1);
		$object->setData(['title' => 'Object Title']);
		
		$text = $this->service->convertObjectToText($object);
		
		$this->assertStringContainsString('Test Schema', $text);
		$this->assertStringContainsString('Schema Description', $text);
		$this->assertStringContainsString('Test Register', $text);
		$this->assertStringContainsString('Register Description', $text);
		$this->assertStringContainsString('Object Title', $text);
	}
	
	public function testConvertObjectToTextHandlesRecursionDepth()
	{
		// Create deeply nested structure.
		$deepData = ['level0' => []];
		$current = &$deepData['level0'];
		for ($i = 1; $i <= 15; $i++) {
			$current['level' . $i] = [];
			$current = &$current['level' . $i];
		}
		$current['deep_value'] = 'Found me!';
		
		$object = new ObjectEntity();
		$object->setUuid('deep-nest');
		$object->setData($deepData);
		
		$text = $this->service->convertObjectToText($object);
		
		// Should stop at max depth (10 by default).
		$this->assertIsString($text);
	}
	
	/**
	 * Helper method to create object with title
	 *
	 * @param string $title
	 * @return ObjectEntity
	 */
	private function createObjectWithTitle(string $title): ObjectEntity
	{
		$object = new ObjectEntity();
		$object->setUuid('uuid-' . md5($title));
		$object->setData(['title' => $title]);
		return $object;
	}
}

