<?php

/**
 * ReferentialIntegrityService Coverage Tests
 *
 * Tests for uncovered branches: isValidOnDeleteAction, extractOnDelete,
 * extractTargetRef, resolveSchemaRef, isRequiredProperty, getDefaultValue,
 * hasIncomingOnDeleteReferences, canDelete with no schema, logRestrictBlock,
 * and walkDeletionGraph edge cases.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
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
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class ReferentialIntegrityServiceCoverageTest extends TestCase
{
    private ReferentialIntegrityService $service;
    private SchemaMapper|MockObject $schemaMapper;
    private RegisterMapper|MockObject $registerMapper;
    private MagicMapper|MockObject $objectMapper;
    private AuditTrailMapper|MockObject $auditTrailMapper;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ReferentialIntegrityService(
            $this->schemaMapper,
            $this->registerMapper,
            $this->objectMapper,
            $this->auditTrailMapper,
            $this->logger
        );
    }

    private function invokeMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    private function setProperty(object $obj, string $prop, mixed $value): void
    {
        $ref = new ReflectionClass($obj);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($obj, $value);
    }

    // =========================================================================
    // isValidOnDeleteAction
    // =========================================================================

    public function testIsValidOnDeleteActionCascade(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('CASCADE'));
    }

    public function testIsValidOnDeleteActionRestrict(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('RESTRICT'));
    }

    public function testIsValidOnDeleteActionSetNull(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('SET_NULL'));
    }

    public function testIsValidOnDeleteActionSetDefault(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('SET_DEFAULT'));
    }

    public function testIsValidOnDeleteActionNoAction(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('NO_ACTION'));
    }

    public function testIsValidOnDeleteActionCaseInsensitive(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('cascade'));
    }

    public function testIsValidOnDeleteActionInvalid(): void
    {
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('DELETE'));
    }

    // =========================================================================
    // extractOnDelete
    // =========================================================================

    public function testExtractOnDeletePresent(): void
    {
        $result = $this->invokeMethod($this->service, 'extractOnDelete', [
            ['onDelete' => 'cascade'],
        ]);

        $this->assertSame('CASCADE', $result);
    }

    public function testExtractOnDeleteMissing(): void
    {
        $result = $this->invokeMethod($this->service, 'extractOnDelete', [
            ['type' => 'string'],
        ]);

        $this->assertNull($result);
    }

    // =========================================================================
    // extractTargetRef
    // =========================================================================

    public function testExtractTargetRefDirect(): void
    {
        $result = $this->invokeMethod($this->service, 'extractTargetRef', [
            ['$ref' => 'my-schema'],
        ]);

        $this->assertSame('my-schema', $result);
    }

    public function testExtractTargetRefArrayItems(): void
    {
        $result = $this->invokeMethod($this->service, 'extractTargetRef', [
            ['type' => 'array', 'items' => ['$ref' => 'other-schema']],
        ]);

        $this->assertSame('other-schema', $result);
    }

    public function testExtractTargetRefNone(): void
    {
        $result = $this->invokeMethod($this->service, 'extractTargetRef', [
            ['type' => 'string'],
        ]);

        $this->assertNull($result);
    }

    // =========================================================================
    // resolveSchemaRef
    // =========================================================================

    public function testResolveSchemaRefById(): void
    {
        $schema = new Schema();
        $schema->setId(42);
        $schema->setUuid('uuid-42');

        $result = $this->invokeMethod($this->service, 'resolveSchemaRef', [
            '42',
            [$schema],
        ]);

        $this->assertSame('42', $result);
    }

    public function testResolveSchemaRefBySlug(): void
    {
        $schema = new Schema();
        $schema->setId(10);
        $schema->setUuid('uuid-10');
        $schema->setSlug('my-schema');

        $result = $this->invokeMethod($this->service, 'resolveSchemaRef', [
            'my-schema',
            [$schema],
        ]);

        $this->assertSame('10', $result);
    }

    public function testResolveSchemaRefByUuid(): void
    {
        $schema = new Schema();
        $schema->setId(5);
        $schema->setUuid('abc-def-123');

        $result = $this->invokeMethod($this->service, 'resolveSchemaRef', [
            'abc-def-123',
            [$schema],
        ]);

        $this->assertSame('5', $result);
    }

    public function testResolveSchemaRefByPathBasename(): void
    {
        $schema = new Schema();
        $schema->setId(7);
        $schema->setUuid('uuid-7');

        $result = $this->invokeMethod($this->service, 'resolveSchemaRef', [
            '/schemas/uuid-7',
            [$schema],
        ]);

        $this->assertSame('7', $result);
    }

    public function testResolveSchemaRefNotFound(): void
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setUuid('uuid-1');

        $result = $this->invokeMethod($this->service, 'resolveSchemaRef', [
            'nonexistent',
            [$schema],
        ]);

        $this->assertNull($result);
    }

    // =========================================================================
    // isRequiredProperty
    // =========================================================================

    public function testIsRequiredPropertyTrue(): void
    {
        $schema = new Schema();
        $schema->setRequired(['name', 'email']);

        $this->setProperty($this->service, 'schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod($this->service, 'isRequiredProperty', ['1', 'name']);

        $this->assertTrue($result);
    }

    public function testIsRequiredPropertyFalse(): void
    {
        $schema = new Schema();
        $schema->setRequired(['name']);

        $this->setProperty($this->service, 'schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod($this->service, 'isRequiredProperty', ['1', 'optional_field']);

        $this->assertFalse($result);
    }

    public function testIsRequiredPropertySchemaNotCached(): void
    {
        $this->setProperty($this->service, 'schemaCache', []);

        $result = $this->invokeMethod($this->service, 'isRequiredProperty', ['999', 'name']);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getDefaultValue
    // =========================================================================

    public function testGetDefaultValuePresent(): void
    {
        $schema = new Schema();
        $schema->setProperties([
            'status' => ['type' => 'string', 'default' => 'active'],
        ]);

        $this->setProperty($this->service, 'schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod($this->service, 'getDefaultValue', ['1', 'status']);

        $this->assertSame('active', $result);
    }

    public function testGetDefaultValueMissing(): void
    {
        $schema = new Schema();
        $schema->setProperties([
            'status' => ['type' => 'string'],
        ]);

        $this->setProperty($this->service, 'schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod($this->service, 'getDefaultValue', ['1', 'status']);

        $this->assertNull($result);
    }

    public function testGetDefaultValueSchemaNotCached(): void
    {
        $this->setProperty($this->service, 'schemaCache', []);

        $result = $this->invokeMethod($this->service, 'getDefaultValue', ['999', 'field']);

        $this->assertNull($result);
    }

    public function testGetDefaultValuePropertyNotFound(): void
    {
        $schema = new Schema();
        $schema->setProperties(['name' => ['type' => 'string']]);

        $this->setProperty($this->service, 'schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod($this->service, 'getDefaultValue', ['1', 'nonexistent']);

        $this->assertNull($result);
    }

    public function testGetDefaultValueNullProperties(): void
    {
        $schema = new Schema();
        $schema->setProperties(null);

        $this->setProperty($this->service, 'schemaCache', ['1' => $schema]);

        $result = $this->invokeMethod($this->service, 'getDefaultValue', ['1', 'field']);

        $this->assertNull($result);
    }

    // =========================================================================
    // canDelete — no schema on object
    // =========================================================================

    public function testCanDeleteNoSchema(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid');

        // Set up empty relation index
        $this->setProperty($this->service, 'relationIndex', []);

        $result = $this->service->canDelete($object);

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->blockers);
    }

    // =========================================================================
    // canDelete — schema not in relation index
    // =========================================================================

    public function testCanDeleteSchemaNotInIndex(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid');
        $object->setSchema('5');

        $this->setProperty($this->service, 'relationIndex', [
            '10' => [['sourceSchemaId' => '3', 'property' => 'ref']],
        ]);

        $result = $this->service->canDelete($object);

        $this->assertTrue($result->deletable);
    }

    // =========================================================================
    // hasIncomingOnDeleteReferences
    // =========================================================================

    public function testHasIncomingOnDeleteReferencesTrue(): void
    {
        $this->setProperty($this->service, 'relationIndex', [
            '5' => [['sourceSchemaId' => '3']],
        ]);

        $this->assertTrue($this->service->hasIncomingOnDeleteReferences('5'));
    }

    public function testHasIncomingOnDeleteReferencesFalse(): void
    {
        $this->setProperty($this->service, 'relationIndex', [
            '5' => [['sourceSchemaId' => '3']],
        ]);

        $this->assertFalse($this->service->hasIncomingOnDeleteReferences('99'));
    }

    // =========================================================================
    // logRestrictBlock
    // =========================================================================

    public function testLogRestrictBlock(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: false,
            blockers: [
                ['schema' => '1', 'property' => 'ref_field', 'objectUuid' => 'blocker-1'],
                ['schema' => '1', 'property' => 'ref_field', 'objectUuid' => 'blocker-2'],
                ['schema' => '2', 'property' => 'other_ref', 'objectUuid' => 'blocker-3'],
            ]
        );

        $this->auditTrailMapper->expects($this->once())->method('insert');

        $this->service->logRestrictBlock('target-uuid', '5', $analysis, 'admin');
    }

    public function testLogRestrictBlockWithEmptyBlockers(): void
    {
        $analysis = new DeletionAnalysis(deletable: true, blockers: []);

        $this->auditTrailMapper->expects($this->once())->method('insert');

        $this->service->logRestrictBlock('target-uuid', '5', $analysis, 'admin');
    }

    // =========================================================================
    // applyDeletionActions — empty analysis
    // =========================================================================

    public function testApplyDeletionActionsEmptyAnalysis(): void
    {
        $analysis = DeletionAnalysis::empty();

        // Should not call any mapper methods
        $this->objectMapper->expects($this->never())->method('findAcrossAllSources');
        $this->objectMapper->expects($this->never())->method('deleteObjects');

        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid');
    }

    // =========================================================================
    // logIntegrityAction — exception handling
    // =========================================================================

    public function testLogIntegrityActionExceptionIsSwallowed(): void
    {
        $this->auditTrailMapper->method('insert')
            ->willThrowException(new Exception('DB insert failed'));

        $this->logger->expects($this->once())->method('warning');

        // Call logRestrictBlock which calls logIntegrityAction internally
        $analysis = new DeletionAnalysis(deletable: false, blockers: [
            ['schema' => '1', 'property' => 'ref'],
        ]);

        // Should not throw
        $this->service->logRestrictBlock('uuid', '1', $analysis, 'admin');
    }
}
