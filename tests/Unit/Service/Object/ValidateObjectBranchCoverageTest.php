<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use stdClass;

/**
 * Branch coverage tests for ValidateObject — targets uncovered branches in
 * extractObjectConfigurationHandling, getMixedValue, extractHandlingFromOneOfItems,
 * transformPropertyForOpenRegister, transformToUuidProperty, transformToNestedObjectProperty.
 */
class ValidateObjectBranchCoverageTest extends TestCase
{
    private ValidateObject $validator;
    private IAppConfig&MockObject $config;
    private ObjectEntityMapper&MockObject $objectMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private IURLGenerator&MockObject $urlGenerator;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->createMock(IAppConfig::class);
        $this->objectMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->validator = new ValidateObject(
            $this->config,
            $this->objectMapper,
            $this->schemaMapper,
            $this->urlGenerator,
            $this->logger
        );
    }

    /**
     * Helper to invoke private methods via reflection.
     */
    private function invokePrivate(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(ValidateObject::class, $method);
        return $ref->invoke($this->validator, ...$args);
    }

    // =========================================================================
    // getMixedValue — array, object, null cases
    // =========================================================================

    public function testGetMixedValueFromArray(): void
    {
        $result = $this->invokePrivate('getMixedValue', [['handling' => 'related-object'], 'handling']);
        $this->assertSame('related-object', $result);
    }

    public function testGetMixedValueFromObject(): void
    {
        $obj = (object) ['handling' => 'nested-object'];
        $result = $this->invokePrivate('getMixedValue', [$obj, 'handling']);
        $this->assertSame('nested-object', $result);
    }

    public function testGetMixedValueMissingKey(): void
    {
        $result = $this->invokePrivate('getMixedValue', [['foo' => 'bar'], 'handling']);
        $this->assertNull($result);
    }

    public function testGetMixedValueNull(): void
    {
        $result = $this->invokePrivate('getMixedValue', [null, 'handling']);
        $this->assertNull($result);
    }

    public function testGetMixedValueString(): void
    {
        $result = $this->invokePrivate('getMixedValue', ['string-value', 'handling']);
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

    public function testExtractHandlingFromOneOfItemsScalar(): void
    {
        $result = $this->invokePrivate('extractHandlingFromOneOfItems', ['not-iterable']);
        $this->assertNull($result);
    }

    public function testExtractHandlingFromOneOfItemsEmptyArray(): void
    {
        $result = $this->invokePrivate('extractHandlingFromOneOfItems', [[]]);
        $this->assertNull($result);
    }

    public function testExtractHandlingFromOneOfItemsWithObjectConfig(): void
    {
        $oneOf = [
            (object) [
                'objectConfiguration' => (object) [
                    'handling' => 'related-object',
                ],
            ],
        ];
        $result = $this->invokePrivate('extractHandlingFromOneOfItems', [$oneOf]);
        $this->assertSame('related-object', $result);
    }

    public function testExtractHandlingFromOneOfItemsWithArrayConfig(): void
    {
        $oneOf = [
            ['objectConfiguration' => ['handling' => 'nested-object']],
        ];
        $result = $this->invokePrivate('extractHandlingFromOneOfItems', [$oneOf]);
        $this->assertSame('nested-object', $result);
    }

    public function testExtractHandlingFromOneOfItemsNoHandling(): void
    {
        $oneOf = [
            (object) ['objectConfiguration' => (object) ['other' => 'value']],
        ];
        $result = $this->invokePrivate('extractHandlingFromOneOfItems', [$oneOf]);
        $this->assertNull($result);
    }

    // =========================================================================
    // extractObjectConfigurationHandling
    // =========================================================================

    public function testExtractObjectConfigurationHandlingDirect(): void
    {
        $schema = (object) [
            'objectConfiguration' => ['handling' => 'related-object'],
        ];
        $result = $this->invokePrivate('extractObjectConfigurationHandling', [$schema]);
        $this->assertSame('related-object', $result);
    }

    public function testExtractObjectConfigurationHandlingFromItems(): void
    {
        $schema = (object) [
            'items' => (object) [
                'objectConfiguration' => ['handling' => 'nested-object'],
            ],
        ];
        $result = $this->invokePrivate('extractObjectConfigurationHandling', [$schema]);
        $this->assertSame('nested-object', $result);
    }

    public function testExtractObjectConfigurationHandlingFromItemsOneOf(): void
    {
        $schema = (object) [
            'items' => (object) [
                'oneOf' => [
                    (object) [
                        'objectConfiguration' => (object) ['handling' => 'related-object'],
                    ],
                ],
            ],
        ];
        $result = $this->invokePrivate('extractObjectConfigurationHandling', [$schema]);
        $this->assertSame('related-object', $result);
    }

    public function testExtractObjectConfigurationHandlingFromPropertyOneOf(): void
    {
        $schema = (object) [
            'oneOf' => [
                (object) [
                    'objectConfiguration' => ['handling' => 'nested-object'],
                ],
            ],
        ];
        $result = $this->invokePrivate('extractObjectConfigurationHandling', [$schema]);
        $this->assertSame('nested-object', $result);
    }

    public function testExtractObjectConfigurationHandlingNone(): void
    {
        $schema = (object) ['type' => 'string'];
        $result = $this->invokePrivate('extractObjectConfigurationHandling', [$schema]);
        $this->assertNull($result);
    }

    // =========================================================================
    // transformObjectPropertyForOpenRegister
    // =========================================================================

    public function testTransformObjectPropertyNoHandling(): void
    {
        $schema = (object) ['type' => 'object', 'properties' => (object) []];
        $this->invokePrivate('transformObjectPropertyForOpenRegister', [$schema]);
        // Should be unchanged — no objectConfiguration
        $this->assertSame('object', $schema->type);
    }

    public function testTransformObjectPropertyRelatedObject(): void
    {
        $schema = (object) [
            'type' => 'object',
            'objectConfiguration' => ['handling' => 'related-object'],
            'properties' => (object) ['name' => (object) ['type' => 'string']],
            'required' => ['name'],
        ];
        $this->invokePrivate('transformObjectPropertyForOpenRegister', [$schema]);
        $this->assertSame('string', $schema->type);
        $this->assertStringContainsString('[0-9a-f]', $schema->pattern);
    }

    public function testTransformObjectPropertyNestedObject(): void
    {
        $schema = (object) [
            'type' => 'object',
            'objectConfiguration' => ['handling' => 'nested-object'],
        ];
        $this->invokePrivate('transformObjectPropertyForOpenRegister', [$schema]);
        // Should remain as object type
        $this->assertSame('object', $schema->type);
    }

    // =========================================================================
    // transformToUuidProperty — with inversedBy
    // =========================================================================

    public function testTransformToUuidPropertyWithInversedBy(): void
    {
        $schema = (object) [
            'type' => 'object',
            'inversedBy' => 'children',
            'properties' => (object) ['name' => (object) ['type' => 'string']],
            'required' => ['name'],
            '$ref' => '#/components/schemas/MySchema',
        ];
        $this->invokePrivate('transformToUuidProperty', [$schema]);

        $this->assertIsArray($schema->oneOf);
        $this->assertCount(2, $schema->oneOf);
    }

    public function testTransformToUuidPropertyWithoutInversedBy(): void
    {
        $schema = (object) [
            'type' => 'object',
            'properties' => (object) [],
            'required' => [],
        ];
        $this->invokePrivate('transformToUuidProperty', [$schema]);

        $this->assertSame('string', $schema->type);
        $this->assertStringContainsString('[0-9a-f]', $schema->pattern);
    }

    // =========================================================================
    // transformSchemaForValidation — no properties
    // =========================================================================

    public function testTransformSchemaForValidationNoProperties(): void
    {
        $schema = (object) ['type' => 'string'];
        [$resultSchema, $resultObj] = $this->invokePrivate(
            'transformSchemaForValidation',
            [$schema, ['key' => 'value'], 'my-slug']
        );
        $this->assertSame('string', $resultSchema->type);
        $this->assertSame('value', $resultObj['key']);
    }

    // =========================================================================
    // transformOpenRegisterObjectConfigurations — no properties
    // =========================================================================

    public function testTransformOpenRegisterObjectConfigurationsNoProperties(): void
    {
        $schema = (object) ['type' => 'array'];
        $result = $this->invokePrivate('transformOpenRegisterObjectConfigurations', [$schema]);
        $this->assertSame('array', $result->type);
    }

    // =========================================================================
    // transformPropertyForOpenRegister — strip $ref from string type
    // =========================================================================

    public function testTransformPropertyStripsRefFromStringType(): void
    {
        $schema = (object) [
            'type' => 'string',
            '$ref' => '#/components/schemas/SomeRef',
        ];
        $this->invokePrivate('transformPropertyForOpenRegister', [$schema]);

        $this->assertFalse(isset($schema->{'$ref'}));
        $this->assertSame('string', $schema->type);
    }

    // =========================================================================
    // transformPropertyForOpenRegister — inversedBy array
    // =========================================================================

    public function testTransformPropertyInversedByArray(): void
    {
        $schema = (object) [
            'type' => 'array',
            'inversedBy' => 'parentRef',
            'items' => (object) ['type' => 'object'],
        ];
        $this->invokePrivate('transformPropertyForOpenRegister', [$schema]);

        $this->assertIsArray($schema->items->oneOf);
    }

    public function testTransformPropertyInversedByObject(): void
    {
        $schema = (object) [
            'type' => 'object',
            'inversedBy' => 'parentRef',
            'properties' => (object) [],
        ];
        $this->invokePrivate('transformPropertyForOpenRegister', [$schema]);

        $this->assertIsArray($schema->oneOf);
        $this->assertCount(3, $schema->oneOf);
    }
}
