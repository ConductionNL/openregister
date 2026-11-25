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
	 * A literal dotted key unfolds into the nested shape; plain keys stay.
	 *
	 * @return void
	 */
	public function testUnfoldTurnsDottedKeysIntoNestedValues(): void {
		$this->assertSame(
			['feedback' => ['note' => 'x', 'route' => 'mas'], 'reason' => 'r'],
			TransitionInputPaths::unfold(['feedback.route' => 'mas', 'feedback' => ['note' => 'x'], 'reason' => 'r'])
		);
	}//end testUnfoldTurnsDottedKeysIntoNestedValues()

	/**
	 * Declared children under an undeclared parent are accepted.
	 *
	 * @return void
	 */
	public function testDeclaredChildrenAreAccepted(): void {
		$this->assertSame(
			[],
			TransitionInputPaths::undeclared(self::DECLARED, ['feedback' => ['route' => 'mas', 'note' => 'n'], 'reason' => 'r'])
		);
	}//end testDeclaredChildrenAreAccepted()

	/**
	 * An undeclared child is named by its dotted path; an undeclared top-level key by its name.
	 *
	 * @return void
	 */
	public function testUndeclaredChildAndKeyAreNamed(): void {
		$this->assertSame(
			['feedback.secret', 'salary'],
			TransitionInputPaths::undeclared(self::DECLARED, ['feedback' => ['route' => 'mas', 'secret' => 1], 'salary' => 9])
		);
	}//end testUndeclaredChildAndKeyAreNamed()

	/**
	 * A parent reached only through children may not be replaced by a scalar or a list.
	 *
	 * @return void
	 */
	public function testParentMustHoldAnObject(): void {
		$this->assertSame(['feedback'], TransitionInputPaths::undeclared(self::DECLARED, ['feedback' => 'all of it']));
		$this->assertSame(['feedback'], TransitionInputPaths::undeclared(self::DECLARED, ['feedback' => ['a', 'b']]));
	}//end testParentMustHoldAnObject()

	/**
	 * A parent that is itself declared accepts its whole value, as before.
	 *
	 * @return void
	 */
	public function testDeclaredParentAcceptsAnything(): void {
		$declared = ['feedback' => false, 'feedback.route' => true];
		$this->assertSame([], TransitionInputPaths::undeclared($declared, ['feedback' => ['route' => 'mas', 'other' => 1]]));
	}//end testDeclaredParentAcceptsAnything()

	/**
	 * A required child is missing when absent, empty-string, or when its parent is absent.
	 *
	 * @return void
	 */
	public function testRequiredChildIsCheckedInsideItsParent(): void {
		$this->assertSame(['feedback.route'], TransitionInputPaths::missing(self::DECLARED, []));
		$this->assertSame(['feedback.route'], TransitionInputPaths::missing(self::DECLARED, ['feedback' => ['note' => 'n']]));
		$this->assertSame(['feedback.route'], TransitionInputPaths::missing(self::DECLARED, ['feedback' => ['route' => '']]));
		$this->assertSame([], TransitionInputPaths::missing(self::DECLARED, ['feedback' => ['route' => 'mas']]));
	}//end testRequiredChildIsCheckedInsideItsParent()

	/**
	 * The merge writes declared children leaf by leaf and keeps the parent's other fields.
	 *
	 * @return void
	 */
	public function testMergeKeepsTheParentsOtherFields(): void {
		$stored = ['status' => 'open', 'feedback' => ['receivedOn' => '2026-11-01', 'note' => 'old']];
		$this->assertSame(
			['status' => 'open', 'feedback' => ['receivedOn' => '2026-11-01', 'note' => 'new', 'route' => 'mas'], 'reason' => 'r'],
			TransitionInputPaths::merge($stored, ['feedback' => ['route' => 'mas', 'note' => 'new'], 'reason' => 'r'], self::DECLARED)
		);
	}//end testMergeKeepsTheParentsOtherFields()

	/**
	 * The merge creates the parent when the object does not hold one yet (or holds a scalar).
	 *
	 * @return void
	 */
	public function testMergeCreatesAMissingParent(): void {
		$this->assertSame(
			['feedback' => ['route' => 'mas']],
			TransitionInputPaths::merge(['feedback' => null], ['feedback' => ['route' => 'mas']], self::DECLARED)
		);
	}//end testMergeCreatesAMissingParent()

	/**
	 * Plain declarations behave exactly as before: top-level replace.
	 *
	 * @return void
	 */
	public function testPlainDeclarationsReplaceTopLevel(): void {
		$declared = ['hours' => true];
		$this->assertSame([], TransitionInputPaths::undeclared($declared, ['hours' => 8]));
		$this->assertSame(['hours'], TransitionInputPaths::missing($declared, ['hours' => '']));
		$this->assertSame(['hours' => 8, 'e' => 1], TransitionInputPaths::merge(['hours' => 2, 'e' => 1], ['hours' => 8], $declared));
	}//end testPlainDeclarationsReplaceTopLevel()
}//end class
