<?php

declare(strict_types=1);

/**
 * ValidateObject unusable-`$ref` Unit Tests.
 *
 * A JSON Schema `$ref` MUST be a non-empty string. Opis enforces that in
 * `RefKeywordParser::parse()` and throws
 * `Opis\JsonSchema\Exceptions\InvalidKeywordException: $ref must be a non-empty string`
 * for anything else.
 *
 * OpenRegister stores `$ref` as a RELATION marker, never as something Opis
 * should resolve, and `ValidateObject` strips it before validation — but only
 * for the shapes its transform branches recognise: string-typed properties,
 * `items`, self-references and object properties carrying an
 * `objectConfiguration.handling`. A `$ref` that is an INT, null, an array or an
 * empty string matches none of them, survives into the schema handed to Opis,
 * and blows up.
 *
 * That is not hypothetical. `ImportHandler::importSchema()` wrote a resolved
 * schema ID (an int) to `$property['$ref']` when it meant
 * `$property['items']['$ref']`, so any array-of-relations property imported
 * through the `schemasMap` fallback carried an int `$ref`. Measured on hermiq's
 * clean-install e2e (run 30865280923): `PUT /api/agents/{id}/tool-grants`
 * answered 500 with `$ref must be a non-empty string` from
 * `ObjectService::saveObject()`.
 *
 * The import side is fixed, but every database imported before that fix still
 * carries the bad value, so this normalisation is what heals them — and these
 * tests are what stop it regressing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
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

/**
 * Locks the behaviour of an unusable `$ref` on a stored schema.
 */
class ValidateObjectUnusableRefTest extends TestCase
{

    /**
     * The subject under test.
     *
     * @var ValidateObject
     */
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
     * A Schema entity with the given slug.
     *
     * @param string $slug Schema slug.
     *
     * @return Schema
     */
    private function schema(string $slug='agent'): Schema
    {
        $schema = new Schema();
        $schema->setSlug($slug);
        $schema->setTitle('Agent');
        return $schema;

    }//end schema()


    /**
     * A schema object with one array property carrying the given top-level
     * `$ref` — the exact shape a pre-fix import left behind.
     *
     * @param mixed $ref The stored `$ref` value.
     *
     * @return object
     */
    private function schemaWithPropertyRef(mixed $ref): object
    {
        return json_decode(
            json_encode(
                [
                    'type'       => 'object',
                    'properties' => [
                        'delegationAllowlist' => [
                            'type'  => 'array',
                            '$ref'  => $ref,
                            'items' => [
                                'type'   => 'string',
                                'format' => 'uuid',
                            ],
                        ],
                    ],
                ]
            )
        );

    }//end schemaWithPropertyRef()


    /**
     * An INT `$ref` — the ImportHandler shape — validates instead of throwing.
     *
     * The property must be PRESENT in the object: Opis parses a property's
     * subschema lazily, which is why this endpoint looked healthy for months
     * on instances whose optional relation arrays were never written.
     *
     * @return void
     */
    public function testIntegerRefOnAnArrayPropertyDoesNotThrow(): void
    {
        $object = ['delegationAllowlist' => ['550e8400-e29b-41d4-a716-446655440000']];

        $result = $this->handler->validateObject($object, $this->schema(), $this->schemaWithPropertyRef(4365));

        $this->assertTrue(
            $result->isValid(),
            'an int $ref must be dropped, not handed to Opis as a JSON Schema reference'
        );

    }//end testIntegerRefOnAnArrayPropertyDoesNotThrow()


    /**
     * An EMPTY-STRING `$ref` is equally unusable and equally dropped.
     *
     * @return void
     */
    public function testEmptyStringRefDoesNotThrow(): void
    {
        $object = ['delegationAllowlist' => ['11111111-1111-1111-1111-111111111111']];

        $result = $this->handler->validateObject($object, $this->schema(), $this->schemaWithPropertyRef(''));

        $this->assertTrue($result->isValid(), 'an empty-string $ref must be dropped');

    }//end testEmptyStringRefDoesNotThrow()


    /**
     * A NULL `$ref` is dropped too — and a null property value, which survives
     * OpenRegister's own empty-value filter, is what actually reaches Opis.
     *
     * @return void
     */
    public function testNullRefWithANullValueDoesNotThrow(): void
    {
        $object = ['delegationAllowlist' => null];

        $result = $this->handler->validateObject($object, $this->schema(), $this->schemaWithPropertyRef(null));

        $this->assertTrue($result->isValid(), 'a null $ref must be dropped');

    }//end testNullRefWithANullValueDoesNotThrow()


    /**
     * 🔑 NEGATIVE CONTROL. Dropping the unusable `$ref` must not switch
     * validation off for the property — a value of the wrong TYPE still fails.
     *
     * Without this, all three tests above would pass just as well if the fix
     * had removed the property from validation altogether.
     *
     * @return void
     */
    public function testDroppingTheRefStillLeavesTheTypeEnforced(): void
    {
        $object = ['delegationAllowlist' => 'not an array at all'];

        $result = $this->handler->validateObject($object, $this->schema(), $this->schemaWithPropertyRef(4365));

        $this->assertFalse(
            $result->isValid(),
            'a string where the schema declares an array must still fail validation'
        );

    }//end testDroppingTheRefStillLeavesTheTypeEnforced()


}//end class
