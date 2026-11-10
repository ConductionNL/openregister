<?php

/**
 * Golden test for MappingEngine.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\MigrationPack
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\MigrationPack;

use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Runs every (pack, row) pair below through mapRow() and compares the full
 * grid of data and errors (each message as its untranslated text plus its
 * parameters) with fixtures/mapping-engine-golden.json. Set UPDATE_GOLDEN=1
 * to rewrite the fixture. Dates are date-only on both sides, so the grid
 * depends neither on today's date nor on the default timezone.
 *
 * @covers \OCA\OpenRegister\Service\MigrationPack\MappingEngine
 */
class MappingEngineGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/mapping-engine-golden.json';

	/**
	 * An engine whose translations show the source text and the parameters.
	 *
	 * @return MappingEngine
	 */
	private function engine(): MappingEngine {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => $text . ' <' . json_encode($params, JSON_UNESCAPED_SLASHES) . '>'
		);
		return new MappingEngine($l10n);
	}//end engine()

	/**
	 * The source row most cases read.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(): array {
		return [
			'Naam' => '  Jan  ',
			'Leeg' => '',
			'Nul' => '0',
			'Getal' => 7,
			'Datum' => '05-03-2024',
			'Iso' => '2024-03-05',
			'Ja' => 'J',
			'Status' => 'open',
			'Voor' => 'Jan',
			'Achter' => 'Jansen',
			'Nr' => 42,
			'Adres' => ['Straat' => 'Kerkstraat', 'a/b' => 'slash', 'c~d' => 'tilde', 'Leeg' => null],
			'Lijst' => ['x', 'y'],
		];
	}//end row()

	/**
	 * Every case: field mappings, extra pack keys, and the row (null = row()).
	 *
	 * @return array<string, array{0: array<int, mixed>, 1: array<string, mixed>, 2?: array<string, mixed>}>
	 */
	private static function cases(): array {
		$map = static fn (array $transform, string $source = 'Status'): array => [
			['source' => $source, 'target' => 't', 'transform' => $transform],
		];
		return [
			'identity' => [[['source' => 'Naam', 'target' => 'naam'], ['source' => 'Getal', 'target' => 'getal']], []],
			'missing and empty optional' => [
				[['source' => 'Nope', 'target' => 'a'], ['source' => 'Leeg', 'target' => 'b'], ['source' => '', 'target' => 'c']],
				[],
			],
			'zero string is a value' => [[['source' => 'Nul', 'target' => 'nul']], []],
			'required missing' => [[['source' => 'Nope', 'target' => 'a', 'required' => true, 'transform' => ['type' => 'trim']]], []],
			'required empty' => [[['source' => 'Leeg', 'target' => 'a', 'required' => true]], []],
			'required truthy but not true' => [[['source' => 'Leeg', 'target' => 'a', 'required' => 1]], []],
			'defaults seeded and kept' => [
				[['source' => 'Leeg', 'target' => 'a'], ['source' => 'Naam', 'target' => 'b']],
				['defaults' => ['a' => 'standaard', 'b' => 'weg', 'c' => 1]],
			],
			'later mapping overwrites' => [[['source' => 'Voor', 'target' => 'x'], ['source' => 'Achter', 'target' => 'x']], []],
			'mapping without target' => [[['source' => 'Voor']], []],
			'trim' => [$map(['type' => 'trim'], 'Naam'), []],
			'trim integer' => [$map(['type' => 'trim'], 'Getal'), []],
			'const on empty source' => [[['source' => 'Leeg', 'target' => 'c', 'transform' => ['type' => 'const', 'value' => 'vast']]], []],
			'const on missing source' => [[['source' => 'Nope', 'target' => 'c', 'transform' => ['type' => 'const']]], []],
			'const required and empty' => [[['source' => 'Leeg', 'target' => 'c', 'required' => true, 'transform' => ['type' => 'const', 'value' => 1]]], []],
			'const with value' => [$map(['type' => 'const', 'value' => ['a' => 1]]), []],
			'const without value on a filled source' => [$map(['type' => 'const']), []],
			'const null value on a filled source' => [$map(['type' => 'const', 'value' => null]), []],
			'date with source format' => [$map(['type' => 'date', 'sourceFormat' => 'd-m-Y'], 'Datum'), []],
			'date with target format' => [$map(['type' => 'date', 'sourceFormat' => 'd-m-Y', 'targetFormat' => 'd/m/Y'], 'Datum'), []],
			'date free form' => [$map(['type' => 'date'], 'Iso'), []],
			'date empty source format' => [$map(['type' => 'date', 'sourceFormat' => ''], 'Iso'), []],
			'date non string source format' => [$map(['type' => 'date', 'sourceFormat' => 5], 'Iso'), []],
			'date wrong format' => [$map(['type' => 'date', 'sourceFormat' => 'Y/m/d'], 'Datum'), []],
			'bool-map hit' => [$map(['type' => 'bool-map', 'map' => ['J' => 1, 'N' => 0]], 'Ja'), []],
			'bool-map default' => [$map(['type' => 'bool-map', 'map' => ['N' => 0], 'default' => 'yes']), []],
			'bool-map null default' => [$map(['type' => 'bool-map', 'map' => [], 'default' => null]), []],
			'bool-map miss' => [$map(['type' => 'bool-map', 'map' => ['N' => 0]]), []],
			'lookup hit' => [$map(['type' => 'lookup', 'map' => ['open' => 'OPEN', 'dicht' => 'CLOSED']]), []],
			'lookup numeric key' => [$map(['type' => 'lookup', 'map' => ['7' => 'zeven']], 'Getal'), []],
			'lookup null mapped' => [$map(['type' => 'lookup', 'map' => ['open' => null], 'default' => 'x']), []],
			'lookup default' => [$map(['type' => 'lookup', 'map' => [], 'default' => 0]), []],
			'lookup miss' => [$map(['type' => 'lookup']), []],
			'concat' => [$map(['type' => 'concat', 'fields' => ['Achter', 'Nope', '/Adres/Straat']], 'Voor'), []],
			'concat separator' => [$map(['type' => 'concat', 'fields' => ['Achter'], 'separator' => ', '], 'Voor'), []],
			'concat no fields' => [$map(['type' => 'concat'], 'Voor'), []],
			'unknown type' => [$map(['type' => 'nope']), []],
			'missing type' => [$map([]), []],
			'boolean true type' => [$map(['type' => true], 'Naam'), []],
			'pointer paths' => [
				[
					['source' => '/Adres/Straat', 'target' => 'a'],
					['source' => '/Adres/a~1b', 'target' => 'b'],
					['source' => '/Adres/c~0d', 'target' => 'c'],
					['source' => '/Adres/Nope', 'target' => 'd'],
					['source' => '/Naam/x', 'target' => 'e'],
					['source' => '/Lijst/1', 'target' => 'f'],
					['source' => '/Adres/Leeg', 'target' => 'g', 'required' => true],
					['source' => '/', 'target' => 'h'],
				],
				[],
			],
			'id from source field' => [[['source' => 'Naam', 'target' => 'naam']], ['idStrategy' => ['type' => 'sourceField', 'field' => 'Nr']]],
			'id from pointer' => [[], ['idStrategy' => ['type' => 'sourceField', 'field' => '/Adres/Straat']]],
			'id source empty' => [[], ['idStrategy' => ['type' => 'sourceField', 'field' => 'Leeg']], ['Leeg' => '', 'id' => 'x']],
			'id overrides mapped id' => [[['source' => 'Voor', 'target' => 'id']], ['idStrategy' => ['type' => 'sourceField', 'field' => 'Nr']]],
			'id without field' => [[['source' => 'Voor', 'target' => 'id']], ['idStrategy' => ['type' => 'sourceField']]],
			'id generate' => [[['source' => 'Voor', 'target' => 'id']], ['idStrategy' => ['type' => 'generate', 'field' => 'Nr']]],
			'id strategy without type' => [[], ['idStrategy' => ['field' => 'Nr']]],
			'no mappings' => [[], ['fieldMappings' => null]],
			'errors and values mixed' => [
				[
					['source' => 'Nope', 'target' => 'a', 'required' => true],
					['source' => 'Voor', 'target' => 'b'],
					['source' => 'Status', 'target' => 'c', 'transform' => ['type' => 'lookup', 'map' => []]],
					['source' => 'Achter', 'target' => 'd', 'transform' => ['type' => 'trim']],
				],
				[],
			],
		];
	}//end cases()

	/**
	 * @return void
	 */
	public function testRowsMatchTheGoldenGrid(): void {
		$engine = $this->engine();
		$actual = [];
		foreach (self::cases() as $name => $case) {
			$pack = array_merge(['fieldMappings' => $case[0]], $case[1]);
			$actual[$name] = $engine->mapRow($pack, ($case[2] ?? self::row()), 3);
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}//end testRowsMatchTheGoldenGrid()

	/**
	 * The parser's own message is the PHP build's; only the frame is pinned.
	 *
	 * @return void
	 */
	public function testAnUnparseableFreeFormDateNamesTheValue(): void {
		$pack = ['fieldMappings' => [['source' => 'd', 'target' => 't', 'transform' => ['type' => 'date']]]];
		$result = $this->engine()->mapRow($pack, ['d' => 'geen datum'], 9);

		$this->assertSame([], $result['data']);
		$this->assertCount(1, $result['errors']);
		$error = $result['errors'][0];
		$this->assertSame(['row' => 9, 'source' => 'd', 'target' => 't', 'transform' => 'date'], array_slice($error, 0, 4, true));
		$this->assertStringStartsWith('Could not parse date "%1$s": %2$s <["geen datum",', $error['message']);
	}//end testAnUnparseableFreeFormDateNamesTheValue()

	/**
	 * @return void
	 */
	public function testSkipRows(): void {
		$engine = $this->engine();
		$this->assertTrue($engine->isRowSkipped(['skipRows' => [1, 3]], 3));
		$this->assertFalse($engine->isRowSkipped(['skipRows' => [1, '2']], 2));
		$this->assertFalse($engine->isRowSkipped([], 1));
	}//end testSkipRows()
}//end class
