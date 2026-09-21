<?php

/**
 * Comparing a referenced object against a resolved filter.
 *
 * 🔴 THE HALF THAT CAN SILENTLY ACCEPT. The resolver is tested next door and
 * refuses to hand out a partial filter. What is left is the comparison, and a
 * comparison has exactly one dangerous failure: returning true for something it
 * did not understand. An operator with no arm, a null on the referenced object,
 * an `in` list of the wrong type — each of those, read charitably, accepts the
 * write, and accepting is the direction that discloses.
 *
 * So the rule under test is: anything this comparison does not positively
 * recognise as a match is a refusal.
 *
 * `SaveObject::filterFieldMatches()` is private and lives in a 5000-line class
 * that cannot be constructed in a unit test. Its logic is small, total and
 * exactly the thing worth pinning, so it is mirrored here through the same
 * declaration the production path uses, and a source assertion holds the two
 * together: if the production arms change, the last test fails.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * The write-side comparison refuses anything it does not recognise.
 *
 * @coversNothing
 */
class ReferenceFilterMatchTest extends TestCase {

	/**
	 * The production file, read to keep this mirror honest.
	 */
	private const SAVE_OBJECT = __DIR__ . '/../../../../lib/Service/Object/SaveObject.php';

	/**
	 * The same comparison the save path performs, over one condition.
	 *
	 * @param mixed $actual   The referenced object's value.
	 * @param mixed $expected The resolved expectation.
	 *
	 * @return bool True when it matches.
	 */
	private function fieldMatches(mixed $actual, mixed $expected): bool {
		if (is_array($expected) === false) {
			return ((string)$actual === (string)$expected);
		}

		if (array_key_exists('neq', $expected) === true) {
			return ((string)$actual !== (string)$expected['neq']);
		}

		if (array_key_exists('in', $expected) === true) {
			return in_array((string)$actual, array_map('strval', (array)$expected['in']), true);
		}

		return false;
	}

	/**
	 * `eq` matches the value and nothing else.
	 *
	 * @return void
	 */
	public function testEqMatchesOnlyTheValue(): void {
		$this->assertTrue($this->fieldMatches(actual: 'org-7', expected: 'org-7'));
		$this->assertFalse($this->fieldMatches(actual: 'org-8', expected: 'org-7'));
		// The one that matters: an object that answers nothing for the field
		// is NOT a match. Reading a missing field as "matches everything" is
		// how a contact with no organisation becomes a contact of every one.
		$this->assertFalse($this->fieldMatches(actual: null, expected: 'org-7'));
	}//end testEqMatchesOnlyTheValue()

	/**
	 * `neq` excludes the value, and a missing one is still not that value.
	 *
	 * @return void
	 */
	public function testNeqExcludesTheValue(): void {
		$this->assertFalse($this->fieldMatches(actual: 'org-7', expected: ['neq' => 'org-7']));
		$this->assertTrue($this->fieldMatches(actual: 'org-8', expected: ['neq' => 'org-7']));
		$this->assertTrue($this->fieldMatches(actual: null, expected: ['neq' => 'org-7']));
	}//end testNeqExcludesTheValue()

	/**
	 * `in` matches a member of the list and nothing else.
	 *
	 * @return void
	 */
	public function testInMatchesAMember(): void {
		$this->assertTrue($this->fieldMatches(actual: 'org-7', expected: ['in' => ['org-7', 'org-8']]));
		$this->assertFalse($this->fieldMatches(actual: 'org-9', expected: ['in' => ['org-7', 'org-8']]));
		$this->assertFalse($this->fieldMatches(actual: null, expected: ['in' => ['org-7']]));
		$this->assertFalse($this->fieldMatches(actual: 'org-7', expected: ['in' => []]));
	}//end testInMatchesAMember()

	/**
	 * An expectation this comparison does not understand REFUSES.
	 *
	 * 🔴 THE ASSERTION THIS FILE EXISTS FOR. A new entry in
	 * `ReferenceFilterDeclaration::OPERATORS` with no arm in the comparison
	 * would otherwise accept every value on that condition, silently, on a
	 * field somebody deliberately narrowed.
	 *
	 * @return void
	 */
	public function testAnUnknownExpectationRefuses(): void {
		$this->assertFalse($this->fieldMatches(actual: 'org-7', expected: ['like' => 'org%']));
		$this->assertFalse($this->fieldMatches(actual: 'org-7', expected: ['gt' => 1]));
		$this->assertFalse($this->fieldMatches(actual: 'org-7', expected: []));
	}//end testAnUnknownExpectationRefuses()

	/**
	 * Every declared operator has an arm in the production comparison.
	 *
	 * The thread between this mirror and the real thing. `OPERATORS` is the
	 * list the schema save accepts; an operator on it with no arm in
	 * `filterFieldMatches()` falls through to the refusal, which is safe but
	 * means a filter an author was allowed to write can never match anything.
	 * Either way the two have to be kept in step, and this is what says so.
	 *
	 * @return void
	 */
	public function testEveryDeclaredOperatorHasAnArm(): void {
		$body = $this->filterFieldMatchesBody();

		foreach (ReferenceFilterDeclaration::OPERATORS as $operator) {
			if ($operator === 'eq') {
				// `eq` is the bare-value arm rather than a named key.
				continue;
			}

			$this->assertStringContainsString(
				sprintf("array_key_exists('%s'", $operator),
				$body,
				sprintf(
					'operator %s is accepted at schema save and has no arm in filterFieldMatches(), '
					. 'so a filter using it matches nothing',
					$operator
				)
			);
		}
	}//end testEveryDeclaredOperatorHasAnArm()

	/**
	 * The body of `SaveObject::filterFieldMatches()`, and nothing else.
	 *
	 * 🔴 IT IS THE METHOD BODY, NOT THE FILE, AND THAT IS THE WHOLE TEST.
	 * Written first as a search for `'in'` across SaveObject.php, it survived a
	 * mutation that deleted the `in` arm: the operator name still appeared on
	 * the next line, in the very expression the arm had guarded. A whole-file
	 * grep answers a question next to the one being asked, which is how a test
	 * that cannot fail gets written. Extracting the method is what makes the
	 * mutation redden.
	 *
	 * @return string The method body.
	 */
	private function filterFieldMatchesBody(): string {
		$source = (string)file_get_contents(self::SAVE_OBJECT);

		$start = strpos($source, 'private function filterFieldMatches(');
		$this->assertNotFalse(
			$start,
			'the save path must still perform this comparison, or this file is mirroring nothing'
		);

		$end = strpos($source, '}//end filterFieldMatches()', (int)$start);
		$this->assertNotFalse($end, 'the method must end the way this codebase ends methods');

		return substr($source, (int)$start, ((int)$end - (int)$start));
	}
}//end class
