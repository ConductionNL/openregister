<?php

/**
 * Refusing an app import that meets a slug held by several registers or
 * schemas: the error message names the app, the slug and every duplicate
 * row, it is logged, and a failing lookup is described rather than thrown.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Configuration\ImportDuplicates;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ImportDuplicates.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportDuplicates
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\MagicMappingOptions
 */
class ImportDuplicatesTest extends TestCase {

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private LoggerInterface&MockObject $logger;

	private ImportDuplicates $duplicates;

	protected function setUp(): void {
		$this->registers  = $this->createMock(RegisterMapperInterface::class);
		$this->schemas    = $this->createMock(SchemaMapperInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->duplicates = new ImportDuplicates($this->registers, $this->schemas, $this->logger);
	}//end setUp()

	private function register(int $id, string $slug, ?string $created = null): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setUuid('uuid-'.$id);
		$register->setTitle('Register '.$id);
		if ($created !== null) {
			$register->setCreated(new DateTime($created));
		}

		return $register;
	}//end register()

	public function testTheSourceNamesAppAndVersion(): void {
		$this->assertSame("'dossiq' (version 1.2.0)", ImportDuplicates::source('dossiq', '1.2.0'));
		$this->assertSame("'unknown' (version unknown)", ImportDuplicates::source(null, null));
	}//end testTheSourceNamesAppAndVersion()

	public function testEveryRowSharingTheSlugIsDescribedCaseInsensitively(): void {
		$this->registers->method('findAll')->willReturn(
			[
				$this->register(1, 'Zaken', '2026-01-02 03:04:05'),
				$this->register(2, 'other'),
				$this->register(3, 'zaken'),
			]
		);

		$this->assertSame(
			"ID: 1, UUID: uuid-1, Title: 'Register 1', Created: 2026-01-02 03:04:05; ID: 3, UUID: uuid-3, Title: 'Register 3', Created: unknown",
			$this->duplicates->describeRegisters('ZAKEN')
		);
	}//end testEveryRowSharingTheSlugIsDescribedCaseInsensitively()

	public function testASingleMatchHasNoDuplicateDetail(): void {
		$this->registers->method('findAll')->willReturn([$this->register(1, 'zaken'), $this->register(2, 'other')]);

		$this->assertSame('Unable to retrieve detailed duplicate information', $this->duplicates->describeRegisters('zaken'));
	}//end testASingleMatchHasNoDuplicateDetail()

	public function testAFailingLookupIsDescribedNotThrown(): void {
		$this->schemas->method('findAll')->willThrowException(new Exception('db gone'));

		$this->assertSame('Unable to retrieve duplicate information: db gone', $this->duplicates->describeSchemas('case'));
	}//end testAFailingLookupIsDescribedNotThrown()

	public function testSchemasAreDescribedFromTheSchemaMapper(): void {
		$one = new Schema();
		$one->setId(7);
		$one->setSlug('case');
		$two = new Schema();
		$two->setId(8);
		$two->setSlug('case');
		$this->schemas->expects($this->once())->method('findAll')->willReturn([$one, $two]);
		$this->registers->expects($this->never())->method('findAll');

		$described = $this->duplicates->describeSchemas('case');

		$this->assertStringStartsWith('ID: 7, UUID: ', $described);
		$this->assertStringContainsString('; ID: 8, UUID: ', $described);
	}//end testSchemasAreDescribedFromTheSchemaMapper()

	public function testADuplicateRegisterIsRefusedAndLogged(): void {
		$this->registers->method('findAll')->willReturn([$this->register(1, 'zaken'), $this->register(2, 'zaken')]);
		$expected = "Duplicate register detected during import from app 'dossiq' (version 1.0). "
			."Register with slug 'zaken' has multiple entries in the database: "
			."ID: 1, UUID: uuid-1, Title: 'Register 1', Created: unknown; ID: 2, UUID: uuid-2, Title: 'Register 2', Created: unknown. "
			.'Please resolve this by removing duplicate entries or updating the register slugs to be unique. '
			.'You can identify duplicates by checking registers with the same slug, uuid, or id.';
		$this->logger->expects($this->once())->method('error')->with('[ImportHandler] '.$expected, $this->anything());

		$this->expectException(Exception::class);
		$this->expectExceptionMessage($expected);

		$this->duplicates->refuseRegister('zaken', "'dossiq' (version 1.0)");
	}//end testADuplicateRegisterIsRefusedAndLogged()

	public function testADuplicateSchemaIsRefusedAsASchema(): void {
		$this->schemas->method('findAll')->willReturn([]);
		$this->logger->expects($this->once())->method('error');

		$this->expectExceptionMessage(
			"Duplicate schema detected during import from app 'x' (version y). Schema with slug 'case' has multiple entries in the database: "
			.'Unable to retrieve detailed duplicate information. Please resolve this by removing duplicate entries or updating the schema '
		);

		$this->duplicates->refuseSchema('case', "'x' (version y)");
	}//end testADuplicateSchemaIsRefusedAsASchema()
}//end class
