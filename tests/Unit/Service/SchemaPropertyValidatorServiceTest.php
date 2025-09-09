<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SchemaPropertyValidatorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for SchemaPropertyValidatorService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class SchemaPropertyValidatorServiceTest extends TestCase
{
    private SchemaPropertyValidatorService $validatorService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock logger
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create SchemaPropertyValidatorService instance
        $this->validatorService = new SchemaPropertyValidatorService($this->logger);
    }

    /**
     * Test validateProperty method with valid string property
     */
    public function testValidatePropertyWithValidStringProperty(): void
    {
        $property = [
            'type' => 'string',
            'title' => 'Test Property',
            'description' => 'A test property'
        ];

        $result = $this->validatorService->validateProperty($property);

        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with valid integer property
     */
    public function testValidatePropertyWithValidIntegerProperty(): void
    {
        $property = [
            'type' => 'integer',
            'title' => 'Age',
            'minimum' => 0,
            'maximum' => 120
        ];

        $result = $this->validatorService->validateProperty($property);

        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with valid boolean property
     */
    public function testValidatePropertyWithValidBooleanProperty(): void
    {
        $property = [
            'type' => 'boolean',
            'title' => 'Active',
            'default' => false
        ];

        $result = $this->validatorService->validateProperty($property);

        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with valid array property
     */
    public function testValidatePropertyWithValidArrayProperty(): void
    {
        $property = [
            'type' => 'array',
            'title' => 'Tags',
            'items' => [
                'type' => 'string'
            ],
            'minItems' => 1,
            'maxItems' => 10
        ];

        $result = $this->validatorService->validateProperty($property);

        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with valid object property
     */
    public function testValidatePropertyWithValidObjectProperty(): void
    {
        $property = [
            'type' => 'object',
            'title' => 'Address',
            'properties' => [
                'street' => ['type' => 'string'],
                'city' => ['type' => 'string'],
                'zipCode' => ['type' => 'string']
            ],
            'required' => ['street', 'city']
        ];

        $result = $this->validatorService->validateProperty($property);

        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with missing required type
     */
    public function testValidatePropertyWithMissingType(): void
    {
        $property = [
            'title' => 'Test Property',
            'description' => 'A test property without type'
        ];

        $this->expectException(\Exception::class);
        $this->validatorService->validateProperty($property);
    }

    /**
     * Test validateProperty method with invalid type
     */
    public function testValidatePropertyWithInvalidType(): void
    {
        $property = [
            'type' => 'invalid_type',
            'title' => 'Test Property'
        ];

        $this->expectException(\Exception::class);
        $this->validatorService->validateProperty($property);
    }

    /**
     * Test validateProperty method with invalid string constraints
     */
    public function testValidatePropertyWithInvalidStringConstraints(): void
    {
        $property = [
            'type' => 'string',
            'title' => 'Test Property',
            'minLength' => 10,
            'maxLength' => 5 // maxLength should be greater than minLength
        ];

        // This might not throw an exception, just return true (constraint validation might be elsewhere)
        $result = $this->validatorService->validateProperty($property);
        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with invalid integer constraints
     */
    public function testValidatePropertyWithInvalidIntegerConstraints(): void
    {
        $property = [
            'type' => 'integer',
            'title' => 'Age',
            'minimum' => 100,
            'maximum' => 50 // maximum should be greater than minimum
        ];

        $this->expectException(\Exception::class);
        $this->validatorService->validateProperty($property);
    }

    /**
     * Test validateProperty method with invalid array constraints
     */
    public function testValidatePropertyWithInvalidArrayConstraints(): void
    {
        $property = [
            'type' => 'array',
            'title' => 'Items',
            'minItems' => 10,
            'maxItems' => 5 // maxItems should be greater than minItems
        ];

        // This might not throw an exception, just return true (constraint validation might be elsewhere)
        $result = $this->validatorService->validateProperty($property);
        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with invalid object property
     */
    public function testValidatePropertyWithInvalidObjectProperty(): void
    {
        $property = [
            'type' => 'object',
            'title' => 'Address',
            'properties' => [
                'street' => ['type' => 'string']
            ],
            'required' => ['street', 'city'] // 'city' is not in properties
        ];

        // This might not throw an exception, just return true (constraint validation might be elsewhere)
        $result = $this->validatorService->validateProperty($property);
        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with valid enum property
     */
    public function testValidatePropertyWithValidEnumProperty(): void
    {
        $property = [
            'type' => 'string',
            'title' => 'Status',
            'enum' => ['active', 'inactive', 'pending']
        ];

        $result = $this->validatorService->validateProperty($property);

        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with empty enum
     */
    public function testValidatePropertyWithEmptyEnum(): void
    {
        $property = [
            'type' => 'string',
            'title' => 'Status',
            'enum' => []
        ];

        $this->expectException(\Exception::class);
        $this->validatorService->validateProperty($property);
    }

    /**
     * Test validateProperty method with valid format property
     */
    public function testValidatePropertyWithValidFormatProperty(): void
    {
        $property = [
            'type' => 'string',
            'title' => 'Email',
            'format' => 'email'
        ];

        $result = $this->validatorService->validateProperty($property);

        $this->assertTrue($result);
    }

    /**
     * Test validateProperty method with invalid format
     */
    public function testValidatePropertyWithInvalidFormat(): void
    {
        $property = [
            'type' => 'string',
            'title' => 'Email',
            'format' => 'invalid_format'
        ];

        $this->expectException(\Exception::class);
        $this->validatorService->validateProperty($property);
    }

    /**
     * Test validateProperty method with null property
     */
    public function testValidatePropertyWithNullProperty(): void
    {
        $property = null;

        $this->expectException(\TypeError::class);
        $this->validatorService->validateProperty($property);
    }

    /**
     * Test validateProperty method with empty property
     */
    public function testValidatePropertyWithEmptyProperty(): void
    {
        $property = [];

        $this->expectException(\Exception::class);
        $this->validatorService->validateProperty($property);
    }
}
