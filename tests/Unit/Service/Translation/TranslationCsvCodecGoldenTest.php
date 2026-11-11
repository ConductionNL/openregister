<?php

/**
 * Golden test for TranslationCsvCodec.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Translation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/register-i18n/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Translation;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Translation\TranslationCsvCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact rows TranslationCsvCodec produces in both directions,
 * including key order, so a refactor cannot move a column or a fallback.
 *
 * @covers \OCA\OpenRegister\Service\Translation\TranslationCsvCodec
 */
class TranslationCsvCodecGoldenTest extends TestCase {

	/**
	 * Build a codec whose schema declares the given translatable properties.
	 *
	 * @param list<string> $translatable The translatable property names.
	 *
	 * @return TranslationCsvCodec
	 */
	private function codec(array $translatable): TranslationCsvCodec {
		$handler = $this->createStub(TranslationHandler::class);
		$handler->method('getTranslatableProperties')->willReturn($translatable);
		return new TranslationCsvCodec($handler);
	}//end codec()

	/**
	 * @return array<string, array{0: list<string>, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public static function flattenCases(): array {
		return [
			'no translatable properties' => [
				[],
				['a' => 'x', 'b' => 3, 'c' => 1.5, 'd' => true, 'e' => null, 'f' => ['u' => 'a/b', 'v' => 'é'], 'g' => []],
				['a' => 'x', 'b' => 3, 'c' => 1.5, 'd' => true, 'e' => null, 'f' => '{"u":"a/b","v":"\u00e9"}', 'g' => '[]'],
			],
			'language keyed value' => [
				['title'],
				['id' => 7, 'title' => ['nl' => 'Hallo', 'en-GB' => 'Hi', 'de' => ['x'], 'fr' => null, 'it' => 4], 'tail' => 'z'],
				['id' => 7, 'title_nl' => 'Hallo', 'title_en-GB' => 'Hi', 'title_de' => null, 'title_fr' => null, 'title_it' => 4, 'tail' => 'z'],
			],
			'plain string goes to und' => [
				['title'],
				['title' => 'Legacy'],
				['title_und' => 'Legacy'],
			],
			'empty string goes to und' => [
				['title'],
				['title' => ''],
				['title_und' => ''],
			],
			'other translatable values' => [
				['a', 'b', 'c', 'd', 'e', 'f'],
				['a' => 5, 'b' => null, 'c' => [], 'd' => ['nl' => 'x', 0 => 'y'], 'e' => ['n_l' => 'x'], 'f' => false],
				['a' => 5, 'b' => null, 'c' => null, 'd' => null, 'e' => null, 'f' => false],
			],
			'later column overwrites in place' => [
				['title'],
				['title_nl' => 'first', 'x' => 1, 'title' => ['nl' => 'second', 'en' => 'third']],
				['title_nl' => 'second', 'x' => 1, 'title_en' => 'third'],
			],
			'pass through overwrites flattened' => [
				['title'],
				['title' => ['nl' => 'a'], 'title_nl' => ['k' => 'v']],
				['title_nl' => '{"k":"v"}'],
			],
			'language code too long' => [
				['title'],
				['title' => ['abcdefghijklmnopq' => 'x']],
				['title' => null],
			],
			'language code at max length' => [
				['title'],
				['title' => ['abcdefghijklmnop' => 'x']],
				['title_abcdefghijklmnop' => 'x'],
			],
		];
	}//end flattenCases()

	/**
	 * @param list<string>         $translatable Translatable properties.
	 * @param array<string, mixed> $data         Object data.
	 * @param array<string, mixed> $expected     Expected flat row.
	 *
	 * @return void
	 */
	#[DataProvider('flattenCases')]
	public function testFlattenForCsv(array $translatable, array $data, array $expected): void {
		$schema = $this->createStub(Schema::class);
		$this->assertSame($expected, $this->codec($translatable)->flattenForCsv($data, $schema));
	}//end testFlattenForCsv()

	/**
	 * @return array<string, array{0: list<string>, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public static function unflattenCases(): array {
		return [
			'no translatable properties' => [
				[],
				['title_nl' => 'x', 'n' => 3],
				['title_nl' => 'x', 'n' => 3],
			],
			'language columns nest' => [
				['title'],
				['id' => 1, 'title_nl' => 'Hallo', 'title_en-GB' => 'Hi', 'other_nl' => 'keep'],
				['id' => 1, 'title' => ['nl' => 'Hallo', 'en-GB' => 'Hi'], 'other_nl' => 'keep'],
			],
			'empty and non string cells are dropped' => [
				['title'],
				['title_nl' => '', 'title_en' => 5, 'title_de' => null, 'title_fr' => 'Salut'],
				['title' => ['fr' => 'Salut']],
			],
			'only empty cells write nothing' => [
				['title'],
				['title_nl' => ''],
				[],
			],
			'invalid suffix passes through' => [
				['title'],
				['title_' => 'a', 'title_1nl' => 'b', 'title_n_l' => 'c', 'title_abcdefghijklmnopq' => 'd', 'title' => 'e'],
				['title_' => 'a', 'title_1nl' => 'b', 'title_n_l' => 'c', 'title_abcdefghijklmnopq' => 'd', 'title' => 'e'],
			],
			'scalar base column is replaced by languages' => [
				['title'],
				['title' => 'plain', 'title_nl' => 'Hallo'],
				['title' => ['nl' => 'Hallo']],
			],
			'base column after languages overwrites' => [
				['title'],
				['title_nl' => 'Hallo', 'title' => 'plain'],
				['title' => 'plain'],
			],
			'overlapping property names try each in order' => [
				['title', 'title_sub'],
				['title_sub_nl' => 'a', 'title_sub' => 'b', 'title_nl' => 'c'],
				['title_sub' => ['nl' => 'a'], 'title' => ['sub' => 'b', 'nl' => 'c']],
			],
			'array base column keeps its keys' => [
				['title'],
				['title' => ['x' => 1], 'title_nl' => 'Hallo'],
				['title' => ['x' => 1, 'nl' => 'Hallo']],
			],
		];
	}//end unflattenCases()

	/**
	 * @param list<string>         $translatable Translatable properties.
	 * @param array<string, mixed> $row          Flat row.
	 * @param array<string, mixed> $expected     Expected nested data.
	 *
	 * @return void
	 */
	#[DataProvider('unflattenCases')]
	public function testUnflattenFromCsv(array $translatable, array $row, array $expected): void {
		$schema = $this->createStub(Schema::class);
		$this->assertSame($expected, $this->codec($translatable)->unflattenFromCsv($row, $schema));
	}//end testUnflattenFromCsv()
}//end class
