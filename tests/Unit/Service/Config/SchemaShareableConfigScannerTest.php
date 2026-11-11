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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemaShareableConfigScannerTest extends TestCase {

	private SchemaMapper $schemaMapper;

	private RegisterMapper $registerMapper;

	private SchemaShareableConfigScanner $scanner;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->scanner = new SchemaShareableConfigScanner(
			new \OCA\OpenRegister\Db\SchemaCatalog(
				$this->registerMapper,
				$this->schemaMapper,
				$this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class)
			),
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	private function schema(int $id, string $slug, string $title, ?array $config): Schema {
		$s = new Schema();
		$s->setId($id);
		$s->setSlug($slug);
		$s->setTitle($title);
		$s->setConfiguration($config);
		return $s;
	}//end schema()

	private function register(string $slug, string $title, array $schemaIds): Register {
		$r = new Register();
		$r->setSlug($slug);
		$r->setTitle($title);
		$r->setSchemas($schemaIds);
		return $r;
	}//end register()

	public function testUnmarkedSchemasProduceNoTypes(): void {
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

	public function testBooleanMarkerDerivesDefaults(): void {
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

	public function testObjectMarkerRefinesIdentity(): void {
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

	public function testOnlyRegistersHoldingTheSchemaProduceTypes(): void {
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

	public function testACatalogueFailureLogsAndYieldsNoTypes(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->equalTo('[SchemaShareableConfigScanner] Could not scan for shareable schemas: catalogue down'),
			$this->callback(static fn (array $context): bool => $context['file'] === (new \ReflectionClass(SchemaShareableConfigScanner::class))->getFileName() && is_int($context['line']))
		);
		$this->schemaMapper->method('findAll')->willReturn([$this->schema(1, 'casetype', 'Case type', ['x-openregister-shareable' => true])]);
		$this->registerMapper->method('findAll')->willThrowException(new \RuntimeException('catalogue down'));
		$scanner = new SchemaShareableConfigScanner(
			new \OCA\OpenRegister\Db\SchemaCatalog(
				$this->registerMapper,
				$this->schemaMapper,
				$this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class)
			),
			$this->createMock(ObjectService::class),
			$logger
		);

		$this->assertSame([], $scanner->scan());
	}//end testACatalogueFailureLogsAndYieldsNoTypes()

	public function testTypesFollowSchemaThenRegisterOrder(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[
				$this->schema(1, 'casetype', 'Case type', ['x-openregister-shareable' => true]),
				$this->schema(2, 'plain', 'Plain', null),
				$this->schema(3, 'pack', 'Pack', ['x-openregister-shareable' => ['topic' => 'packs']]),
			]
		);
		$this->registerMapper->method('findAll')->willReturn(
			[
				$this->register('procest', 'Procest', [1, 2, 3]),
				$this->register('shillinq', 'Shillinq', [3, 1]),
			]
		);

		$types = $this->scanner->scan();
		$this->assertSame(
			['procest.casetype', 'shillinq.casetype', 'procest.pack', 'shillinq.pack'],
			array_map(static fn ($type): string => $type->getId(), $types)
		);
		$this->assertSame(
			['procest-casetype', 'shillinq-casetype', 'packs', 'packs'],
			array_map(static fn ($type): string => $type->getTopic(), $types)
		);
		$this->assertSame(
			['Case type (Procest)', 'Case type (Shillinq)', 'Pack (Procest)', 'Pack (Shillinq)'],
			array_map(static fn ($type): string => $type->getDisplayName(), $types)
		);
	}//end testTypesFollowSchemaThenRegisterOrder()
}//end class
