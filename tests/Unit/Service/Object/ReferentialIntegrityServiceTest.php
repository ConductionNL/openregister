<?php

declare(strict_types=1);

/**
 * ReferentialIntegrityService Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ReferentialIntegrityService
 *
 * Tests referential integrity enforcement: canDelete analysis, applyDeletionActions,
 * logRestrictBlock, hasIncomingOnDeleteReferences, isValidOnDeleteAction,
 * and all internal helpers via reflection.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class ReferentialIntegrityServiceTest extends TestCase
{
    /** @var ReferentialIntegrityService */
    private ReferentialIntegrityService $service;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var MagicMapper&MockObject */
    private MagicMapper $objectMapper;

    /** @var AuditTrailMapper&MockObject */
    private AuditTrailMapper $auditTrailMapper;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->db = $this->createMock(IDBConnection::class);

        $this->service = new ReferentialIntegrityService(
            $this->schemaMapper,
            $this->registerMapper,
            $this->objectMapper,
            $this->auditTrailMapper,
            $this->logger,
            $this->db
        );
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Create an ObjectEntity with common fields set.
     *
     * @param string      $uuid     The UUID for the entity.
     * @param string|null $schema   The schema ID.
     * @param string|null $register The register ID.
     * @param array|null  $object   The object data.
     * @param array|null  $deleted  The deleted data (null = not deleted).
     *
     * @return ObjectEntity
     */
    private function createObjectEntity(
        string $uuid,
        ?string $schema = null,
        ?string $register = null,
        ?array $object = null,
        ?array $deleted = null
    ): ObjectEntity {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        if ($schema !== null) {
            $entity->setSchema($schema);
        }
        if ($register !== null) {
            $entity->setRegister($register);
        }
        if ($object !== null) {
            $entity->setObject($object);
        }
        if ($deleted !== null) {
            $entity->setDeleted($deleted);
        }
        return $entity;
    }

    /**
     * Create a Schema entity with ID, properties, and required fields.
     *
     * @param int         $id         The schema ID.
     * @param array       $properties The properties configuration.
     * @param array       $required   The required field names.
     * @param string|null $slug       The schema slug.
     * @param string|null $uuid       The schema UUID.
     *
     * @return Schema
     */
    private function createSchema(
        int $id,
        array $properties = [],
        array $required = [],
        ?string $slug = null,
        ?string $uuid = null
    ): Schema {
        $schema = new Schema();
        $this->setEntityId($schema, $id);
        $schema->setProperties($properties);
        $schema->setRequired($required);
        if ($slug !== null) {
            $schema->setSlug($slug);
        }
        if ($uuid !== null) {
            $schema->setUuid($uuid);
        }
        return $schema;
    }

    /**
     * Create a Register entity with an ID.
     *
     * @param int $id The register ID.
     *
     * @return Register
     */
    private function createRegister(int $id): Register
    {
        $register = new Register();
        $this->setEntityId($register, $id);
        return $register;
    }

    /**
     * Set the ID on an Entity via reflection (since ID is managed by the base class).
     *
     * @param object $entity The entity to set the ID on.
     * @param int    $id     The ID value to set.
     *
     * @return void
     */
    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new ReflectionClass($entity);
        // Walk up to the Entity base class to find the $id property.
        $class = $reflection;
        while ($class !== false) {
            if ($class->hasProperty('id')) {
                $prop = $class->getProperty('id');
                $prop->setAccessible(true);
                $prop->setValue($entity, $id);
                return;
            }
            $class = $class->getParentClass();
        }
    }

    /**
     * Invoke a private or protected method on the service.
     *
     * @param string $methodName The method name.
     * @param array  $args       The arguments to pass.
     *
     * @return mixed The return value.
     */
    private function invokeMethod(string $methodName, array $args = []): mixed
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->service, $args);
    }

    /**
     * Set a private property on the service.
     *
     * @param string $propertyName The property name.
     * @param mixed  $value        The value to set.
     *
     * @return void
     */
    private function setPrivateProperty(string $propertyName, mixed $value): void
    {
        $ref = new ReflectionClass($this->service);
        $prop = $ref->getProperty($propertyName);
        $prop->setAccessible(true);
        $prop->setValue($this->service, $value);
    }

    /**
     * Get a private property from the service.
     *
     * @param string $propertyName The property name.
     *
     * @return mixed The property value.
     */
    private function getPrivateProperty(string $propertyName): mixed
    {
        $ref = new ReflectionClass($this->service);
        $prop = $ref->getProperty($propertyName);
        $prop->setAccessible(true);
        return $prop->getValue($this->service);
    }

    /**
     * Set up the relation index, schema cache, and schema register map directly.
     *
     * This bypasses ensureRelationIndex() which requires database access.
     *
     * @param array      $relationIndex    The relation index.
     * @param array|null $schemaCache      The schema cache.
     * @param array|null $schemaRegisterMap The schema-register map.
     *
     * @return void
     */
    private function setRelationIndex(
        array $relationIndex,
        ?array $schemaCache = null,
        ?array $schemaRegisterMap = null
    ): void {
        $this->setPrivateProperty('relationIndex', $relationIndex);
        if ($schemaCache !== null) {
            $this->setPrivateProperty('schemaCache', $schemaCache);
        } else {
            $this->setPrivateProperty('schemaCache', []);
        }
        if ($schemaRegisterMap !== null) {
            $this->setPrivateProperty('schemaRegisterMap', $schemaRegisterMap);
        } else {
            $this->setPrivateProperty('schemaRegisterMap', []);
        }
    }

    // =========================================================================
    // isValidOnDeleteAction (static method)
    // =========================================================================

    public function testIsValidOnDeleteActionWithValidActions(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('CASCADE'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('RESTRICT'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('SET_NULL'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('SET_DEFAULT'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('NO_ACTION'));
    }

    public function testIsValidOnDeleteActionCaseInsensitive(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('cascade'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('restrict'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('set_null'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('Set_Default'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('no_action'));
    }

    public function testIsValidOnDeleteActionWithInvalidActions(): void
    {
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction(''));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('DELETE'));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('TRUNCATE'));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('SET NULL'));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('cascade_delete'));
    }

    // =========================================================================
    // VALID_ON_DELETE_ACTIONS constant
    // =========================================================================

    public function testValidOnDeleteActionsConstant(): void
    {
        $expected = ['CASCADE', 'RESTRICT', 'SET_NULL', 'SET_DEFAULT', 'NO_ACTION'];
        $this->assertSame($expected, ReferentialIntegrityService::VALID_ON_DELETE_ACTIONS);
    }

    // =========================================================================
    // extractOnDelete (private)
    // =========================================================================

    public function testExtractOnDeleteReturnsNullWhenNotSet(): void
    {
        $result = $this->invokeMethod('extractOnDelete', [['type' => 'string']]);
        $this->assertNull($result);
    }

    public function testExtractOnDeleteReturnsUppercaseValue(): void
    {
        $result = $this->invokeMethod('extractOnDelete', [['onDelete' => 'cascade']]);
        $this->assertSame('CASCADE', $result);
    }

    public function testExtractOnDeleteWithUppercaseValue(): void
    {
        $result = $this->invokeMethod('extractOnDelete', [['onDelete' => 'RESTRICT']]);
        $this->assertSame('RESTRICT', $result);
    }

    public function testExtractOnDeleteWithMixedCaseValue(): void
    {
        $result = $this->invokeMethod('extractOnDelete', [['onDelete' => 'Set_Null']]);
        $this->assertSame('SET_NULL', $result);
    }

    // =========================================================================
    // extractTargetRef (private)
    // =========================================================================

    public function testExtractTargetRefReturnsNullWhenNoRef(): void
    {
        $result = $this->invokeMethod('extractTargetRef', [['type' => 'string']]);
        $this->assertNull($result);
    }

    public function testExtractTargetRefReturnsDirectRef(): void
    {
        $result = $this->invokeMethod('extractTargetRef', [['$ref' => 'my-schema']]);
        $this->assertSame('my-schema', $result);
    }

    public function testExtractTargetRefReturnsArrayItemsRef(): void
    {
        $result = $this->invokeMethod('extractTargetRef', [
            ['type' => 'array', 'items' => ['$ref' => 'my-schema']],
        ]);
        $this->assertSame('my-schema', $result);
    }

    public function testExtractTargetRefPrefersDirectRefOverItems(): void
    {
        // When both $ref and items.$ref exist, direct $ref wins.
        $result = $this->invokeMethod('extractTargetRef', [
            ['$ref' => 'direct-schema', 'items' => ['$ref' => 'items-schema']],
        ]);
        $this->assertSame('direct-schema', $result);
    }

    public function testExtractTargetRefReturnsNullForEmptyItems(): void
    {
        $result = $this->invokeMethod('extractTargetRef', [
            ['type' => 'array', 'items' => ['type' => 'string']],
        ]);
        $this->assertNull($result);
    }

    // =========================================================================
    // resolveSchemaRef (private)
    // =========================================================================

    public function testResolveSchemaRefById(): void
    {
        $schema = $this->createSchema(42);
        $result = $this->invokeMethod('resolveSchemaRef', ['42', [$schema]]);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaRefBySlug(): void
    {
        $schema = $this->createSchema(42, [], [], 'my-schema');
        $result = $this->invokeMethod('resolveSchemaRef', ['my-schema', [$schema]]);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaRefByUuid(): void
    {
        $schema = $this->createSchema(42, [], [], null, 'abc-def-123');
        $result = $this->invokeMethod('resolveSchemaRef', ['abc-def-123', [$schema]]);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaRefByPathWithBasename(): void
    {
        // "/schemas/my-schema" -> basename is "my-schema".
        $schema = $this->createSchema(42, [], [], 'my-schema');
        $result = $this->invokeMethod('resolveSchemaRef', ['/schemas/my-schema', [$schema]]);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaRefReturnsNullWhenNotFound(): void
    {
        $schema = $this->createSchema(42, [], [], 'existing');
        $result = $this->invokeMethod('resolveSchemaRef', ['nonexistent', [$schema]]);
        $this->assertNull($result);
    }

    public function testResolveSchemaRefMatchesFirstSchema(): void
    {
        $schema1 = $this->createSchema(1, [], [], 'first');
        $schema2 = $this->createSchema(2, [], [], 'second');
        $result = $this->invokeMethod('resolveSchemaRef', ['first', [$schema1, $schema2]]);
        $this->assertSame('1', $result);
    }

    public function testResolveSchemaRefWithEmptySchemaList(): void
    {
        $result = $this->invokeMethod('resolveSchemaRef', ['anything', []]);
        $this->assertNull($result);
    }

    // =========================================================================
    // isRequiredProperty (private)
    // =========================================================================

    public function testIsRequiredPropertyReturnsTrueWhenRequired(): void
    {
        $schema = $this->createSchema(1, [], ['name', 'email']);
        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod('isRequiredProperty', ['1', 'name']);
        $this->assertTrue($result);
    }

    public function testIsRequiredPropertyReturnsFalseWhenNotRequired(): void
    {
        $schema = $this->createSchema(1, [], ['name']);
        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod('isRequiredProperty', ['1', 'description']);
        $this->assertFalse($result);
    }

    public function testIsRequiredPropertyReturnsFalseWhenSchemaNotCached(): void
    {
        $this->setPrivateProperty('schemaCache', []);

        $result = $this->invokeMethod('isRequiredProperty', ['999', 'name']);
        $this->assertFalse($result);
    }

    public function testIsRequiredPropertyReturnsFalseWithNullSchemaCache(): void
    {
        $this->setPrivateProperty('schemaCache', ['1' => null]);

        $result = $this->invokeMethod('isRequiredProperty', ['1', 'name']);
        $this->assertFalse($result);
    }

    // =========================================================================
    // getDefaultValue (private)
    // =========================================================================

    public function testGetDefaultValueReturnsDefault(): void
    {
        $schema = $this->createSchema(1, [
            'status' => ['type' => 'string', 'default' => 'active'],
        ]);
        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod('getDefaultValue', ['1', 'status']);
        $this->assertSame('active', $result);
    }

    public function testGetDefaultValueReturnsNullWhenNoDefault(): void
    {
        $schema = $this->createSchema(1, [
            'name' => ['type' => 'string'],
        ]);
        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod('getDefaultValue', ['1', 'name']);
        $this->assertNull($result);
    }

    public function testGetDefaultValueReturnsNullWhenPropertyMissing(): void
    {
        $schema = $this->createSchema(1, [
            'name' => ['type' => 'string'],
        ]);
        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod('getDefaultValue', ['1', 'nonexistent']);
        $this->assertNull($result);
    }

    public function testGetDefaultValueReturnsNullWhenSchemaNotCached(): void
    {
        $this->setPrivateProperty('schemaCache', []);

        $result = $this->invokeMethod('getDefaultValue', ['999', 'name']);
        $this->assertNull($result);
    }

    public function testGetDefaultValueReturnsNullWhenSchemaIsNull(): void
    {
        $this->setPrivateProperty('schemaCache', ['1' => null]);

        $result = $this->invokeMethod('getDefaultValue', ['1', 'name']);
        $this->assertNull($result);
    }

    public function testGetDefaultValueReturnsFalseDefault(): void
    {
        $schema = $this->createSchema(1, [
            'active' => ['type' => 'boolean', 'default' => false],
        ]);
        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod('getDefaultValue', ['1', 'active']);
        $this->assertFalse($result);
    }

    public function testGetDefaultValueReturnsZeroDefault(): void
    {
        $schema = $this->createSchema(1, [
            'count' => ['type' => 'integer', 'default' => 0],
        ]);
        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod('getDefaultValue', ['1', 'count']);
        $this->assertSame(0, $result);
    }

    // =========================================================================
    // hasIncomingOnDeleteReferences
    // =========================================================================

    public function testHasIncomingOnDeleteReferencesReturnsTrueWhenExists(): void
    {
        $this->setRelationIndex([
            '42' => [['sourceSchemaId' => '1', 'property' => 'ref', 'onDelete' => 'CASCADE', 'isArray' => false]],
        ]);

        $this->assertTrue($this->service->hasIncomingOnDeleteReferences('42'));
    }

    public function testHasIncomingOnDeleteReferencesReturnsFalseWhenNone(): void
    {
        $this->setRelationIndex([]);

        $this->assertFalse($this->service->hasIncomingOnDeleteReferences('42'));
    }

    public function testHasIncomingOnDeleteReferencesReturnsFalseForDifferentSchema(): void
    {
        $this->setRelationIndex([
            '10' => [['sourceSchemaId' => '1', 'property' => 'ref', 'onDelete' => 'CASCADE', 'isArray' => false]],
        ]);

        $this->assertFalse($this->service->hasIncomingOnDeleteReferences('42'));
    }

    // =========================================================================
    // canDelete — empty/simple cases
    // =========================================================================

    public function testCanDeleteReturnsEmptyWhenObjectHasNoSchema(): void
    {
        // Set up empty relation index to bypass ensureRelationIndex.
        $this->setRelationIndex([]);

        $object = $this->createObjectEntity('uuid-1', null);
        $result = $this->service->canDelete($object);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->cascadeTargets);
        $this->assertEmpty($result->nullifyTargets);
        $this->assertEmpty($result->defaultTargets);
        $this->assertEmpty($result->blockers);
    }

    public function testCanDeleteReturnsEmptyWhenNoRelationsExist(): void
    {
        $this->setRelationIndex([]);

        $object = $this->createObjectEntity('uuid-1', '42');
        $result = $this->service->canDelete($object);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->cascadeTargets);
    }

    public function testCanDeleteReturnsEmptyWhenSchemaNotInIndex(): void
    {
        $this->setRelationIndex([
            '99' => [['sourceSchemaId' => '1', 'property' => 'ref', 'onDelete' => 'CASCADE', 'isArray' => false]],
        ]);

        $object = $this->createObjectEntity('uuid-1', '42');
        $result = $this->service->canDelete($object);

        $this->assertTrue($result->deletable);
    }

    // =========================================================================
    // canDelete — RESTRICT
    // =========================================================================

    public function testCanDeleteDetectsRestrictBlocker(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        // The referencing object.
        $referencingObj = $this->createObjectEntity('ref-uuid', '1', '1', ['parentRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$referencingObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertFalse($result->deletable);
        $this->assertNotEmpty($result->blockers);
        $this->assertSame('RESTRICT', $result->blockers[0]['action']);
        $this->assertSame('ref-uuid', $result->blockers[0]['objectUuid']);
    }

    // =========================================================================
    // canDelete — CASCADE
    // =========================================================================

    public function testCanDeleteDetectsCascadeTarget(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'CASCADE'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'CASCADE',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $referencingObj = $this->createObjectEntity('ref-uuid', '1', '5', ['parentRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$referencingObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertNotEmpty($result->cascadeTargets);
        $this->assertSame('ref-uuid', $result->cascadeTargets[0]['objectUuid']);
    }

    // =========================================================================
    // canDelete — SET_NULL
    // =========================================================================

    public function testCanDeleteDetectsSetNullTargetForNonRequired(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'SET_NULL'],
        ], []);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'SET_NULL',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $referencingObj = $this->createObjectEntity('ref-uuid', '1', '1', ['parentRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$referencingObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertNotEmpty($result->nullifyTargets);
        $this->assertSame('ref-uuid', $result->nullifyTargets[0]['objectUuid']);
        $this->assertSame('parentRef', $result->nullifyTargets[0]['property']);
    }

    public function testCanDeleteSetNullFallsBackToRestrictWhenRequired(): void
    {
        // When SET_NULL targets a required property, it falls back to RESTRICT.
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'SET_NULL'],
        ], ['parentRef']);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'SET_NULL',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $referencingObj = $this->createObjectEntity('ref-uuid', '1', '1', ['parentRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$referencingObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertFalse($result->deletable);
        $this->assertNotEmpty($result->blockers);
        $this->assertSame('RESTRICT', $result->blockers[0]['action']);
        $this->assertEmpty($result->nullifyTargets);
    }

    // =========================================================================
    // canDelete — SET_DEFAULT
    // =========================================================================

    public function testCanDeleteDetectsSetDefaultTargetWithDefault(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'SET_DEFAULT', 'default' => 'default-uuid'],
        ], []);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'SET_DEFAULT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $referencingObj = $this->createObjectEntity('ref-uuid', '1', '1', ['parentRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$referencingObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertNotEmpty($result->defaultTargets);
        $this->assertSame('ref-uuid', $result->defaultTargets[0]['objectUuid']);
        $this->assertSame('default-uuid', $result->defaultTargets[0]['defaultValue']);
    }

    public function testCanDeleteSetDefaultFallsBackToSetNullWhenNoDefault(): void
    {
        // SET_DEFAULT without default falls back to SET_NULL (for non-required).
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'SET_DEFAULT'],
        ], []);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'SET_DEFAULT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $referencingObj = $this->createObjectEntity('ref-uuid', '1', '1', ['parentRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$referencingObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertNotEmpty($result->nullifyTargets);
        $this->assertEmpty($result->defaultTargets);
    }

    public function testCanDeleteSetDefaultFallsToRestrictWhenNoDefaultAndRequired(): void
    {
        // SET_DEFAULT without default on required property -> RESTRICT.
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'SET_DEFAULT'],
        ], ['parentRef']);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'SET_DEFAULT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $referencingObj = $this->createObjectEntity('ref-uuid', '1', '1', ['parentRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$referencingObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertFalse($result->deletable);
        $this->assertNotEmpty($result->blockers);
        $this->assertSame('RESTRICT', $result->blockers[0]['action']);
    }

    // =========================================================================
    // canDelete — soft-deleted objects skipped
    // =========================================================================

    public function testCanDeleteSkipsSoftDeletedObjects(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        // Soft-deleted referencing object should be skipped.
        $referencingObj = $this->createObjectEntity(
            'ref-uuid',
            '1',
            '1',
            ['parentRef' => 'target-uuid'],
            ['deletedAt' => '2024-01-01']
        );

        $this->objectMapper->method('findByRelation')
            ->willReturn([$referencingObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->blockers);
    }

    // =========================================================================
    // canDelete — cycle detection
    // =========================================================================

    public function testCanDeleteHandlesCycleDetection(): void
    {
        // Schema 1 references schema 2 with CASCADE, schema 2 references schema 1 with CASCADE.
        // This would create an infinite loop without cycle detection.
        $schema1 = $this->createSchema(1, [
            'refTo2' => ['$ref' => '2', 'onDelete' => 'CASCADE'],
        ]);
        $schema2 = $this->createSchema(2, [
            'refTo1' => ['$ref' => '1', 'onDelete' => 'CASCADE'],
        ]);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'refTo2',
                    'onDelete'       => 'CASCADE',
                    'isArray'        => false,
                ]],
                '1' => [[
                    'sourceSchemaId' => '2',
                    'property'       => 'refTo1',
                    'onDelete'       => 'CASCADE',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $obj1 = $this->createObjectEntity('uuid-1', '1', '1', ['refTo2' => 'uuid-2']);
        $obj2 = $this->createObjectEntity('uuid-2', '2', '1', ['refTo1' => 'uuid-1']);

        // findByRelation returns objects that reference the target.
        // For schema 2 (target uuid-1): obj2 references uuid-1 via refTo1.
        // For schema 1 (target uuid-2): obj1 references uuid-2 via refTo2.
        $this->objectMapper->method('findByRelation')
            ->willReturnCallback(function (string $search) use ($obj1, $obj2) {
                if ($search === 'uuid-1') {
                    return [$obj2];
                }
                if ($search === 'uuid-2') {
                    return [$obj1];
                }
                return [];
            });

        // Delete obj1 -> cascades to obj2 -> tries to cascade to obj1, but visited.
        $result = $this->service->canDelete($obj1);

        // Should complete without infinite loop.
        $this->assertTrue($result->deletable);
    }

    // =========================================================================
    // canDelete — array references
    // =========================================================================

    public function testCanDeleteWithArrayReference(): void
    {
        $schema1 = $this->createSchema(1, [
            'tags' => ['type' => 'array', 'items' => ['$ref' => '2'], 'onDelete' => 'SET_NULL'],
        ], []);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'tags',
                    'onDelete'       => 'SET_NULL',
                    'isArray'        => true,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $referencingObj = $this->createObjectEntity(
            'ref-uuid',
            '1',
            '1',
            ['tags' => ['target-uuid', 'other-uuid']]
        );

        $this->objectMapper->method('findByRelation')
            ->willReturn([$referencingObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertNotEmpty($result->nullifyTargets);
        $this->assertTrue($result->nullifyTargets[0]['isArray']);
    }

    // =========================================================================
    // canDelete — findByRelation filters by schema and property
    // =========================================================================

    public function testCanDeleteFiltersObjectsBySchemaInFallbackPath(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        // Object with wrong schema should be filtered out.
        $wrongSchemaObj = $this->createObjectEntity('wrong-uuid', '99', '1', ['parentRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$wrongSchemaObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->blockers);
    }

    public function testCanDeleteFiltersObjectsByPropertyValueInFallbackPath(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        // Object with wrong property value should be filtered out.
        $wrongValueObj = $this->createObjectEntity('ref-uuid', '1', '1', ['parentRef' => 'different-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$wrongValueObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->blockers);
    }

    public function testCanDeleteFiltersObjectsWithNullObjectData(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        // Object with null object data should be filtered out.
        $nullDataObj = $this->createObjectEntity('ref-uuid', '1', '1');

        $this->objectMapper->method('findByRelation')
            ->willReturn([$nullDataObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
    }

    public function testCanDeleteArrayRefFiltersMismatchedArrayValue(): void
    {
        $schema1 = $this->createSchema(1, [
            'tags' => ['type' => 'array', 'items' => ['$ref' => '2'], 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'tags',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => true,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        // Array doesn't contain the target UUID.
        $noMatchObj = $this->createObjectEntity('ref-uuid', '1', '1', ['tags' => ['other-uuid']]);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$noMatchObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->blockers);
    }

    // =========================================================================
    // canDelete — findByRelation exception handling
    // =========================================================================

    public function testCanDeleteHandlesFindByRelationException(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $this->objectMapper->method('findByRelation')
            ->willThrowException(new \Exception('DB error'));

        $this->logger->expects($this->once())
            ->method('warning');

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
    }

    // =========================================================================
    // canDelete — multiple dependents
    // =========================================================================

    public function testCanDeleteWithMultipleDependents(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '3', 'onDelete' => 'CASCADE'],
        ], []);
        $schema2 = $this->createSchema(2, [
            'targetRef' => ['$ref' => '3', 'onDelete' => 'SET_NULL'],
        ], []);
        $schema3 = $this->createSchema(3);

        $this->setRelationIndex(
            [
                '3' => [
                    [
                        'sourceSchemaId' => '1',
                        'property'       => 'parentRef',
                        'onDelete'       => 'CASCADE',
                        'isArray'        => false,
                    ],
                    [
                        'sourceSchemaId' => '2',
                        'property'       => 'targetRef',
                        'onDelete'       => 'SET_NULL',
                        'isArray'        => false,
                    ],
                ],
            ],
            ['1' => $schema1, '2' => $schema2, '3' => $schema3]
        );

        $cascadeObj = $this->createObjectEntity('cascade-uuid', '1', '1', ['parentRef' => 'target-uuid']);
        $nullifyObj = $this->createObjectEntity('nullify-uuid', '2', '1', ['targetRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$cascadeObj, $nullifyObj]);

        $targetObj = $this->createObjectEntity('target-uuid', '3');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertCount(1, $result->cascadeTargets);
        $this->assertCount(1, $result->nullifyTargets);
    }

    // =========================================================================
    // canDelete — no referencing objects found
    // =========================================================================

    public function testCanDeleteWithNoReferencingObjects(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $this->objectMapper->method('findByRelation')
            ->willReturn([]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->blockers);
    }

    // =========================================================================
    // walkDeletionGraph — depth limit
    // =========================================================================

    public function testWalkDeletionGraphRespectsMaxDepth(): void
    {
        // Test depth limit directly via reflection since cycle detection
        // normally prevents reaching MAX_DEPTH with unique objects.
        $schema = $this->createSchema(1, [
            'selfRef' => ['$ref' => '1', 'onDelete' => 'CASCADE'],
        ]);

        $this->setRelationIndex(
            [
                '1' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'selfRef',
                    'onDelete'       => 'CASCADE',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema]
        );

        // Invoke walkDeletionGraph directly at depth=10 (MAX_DEPTH).
        $obj = $this->createObjectEntity('deep-uuid', '1', '1', ['selfRef' => 'x']);
        $visited = [];

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Max depth reached'),
                $this->anything()
            );

        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('walkDeletionGraph');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->service, [
            $obj,
            &$visited,
            [],
            10,
        ]);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->cascadeTargets);
    }

    // =========================================================================
    // logRestrictBlock
    // =========================================================================

    public function testLogRestrictBlockLogsBlockers(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: false,
            blockers: [
                ['objectUuid' => 'blocker-1', 'schema' => '1', 'property' => 'ref1', 'action' => 'RESTRICT'],
                ['objectUuid' => 'blocker-2', 'schema' => '2', 'property' => 'ref2', 'action' => 'RESTRICT'],
            ]
        );

        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (AuditTrail $trail) {
                return $trail->getAction() === 'referential_integrity.restrict_blocked'
                    && $trail->getObjectUuid() === 'obj-uuid';
            }));

        $this->service->logRestrictBlock('obj-uuid', '5', $analysis, 'admin');
    }

    public function testLogRestrictBlockHandlesEmptyBlockers(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: false,
            blockers: []
        );

        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (AuditTrail $trail) {
                $changed = $trail->getChanged();
                return $changed['blockerCount'] === 0
                    && $changed['blockerSchema'] === 'unknown'
                    && $changed['blockerProperty'] === 'unknown';
            }));

        $this->service->logRestrictBlock('obj-uuid', '5', $analysis, 'admin');
    }

    public function testLogRestrictBlockDeduplicatesSchemas(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: false,
            blockers: [
                ['objectUuid' => 'b1', 'schema' => '1', 'property' => 'ref', 'action' => 'RESTRICT'],
                ['objectUuid' => 'b2', 'schema' => '1', 'property' => 'ref', 'action' => 'RESTRICT'],
            ]
        );

        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (AuditTrail $trail) {
                $changed = $trail->getChanged();
                return $changed['blockerCount'] === 2
                    && $changed['blockerSchema'] === '1';
            }));

        $this->service->logRestrictBlock('obj-uuid', '5', $analysis, 'admin');
    }

    public function testLogRestrictBlockWithNullSchemaId(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: false,
            blockers: [
                ['objectUuid' => 'b1', 'schema' => '1', 'property' => 'ref', 'action' => 'RESTRICT'],
            ]
        );

        $this->auditTrailMapper->expects($this->once())
            ->method('insert');

        // Should not throw when schemaId is null.
        $this->service->logRestrictBlock('obj-uuid', null, $analysis, 'admin');
    }

    public function testLogRestrictBlockHandlesBlockersWithMissingKeys(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: false,
            blockers: [
                ['objectUuid' => 'b1'],
            ]
        );

        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (AuditTrail $trail) {
                $changed = $trail->getChanged();
                return $changed['blockerSchema'] === 'unknown'
                    && $changed['blockerProperty'] === 'unknown';
            }));

        $this->service->logRestrictBlock('obj-uuid', '5', $analysis, 'admin');
    }

    // =========================================================================
    // logIntegrityAction (private) — error handling
    // =========================================================================

    public function testLogIntegrityActionHandlesInsertException(): void
    {
        $this->auditTrailMapper->method('insert')
            ->willThrowException(new \Exception('DB insert failed'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Call logRestrictBlock which internally calls logIntegrityAction.
        $analysis = new DeletionAnalysis(
            deletable: false,
            blockers: [
                ['objectUuid' => 'b1', 'schema' => '1', 'property' => 'ref', 'action' => 'RESTRICT'],
            ]
        );

        // Should not throw.
        $this->service->logRestrictBlock('obj-uuid', '5', $analysis, 'admin');
    }

    // =========================================================================
    // applyDeletionActions — SET_NULL targets
    // =========================================================================

    public function testApplyDeletionActionsAppliesSetNull(): void
    {
        $object = $this->createObjectEntity('dep-uuid', '1', '1', ['parentRef' => 'source-uuid']);
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $this->objectMapper->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (ObjectEntity $entity) {
                    $data = $entity->getObject();
                    return $data['parentRef'] === null;
                }),
                $this->isInstanceOf(Register::class),
                $this->isInstanceOf(Schema::class)
            );

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'dep-uuid',
                'schema'     => '1',
                'property'   => 'parentRef',
                'isArray'    => false,
                'sourceUuid' => 'source-uuid',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    public function testApplyDeletionActionsAppliesSetNullForArray(): void
    {
        $object = $this->createObjectEntity(
            'dep-uuid',
            '1',
            '1',
            ['tags' => ['source-uuid', 'keep-uuid', 'source-uuid']]
        );
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $this->objectMapper->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (ObjectEntity $entity) {
                    $data = $entity->getObject();
                    // source-uuid should be removed, keep-uuid should remain.
                    return $data['tags'] === ['keep-uuid'];
                }),
                $this->isInstanceOf(Register::class),
                $this->isInstanceOf(Schema::class)
            );

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'dep-uuid',
                'schema'     => '1',
                'property'   => 'tags',
                'isArray'    => true,
                'sourceUuid' => 'source-uuid',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    public function testApplyDeletionActionsSetNullHandlesException(): void
    {
        $this->objectMapper->method('findAcrossAllSources')
            ->willThrowException(new \Exception('Not found'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'dep-uuid',
                'schema'     => '1',
                'property'   => 'parentRef',
                'isArray'    => false,
                'sourceUuid' => 'source-uuid',
            ]]
        );

        // Should not throw.
        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    // =========================================================================
    // applyDeletionActions — SET_DEFAULT targets
    // =========================================================================

    public function testApplyDeletionActionsAppliesSetDefault(): void
    {
        $object = $this->createObjectEntity('dep-uuid', '1', '1', ['parentRef' => 'source-uuid']);
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $this->objectMapper->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (ObjectEntity $entity) {
                    $data = $entity->getObject();
                    return $data['parentRef'] === 'default-uuid';
                }),
                $this->isInstanceOf(Register::class),
                $this->isInstanceOf(Schema::class)
            );

        $analysis = new DeletionAnalysis(
            deletable: true,
            defaultTargets: [[
                'objectUuid'   => 'dep-uuid',
                'schema'       => '1',
                'property'     => 'parentRef',
                'defaultValue' => 'default-uuid',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    public function testApplyDeletionActionsSetDefaultHandlesException(): void
    {
        $this->objectMapper->method('findAcrossAllSources')
            ->willThrowException(new \Exception('Not found'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $analysis = new DeletionAnalysis(
            deletable: true,
            defaultTargets: [[
                'objectUuid'   => 'dep-uuid',
                'schema'       => '1',
                'property'     => 'parentRef',
                'defaultValue' => 'default-uuid',
            ]]
        );

        // Should not throw.
        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    // =========================================================================
    // applyDeletionActions — CASCADE targets
    // =========================================================================

    public function testApplyDeletionActionsAppliesCascade(): void
    {
        $register = $this->createRegister(5);
        $schema = $this->createSchema(10);

        $this->registerMapper->method('find')
            ->willReturn($register);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $this->objectMapper->expects($this->once())
            ->method('deleteObjects')
            ->with(
                $this->equalTo(['cascade-uuid']),
                $this->equalTo(false)
            );

        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [[
                'objectUuid' => 'cascade-uuid',
                'register'   => '5',
                'schema'     => '10',
                'property'   => 'parentRef',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid', null, 'trigger-slug');
    }

    public function testApplyDeletionActionsGroupsCascadeByRegisterSchema(): void
    {
        $register = $this->createRegister(5);
        $schema = $this->createSchema(10);

        $this->registerMapper->method('find')
            ->willReturn($register);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        // Two targets in the same register+schema group should be batched.
        $this->objectMapper->expects($this->once())
            ->method('deleteObjects')
            ->with(
                // Reversed order because applyDeletionActions reverses cascadeTargets.
                $this->equalTo(['cascade-uuid-2', 'cascade-uuid-1']),
                $this->equalTo(false)
            );

        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [
                [
                    'objectUuid' => 'cascade-uuid-1',
                    'register'   => '5',
                    'schema'     => '10',
                    'property'   => 'ref1',
                ],
                [
                    'objectUuid' => 'cascade-uuid-2',
                    'register'   => '5',
                    'schema'     => '10',
                    'property'   => 'ref2',
                ],
            ]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    public function testApplyDeletionActionsCascadeFallbackForNoRegister(): void
    {
        // Target without register info should be handled in its own group.
        $this->objectMapper->expects($this->once())
            ->method('deleteObjects')
            ->with(
                $this->equalTo(['cascade-uuid']),
                $this->equalTo(false)
            );

        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [[
                'objectUuid' => 'cascade-uuid',
                'register'   => null,
                'schema'     => null,
                'property'   => 'parentRef',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    public function testApplyDeletionActionsCascadeHandlesDeleteException(): void
    {
        $this->objectMapper->method('deleteObjects')
            ->willThrowException(new \Exception('Batch delete failed'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [[
                'objectUuid' => 'cascade-uuid',
                'register'   => '99',
                'schema'     => '99',
                'property'   => 'ref',
            ]]
        );

        // Should not throw.
        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    // =========================================================================
    // applyDeletionActions — audit trail logging for each action
    // =========================================================================

    public function testApplyDeletionActionsLogsSetNullAuditTrail(): void
    {
        $object = $this->createObjectEntity('dep-uuid', '1', '1', ['parentRef' => 'source-uuid']);
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        // Expect audit trail insert for set_null action.
        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (AuditTrail $trail) {
                return $trail->getAction() === 'referential_integrity.set_null'
                    && $trail->getObjectUuid() === 'dep-uuid';
            }));

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'dep-uuid',
                'schema'     => '1',
                'property'   => 'parentRef',
                'isArray'    => false,
                'sourceUuid' => 'source-uuid',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid', null, 'test-slug');
    }

    public function testApplyDeletionActionsLogsSetDefaultAuditTrail(): void
    {
        $object = $this->createObjectEntity('dep-uuid', '1', '1', ['parentRef' => 'source-uuid']);
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (AuditTrail $trail) {
                return $trail->getAction() === 'referential_integrity.set_default';
            }));

        $analysis = new DeletionAnalysis(
            deletable: true,
            defaultTargets: [[
                'objectUuid'   => 'dep-uuid',
                'schema'       => '1',
                'property'     => 'parentRef',
                'defaultValue' => 'default-uuid',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    public function testApplyDeletionActionsLogsCascadeDeleteAuditTrail(): void
    {
        $register = $this->createRegister(5);
        $schema = $this->createSchema(10);

        $this->registerMapper->method('find')
            ->willReturn($register);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        // Expect one audit trail per cascade target.
        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (AuditTrail $trail) {
                return $trail->getAction() === 'referential_integrity.cascade_delete'
                    && $trail->getObjectUuid() === 'cascade-uuid';
            }));

        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [[
                'objectUuid' => 'cascade-uuid',
                'register'   => '5',
                'schema'     => '10',
                'property'   => 'parentRef',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid', null, 'test-slug');
    }

    // =========================================================================
    // applyDeletionActions — empty analysis
    // =========================================================================

    public function testApplyDeletionActionsWithEmptyAnalysis(): void
    {
        $analysis = DeletionAnalysis::empty();

        // Should not call any mapper methods.
        $this->objectMapper->expects($this->never())
            ->method('findAcrossAllSources');
        $this->objectMapper->expects($this->never())
            ->method('update');
        $this->objectMapper->expects($this->never())
            ->method('deleteObjects');

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    // =========================================================================
    // applyDeletionActions — execution order
    // =========================================================================

    public function testApplyDeletionActionsExecutesInOrder(): void
    {
        // SET_NULL first, then SET_DEFAULT, then CASCADE.
        $object = $this->createObjectEntity('dep-uuid', '1', '1', ['ref1' => 'src', 'ref2' => 'src']);
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $cascadeRegister = $this->createRegister(5);
        $cascadeSchema = $this->createSchema(10);

        $this->registerMapper->method('find')
            ->willReturn($cascadeRegister);
        $this->schemaMapper->method('find')
            ->willReturn($cascadeSchema);

        $callOrder = [];

        $this->objectMapper->method('update')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'update';
                return new ObjectEntity();
            });

        $this->objectMapper->method('deleteObjects')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'delete';
                return [];
            });

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'null-uuid',
                'schema'     => '1',
                'property'   => 'ref1',
                'isArray'    => false,
                'sourceUuid' => 'src',
            ]],
            defaultTargets: [[
                'objectUuid'   => 'def-uuid',
                'schema'       => '1',
                'property'     => 'ref2',
                'defaultValue' => 'def-val',
            ]],
            cascadeTargets: [[
                'objectUuid' => 'casc-uuid',
                'register'   => '5',
                'schema'     => '10',
                'property'   => 'ref3',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');

        // update called for SET_NULL, then for SET_DEFAULT, then deleteObjects for CASCADE.
        $this->assertSame(['update', 'update', 'delete'], $callOrder);
    }

    // =========================================================================
    // applyDeletionActions — SET_NULL on non-array property when isArray is missing
    // =========================================================================

    public function testApplySetNullDefaultsIsArrayToFalse(): void
    {
        $object = $this->createObjectEntity('dep-uuid', '1', '1', ['ref' => 'src-uuid']);
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $this->objectMapper->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (ObjectEntity $entity) {
                    return $entity->getObject()['ref'] === null;
                }),
                $this->anything(),
                $this->anything()
            );

        // Target without 'isArray' key.
        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'dep-uuid',
                'schema'     => '1',
                'property'   => 'ref',
                'sourceUuid' => 'src-uuid',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    // =========================================================================
    // applyDeletionActions — mixed register/schema groups for cascade
    // =========================================================================

    public function testApplyDeletionActionsCascadeSeparatesGroups(): void
    {
        $register1 = $this->createRegister(1);
        $register2 = $this->createRegister(2);
        $schema1 = $this->createSchema(10);
        $schema2 = $this->createSchema(20);

        $this->registerMapper->method('find')
            ->willReturnCallback(function ($id) use ($register1, $register2) {
                return $id === '1' || $id === 1 ? $register1 : $register2;
            });

        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($schema1, $schema2) {
                return $id === '10' || $id === 10 ? $schema1 : $schema2;
            });

        // Two different groups should produce two deleteObjects calls.
        $this->objectMapper->expects($this->exactly(2))
            ->method('deleteObjects');

        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [
                [
                    'objectUuid' => 'uuid-a',
                    'register'   => '1',
                    'schema'     => '10',
                    'property'   => 'ref',
                ],
                [
                    'objectUuid' => 'uuid-b',
                    'register'   => '2',
                    'schema'     => '20',
                    'property'   => 'ref',
                ],
            ]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    // =========================================================================
    // applyDeletionActions — organisation and triggerSchemaSlug params
    // =========================================================================

    public function testApplyDeletionActionsPassesTriggerSchemaSlug(): void
    {
        $object = $this->createObjectEntity('dep-uuid', '1', '1', ['ref' => 'src']);
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (AuditTrail $trail) {
                $changed = $trail->getChanged();
                return ($changed['triggerSchema'] ?? null) === 'my-schema-slug';
            }));

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'dep-uuid',
                'schema'     => '1',
                'property'   => 'ref',
                'isArray'    => false,
                'sourceUuid' => 'src',
            ]]
        );

        $this->service->applyDeletionActions(
            $analysis,
            'admin',
            'source-uuid',
            'org-1',
            'my-schema-slug'
        );
    }

    // =========================================================================
    // ensureRelationIndex — schema loading exception
    // =========================================================================

    public function testEnsureRelationIndexHandlesSchemaLoadException(): void
    {
        $this->schemaMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $this->logger->expects($this->once())
            ->method('warning');

        // Call a public method that triggers ensureRelationIndex.
        $result = $this->service->hasIncomingOnDeleteReferences('42');

        $this->assertFalse($result);

        // Relation index should be set (empty) so it doesn't retry.
        $idx = $this->getPrivateProperty('relationIndex');
        $this->assertSame([], $idx);
    }

    // =========================================================================
    // ensureRelationIndex — skips NO_ACTION and properties without onDelete
    // =========================================================================

    public function testEnsureRelationIndexSkipsNoActionAndNoOnDelete(): void
    {
        $schema1 = $this->createSchema(1, [
            'noAction' => ['$ref' => '2', 'onDelete' => 'NO_ACTION'],
            'noConfig' => ['$ref' => '2'],
            'cascade'  => ['$ref' => '2', 'onDelete' => 'CASCADE'],
        ], [], 'schema-1');

        $schema2 = $this->createSchema(2, [], [], 'schema-2');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema1, $schema2]);

        $this->registerMapper->method('findAll')
            ->willReturn([]);

        // Trigger ensureRelationIndex.
        $result = $this->service->hasIncomingOnDeleteReferences('2');
        $this->assertTrue($result);

        // Should only have the CASCADE relation, not NO_ACTION or the one without onDelete.
        $idx = $this->getPrivateProperty('relationIndex');
        $this->assertCount(1, $idx['2']);
        $this->assertSame('CASCADE', $idx['2'][0]['onDelete']);
    }

    // =========================================================================
    // ensureRelationIndex — skips properties without $ref
    // =========================================================================

    public function testEnsureRelationIndexSkipsPropertiesWithoutRef(): void
    {
        $schema1 = $this->createSchema(1, [
            'noRef' => ['type' => 'string', 'onDelete' => 'CASCADE'],
        ], [], 'schema-1');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema1]);

        $this->registerMapper->method('findAll')
            ->willReturn([]);

        $this->service->hasIncomingOnDeleteReferences('1');

        $idx = $this->getPrivateProperty('relationIndex');
        $this->assertEmpty($idx);
    }

    // =========================================================================
    // ensureRelationIndex — skips unresolvable $ref
    // =========================================================================

    public function testEnsureRelationIndexSkipsUnresolvableRef(): void
    {
        $schema1 = $this->createSchema(1, [
            'badRef' => ['$ref' => 'nonexistent-schema', 'onDelete' => 'CASCADE'],
        ], [], 'schema-1');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema1]);

        $this->registerMapper->method('findAll')
            ->willReturn([]);

        $this->service->hasIncomingOnDeleteReferences('1');

        $idx = $this->getPrivateProperty('relationIndex');
        $this->assertEmpty($idx);
    }

    // =========================================================================
    // ensureRelationIndex — handles schemas with null properties
    // =========================================================================

    public function testEnsureRelationIndexHandlesNullProperties(): void
    {
        // Schema with no properties set should be skipped.
        $schema = new Schema();
        $this->setEntityId($schema, 1);

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $this->registerMapper->method('findAll')
            ->willReturn([]);

        $this->service->hasIncomingOnDeleteReferences('1');

        $idx = $this->getPrivateProperty('relationIndex');
        $this->assertEmpty($idx);
    }

    // =========================================================================
    // ensureRelationIndex — caching (only builds once)
    // =========================================================================

    public function testEnsureRelationIndexOnlyBuildsOnce(): void
    {
        $schema = $this->createSchema(1, [
            'ref' => ['$ref' => '2', 'onDelete' => 'CASCADE'],
        ], [], 'schema-1');
        $schema2 = $this->createSchema(2, [], [], 'schema-2');

        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([$schema, $schema2]);

        $this->registerMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        // Call twice — should only load schemas once.
        $this->service->hasIncomingOnDeleteReferences('2');
        $this->service->hasIncomingOnDeleteReferences('2');
    }

    // =========================================================================
    // ensureRelationIndex — array type detection
    // =========================================================================

    public function testEnsureRelationIndexDetectsArrayType(): void
    {
        $schema1 = $this->createSchema(1, [
            'tags' => [
                'type' => 'array',
                'items' => ['$ref' => '2'],
                'onDelete' => 'CASCADE',
            ],
        ], [], 'schema-1');

        $schema2 = $this->createSchema(2, [], [], 'schema-2');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema1, $schema2]);

        $this->registerMapper->method('findAll')
            ->willReturn([]);

        $this->service->hasIncomingOnDeleteReferences('2');

        $idx = $this->getPrivateProperty('relationIndex');
        $this->assertTrue($idx['2'][0]['isArray']);
    }

    public function testEnsureRelationIndexDetectsNonArrayType(): void
    {
        $schema1 = $this->createSchema(1, [
            'parent' => [
                '$ref' => '2',
                'onDelete' => 'CASCADE',
            ],
        ], [], 'schema-1');

        $schema2 = $this->createSchema(2, [], [], 'schema-2');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema1, $schema2]);

        $this->registerMapper->method('findAll')
            ->willReturn([]);

        $this->service->hasIncomingOnDeleteReferences('2');

        $idx = $this->getPrivateProperty('relationIndex');
        $this->assertFalse($idx['2'][0]['isArray']);
    }

    // =========================================================================
    // ensureRelationIndex — register map exception handling
    // =========================================================================

    public function testEnsureRelationIndexHandlesRegisterLoadException(): void
    {
        $schema = $this->createSchema(1, [
            'ref' => ['$ref' => '2', 'onDelete' => 'CASCADE'],
        ], [], 'schema-1');
        $schema2 = $this->createSchema(2, [], [], 'schema-2');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema, $schema2]);

        $this->registerMapper->method('findAll')
            ->willThrowException(new \Exception('Register load failed'));

        // Should still build the relation index from schemas, just without register map.
        $this->logger->expects($this->once())
            ->method('debug');

        $this->service->hasIncomingOnDeleteReferences('2');

        $idx = $this->getPrivateProperty('relationIndex');
        $this->assertNotEmpty($idx['2']);
    }

    // =========================================================================
    // resolveSchemaRef — by cleaned path basename
    // =========================================================================

    public function testResolveSchemaRefByIdAfterBasenameClean(): void
    {
        $schema = $this->createSchema(42);
        // "/some/path/42" -> basename is "42", matches ID "42".
        $result = $this->invokeMethod('resolveSchemaRef', ['/some/path/42', [$schema]]);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaRefByUuidAfterBasenameClean(): void
    {
        $schema = $this->createSchema(1, [], [], null, 'abc-123');
        $result = $this->invokeMethod('resolveSchemaRef', ['/schemas/abc-123', [$schema]]);
        $this->assertSame('1', $result);
    }

    // =========================================================================
    // canDelete — property missing from object data
    // =========================================================================

    public function testCanDeleteHandlesPropertyMissingFromObjectData(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        // Object has different properties, not the expected one.
        $obj = $this->createObjectEntity('ref-uuid', '1', '1', ['otherProp' => 'value']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$obj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->blockers);
    }

    // =========================================================================
    // canDelete — array type with non-array property value
    // =========================================================================

    public function testCanDeleteArrayTypeWithNonArrayValue(): void
    {
        $schema1 = $this->createSchema(1, [
            'tags' => ['type' => 'array', 'items' => ['$ref' => '2'], 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'tags',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => true,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        // tags is a string, not an array.
        $obj = $this->createObjectEntity('ref-uuid', '1', '1', ['tags' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$obj]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->blockers);
    }

    // =========================================================================
    // SET_NULL on array — empty after removal
    // =========================================================================

    public function testApplySetNullArrayRemovesAllMatchingEntries(): void
    {
        $object = $this->createObjectEntity(
            'dep-uuid',
            '1',
            '1',
            ['tags' => ['source-uuid']]
        );
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $this->objectMapper->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (ObjectEntity $entity) {
                    $data = $entity->getObject();
                    return $data['tags'] === [];
                }),
                $this->anything(),
                $this->anything()
            );

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'dep-uuid',
                'schema'     => '1',
                'property'   => 'tags',
                'isArray'    => true,
                'sourceUuid' => 'source-uuid',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    // =========================================================================
    // SET_NULL — non-array property not in object data
    // =========================================================================

    public function testApplySetNullNonArrayWhenPropertyNotInData(): void
    {
        $object = $this->createObjectEntity('dep-uuid', '1', '1', ['other' => 'val']);
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $this->objectMapper->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (ObjectEntity $entity) {
                    $data = $entity->getObject();
                    // Property should be set to null even if it didn't exist.
                    return array_key_exists('ref', $data) && $data['ref'] === null;
                }),
                $this->anything(),
                $this->anything()
            );

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'dep-uuid',
                'schema'     => '1',
                'property'   => 'ref',
                'isArray'    => false,
                'sourceUuid' => 'src',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }

    // =========================================================================
    // Multiple blockers from same schema
    // =========================================================================

    public function testCanDeleteCollectsMultipleBlockersFromSameSchema(): void
    {
        $schema1 = $this->createSchema(1, [
            'parentRef' => ['$ref' => '2', 'onDelete' => 'RESTRICT'],
        ]);
        $schema2 = $this->createSchema(2);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'parentRef',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2]
        );

        $obj1 = $this->createObjectEntity('ref-1', '1', '1', ['parentRef' => 'target-uuid']);
        $obj2 = $this->createObjectEntity('ref-2', '1', '1', ['parentRef' => 'target-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturn([$obj1, $obj2]);

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertFalse($result->deletable);
        $this->assertCount(2, $result->blockers);
    }

    // =========================================================================
    // canDelete — CASCADE with nested RESTRICT
    // =========================================================================

    public function testCanDeleteCascadeWithNestedRestrict(): void
    {
        // Schema 1 -> CASCADE on schema 2.
        // Schema 3 -> RESTRICT on schema 1.
        // Deleting schema 2 cascades to schema 1, which is restricted by schema 3.
        $schema1 = $this->createSchema(1, [
            'refTo2' => ['$ref' => '2', 'onDelete' => 'CASCADE'],
        ]);
        $schema2 = $this->createSchema(2);
        $schema3 = $this->createSchema(3, [
            'refTo1' => ['$ref' => '1', 'onDelete' => 'RESTRICT'],
        ]);

        $this->setRelationIndex(
            [
                '2' => [[
                    'sourceSchemaId' => '1',
                    'property'       => 'refTo2',
                    'onDelete'       => 'CASCADE',
                    'isArray'        => false,
                ]],
                '1' => [[
                    'sourceSchemaId' => '3',
                    'property'       => 'refTo1',
                    'onDelete'       => 'RESTRICT',
                    'isArray'        => false,
                ]],
            ],
            ['1' => $schema1, '2' => $schema2, '3' => $schema3]
        );

        $cascadeObj = $this->createObjectEntity('cascade-uuid', '1', '1', ['refTo2' => 'target-uuid']);
        $restrictObj = $this->createObjectEntity('restrict-uuid', '3', '1', ['refTo1' => 'cascade-uuid']);

        $this->objectMapper->method('findByRelation')
            ->willReturnCallback(function (string $search) use ($cascadeObj, $restrictObj) {
                if ($search === 'target-uuid') {
                    return [$cascadeObj];
                }
                if ($search === 'cascade-uuid') {
                    return [$restrictObj];
                }
                return [];
            });

        $targetObj = $this->createObjectEntity('target-uuid', '2');
        $result = $this->service->canDelete($targetObj);

        $this->assertFalse($result->deletable);
        $this->assertNotEmpty($result->blockers);
        $this->assertNotEmpty($result->cascadeTargets);
    }

    // =========================================================================
    // SET_NULL array with isArray target + property null
    // =========================================================================

    public function testApplySetNullArrayWhenPropertyIsNull(): void
    {
        $object = $this->createObjectEntity('dep-uuid', '1', '1', ['tags' => null]);
        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn([
                'object' => $object,
                'register' => $register,
                'schema' => $schema,
            ]);

        $this->objectMapper->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (ObjectEntity $entity) {
                    $data = $entity->getObject();
                    // When isArray=true but property is null, it falls to the else branch (sets null).
                    return $data['tags'] === null;
                }),
                $this->anything(),
                $this->anything()
            );

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [[
                'objectUuid' => 'dep-uuid',
                'schema'     => '1',
                'property'   => 'tags',
                'isArray'    => true,
                'sourceUuid' => 'source-uuid',
            ]]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'source-uuid');
    }
}
