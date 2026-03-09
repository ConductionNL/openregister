<?php

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
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
     * @var ObjectEntityMapper&MockObject
     */
    private ObjectEntityMapper $objectEntityMapper;

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
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);

        $this->service = new ExportService(
            $this->objectEntityMapper,
            $this->registerMapper,
            $this->userManager,
            $this->groupManager,
            $this->objectService,
            $this->cacheHandler,
            $this->propertyRbacHandler
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
}
