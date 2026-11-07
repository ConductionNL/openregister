<?php

/**
 * Golden test for ZaaktypeCaseSkeletonMapper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for ZaaktypeCaseSkeletonMapper::map().
 *
 * The full result (definition and report, order included) of each zaaktype
 * document is compared with fixtures/zaaktype-skeleton-golden.json. Set
 * UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper
 * @covers \OCA\OpenRegister\Service\Case\ZaaktypeSkeletonDraft
 * @covers \OCA\OpenRegister\Service\Case\ZaaktypeStageBuilder
 * @covers \OCA\OpenRegister\Service\Case\ZaaktypeRoleMapper
 */
class ZaaktypeCaseSkeletonMapperGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/zaaktype-skeleton-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$full = [
			'url' => 'https://ztc.example/zaaktypen/1',
			'identificatie' => 'ZT-1',
			'omschrijving' => 'Omgevingsvergunning',
			'versiedatum' => '2024-01-01',
			'concept' => false,
			'doorlooptijd' => ' P8W ',
			'servicenorm' => 'P6W',
			'statustypen' => [
				['volgnummer' => 3, 'omschrijving' => 'Besluit genomen', 'informeren' => true, 'statustekst' => ''],
				'junk',
				['omschrijving' => 'Zonder nummer', 'toelichting' => 'x'],
				['volgnummer' => '1', 'omschrijving' => 'Ontvangen', 'statustekst' => 'Uw aanvraag is ontvangen', 'checklistitemStatustype' => []],
				['volgnummer' => 'twee', 'omschrijving' => '!!!'],
				['volgnummer' => 2, 'doorlooptijd' => 'P1W'],
				['volgnummer' => 2, 'omschrijving' => str_repeat('Lange omschrijving ', 6)],
			],
			'roltypen' => [
				['omschrijving' => 'Initiator', 'omschrijvingGeneriek' => 'initiator'],
				'junk',
				['omschrijving' => '  ', 'omschrijvingGeneriek' => 'behandelaar'],
				['omschrijving' => ' Vergunningverlener ', 'omschrijvingGeneriek' => ' behandelaar '],
				['omschrijving' => 'Tweede behandelaar', 'omschrijvingGeneriek' => 'behandelaar'],
				['omschrijving' => 'Zonder generiek'],
			],
			'resultaattypen' => [
				['omschrijving' => 'Verleend', 'archiefnominatie' => 'blijvend_bewaren', 'archiefactietermijn' => 'P20Y'],
				['omschrijving' => 'Geweigerd'],
				'junk',
				['omschrijving' => ''],
				[
					'omschrijving' => 'Ingetrokken',
					'selectielijstklasse' => 'https://x',
					'brondatumArchiefprocedure' => ['afleidingswijze' => 'afgehandeld'],
					'other' => 1,
				],
			],
			'trefwoorden' => ['bouw'],
			'vertrouwelijkheidaanduiding' => 'openbaar',
			'leeg' => '',
			'niets' => null,
			'lijst' => [],
			'nul' => 0,
			7 => 'numeric element',
		];

		return [
			'empty document' => [[]],
			'identity only' => [['identificatie' => 'ZT-2', 'uuid' => 'u', 'catalogus' => 'c']],
			'url fallback to identificatie' => [['identificatie' => 'ZT-3', 'statustypen' => [['volgnummer' => 1]]]],
			'scalar collections' => [['omschrijving' => 'X', 'statustypen' => 'x', 'roltypen' => 'x', 'resultaattypen' => 'x']],
			'empty collections' => [['statustypen' => [], 'roltypen' => [], 'resultaattypen' => [], 'doorlooptijd' => '  ', 'servicenorm' => '']],
			'roles without behandelaar' => [
				[
					'roltypen' => [['omschrijving' => 'Adviseur', 'omschrijvingGeneriek' => 'adviseur']],
					'statustypen' => [['volgnummer' => 1, 'omschrijving' => 'Een']],
				],
			],
			'only junk statuses' => [['statustypen' => ['a', 3, null]]],
			'only invalid results' => [['resultaattypen' => ['a', ['omschrijving' => ' ']]]],
			'null archival terms' => [['resultaattypen' => [['omschrijving' => ' Verleend ', 'archiefnominatie' => null, 'selectielijstklasse' => 0]]]],
			'numeric volgnummer strings and ties' => [
				[
					'statustypen' => [
						['volgnummer' => '10', 'omschrijving' => 'Tien'],
						['volgnummer' => 9.5, 'omschrijving' => 'Negen'],
						['volgnummer' => 9, 'omschrijving' => 'Ook negen'],
						['volgnummer' => null, 'omschrijving' => 'Leeg'],
					],
				],
			],
			'full' => [$full],
		];
	}//end cases()

	/**
	 * Compare the mapping of one document with the recording.
	 *
	 * @param array<string, mixed> $zaaktype The zaaktype document.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(array $zaaktype): void {
		$name   = $this->dataName();
		$actual = (new ZaaktypeCaseSkeletonMapper())->map($zaaktype);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testMatchesGolden()
}//end class
