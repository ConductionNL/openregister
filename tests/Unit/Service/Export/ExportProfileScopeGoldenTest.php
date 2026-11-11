<?php

/**
 * Golden coverage for what an export profile update stores as its scope.
 *
 * Pins the register id, the schema id and the filters an update writes,
 * including the null that clears a schema or the filters, and that an
 * absent key leaves the stored value alone.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ExportProfileMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Export\ExportAuditRecorder;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportProfileSteps;
use OCA\OpenRegister\Service\Export\ExportProfileStores;
use OCA\OpenRegister\Service\Export\ExportProfileValidator;
use OCA\OpenRegister\Service\Export\ExportProfileWriter;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\ExportService;
use PHPUnit\Framework\TestCase;

/**
 * Locks the scope fields an update writes.
 */
class ExportProfileScopeGoldenTest extends TestCase {

	/**
	 * A service whose mapper hands back what it is given.
	 *
	 * @return ExportProfileService The service.
	 */
	private function service(): ExportProfileService {
		$mapper = $this->createMock(ExportProfileMapper::class);
		$mapper->method('update')->willReturnArgument(0);

		return new ExportProfileService(
			new ExportProfileStores(
				$mapper,
				new SchemaCatalog(
					$this->createMock(RegisterMapper::class),
					$this->createMock(SchemaMapper::class),
					$this->createMock(MagicMapperInterface::class)
				),
			),
			new ExportProfileSteps(
				$this->createMock(ExportService::class),
				$this->createMock(ExportProfileWriter::class),
				new ExportProfileValidator(),
			),
			$this->createMock(ExportRightService::class),
			$this->createMock(ExportAuditRecorder::class),
		);
	}//end service()

	/**
	 * A stored profile with a schema and filters.
	 *
	 * @return ExportProfile The profile.
	 */
	private function stored(): ExportProfile {
		$profile = new ExportProfile();
		$profile->setName('Aanlevering');
		$profile->setOwner('eigenaar-1');
		$profile->setRegisterId(7);
		$profile->setSchemaId(19);
		$profile->setFilters('{"status":"open"}');
		$profile->setFormat('csv');
		$profile->setValueMode(ExportProfile::MODE_STORED);
		$profile->setFields((string)json_encode(['zaaknummer']));

		return $profile;
	}//end stored()

	/**
	 * Submitted values are cast and encoded.
	 *
	 * @return void
	 */
	public function testSubmittedScopeValuesAreCastAndEncoded(): void {
		$profile = $this->service()->update(
			$this->stored(),
			['registerId' => '8', 'schemaId' => '21', 'filters' => ['a' => 1], 'wholeSet' => 1]
		);

		$this->assertSame(8, $profile->getRegisterId());
		$this->assertSame(21, $profile->getSchemaId());
		$this->assertSame('{"a":1}', $profile->getFilters());
	}//end testSubmittedScopeValuesAreCastAndEncoded()

	/**
	 * A null schema id or null filters clear them.
	 *
	 * @return void
	 */
	public function testNullClearsTheSchemaAndTheFilters(): void {
		$profile = $this->service()->update($this->stored(), ['schemaId' => null, 'filters' => null]);

		$this->assertNull($profile->getSchemaId());
		$this->assertNull($profile->getFilters());
		$this->assertSame(7, $profile->getRegisterId());
	}//end testNullClearsTheSchemaAndTheFilters()

	/**
	 * Absent keys leave the stored scope alone.
	 *
	 * @return void
	 */
	public function testAbsentKeysLeaveTheScopeAlone(): void {
		$profile = $this->service()->update($this->stored(), ['name' => 'Ander']);

		$this->assertSame(7, $profile->getRegisterId());
		$this->assertSame(19, $profile->getSchemaId());
		$this->assertSame('{"status":"open"}', $profile->getFilters());
	}//end testAbsentKeysLeaveTheScopeAlone()
}//end class
