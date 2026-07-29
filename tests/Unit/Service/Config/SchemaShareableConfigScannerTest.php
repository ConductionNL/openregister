<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Config\SchemaShareableConfigScanner;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class SchemaShareableConfigScannerTest extends TestCase
{

    private SchemaMapper $schemaMapper;

    private RegisterMapper $registerMapper;

    private SchemaShareableConfigScanner $scanner;

    protected function setUp(): void
    {
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->scanner        = new SchemaShareableConfigScanner(
            $this->schemaMapper,
            $this->registerMapper,
            $this->createMock(ObjectService::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    private function schema(int $id, string $slug, string $title, ?array $config): Schema
    {
        $s = new Schema();
        $s->setId($id);
        $s->setSlug($slug);
        $s->setTitle($title);
        $s->setConfiguration($config);
        return $s;
    }//end schema()

    private function register(string $slug, string $title, array $schemaIds): Register
    {
        $r = new Register();
        $r->setSlug($slug);
        $r->setTitle($title);
        $r->setSchemas($schemaIds);
        return $r;
    }//end register()

    public function testUnmarkedSchemasProduceNoTypes(): void
    {
        $this->schemaMapper->method('findAll')->willReturn(
                [
                    $this->schema(1, 'thing', 'Thing', null),
                    $this->schema(2, 'other', 'Other', ['x-openregister-shareable' => false]),
                ]
                );
        $this->registerMapper->method('findAll')->willReturn(
                [
                    $this->register('reg', 'Reg', [1, 2]),
                ]
                );

        $this->assertSame([], $this->scanner->scan());
    }//end testUnmarkedSchemasProduceNoTypes()

    public function testBooleanMarkerDerivesDefaults(): void
    {
        $this->schemaMapper->method('findAll')->willReturn(
                [
                    $this->schema(1, 'casetype', 'Case type', ['x-openregister-shareable' => true]),
                ]
                );
        $this->registerMapper->method('findAll')->willReturn(
                [
                    $this->register('procest', 'Procest', [1]),
                ]
                );

        $types = $this->scanner->scan();
        $this->assertCount(1, $types);
        $this->assertSame('procest.casetype', $types[0]->getId());
        $this->assertSame('procest-casetype', $types[0]->getTopic());
        $this->assertSame('Case type (Procest)', $types[0]->getDisplayName());
    }//end testBooleanMarkerDerivesDefaults()

    public function testObjectMarkerRefinesIdentity(): void
    {
        $this->schemaMapper->method('findAll')->willReturn(
                [
                    $this->schema(3, 'pack', 'Pack', ['x-openregister-shareable' => ['id' => 'shillinq.pack', 'topic' => 'shillinq-payroll', 'name' => 'Payroll pack']]),
                ]
                );
        $this->registerMapper->method('findAll')->willReturn(
                [
                    $this->register('shillinq', 'Shillinq', [3]),
                ]
                );

        $types = $this->scanner->scan();
        $this->assertCount(1, $types);
        $this->assertSame('shillinq.pack', $types[0]->getId());
        $this->assertSame('shillinq-payroll', $types[0]->getTopic());
        $this->assertSame('Payroll pack', $types[0]->getDisplayName());
    }//end testObjectMarkerRefinesIdentity()

    public function testOnlyRegistersHoldingTheSchemaProduceTypes(): void
    {
        $this->schemaMapper->method('findAll')->willReturn(
                [
                    $this->schema(1, 'casetype', 'Case type', ['x-openregister-shareable' => true]),
                ]
                );
        $this->registerMapper->method('findAll')->willReturn(
                [
                    $this->register('procest', 'Procest', [1]),
                    $this->register('unrelated', 'Unrelated', [9]),
                ]
                );

        $types = $this->scanner->scan();
        $this->assertCount(1, $types);
        $this->assertSame('procest.casetype', $types[0]->getId());
    }//end testOnlyRegistersHoldingTheSchemaProduceTypes()
}//end class
