<?php

/**
 * ImportServiceMigrationPackTest
 *
 * End-to-end tests proving the migration-mapping-packs feature is wired
 * into the real import row pipeline (not an orphaned capability): CSV and
 * JSON imports with a `packId`-resolved pack run every row through
 * `MappingEngine` before the existing validate/save step, mapping errors
 * (required-missing, unresolved lookup — the literal-leak guard) exclude
 * just that row and are reported in the existing per-row error shape, and
 * `dryRun` maps + validates every row while never calling
 * `ObjectService::saveObjects()`/`saveObject()` (genuine side-effect-freedom).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class ImportServiceMigrationPackTest extends TestCase
{
    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var ObjectService&MockObject */
    private ObjectService $objectService;

    /** @var ValidateObject&MockObject */
    private ValidateObject $validateObjectHandler;

    private ImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper  = $this->createMock(SchemaMapper::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $groupManager  = $this->createMock(IGroupManager::class);

        $translationCsvCodec = $this->createMock(\OCA\OpenRegister\Service\Translation\TranslationCsvCodec::class);
        $translationCsvCodec->method('unflattenFromCsv')->willReturnCallback(static fn(array $row) => $row);

        $this->validateObjectHandler = $this->createMock(ValidateObject::class);

        $this->service = new ImportService(
            $this->schemaMapper,
            $this->objectService,
            $logger,
            $groupManager,
            $translationCsvCodec,
            $this->createMock(\OCA\OpenRegister\Db\AuditTrailMapper::class),
            new MappingEngine(),
            $this->validateObjectHandler,
            $this->createMock(\Psr\Container\ContainerInterface::class)
        );
    }

    private function createRegister(int $id): Register
    {
        $register = new Register();
        $register->setTitle('TestRegister');
        $ref  = new ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        return $register;
    }

    private function createSchema(int $id, array $properties=[]): Schema
    {
        $schema = new Schema();
        $schema->setTitle('TestSchema');
        $schema->setSlug('test-schema');
        $schema->setProperties($properties);
        $ref  = new ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        return $schema;
    }

    private function writeTempFile(string $content, string $suffix='.csv'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'migration_pack_test_').$suffix;
        file_put_contents($path, $content);
        return $path;
    }

    private function csvPack(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'            => 'csv-test-pack',
                'name'          => 'CSV Test Pack',
                'sourceFormat'  => 'csv',
                'version'       => '1.0.0',
                'idStrategy'    => ['type' => 'generate'],
                'fieldMappings' => [
                    ['source' => 'Naam', 'target' => 'title', 'required' => true, 'transform' => ['type' => 'trim']],
                ],
            ],
            $overrides
        );
    }

    public function testCsvImportWithPackMapsColumnsToTargetProperties(): void
    {
        $file = $this->writeTempFile("Naam,Actief\n  Acme  ,J\n");

        $pack = $this->csvPack(
            [
                'fieldMappings' => [
                    ['source' => 'Naam', 'target' => 'title', 'required' => true, 'transform' => ['type' => 'trim']],
                    ['source' => 'Actief', 'target' => 'active', 'transform' => ['type' => 'bool-map', 'map' => ['J' => true, 'N' => false]]],
                ],
            ]
        );

        $register = $this->createRegister(1);
        $schema   = $this->createSchema(2, ['title' => ['type' => 'string'], 'active' => ['type' => 'boolean']]);

        $captured = null;
        $this->objectService->method('saveObjects')
            ->willReturnCallback(
                function (array $objects) use (&$captured) {
                    $captured = $objects;
                    return ['saved' => [['@self' => ['id' => 'uuid-1']]], 'updated' => [], 'unchanged' => []];
                }
            );

        try {
            $result = $this->service->importFromCsv($file, $register, $schema, pack: $pack);
        } finally {
            @unlink($file);
        }

        $sheetResult = reset($result);
        $this->assertSame(1, $sheetResult['found']);
        $this->assertSame([], $sheetResult['errors']);
        $this->assertNotNull($captured);
        $this->assertSame('Acme', $captured[0]['title']);
        $this->assertTrue($captured[0]['active']);
    }

    public function testCsvImportWithPackRequiredFieldMissingExcludesRowAndReportsError(): void
    {
        // Row 2 has a "Naam" value (maps successfully); row 3's "Naam" cell is
        // empty but "Other" is present, so the row is still extracted (not
        // skipped as fully empty) and hits the required-source-missing path.
        $file = $this->writeTempFile("Naam,Other\nAcme,x\n,y\n");

        $pack = $this->csvPack();

        $register = $this->createRegister(1);
        $schema   = $this->createSchema(2, ['title' => ['type' => 'string']]);

        $captured = null;
        $this->objectService->method('saveObjects')
            ->willReturnCallback(
                function (array $objects) use (&$captured) {
                    $captured = $objects;
                    return ['saved' => [['@self' => ['id' => 'uuid-1']]], 'updated' => [], 'unchanged' => []];
                }
            );

        try {
            $result = $this->service->importFromCsv($file, $register, $schema, pack: $pack);
        } finally {
            @unlink($file);
        }

        $sheetResult = reset($result);
        $this->assertCount(1, $captured, 'Only the row with a non-empty required source should reach save');
        $this->assertSame('Acme', $captured[0]['title']);
        $this->assertNotEmpty($sheetResult['errors']);
        $this->assertSame('MigrationPackMappingError', $sheetResult['errors'][0]['type']);
        $this->assertStringContainsString('Naam', $sheetResult['errors'][0]['error']);
    }

    /**
     * Literal-leak guard: a lookup transform whose source value has no
     * matching map entry and no default must ERROR the row — the raw
     * unmapped value must never reach the saved object.
     */
    public function testCsvImportWithPackLookupLiteralLeakGuardExcludesUnresolvedRow(): void
    {
        $file = $this->writeTempFile("Naam,Type\nAcme,A\nOther,Z\n");

        $pack = $this->csvPack(
            [
                'fieldMappings' => [
                    ['source' => 'Naam', 'target' => 'title'],
                    ['source' => 'Type', 'target' => 'category', 'transform' => ['type' => 'lookup', 'map' => ['A' => 'Alpha']]],
                ],
            ]
        );

        $register = $this->createRegister(1);
        $schema   = $this->createSchema(2, ['title' => ['type' => 'string'], 'category' => ['type' => 'string']]);

        $captured = null;
        $this->objectService->method('saveObjects')
            ->willReturnCallback(
                function (array $objects) use (&$captured) {
                    $captured = $objects;
                    return ['saved' => [['@self' => ['id' => 'uuid-1']]], 'updated' => [], 'unchanged' => []];
                }
            );

        try {
            $result = $this->service->importFromCsv($file, $register, $schema, pack: $pack);
        } finally {
            @unlink($file);
        }

        $sheetResult = reset($result);
        $this->assertCount(1, $captured);
        $this->assertSame('Acme', $captured[0]['title']);
        $this->assertSame('Alpha', $captured[0]['category']);
        $this->assertNotEmpty($sheetResult['errors']);
        $this->assertSame('lookup', $this->extractTransformLabel($sheetResult['errors'][0]['error']));
    }

    private function extractTransformLabel(string $error): ?string
    {
        return (str_contains($error, 'transform: lookup') === true) ? 'lookup' : null;
    }

    public function testCsvImportDryRunNeverCallsSaveObjects(): void
    {
        $file = $this->writeTempFile("Naam\nAcme\nOther\n");
        $pack = $this->csvPack();

        $register = $this->createRegister(1);
        $schema   = $this->createSchema(2, ['title' => ['type' => 'string']]);

        $this->objectService->expects($this->never())->method('saveObjects');
        $this->objectService->expects($this->never())->method('saveObject');

        $validResult = $this->createMock(ValidationResult::class);
        $validResult->method('isValid')->willReturn(true);
        $this->validateObjectHandler->method('validateObject')->willReturn($validResult);

        try {
            $result = $this->service->importFromCsv($file, $register, $schema, pack: $pack, dryRun: true);
        } finally {
            @unlink($file);
        }

        $sheetResult = reset($result);
        $this->assertTrue($sheetResult['dryRun']);
        $this->assertSame(2, $sheetResult['validRows']);
        $this->assertSame(0, $sheetResult['invalidRows']);
        $this->assertCount(2, $sheetResult['rows']);
        $this->assertSame([], $sheetResult['created']);
        $this->assertSame([], $sheetResult['updated']);
    }

    public function testCsvImportDryRunReportsInvalidRows(): void
    {
        $file = $this->writeTempFile("Naam\nAcme\n");
        $pack = $this->csvPack();

        $register = $this->createRegister(1);
        $schema   = $this->createSchema(2, ['title' => ['type' => 'string']]);

        $invalidResult = $this->createMock(ValidationResult::class);
        $invalidResult->method('isValid')->willReturn(false);
        $this->validateObjectHandler->method('validateObject')->willReturn($invalidResult);
        $this->validateObjectHandler->method('generateErrorMessage')->willReturn('title is too short');

        $this->objectService->expects($this->never())->method('saveObjects');

        try {
            $result = $this->service->importFromCsv($file, $register, $schema, pack: $pack, dryRun: true);
        } finally {
            @unlink($file);
        }

        $sheetResult = reset($result);
        $this->assertSame(0, $sheetResult['validRows']);
        $this->assertSame(1, $sheetResult['invalidRows']);
        $this->assertFalse($sheetResult['rows'][0]['valid']);
        $this->assertSame(['title is too short'], $sheetResult['rows'][0]['errors']);
    }

    private function jsonPack(array $overrides=[]): array
    {
        return array_merge(
            [
                'id'            => 'json-test-pack',
                'name'          => 'JSON Test Pack',
                'sourceFormat'  => 'json',
                'version'       => '1.0.0',
                'idStrategy'    => ['type' => 'generate'],
                'fieldMappings' => [
                    ['source' => '/identificatie', 'target' => 'caseNumber', 'required' => true, 'transform' => ['type' => 'trim']],
                    ['source' => '/omschrijving', 'target' => 'title'],
                ],
            ],
            $overrides
        );
    }

    public function testJsonImportWithPackResolvesNestedPointerAndCreatesNewObjects(): void
    {
        $file = $this->writeTempFile(
            json_encode([['identificatie' => 'Z001', 'omschrijving' => 'A test case']]),
            '.json'
        );

        $pack     = $this->jsonPack();
        $register = $this->createRegister(1);
        $schema   = $this->createSchema(2, ['caseNumber' => ['type' => 'string'], 'title' => ['type' => 'string']]);
        $schema->setProperties(['caseNumber' => ['type' => 'string'], 'title' => ['type' => 'string']]);

        // getUuid() is an NC Entity magic accessor — mocks can't configure it;
        // use a real entity with the uuid set instead.
        $savedEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $savedEntity->setUuid('new-uuid');

        // ObjectService::saveObject()'s real signature is
        // (object, extend, register, schema, uuid, ...) — the callback
        // receives positional args in that order regardless of the
        // named-argument call style ImportService uses.
        $capturedUuid = 'not-set';
        $capturedBody = null;
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object, ?array $extend, $register, $schema, $uuid) use (&$capturedUuid, &$capturedBody, $savedEntity) {
                    $capturedUuid = $uuid;
                    $capturedBody = $object;
                    return $savedEntity;
                }
            );

        try {
            $result = $this->service->importFromJson($file, $register, $schema, pack: $pack);
        } finally {
            @unlink($file);
        }

        $this->assertNull($capturedUuid);
        $this->assertSame('Z001', $capturedBody['caseNumber']);
        $this->assertSame('A test case', $capturedBody['title']);
        $this->assertArrayNotHasKey('id', $capturedBody);
        $this->assertCount(1, $result['JSON']['created']);
    }

    public function testJsonImportWithPackIdStrategySourceFieldUpserts(): void
    {
        $file = $this->writeTempFile(
            json_encode([['identificatie' => 'Z002', 'omschrijving' => 'desc', 'uuid' => 'existing-uuid']]),
            '.json'
        );

        $pack = $this->jsonPack(['idStrategy' => ['type' => 'sourceField', 'field' => '/uuid']]);
        $register = $this->createRegister(1);
        $schema   = $this->createSchema(2, ['caseNumber' => ['type' => 'string'], 'title' => ['type' => 'string']]);

        $savedEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $savedEntity->setUuid('existing-uuid');

        $capturedUuid = 'not-set';
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object, ?array $extend, $register, $schema, $uuid) use (&$capturedUuid, $savedEntity) {
                    $capturedUuid = $uuid;
                    return $savedEntity;
                }
            );

        try {
            $result = $this->service->importFromJson($file, $register, $schema, pack: $pack);
        } finally {
            @unlink($file);
        }

        $this->assertSame('existing-uuid', $capturedUuid);
        $this->assertCount(1, $result['JSON']['updated']);
    }

    public function testJsonImportDryRunNeverCallsSaveObject(): void
    {
        $file = $this->writeTempFile(
            json_encode([['identificatie' => 'Z003', 'omschrijving' => 'desc']]),
            '.json'
        );

        $pack     = $this->jsonPack();
        $register = $this->createRegister(1);
        $schema   = $this->createSchema(2, ['caseNumber' => ['type' => 'string'], 'title' => ['type' => 'string']]);

        $validResult = $this->createMock(ValidationResult::class);
        $validResult->method('isValid')->willReturn(true);
        $this->validateObjectHandler->method('validateObject')->willReturn($validResult);

        $this->objectService->expects($this->never())->method('saveObject');
        $this->objectService->expects($this->never())->method('saveObjects');

        try {
            $result = $this->service->importFromJson($file, $register, $schema, pack: $pack, dryRun: true);
        } finally {
            @unlink($file);
        }

        $this->assertTrue($result['JSON']['dryRun']);
        $this->assertSame(1, $result['JSON']['validRows']);
        $this->assertSame([], $result['JSON']['created']);
    }
}
