<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Service\ObjectHandlers\ValidateObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Test class for ValidateObject
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class ValidateObjectTest extends TestCase
{
    private ValidateObject $validateObject;
    private $config;
    private $logger;
    private $objectService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validateObject = new ValidateObject(
            $this->createMock(\OCP\IURLGenerator::class),
            $this->createMock(\OCP\IAppConfig::class),
            $this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\ObjectEntityMapper::class)
        );
    }

    /**
     * Test constructor
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(ValidateObject::class, $this->validateObject);
    }

    /**
     * Test validateObject method with valid object
     */
    public function testValidateObjectWithValidObject(): void
    {
        $object = ['name' => 'Test Object', 'description' => 'Valid description'];
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getSlug')->willReturn('test-schema');

        $result = $this->validateObject->validateObject($object, $schema);

        $this->assertInstanceOf(\Opis\JsonSchema\ValidationResult::class, $result);
    }

    /**
     * Test validateObject method with invalid object
     */
    public function testValidateObjectWithInvalidObject(): void
    {
        $object = ['name' => '', 'description' => 'Invalid object'];
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getSlug')->willReturn('test-schema');

        $result = $this->validateObject->validateObject($object, $schema);

        $this->assertInstanceOf(\Opis\JsonSchema\ValidationResult::class, $result);
    }

    /**
     * Test validateObject method with schema ID
     */
    public function testValidateObjectWithSchemaId(): void
    {
        $object = ['name' => 'Test Object'];
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getSlug')->willReturn('test-schema');

        $result = $this->validateObject->validateObject($object, $schema);

        $this->assertInstanceOf(\Opis\JsonSchema\ValidationResult::class, $result);
    }

    /**
     * Test validateObject method with custom schema object
     */
    public function testValidateObjectWithCustomSchema(): void
    {
        $object = ['name' => 'Test Object'];
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getSlug')->willReturn('test-schema');
        $schemaObject = (object) ['type' => 'object', 'properties' => (object) ['name' => (object) ['type' => 'string']]];

        $result = $this->validateObject->validateObject($object, $schema, $schemaObject);

        $this->assertInstanceOf(\Opis\JsonSchema\ValidationResult::class, $result);
    }

    /**
     * Test validateObject method with depth parameter
     */
    public function testValidateObjectWithDepth(): void
    {
        $object = ['name' => 'Test Object'];
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getSlug')->willReturn('test-schema');

        $result = $this->validateObject->validateObject($object, $schema, new \stdClass(), 2);

        $this->assertInstanceOf(\Opis\JsonSchema\ValidationResult::class, $result);
    }
}
