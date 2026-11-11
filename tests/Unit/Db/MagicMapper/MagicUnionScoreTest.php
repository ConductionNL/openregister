<?php

/**
 * The search score SQL of a cross-schema UNION arm.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicUnionScore;
use PHPUnit\Framework\TestCase;

/**
 * Each platform scores a column its own way.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicUnionScore
 */
class MagicUnionScoreTest extends TestCase {

	/**
	 * MariaDB and MySQL match with LIKE, whatever pg_trgm says.
	 *
	 * @return void
	 */
	public function testMariaDbScoresWithLike(): void {
		$score = new MagicUnionScore(quotedTerm: "'foo'", isPostgres: false, hasTrgm: true);

		$this->assertSame("CASE WHEN CAST(`a` AS CHAR) LIKE '%foo%' THEN 1 ELSE 0 END", $score->ofColumn(column: '`a`'));
	}//end testMariaDbScoresWithLike()

	/**
	 * PostgreSQL without pg_trgm matches with ILIKE.
	 *
	 * @return void
	 */
	public function testPostgresWithoutTrgmScoresWithIlike(): void {
		$score = new MagicUnionScore(quotedTerm: "'foo'", isPostgres: true, hasTrgm: false);

		$this->assertSame("CASE WHEN \"a\"::text ILIKE '%foo%' THEN 1 ELSE 0 END", $score->ofColumn(column: '"a"'));
	}//end testPostgresWithoutTrgmScoresWithIlike()

	/**
	 * PostgreSQL with pg_trgm scores by similarity.
	 *
	 * @return void
	 */
	public function testPostgresWithTrgmScoresBySimilarity(): void {
		$score = new MagicUnionScore(quotedTerm: "'foo'", isPostgres: true, hasTrgm: true);

		$this->assertSame("COALESCE(similarity(\"a\"::text, 'foo'), 0)", $score->ofColumn(column: '"a"'));
	}//end testPostgresWithTrgmScoresBySimilarity()

	/**
	 * An arm scores the greatest of its columns, or 0 with none to score.
	 *
	 * @return void
	 */
	public function testTheArmScoresTheGreatestColumn(): void {
		$score = new MagicUnionScore(quotedTerm: "'foo'", isPostgres: false, hasTrgm: false);

		$this->assertSame('GREATEST(x, y) AS _search_score', $score->greatest(scores: ['x', 'y']));
		$this->assertSame('0 AS _search_score', $score->greatest(scores: []));
	}//end testTheArmScoresTheGreatestColumn()
}//end class
