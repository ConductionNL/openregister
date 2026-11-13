<?php

/**
 * The stored-profile side of ExportProfileService: find, list (all for an
 * administrator, own for anyone else) and delete through the mapper; the
 * name and description an update writes; and a run whose register or
 * schema cannot be read, with the download named after the profile.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ExportProfileMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Export\ExportAuditRecorder;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportProfileSteps;
use OCA\OpenRegister\Service\Export\ExportProfileStores;
use OCA\OpenRegister\Service\Export\ExportProfileValidator;
use OCA\OpenRegister\Service\Export\ExportProfileWriter;
use OCA\OpenRegister\Service\Export\ExportRequest;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\ExportService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ExportProfileService.
 *
 * @covers \OCA\OpenRegister\Service\Export\ExportProfileService
 * @uses \OCA\OpenRegister\Service\Export\ExportProfileStores
 * @uses \OCA\OpenRegister\Service\Export\ExportProfileSteps
 * @uses \OCA\OpenRegister\Service\Export\ExportProfileValidator
 * @uses \OCA\OpenRegister\Service\Export\ExportRequest
 * @uses \OCA\OpenRegister\Service\Export\ProfileExportRun
 * @uses \OCA\OpenRegister\Service\Export\ProfileProjection
 * @uses \OCA\OpenRegister\Db\ExportProfile
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\MagicMappingOptions
 */
class ExportProfileServiceStoreTest extends TestCase {

	private ExportProfileMapperInterface&MockObject $mapper;

	private RegisterMapperInterface&MockObject $registers;

	private SchemaMapperInterface&MockObject $schemas;

	private ExportService&MockObject $exportService;

	private ExportProfileWriter&MockObject $writer;

	private ExportProfileService $service;

	protected function setUp(): void {
		$this->mapper        = $this->createMock(ExportProfileMapperInterface::class);
		$this->registers     = $this->createMock(RegisterMapperInterface::class);
		$this->schemas       = $this->createMock(SchemaMapperInterface::class);
		$this->exportService = $this->createMock(ExportService::class);
		$this->writer        = $this->createMock(ExportProfileWriter::class);

		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($this->registers);
		$catalog->method('schemas')->willReturn($this->schemas);

		$this->service = new ExportProfileService(
			new ExportProfileStores($this->mapper, $catalog),
			new ExportProfileSteps($this->exportService, $this->writer, new ExportProfileValidator()),
			$this->createMock(ExportRightService::class),
			$this->createMock(ExportAuditRecorder::class)
		);
	}//end setUp()

	public function testAProfileIsFoundThroughTheMapper(): void {
		$profile = new ExportProfile();
		$this->mapper->expects($this->once())->method('find')->with(14)->willReturn($profile);

		$this->assertSame($profile, $this->service->find(14));
	}//end testAProfileIsFoundThroughTheMapper()

	public function testAnAdministratorListsEveryProfile(): void {
		$all = [new ExportProfile(), new ExportProfile()];
		$this->mapper->expects($this->once())->method('findAll')->willReturn($all);
		$this->mapper->expects($this->never())->method('findByOwner');

		$this->assertSame($all, $this->service->listFor('admin', true));
	}//end testAnAdministratorListsEveryProfile()

	public function testAnyoneElseListsOnlyTheirOwnProfiles(): void {
		$own = [new ExportProfile()];
		$this->mapper->expects($this->once())->method('findByOwner')->with('alice')->willReturn($own);
		$this->mapper->expects($this->never())->method('findAll');

		$this->assertSame($own, $this->service->listFor('alice', false));
	}//end testAnyoneElseListsOnlyTheirOwnProfiles()

	public function testAProfileIsDeletedThroughTheMapper(): void {
		$profile = new ExportProfile();
		$this->mapper->expects($this->once())->method('delete')->with($profile);

		$this->service->delete($profile);
	}//end testAProfileIsDeletedThroughTheMapper()

	public function testAnUpdateRenamesAndClearsTheDescription(): void {
		$profile = new ExportProfile();
		$profile->setName('Old');
		$profile->setDescription('Old description');
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service->update($profile, ['name' => 'Monthly', 'description' => null]);

		$this->assertSame('Monthly', $updated->getName());
		$this->assertNull($updated->getDescription());
	}//end testAnUpdateRenamesAndClearsTheDescription()

	public function testAnUpdateWithoutADescriptionKeepsIt(): void {
		$profile = new ExportProfile();
		$profile->setDescription('Kept');
		$this->mapper->method('update')->willReturnArgument(0);

		$this->assertSame('Kept', $this->service->update($profile, ['name' => 'Renamed'])->getDescription());
		$this->assertSame('42', $this->service->update($profile, ['description' => 42])->getDescription());
	}//end testAnUpdateWithoutADescriptionKeepsIt()

	public function testARunWhoseRegisterAndSchemaCannotBeReadExportsUnscoped(): void {
		$profile = new ExportProfile();
		$profile->setName('*** ***');
		$profile->setRegisterId(7);
		$profile->setSchemaId(19);
		$profile->setFormat('xlsx');
		$this->registers->expects($this->once())->method('find')->with(7, false, false)->willThrowException(new DoesNotExistException('gone'));
		$this->schemas->expects($this->once())->method('find')->with(19, [], false, false)->willThrowException(new DoesNotExistException('gone'));
		$this->exportService->expects($this->once())->method('fetchExportObjects')->with(
			$this->callback(static fn (ExportRequest $request): bool => $request->register === null && $request->schema === null)
		)->willReturn([]);
		$this->writer->method('write')->willReturn(['rowCount' => 0, 'content' => '']);

		$written = $this->service->run($profile, 'alice');

		$this->assertSame(0, $written['rowCount']);
		$this->assertMatchesRegularExpression('/^export_\d{4}-\d{2}-\d{2}_\d{6}\.xlsx$/', $written['filename']);
	}//end testARunWhoseRegisterAndSchemaCannotBeReadExportsUnscoped()

	public function testTheDownloadIsNamedAfterTheProfileAsCsvByDefault(): void {
		$profile = new ExportProfile();
		$profile->setName('Monthly Delivery (Q1)');
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('find');
		$this->exportService->method('fetchExportObjects')->willReturn([]);
		$this->writer->method('write')->willReturn(['rowCount' => 3]);

		$written = $this->service->run($profile, 'alice');

		$this->assertMatchesRegularExpression('/^monthly-delivery-q1_\d{4}-\d{2}-\d{2}_\d{6}\.csv$/', $written['filename']);
	}//end testTheDownloadIsNamedAfterTheProfileAsCsvByDefault()
}//end class
