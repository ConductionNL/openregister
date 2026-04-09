<?php

/**
 * ExportService Coverage Tests
 *
 * Tests for uncovered branches: isRelationProperty, collectUuids,
 * resolveUuidsToNames, convertValueToString, getObjectValue,
 * isUserAdmin, identifyNameCompanionColumns, and getHeaders edge cases.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IGroup;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

class ExportServiceCoverageTest extends TestCase
{
    private ExportService $service;
    private MagicMapper|MockObject $objectMapper;
    private RegisterMapper|MockObject $registerMapper;
    private IUserManager|MockObject $userManager;
    private IGroupManager|MockObject $groupManager;
    private ObjectService|MockObject $objectService;
    private CacheHandler|MockObject $cacheHandler;
    private PropertyRbacHandler|MockObject $propertyRbacHandler;

    protected function setUp(): void
    {
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);

        $this->service = new ExportService(
            $this->registerMapper,
            $this->userManager,
            $this->groupManager,
            $this->objectService,
            $this->cacheHandler,
            $this->propertyRbacHandler
        );
    }

    private function invokeMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }

    // =========================================================================
    // isUserAdmin
    // =========================================================================

    public function testIsUserAdminNullUser(): void
    {
        $result = $this->invokeMethod($this->service, 'isUserAdmin', [null]);
        $this->assertFalse($result);
    }

    public function testIsUserAdminNoAdminGroup(): void
    {
        $user = $this->createMock(IUser::class);
        $this->groupManager->method('get')->with('admin')->willReturn(null);

        $result = $this->invokeMethod($this->service, 'isUserAdmin', [$user]);
        $this->assertFalse($result);
    }

    public function testIsUserAdminTrue(): void
    {
        $user = $this->createMock(IUser::class);
        $group = $this->createMock(IGroup::class);
        $group->method('inGroup')->with($user)->willReturn(true);
        $this->groupManager->method('get')->with('admin')->willReturn($group);

        $result = $this->invokeMethod($this->service, 'isUserAdmin', [$user]);
        $this->assertTrue($result);
    }

    public function testIsUserAdminFalse(): void
    {
        $user = $this->createMock(IUser::class);
        $group = $this->createMock(IGroup::class);
        $group->method('inGroup')->with($user)->willReturn(false);
        $this->groupManager->method('get')->with('admin')->willReturn($group);

        $result = $this->invokeMethod($this->service, 'isUserAdmin', [$user]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // isRelationProperty
    // =========================================================================

    public function testIsRelationPropertyUuidFormat(): void
    {
        $result = $this->invokeMethod($this->service, 'isRelationProperty', [
            ['format' => 'uuid'],
        ]);
        $this->assertTrue($result);
    }

    public function testIsRelationPropertyWithRef(): void
    {
        $result = $this->invokeMethod($this->service, 'isRelationProperty', [
            ['$ref' => 'other-schema'],
        ]);
        $this->assertTrue($result);
    }

    public function testIsRelationPropertyArrayItems(): void
    {
        $result = $this->invokeMethod($this->service, 'isRelationProperty', [
            ['type' => 'array', 'items' => ['format' => 'uuid']],
        ]);
        $this->assertTrue($result);
    }

    public function testIsRelationPropertyArrayItemsRef(): void
    {
        $result = $this->invokeMethod($this->service, 'isRelationProperty', [
            ['type' => 'array', 'items' => ['$ref' => 'schema']],
        ]);
        $this->assertTrue($result);
    }

    public function testIsRelationPropertyRegularString(): void
    {
        $result = $this->invokeMethod($this->service, 'isRelationProperty', [
            ['type' => 'string'],
        ]);
        $this->assertFalse($result);
    }

    public function testIsRelationPropertyArrayNoItems(): void
    {
        $result = $this->invokeMethod($this->service, 'isRelationProperty', [
            ['type' => 'array'],
        ]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // collectUuids
    // =========================================================================

    public function testCollectUuidsSingleString(): void
    {
        $uuids = [];
        $this->invokeMethod($this->service, 'collectUuids', ['abc-123', &$uuids]);

        $this->assertSame(['abc-123'], $uuids);
    }

    public function testCollectUuidsJsonArray(): void
    {
        $uuids = [];
        $this->invokeMethod($this->service, 'collectUuids', [json_encode(['id1', 'id2']), &$uuids]);

        $this->assertSame(['id1', 'id2'], $uuids);
    }

    public function testCollectUuidsArray(): void
    {
        $uuids = [];
        $this->invokeMethod($this->service, 'collectUuids', [['uuid1', 'uuid2'], &$uuids]);

        $this->assertSame(['uuid1', 'uuid2'], $uuids);
    }

    public function testCollectUuidsEmptyString(): void
    {
        $uuids = [];
        $this->invokeMethod($this->service, 'collectUuids', ['', &$uuids]);

        $this->assertEmpty($uuids);
    }

    public function testCollectUuidsNullValuesInArray(): void
    {
        $uuids = [];
        $this->invokeMethod($this->service, 'collectUuids', [['uuid1', null, 42], &$uuids]);

        $this->assertSame(['uuid1'], $uuids);
    }

    // =========================================================================
    // resolveUuidsToNames
    // =========================================================================

    public function testResolveUuidsToNamesNull(): void
    {
        $result = $this->invokeMethod($this->service, 'resolveUuidsToNames', [null, []]);
        $this->assertNull($result);
    }

    public function testResolveUuidsToNamesSingleString(): void
    {
        $map = ['uuid-1' => 'My Object'];
        $result = $this->invokeMethod($this->service, 'resolveUuidsToNames', ['uuid-1', $map]);

        $this->assertSame('My Object', $result);
    }

    public function testResolveUuidsToNamesSingleStringNotFound(): void
    {
        $result = $this->invokeMethod($this->service, 'resolveUuidsToNames', ['unknown-uuid', []]);
        $this->assertSame('unknown-uuid', $result);
    }

    public function testResolveUuidsToNamesJsonArray(): void
    {
        $map = ['id1' => 'Name 1', 'id2' => 'Name 2'];
        $result = $this->invokeMethod($this->service, 'resolveUuidsToNames', [
            json_encode(['id1', 'id2']),
            $map,
        ]);

        $decoded = json_decode($result, true);
        $this->assertSame(['Name 1', 'Name 2'], $decoded);
    }

    public function testResolveUuidsToNamesArray(): void
    {
        $map = ['id1' => 'Name 1'];
        $result = $this->invokeMethod($this->service, 'resolveUuidsToNames', [
            ['id1', 'id2'],
            $map,
        ]);

        $decoded = json_decode($result, true);
        $this->assertSame(['Name 1', 'id2'], $decoded);
    }

    // =========================================================================
    // convertValueToString
    // =========================================================================

    public function testConvertValueToStringNull(): void
    {
        $result = $this->invokeMethod($this->service, 'convertValueToString', [null]);
        $this->assertNull($result);
    }

    public function testConvertValueToStringScalar(): void
    {
        $result = $this->invokeMethod($this->service, 'convertValueToString', [42]);
        $this->assertSame('42', $result);
    }

    public function testConvertValueToStringBool(): void
    {
        $result = $this->invokeMethod($this->service, 'convertValueToString', [true]);
        $this->assertSame('1', $result);
    }

    public function testConvertValueToStringArray(): void
    {
        $result = $this->invokeMethod($this->service, 'convertValueToString', [['a', 'b']]);
        $this->assertSame('["a","b"]', $result);
    }

    public function testConvertValueToStringObjectWithToString(): void
    {
        $obj = new class {
            public function __toString(): string
            {
                return 'hello';
            }
        };

        $result = $this->invokeMethod($this->service, 'convertValueToString', [$obj]);
        $this->assertSame('hello', $result);
    }

    // =========================================================================
    // identifyNameCompanionColumns
    // =========================================================================

    public function testIdentifyNameCompanionColumns(): void
    {
        $headers = [
            'A' => 'id',
            'B' => 'name',
            'C' => 'parent',
            'D' => '_parent',
            'E' => '@self.created',
        ];

        $result = $this->invokeMethod($this->service, 'identifyNameCompanionColumns', [$headers]);

        $this->assertArrayHasKey('D', $result);
        $this->assertSame('parent', $result['D']);
        $this->assertArrayNotHasKey('E', $result); // @self fields excluded
    }

    // =========================================================================
    // getObjectValue — various header prefixes
    // =========================================================================

    public function testGetObjectValueIdHeader(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('test-uuid-123');

        $result = $this->invokeMethod($this->service, 'getObjectValue', [$object, 'id']);
        $this->assertSame('test-uuid-123', $result);
    }

    public function testGetObjectValueRegularField(): void
    {
        $object = new ObjectEntity();
        $object->setObject(['title' => 'Hello World']);

        $result = $this->invokeMethod($this->service, 'getObjectValue', [$object, 'title']);
        $this->assertSame('Hello World', $result);
    }

    public function testGetObjectValueMissingField(): void
    {
        $object = new ObjectEntity();
        $object->setObject([]);

        $result = $this->invokeMethod($this->service, 'getObjectValue', [$object, 'nonexistent']);
        $this->assertNull($result);
    }

    // =========================================================================
    // exportToCsv — multiple schemas throws
    // =========================================================================

    public function testExportToCsvMultipleSchemasThrows(): void
    {
        $register = new \OCA\OpenRegister\Db\Register();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->exportToCsv($register, null);
    }
}
