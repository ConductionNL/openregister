<?php
/**
 * Regression tests for JSON-Schema `readOnly` enforcement on the write path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://openregister.app
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `readOnly: true` was pure metadata before this change: Opis has no readOnly
 * keyword parser, and SchemaMapper classes it as a "freely overridable"
 * metadata field. These tests pin the enforcement contract.
 */
class Wave12ReadOnlyEnforcementTest extends TestCase
{
    private ValidateObject $validator;

    protected function setUp(): void
    {
        $this->validator = new ValidateObject(
            $this->createMock(IAppConfig::class),
            $this->createMock(MagicMapper::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * Build a schema whose properties are exactly $properties.
     */
    private function schemaWith(array $properties): Schema
    {
        $schema = new Schema();
        $schema->setProperties($properties);
        return $schema;
    }

    private function readOnlySchema(): Schema
    {
        return $this->schemaWith([
            'bsn'   => ['type' => 'string', 'readOnly' => true],
            'notes' => ['type' => 'string'],
        ]);
    }

    public function testMutatingReadOnlyPropertyIsAViolation(): void
    {
        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['bsn' => '999999999'],
            existingObject: ['bsn' => '111111111'],
            schema: $this->readOnlySchema()
        );

        $this->assertCount(1, $violations);
        $this->assertSame('bsn', $violations[0]['property']);
        $this->assertSame('999999999', $violations[0]['attempted']);
        $this->assertSame('111111111', $violations[0]['stored']);
    }

    public function testCreateIsNotEnforced(): void
    {
        // An empty existing object means CREATE — there is no prior value to
        // violate. readOnly means "immutable after creation", not "never set".
        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['bsn' => '999999999'],
            existingObject: [],
            schema: $this->readOnlySchema()
        );

        $this->assertSame([], $violations);
    }

    public function testUnchangedReadOnlyValueIsNotAViolation(): void
    {
        // A full-document PUT resends every field. Resending the same value is
        // not a mutation and must not be rejected.
        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['bsn' => '111111111', 'notes' => 'changed'],
            existingObject: ['bsn' => '111111111', 'notes' => 'original'],
            schema: $this->readOnlySchema()
        );

        $this->assertSame([], $violations);
    }

    public function testOmittedReadOnlyPropertyIsNotAViolation(): void
    {
        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['notes' => 'changed'],
            existingObject: ['bsn' => '111111111', 'notes' => 'original'],
            schema: $this->readOnlySchema()
        );

        $this->assertSame([], $violations);
    }

    public function testMutablePropertyIsNotEnforced(): void
    {
        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['notes' => 'changed'],
            existingObject: ['notes' => 'original'],
            schema: $this->readOnlySchema()
        );

        $this->assertSame([], $violations);
    }

    public function testTypeCoercionDoesNotMaskAMutation(): void
    {
        // Comparison is strict: "42" is not 42. A loose compare would let a
        // client silently change a readOnly field's type.
        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['bsn' => 42],
            existingObject: ['bsn' => '42'],
            schema: $this->readOnlySchema()
        );

        $this->assertCount(1, $violations);
        $this->assertSame('bsn', $violations[0]['property']);
    }

    public function testReadOnlyFalseIsNotEnforced(): void
    {
        $schema = $this->schemaWith([
            'bsn' => ['type' => 'string', 'readOnly' => false],
        ]);

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['bsn' => 'new'],
            existingObject: ['bsn' => 'old'],
            schema: $schema
        );

        $this->assertSame([], $violations);
    }

    public function testSchemaWithNoReadOnlyPropertiesIsANoOp(): void
    {
        $schema = $this->schemaWith(['notes' => ['type' => 'string']]);

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['notes' => 'changed'],
            existingObject: ['notes' => 'original'],
            schema: $schema
        );

        $this->assertSame([], $violations);
    }

    public function testMultipleViolationsAreAllReported(): void
    {
        // The caller renders these to the user; reporting only the first would
        // force a fix-one-retry loop.
        $schema = $this->schemaWith([
            'bsn'       => ['type' => 'string', 'readOnly' => true],
            'createdAt' => ['type' => 'string', 'readOnly' => true],
        ]);

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['bsn' => 'b2', 'createdAt' => 't2'],
            existingObject: ['bsn' => 'b1', 'createdAt' => 't1'],
            schema: $schema
        );

        $this->assertCount(2, $violations);
        $this->assertSame(['bsn', 'createdAt'], array_column($violations, 'property'));
    }

    public function testSettingAReadOnlyPropertyThatWasPreviouslyNullIsAViolation(): void
    {
        // The stored value is absent; the incoming payload sets it. On UPDATE
        // that is still a mutation of an immutable field.
        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['bsn' => 'now-set'],
            existingObject: ['notes' => 'original'],
            schema: $this->readOnlySchema()
        );

        $this->assertCount(1, $violations);
        $this->assertNull($violations[0]['stored']);
    }

    public function testNonArrayPropertyDefinitionIsIgnored(): void
    {
        // Defensive: a malformed schema must not fatal the write path.
        $schema = $this->schemaWith(['broken' => 'not-an-array']);

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['broken' => 'x'],
            existingObject: ['broken' => 'y'],
            schema: $schema
        );

        $this->assertSame([], $violations);
    }
}
