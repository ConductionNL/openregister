<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Decision;

use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use PHPUnit\Framework\TestCase;

/**
 * A review policy read from a declaration falls back to today's behaviour
 * only when nothing is declared.
 *
 * @covers \OCA\OpenRegister\Service\Decision\ReviewPolicy
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */
class ReviewPolicyTest extends TestCase {

	/**
	 * Nothing declared is open, no supervisors, nothing hidden.
	 *
	 * @return void
	 */
	public function testNothingDeclaredIsOpen(): void {
		$policy = ReviewPolicy::fromDeclaration(declaration: null);

		self::assertFalse($policy->isDeciderOwned());
		self::assertSame([], $policy->getSupervisors());
		self::assertFalse($policy->hidesOthers());
		self::assertSame(['ownership' => 'open', 'supervisors' => [], 'hideOthers' => false], $policy->toArray());
	}//end testNothingDeclaredIsOpen()

	/**
	 * A declaration is read as declared, with blank and duplicate groups dropped.
	 *
	 * @return void
	 */
	public function testADeclarationIsReadAsDeclared(): void {
		$policy = ReviewPolicy::fromDeclaration(
			declaration: ['ownership' => 'decider', 'supervisors' => ['woo-coordinator', '', 'woo-coordinator', 7], 'hideOthers' => true]
		);

		self::assertTrue($policy->isDeciderOwned());
		self::assertSame(['woo-coordinator'], $policy->getSupervisors());
		self::assertTrue($policy->hidesOthers());
	}//end testADeclarationIsReadAsDeclared()

	/**
	 * An ownership nobody can honour fails closed: read as decider, never as open.
	 *
	 * The validator refuses it at save; this covers a value stored before the
	 * validator existed, or written around it.
	 *
	 * @return void
	 */
	public function testAnUnreadableOwnershipFailsClosed(): void {
		self::assertTrue(ReviewPolicy::fromDeclaration(declaration: ['ownership' => 'decidr'])->isDeciderOwned());
		self::assertFalse(ReviewPolicy::fromDeclaration(declaration: ['ownership' => 'open'])->isDeciderOwned());
		self::assertFalse(ReviewPolicy::fromDeclaration(declaration: [])->isDeciderOwned());
	}//end testAnUnreadableOwnershipFailsClosed()
}//end class
