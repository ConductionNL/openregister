<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ValidationService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Test class for ValidationService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class ValidationServiceTest extends TestCase
{
    private ValidationService $validationService;
    private SchemaMapper $schemaMapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        // Create ValidationService instance
        $this->validationService = new ValidationService($this->schemaMapper);
    }

    /**
     * Test validateObject method with valid object
     */
    public function testValidateObjectWithValidObject(): void
    {
        $objectData = [
            'name' => 'Test Object',
            'age' => 25,
            'active' => true,
            'email' => 'test@example.com'
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string', 'required' => true],
            'age' => ['type' => 'integer', 'required' => true],
            'active' => ['type' => 'boolean', 'required' => false],
            'email' => ['type' => 'string', 'format' => 'email', 'required' => true]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue($result['valid']);
        $this->assertCount(0, $result['errors']);
    }

    /**
     * Test validateObject method with missing required fields
     */
    public function testValidateObjectWithMissingRequiredFields(): void
    {
        $objectData = [
            'name' => 'Test Object',
            'age' => 25
            // Missing 'email' which is required
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string', 'required' => true],
            'age' => ['type' => 'integer', 'required' => true],
            'email' => ['type' => 'string', 'format' => 'email', 'required' => true]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
        
        // Check that error mentions missing required field
        $hasRequiredError = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'email') !== false && strpos($error, 'required') !== false) {
                $hasRequiredError = true;
                break;
            }
        }
        $this->assertTrue($hasRequiredError, 'Should have error about missing required field');
    }

    /**
     * Test validateObject method with invalid data types
     */
    public function testValidateObjectWithInvalidDataTypes(): void
    {
        $objectData = [
            'name' => 123, // Should be string
            'age' => 'not a number', // Should be integer
            'active' => 'yes', // Should be boolean
            'email' => 'invalid-email' // Should be valid email
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string', 'required' => true],
            'age' => ['type' => 'integer', 'required' => true],
            'active' => ['type' => 'boolean', 'required' => false],
            'email' => ['type' => 'string', 'format' => 'email', 'required' => true]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
    }

    /**
     * Test validateObject method with invalid email format
     */
    public function testValidateObjectWithInvalidEmailFormat(): void
    {
        $objectData = [
            'name' => 'Test Object',
            'age' => 25,
            'email' => 'invalid-email-format'
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string', 'required' => true],
            'age' => ['type' => 'integer', 'required' => true],
            'email' => ['type' => 'string', 'format' => 'email', 'required' => true]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
        
        // Check that error mentions invalid email format
        $hasEmailError = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'email') !== false && strpos($error, 'format') !== false) {
                $hasEmailError = true;
                break;
            }
        }
        $this->assertTrue($hasEmailError, 'Should have error about invalid email format');
    }

    /**
     * Test validateObject method with string length constraints
     */
    public function testValidateObjectWithStringLengthConstraints(): void
    {
        $objectData = [
            'name' => 'A', // Too short
            'description' => str_repeat('A', 1001) // Too long
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 50],
            'description' => ['type' => 'string', 'maxLength' => 1000]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
    }

    /**
     * Test validateObject method with numeric constraints
     */
    public function testValidateObjectWithNumericConstraints(): void
    {
        $objectData = [
            'age' => 5, // Too young
            'score' => 150 // Too high
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'age' => ['type' => 'integer', 'minimum' => 18, 'maximum' => 100],
            'score' => ['type' => 'number', 'maximum' => 100]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
    }

    /**
     * Test validateObject method with array constraints
     */
    public function testValidateObjectWithArrayConstraints(): void
    {
        $objectData = [
            'tags' => ['tag1'], // Too few items
            'categories' => array_fill(0, 11, 'category') // Too many items
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'tags' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 5],
            'categories' => ['type' => 'array', 'maxItems' => 10]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
    }

    /**
     * Test validateObject method with enum constraints
     */
    public function testValidateObjectWithEnumConstraints(): void
    {
        $objectData = [
            'status' => 'invalid_status',
            'priority' => 'high' // Valid enum value
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'pending']],
            'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
        
        // Check that error mentions invalid enum value
        $hasEnumError = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'status') !== false && strpos($error, 'enum') !== false) {
                $hasEnumError = true;
                break;
            }
        }
        $this->assertTrue($hasEnumError, 'Should have error about invalid enum value');
    }

    /**
     * Test validateObject method with nested object validation
     */
    public function testValidateObjectWithNestedObjectValidation(): void
    {
        $objectData = [
            'name' => 'Test Object',
            'address' => [
                'street' => '123 Main St',
                'city' => 'Test City',
                'zipCode' => '12345'
            ]
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string', 'required' => true],
            'address' => [
                'type' => 'object',
                'properties' => [
                    'street' => ['type' => 'string', 'required' => true],
                    'city' => ['type' => 'string', 'required' => true],
                    'zipCode' => ['type' => 'string', 'required' => true]
                ]
            ]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue($result['valid']);
        $this->assertCount(0, $result['errors']);
    }

    /**
     * Test validateObject method with nested object validation errors
     */
    public function testValidateObjectWithNestedObjectValidationErrors(): void
    {
        $objectData = [
            'name' => 'Test Object',
            'address' => [
                'street' => '123 Main St',
                'city' => 'Test City'
                // Missing 'zipCode' which is required
            ]
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string', 'required' => true],
            'address' => [
                'type' => 'object',
                'properties' => [
                    'street' => ['type' => 'string', 'required' => true],
                    'city' => ['type' => 'string', 'required' => true],
                    'zipCode' => ['type' => 'string', 'required' => true]
                ]
            ]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
        
        // Check that error mentions nested field
        $hasNestedError = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'address.zipCode') !== false) {
                $hasNestedError = true;
                break;
            }
        }
        $this->assertTrue($hasNestedError, 'Should have error about missing nested field');
    }

    /**
     * Test validateObject method with empty schema
     */
    public function testValidateObjectWithEmptySchema(): void
    {
        $objectData = [
            'name' => 'Test Object',
            'age' => 25
        ];

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue($result['valid']);
        $this->assertCount(0, $result['errors']);
    }

    /**
     * Test validateObject method with null object data
     */
    public function testValidateObjectWithNullObjectData(): void
    {
        $objectData = null;

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string', 'required' => true]
        ]);

        $result = $this->validationService->validateObject($objectData, $schema);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThan(0, count($result['errors']));
    }
}
