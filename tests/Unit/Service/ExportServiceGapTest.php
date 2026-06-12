<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IGroup;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Gap tests for ExportService covering uncovered methods and branches.
 */
class ExportServiceGapTest extends TestCase
{
    private ExportService $exportService;
    private MagicMapper&MockObject $objectMapper;
    private RegisterMapper&MockObject $registerMapper;
    private IUserManager&MockObject $userManager;
    private IGroupManager&MockObject $groupManager;
    private ObjectService&MockObject $objectService;
    private CacheHandler&MockObject $cacheHandler;
    private PropertyRbacHandler&MockObject $propertyRbacHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);

        $this->exportService = new ExportService(
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
     * Create a Schema entity mock.
     * getProperties() is a real method (onlyMethods), getSlug() is magic (addMethods).
     */
    private function createSchemaEntity(string $slug, array $properties): Schema&MockObject
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getProperties'])
            ->addMethods(['getSlug'])
            ->getMock();
        $schema->method('getSlug')->willReturn($slug);
        $schema->method('getProperties')->willReturn($properties);
        return $schema;
    }

    /**
     * Create a Register entity mock. getId() is a magic method.
     */
    private function createRegisterEntity(int $id): Register&MockObject
    {
        $register = $this->getMockBuilder(Register::class)
            ->addMethods(['getId'])
            ->getMock();
        $register->method('getId')->willReturn($id);
        return $register;
    }

    /**
     * Test exportToCsv throws when register set but no schema (multiple schemas).
     */
    public function testExportToCsvThrowsForMultipleSchemas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot export multiple schemas to CSV format');

        $register = $this->createRegisterEntity(1);
        $this->exportService->exportToCsv($register, null);
    }

    /**
     * Test exportToExcel with null register and null schema.
     */
    public function testExportToExcelWithNullRegisterAndSchema(): void
    {
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel();

        $this->assertInstanceOf(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, $spreadsheet);
        $this->assertEquals('data', $spreadsheet->getActiveSheet()->getTitle());
    }

    /**
     * Test exportToExcel with schema having properties.
     */
    public function testExportToExcelWithSchema(): void
    {
        $schema = $this->createSchemaEntity('test-schema', [
            'name' => ['type' => 'string'],
            'status' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema);

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertEquals('test-schema', $sheet->getTitle());
        $this->assertEquals('id', $sheet->getCell('A1')->getValue());
        $this->assertEquals('name', $sheet->getCell('B1')->getValue());
        $this->assertEquals('status', $sheet->getCell('C1')->getValue());
    }

    /**
     * Test exportToExcel skips hideOnCollection properties.
     */
    public function testExportToExcelSkipsHiddenProperties(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'name' => ['type' => 'string'],
            'hidden_field' => ['type' => 'string', 'hideOnCollection' => true],
            'visible_field' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('id', $sheet->getCell('A1')->getValue());
        $this->assertEquals('name', $sheet->getCell('B1')->getValue());
        $this->assertEquals('visible_field', $sheet->getCell('C1')->getValue());
    }

    /**
     * Test exportToExcel skips properties with visible=false.
     */
    public function testExportToExcelSkipsInvisibleProperties(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'name' => ['type' => 'string'],
            'invisible' => ['type' => 'string', 'visible' => false],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('id', $sheet->getCell('A1')->getValue());
        $this->assertEquals('name', $sheet->getCell('B1')->getValue());
        $this->assertNull($sheet->getCell('C1')->getValue());
    }

    /**
     * Test exportToExcel skips RBAC-restricted properties.
     */
    public function testExportToExcelSkipsRbacRestrictedProperties(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'name' => ['type' => 'string'],
            'secret_field' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')
            ->willReturnCallback(function ($s, $p) {
                return $p !== 'secret_field';
            });
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('id', $sheet->getCell('A1')->getValue());
        $this->assertEquals('name', $sheet->getCell('B1')->getValue());
        $this->assertNull($sheet->getCell('C1')->getValue());
    }

    /**
     * Test exportToExcel adds relation companion columns for uuid format.
     */
    public function testExportToExcelAddsRelationCompanionColumns(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'name' => ['type' => 'string'],
            'owner' => ['type' => 'string', 'format' => 'uuid'],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('owner', $sheet->getCell('C1')->getValue());
        $this->assertEquals('_owner', $sheet->getCell('D1')->getValue());
    }

    /**
     * Test exportToExcel adds companion columns for $ref properties.
     */
    public function testExportToExcelAddsRefCompanionColumns(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'parent' => ['type' => 'string', '$ref' => '#/components/schemas/Parent'],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('parent', $sheet->getCell('B1')->getValue());
        $this->assertEquals('_parent', $sheet->getCell('C1')->getValue());
    }

    /**
     * Test exportToExcel adds companion columns for array of uuids.
     */
    public function testExportToExcelAddsArrayUuidCompanionColumns(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'tags' => ['type' => 'array', 'items' => ['format' => 'uuid']],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('tags', $sheet->getCell('B1')->getValue());
        $this->assertEquals('_tags', $sheet->getCell('C1')->getValue());
    }

    /**
     * Test isUserAdmin returns false for null user (no metadata).
     */
    public function testExportToExcelWithNullUserSkipsMetadata(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'name' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema, [], null);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('name', $sheet->getCell('B1')->getValue());
        $this->assertNull($sheet->getCell('C1')->getValue());
    }

    /**
     * Test isUserAdmin returns true and adds metadata columns.
     */
    public function testExportToExcelWithAdminUserAddsMetadata(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'name' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $user = $this->createMock(IUser::class);
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('inGroup')->with($user)->willReturn(true);
        $this->groupManager->method('get')->with('admin')->willReturn($adminGroup);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema, [], $user);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('name', $sheet->getCell('B1')->getValue());
        $this->assertEquals('@self.created', $sheet->getCell('C1')->getValue());
    }

    /**
     * Test isUserAdmin returns false when admin group doesn't exist.
     */
    public function testExportToExcelWithNonAdminGroupNull(): void
    {
        $this->objectService->method('searchObjects')->willReturn([]);

        $user = $this->createMock(IUser::class);
        $this->groupManager->method('get')->with('admin')->willReturn(null);

        $spreadsheet = $this->exportService->exportToExcel(null, null, [], $user);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('id', $sheet->getCell('A1')->getValue());
    }

    /**
     * Test exportToExcel skips default header fields (id, uuid, uri, etc.).
     */
    public function testExportToExcelSkipsDefaultFields(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'id' => ['type' => 'integer'],
            'uuid' => ['type' => 'string'],
            'uri' => ['type' => 'string'],
            'name' => ['type' => 'string'],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('id', $sheet->getCell('A1')->getValue());
        $this->assertEquals('name', $sheet->getCell('B1')->getValue());
    }

    /**
     * Test exportToExcel with register but no schema (all schemas).
     */
    public function testExportToExcelWithRegisterAllSchemas(): void
    {
        $register = $this->createRegisterEntity(1);

        $schema1 = $this->createSchemaEntity('schema-one', []);
        $schema2 = $this->createSchemaEntity('schema-two', []);

        $this->registerMapper->method('getSchemasByRegisterId')
            ->with(1)
            ->willReturn([$schema1, $schema2]);

        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel($register);

        $this->assertEquals(2, $spreadsheet->getSheetCount());
        $this->assertEquals('schema-one', $spreadsheet->getSheet(0)->getTitle());
        $this->assertEquals('schema-two', $spreadsheet->getSheet(1)->getTitle());
    }

    /**
     * Test non-relation property without uuid/ref does not get companion column.
     */
    public function testExportToExcelNoCompanionForNonRelation(): void
    {
        $schema = $this->createSchemaEntity('test', [
            'name' => ['type' => 'string'],
            'count' => ['type' => 'integer'],
        ]);

        $this->propertyRbacHandler->method('canReadProperty')->willReturn(true);
        $this->objectService->method('searchObjects')->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, $schema);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('name', $sheet->getCell('B1')->getValue());
        $this->assertEquals('count', $sheet->getCell('C1')->getValue());
        $this->assertNull($sheet->getCell('D1')->getValue());
    }
}
