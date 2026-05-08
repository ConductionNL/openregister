<?php

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ExportServiceTest extends TestCase
{

    /**
     * @var MagicMapper&MockObject
     */
    private MagicMapper $objectMapper;

    /**
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper $registerMapper;

    /**
     * @var IUserManager&MockObject
     */
    private IUserManager $userManager;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * @var CacheHandler&MockObject
     */
    private CacheHandler $cacheHandler;

    /**
     * @var PropertyRbacHandler&MockObject
     */
    private PropertyRbacHandler $propertyRbacHandler;

    private ExportService $service;

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
            $this->propertyRbacHandler,
            $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class)
        );
    }

    /**
     * Create a real ObjectEntity instance with the given data.
     */
    private function createObjectEntity(string $uuid, ?string $name, array $objectData): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setName($name);
        $entity->setObject($objectData);
        return $entity;
    }

    /**
     * Read all non-empty headers from row 1 of the active sheet.
     */
    private function readHeaders(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): array
    {
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [];
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            $val = $sheet->getCell($col . '1')->getValue();
            if ($val === null || $val === '') {
                break;
            }
            $headers[$col] = $val;
        }
        return $headers;
    }

    // --- exportToExcel ---

    public function testExportToExcelWithSingleSchema(): void
    {
        $schema = new Schema();
        $schema->setSlug('test-schema');
        $schema->setProperties([
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, $spreadsheet);
        $this->assertSame(1, $spreadsheet->getSheetCount());
        $this->assertSame('test-schema', $spreadsheet->getActiveSheet()->getTitle());
    }

    public function testExportToExcelWithRegisterExportsAllSchemas(): void
    {
        $register = new Register();
        $reflection = new \ReflectionClass($register);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 1);

        $schema1 = new Schema();
        $schema1->setSlug('schema-a');
        $schema1->setProperties(['field1' => ['type' => 'string']]);

        $schema2 = new Schema();
        $schema2->setSlug('schema-b');
        $schema2->setProperties(['field2' => ['type' => 'string']]);

        $this->registerMapper
            ->expects($this->once())
            ->method('getSchemasByRegisterId')
            ->with(1)
            ->willReturn([$schema1, $schema2]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel($register);

        $this->assertSame(2, $spreadsheet->getSheetCount());
    }

    public function testExportToExcelWithObjectData(): void
    {
        $schema = new Schema();
        $schema->setSlug('test-schema');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $object = $this->createObjectEntity('uuid-123', 'Test Object', ['title' => 'Test Object']);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();
        // Row 1 is headers, row 2 is data.
        $this->assertSame('uuid-123', $sheet->getCell('A2')->getValue());
        $this->assertSame('Test Object', $sheet->getCell('B2')->getValue());
    }

    public function testExportToExcelSkipsHiddenOnCollectionProperties(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'visible' => ['type' => 'string'],
            'hidden' => ['type' => 'string', 'hideOnCollection' => true],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertSame('id', $headerValues[0]);
        $this->assertContains('visible', $headerValues);
        $this->assertNotContains('hidden', $headerValues);
    }

    public function testExportToExcelSkipsRbacRestrictedProperties(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'public_field' => ['type' => 'string'],
            'restricted_field' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturnCallback(function ($schema, $property, $object) {
                return $property !== 'restricted_field';
            });

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertContains('public_field', $headerValues);
        $this->assertNotContains('restricted_field', $headerValues);
    }

    public function testExportToExcelAddsMetadataColumnsForAdmin(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $adminUser = $this->createMock(IUser::class);
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->with($adminUser)->willReturn(true);

        $this->groupManager
            ->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema, [], $adminUser);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertContains('@self.created', $headerValues);
        $this->assertContains('@self.updated', $headerValues);
        $this->assertContains('@self.owner', $headerValues);
    }

    public function testExportToExcelNoMetadataForNonAdmin(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $regularUser = $this->createMock(IUser::class);
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->with($regularUser)->willReturn(false);

        $this->groupManager
            ->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema, [], $regularUser);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertNotContains('@self.created', $headerValues);
    }

    // --- exportToCsv ---

    public function testExportToCsvThrowsForMultipleSchemas(): void
    {
        $register = new Register();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot export multiple schemas to CSV');

        $this->service->exportToCsv($register, null);
    }

    public function testExportToCsvReturnsCsvString(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $csv = $this->service->exportToCsv(null, $schema);

        $this->assertIsString($csv);
        $this->assertStringContainsString('id', $csv);
    }

    // --- Relation property detection (companion columns) ---

    public function testExportToExcelAddsCompanionColumnsForUuidRelations(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
            'relatedItem' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertContains('relatedItem', $headerValues);
        $this->assertContains('_relatedItem', $headerValues);
    }

    public function testExportToExcelAddsCompanionColumnsForRefRelations(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'parent' => ['type' => 'string', '$ref' => '#/definitions/Parent'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertContains('_parent', $headerValues);
    }

    public function testExportToExcelAddsCompanionColumnsForArrayOfUuids(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'tags' => [
                'type' => 'array',
                'items' => ['format' => 'uuid'],
            ],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertContains('_tags', $headerValues);
    }

    // --- UUID name resolution ---

    public function testExportToExcelResolvesUuidNames(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'owner' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        $object = $this->createObjectEntity('obj-uuid-1', 'Object Name', ['owner' => 'owner-uuid-1']);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $this->cacheHandler
            ->method('getMultipleObjectNames')
            ->willReturn(['owner-uuid-1' => 'Owner Name']);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        // Find the _owner column.
        $ownerNameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_owner') {
                $ownerNameCol = $col;
                break;
            }
        }

        $this->assertNotNull($ownerNameCol);
        $this->assertSame('Owner Name', $sheet->getCell($ownerNameCol . '2')->getValue());
    }

    // --- convertValueToString edge cases ---

    public function testExportHandlesNullValuesGracefully(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $object = $this->createObjectEntity('uuid-1', null, ['title' => null]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        // Should not throw.
        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, $spreadsheet);
    }

    public function testExportHandlesArrayValuesAsJson(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'tags' => ['type' => 'array'],
        ]);

        $object = $this->createObjectEntity('uuid-1', 'name', ['tags' => ['a', 'b', 'c']]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('["a","b","c"]', $sheet->getCell('B2')->getValue());
    }

    // --- Skip default header fields ---

    public function testExportSkipsBuiltInHeaderFields(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'id' => ['type' => 'integer'],
            'uuid' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'created' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertSame('id', $headerValues[0]);
        $this->assertContains('title', $headerValues);
        // uuid and created are built-in and should be skipped from schema properties.
        $this->assertNotContains('uuid', $headerValues);
    }

    public function testExportToExcelNoMetadataForAnonymousUser(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        // null user = anonymous.
        $spreadsheet = $this->service->exportToExcel(null, $schema, [], null);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertNotContains('@self.created', $headerValues);
    }

    public function testExportToCsvWithDataRows(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
        ]);

        $obj1 = $this->createObjectEntity('uuid-1', 'First', ['title' => 'Alpha', 'count' => 10]);
        $obj2 = $this->createObjectEntity('uuid-2', 'Second', ['title' => 'Beta', 'count' => 20]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$obj1, $obj2]);

        $csv = $this->service->exportToCsv(null, $schema);

        $this->assertIsString($csv);
        $this->assertStringContainsString('title', $csv);
        $this->assertStringContainsString('Alpha', $csv);
        $this->assertStringContainsString('Beta', $csv);
    }

    public function testExportToCsvSkipsHiddenOnCollectionProperties(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'visible' => ['type' => 'string'],
            'hidden' => ['type' => 'string', 'hideOnCollection' => true],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $csv = $this->service->exportToCsv(null, $schema);

        $this->assertIsString($csv);
        $this->assertStringContainsString('visible', $csv);
        $this->assertStringNotContainsString('hidden', $csv);
    }

    public function testExportToExcelHandlesBooleanValues(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'active' => ['type' => 'boolean'],
        ]);

        $object = $this->createObjectEntity('uuid-1', 'Test', ['active' => true]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();
        // Boolean true should be converted to "true" string.
        $cellValue = $sheet->getCell('B2')->getValue();
        $this->assertNotNull($cellValue);
    }

    public function testExportToExcelHandlesNestedObjectValues(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'address' => ['type' => 'object'],
        ]);

        $object = $this->createObjectEntity('uuid-1', 'Test', [
            'address' => ['street' => 'Main St', 'city' => 'Amsterdam'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();
        $cellValue = $sheet->getCell('B2')->getValue();
        // Nested objects should be JSON-encoded.
        $this->assertIsString($cellValue);
        $this->assertStringContainsString('Main St', $cellValue);
    }

    public function testExportToExcelWithEmptySchemaProperties(): void
    {
        $schema = new Schema();
        $schema->setSlug('empty-schema');
        $schema->setProperties([]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, $spreadsheet);
        // Should still have at least the default id/name headers.
        $headers = $this->readHeaders($spreadsheet);
        $this->assertContains('id', array_values($headers));
    }

    public function testExportToCsvWithRbacRestrictions(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'public_field' => ['type' => 'string'],
            'secret_field' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturnCallback(function ($schema, $property, $object) {
                return $property !== 'secret_field';
            });

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $csv = $this->service->exportToCsv(null, $schema);

        $this->assertStringContainsString('public_field', $csv);
        $this->assertStringNotContainsString('secret_field', $csv);
    }

    // ── Additional edge-case tests ─────────────────────────────────────

    public function testExportToExcelWithFilters(): void
    {
        $schema = new Schema();
        $schema->setSlug('filtered');
        $schema->setProperties([
            'status' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $obj = $this->createObjectEntity('uuid-1', 'Active', ['status' => 'active']);
        $this->objectService
            ->method('searchObjects')
            ->willReturn([$obj]);

        $spreadsheet = $this->service->exportToExcel(null, $schema, ['status' => 'active']);

        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, $spreadsheet);
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('uuid-1', $sheet->getCell('A2')->getValue());
    }

    public function testExportToCsvWithMultipleObjectsVerifyRowCount(): void
    {
        $schema = new Schema();
        $schema->setSlug('count-test');
        $schema->setProperties([
            'name' => ['type' => 'string'],
        ]);

        $objects = [];
        for ($i = 0; $i < 5; $i++) {
            $objects[] = $this->createObjectEntity("uuid-$i", "Obj $i", ['name' => "Item $i"]);
        }

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn($objects);

        $csv = $this->service->exportToCsv(null, $schema);

        // Header + 5 data rows = 6 lines (last line may be empty)
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(6, $lines);
    }

    public function testExportToExcelWithArrayOfUuidsResolvesNames(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'members' => [
                'type' => 'array',
                'items' => ['format' => 'uuid'],
            ],
        ]);

        $object = $this->createObjectEntity('obj-1', 'Team', [
            'members' => ['member-uuid-1', 'member-uuid-2'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $this->cacheHandler
            ->method('getMultipleObjectNames')
            ->willReturn([
                'member-uuid-1' => 'Alice',
                'member-uuid-2' => 'Bob',
            ]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();
        $nameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_members') {
                $nameCol = $col;
                break;
            }
        }

        $this->assertNotNull($nameCol);
        $nameValue = $sheet->getCell($nameCol . '2')->getValue();
        $this->assertStringContainsString('Alice', $nameValue);
    }

    public function testExportToCsvHandlesSpecialCharsInValues(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'note' => ['type' => 'string'],
        ]);

        $object = $this->createObjectEntity('uuid-1', 'Test', [
            'note' => 'Value with "quotes" and, commas',
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $csv = $this->service->exportToCsv(null, $schema);

        $this->assertIsString($csv);
        $this->assertStringContainsString('quotes', $csv);
    }

    public function testExportToExcelWithRegisterAndSchemaOverrideUsesSchema(): void
    {
        $register = new Register();
        $ref = new \ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 1);

        $schema = new Schema();
        $schema->setSlug('override-schema');
        $schema->setProperties(['title' => ['type' => 'string']]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel($register, $schema);

        $this->assertSame(1, $spreadsheet->getSheetCount());
        $this->assertSame('override-schema', $spreadsheet->getActiveSheet()->getTitle());
    }

    // ── isUserAdmin edge cases ──────────────────────────────────────────

    public function testExportToExcelNoMetadataWhenAdminGroupDoesNotExist(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $user = $this->createMock(IUser::class);

        // groupManager->get('admin') returns null (admin group doesn't exist).
        $this->groupManager
            ->method('get')
            ->with('admin')
            ->willReturn(null);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema, [], $user);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        // No metadata columns since admin group doesn't exist.
        $this->assertNotContains('@self.created', $headerValues);
    }

    // ── fetchObjectsForExport with @self. metadata filters ──────────────

    public function testExportPassesSelfMetadataFilters(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $ref = new \ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 42);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->expects($this->once())
            ->method('searchObjects')
            ->with($this->callback(function ($query) {
                // @self.owner should be mapped to objectFilters['owner'].
                return isset($query['@self']['owner']) && $query['@self']['owner'] === 'admin';
            }))
            ->willReturn([]);

        $this->service->exportToExcel(null, $schema, ['@self.owner' => 'admin']);
    }

    public function testExportSkipsNonSelfFilters(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $ref = new \ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 42);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->expects($this->once())
            ->method('searchObjects')
            ->with($this->callback(function ($query) {
                // 'status' is a JSON property filter, should NOT be in @self.
                return !isset($query['@self']['status']);
            }))
            ->willReturn([]);

        $this->service->exportToExcel(null, $schema, ['status' => 'active']);
    }

    public function testExportWithMultiFilterFalse(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->expects($this->once())
            ->method('searchObjects')
            ->with(
                $this->callback(function ($query) {
                    return $query['_multitenancy_explicit'] === true;
                }),
                true,   // _rbac
                false,  // _multitenancy should be false
                null,
                null
            )
            ->willReturn([]);

        $this->service->exportToExcel(null, $schema, ['_multi' => 'false']);
    }

    public function testExportWithMultiFilterShortName(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->expects($this->once())
            ->method('searchObjects')
            ->with(
                $this->callback(function ($query) {
                    return $query['_multitenancy_explicit'] === true;
                }),
                true,   // _rbac
                false,  // _multitenancy should be false because 'multi' => '0'
                null,
                null
            )
            ->willReturn([]);

        $this->service->exportToExcel(null, $schema, ['multi' => '0']);
    }

    // ── getObjectValue: @self. metadata fields ──────────────────────────

    public function testExportMetadataDateTimeFieldFormatsCorrectly(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $entity = new ObjectEntity();
        $entity->setUuid('uuid-1');
        $entity->setName('Test');
        $entity->setObject(['title' => 'Hello']);
        // Set created via reflection to a DateTime.
        $ref = new \ReflectionClass($entity);
        $prop = $ref->getProperty('created');
        $prop->setAccessible(true);
        $prop->setValue($entity, new \DateTime('2025-06-15T10:30:00Z'));

        $adminUser = $this->createMock(IUser::class);
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->with($adminUser)->willReturn(true);

        $this->groupManager
            ->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$entity]);

        $spreadsheet = $this->service->exportToExcel(null, $schema, [], $adminUser);

        $sheet = $spreadsheet->getActiveSheet();

        // Find the @self.created column.
        $createdCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '@self.created') {
                $createdCol = $col;
                break;
            }
        }

        $this->assertNotNull($createdCol, '@self.created column should exist');
        $val = $sheet->getCell($createdCol . '2')->getValue();
        // The value should be a formatted date string (Y-m-d H:i:s).
        $this->assertNotNull($val);
        $this->assertStringContainsString('2025-06-15', $val);
    }

    public function testExportMetadataArrayFieldConvertedToJson(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $entity = new ObjectEntity();
        $entity->setUuid('uuid-1');
        $entity->setName('Test');
        $entity->setObject(['title' => 'Hello']);
        $entity->setGroups(['group-a', 'group-b']);

        $adminUser = $this->createMock(IUser::class);
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->with($adminUser)->willReturn(true);

        $this->groupManager
            ->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$entity]);

        $spreadsheet = $this->service->exportToExcel(null, $schema, [], $adminUser);

        $sheet = $spreadsheet->getActiveSheet();

        // Find the @self.groups column.
        $groupsCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '@self.groups') {
                $groupsCol = $col;
                break;
            }
        }

        $this->assertNotNull($groupsCol, '@self.groups column should exist');
        $val = $sheet->getCell($groupsCol . '2')->getValue();
        $this->assertIsString($val);
        $this->assertStringContainsString('group-a', $val);
    }

    public function testExportMetadataScalarFieldReturnsString(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $entity = new ObjectEntity();
        $entity->setUuid('uuid-1');
        $entity->setName('Test');
        $entity->setObject(['title' => 'Hello']);
        $entity->setOwner('admin');

        $adminUser = $this->createMock(IUser::class);
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->with($adminUser)->willReturn(true);

        $this->groupManager
            ->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$entity]);

        $spreadsheet = $this->service->exportToExcel(null, $schema, [], $adminUser);

        $sheet = $spreadsheet->getActiveSheet();

        // Find the @self.owner column.
        $ownerCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '@self.owner') {
                $ownerCol = $col;
                break;
            }
        }

        $this->assertNotNull($ownerCol, '@self.owner column should exist');
        $this->assertSame('admin', $sheet->getCell($ownerCol . '2')->getValue());
    }

    public function testExportMetadataNullFieldReturnsNull(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $entity = new ObjectEntity();
        $entity->setUuid('uuid-1');
        $entity->setName('Test');
        $entity->setObject(['title' => 'Hello']);
        // updated is null by default.

        $adminUser = $this->createMock(IUser::class);
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->with($adminUser)->willReturn(true);

        $this->groupManager
            ->method('get')
            ->with('admin')
            ->willReturn($adminGroup);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$entity]);

        $spreadsheet = $this->service->exportToExcel(null, $schema, [], $adminUser);

        $sheet = $spreadsheet->getActiveSheet();

        // Find the @self.updated column.
        $updatedCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '@self.updated') {
                $updatedCol = $col;
                break;
            }
        }

        $this->assertNotNull($updatedCol, '@self.updated column should exist');
        $this->assertNull($sheet->getCell($updatedCol . '2')->getValue());
    }

    // ── getObjectValue: _ prefix legacy metadata ────────────────────────

    /**
     * Test that companion name columns for relation properties work with
     * non-existent source data (null).
     */
    public function testExportCompanionColumnWithNullRelation(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'relatedItem' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        // Object has NO relatedItem field.
        $object = $this->createObjectEntity('uuid-1', 'Test', []);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        // Find the _relatedItem column.
        $nameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_relatedItem') {
                $nameCol = $col;
                break;
            }
        }

        $this->assertNotNull($nameCol);
        // Should resolve null to null.
        $this->assertNull($sheet->getCell($nameCol . '2')->getValue());
    }

    // ── resolveUuidsToNames: JSON-encoded array of UUIDs ────────────────

    public function testExportResolvesJsonEncodedArrayOfUuids(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'members' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        // Object has a JSON-encoded array as the value.
        $object = $this->createObjectEntity('obj-1', 'Team', [
            'members' => json_encode(['uuid-a', 'uuid-b']),
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $this->cacheHandler
            ->method('getMultipleObjectNames')
            ->willReturn([
                'uuid-a' => 'Alice',
                'uuid-b' => 'Bob',
            ]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        // Find _members column.
        $nameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_members') {
                $nameCol = $col;
                break;
            }
        }

        $this->assertNotNull($nameCol);
        $val = $sheet->getCell($nameCol . '2')->getValue();
        $this->assertStringContainsString('Alice', $val);
        $this->assertStringContainsString('Bob', $val);
    }

    // ── resolveUuidNameMap: pre-seeding from loaded objects ─────────────

    public function testExportPreSeedsNameMapFromLoadedObjects(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'parent' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        // Object references itself.
        $obj1 = $this->createObjectEntity('uuid-1', 'Self Ref', ['parent' => 'uuid-1']);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$obj1]);

        // cacheHandler should NOT be called because uuid-1 is already in loaded objects.
        $this->cacheHandler
            ->expects($this->never())
            ->method('getMultipleObjectNames');

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        // Find _parent column.
        $nameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_parent') {
                $nameCol = $col;
                break;
            }
        }

        $this->assertNotNull($nameCol);
        $this->assertSame('Self Ref', $sheet->getCell($nameCol . '2')->getValue());
    }

    // ── isRelationProperty: non-relation properties ─────────────────────

    public function testExportNoCompanionColumnForNonRelationProperty(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        // No companion columns for non-relation properties.
        $this->assertNotContains('_title', $headerValues);
        $this->assertNotContains('_count', $headerValues);
        $this->assertNotContains('_tags', $headerValues);
    }

    public function testExportCompanionColumnForArrayWithRefItems(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'children' => [
                'type' => 'array',
                'items' => ['$ref' => '#/definitions/Child'],
            ],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertContains('_children', $headerValues);
    }

    // ── populateSheet: sheet title defaults to 'data' when no schema ────

    public function testExportToExcelWithNoSchemaUsesDataAsSheetTitle(): void
    {
        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, null);

        $this->assertSame(1, $spreadsheet->getSheetCount());
        $this->assertSame('data', $spreadsheet->getActiveSheet()->getTitle());
    }

    // ── Skipping visible:false properties ───────────────────────────────

    public function testExportSkipsExplicitlyInvisibleProperties(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'visibleField' => ['type' => 'string'],
            'invisibleField' => ['type' => 'string', 'visible' => false],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $headers = $this->readHeaders($spreadsheet);
        $headerValues = array_values($headers);

        $this->assertContains('visibleField', $headerValues);
        $this->assertNotContains('invisibleField', $headerValues);
    }

    // ── convertValueToString with integer/boolean scalars ───────────────

    public function testExportHandlesIntegerValuesAsStrings(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'count' => ['type' => 'integer'],
        ]);

        $object = $this->createObjectEntity('uuid-1', 'Test', ['count' => 42]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();
        // convertValueToString returns '42' but PhpSpreadsheet may store as int.
        $this->assertEquals(42, $sheet->getCell('B2')->getValue());
    }

    // ── fetchObjectsForExport: register + schema IDs ────────────────────

    public function testExportWithRegisterAndSchemaPassesBothIds(): void
    {
        $register = new Register();
        $ref = new \ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 10);

        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties(['title' => ['type' => 'string']]);
        $sRef = new \ReflectionClass($schema);
        $sIdProp = $sRef->getProperty('id');
        $sIdProp->setAccessible(true);
        $sIdProp->setValue($schema, 20);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->expects($this->once())
            ->method('searchObjects')
            ->with($this->callback(function ($query) {
                return $query['@self']['register'] === 10
                    && $query['@self']['schema'] === 20;
            }))
            ->willReturn([]);

        $this->service->exportToExcel($register, $schema);
    }

    // ── resolveUuidsToNames: single UUID with unknown name ──────────────

    public function testExportFallsBackToUuidWhenNameNotResolved(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'owner' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        $object = $this->createObjectEntity('obj-1', 'Obj', ['owner' => 'unknown-uuid']);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        // Return empty map — name not found.
        $this->cacheHandler
            ->method('getMultipleObjectNames')
            ->willReturn([]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        $nameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_owner') {
                $nameCol = $col;
                break;
            }
        }

        $this->assertNotNull($nameCol);
        // Falls back to the UUID itself.
        $this->assertSame('unknown-uuid', $sheet->getCell($nameCol . '2')->getValue());
    }

    // ── collectUuids: JSON string with empty items ──────────────────────

    public function testExportHandlesJsonArrayWithEmptyStrings(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'refs' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        // JSON-encoded array with empty strings (should be skipped).
        $object = $this->createObjectEntity('obj-1', 'Test', [
            'refs' => json_encode(['uuid-a', '', 'uuid-b']),
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $this->cacheHandler
            ->method('getMultipleObjectNames')
            ->willReturn([
                'uuid-a' => 'Name A',
                'uuid-b' => 'Name B',
            ]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        // Should not throw.
        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, $spreadsheet);
    }

    // ── Multiple objects with mixed relation and non-relation data ───────

    public function testExportWithMultipleObjectsMixedRelationData(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
            'parent' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        $obj1 = $this->createObjectEntity('uuid-1', 'First', ['title' => 'A', 'parent' => 'ref-uuid-1']);
        $obj2 = $this->createObjectEntity('uuid-2', 'Second', ['title' => 'B', 'parent' => null]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$obj1, $obj2]);

        $this->cacheHandler
            ->method('getMultipleObjectNames')
            ->willReturn(['ref-uuid-1' => 'Parent Name']);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        // Find _parent column.
        $nameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_parent') {
                $nameCol = $col;
                break;
            }
        }

        $this->assertNotNull($nameCol);
        $this->assertSame('Parent Name', $sheet->getCell($nameCol . '2')->getValue());
        // Second object has null parent.
        $this->assertNull($sheet->getCell($nameCol . '3')->getValue());
    }

    // ── Export with no name columns should not call cacheHandler ─────────

    public function testExportWithNoRelationPropertiesSkipsBulkResolve(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
        ]);

        $object = $this->createObjectEntity('uuid-1', 'Test', ['title' => 'Hi']);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        // Should never be called since there are no relation columns.
        $this->cacheHandler
            ->expects($this->never())
            ->method('getMultipleObjectNames');

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, $spreadsheet);
    }

    // ── resolveUuidsToNames: native array of UUIDs ──────────────────────

    public function testExportResolvesNativeArrayOfUuids(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'tags' => [
                'type' => 'array',
                'items' => ['format' => 'uuid'],
            ],
        ]);

        $object = $this->createObjectEntity('obj-1', 'Obj', [
            'tags' => ['tag-uuid-1', 'tag-uuid-2'],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $this->cacheHandler
            ->method('getMultipleObjectNames')
            ->willReturn([
                'tag-uuid-1' => 'Tag One',
                'tag-uuid-2' => 'Tag Two',
            ]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        $nameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_tags') {
                $nameCol = $col;
                break;
            }
        }

        $this->assertNotNull($nameCol);
        $val = $sheet->getCell($nameCol . '2')->getValue();
        $this->assertStringContainsString('Tag One', $val);
        $this->assertStringContainsString('Tag Two', $val);
    }

    // ── Export with empty string UUID value ──────────────────────────────

    public function testExportHandlesEmptyStringUuidValue(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'ref' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        $object = $this->createObjectEntity('obj-1', 'Obj', ['ref' => '']);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        // Empty string should not trigger cacheHandler.
        $this->cacheHandler
            ->expects($this->never())
            ->method('getMultipleObjectNames');

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, $spreadsheet);
    }

    // ── getObjectValue: missing field in objectData ─────────────────────

    public function testExportHandlesMissingFieldInObjectData(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'title' => ['type' => 'string'],
            'missing' => ['type' => 'string'],
        ]);

        // Object only has 'title', not 'missing'.
        $object = $this->createObjectEntity('uuid-1', 'Test', ['title' => 'Hello']);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        // Find the 'missing' column.
        $missingCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === 'missing') {
                $missingCol = $col;
                break;
            }
        }

        $this->assertNotNull($missingCol);
        // Missing field should be null.
        $this->assertNull($sheet->getCell($missingCol . '2')->getValue());
    }

    // ── Export CSV with register + schema (no exception) ────────────────

    public function testExportToCsvWithRegisterAndSchema(): void
    {
        $register = new Register();
        $ref = new \ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 1);

        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties(['title' => ['type' => 'string']]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([]);

        $csv = $this->service->exportToCsv($register, $schema);

        $this->assertIsString($csv);
        $this->assertStringContainsString('id', $csv);
        $this->assertStringContainsString('title', $csv);
    }

    // ── Export with _multi = true (default behavior) ────────────────────

    public function testExportWithMultiFilterTrue(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties(['title' => ['type' => 'string']]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->expects($this->once())
            ->method('searchObjects')
            ->with(
                $this->callback(function ($query) {
                    return $query['_multitenancy_explicit'] === true;
                }),
                true,
                true,  // _multitenancy should be true
                null,
                null
            )
            ->willReturn([]);

        $this->service->exportToExcel(null, $schema, ['_multi' => 'true']);
    }

    // ── resolveUuidsToNames: array containing non-string items ─────────

    public function testExportResolvesArrayWithNonStringItems(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'refs' => [
                'type' => 'array',
                'items' => ['format' => 'uuid'],
            ],
        ]);

        // Native array with a mix of strings and non-strings (int).
        $object = $this->createObjectEntity('obj-1', 'Obj', [
            'refs' => ['uuid-a', 42],
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $this->cacheHandler
            ->method('getMultipleObjectNames')
            ->willReturn(['uuid-a' => 'Name A']);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        $nameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_refs') {
                $nameCol = $col;
                break;
            }
        }

        $this->assertNotNull($nameCol);
        $val = $sheet->getCell($nameCol . '2')->getValue();
        // Should contain resolved name and the int converted to string.
        $this->assertStringContainsString('Name A', $val);
        $this->assertStringContainsString('42', $val);
    }

    public function testExportResolvesJsonArrayWithNonStringItems(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties([
            'refs' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        // JSON-encoded array with non-string items.
        $object = $this->createObjectEntity('obj-1', 'Obj', [
            'refs' => json_encode(['uuid-a', 123, null]),
        ]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->method('searchObjects')
            ->willReturn([$object]);

        $this->cacheHandler
            ->method('getMultipleObjectNames')
            ->willReturn(['uuid-a' => 'Name A']);

        $spreadsheet = $this->service->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();

        $nameCol = null;
        for ($col = 'A'; $col !== 'ZZ'; $col++) {
            if ($sheet->getCell($col . '1')->getValue() === '_refs') {
                $nameCol = $col;
                break;
            }
        }

        $this->assertNotNull($nameCol);
        $val = $sheet->getCell($nameCol . '2')->getValue();
        $this->assertStringContainsString('Name A', $val);
    }

    // ── Export with no filters (default multitenancy) ───────────────────

    public function testExportDefaultMultitenancy(): void
    {
        $schema = new Schema();
        $schema->setSlug('test');
        $schema->setProperties(['title' => ['type' => 'string']]);

        $this->propertyRbacHandler
            ->method('canReadProperty')
            ->willReturn(true);

        $this->objectService
            ->expects($this->once())
            ->method('searchObjects')
            ->with(
                $this->callback(function ($query) {
                    return $query['_multitenancy_explicit'] === false;
                }),
                true,
                true,  // default multitenancy is true
                null,
                null
            )
            ->willReturn([]);

        $this->service->exportToExcel(null, $schema, []);
    }

}
