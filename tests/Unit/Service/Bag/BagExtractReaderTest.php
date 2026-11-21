<?php
/**
 * Unit tests for BagExtractReader — the rows of a BAG extract file.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Bag
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Bag;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Bag\BagExtractReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BagExtractReader::class)]
final class BagExtractReaderTest extends TestCase {

	/**
	 * Files written by a test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
	}

	private function file(string $content): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'bag');
		file_put_contents($path, $content);
		$this->files[] = $path;
		return $path;
	}

	private function read(string $content): array {
		return iterator_to_array((new BagExtractReader())->rows($this->file($content)), false);
	}

	public function testASemicolonCsvWithPdokColumnNamesMapsToSchemaProperties(): void {
		$rows = $this->read(
			"\xEF\xBB\xBFidentificatie;huisnummer;huisletter;toevoeging;postcode;openbare_ruimte_naam;woonplaats_naam;adresseerbaar_object_identificatie\n"
			. "0599200000001234;12;;B;2611 ab;Lindelaan;Zuiddrecht;0599010000005678\n"
		);

		$this->assertSame(
			[[
				'identificatie' => '0599200000001234',
				'huisnummer' => '12',
				'huisnummertoevoeging' => 'B',
				'postcode' => '2611 ab',
				'openbareRuimteNaam' => 'Lindelaan',
				'woonplaatsNaam' => 'Zuiddrecht',
				'adresseerbaarObject' => '0599010000005678',
			]],
			$rows
		);
	}

	public function testACommaCsvWithSchemaNamesSkipsRowsWithoutAnIdOrOfTheWrongWidth(): void {
		$rows = $this->read(
			"identificatie,huisnummer,postcode,openbareRuimteNaam\n"
			. "0599200000000001,1,2611AB,Lindelaan\n"
			. ",2,2611AB,Lindelaan\n"
			. "0599200000000003,3\n"
			. "\n"
			. "0599200000000004,4,2611AB,\"Laan, de\"\n"
		);

		$this->assertSame(['0599200000000001', '0599200000000004'], array_column($rows, 'identificatie'));
		$this->assertSame('Laan, de', $rows[1]['openbareRuimteNaam']);
	}

	public function testAFeatureCollectionContributesEachFeaturesProperties(): void {
		$rows = $this->read(
			json_encode(
				[
					'type' => 'FeatureCollection',
					'features' => [
						['type' => 'Feature', 'properties' => ['identificatie' => '0599200000000001', 'huisnummer' => 12, 'status' => 'Naamgeving ingetrokken']],
						['type' => 'Feature', 'properties' => ['huisnummer' => 13]],
					],
				],
				JSON_PRETTY_PRINT
			)
		);

		$this->assertSame([['identificatie' => '0599200000000001', 'huisnummer' => 12, 'status' => 'Naamgeving ingetrokken']], $rows);
	}

	public function testNewlineDelimitedJsonIsReadOneObjectPerLine(): void {
		$rows = $this->read(
			'{"identificatie":"0599200000000001","straat":"Lindelaan"}' . "\n"
			. "not json\n"
			. '{"nummeraanduiding_identificatie":"0599200000000002","woonplaats":"Zuiddrecht"}' . "\n"
		);

		$this->assertSame(
			[
				['identificatie' => '0599200000000001', 'openbareRuimteNaam' => 'Lindelaan'],
				['identificatie' => '0599200000000002', 'woonplaatsNaam' => 'Zuiddrecht'],
			],
			$rows
		);
	}

	public function testAJsonListOfRecordsIsRead(): void {
		$rows = $this->read('[{"identificatie":1234567890123456,"huisnummer":"7"}]');

		$this->assertSame([['identificatie' => '1234567890123456', 'huisnummer' => '7']], $rows);
	}

	public function testBrokenJsonIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not valid JSON');

		$this->read('{"features": [');
	}

	public function testAMissingFileIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be read: no-such-extract.csv');

		iterator_to_array((new BagExtractReader())->rows(sys_get_temp_dir() . '/no-such-extract.csv'));
	}

	public function testTheFirstColumnOfTwoForOnePropertyWins(): void {
		$this->assertSame(
			['identificatie' => '0599200000000001', 'openbareRuimteNaam' => 'Lindelaan'],
			BagExtractReader::mapRecord(['identificatie' => '0599200000000001', 'straat' => 'Lindelaan', 'openbare_ruimte_naam' => 'Kerkstraat', 'unknown' => 'x'])
		);
		$this->assertNull(BagExtractReader::mapRecord(['identificatie' => ['nested']]));
	}

	public function testTheTenThousandRowSampleIsReadWhole(): void {
		$rows = iterator_to_array((new BagExtractReader())->rows(__DIR__ . '/../../../fixtures/bag/nummeraanduiding-sample-10000.csv'), false);

		$this->assertCount(10000, $rows);
		$this->assertCount(10000, array_unique(array_column($rows, 'identificatie')));
		$this->assertSame('Lindelaan', $rows[0]['openbareRuimteNaam']);
	}
}
