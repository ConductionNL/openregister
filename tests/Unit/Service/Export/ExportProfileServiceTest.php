<?php

/**
 * Unit tests for ExportProfileService — the verb, the run and the record.
 *
 * The case worth keeping: `run()` takes the uid it exports as, rather than
 * reading the session. That is what lets a scheduled export hold its owner's
 * access inside a background job where there is no session at all, and it is
 * the only reason the verb survives off the request path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ExportProfileMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Export\ExportAuditRecorder;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportProfileWriter;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\ExportService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ExportProfileServiceTest extends TestCase {
	private ExportRightService&MockObject $rights;

	private ExportAuditRecorder&MockObject $recorder;

	private ExportService&MockObject $exportService;

	private ExportProfileWriter&MockObject $writer;

	private ExportProfileMapper&MockObject $mapper;

	protected function setUp(): void {
		$this->rights = $this->createMock(ExportRightService::class);
		$this->recorder = $this->createMock(ExportAuditRecorder::class);
		$this->exportService = $this->createMock(ExportService::class);
		$this->writer = $this->createMock(ExportProfileWriter::class);
		$this->mapper = $this->createMock(ExportProfileMapper::class);
	}//end setUp()

	private function service(): ExportProfileService {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn(new Register());

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn(new Schema());

		return new ExportProfileService(
			$this->mapper,
			$registers,
			$schemas,
			$this->exportService,
			$this->writer,
			$this->rights,
			$this->recorder
		);
	}//end service()

	private function profile(): ExportProfile {
		$profile = new ExportProfile();
		$profile->setName('Maandelijkse aanlevering');
		$profile->setOwner('eigenaar-1');
		$profile->setRegisterId(7);
		$profile->setSchemaId(19);
		$profile->setFormat('csv');
		$profile->setValueMode(ExportProfile::MODE_RENDERED);
		$profile->setFields((string)json_encode(['zaaknummer']));

		return $profile;
	}//end profile()

	public function testTheRunIsCheckedAgainstTheUidItIsHandedNotTheSession(): void {
		$seen = null;
		$this->rights->method('refusalForUid')->willReturnCallback(
			function (...$args) use (&$seen): ?ExportRefusedException {
				$seen = $args[1];

				return null;
			}
		);
		$this->exportService->method('fetchExportObjects')->willReturn([]);
		$this->writer->method('write')->willReturn(['bytes' => '', 'rowCount' => 0, 'metadata' => []]);

		$this->service()->run($this->profile(), 'eigenaar-1');

		self::assertSame('eigenaar-1', $seen);
	}//end testTheRunIsCheckedAgainstTheUidItIsHandedNotTheSession()

	public function testARefusedRunThrowsAndIsRecorded(): void {
		$this->rights->method('refusalForUid')->willReturn(
			new ExportRefusedException('export-right-missing', 'no export for you', 403)
		);
		$this->exportService->expects(self::never())->method('fetchExportObjects');
		$this->recorder->expects(self::once())
			->method('recordRefused')
			->with(
				self::equalTo('Maandelijkse aanlevering'),
				self::equalTo('export-right-missing'),
				self::equalTo('no export for you'),
				self::equalTo(7),
				self::equalTo(19),
				self::equalTo('behandelaar-1')
			);

		$this->expectException(ExportRefusedException::class);

		$this->service()->run($this->profile(), 'behandelaar-1');
	}//end testARefusedRunThrowsAndIsRecorded()

	public function testACompletedRunRecordsItsRowCountAndValueMode(): void {
		$this->rights->method('refusalForUid')->willReturn(null);
		$this->exportService->method('fetchExportObjects')->willReturn([]);
		$this->writer->method('write')->willReturn(
			['bytes' => 'x', 'rowCount' => 412, 'metadata' => ['valueMode' => 'rendered']]
		);
		$this->recorder->expects(self::once())
			->method('recordCompleted')
			->with(
				self::equalTo('Maandelijkse aanlevering'),
				self::equalTo(412),
				self::equalTo('csv'),
				self::equalTo('rendered'),
				self::equalTo(7),
				self::equalTo(19),
				self::equalTo('eigenaar-1')
			);

		$written = $this->service()->run($this->profile(), 'eigenaar-1');

		self::assertSame(412, $written['rowCount']);
		self::assertStringEndsWith('.csv', $written['filename']);
		self::assertStringStartsWith('maandelijkse-aanlevering_', $written['filename']);
	}//end testACompletedRunRecordsItsRowCountAndValueMode()

	public function testAProfileWithoutFieldsIsRefusedAtCreate(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('at least one field');

		$this->service()->create(['name' => 'x', 'registerId' => 7, 'fields' => []], 'eigenaar-1');
	}//end testAProfileWithoutFieldsIsRefusedAtCreate()

	public function testAnUnknownValueModeIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('stored values or rendered');

		$this->service()->create(
			['name' => 'x', 'registerId' => 7, 'fields' => ['a'], 'valueMode' => 'pretty'],
			'eigenaar-1'
		);
	}//end testAnUnknownValueModeIsRefused()

	public function testAnUnknownFormatIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->create(
			['name' => 'x', 'registerId' => 7, 'fields' => ['a'], 'format' => 'ods'],
			'eigenaar-1'
		);
	}//end testAnUnknownFormatIsRefused()

	public function testACreatedProfileGetsAUuidAnOwnerAndItsFieldOrder(): void {
		$this->mapper->method('insert')->willReturnCallback(static fn (...$args) => $args[0]);

		$profile = $this->service()->create(
			['name' => 'Aanlevering', 'registerId' => 7, 'fields' => ['b', 'a', 'c']],
			'eigenaar-1'
		);

		self::assertNotNull($profile->getUuid());
		self::assertSame('eigenaar-1', $profile->getOwner());
		self::assertSame(['b', 'a', 'c'], $profile->getFieldsArray());
		self::assertSame(ExportProfile::MODE_STORED, $profile->getValueMode());
	}//end testACreatedProfileGetsAUuidAnOwnerAndItsFieldOrder()

	public function testSomebodyElsesProfileIsRefused(): void {
		$this->expectException(ExportRefusedException::class);

		$this->service()->assertOwnerOrAdmin($this->profile(), 'andere-gebruiker', false);
	}//end testSomebodyElsesProfileIsRefused()

	public function testAnAdministratorMayTouchAnyProfile(): void {
		$this->service()->assertOwnerOrAdmin($this->profile(), 'andere-gebruiker', true);

		self::assertTrue(true);
	}//end testAnAdministratorMayTouchAnyProfile()

	public function testAFilteredProfileIsNotAWholeSetExtractHoweverItIsFlagged(): void {
		// Both halves are required. A filtered profile flagged whole-set is a
		// report somebody mislabelled, and running it as an overnight job over
		// every register would be the wrong answer to it.
		$profile = $this->profile();
		$profile->setWholeSet(true);
		$profile->setFilters((string)json_encode(['status' => 'open']));

		self::assertFalse($profile->isWholeSet());

		$profile->setFilters(null);

		self::assertTrue($profile->isWholeSet());
	}//end testAFilteredProfileIsNotAWholeSetExtractHoweverItIsFlagged()
}//end class
