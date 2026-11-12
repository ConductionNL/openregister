<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaChangeLog;
use OCA\OpenRegister\Service\Schema\IncomingDefinition;
use OCA\OpenRegister\Service\Schema\SchemaChangeRecord;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * How an import classifies a schema change and writes it to the changelog.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportedSchemaChangeLog
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Schema\SchemaChangeSet
 */
class ImportedSchemaChangeLogTest extends TestCase {

	/**
	 * A stored schema.
	 *
	 * @return Schema
	 */
	private function stored(): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setVersion('1.2.3');
		$schema->setProperties(['stored' => ['type' => 'string']]);
		$schema->setRequired(['stored']);

		return $schema;
	}//end stored()

	/**
	 * A change set with changes in it.
	 *
	 * @return SchemaChangeSet
	 */
	private function changes(): SchemaChangeSet {
		return new SchemaChangeSet(changes: [['type' => 'added']], classification: 'minor', bump: 'minor');
	}//end changes()

	/**
	 * Without a versioning service nothing is classified.
	 *
	 * @return void
	 */
	public function testWithoutAVersioningServiceNothingIsClassified(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$log = new ImportedSchemaChangeLog(versioning: null, logger: $logger, appId: null);

		$this->assertNull($log->classify(existing: $this->stored(), data: ['properties' => []]));
	}//end testWithoutAVersioningServiceNothingIsClassified()

	/**
	 * An import that carries no definition is not classified.
	 *
	 * @return void
	 */
	public function testAnImportWithoutADefinitionIsNotClassified(): void {
		$versioning = $this->createMock(SchemaVersioningService::class);
		$versioning->expects($this->never())->method('classify');
		$log = new ImportedSchemaChangeLog(versioning: $versioning, logger: $this->createMock(LoggerInterface::class), appId: null);

		$this->assertNull($log->classify(existing: $this->stored(), data: ['title' => 'x']));
	}//end testAnImportWithoutADefinitionIsNotClassified()

	/**
	 * What the import leaves out is taken from the stored schema.
	 *
	 * @return void
	 */
	public function testPropertiesAloneAreClassifiedWithTheStoredRequiredList(): void {
		$stored = $this->stored();
		$changes = $this->changes();
		$versioning = $this->createMock(SchemaVersioningService::class);
		$versioning->expects($this->once())->method('classify')
			->with($stored, new IncomingDefinition(['properties' => ['new' => []], 'required' => ['stored']]))
			->willReturn($changes);
		$log = new ImportedSchemaChangeLog(versioning: $versioning, logger: $this->createMock(LoggerInterface::class), appId: null);

		$this->assertSame($changes, $log->classify(existing: $stored, data: ['properties' => ['new' => []]]));
	}//end testPropertiesAloneAreClassifiedWithTheStoredRequiredList()

	/**
	 * A required list alone is classified with the stored properties.
	 *
	 * @return void
	 */
	public function testARequiredListAloneIsClassifiedWithTheStoredProperties(): void {
		$stored = $this->stored();
		$versioning = $this->createMock(SchemaVersioningService::class);
		$versioning->expects($this->once())->method('classify')
			->with($stored, new IncomingDefinition(['properties' => ['stored' => ['type' => 'string']], 'required' => []]))
			->willReturn($this->changes());
		$log = new ImportedSchemaChangeLog(versioning: $versioning, logger: $this->createMock(LoggerInterface::class), appId: null);

		$log->classify(existing: $stored, data: ['required' => []]);
	}//end testARequiredListAloneIsClassifiedWithTheStoredProperties()

	/**
	 * A classification that fails leaves the import unclassified, with a warning.
	 *
	 * @return void
	 */
	public function testAFailedClassificationIsLoggedAndLeavesTheImportUnclassified(): void {
		$versioning = $this->createMock(SchemaVersioningService::class);
		$versioning->method('classify')->willThrowException(new RuntimeException('broken'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[ImportHandler] Could not classify an imported schema change: broken',
			$this->callback(static fn (array $context): bool => $context['schema_id'] === 12)
		);
		$log = new ImportedSchemaChangeLog(versioning: $versioning, logger: $logger, appId: null);

		$this->assertNull($log->classify(existing: $this->stored(), data: ['properties' => []]));
	}//end testAFailedClassificationIsLoggedAndLeavesTheImportUnclassified()

	/**
	 * A change is written with the app that imported it as its origin.
	 *
	 * @return void
	 */
	public function testAChangeIsWrittenNamingTheImportingApp(): void {
		$changes = $this->changes();
		$versioning = $this->createMock(SchemaVersioningService::class);
		$versioning->expects($this->once())->method('recordChange')->with(12, new SchemaChangeRecord($changes, false, '1.2.3', 'configuration import of dossiq'));
		$log = new ImportedSchemaChangeLog(versioning: $versioning, logger: $this->createMock(LoggerInterface::class), appId: 'dossiq');

		$log->record(schema: $this->stored(), changeSet: $changes);
	}//end testAChangeIsWrittenNamingTheImportingApp()

	/**
	 * An import no app made has a plain origin.
	 *
	 * @return void
	 */
	public function testAChangeWithoutAnAppHasAPlainOrigin(): void {
		$changes = $this->changes();
		$versioning = $this->createMock(SchemaVersioningService::class);
		$versioning->expects($this->once())->method('recordChange')->with(12, new SchemaChangeRecord($changes, false, '1.2.3', 'configuration import'));
		$log = new ImportedSchemaChangeLog(versioning: $versioning, logger: $this->createMock(LoggerInterface::class), appId: null);

		$log->record(schema: $this->stored(), changeSet: $changes);
	}//end testAChangeWithoutAnAppHasAPlainOrigin()

	/**
	 * Nothing classified, or nothing changed, writes nothing.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWithoutAChange(): void {
		$versioning = $this->createMock(SchemaVersioningService::class);
		$versioning->expects($this->never())->method('recordChange');
		$log = new ImportedSchemaChangeLog(versioning: $versioning, logger: $this->createMock(LoggerInterface::class), appId: 'dossiq');

		$log->record(schema: $this->stored(), changeSet: null);
		$log->record(schema: $this->stored(), changeSet: new SchemaChangeSet(changes: [], classification: 'none', bump: 'none'));
	}//end testNothingIsWrittenWithoutAChange()

	/**
	 * Without a versioning service a change writes nothing and does not fail.
	 *
	 * @return void
	 */
	public function testWithoutAVersioningServiceNothingIsWritten(): void {
		$log = new ImportedSchemaChangeLog(versioning: null, logger: $this->createMock(LoggerInterface::class), appId: 'dossiq');

		$log->record(schema: $this->stored(), changeSet: $this->changes());
		$this->addToAssertionCount(1);
	}//end testWithoutAVersioningServiceNothingIsWritten()
}//end class
