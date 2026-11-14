<?php

/**
 * SearchFoldingTest: the SQL each platform and mode compares a search with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/search-accent-insensitive/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\SearchFolding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchFolding::class)]
final class SearchFoldingTest extends TestCase {

	/**
	 * Every platform and mode: the full match, plain and null-safe.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: string, 3: string}>
	 */
	public static function comparisons(): array {
		return [
			'postgres exact keeps ILIKE' => [
				SearchFolding::PLATFORM_POSTGRES,
				false,
				'"t"."name"::text ILIKE \'%cafe%\'',
				'COALESCE("t"."name"::text, \'\') ILIKE \'%cafe%\'',
			],
			'postgres folded uses the wrapper on both sides' => [
				SearchFolding::PLATFORM_POSTGRES,
				true,
				'openregister_fold("t"."name"::text) LIKE openregister_fold(\'%cafe%\')',
				'openregister_fold(COALESCE("t"."name"::text, \'\')) LIKE openregister_fold(\'%cafe%\')',
			],
			'mysql exact lowers both sides' => [
				SearchFolding::PLATFORM_MYSQL,
				false,
				'LOWER(CAST("t"."name" AS CHAR)) LIKE LOWER(\'%cafe%\')',
				'LOWER(CAST(COALESCE("t"."name", \'\') AS CHAR)) LIKE LOWER(\'%cafe%\')',
			],
			'mysql folded compares under the unicode collation' => [
				SearchFolding::PLATFORM_MYSQL,
				true,
				'CAST("t"."name" AS CHAR) COLLATE utf8mb4_unicode_ci LIKE \'%cafe%\' COLLATE utf8mb4_unicode_ci',
				'CAST(COALESCE("t"."name", \'\') AS CHAR) COLLATE utf8mb4_unicode_ci LIKE \'%cafe%\' COLLATE utf8mb4_unicode_ci',
			],
			'another platform never folds' => [
				SearchFolding::PLATFORM_OTHER,
				true,
				'LOWER(CAST("t"."name" AS CHAR)) LIKE LOWER(\'%cafe%\')',
				'LOWER(CAST(COALESCE("t"."name", \'\') AS CHAR)) LIKE LOWER(\'%cafe%\')',
			],
		];
	}//end comparisons()

	/**
	 * The comparison per platform and mode.
	 *
	 * @param string $platform The platform.
	 * @param bool   $folded   Whether accents are to be ignored.
	 * @param string $plain    The expected plain match.
	 * @param string $nullSafe The expected null-safe match.
	 *
	 * @return void
	 */
	#[DataProvider('comparisons')]
	public function testTheMatchPerPlatformAndMode(string $platform, bool $folded, string $plain, string $nullSafe): void {
		$folding = new SearchFolding(platform: $platform, folded: $folded);

		$this->assertSame($plain, $folding->match(quotedColumn: '"t"."name"', quotedPattern: "'%cafe%'", nullSafe: false));
		$this->assertSame($nullSafe, $folding->match(quotedColumn: '"t"."name"', quotedPattern: "'%cafe%'", nullSafe: true));
	}//end testTheMatchPerPlatformAndMode()

	/**
	 * The parts agree with the whole match.
	 *
	 * @return void
	 */
	public function testThePartsMakeUpTheMatch(): void {
		$folding = new SearchFolding(platform: SearchFolding::PLATFORM_POSTGRES, folded: true);

		$this->assertSame('openregister_fold(_name::text)', $folding->column(quotedColumn: '_name', nullSafe: false));
		$this->assertSame("openregister_fold('x')", $folding->pattern(quotedPattern: "'x'"));
		$this->assertSame('LIKE', $folding->matchOperator());
		$this->assertSame('ILIKE', (new SearchFolding(platform: SearchFolding::PLATFORM_POSTGRES, folded: false))->matchOperator());
	}//end testThePartsMakeUpTheMatch()

	/**
	 * Only PostgreSQL and MySQL fold, and only when asked.
	 *
	 * @return void
	 */
	public function testWhoFolds(): void {
		$this->assertTrue((new SearchFolding(platform: SearchFolding::PLATFORM_POSTGRES, folded: true))->isFolded());
		$this->assertTrue((new SearchFolding(platform: SearchFolding::PLATFORM_MYSQL, folded: true))->isFolded());
		$this->assertFalse((new SearchFolding(platform: SearchFolding::PLATFORM_POSTGRES, folded: false))->isFolded());
		$this->assertFalse((new SearchFolding(platform: SearchFolding::PLATFORM_OTHER, folded: true))->isFolded());
		$this->assertFalse((new SearchFolding(platform: 'sqlite', folded: true))->isFolded());
	}//end testWhoFolds()

	/**
	 * The fuzzy arm folds both sides on PostgreSQL only when folded.
	 *
	 * @return void
	 */
	public function testSimilarityFoldsBothSidesWhenFolded(): void {
		$folded = new SearchFolding(platform: SearchFolding::PLATFORM_POSTGRES, folded: true);
		$exact  = new SearchFolding(platform: SearchFolding::PLATFORM_POSTGRES, folded: false);

		$this->assertSame(
			"similarity(openregister_fold(t._name::text), openregister_fold('reunie')) > 0.1",
			$folded->similarity(textExpression: 't._name::text', quotedLiteral: "'reunie'")
		);
		$this->assertSame(
			"similarity(t._name::text, 'reunie') > 0.1",
			$exact->similarity(textExpression: 't._name::text', quotedLiteral: "'reunie'")
		);
		$this->assertSame(
			"similarity(t._name::text, 'reunie') > 0.3",
			$exact->similarity(textExpression: 't._name::text', quotedLiteral: "'reunie'", threshold: 0.3)
		);
	}//end testSimilarityFoldsBothSidesWhenFolded()

	/**
	 * Only `_accents=exact` asks for the exact comparison.
	 *
	 * @return void
	 */
	public function testOnlyExactRequestsTheExactComparison(): void {
		$this->assertTrue(SearchFolding::requestsExact(value: 'exact'));
		$this->assertFalse(SearchFolding::requestsExact(value: 'EXACT'));
		$this->assertFalse(SearchFolding::requestsExact(value: 'folded'));
		$this->assertFalse(SearchFolding::requestsExact(value: null));
		$this->assertFalse(SearchFolding::requestsExact(value: ['exact']));
	}//end testOnlyExactRequestsTheExactComparison()
}//end class
