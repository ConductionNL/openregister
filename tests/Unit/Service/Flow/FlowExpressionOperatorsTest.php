<?php

/**
 * Unit test for FlowExpressionOperators: the operator table a flow expression
 * reads from, called directly, including the null answers for unreadable
 * dates and an all-empty coalesce.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowExpressionOperators;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowExpressionOperators
 */
class FlowExpressionOperatorsTest extends TestCase {

	/**
	 * The time zone before the test.
	 *
	 * @var string
	 */
	private string $zone = 'UTC';

	/**
	 * Fix the time zone, so date answers do not depend on the machine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->zone = date_default_timezone_get();
		date_default_timezone_set('UTC');
	}//end setUp()

	/**
	 * Restore the time zone.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		date_default_timezone_set($this->zone);
	}//end tearDown()

	/**
	 * One operator.
	 *
	 * @param string $name The operator.
	 *
	 * @return callable
	 */
	private function op(string $name): callable {
		return FlowExpressionOperators::all()[$name];
	}//end op()

	/**
	 * The table holds the string, date, array and structure operators.
	 *
	 * @return void
	 */
	public function testTheTableHoldsEveryOperator(): void {
		$this->assertSame(
			['upper', 'lower', 'trim', 'split', 'join', 'replace', 'matches', 'now', 'dateFormat', 'dateAdd', 'unique', 'sort', 'length', 'coalesce', 'toJson', 'fromJson'],
			array_keys(FlowExpressionOperators::all())
		);
	}//end testTheTableHoldsEveryOperator()

	/**
	 * Dates are shifted and formatted in the fixed zone; an unreadable shift is null.
	 *
	 * @return void
	 */
	public function testDateOperators(): void {
		$this->assertSame('2026-01-03T00:00:00+00:00', ($this->op('dateAdd'))('2026-01-02', '+1 day'));
		$this->assertNull(($this->op('dateAdd'))('2026-01-02', 'whenever it suits'));
		$this->assertSame('02-01-2026', ($this->op('dateFormat'))('2026-01-02', 'd-m-Y'));
		$this->assertNull(($this->op('dateFormat'))('not a date'));
	}//end testDateOperators()

	/**
	 * A sort sorts a copy, a scalar sorts as a one-item list.
	 *
	 * @return void
	 */
	public function testSortSortsAList(): void {
		$this->assertSame([1, 2, 3], ($this->op('sort'))([3, 1, 2]));
		$this->assertSame(['b'], ($this->op('sort'))('b'));
	}//end testSortSortsAList()

	/**
	 * Length counts list members, or the characters of anything else.
	 *
	 * @return void
	 */
	public function testLength(): void {
		$this->assertSame(3, ($this->op('length'))(['a', 'b', 'c']));
		$this->assertSame(4, ($this->op('length'))('één!'));
		$this->assertSame(2, ($this->op('length'))(42));
	}//end testLength()

	/**
	 * Coalesce answers the first value that is neither null nor empty text, and null when there is none.
	 *
	 * @return void
	 */
	public function testCoalesce(): void {
		$this->assertSame(0, ($this->op('coalesce'))(null, '', 0, 'x'));
		$this->assertSame('x', ($this->op('coalesce'))(null, '', 'x'));
		$this->assertNull(($this->op('coalesce'))(null, ''));
	}//end testCoalesce()

	/**
	 * A pattern that is no regular expression does not match, silently.
	 *
	 * @return void
	 */
	public function testABrokenPatternDoesNotMatch(): void {
		$this->assertTrue(($this->op('matches'))('Z-2026-1', '/^Z-\d{4}/'));
		$this->assertFalse(($this->op('matches'))('Z-2026-1', '/^Z-(/'));
	}//end testABrokenPatternDoesNotMatch()
}//end class
