<?php

/**
 * Soft-uniqueness nominations.
 *
 * The case that matters is a nomination of a property the schema does not
 * declare: it is the one mistake that would otherwise cost a uniqueness alert
 * that silently never fires.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Service\Quality\UniqueHintAnnotationValidator;
use PHPUnit\Framework\TestCase;

class UniqueHintAnnotationValidatorTest extends TestCase {

	/**
	 * Validator under test.
	 *
	 * @var UniqueHintAnnotationValidator
	 */
	private UniqueHintAnnotationValidator $validator;

	/**
	 * Build the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->validator = new UniqueHintAnnotationValidator();
	}//end setUp()

	/**
	 * A schema shape declaring `kvkNumber` and `bsn`.
	 *
	 * @param mixed $annotation The nomination under test.
	 *
	 * @return array<string, mixed>
	 */
	private function shape($annotation): array {
		return [
			'properties' => [
				'kvkNumber' => ['type' => 'string'],
				'bsn' => ['type' => 'string'],
			],
			'x-openregister-unique-hint' => $annotation,
		];
	}//end shape()

	/**
	 * No nomination is valid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testAbsentAnnotationIsValid(): void {
		$this->assertSame([], $this->validator->validate(['properties' => []]));
	}//end testAbsentAnnotationIsValid()

	/**
	 * Nominating declared properties is valid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testDeclaredPropertiesAreValid(): void {
		$this->assertSame([], $this->validator->validate($this->shape(['kvkNumber', 'bsn'])));
	}//end testDeclaredPropertiesAreValid()

	/**
	 * A nomination of a property the schema does not declare is refused, and
	 * the refusal names it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testUndeclaredPropertyIsRefusedByName(): void {
		$errors = $this->validator->validate($this->shape(['kvkNummer']));

		$this->assertCount(1, $errors);
		$this->assertSame('unique-hint.unknown-property', $errors[0]['code']);
		$this->assertSame('kvkNummer', $errors[0]['property']);
	}//end testUndeclaredPropertyIsRefusedByName()

	/**
	 * The nomination has to be a list of names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testAScalarNominationIsRefused(): void {
		$errors = $this->validator->validate($this->shape('kvkNumber'));

		$this->assertSame('unique-hint.not-list', $errors[0]['code']);
	}//end testAScalarNominationIsRefused()

	/**
	 * An empty entry is refused rather than skipped: it is a typo, and
	 * skipping it would nominate nothing while looking like it nominated
	 * something.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testAnEmptyEntryIsRefused(): void {
		$errors = $this->validator->validate($this->shape(['kvkNumber', '']));

		$this->assertSame('unique-hint.not-a-name', $errors[0]['code']);
	}//end testAnEmptyEntryIsRefused()

	/**
	 * The reader hands the save path a clean, de-duplicated list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function testNominatedReadsTheConfiguration(): void {
		$this->assertSame(
			['kvkNumber', 'bsn'],
			$this->validator->nominated(['x-openregister-unique-hint' => ['kvkNumber', 'bsn', 'kvkNumber', '']])
		);
		$this->assertSame([], $this->validator->nominated([]));
	}//end testNominatedReadsTheConfiguration()
}//end class
