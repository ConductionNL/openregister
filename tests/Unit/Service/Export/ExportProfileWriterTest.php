<?php

/**
 * Unit tests for ExportProfileWriter — one field order, one value mode, stated.
 *
 * Three things are asserted here and nowhere else: the file holds the profile's
 * fields in the profile's order rather than the schema's, `rendered` resolves a
 * relation and a code list value while `stored` leaves both alone, and the file
 * says which of the two produced it.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Export\ExportProfileWriter;
use OCA\OpenRegister\Service\Export\ExportValueRenderer;
use OCA\OpenRegister\Service\Object\CacheHandler;
use PHPUnit\Framework\TestCase;

final class ExportProfileWriterTest extends TestCase {
	private const RELATED_UUID = '2f1c1b2e-6a0a-4b8e-9f1a-1d2c3b4a5e6f';

	private function writer(array $names = []): ExportProfileWriter {
		$cache = $this->createMock(CacheHandler::class);
		$cache->method('getMultipleObjectNames')->willReturn($names);

		return new ExportProfileWriter($cache, new ExportValueRenderer());
	}//end writer()

	private function profile(string $mode, array $fields, string $format = 'csv'): ExportProfile {
		$profile = new ExportProfile();
		$profile->setUuid('profile-uuid-1');
		$profile->setName('Maandelijkse aanlevering');
		$profile->setValueMode($mode);
		$profile->setFormat($format);
		$profile->setFields((string)json_encode($fields));

		return $profile;
	}//end profile()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-1');
		$object->setName('Zaak 1');
		$object->setObject(
			[
				'zaaknummer' => 'Z-001',
				'status' => 'afgerond',
				'behandelaar' => self::RELATED_UUID,
				'geopend' => '2026-03-04T09:15:00Z',
				'spoed' => true,
			]
		);

		return $object;
	}//end object()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('zaken');
		$schema->setProperties(
			[
				'status' => [
					'type' => 'string',
					'enum' => ['open', 'afgerond'],
					'enumNames' => ['Open', 'Afgerond'],
				],
			]
		);

		return $schema;
	}//end schema()

	public function testTheMonthlyAanleveringHasTheProfilesShapeNotTheSchemas(): void {
		// The profile names four fields in an order the schema does not use, and
		// omits two the object carries. The file follows the profile.
		$written = $this->writer()->write(
			$this->profile(ExportProfile::MODE_STORED, ['geopend', 'zaaknummer', 'status', 'spoed']),
			[$this->object()],
			$this->schema()
		);

		$lines = explode("\n", $written['bytes']);

		self::assertSame('"geopend","zaaknummer","status","spoed"', $lines[1]);
		self::assertSame('"2026-03-04T09:15:00Z","Z-001","afgerond","true"', $lines[2]);
		self::assertSame(1, $written['rowCount']);
	}//end testTheMonthlyAanleveringHasTheProfilesShapeNotTheSchemas()

	public function testAStoredExportKeepsTheCodesAndTheRawTimestamp(): void {
		$written = $this->writer(['x' => 'y'])->write(
			$this->profile(ExportProfile::MODE_STORED, ['status', 'behandelaar', 'geopend']),
			[$this->object()],
			$this->schema()
		);

		$row = explode("\n", $written['bytes'])[2];

		self::assertStringContainsString('"afgerond"', $row);
		self::assertStringContainsString('"' . self::RELATED_UUID . '"', $row);
		self::assertStringContainsString('"2026-03-04T09:15:00Z"', $row);
	}//end testAStoredExportKeepsTheCodesAndTheRawTimestamp()

	public function testARenderedExportResolvesRelationsLabelsAndDates(): void {
		$written = $this->writer([self::RELATED_UUID => 'Ayse Demir'])->write(
			$this->profile(ExportProfile::MODE_RENDERED, ['status', 'behandelaar', 'geopend', 'spoed']),
			[$this->object()],
			$this->schema()
		);

		$row = explode("\n", $written['bytes'])[2];

		self::assertStringContainsString('"Afgerond"', $row);
		self::assertStringContainsString('"Ayse Demir"', $row);
		self::assertStringNotContainsString(self::RELATED_UUID, $row);
		self::assertStringContainsString('"2026-03-04 09:15:00"', $row);
		self::assertStringContainsString('"yes"', $row);
	}//end testARenderedExportResolvesRelationsLabelsAndDates()

	public function testTheCsvFileNamesItsValueMode(): void {
		$written = $this->writer()->write(
			$this->profile(ExportProfile::MODE_RENDERED, ['zaaknummer']),
			[$this->object()],
			$this->schema()
		);

		$first = explode("\n", $written['bytes'])[0];

		self::assertStringStartsWith(ExportProfileWriter::CSV_METADATA_PREFIX, $first);
		self::assertStringContainsString('valueMode=rendered', $first);
		self::assertStringContainsString('profileUuid=profile-uuid-1', $first);
		self::assertSame(ExportProfile::MODE_RENDERED, $written['metadata']['valueMode']);
	}//end testTheCsvFileNamesItsValueMode()

	public function testTheJsonFileNamesItsValueModeInTheEnvelope(): void {
		$written = $this->writer()->write(
			$this->profile(ExportProfile::MODE_STORED, ['zaaknummer', 'status'], 'json'),
			[$this->object()],
			$this->schema()
		);

		$decoded = json_decode($written['bytes'], true);

		self::assertSame(ExportProfile::MODE_STORED, $decoded['export']['valueMode']);
		self::assertSame(['zaaknummer', 'status'], $decoded['export']['fields']);
		self::assertSame(['zaaknummer' => 'Z-001', 'status' => 'afgerond'], $decoded['results'][0]);
	}//end testTheJsonFileNamesItsValueModeInTheEnvelope()

	public function testAFieldTheObjectDoesNotCarryIsAnEmptyCellNotAMissingColumn(): void {
		// A column that disappears when one row lacks a value is what breaks a
		// receiving system that reads by position.
		$written = $this->writer()->write(
			$this->profile(ExportProfile::MODE_STORED, ['zaaknummer', 'nietbestaand', 'status']),
			[$this->object()],
			$this->schema()
		);

		self::assertSame('"Z-001","","afgerond"', explode("\n", $written['bytes'])[2]);
	}//end testAFieldTheObjectDoesNotCarryIsAnEmptyCellNotAMissingColumn()

	public function testMetadataFieldsAreAddressedWithTheSelfPrefix(): void {
		$written = $this->writer()->write(
			$this->profile(ExportProfile::MODE_STORED, ['@self.name', 'zaaknummer']),
			[$this->object()],
			$this->schema()
		);

		self::assertSame('"Zaak 1","Z-001"', explode("\n", $written['bytes'])[2]);
	}//end testMetadataFieldsAreAddressedWithTheSelfPrefix()

	public function testTheWholeSetOpeningCarriesTheMetadataAndTheHeader(): void {
		$opening = $this->writer()->csvOpeningFor($this->profile(ExportProfile::MODE_STORED, ['zaaknummer', 'status']));
		$lines = explode("\n", $opening);

		self::assertStringStartsWith(ExportProfileWriter::CSV_METADATA_PREFIX, $lines[0]);
		self::assertSame('"zaaknummer","status"', $lines[1]);
	}//end testTheWholeSetOpeningCarriesTheMetadataAndTheHeader()

	public function testTheWholeSetLineIsTheRowAloneSoItCanBeAppended(): void {
		$line = $this->writer()->csvLineFor(
			$this->profile(ExportProfile::MODE_STORED, ['zaaknummer', 'status']),
			$this->object(),
			$this->schema()
		);

		self::assertSame("\"Z-001\",\"afgerond\"\n", $line);
	}//end testTheWholeSetLineIsTheRowAloneSoItCanBeAppended()

	public function testACellContainingAQuoteIsEscapedRatherThanBreakingTheRow(): void {
		$object = $this->object();
		$object->setObject(['zaaknummer' => 'Z-"001"', 'status' => 'open']);

		$written = $this->writer()->write(
			$this->profile(ExportProfile::MODE_STORED, ['zaaknummer']),
			[$object],
			$this->schema()
		);

		self::assertSame('"Z-""001"""', explode("\n", $written['bytes'])[2]);
	}//end testACellContainingAQuoteIsEscapedRatherThanBreakingTheRow()
}//end class
