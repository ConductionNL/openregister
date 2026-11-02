<?php

/**
 * ExportService accepts only stored Register and Schema rows behind the Kernel interfaces.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportService::class)]
#[UsesClass(StoredEntity::class)]
#[UsesClass(Schema::class)]
class ExportServiceStoredRowTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var ExportService
	 */
	private ExportService $service;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new ExportService(
			registerMapper: $this->createMock(originalClassName: RegisterMapper::class),
			_userManager: $this->createMock(originalClassName: IUserManager::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			objectService: $this->createMock(originalClassName: ObjectService::class),
			cacheHandler: $this->createMock(originalClassName: CacheHandler::class),
			propertyRbacHandler: $this->createMock(originalClassName: PropertyRbacHandler::class),
			translationHandler: $this->createMock(originalClassName: TranslationHandler::class)
		);
	}//end setUp()

	/**
	 * The JSON export refuses a schema that is not a stored row.
	 *
	 * @return void
	 */
	public function testJsonExportRefusesAForeignSchema(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Only a stored Schema row is accepted here.');

		$this->service->exportToJson(schema: $this->createMock(originalClassName: SchemaInterface::class));
	}//end testJsonExportRefusesAForeignSchema()

	/**
	 * The JSON export refuses a register that is not a stored row.
	 *
	 * @return void
	 */
	public function testJsonExportRefusesAForeignRegister(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Only a stored Register row is accepted here.');

		$this->service->exportToJson(
			register: $this->createMock(originalClassName: RegisterInterface::class),
			schema: new Schema()
		);
	}//end testJsonExportRefusesAForeignRegister()

	/**
	 * The template spreadsheet refuses a schema that is not a stored row.
	 *
	 * @return void
	 */
	public function testTemplateSpreadsheetRefusesAForeignSchema(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Only a stored Schema row is accepted here.');

		$this->service->buildTemplateSpreadsheet(
			register: null,
			schema: $this->createMock(originalClassName: SchemaInterface::class)
		);
	}//end testTemplateSpreadsheetRefusesAForeignSchema()

	/**
	 * The template CSV refuses a schema that is not a stored row.
	 *
	 * @return void
	 */
	public function testTemplateCsvRefusesAForeignSchema(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Only a stored Schema row is accepted here.');

		$this->service->buildTemplateCsv(
			register: null,
			schema: $this->createMock(originalClassName: SchemaInterface::class)
		);
	}//end testTemplateCsvRefusesAForeignSchema()
}//end class
