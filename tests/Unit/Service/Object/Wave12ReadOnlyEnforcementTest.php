<?php

/**
 * Wave-12 Fix 1 regression tests for JSON-Schema `readOnly: true` enforcement.
 *
 * Tests {@see ValidateObject::validateReadOnlyConstraints()} which closes the
 * silent-fiction gap documented at `/tmp/wave11-or-engine-primitives.md`
 * Section A: prior to Wave-12, `readOnly: true` was pure metadata, ignored on
 * every write path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject::validateReadOnlyConstraints
 */
class Wave12ReadOnlyEnforcementTest extends TestCase
{

    private IAppConfig&MockObject $config;

    private MagicMapper&MockObject $objectMapper;

    private SchemaMapper&MockObject $schemaMapper;

    private IURLGenerator&MockObject $urlGenerator;

    private LoggerInterface&MockObject $logger;

    private ValidateObject $validator;

    protected function setUp(): void
    {
        $this->config       = $this->createMock(IAppConfig::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->validator = new ValidateObject(
            $this->config,
            $this->objectMapper,
            $this->schemaMapper,
            $this->urlGenerator,
            $this->logger
        );
    }//end setUp()

    private function schemaWithProperties(array $properties): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setTitle('test');
        $schema->setProperties($properties);
        return $schema;
    }//end schemaWithProperties()

    public function testCreateWithReadOnlyFieldIsAllowed(): void
    {
        // CREATE path: no existing object → readOnly is not enforced.
        // The canonical use of readOnly is "server-stamped on create,
        // immutable afterwards" — the caller may set the initial value.
        $schema = $this->schemaWithProperties(
                [
                    'verifiedActorId' => ['type' => 'string', 'readOnly' => true],
                    'title'           => ['type' => 'string'],
                ]
                );

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['verifiedActorId' => 'actor-123', 'title' => 'hello'],
            existingObject: [],
            schema: $schema
        );

        $this->assertSame([], $violations);
    }//end testCreateWithReadOnlyFieldIsAllowed()

    public function testUpdateChangingReadOnlyFieldIsRejected(): void
    {
        // UPDATE path: changing a readOnly value rejects with violation list.
        $schema = $this->schemaWithProperties(
                [
                    'verifiedActorId' => ['type' => 'string', 'readOnly' => true],
                    'title'           => ['type' => 'string'],
                ]
                );

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['verifiedActorId' => 'attacker-uid', 'title' => 'unchanged'],
            existingObject: ['verifiedActorId' => 'original-actor', 'title' => 'unchanged'],
            schema: $schema
        );

        $this->assertCount(1, $violations);
        $this->assertSame('verifiedActorId', $violations[0]['property']);
        $this->assertSame('attacker-uid', $violations[0]['attempted']);
        $this->assertSame('original-actor', $violations[0]['stored']);
    }//end testUpdateChangingReadOnlyFieldIsRejected()

    public function testUpdateWithUnchangedReadOnlyValueIsAllowed(): void
    {
        // Sending the same value back must be a no-op success — common when
        // the client round-trips the full object.
        $schema = $this->schemaWithProperties(
                [
                    'verifiedActorId' => ['type' => 'string', 'readOnly' => true],
                ]
                );

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['verifiedActorId' => 'original-actor'],
            existingObject: ['verifiedActorId' => 'original-actor'],
            schema: $schema
        );

        $this->assertSame([], $violations);
    }//end testUpdateWithUnchangedReadOnlyValueIsAllowed()

    public function testUpdateOmittingReadOnlyFieldIsAllowed(): void
    {
        // PATCH-style updates that don't touch the readOnly field at all
        // must succeed.
        $schema = $this->schemaWithProperties(
                [
                    'verifiedActorId' => ['type' => 'string', 'readOnly' => true],
                    'title'           => ['type' => 'string'],
                ]
                );

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['title' => 'updated title'],
            existingObject: ['verifiedActorId' => 'original-actor', 'title' => 'old title'],
            schema: $schema
        );

        $this->assertSame([], $violations);
    }//end testUpdateOmittingReadOnlyFieldIsAllowed()

    public function testMultipleReadOnlyMutationsReportAllViolations(): void
    {
        $schema = $this->schemaWithProperties(
                [
                    'verifiedActorId' => ['type' => 'string', 'readOnly' => true],
                    'createdAt'       => ['type' => 'string', 'readOnly' => true],
                ]
                );

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['verifiedActorId' => 'X', 'createdAt' => '2099-01-01'],
            existingObject: ['verifiedActorId' => 'A', 'createdAt' => '2024-01-01'],
            schema: $schema
        );

        $this->assertCount(2, $violations);
        $properties = array_column($violations, 'property');
        $this->assertContains('verifiedActorId', $properties);
        $this->assertContains('createdAt', $properties);
    }//end testMultipleReadOnlyMutationsReportAllViolations()

    public function testNonReadOnlyFieldChangesAreIgnored(): void
    {
        $schema = $this->schemaWithProperties(
                [
                    'verifiedActorId' => ['type' => 'string', 'readOnly' => true],
                    'title'           => ['type' => 'string'],
                ]
                );

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['verifiedActorId' => 'same', 'title' => 'changed'],
            existingObject: ['verifiedActorId' => 'same', 'title' => 'original'],
            schema: $schema
        );

        $this->assertSame([], $violations);
    }//end testNonReadOnlyFieldChangesAreIgnored()

    public function testReadOnlyFalseIsNotEnforced(): void
    {
        // Explicit readOnly: false must NOT trigger enforcement.
        $schema = $this->schemaWithProperties(
                [
                    'someField' => ['type' => 'string', 'readOnly' => false],
                ]
                );

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['someField' => 'X'],
            existingObject: ['someField' => 'A'],
            schema: $schema
        );

        $this->assertSame([], $violations);
    }//end testReadOnlyFalseIsNotEnforced()

    public function testSchemaWithoutPropertiesReturnsNoViolations(): void
    {
        $schema = new Schema();
        $schema->setId(2);
        $schema->setTitle('empty');
        // No properties at all.
        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['anything' => 'goes'],
            existingObject: ['anything' => 'changed'],
            schema: $schema
        );

        $this->assertSame([], $violations);
    }//end testSchemaWithoutPropertiesReturnsNoViolations()

    public function testTypeCoercionIsTreatedAsMutation(): void
    {
        // Strict equality (===) compare: 42 (int) and "42" (string) are NOT
        // equivalent. Authors who want lax equality should use `default`
        // with `defaultBehavior: 'always'` instead.
        $schema = $this->schemaWithProperties(
                [
                    'count' => ['type' => 'integer', 'readOnly' => true],
                ]
                );

        $violations = $this->validator->validateReadOnlyConstraints(
            incomingObject: ['count' => '42'],
            existingObject: ['count' => 42],
            schema: $schema
        );

        $this->assertCount(1, $violations);
        $this->assertSame('count', $violations[0]['property']);
    }//end testTypeCoercionIsTreatedAsMutation()
}//end class
