<?php

/**
 * Tests for QueryLimit — the `_limit` normaliser.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Support\QueryLimit;
use PHPUnit\Framework\TestCase;

/**
 * `_limit` normalisation.
 *
 * The behaviour under test is the boundary between "a number of rows" and
 * "every row". Getting it wrong is silent in both directions: too small a
 * value truncates a result set that the caller then presents as a total, and
 * an accidental unlimited turns a paged read into a full table scan.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
 */
class QueryLimitTest extends TestCase {

	/**
	 * A positive integer is a row count, whatever type it arrives as.
	 *
	 * Query strings carry no types, so the string "50" and the int 50 are the
	 * same request and must not diverge.
	 *
	 * @return void
	 */
	public function testPositiveValuesAreRowCounts(): void {
		$this->assertSame(50, QueryLimit::normalise(50));
		$this->assertSame(50, QueryLimit::normalise('50'));
		$this->assertSame(1, QueryLimit::normalise(1));
		$this->assertSame(1, QueryLimit::normalise('1'));
		$this->assertSame(5000, QueryLimit::normalise(5000), 'no cap: 5000 must survive');
	}//end testPositiveValuesAreRowCounts()

	/**
	 * The values that spell "no limit".
	 *
	 * `false` and `null` are the two the API documents; the words are accepted
	 * because a query string cannot carry a boolean and `?_limit=false` is what
	 * a caller writes when they mean it.
	 *
	 * @return void
	 */
	public function testUnlimitedSpellings(): void {
		$this->assertNull(QueryLimit::normalise(null));
		$this->assertNull(QueryLimit::normalise(false));
		$this->assertNull(QueryLimit::normalise('false'));
		$this->assertNull(QueryLimit::normalise('FALSE'), 'case must not matter');
		$this->assertNull(QueryLimit::normalise('null'));
		$this->assertNull(QueryLimit::normalise('all'));
		$this->assertNull(QueryLimit::normalise('unlimited'));
		$this->assertNull(QueryLimit::normalise('none'));
		$this->assertNull(QueryLimit::normalise(''));
		$this->assertNull(QueryLimit::normalise('  '), 'whitespace is not a row count');
	}//end testUnlimitedSpellings()

	/**
	 * Zero and negatives mean unlimited, and this is a DELIBERATE change.
	 *
	 * Before normalisation the same `_limit=0` produced three different results
	 * depending on which read path served it: `LIMIT 0` (no rows) on the
	 * canonical path, one row on the UNION path (`max(1, …)`), and the
	 * provider's default of 200 on the external-database path. No caller can
	 * have depended on a value that behaved three ways, and one caller in this
	 * repository — `TmloController::summary()`, which passes `'_limit' => 0` —
	 * already meant "unlimited" by it.
	 *
	 * @return void
	 */
	public function testZeroAndNegativeMeanUnlimited(): void {
		$this->assertNull(QueryLimit::normalise(0));
		$this->assertNull(QueryLimit::normalise('0'));
		$this->assertNull(QueryLimit::normalise(-1));
		$this->assertNull(QueryLimit::normalise('-1'));
		$this->assertNull(QueryLimit::normalise(-999));
	}//end testZeroAndNegativeMeanUnlimited()

	/**
	 * Junk is unlimited, not zero.
	 *
	 * This is the arm that matters most for the failure this class exists to
	 * stop. `(int)'abc'` is 0, and 0 used to mean "LIMIT 0" — so a typo in a
	 * query string produced a confidently empty list with HTTP 200. Reading
	 * junk as "no limit given" degrades to the documented default behaviour
	 * instead of inventing an empty result.
	 *
	 * @return void
	 */
	public function testNonNumericStringsDoNotBecomeZero(): void {
		$this->assertNull(QueryLimit::normalise('abc'));
		$this->assertNull(QueryLimit::normalise('twenty'));
		$this->assertNull(QueryLimit::normalise('1x'), 'partially numeric is still not a number');
	}//end testNonNumericStringsDoNotBecomeZero()

	/**
	 * A float truncates toward zero rather than erroring.
	 *
	 * @return void
	 */
	public function testFloatsTruncate(): void {
		$this->assertSame(20, QueryLimit::normalise(20.9));
		$this->assertSame(20, QueryLimit::normalise('20.9'));
		$this->assertNull(QueryLimit::normalise(0.4), '0.4 truncates to 0, which is unlimited');
	}//end testFloatsTruncate()

	/**
	 * `true` is not a row count.
	 *
	 * `?_limit=true` is a caller saying "limit it" without saying to what. The
	 * old `(int)` cast turned that into 1 — a single row, silently.
	 *
	 * @return void
	 */
	public function testBooleanTrueIsNotOneRow(): void {
		$this->assertNull(QueryLimit::normalise(true));
	}//end testBooleanTrueIsNotOneRow()

	/**
	 * `isUnlimited()` agrees with `normalise()` on every input.
	 *
	 * The two must not drift: a call site branching on `isUnlimited()` and one
	 * reading `normalise()` have to reach the same conclusion, or the SQL and
	 * the pagination metadata will describe different queries.
	 *
	 * @return void
	 */
	public function testIsUnlimitedAgreesWithNormalise(): void {
		$inputs = [null, false, true, 0, '0', -1, 1, 20, '20', 'false', 'abc', '', 5000];
		foreach ($inputs as $input) {
			$this->assertSame(
				QueryLimit::normalise($input) === null,
				QueryLimit::isUnlimited($input),
				'disagreement on ' . var_export($input, true)
			);
		}
	}//end testIsUnlimitedAgreesWithNormalise()
}//end class
