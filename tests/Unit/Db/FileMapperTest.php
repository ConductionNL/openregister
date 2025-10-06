<?php

declare(strict_types=1);

/**
 * FileMapperTest
 *
 * Basic unit tests for the FileMapper class to verify file
 * database operations and CRUD functionality.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenRegister/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FileMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * File Mapper Test Suite
 *
 * Basic unit tests for file database operations focusing on
 * class structure and basic functionality.
 *
 * @coversDefaultClass FileMapper
 */
class FileMapperTest extends TestCase
{
    private FileMapper $fileMapper;
    private IDBConnection|MockObject $db;
    private IURLGenerator|MockObject $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->fileMapper = new FileMapper($this->db, $this->urlGenerator);
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(FileMapper::class, $this->fileMapper);
    }

    /**
     * Test FileMapper class inheritance
     *
     * @return void
     */
    public function testFileMapperClassInheritance(): void
    {
        $this->assertInstanceOf(\OCP\AppFramework\Db\QBMapper::class, $this->fileMapper);
    }

    /**
     * Test FileMapper has required dependencies
     *
     * @return void
     */
    public function testFileMapperHasRequiredDependencies(): void
    {
        $reflection = new \ReflectionClass($this->fileMapper);
        
        // Check that urlGenerator property exists and is private readonly
        $this->assertTrue($reflection->hasProperty('urlGenerator'));
        $urlGeneratorProperty = $reflection->getProperty('urlGenerator');
        $this->assertTrue($urlGeneratorProperty->isPrivate());
        $this->assertTrue($urlGeneratorProperty->isReadOnly());
    }

    /**
     * Test FileMapper table name
     *
     * @return void
     */
    public function testFileMapperTableName(): void
    {
        $reflection = new \ReflectionClass($this->fileMapper);
        $parentClass = $reflection->getParentClass();
        
        // The parent QBMapper should exist
        $this->assertNotFalse($parentClass);
        $this->assertEquals('OCP\AppFramework\Db\QBMapper', $parentClass->getName());
    }

    /**
     * Test FileMapper has expected methods
     *
     * @return void
     */
    public function testFileMapperHasExpectedMethods(): void
    {
        $this->assertTrue(method_exists($this->fileMapper, 'getFiles'));
        $this->assertTrue(method_exists($this->fileMapper, 'getFile'));
        $this->assertTrue(method_exists($this->fileMapper, 'getFilesForObject'));
        $this->assertTrue(method_exists($this->fileMapper, 'publishFile'));
        $this->assertTrue(method_exists($this->fileMapper, 'depublishFile'));
        $this->assertTrue(method_exists($this->fileMapper, 'setFileOwnership'));
    }

    /**
     * Test FileMapper method signatures
     *
     * @return void
     */
    public function testFileMapperMethodSignatures(): void
    {
        $reflection = new \ReflectionClass($this->fileMapper);
        
        // Test getFiles method signature
        $getFilesMethod = $reflection->getMethod('getFiles');
        $this->assertCount(2, $getFilesMethod->getParameters());
        $this->assertEquals('int', $getFilesMethod->getParameters()[0]->getType()?->getName());
        $this->assertEquals('array', $getFilesMethod->getParameters()[1]->getType()?->getName());
        
        // Test getFile method signature
        $getFileMethod = $reflection->getMethod('getFile');
        $this->assertCount(1, $getFileMethod->getParameters());
        $this->assertEquals('int', $getFileMethod->getParameters()[0]->getType()?->getName());
        
        // Test getFilesForObject method signature
        $getFilesForObjectMethod = $reflection->getMethod('getFilesForObject');
        $this->assertCount(1, $getFilesForObjectMethod->getParameters());
        $this->assertEquals('OCA\OpenRegister\Db\ObjectEntity', $getFilesForObjectMethod->getParameters()[0]->getType()?->getName());
    }

    /**
     * Test FileMapper return types
     *
     * @return void
     */
    public function testFileMapperReturnTypes(): void
    {
        $reflection = new \ReflectionClass($this->fileMapper);
        
        // Test getFiles return type
        $getFilesMethod = $reflection->getMethod('getFiles');
        $this->assertEquals('array', $getFilesMethod->getReturnType()?->getName());
        
        // Test getFile return type
        $getFileMethod = $reflection->getMethod('getFile');
        $this->assertEquals('array', $getFileMethod->getReturnType()?->getName());
        
        // Test getFilesForObject return type
        $getFilesForObjectMethod = $reflection->getMethod('getFilesForObject');
        $this->assertEquals('array', $getFilesForObjectMethod->getReturnType()?->getName());
        
        // Test setFileOwnership return type
        $setFileOwnershipMethod = $reflection->getMethod('setFileOwnership');
        $this->assertEquals('bool', $setFileOwnershipMethod->getReturnType()?->getName());
    }

    /**
     * Test FileMapper URL generator dependency
     *
     * @return void
     */
    public function testFileMapperUrlGeneratorDependency(): void
    {
        $reflection = new \ReflectionProperty($this->fileMapper, 'urlGenerator');
        $reflection->setAccessible(true);
        $this->assertInstanceOf(IURLGenerator::class, $reflection->getValue($this->fileMapper));
    }

    /**
     * Test FileMapper database connection dependency
     *
     * @return void
     */
    public function testFileMapperDatabaseConnectionDependency(): void
    {
        $reflection = new \ReflectionClass($this->fileMapper);
        $parentClass = $reflection->getParentClass();
        $dbProperty = $parentClass->getProperty('db');
        $dbProperty->setAccessible(true);
        $this->assertInstanceOf(IDBConnection::class, $dbProperty->getValue($this->fileMapper));
    }

    /**
     * Test FileMapper is properly configured
     *
     * @return void
     */
    public function testFileMapperIsProperlyConfigured(): void
    {
        // Test that the mapper extends QBMapper
        $this->assertInstanceOf(\OCP\AppFramework\Db\QBMapper::class, $this->fileMapper);
        
        // Test that it has the required dependencies
        $reflection = new \ReflectionClass($this->fileMapper);
        $this->assertTrue($reflection->hasProperty('urlGenerator'));
        
        // Test that the urlGenerator is readonly
        $urlGeneratorProperty = $reflection->getProperty('urlGenerator');
        $this->assertTrue($urlGeneratorProperty->isReadOnly());
    }

    /**
     * Test FileMapper method accessibility
     *
     * @return void
     */
    public function testFileMapperMethodAccessibility(): void
    {
        $reflection = new \ReflectionClass($this->fileMapper);
        
        // All public methods should be accessible
        $publicMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        $this->assertGreaterThan(0, count($publicMethods));
        
        // Check that key methods are public
        $methodNames = array_map(fn($method) => $method->getName(), $publicMethods);
        $this->assertContains('getFiles', $methodNames);
        $this->assertContains('getFile', $methodNames);
        $this->assertContains('getFilesForObject', $methodNames);
    }

    /**
     * Test FileMapper constructor parameters
     *
     * @return void
     */
    public function testFileMapperConstructorParameters(): void
    {
        $reflection = new \ReflectionClass($this->fileMapper);
        $constructor = $reflection->getConstructor();
        
        $this->assertCount(2, $constructor->getParameters());
        
        $params = $constructor->getParameters();
        $this->assertEquals('db', $params[0]->getName());
        $this->assertEquals('urlGenerator', $params[1]->getName());
        
        $this->assertEquals(IDBConnection::class, $params[0]->getType()?->getName());
        $this->assertEquals(IURLGenerator::class, $params[1]->getType()?->getName());
    }
}