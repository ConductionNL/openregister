<?php

declare(strict_types=1);

/**
 * ValidateObject array-of-$ref Unit Tests
 *
 * Regression coverage for the fleet detail-page seeding audit (ISSUE C1):
 * a property that is an ARRAY whose items carry a bare schema-slug `$ref`
 * (e.g. scholiq assignment.briefingMaterialIds / item-bank.itemIds declared as
 * items {"$ref":"<slug>","format":"uuid"}) failed create with
 * `Unresolved reference: schema:///<slug>#` because Opis JSON Schema tried to
 * resolve the slug as a URI. Such relation references must validate as opaque
 * UUID strings, mirroring single-property string `$ref` handling.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Unit tests for array-of-$ref relation validation in ValidateObject.
 */
class ValidateObjectArrayRefTest extends TestCase
{
    private ValidateObject $handler;

    /**
     * Build a ValidateObject with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('getBaseUrl')->willReturn('http://localhost:8080');

        $this->handler = new ValidateObject(
            $this->createMock(IAppConfig::class),
            $this->createMock(MagicMapper::class),
            $this->createMock(SchemaMapper::class),
            $urlGenerator,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Build a Schema entity with the given slug.
     *
     * @param string $slug Schema slug.
     *
     * @return Schema
     */
    private function schema(string $slug='assignment'): Schema
    {
        $schema = new Schema();
        $schema->setSlug($slug);
        $schema->setTitle('Test Schema');
        return $schema;
    }//end schema()

    /**
     * Build a schema object with a single array-of-$ref property.
     *
     * @param string $property Property name.
     *
     * @return object
     */
    private function arrayRefSchema(string $property='briefingMaterialIds'): object
    {
        return json_decode(
            json_encode(
                [
                    'type'       => 'object',
                    'properties' => [
                        $property => [
                            'type'  => 'array',
                            'items' => [
                                '$ref'   => 'briefing-material',
                                'format' => 'uuid',
                            ],
                        ],
                    ],
                ]
            )
        );
    }//end arrayRefSchema()

    /**
     * An array of UUID references validates instead of raising
     * "Unresolved reference".
     *
     * @return void
     */
    public function testArrayOfSlugRefResolvesForUuidValues(): void
    {
        $object = ['briefingMaterialIds' => ['550e8400-e29b-41d4-a716-446655440000']];

        $result = $this->handler->validateObject($object, $this->schema(), $this->arrayRefSchema());

        $this->assertTrue(
            $result->isValid(),
            'array-of-$ref relation should validate as opaque UUID strings'
        );
    }//end testArrayOfSlugRefResolvesForUuidValues()

    /**
     * Multiple UUID references (item-bank.itemIds style) validate.
     *
     * @return void
     */
    public function testArrayOfSlugRefAcceptsMultipleUuids(): void
    {
        $object = [
            'itemIds' => [
                '11111111-1111-1111-1111-111111111111',
                '22222222-2222-2222-2222-222222222222',
            ],
        ];

        $result = $this->handler->validateObject($object, $this->schema('item-bank'), $this->arrayRefSchema('itemIds'));

        $this->assertTrue($result->isValid(), 'multiple UUID references should validate');
    }//end testArrayOfSlugRefAcceptsMultipleUuids()

    /**
     * A non-UUID value in an array-of-$ref relation fails with a normal
     * validation error (HTTP 400 semantics), not a 500 "Unresolved reference".
     *
     * @return void
     */
    public function testArrayOfSlugRefRejectsNonReferenceValue(): void
    {
        $object = ['briefingMaterialIds' => ['not a uuid at all!']];

        $result = $this->handler->validateObject($object, $this->schema(), $this->arrayRefSchema());

        $this->assertFalse($result->isValid(), 'garbage values should fail validation, not throw');
    }//end testArrayOfSlugRefRejectsNonReferenceValue()

    /**
     * A genuine nested array-of-objects property (no $ref / no inversedBy —
     * scholiq proctoring-session `flags` shape) still validates cleanly and is
     * NOT collapsed to a UUID string by the array-$ref transform.
     *
     * @return void
     */
    public function testNestedArrayOfObjectsStillValidates(): void
    {
        $schemaObject = json_decode(
            json_encode(
                [
                    'type'       => 'object',
                    'properties' => [
                        'flags' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'required'   => ['flagId', 'kind'],
                                'properties' => [
                                    'flagId'   => ['type' => 'string'],
                                    'kind'     => ['type' => 'string'],
                                    'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                                ],
                            ],
                        ],
                    ],
                ]
            )
        );

        $object = ['flags' => [['flagId' => 'f1', 'kind' => 'gaze-away', 'severity' => 'low']]];

        $result = $this->handler->validateObject($object, $this->schema('proctoring-session'), $schemaObject);

        $this->assertTrue($result->isValid(), 'nested array-of-objects must keep its object items');
    }//end testNestedArrayOfObjectsStillValidates()
}//end class
