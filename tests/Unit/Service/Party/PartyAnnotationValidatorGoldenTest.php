<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

use OCA\OpenRegister\Service\Party\PartyAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Golden errors of the party annotation's property fields, pinned before
 * the per-field check moved into its own method.
 *
 * @spec openspec/specs/party-model/spec.md
 */
class PartyAnnotationValidatorGoldenTest extends TestCase {

	/**
	 * Every property field is checked in order: not-string, blank, unknown, known, absent.
	 *
	 * @return void
	 */
	public function testThePropertyFieldErrors(): void {
		$errors = (new PartyAnnotationValidator())->validate(
			[
				'properties' => ['naam' => [], 'adres' => []],
				'x-openregister-party' => [
					'kind' => 'person',
					'nameProperty' => ' naam ',
					'addressesProperty' => 12,
					'indicatorsProperty' => '   ',
					'parentProperty' => ' moeder ',
				],
			]
		);

		$this->assertSame(
			[
				['code' => 'party.field-not-string', 'message' => 'x-openregister-party "addressesProperty" must be a non-empty string.'],
				['code' => 'party.field-not-string', 'message' => 'x-openregister-party "indicatorsProperty" must be a non-empty string.'],
				[
					'code' => 'party.unknown-property',
					'message' => 'x-openregister-party "parentProperty" names "moeder", which is not a declared property of this schema.',
				],
			],
			$errors
		);
	}//end testThePropertyFieldErrors()

	/**
	 * A schema declaring no properties checks only that the fields are strings.
	 *
	 * @return void
	 */
	public function testNoDeclaredPropertiesSkipsTheNameCheck(): void {
		$errors = (new PartyAnnotationValidator())->validate(
			['x-openregister-party' => ['kind' => 'person', 'nameProperty' => 'whatever', 'parentProperty' => null]]
		);

		$this->assertSame(
			[['code' => 'party.field-not-string', 'message' => 'x-openregister-party "parentProperty" must be a non-empty string.']],
			$errors
		);
	}//end testNoDeclaredPropertiesSkipsTheNameCheck()
}//end class
