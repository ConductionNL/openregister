<?php

/**
 * OpenRegister TransitionInputPaths tests
 *
 * A transition input may name a field inside an object property
 * (`parent.child`): the parent is accepted holding only its declared
 * children, a required child is checked inside it, and the merge keeps the
 * parent's other stored fields.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/transition-inputs-name-a-nested-field/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\TransitionInputPaths;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionInputPaths
 */
class TransitionInputPathsTest extends TestCase {
	private const DECLARED = [
		'feedback.route' => true,
		'feedback.note' => false,
		'reason' => false,
	];

	/**
	 * The class under test.
	 *
	 * @var TransitionInputPaths
	 */
	private TransitionInputPaths $paths;

	/**
	 * A fresh, stateless instance per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->paths = new TransitionInputPaths();
	}//end setUp()

	/**
	 * A literal dotted key unfolds into the nested shape; plain keys stay.
	 *
	 * @return void
	 */
	public function testUnfoldTurnsDottedKeysIntoNestedValues(): void {
		$payload = ['feedback.route' => 'mas', 'feedback' => ['note' => 'x'], 'reason' => 'r'];
		$this->assertSame(
			expected: ['feedback' => ['note' => 'x', 'route' => 'mas'], 'reason' => 'r'],
			actual: $this->paths->unfold(data: $payload)
		);
	}//end testUnfoldTurnsDottedKeysIntoNestedValues()

	/**
	 * Declared children under an undeclared parent are accepted.
	 *
	 * @return void
	 */
	public function testDeclaredChildrenAreAccepted(): void {
		$payload = ['feedback' => ['route' => 'mas', 'note' => 'n'], 'reason' => 'r'];
		$this->assertSame(expected: [], actual: $this->paths->undeclared(declared: self::DECLARED, data: $payload));
	}//end testDeclaredChildrenAreAccepted()

	/**
	 * An undeclared child is named by its dotted path; an undeclared top-level key by its name.
	 *
	 * @return void
	 */
	public function testUndeclaredChildAndKeyAreNamed(): void {
		$payload = ['feedback' => ['route' => 'mas', 'secret' => 1], 'salary' => 9];
		$this->assertSame(
			expected: ['feedback.secret', 'salary'],
			actual: $this->paths->undeclared(declared: self::DECLARED, data: $payload)
		);
	}//end testUndeclaredChildAndKeyAreNamed()

	/**
	 * A parent reached only through children may not be replaced by a scalar or a list.
	 *
	 * @return void
	 */
	public function testParentMustHoldAnObject(): void {
		$scalar = $this->paths->undeclared(declared: self::DECLARED, data: ['feedback' => 'all of it']);
		$list = $this->paths->undeclared(declared: self::DECLARED, data: ['feedback' => ['a', 'b']]);
		$this->assertSame(expected: ['feedback'], actual: $scalar);
		$this->assertSame(expected: ['feedback'], actual: $list);
	}//end testParentMustHoldAnObject()

	/**
	 * A parent that is itself declared accepts its whole value, as before.
	 *
	 * @return void
	 */
	public function testDeclaredParentAcceptsAnything(): void {
		$declared = ['feedback' => false, 'feedback.route' => true];
		$payload = ['feedback' => ['route' => 'mas', 'other' => 1]];
		$this->assertSame(expected: [], actual: $this->paths->undeclared(declared: $declared, data: $payload));
	}//end testDeclaredParentAcceptsAnything()

	/**
	 * A required child is missing when absent, empty-string, or when its parent is absent.
	 *
	 * @return void
	 */
	public function testRequiredChildIsCheckedInsideItsParent(): void {
		$cases = [
			[[], ['feedback.route']],
			[['feedback' => ['note' => 'n']], ['feedback.route']],
			[['feedback' => ['route' => '']], ['feedback.route']],
			[['feedback' => ['route' => 'mas']], []],
		];
		foreach ($cases as [$payload, $missing]) {
			$this->assertSame(expected: $missing, actual: $this->paths->missing(declared: self::DECLARED, data: $payload));
		}
	}//end testRequiredChildIsCheckedInsideItsParent()

	/**
	 * The merge writes declared children leaf by leaf and keeps the parent's other fields.
	 *
	 * @return void
	 */
	public function testMergeKeepsTheParentsOtherFields(): void {
		$stored = ['status' => 'open', 'feedback' => ['receivedOn' => '2026-11-01', 'note' => 'old']];
		$accepted = ['feedback' => ['route' => 'mas', 'note' => 'new'], 'reason' => 'r'];
		$this->assertSame(
			expected: [
				'status' => 'open',
				'feedback' => ['receivedOn' => '2026-11-01', 'note' => 'new', 'route' => 'mas'],
				'reason' => 'r',
			],
			actual: $this->paths->merge(objectData: $stored, accepted: $accepted, declared: self::DECLARED)
		);
	}//end testMergeKeepsTheParentsOtherFields()

	/**
	 * The merge creates the parent when the object does not hold one yet (or holds a scalar).
	 *
	 * @return void
	 */
	public function testMergeCreatesAMissingParent(): void {
		$merged = $this->paths->merge(
			objectData: ['feedback' => null],
			accepted: ['feedback' => ['route' => 'mas']],
			declared: self::DECLARED
		);
		$this->assertSame(expected: ['feedback' => ['route' => 'mas']], actual: $merged);
	}//end testMergeCreatesAMissingParent()

	/**
	 * Plain declarations behave exactly as before: top-level replace.
	 *
	 * @return void
	 */
	public function testPlainDeclarationsReplaceTopLevel(): void {
		$declared = ['hours' => true];
		$merged = $this->paths->merge(objectData: ['hours' => 2, 'e' => 1], accepted: ['hours' => 8], declared: $declared);
		$this->assertSame(expected: [], actual: $this->paths->undeclared(declared: $declared, data: ['hours' => 8]));
		$this->assertSame(expected: ['hours'], actual: $this->paths->missing(declared: $declared, data: ['hours' => '']));
		$this->assertSame(expected: ['hours' => 8, 'e' => 1], actual: $merged);
	}//end testPlainDeclarationsReplaceTopLevel()
}//end class
