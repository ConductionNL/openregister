<?php

/**
 * Unit tests for ReversibilityAnnotationValidator — what a schema may say
 * about undoing its bulk actions.
 *
 * The rule the tests exist for: an action that destroys data, dispatches a
 * message or transfers to an e-depot cannot be declared reversible. Stored
 * as written, that declaration puts an undo button in front of an operator
 * that cannot work, and they find out on the day they need it (D-4).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\BulkJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator;
use PHPUnit\Framework\TestCase;

final class ReversibilityAnnotationValidatorTest extends TestCase {

	private function validate(array $actions): array {
		return (new ReversibilityAnnotationValidator())->validate(
			[ReversibilityAnnotationValidator::ANNOTATION => $actions]
		);
	}

	private function codes(array $errors): array {
		return array_map(static fn (array $error): string => $error['code'], $errors);
	}

	public function testASchemaWithNoDeclaredActionsIsAccepted(): void {
		$this->assertSame([], (new ReversibilityAnnotationValidator())->validate([]));
	}

	public function testAPropertyWriteMayDeclareItselfReversible(): void {
		$errors = $this->validate(
			[
				'close' => [
					'name' => 'Close',
					'description' => 'Move the case to afgehandeld.',
					'kind' => 'write',
					'reversible' => true,
					'reversalWindow' => 604800,
				],
			]
		);

		$this->assertSame([], $errors);
	}

	/**
	 * @param string $kind The declared kind under test.
	 *
	 * @return void
	 *
	 * @dataProvider irreversibleKinds
	 */
	public function testAnIrreversibleKindCannotDeclareItselfReversible(string $kind): void {
		$errors = $this->validate(
			[
				'dossierAfsluiten' => [
					'name' => 'Sluit af',
					'description' => 'Doet iets onomkeerbaars.',
					'kind' => $kind,
					'reversible' => true,
				],
			]
		);

		$this->assertSame(['reversibility-irreversible-kind'], $this->codes($errors));
		$this->assertStringContainsString('dossierAfsluiten', $errors[0]['message']);
		$this->assertStringContainsString($kind, $errors[0]['message']);
	}

	public static function irreversibleKinds(): array {
		return [
			'a destruction' => ['destroy'],
			'a dispatched message' => ['dispatch'],
			'an e-depot transfer' => ['transfer'],
		];
	}

	public function testAKeyThatReadsAsADestructionMustSayWhatItReallyIs(): void {
		$errors = $this->validate(
			[
				'deleteAttachments' => [
					'name' => 'Delete attachments',
					'description' => 'Removes every attachment.',
					'reversible' => true,
				],
			]
		);

		$this->assertSame(['reversibility-undeclared-kind'], $this->codes($errors));
		$this->assertStringContainsString('deleteAttachments', $errors[0]['message']);
	}

	public function testAnExplicitKindIsTakenAtItsWord(): void {
		// `delete` in the key, but the author says it writes a property. The
		// runtime authority is the registered action, which either implements
		// the reversible interface or does not; this validator refuses a
		// declaration that contradicts ITSELF, not one it merely distrusts.
		$errors = $this->validate(
			[
				'deleteDraftFlag' => [
					'name' => 'Clear the draft flag',
					'description' => 'Writes draft false.',
					'kind' => 'write',
					'reversible' => true,
				],
			]
		);

		$this->assertSame([], $errors);
	}

	public function testAnActionThatDoesNotClaimReversibilityIsLeftAlone(): void {
		$errors = $this->validate(
			[
				'deleteAttachments' => [
					'name' => 'Delete attachments',
					'description' => 'Removes every attachment.',
					'kind' => 'destroy',
				],
			]
		);

		$this->assertSame([], $errors);
	}

	public function testAKindOutsideTheVocabularyIsRefused(): void {
		$errors = $this->validate(
			[
				'close' => ['name' => 'Close', 'description' => 'x', 'kind' => 'wrtie', 'reversible' => true],
			]
		);

		$this->assertSame(['reversibility-unknown-kind'], $this->codes($errors));
	}

	public function testAWindowWithoutReversibilityBoundsNothingAndIsRefused(): void {
		$errors = $this->validate(
			[
				'close' => ['name' => 'Close', 'description' => 'x', 'reversalWindow' => 3600],
			]
		);

		$this->assertSame(['reversibility-window-without-reversible'], $this->codes($errors));
	}

	/**
	 * @param mixed $window The declared window under test.
	 *
	 * @return void
	 *
	 * @dataProvider badWindows
	 */
	public function testAWindowOutsideTheBoundsIsRefused(mixed $window): void {
		$errors = $this->validate(
			[
				'close' => ['name' => 'Close', 'description' => 'x', 'reversible' => true, 'reversalWindow' => $window],
			]
		);

		$this->assertSame(['reversibility-window-out-of-range'], $this->codes($errors));
	}

	public static function badWindows(): array {
		return [
			'zero' => [0],
			'negative' => [-1],
			'a string' => ['604800'],
			'past the ninety day bound' => [(ReversibilityAnnotationValidator::MAX_WINDOW + 1)],
		];
	}

	public function testReversibleMustBeABoolean(): void {
		$errors = $this->validate(
			[
				'close' => ['name' => 'Close', 'description' => 'x', 'reversible' => 'yes'],
			]
		);

		$this->assertSame(['reversibility-not-a-boolean'], $this->codes($errors));
	}
}//end class
