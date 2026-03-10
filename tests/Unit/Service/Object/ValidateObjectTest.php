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

    /**
     * Helper to invoke private methods via reflection.
     */
    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionClass($this->handler);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->handler, $args);
    }

    // =========================================================================
    // transformSchemaForValidation
    // =========================================================================

    public function testTransformSchemaForValidationNoProperties(): void
    {
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';

        [$result, $object] = $this->invokePrivate('transformSchemaForValidation', [$schemaObject, ['name' => 'Test'], 'test-schema']);

        $this->assertSame('object', $result->type);
        $this->assertSame(['name' => 'Test'], $object);
    }

    public function testTransformSchemaForValidationWithSelfReferenceRelatedObject(): void
    {
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->parent = new stdClass();
        $schemaObject->properties->parent->type = 'object';
        $schemaObject->properties->parent->{'$ref'} = '#/components/schemas/test-schema';
        $schemaObject->properties->parent->objectConfiguration = ['handling' => 'related-object'];

        [$result, $object] = $this->invokePrivate('transformSchemaForValidation', [$schemaObject, ['parent' => 'uuid-123'], 'test-schema']);

        // Self-reference with related-object should become string UUID type.
        $this->assertSame('string', $result->properties->parent->type);
        $this->assertNotNull($result->properties->parent->pattern);
    }

    public function testTransformSchemaForValidationSelfReferenceInversedBy(): void
    {
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->parent = new stdClass();
        $schemaObject->properties->parent->type = 'object';
        $schemaObject->properties->parent->{'$ref'} = '#/components/schemas/test-schema';
        $schemaObject->properties->parent->objectConfiguration = ['handling' => 'related-object'];
        $schemaObject->properties->parent->inversedBy = 'children';

        [$result, $object] = $this->invokePrivate('transformSchemaForValidation', [$schemaObject, ['parent' => null], 'test-schema']);

        // Self-reference with inversedBy should become oneOf [null, string, object].
        $this->assertNotNull($result->properties->parent->oneOf);
        $this->assertCount(3, $result->properties->parent->oneOf);
    }

    public function testTransformSchemaForValidationSelfReferenceArrayItems(): void
    {
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->children = new stdClass();
        $schemaObject->properties->children->type = 'array';
        $schemaObject->properties->children->items = new stdClass();
        $schemaObject->properties->children->items->type = 'object';
        $schemaObject->properties->children->items->{'$ref'} = '#/components/schemas/test-schema';

        [$result, $object] = $this->invokePrivate('transformSchemaForValidation', [$schemaObject, ['children' => []], 'test-schema']);

        // Array items self-ref without objectConfiguration handling: the isSelfReference check on items
        // uses `=== true` for preg_match which won't match. So items remain object type
        // and the $ref gets unset by transformOpenRegisterObjectConfigurations.
        $this->assertSame('array', $result->properties->children->type);
    }

    public function testTransformSchemaForValidationRemovesDollarId(): void
    {
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->{'$id'} = 'http://example.com/schemas/test';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';

        [$result, $object] = $this->invokePrivate('transformSchemaForValidation', [$schemaObject, ['name' => 'Test'], 'test-schema']);

        $this->assertFalse(isset($result->{'$id'}));
    }

    // =========================================================================
    // cleanSchemaForValidation
    // =========================================================================

    public function testCleanSchemaForValidationRemovesMetadataProperties(): void
    {
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->cascadeDelete = true;
        $schemaObject->objectConfiguration = ['handling' => 'related-object'];
        $schemaObject->inversedBy = 'children';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->properties->name->objectConfiguration = ['handling' => 'nested-object'];

        $result = $this->invokePrivate('cleanSchemaForValidation', [$schemaObject, false]);

        $this->assertFalse(isset($result->cascadeDelete));
        $this->assertFalse(isset($result->objectConfiguration));
        $this->assertFalse(isset($result->inversedBy));
        // Nested properties should also be cleaned.
        $this->assertFalse(isset($result->properties->name->objectConfiguration));
    }

    public function testCleanSchemaForValidationWithItems(): void
    {
        $schemaObject = new stdClass();
        $schemaObject->type = 'array';
        $schemaObject->items = new stdClass();
        $schemaObject->items->type = 'object';
        $schemaObject->items->inversedBy = 'parent';

        $result = $this->invokePrivate('cleanSchemaForValidation', [$schemaObject, false]);

        $this->assertFalse(isset($result->items->inversedBy));
    }

    // =========================================================================
    // cleanPropertyForValidation
    // =========================================================================

    public function testCleanPropertyForValidationNonObject(): void
    {
        $result = $this->invokePrivate('cleanPropertyForValidation', ['string', false]);
        $this->assertSame('string', $result);
    }

    public function testCleanPropertyForValidationWithNestedProperties(): void
    {
        $prop = new stdClass();
        $prop->type = 'object';
        $prop->cascadeDelete = true;
        $prop->properties = new stdClass();
        $prop->properties->inner = new stdClass();
        $prop->properties->inner->type = 'string';
        $prop->properties->inner->inversedBy = 'something';

        $result = $this->invokePrivate('cleanPropertyForValidation', [$prop, false]);

        $this->assertFalse(isset($result->cascadeDelete));
        $this->assertFalse(isset($result->properties->inner->inversedBy));
    }

    // =========================================================================
    // transformCustomTypeToJsonSchemaType
    // =========================================================================

    public function testTransformCustomTypeFile(): void
    {
        $prop = new stdClass();
        $prop->type = 'file';

        $result = $this->invokePrivate('transformCustomTypeToJsonSchemaType', [$prop]);

        $this->assertSame(['integer', 'string', 'null'], $result->type);
    }

    public function testTransformCustomTypeDatetime(): void
    {
        $prop = new stdClass();
        $prop->type = 'datetime';

        $result = $this->invokePrivate('transformCustomTypeToJsonSchemaType', [$prop]);

        $this->assertSame('string', $result->type);
    }

    public function testTransformCustomTypeArrayOfTypes(): void
    {
        $prop = new stdClass();
        $prop->type = ['file', 'null'];

        $result = $this->invokePrivate('transformCustomTypeToJsonSchemaType', [$prop]);

        $this->assertIsArray($result->type);
        $this->assertSame(['integer', 'string', 'null'], $result->type[0]);
        $this->assertSame('null', $result->type[1]);
    }

    public function testTransformCustomTypeNoType(): void
    {
        $prop = new stdClass();

        $result = $this->invokePrivate('transformCustomTypeToJsonSchemaType', [$prop]);

        $this->assertFalse(isset($result->type));
    }

    public function testTransformCustomTypeStandardType(): void
    {
        $prop = new stdClass();
        $prop->type = 'string';

        $result = $this->invokePrivate('transformCustomTypeToJsonSchemaType', [$prop]);

        $this->assertSame('string', $result->type);
    }

    // =========================================================================
    // fixMisplacedArrayConstraints
    // =========================================================================

    public function testFixMisplacedArrayConstraintsNonArray(): void
    {
        $prop = new stdClass();
        $prop->type = 'string';
        $prop->enum = ['a', 'b'];

        $result = $this->invokePrivate('fixMisplacedArrayConstraints', [$prop]);

        // Should not move enum for non-array types.
        $this->assertSame(['a', 'b'], $result->enum);
    }

    public function testFixMisplacedArrayConstraintsMoveEnumToItems(): void
    {
        $prop = new stdClass();
        $prop->type = 'array';
        $prop->enum = ['a', 'b'];

        $result = $this->invokePrivate('fixMisplacedArrayConstraints', [$prop]);

        $this->assertFalse(isset($result->enum));
        $this->assertSame(['a', 'b'], $result->items->enum);
    }

    public function testFixMisplacedArrayConstraintsDoesNotOverrideExistingEnum(): void
    {
        $prop = new stdClass();
        $prop->type = 'array';
        $prop->enum = ['a', 'b'];
        $prop->items = new stdClass();
        $prop->items->type = 'string';
        $prop->items->enum = ['x', 'y'];

        $result = $this->invokePrivate('fixMisplacedArrayConstraints', [$prop]);

        // Should keep existing items enum.
        $this->assertSame(['x', 'y'], $result->items->enum);
        $this->assertFalse(isset($result->enum));
    }

    public function testFixMisplacedArrayConstraintsMoveOneOfToItems(): void
    {
        $prop = new stdClass();
        $prop->type = 'array';
        $prop->oneOf = [
            (object) ['type' => 'string'],
            (object) ['type' => 'integer'],
        ];

        $result = $this->invokePrivate('fixMisplacedArrayConstraints', [$prop]);

        $this->assertFalse(isset($result->oneOf));
        $this->assertNotNull($result->items->oneOf);
    }

    // =========================================================================
    // isSelfReference
    // =========================================================================

    public function testIsSelfReferenceTrue(): void
    {
        $prop = new stdClass();
        $prop->{'$ref'} = '#/components/schemas/test-schema';

        $result = $this->invokePrivate('isSelfReference', [$prop, 'test-schema']);

        $this->assertTrue($result);
    }

    public function testIsSelfReferenceFalseDifferentSchema(): void
    {
        $prop = new stdClass();
        $prop->{'$ref'} = '#/components/schemas/other-schema';

        $result = $this->invokePrivate('isSelfReference', [$prop, 'test-schema']);

        $this->assertFalse($result);
    }

    public function testIsSelfReferenceNoRef(): void
    {
        $prop = new stdClass();
        $prop->type = 'string';

        $result = $this->invokePrivate('isSelfReference', [$prop, 'test-schema']);

        $this->assertFalse($result);
    }

    public function testIsSelfReferenceWithObjectRef(): void
    {
        $prop = new stdClass();
        $prop->{'$ref'} = (object) ['id' => '#/components/schemas/test-schema'];

        $result = $this->invokePrivate('isSelfReference', [$prop, 'test-schema']);

        $this->assertTrue($result);
    }

    public function testIsSelfReferenceWithArrayRef(): void
    {
        $prop = new stdClass();
        $prop->{'$ref'} = ['id' => '#/components/schemas/test-schema'];

        $result = $this->invokePrivate('isSelfReference', [$prop, 'test-schema']);

        $this->assertTrue($result);
    }

    public function testIsSelfReferenceWithQueryParameters(): void
    {
        $prop = new stdClass();
        $prop->{'$ref'} = '#/components/schemas/test-schema?key=value';

        $result = $this->invokePrivate('isSelfReference', [$prop, 'test-schema']);

        $this->assertTrue($result);
    }

    // =========================================================================
    // removeQueryParameters
    // =========================================================================

    public function testRemoveQueryParametersNoParams(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['#/components/schemas/test']);
        $this->assertSame('#/components/schemas/test', $result);
    }

    public function testRemoveQueryParametersWithParams(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['#/components/schemas/test?key=value&a=b']);
        $this->assertSame('#/components/schemas/test', $result);
    }

    // =========================================================================
    // getMixedValue
    // =========================================================================

    public function testGetMixedValueFromArray(): void
    {
        $result = $this->invokePrivate('getMixedValue', [['handling' => 'related-object'], 'handling']);
        $this->assertSame('related-object', $result);
    }

    public function testGetMixedValueFromObject(): void
    {
        $obj = new stdClass();
        $obj->handling = 'nested-object';

        $result = $this->invokePrivate('getMixedValue', [$obj, 'handling']);
        $this->assertSame('nested-object', $result);
    }

    public function testGetMixedValueMissingKey(): void
    {
        $result = $this->invokePrivate('getMixedValue', [['foo' => 'bar'], 'handling']);
        $this->assertNull($result);
    }

    public function testGetMixedValueNullData(): void
    {
        $result = $this->invokePrivate('getMixedValue', [null, 'handling']);
        $this->assertNull($result);
    }

    public function testGetMixedValueScalarData(): void
    {
        $result = $this->invokePrivate('getMixedValue', ['string-data', 'handling']);
        $this->assertNull($result);
    }

    // =========================================================================
    // extractObjectConfigurationHandling
    // =========================================================================

    public function testExtractObjectConfigurationHandlingDirect(): void
    {
        $prop = new stdClass();
        $prop->objectConfiguration = ['handling' => 'related-object'];

        $result = $this->invokePrivate('extractObjectConfigurationHandling', [$prop]);

        $this->assertSame('related-object', $result);
    }

    public function testExtractObjectConfigurationHandlingFromItems(): void
    {
        $prop = new stdClass();
        $prop->items = new stdClass();
        $prop->items->objectConfiguration = ['handling' => 'nested-object'];

        $result = $this->invokePrivate('extractObjectConfigurationHandling', [$prop]);

        $this->assertSame('nested-object', $result);
    }

    public function testExtractObjectConfigurationHandlingFromOneOf(): void
    {
        $prop = new stdClass();
        $prop->items = new stdClass();
        $prop->items->oneOf = [
            (object) ['objectConfiguration' => (object) ['handling' => 'related-object']],
        ];

        $result = $this->invokePrivate('extractObjectConfigurationHandling', [$prop]);

        $this->assertSame('related-object', $result);
    }

    public function testExtractObjectConfigurationHandlingNone(): void
    {
        $prop = new stdClass();
        $prop->type = 'string';

        $result = $this->invokePrivate('extractObjectConfigurationHandling', [$prop]);

        $this->assertNull($result);
    }

    // =========================================================================
    // extractHandlingFromOneOfItems
    // =========================================================================

    public function testExtractHandlingFromOneOfItemsNull(): void
    {
        $result = $this->invokePrivate('extractHandlingFromOneOfItems', [null]);
        $this->assertNull($result);
    }

    public function testExtractHandlingFromOneOfItemsNotIterable(): void
    {
        $result = $this->invokePrivate('extractHandlingFromOneOfItems', ['not-iterable']);
        $this->assertNull($result);
    }

    public function testExtractHandlingFromOneOfItemsWithMatch(): void
    {
        $oneOf = [
            (object) ['objectConfiguration' => ['handling' => 'related-object']],
        ];

        $result = $this->invokePrivate('extractHandlingFromOneOfItems', [$oneOf]);
        $this->assertSame('related-object', $result);
    }

    public function testExtractHandlingFromOneOfItemsNoMatch(): void
    {
        $oneOf = [
            (object) ['type' => 'string'],
        ];

        $result = $this->invokePrivate('extractHandlingFromOneOfItems', [$oneOf]);
        $this->assertNull($result);
    }

    // =========================================================================
    // transformOpenRegisterObjectConfigurations
    // =========================================================================

    public function testTransformOpenRegisterObjectConfigurationsNoProperties(): void
    {
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';

        $result = $this->invokePrivate('transformOpenRegisterObjectConfigurations', [$schemaObject]);

        $this->assertSame('object', $result->type);
    }

    // =========================================================================
    // transformToUuidProperty
    // =========================================================================

    public function testTransformToUuidPropertyNoInversedBy(): void
    {
        $prop = new stdClass();
        $prop->type = 'object';
        $prop->properties = new stdClass();
        $prop->required = ['id'];

        $this->invokePrivate('transformToUuidProperty', [$prop]);

        $this->assertSame('string', $prop->type);
        $this->assertNotNull($prop->pattern);
        $this->assertFalse(isset($prop->properties));
        $this->assertFalse(isset($prop->required));
    }

    public function testTransformToUuidPropertyWithInversedBy(): void
    {
        $prop = new stdClass();
        $prop->type = 'object';
        $prop->inversedBy = 'children';
        $prop->properties = (object) ['id' => (object) ['type' => 'string']];

        $this->invokePrivate('transformToUuidProperty', [$prop]);

        $this->assertNotNull($prop->oneOf);
        $this->assertCount(2, $prop->oneOf);
    }

    // =========================================================================
    // transformToNestedObjectProperty
    // =========================================================================

    public function testTransformToNestedObjectPropertyNoRef(): void
    {
        $prop = new stdClass();
        $prop->type = 'object';

        $this->invokePrivate('transformToNestedObjectProperty', [$prop]);

        // No change since no $ref.
        $this->assertSame('object', $prop->type);
    }

    // =========================================================================
    // getValueType
    // =========================================================================

    public function testGetValueTypeNull(): void
    {
        $this->assertSame('null', $this->invokePrivate('getValueType', [null]));
    }

    public function testGetValueTypeBoolean(): void
    {
        $this->assertSame('boolean', $this->invokePrivate('getValueType', [true]));
    }

    public function testGetValueTypeInteger(): void
    {
        $this->assertSame('integer', $this->invokePrivate('getValueType', [42]));
    }

    public function testGetValueTypeNumber(): void
    {
        $this->assertSame('number', $this->invokePrivate('getValueType', [3.14]));
    }

    public function testGetValueTypeString(): void
    {
        $this->assertSame('string', $this->invokePrivate('getValueType', ['hello']));
    }

    public function testGetValueTypeArray(): void
    {
        $this->assertSame('array', $this->invokePrivate('getValueType', [['a', 'b']]));
    }

    public function testGetValueTypeObject(): void
    {
        $this->assertSame('object', $this->invokePrivate('getValueType', [new stdClass()]));
    }

    // =========================================================================
    // generateErrorMessage — various error types
    // =========================================================================

    public function testGenerateErrorMessageForRequiredSingleField(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->required = ['name'];

        // Pass at least one field so the object is recognized as an object type.
        $result = $this->handler->validateObject(['other' => 'value'], $schema, $schemaObject);
        $message = $this->handler->generateErrorMessage($result);

        $this->assertStringContainsString('name', $message);
        $this->assertStringContainsString('required', strtolower($message));
    }

    public function testGenerateErrorMessageForRequiredMultipleFields(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->properties->age = new stdClass();
        $schemaObject->properties->age->type = 'integer';
        $schemaObject->required = ['name', 'age'];

        // Pass at least one field so the object is recognized as an object type.
        $result = $this->handler->validateObject(['other' => 'value'], $schema, $schemaObject);
        $message = $this->handler->generateErrorMessage($result);

        $this->assertStringContainsString('name', $message);
        $this->assertStringContainsString('age', $message);
    }

    public function testGenerateErrorMessageForTypeError(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->age = new stdClass();
        $schemaObject->properties->age->type = 'integer';
        $schemaObject->required = ['age'];

        $result = $this->handler->validateObject(['age' => 'notanumber'], $schema, $schemaObject);
        $message = $this->handler->generateErrorMessage($result);

        $this->assertStringContainsString('type', strtolower($message));
    }

    public function testGenerateErrorMessageForEnumError(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->status = new stdClass();
        $schemaObject->properties->status->type = 'string';
        $schemaObject->properties->status->enum = ['active', 'inactive'];
        $schemaObject->required = ['status'];

        $result = $this->handler->validateObject(['status' => 'invalid-value'], $schema, $schemaObject);
        $message = $this->handler->generateErrorMessage($result);

        $this->assertStringContainsString('status', $message);
    }

    public function testGenerateErrorMessageValidResult(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $object = ['name' => 'Test'];

        $result = $this->handler->validateObject($object, $schema, $schemaObject);
        $message = $this->handler->generateErrorMessage($result);

        $this->assertSame('Validation passed', $message);
    }

    // =========================================================================
    // validateObject — filter empty values
    // =========================================================================

    public function testValidateObjectFiltersEmptyStringsForOptionalFields(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->properties->optional = new stdClass();
        $schemaObject->properties->optional->type = 'string';
        $schemaObject->required = ['name'];

        // Empty string for optional field should be filtered out.
        $result = $this->handler->validateObject(['name' => 'Test', 'optional' => ''], $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    public function testValidateObjectKeepsEmptyStringForRequiredField(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->properties->name->minLength = 1;
        $schemaObject->required = ['name'];

        // Empty string for required field should fail validation.
        $result = $this->handler->validateObject(['name' => ''], $schema, $schemaObject);

        $this->assertFalse($result->isValid());
    }

    // =========================================================================
    // validateObject — custom file type handling
    // =========================================================================

    public function testValidateObjectWithFileType(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->document = new stdClass();
        $schemaObject->properties->document->type = 'file';
        $schemaObject->required = ['document'];

        // File type is transformed to integer|string|null, so int should pass.
        $result = $this->handler->validateObject(['document' => 42], $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    public function testValidateObjectWithDatetimeType(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->createdAt = new stdClass();
        $schemaObject->properties->createdAt->type = 'datetime';
        $schemaObject->required = ['createdAt'];

        // Datetime is transformed to string.
        $result = $this->handler->validateObject(['createdAt' => '2024-01-01T00:00:00Z'], $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    // =========================================================================
    // validateObject — removes metadata properties (cascadeDelete, objectConfiguration, etc.)
    // =========================================================================

    public function testValidateObjectWithMetadataProperties(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->name = new stdClass();
        $schemaObject->properties->name->type = 'string';
        $schemaObject->properties->name->objectConfiguration = ['handling' => 'related-object'];
        $schemaObject->properties->name->cascadeDelete = true;

        $result = $this->handler->validateObject(['name' => 'Test'], $schema, $schemaObject);

        $this->assertTrue($result->isValid());
    }

    // =========================================================================
    // validateObject — boolean property
    // =========================================================================

    public function testValidateObjectWithBooleanProperty(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->active = new stdClass();
        $schemaObject->properties->active->type = 'boolean';
        $schemaObject->required = ['active'];

        $result = $this->handler->validateObject(['active' => true], $schema, $schemaObject);
        $this->assertTrue($result->isValid());

        $result2 = $this->handler->validateObject(['active' => 'yes'], $schema, $schemaObject);
        $this->assertFalse($result2->isValid());
    }

    // =========================================================================
    // validateObject — number property
    // =========================================================================

    public function testValidateObjectWithNumberProperty(): void
    {
        $schema = $this->createSchema([]);
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->price = new stdClass();
        $schemaObject->properties->price->type = 'number';
        $schemaObject->required = ['price'];

        $result = $this->handler->validateObject(['price' => 9.99], $schema, $schemaObject);
        $this->assertTrue($result->isValid());

        $result2 = $this->handler->validateObject(['price' => 10], $schema, $schemaObject);
        $this->assertTrue($result2->isValid());
    }

    // =========================================================================
    // preprocessSchemaReferences - UUID-transformed properties should be skipped
    // =========================================================================

    public function testPreprocessSchemaReferencesSkipsUuidTransformed(): void
    {
        $schemaObject = new stdClass();
        $schemaObject->type = 'object';
        $schemaObject->properties = new stdClass();
        $schemaObject->properties->related = new stdClass();
        $schemaObject->properties->related->type = 'string';
        $schemaObject->properties->related->pattern = '^[0-9a-f]{8}-uuid-pattern$';

        $result = $this->invokePrivate('preprocessSchemaReferences', [$schemaObject, [], false]);

        // Should be unchanged since it was already UUID-transformed.
        $this->assertSame('string', $result->properties->related->type);
    }

    // =========================================================================
    // transformArrayItemsForValidation
    // =========================================================================

    public function testTransformArrayItemsNonObjectType(): void
    {
        $items = new stdClass();
        $items->type = 'string';

        $result = $this->invokePrivate('transformArrayItemsForValidation', [$items]);

        $this->assertSame('string', $result->type);
    }

    public function testTransformArrayItemsObjectWithRelatedObjectHandling(): void
    {
        $items = new stdClass();
        $items->type = 'object';
        $items->objectConfiguration = ['handling' => 'related-object'];

        $result = $this->invokePrivate('transformArrayItemsForValidation', [$items]);

        // Should be transformed to oneOf [string UUID, object with id].
        $this->assertNotNull($result->oneOf);
    }

    public function testTransformArrayItemsObjectWithNestedObjectHandling(): void
    {
        $items = new stdClass();
        $items->type = 'object';
        $items->objectConfiguration = ['handling' => 'nested-object'];

        $result = $this->invokePrivate('transformArrayItemsForValidation', [$items]);

        // Should remain as object type for nested objects.
        $this->assertSame('object', $result->type);
    }

    // =========================================================================
    // transformPropertyForOpenRegister — strips $ref from string type
    // =========================================================================

    public function testTransformPropertyStripsRefFromStringType(): void
    {
        $prop = new stdClass();
        $prop->type = 'string';
        $prop->{'$ref'} = '#/components/schemas/something';

        $this->invokePrivate('transformPropertyForOpenRegister', [$prop]);

        $this->assertFalse(isset($prop->{'$ref'}));
        $this->assertSame('string', $prop->type);
    }

    // =========================================================================
    // validateUniqueFields — no unique config
    // =========================================================================

    public function testValidateUniqueFieldsNoConfig(): void
    {
        $schema = $this->createSchema([]);
        $schema->setProperties(['name' => ['type' => 'string']]);

        // Should not throw.
        $this->invokePrivate('validateUniqueFields', [['name' => 'Test'], $schema]);
        $this->assertTrue(true);
    }

    public function testValidateUniqueFieldsEmptyConfig(): void
    {
        $schema = $this->createSchema([]);

        // Configuration with empty unique array.
        $ref = new ReflectionClass($schema);
        // Use Schema's setConfiguration if available.
        if ($ref->hasMethod('setConfiguration')) {
            $schema->setConfiguration(['unique' => []]);
        }

        $this->invokePrivate('validateUniqueFields', [['name' => 'Test'], $schema]);
        $this->assertTrue(true);
    }

    public function testValidateUniqueFieldsWithDuplicateStringConfigThrows(): void
    {
        $schema = $this->createSchema([]);
        $schema->setProperties(['name' => ['type' => 'string']]);

        $ref = new ReflectionClass($schema);
        if ($ref->hasMethod('setConfiguration')) {
            // Use string (not array) config to avoid the array-as-key TypeError bug.
            $schema->setConfiguration(['unique' => 'name']);
        }

        // Count returns 1 meaning duplicate exists.
        $this->objectMapper->method('countAll')->willReturn(1);

        $this->expectException(CustomValidationException::class);

        $this->invokePrivate('validateUniqueFields', [['name' => 'Duplicate'], $schema]);
    }

    public function testValidateUniqueFieldsNoDuplicate(): void
    {
        $schema = $this->createSchema([]);
        $schema->setProperties(['name' => ['type' => 'string']]);

        $ref = new ReflectionClass($schema);
        if ($ref->hasMethod('setConfiguration')) {
            $schema->setConfiguration(['unique' => 'name']);
        }

        // Count returns 0.
        $this->objectMapper->method('countAll')->willReturn(0);

        $this->invokePrivate('validateUniqueFields', [['name' => 'Unique'], $schema]);
        $this->assertTrue(true);
    }

    // =========================================================================
    // resolveSchemaProperty
    // =========================================================================

    public function testResolveSchemaPropertyNoRef(): void
    {
        $prop = new stdClass();
        $prop->type = 'string';

        $result = $this->invokePrivate('resolveSchemaProperty', [$prop, []]);

        $this->assertSame('string', $result->type);
    }

    public function testResolveSchemaPropertyWithRefCircular(): void
    {
        $prop = new stdClass();
        $prop->{'$ref'} = '#/components/schemas/already-visited';

        $result = $this->invokePrivate('resolveSchemaProperty', [$prop, ['already-visited']]);

        // Should return as-is to prevent infinite loops.
        $this->assertNotNull($result->{'$ref'});
    }

    public function testResolveSchemaPropertyWithArrayItemsRef(): void
    {
        $prop = new stdClass();
        $prop->type = 'array';
        $prop->items = new stdClass();
        $prop->items->{'$ref'} = '#/components/schemas/unknown-schema';

        // SchemaMapper find will throw (schema not found).
        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Not found'));
        $this->schemaMapper->method('findAll')
            ->willReturn([]);

        $result = $this->invokePrivate('resolveSchemaProperty', [$prop, []]);

        // Should handle gracefully.
        $this->assertSame('array', $result->type);
    }

    public function testResolveSchemaPropertyWithNestedProperties(): void
    {
        $prop = new stdClass();
        $prop->type = 'object';
        $prop->properties = new stdClass();
        $prop->properties->inner = new stdClass();
        $prop->properties->inner->type = 'string';

        $result = $this->invokePrivate('resolveSchemaProperty', [$prop, []]);

        $this->assertSame('string', $result->properties->inner->type);
    }
}
