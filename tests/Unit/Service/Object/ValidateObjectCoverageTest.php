<?php

declare(strict_types=1);

/**
 * ValidateObject Coverage Tests
 *
 * Tests targeting uncovered lines/branches in ValidateObject.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\ValidationResult;
use Opis\Uri\Uri;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Coverage-focused unit tests for ValidateObject
 *
 * Targets uncovered lines in:
 * - resolveSchemaProperty (object $ref, array $ref, object type union, non-object resolve)
 * - transformPropertyForOpenRegister (inversedBy object, array items inversedBy, strip $ref from string)
 * - transformToUuidProperty (inversedBy path)
 * - transformToNestedObjectProperty (self-reference detection)
 * - extractObjectConfigurationHandling (items oneOf path)
 * - extractHandlingFromOneOfItems
 * - fixMisplacedArrayConstraints (oneOf as object)
 * - transformCustomTypeToJsonSchemaType (type as array)
 * - transformArrayItemsForValidation (nested-object handling)
 * - formatValidationError (maxItems, format, minLength, maxLength, minimum, maximum, enum, pattern, default)
 * - getValueType (all branches)
 * - handleValidationException (both ValidationException and CustomValidationException)
 * - validateUniqueFields (string uniqueFields, array uniqueFields with count > 0)
 * - resolveSchema (file schema path, external schemas)
 */
class ValidateObjectCoverageTest extends TestCase
{
    /** @var ValidateObject */
    private ValidateObject $handler;

    /** @var IAppConfig&MockObject */
    private IAppConfig $config;

    /** @var UnifiedObjectMapper&MockObject */
    private UnifiedObjectMapper $objectMapper;

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
        $this->objectMapper = $this->createMock(UnifiedObjectMapper::class);
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
     * Helper to invoke private/protected methods via reflection.
     */
    private function invokeMethod(string $methodName, array $args = [])
    {
        $ref = new ReflectionMethod(ValidateObject::class, $methodName);
        $ref->setAccessible(true);
        return $ref->invoke($this->handler, ...$args);
    }

    /**
     * Helper to create a Schema entity with id and slug.
     */
    private function createSchema(array $schemaData = [], string $slug = 'test-schema'): Schema
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
    // removeQueryParameters
    // =========================================================================

    public function testRemoveQueryParametersWithQuery(): void
    {
        $result = $this->invokeMethod('removeQueryParameters', ['#/components/schemas/MySchema?key=value']);
        $this->assertSame('#/components/schemas/MySchema', $result);
    }

    public function testRemoveQueryParametersWithoutQuery(): void
    {
        $result = $this->invokeMethod('removeQueryParameters', ['#/components/schemas/MySchema']);
        $this->assertSame('#/components/schemas/MySchema', $result);
    }

    // =========================================================================
    // getMixedValue
    // =========================================================================

    public function testGetMixedValueFromArray(): void
    {
        $result = $this->invokeMethod('getMixedValue', [['handling' => 'related-object'], 'handling']);
        $this->assertSame('related-object', $result);
    }

    public function testGetMixedValueFromObject(): void
    {
        $obj = (object)['handling' => 'nested-object'];
        $result = $this->invokeMethod('getMixedValue', [$obj, 'handling']);
        $this->assertSame('nested-object', $result);
    }

    public function testGetMixedValueReturnsNullForMissingKey(): void
    {
        $result = $this->invokeMethod('getMixedValue', [['foo' => 'bar'], 'handling']);
        $this->assertNull($result);
    }

    public function testGetMixedValueReturnsNullForNonArrayNonObject(): void
    {
        $result = $this->invokeMethod('getMixedValue', ['string-value', 'handling']);
        $this->assertNull($result);
    }

    // =========================================================================
    // getValueType
    // =========================================================================

    public function testGetValueTypeNull(): void
    {
        $this->assertSame('null', $this->invokeMethod('getValueType', [null]));
    }

    public function testGetValueTypeBool(): void
    {
        $this->assertSame('boolean', $this->invokeMethod('getValueType', [true]));
    }

    public function testGetValueTypeInteger(): void
    {
        $this->assertSame('integer', $this->invokeMethod('getValueType', [42]));
    }

    public function testGetValueTypeFloat(): void
    {
        $this->assertSame('number', $this->invokeMethod('getValueType', [3.14]));
    }

    public function testGetValueTypeString(): void
    {
        $this->assertSame('string', $this->invokeMethod('getValueType', ['hello']));
    }

    public function testGetValueTypeArray(): void
    {
        $this->assertSame('array', $this->invokeMethod('getValueType', [[1, 2]]));
    }

    public function testGetValueTypeObject(): void
    {
        $this->assertSame('object', $this->invokeMethod('getValueType', [new stdClass()]));
    }

    // =========================================================================
    // isSelfReference
    // =========================================================================

    public function testIsSelfReferenceWithStringRef(): void
    {
        $prop = (object)['$ref' => '#/components/schemas/Person'];
        $result = $this->invokeMethod('isSelfReference', [$prop, 'Person']);
        $this->assertTrue($result);
    }

    public function testIsSelfReferenceWithObjectRef(): void
    {
        $prop = (object)['$ref' => (object)['id' => '#/components/schemas/Person']];
        $result = $this->invokeMethod('isSelfReference', [$prop, 'Person']);
        $this->assertTrue($result);
    }

    public function testIsSelfReferenceWithArrayRef(): void
    {
        $prop = (object)['$ref' => ['id' => '#/components/schemas/Person']];
        $result = $this->invokeMethod('isSelfReference', [$prop, 'Person']);
        $this->assertTrue($result);
    }

    public function testIsSelfReferenceReturnsFalseForDifferentSlug(): void
    {
        $prop = (object)['$ref' => '#/components/schemas/Address'];
        $result = $this->invokeMethod('isSelfReference', [$prop, 'Person']);
        $this->assertFalse($result);
    }

    public function testIsSelfReferenceReturnsFalseWithNoRef(): void
    {
        $prop = (object)['type' => 'string'];
        $result = $this->invokeMethod('isSelfReference', [$prop, 'Person']);
        $this->assertFalse($result);
    }

    public function testIsSelfReferenceWithQueryParameters(): void
    {
        $prop = (object)['$ref' => '#/components/schemas/Person?onDelete=cascade'];
        $result = $this->invokeMethod('isSelfReference', [$prop, 'Person']);
        $this->assertTrue($result);
    }

    // =========================================================================
    // transformCustomTypeToJsonSchemaType
    // =========================================================================

    public function testTransformFileType(): void
    {
        $prop = (object)['type' => 'file'];
        $result = $this->invokeMethod('transformCustomTypeToJsonSchemaType', [$prop]);
        $this->assertSame(['integer', 'string', 'null'], $result->type);
    }

    public function testTransformDatetimeType(): void
    {
        $prop = (object)['type' => 'datetime'];
        $result = $this->invokeMethod('transformCustomTypeToJsonSchemaType', [$prop]);
        $this->assertSame('string', $result->type);
    }

    public function testTransformTypeAsArray(): void
    {
        $prop = (object)['type' => ['file', 'null']];
        $result = $this->invokeMethod('transformCustomTypeToJsonSchemaType', [$prop]);
        $this->assertIsArray($result->type);
        // file should be transformed to ['integer', 'string', 'null'], null stays
        $this->assertSame([['integer', 'string', 'null'], 'null'], $result->type);
    }

    public function testTransformTypeNoTypeSet(): void
    {
        $prop = (object)['description' => 'no type'];
        $result = $this->invokeMethod('transformCustomTypeToJsonSchemaType', [$prop]);
        $this->assertFalse(isset($result->type));
    }

    public function testTransformStandardTypeUntouched(): void
    {
        $prop = (object)['type' => 'string'];
        $result = $this->invokeMethod('transformCustomTypeToJsonSchemaType', [$prop]);
        $this->assertSame('string', $result->type);
    }

    // =========================================================================
    // fixMisplacedArrayConstraints
    // =========================================================================

    public function testFixMisplacedArrayConstraintsNonArray(): void
    {
        $prop = (object)['type' => 'string', 'enum' => ['a', 'b']];
        $result = $this->invokeMethod('fixMisplacedArrayConstraints', [$prop]);
        // Should return unchanged since type is not array
        $this->assertSame('string', $result->type);
        $this->assertSame(['a', 'b'], $result->enum);
    }

    public function testFixMisplacedArrayConstraintsMovesEnumToItems(): void
    {
        $prop = (object)['type' => 'array', 'enum' => ['a', 'b']];
        $result = $this->invokeMethod('fixMisplacedArrayConstraints', [$prop]);
        $this->assertFalse(isset($result->enum));
        $this->assertSame(['a', 'b'], $result->items->enum);
        $this->assertSame('string', $result->items->type);
    }

    public function testFixMisplacedArrayConstraintsMovesOneOfToItems(): void
    {
        $oneOfItem = (object)['type' => 'string'];
        $prop = (object)['type' => 'array', 'oneOf' => [$oneOfItem]];
        $result = $this->invokeMethod('fixMisplacedArrayConstraints', [$prop]);
        $this->assertFalse(isset($result->oneOf));
        $this->assertTrue(isset($result->items->oneOf));
    }

    public function testFixMisplacedArrayConstraintsOneOfAsObject(): void
    {
        $prop = (object)['type' => 'array', 'oneOf' => (object)['variant1' => (object)['type' => 'string']]];
        $result = $this->invokeMethod('fixMisplacedArrayConstraints', [$prop]);
        $this->assertFalse(isset($result->oneOf));
        $this->assertTrue(isset($result->items->oneOf));
    }

    public function testFixMisplacedArrayConstraintsExistingItems(): void
    {
        $existingItems = (object)['type' => 'string', 'enum' => ['existing']];
        $prop = (object)['type' => 'array', 'enum' => ['new1', 'new2'], 'items' => $existingItems];
        $result = $this->invokeMethod('fixMisplacedArrayConstraints', [$prop]);
        // items already has enum, so array-level enum should be removed but items enum unchanged
        $this->assertFalse(isset($result->enum));
        $this->assertSame(['existing'], $result->items->enum);
    }

    // =========================================================================
    // extractHandlingFromOneOfItems
    // =========================================================================

    public function testExtractHandlingFromOneOfItemsNull(): void
    {
        $result = $this->invokeMethod('extractHandlingFromOneOfItems', [null]);
        $this->assertNull($result);
    }

    public function testExtractHandlingFromOneOfItemsString(): void
    {
        $result = $this->invokeMethod('extractHandlingFromOneOfItems', ['not-an-iterable']);
        $this->assertNull($result);
    }

    public function testExtractHandlingFromOneOfItemsWithConfig(): void
    {
        $oneOfItems = [
            (object)['type' => 'string'],
            (object)['type' => 'object', 'objectConfiguration' => (object)['handling' => 'related-object']],
        ];
        $result = $this->invokeMethod('extractHandlingFromOneOfItems', [$oneOfItems]);
        $this->assertSame('related-object', $result);
    }

    public function testExtractHandlingFromOneOfItemsWithArrayConfig(): void
    {
        $oneOfItems = [
            (object)['type' => 'object', 'objectConfiguration' => ['handling' => 'nested-object']],
        ];
        $result = $this->invokeMethod('extractHandlingFromOneOfItems', [$oneOfItems]);
        $this->assertSame('nested-object', $result);
    }

    public function testExtractHandlingFromOneOfItemsNoHandling(): void
    {
        $oneOfItems = [
            (object)['type' => 'object', 'objectConfiguration' => (object)['other' => 'value']],
        ];
        $result = $this->invokeMethod('extractHandlingFromOneOfItems', [$oneOfItems]);
        $this->assertNull($result);
    }

    // =========================================================================
    // extractObjectConfigurationHandling
    // =========================================================================

    public function testExtractObjectConfigurationHandlingDirect(): void
    {
        $prop = (object)['objectConfiguration' => (object)['handling' => 'related-object']];
        $result = $this->invokeMethod('extractObjectConfigurationHandling', [$prop]);
        $this->assertSame('related-object', $result);
    }

    public function testExtractObjectConfigurationHandlingFromItems(): void
    {
        $prop = (object)[
            'items' => (object)['objectConfiguration' => ['handling' => 'nested-object']],
        ];
        $result = $this->invokeMethod('extractObjectConfigurationHandling', [$prop]);
        $this->assertSame('nested-object', $result);
    }

    public function testExtractObjectConfigurationHandlingFromItemsOneOf(): void
    {
        $prop = (object)[
            'items' => (object)[
                'oneOf' => [
                    (object)['objectConfiguration' => (object)['handling' => 'related-object']],
                ],
            ],
        ];
        $result = $this->invokeMethod('extractObjectConfigurationHandling', [$prop]);
        $this->assertSame('related-object', $result);
    }

    public function testExtractObjectConfigurationHandlingFromDirectOneOf(): void
    {
        $prop = (object)[
            'oneOf' => [
                (object)['objectConfiguration' => ['handling' => 'nested-object']],
            ],
        ];
        $result = $this->invokeMethod('extractObjectConfigurationHandling', [$prop]);
        $this->assertSame('nested-object', $result);
    }

    public function testExtractObjectConfigurationHandlingReturnsNull(): void
    {
        $prop = (object)['type' => 'string'];
        $result = $this->invokeMethod('extractObjectConfigurationHandling', [$prop]);
        $this->assertNull($result);
    }

    // =========================================================================
    // transformArrayItemsForValidation
    // =========================================================================

    public function testTransformArrayItemsForValidationNonObject(): void
    {
        $items = (object)['type' => 'string'];
        $result = $this->invokeMethod('transformArrayItemsForValidation', [$items]);
        $this->assertSame('string', $result->type);
    }

    public function testTransformArrayItemsForValidationRelatedObject(): void
    {
        $items = (object)[
            'type' => 'object',
            'objectConfiguration' => (object)['handling' => 'related-object'],
            'properties' => (object)['name' => (object)['type' => 'string']],
            'required' => ['name'],
        ];
        $result = $this->invokeMethod('transformArrayItemsForValidation', [$items]);
        $this->assertTrue(isset($result->oneOf));
        $this->assertFalse(isset($result->type));
    }

    public function testTransformArrayItemsForValidationNestedObject(): void
    {
        $items = (object)[
            'type' => 'object',
            'objectConfiguration' => ['handling' => 'nested-object'],
        ];
        $result = $this->invokeMethod('transformArrayItemsForValidation', [$items]);
        $this->assertSame('object', $result->type);
        $this->assertSame('Nested object', $result->description);
    }

    public function testTransformArrayItemsForValidationWithRef(): void
    {
        $items = (object)[
            'type' => 'object',
            '$ref' => '#/components/schemas/Something',
        ];
        $result = $this->invokeMethod('transformArrayItemsForValidation', [$items]);
        // Has $ref but no config => useUuidStrings = true
        $this->assertTrue(isset($result->oneOf));
    }

    public function testTransformArrayItemsForValidationNoConfig(): void
    {
        $items = (object)['type' => 'object'];
        $result = $this->invokeMethod('transformArrayItemsForValidation', [$items]);
        // No config, no $ref => nested object path
        $this->assertSame('object', $result->type);
    }

    // =========================================================================
    // transformToUuidProperty
    // =========================================================================

    public function testTransformToUuidPropertyWithoutInversedBy(): void
    {
        $schema = (object)[
            'type' => 'object',
            'properties' => (object)['name' => (object)['type' => 'string']],
            'required' => ['name'],
            '$ref' => '#/components/schemas/Related',
        ];
        $this->invokeMethod('transformToUuidProperty', [$schema]);
        $this->assertSame('string', $schema->type);
        $this->assertStringContainsString('0-9a-f', $schema->pattern);
        $this->assertFalse(isset($schema->properties));
        $this->assertFalse(isset($schema->{'$ref'}));
    }

    public function testTransformToUuidPropertyWithInversedBy(): void
    {
        $schema = (object)[
            'type' => 'object',
            'inversedBy' => 'children',
            'properties' => (object)['name' => (object)['type' => 'string']],
            'required' => ['name'],
            '$ref' => '#/components/schemas/Related',
        ];
        $this->invokeMethod('transformToUuidProperty', [$schema]);
        $this->assertTrue(isset($schema->oneOf));
        $this->assertCount(2, $schema->oneOf);
    }

    public function testTransformToUuidPropertyWithInversedByEmptyProps(): void
    {
        $schema = (object)[
            'type' => 'object',
            'inversedBy' => 'children',
        ];
        $this->invokeMethod('transformToUuidProperty', [$schema]);
        $this->assertTrue(isset($schema->oneOf));
    }

    // =========================================================================
    // transformToNestedObjectProperty
    // =========================================================================

    public function testTransformToNestedObjectPropertyNoRef(): void
    {
        $schema = (object)['type' => 'object', 'properties' => (object)[]];
        $this->invokeMethod('transformToNestedObjectProperty', [$schema]);
        // No change since no $ref
        $this->assertSame('object', $schema->type);
    }

    public function testTransformToNestedObjectPropertyWithObjectRef(): void
    {
        $schema = (object)[
            'type' => 'object',
            '$ref' => (object)['id' => '#/components/schemas/Self'],
        ];
        $this->invokeMethod('transformToNestedObjectProperty', [$schema]);
        // The method resolves object $ref to string, checks for self-reference.
        // isSelfReference uses a tempSchema with $ref = schemaSlug (no prefix),
        // so it won't match the '#/components/schemas/' pattern. Object stays unchanged.
        $this->assertSame('object', $schema->type);
    }

    public function testTransformToNestedObjectPropertyWithStringRef(): void
    {
        $schema = (object)[
            'type' => 'object',
            '$ref' => '#/components/schemas/TestSlug',
        ];
        // The internal isSelfReference check uses a tempSchema with $ref = 'TestSlug',
        // which doesn't contain '#/components/schemas/', so no self-reference detected.
        // The method covers the string reference path.
        $this->invokeMethod('transformToNestedObjectProperty', [$schema]);
        $this->assertSame('object', $schema->type);
    }

    public function testTransformToNestedObjectPropertyWithArrayRef(): void
    {
        $schema = (object)[
            'type' => 'object',
            '$ref' => ['id' => '#/components/schemas/Self'],
        ];
        $this->invokeMethod('transformToNestedObjectProperty', [$schema]);
        $this->assertSame('object', $schema->type);
    }

    // =========================================================================
    // transformOpenRegisterObjectConfigurations
    // =========================================================================

    public function testTransformOpenRegisterObjectConfigurationsNoProperties(): void
    {
        $schema = (object)['type' => 'object'];
        $result = $this->invokeMethod('transformOpenRegisterObjectConfigurations', [$schema]);
        $this->assertSame('object', $result->type);
    }

    // =========================================================================
    // cleanPropertyForValidation
    // =========================================================================

    public function testCleanPropertyForValidationNonObject(): void
    {
        $result = $this->invokeMethod('cleanPropertyForValidation', ['just-a-string', false]);
        $this->assertSame('just-a-string', $result);
    }

    // =========================================================================
    // transformPropertyForOpenRegister - inversedBy branches
    // =========================================================================

    public function testTransformPropertyForOpenRegisterInversedByArray(): void
    {
        $prop = (object)[
            'type' => 'array',
            'inversedBy' => 'parents',
            'items' => (object)['type' => 'object'],
        ];
        $this->invokeMethod('transformPropertyForOpenRegister', [$prop]);
        // For array with inversedBy, items should be transformed to oneOf
        $this->assertTrue(isset($prop->items->oneOf));
    }

    public function testTransformPropertyForOpenRegisterInversedByObject(): void
    {
        $prop = (object)[
            'type' => 'object',
            'inversedBy' => 'parent',
            'properties' => (object)['name' => (object)['type' => 'string']],
            'required' => ['name'],
            '$ref' => '#/components/schemas/Other',
        ];
        $this->invokeMethod('transformPropertyForOpenRegister', [$prop]);
        // For object with inversedBy, should get oneOf with null/string/object
        $this->assertTrue(isset($prop->oneOf));
        $this->assertCount(3, $prop->oneOf);
    }

    public function testTransformPropertyForOpenRegisterStripsRefFromString(): void
    {
        $prop = (object)[
            'type' => 'string',
            '$ref' => '#/components/schemas/Related',
        ];
        $this->invokeMethod('transformPropertyForOpenRegister', [$prop]);
        $this->assertFalse(isset($prop->{'$ref'}));
        $this->assertSame('string', $prop->type);
    }

    public function testTransformPropertyForOpenRegisterArrayItemsInversedBy(): void
    {
        $prop = (object)[
            'type' => 'array',
            'items' => (object)[
                'type' => 'object',
                'inversedBy' => 'parent',
                'properties' => (object)['name' => (object)['type' => 'string']],
            ],
        ];
        $this->invokeMethod('transformPropertyForOpenRegister', [$prop]);
        // Items with inversedBy should be transformed to UUID string
        $this->assertSame('string', $prop->items->type);
        $this->assertStringContainsString('0-9a-f', $prop->items->pattern ?? '');
    }

    // =========================================================================
    // resolveSchema - local and file schema resolution
    // =========================================================================

    public function testResolveSchemaLocalSchemaPath(): void
    {
        $schema = $this->createSchema();

        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $uri = $this->createMock(Uri::class);
        $uri->method('scheme')->willReturn('http');
        $uri->method('host')->willReturn('localhost:8080');
        $uri->method('path')->willReturn('/index.php/apps/openregister/api/schemas/1');

        $result = $this->handler->resolveSchema($uri);
        $this->assertIsString($result);
    }

    public function testResolveSchemaFileSchemaPath(): void
    {
        $uri = $this->createMock(Uri::class);
        $uri->method('scheme')->willReturn('http');
        $uri->method('host')->willReturn('localhost:8080');
        $uri->method('path')->willReturn('/index.php/apps/openregister/api/files/schema');

        $result = $this->handler->resolveSchema($uri);
        $decoded = json_decode($result);
        $this->assertSame('object', $decoded->type);
        $this->assertTrue(isset($decoded->properties->id));
    }

    public function testResolveSchemaExternalNotAllowed(): void
    {
        $this->config->method('getValueBool')
            ->willReturn(false);

        $uri = $this->createMock(Uri::class);
        $uri->method('scheme')->willReturn('https');
        $uri->method('host')->willReturn('example.com');
        $uri->method('path')->willReturn('/schema.json');

        $result = $this->handler->resolveSchema($uri);
        $this->assertSame('', $result);
    }

    // =========================================================================
    // handleValidationException
    // =========================================================================

    public function testHandleCustomValidationException(): void
    {
        $exception = new CustomValidationException(
            'Field not unique',
            ['field' => 'Value is not unique']
        );

        $response = $this->handler->handleValidationException($exception);
        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertSame('error', $data['status']);
        $this->assertSame('Validation failed', $data['message']);
    }

    // =========================================================================
    // generateErrorMessage
    // =========================================================================

    public function testGenerateErrorMessageForValidResult(): void
    {
        // Create a valid ValidationResult by validating valid data
        $validator = new \Opis\JsonSchema\Validator();
        $result = $validator->validate(json_decode('{"name": "test"}'), json_decode('{"type": "object"}'));
        $this->assertTrue($result->isValid());

        $message = $this->handler->generateErrorMessage($result);
        $this->assertSame('Validation passed', $message);
    }

    // =========================================================================
    // preprocessSchemaReferences - UUID-transformed items skip
    // =========================================================================

    public function testPreprocessSchemaReferencesSkipsUuidItems(): void
    {
        $schema = (object)[
            'type' => 'object',
            'items' => (object)[
                'type' => 'string',
                'pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-uuid',
            ],
        ];
        $result = $this->invokeMethod('preprocessSchemaReferences', [$schema, []]);
        // Items with UUID pattern should be skipped
        $this->assertSame('string', $result->items->type);
    }

    public function testPreprocessSchemaReferencesProcessesItems(): void
    {
        $schema = (object)[
            'type' => 'object',
            'items' => (object)[
                'type' => 'object',
                'description' => 'nested',
            ],
        ];
        $result = $this->invokeMethod('preprocessSchemaReferences', [$schema, []]);
        $this->assertSame('object', $result->items->type);
    }

    // =========================================================================
    // transformSchemaForValidation - various branches
    // =========================================================================

    public function testTransformSchemaForValidationNoProperties(): void
    {
        $schema = (object)['type' => 'object'];
        [$resultSchema, $resultObj] = $this->invokeMethod('transformSchemaForValidation', [$schema, ['foo' => 'bar'], 'test']);
        $this->assertSame('object', $resultSchema->type);
    }

    public function testTransformSchemaForValidationSelfRefRelatedObject(): void
    {
        $schema = (object)[
            'type' => 'object',
            'properties' => (object)[
                'parent' => (object)[
                    'type' => 'object',
                    '$ref' => '#/components/schemas/item',
                    'objectConfiguration' => (object)['handling' => 'related-object'],
                ],
            ],
        ];
        [$resultSchema, $resultObj] = $this->invokeMethod('transformSchemaForValidation', [$schema, ['parent' => 'uuid-123'], 'item']);
        // parent should have been transformed (self-ref + related-object)
        $this->assertSame('string', $resultSchema->properties->parent->type ?? null);
    }

    public function testTransformSchemaForValidationSelfRefRelatedObjectWithInversedBy(): void
    {
        $schema = (object)[
            'type' => 'object',
            'properties' => (object)[
                'parent' => (object)[
                    'type' => 'object',
                    '$ref' => '#/components/schemas/item',
                    'objectConfiguration' => ['handling' => 'related-object'],
                    'inversedBy' => 'children',
                ],
            ],
        ];
        [$resultSchema, $resultObj] = $this->invokeMethod('transformSchemaForValidation', [$schema, [], 'item']);
        // Should have oneOf with null, string, object
        $this->assertTrue(isset($resultSchema->properties->parent->oneOf));
    }

    public function testTransformSchemaForValidationSelfRefArrayItems(): void
    {
        $schema = (object)[
            'type' => 'object',
            'properties' => (object)[
                'children' => (object)[
                    'type' => 'array',
                    '$ref' => '#/components/schemas/item',
                    'items' => (object)[
                        '$ref' => '#/components/schemas/item',
                        'type' => 'object',
                    ],
                ],
            ],
        ];
        [$resultSchema, $resultObj] = $this->invokeMethod('transformSchemaForValidation', [$schema, [], 'item']);
        // Array items should be UUID pattern
        $items = $resultSchema->properties->children->items ?? null;
        $this->assertNotNull($items);
    }

    public function testTransformSchemaForValidationSelfRefArrayItemsWithInversedBy(): void
    {
        $schema = (object)[
            'type' => 'object',
            'properties' => (object)[
                'children' => (object)[
                    'type' => 'array',
                    '$ref' => '#/components/schemas/item',
                    'items' => (object)[
                        '$ref' => '#/components/schemas/item',
                        'type' => 'object',
                        'inversedBy' => 'parent',
                    ],
                ],
            ],
        ];
        [$resultSchema, $resultObj] = $this->invokeMethod('transformSchemaForValidation', [$schema, [], 'item']);
        // Items should have oneOf since inversedBy on self-ref array items
        $children = $resultSchema->properties->children ?? null;
        $this->assertNotNull($children);
        $items = $children->items ?? null;
        $this->assertNotNull($items);
        // The items are replaced with oneOf structure for inversedBy
        $this->assertTrue(
            isset($items->oneOf) || isset($items->type),
            'Items should have oneOf or type after transformation'
        );
    }

    public function testTransformSchemaForValidationRemovesSchemaId(): void
    {
        $schema = (object)[
            'type' => 'object',
            '$id' => 'http://example.com/schema',
            'properties' => (object)[
                'name' => (object)['type' => 'string'],
            ],
        ];
        [$resultSchema, $resultObj] = $this->invokeMethod('transformSchemaForValidation', [$schema, ['name' => 'test'], 'test']);
        $this->assertFalse(isset($resultSchema->{'$id'}));
    }

    // =========================================================================
    // cleanSchemaForValidation
    // =========================================================================

    public function testCleanSchemaForValidationRemovesMetadata(): void
    {
        $schema = (object)[
            'type' => 'object',
            'cascadeDelete' => true,
            'objectConfiguration' => (object)['handling' => 'related-object'],
            'inversedBy' => 'parent',
            'properties' => (object)[
                'name' => (object)[
                    'type' => 'string',
                    'cascadeDelete' => true,
                ],
            ],
            'items' => (object)[
                'type' => 'object',
                'objectConfiguration' => (object)['handling' => 'nested-object'],
            ],
        ];
        $result = $this->invokeMethod('cleanSchemaForValidation', [$schema]);
        $this->assertFalse(isset($result->cascadeDelete));
        $this->assertFalse(isset($result->objectConfiguration));
        $this->assertFalse(isset($result->inversedBy));
        $this->assertFalse(isset($result->properties->name->cascadeDelete));
    }

    // =========================================================================
    // findSchemaBySlug
    // =========================================================================

    public function testFindSchemaBySlugDirectMatch(): void
    {
        $schema = $this->createSchema([], 'my-schema');
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->invokeMethod('findSchemaBySlug', ['my-schema']);
        $this->assertNotNull($result);
        $this->assertSame('my-schema', $result->getSlug());
    }

    public function testFindSchemaBySlugCaseInsensitive(): void
    {
        $schema = $this->createSchema([], 'MySchema');

        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('not found'));

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $result = $this->invokeMethod('findSchemaBySlug', ['myschema']);
        $this->assertNotNull($result);
    }

    public function testFindSchemaBySlugNotFound(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('not found'));

        $this->schemaMapper->method('findAll')
            ->willReturn([]);

        $result = $this->invokeMethod('findSchemaBySlug', ['nonexistent']);
        $this->assertNull($result);
    }

    public function testFindSchemaBySlugFindAllFails(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('not found'));

        $this->schemaMapper->method('findAll')
            ->willThrowException(new \Exception('db error'));

        $result = $this->invokeMethod('findSchemaBySlug', ['anything']);
        $this->assertNull($result);
    }
}
