<?php

declare(strict_types=1);

/**
 * ValidateObject Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use stdClass;

/**
 * Unit tests for ValidateObject
 *
 * Tests schema validation, error formatting, and exception handling.
 */
class ValidateObjectTest extends TestCase
{
    /** @var ValidateObject */
    private ValidateObject $handler;

    /** @var IAppConfig&MockObject */
    private IAppConfig $config;

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var IURLGenerator&MockObject */
    private IURLGenerator $urlGenerator;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->createMock(IAppConfig::class);
        $this->objectMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->urlGenerator->method('getBaseUrl')
            ->willReturn('http://localhost:8080');

        $this->handler = new ValidateObject(
            $this->config,
            $this->objectMapper,
            $this->schemaMapper,
            $this->urlGenerator,
            $this->logger
        );
    }

    /**
     * Create a Schema entity with the given JSON schema data.
     */
    private function createSchema(array $schemaData, string $slug = 'test-schema'): Schema
    {
        $schema = new Schema();
        $ref = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 1);
        $schema->setSlug($slug);
        $schema->setTitle('Test Schema');
        return $schema;
    }

    // =========================================================================
    // validateObject with explicit schema object
    // =========================================================================

    public function testValidateObjectWithEmptySchemaObject(): void
    {
        $schema = $this->createSchema([]);
        $object = ['name' => 'Test'];
        $schemaObject = new stdClass();

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isValid());
    }

    public function testValidateObjectWithSimpleStringProperty(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';

        $object = ['name' => 'Test'];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isValid());
    }

    public function testValidateObjectWithRequiredFieldMissing(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->required = ['name'];

        $object = [];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertFalse($result->isValid());
    }

    public function testValidateObjectWithInvalidType(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => ['age' => ['type' => 'integer']],
            'required' => ['age'],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->age = new stdClass();
        $schemaObject->properties->age->type = 'integer';
        $schemaObject->required = ['age'];

        $object = ['age' => 'not-a-number'];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertFalse($result->isValid());
    }

    public function testValidateObjectWithEnumProperty(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
            ],
            'required' => ['status'],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->status = new stdClass();
        $schemaObject->properties->status->type = 'string';
        $schemaObject->properties->status->enum = ['active', 'inactive'];
        $schemaObject->required = ['status'];

        // Valid enum value.
        $result = $this->handler->validateObject(['status' => 'active'], $schema, $schemaObject);
        $this->assertTrue($result->isValid());

        // Invalid enum value.
        $result2 = $this->handler->validateObject(['status' => 'unknown'], $schema, $schemaObject);
        $this->assertFalse($result2->isValid());
    }

    public function testValidateObjectWithNestedObject(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => [
                'address' => [
                    'type' => 'object',
                    'properties' => ['street' => ['type' => 'string']],
                ],
            ],
        ]);

        $addressSchema = new stdClass();
        $addressSchema->type = 'object';
        $addressSchema->properties = new stdClass();
        $addressSchema->properties->street = new stdClass();
        $addressSchema->properties->street->type = 'string';

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->address = $addressSchema;

        $object = ['address' => ['street' => 'Main St']];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    public function testValidateObjectWithArrayProperty(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['tags'],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->tags = new stdClass();
        $schemaObject->properties->tags->type = 'array';
        $schemaObject->properties->tags->items = new stdClass();
        $schemaObject->properties->tags->items->type = 'string';
        $schemaObject->required = ['tags'];

        $object = ['tags' => ['php', 'test']];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    public function testValidateObjectWithMinItemsConstraint(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                ],
            ],
            'required' => ['items'],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->items = new stdClass();
        $schemaObject->properties->items->type = 'array';
        $schemaObject->properties->items->items = new stdClass();
        $schemaObject->properties->items->items->type = 'string';
        $schemaObject->properties->items->minItems = 1;
        $schemaObject->required = ['items'];

        $object = ['items' => []];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertFalse($result->isValid());
    }

    public function testValidateObjectRemovesExtendAndFiltersFromObject(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';

        $object = [
            'name' => 'Test',
            'extend' => ['some.field'],
            'filters' => ['status' => 'active'],
        ];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    public function testValidateObjectWithNoProperties(): void
    {
        $schema = $this->createSchema(['type' => 'object']);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';

        $object = ['anything' => 'goes'];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    public function testValidateObjectNullAllowedForOptionalFields(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
            'required' => ['name'],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->properties->age = new stdClass();
        $schemaObject->properties->age->type = 'integer';
        $schemaObject->required = ['name'];

        $object = ['name' => 'Test', 'age' => null];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    // =========================================================================
    // generateErrorMessage
    // =========================================================================

    public function testGenerateErrorMessageWithValidResult(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $object = ['name' => 'Test'];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $message = $this->handler->generateErrorMessage($result);

        $this->assertIsString($message);
    }

    public function testGenerateErrorMessageWithInvalidResult(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->required = ['name'];

        $result = $this->handler->validateObject([], $schema, $schemaObject);

        $message = $this->handler->generateErrorMessage($result);

        $this->assertIsString($message);
        $this->assertNotEmpty($message);
    }

    // =========================================================================
    // handleValidationException
    // =========================================================================

    public function testHandleValidationExceptionReturnsJsonResponse(): void
    {
        $mockError = $this->createMock(\Opis\JsonSchema\Errors\ValidationError::class);
        $mockError->method('keyword')->willReturn('required');
        $mockError->method('message')->willReturn('The required properties (name) are missing');
        $mockError->method('args')->willReturn([]);
        $mockError->method('subErrors')->willReturn([]);

        $exception = new ValidationException('Validation failed', 0, null, $mockError);

        $response = $this->handler->handleValidationException($exception);

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    public function testHandleCustomValidationExceptionReturnsJsonResponse(): void
    {
        $exception = new CustomValidationException('Custom validation failed', ['name' => 'Required']);

        $response = $this->handler->handleValidationException($exception);

        $this->assertInstanceOf(JSONResponse::class, $response);
    }

    // =========================================================================
    // validateObject with Schema entity (no explicit schemaObject)
    // =========================================================================

    public function testValidateObjectWithSchemaEntity(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);
        $schema->setProperties(['name' => ['type' => 'string']]);
        $schema->setRequired([]);

        $object = ['name' => 'Hello'];

        $result = $this->handler->validateObject($object, $schema);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isValid());
    }

    // =========================================================================
    // VALIDATION_ERROR_MESSAGE constant
    // =========================================================================

    public function testValidationErrorMessageConstant(): void
    {
        $this->assertSame('Invalid object', ValidateObject::VALIDATION_ERROR_MESSAGE);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testValidateObjectWithEmptyRequiredArray(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => [],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->required = [];

        $object = ['name' => 'Test'];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    public function testValidateObjectWithMultipleTypes(): void
    {
        $schema = $this->createSchema([
            'type' => 'object',
            'properties' => ['value' => ['type' => ['string', 'integer']]],
            'required' => ['value'],
        ]);

        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->value = new stdClass();
        $schemaObject->properties->value->type = ['string', 'integer'];
        $schemaObject->required = ['value'];

        // String value.
        $result1 = $this->handler->validateObject(['value' => 'hello'], $schema, $schemaObject);
        $this->assertTrue($result1->isValid());

        // Integer value.
        $result2 = $this->handler->validateObject(['value' => 42], $schema, $schemaObject);
        $this->assertTrue($result2->isValid());
    }
}
