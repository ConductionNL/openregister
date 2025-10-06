<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Service\ObjectHandlers\SaveObjects;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Test class for SaveObjects
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class SaveObjectsTest extends TestCase
{
    private SaveObjects $saveObjects;
    private $config;
    private $logger;
    private $objectService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saveObjects = new SaveObjects(
            $this->createMock(\OCA\OpenRegister\Db\ObjectEntityMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\RegisterMapper::class),
            $this->createMock(\OCA\OpenRegister\Service\ObjectHandlers\SaveObject::class),
            $this->createMock(\OCA\OpenRegister\Service\ObjectHandlers\ValidateObject::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\OCA\OpenRegister\Service\OrganisationService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * Test constructor
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(SaveObjects::class, $this->saveObjects);
    }

    /**
     * Test saveObjects method with valid data
     */
    public function testSaveObjectsWithValidData(): void
    {
        $objects = [
            ['name' => 'Test Object 1', 'description' => 'Description 1'],
            ['name' => 'Test Object 2', 'description' => 'Description 2']
        ];
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->method('getId')->willReturn('1');
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getId')->willReturn('1');

        $result = $this->saveObjects->saveObjects($objects, $register, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('saved', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    /**
     * Test saveObjects method with empty array
     */
    public function testSaveObjectsWithEmptyArray(): void
    {
        $objects = [];

        $result = $this->saveObjects->saveObjects($objects);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('saved', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    /**
     * Test saveObjects method with register parameter
     */
    public function testSaveObjectsWithRegister(): void
    {
        $objects = [['name' => 'Test Object']];
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->method('getId')->willReturn('123');
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getId')->willReturn('1');

        $result = $this->saveObjects->saveObjects($objects, $register, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('saved', $result);
    }

    /**
     * Test saveObjects method with schema parameter
     */
    public function testSaveObjectsWithSchema(): void
    {
        $objects = [['name' => 'Test Object']];
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->method('getId')->willReturn('123');
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getId')->willReturn('456');

        $result = $this->saveObjects->saveObjects($objects, $register, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('saved', $result);
    }

    /**
     * Test saveObjects method with validation enabled
     */
    public function testSaveObjectsWithValidation(): void
    {
        $objects = [['name' => 'Test Object']];
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->method('getId')->willReturn('123');
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getId')->willReturn('456');

        $result = $this->saveObjects->saveObjects($objects, $register, $schema, true, true, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('saved', $result);
    }

    /**
     * Test saveObjects method with events enabled
     */
    public function testSaveObjectsWithEvents(): void
    {
        $objects = [['name' => 'Test Object']];
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->method('getId')->willReturn('123');
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getId')->willReturn('456');

        $result = $this->saveObjects->saveObjects($objects, $register, $schema, true, true, false, true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('saved', $result);
    }
}
