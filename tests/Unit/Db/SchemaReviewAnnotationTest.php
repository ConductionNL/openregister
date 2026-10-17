<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewDeclarationException;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use PHPUnit\Framework\TestCase;

/**
 * `x-openregister-review` survives a schema save and is refused when it says
 * something the ownership rule cannot honour (REQ-ROD-001, task 1.1).
 *
 * @covers \OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator
 * @covers \OCA\OpenRegister\Service\Decision\ReviewDeclarationException
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */
class SchemaReviewAnnotationTest extends TestCase {

	/**
	 * The key round-trips through the schema configuration instead of being dropped.
	 *
	 * @return void
	 */
	public function testTheAnnotationSurvivesASave(): void {
		$review = ['ownership' => 'decider', 'supervisors' => ['woo-coordinator'], 'hideOthers' => true];
		$schema = new Schema();
		$schema->setConfiguration([ReviewPolicy::ANNOTATION => $review]);

		self::assertSame($review, $schema->getConfiguration()[ReviewPolicy::ANNOTATION] ?? null);
	}//end testTheAnnotationSurvivesASave()

	/**
	 * An ownership that is neither `open` nor `decider` is refused, not read as open.
	 *
	 * @return void
	 */
	public function testAnUnknownOwnershipIsRefused(): void {
		$errors = (new ReviewAnnotationValidator())->validate(
			configuration: [ReviewPolicy::ANNOTATION => ['ownership' => 'decidr']]
		);

		self::assertCount(1, $errors);
		self::assertSame('review-ownership', $errors[0]['code']);
		self::assertStringContainsString('decidr', $errors[0]['message']);
	}//end testAnUnknownOwnershipIsRefused()

	/**
	 * Supervisors must be a list of group ids and hideOthers a boolean.
	 *
	 * @return void
	 */
	public function testSupervisorsAndHidingAreChecked(): void {
		$errors = (new ReviewAnnotationValidator())->validate(
			configuration: [ReviewPolicy::ANNOTATION => ['ownership' => 'decider', 'supervisors' => 'woo-coordinator', 'hideOthers' => 'yes']]
		);

		self::assertSame(['review-supervisors', 'review-hide-others'], array_column($errors, 'code'));
	}//end testSupervisorsAndHidingAreChecked()

	/**
	 * A sound declaration, an absent one and an unknown key each answer as expected.
	 *
	 * @return void
	 */
	public function testASoundDeclarationPassesAndAnUnknownKeyIsRefused(): void {
		$validator = new ReviewAnnotationValidator();

		self::assertSame([], $validator->validate(configuration: []));
		self::assertSame(
			[],
			$validator->validate(configuration: [ReviewPolicy::ANNOTATION => ['ownership' => 'open', 'supervisors' => [], 'hideOthers' => false]])
		);
		self::assertSame(
			['review-shape'],
			array_column($validator->validate(configuration: [ReviewPolicy::ANNOTATION => 'decider']), 'code')
		);
		self::assertSame(
			['review-unknown-key'],
			array_column($validator->validate(configuration: [ReviewPolicy::ANNOTATION => ['ownership' => 'decider', 'hide' => true]]), 'code')
		);
	}//end testASoundDeclarationPassesAndAnUnknownKeyIsRefused()

	/**
	 * The exception carries every refusal and names them in its message.
	 *
	 * @return void
	 */
	public function testTheExceptionNamesEachRefusal(): void {
		$errors = [['code' => 'review-ownership', 'message' => 'Ownership "x" is not open or decider.']];
		$exception = new ReviewDeclarationException(errors: $errors);

		self::assertSame($errors, $exception->getErrors());
		self::assertStringContainsString('Ownership "x"', $exception->getMessage());
	}//end testTheExceptionNamesEachRefusal()
}//end class
