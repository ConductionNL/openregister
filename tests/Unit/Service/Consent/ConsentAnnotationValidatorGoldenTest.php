<?php

/**
 * Golden test for ConsentAnnotationValidator::validate(): pins the exact findings
 * and whether each schema is allowed (no findings) or refused, captured on the
 * code before validateProperty() was split into named checks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Consent;

use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of ConsentAnnotationValidator.
 */
class ConsentAnnotationValidatorGoldenTest extends TestCase {

	/**
	 * Schemas and the exact findings the old code returned for them.
	 *
	 * @return array<string, array{0: array, 1: array}>
	 */
	public static function goldenCases(): array {
		$valid = ['type' => 'array', 'x-openregister-consent' => ['purpose' => 'beeldmateriaal', 'subjectProperty' => 'learnerRef']];

		return [
			'allowed: valid annotation' => [['properties' => ['acts' => $valid]], []],
			'allowed: no subject property' => [['properties' => ['acts' => ['type' => 'array', 'x-openregister-consent' => ['purpose' => 'p']]]], []],
			'allowed: properties not an array' => [['properties' => 'x'], []],
			'allowed: no annotation' => [['properties' => ['a' => ['type' => 'string'], 'b' => 'x']], []],
			'refused: string type' => [
				['properties' => ['acts' => ['type' => 'string'] + $valid]],
				[['code' => 'consent-not-array', 'message' => 'Property "acts" declares x-openregister-consent but is type "string"; it must be type "array".']],
			],
			'refused: no type, annotation not an object' => [
				['properties' => [7 => ['x-openregister-consent' => 'yes']]],
				[
					['code' => 'consent-not-array', 'message' => 'Property "7" declares x-openregister-consent but is type "unknown"; it must be type "array".'],
					['code' => 'consent-malformed', 'message' => 'Property "7" x-openregister-consent must be an object.'],
				],
			],
			'refused: empty purpose and bad subject on two properties' => [
				[
					'properties' => [
						'a' => ['type' => 'array', 'x-openregister-consent' => ['purpose' => '', 'subjectProperty' => 5]],
						'b' => ['type' => ['array'], 'x-openregister-consent' => ['subjectProperty' => null]],
					],
				],
				[
					['code' => 'consent-missing-purpose', 'message' => 'Property "a" x-openregister-consent must declare a non-empty "purpose".'],
					['code' => 'consent-bad-subject-property', 'message' => 'Property "a" x-openregister-consent.subjectProperty must be a string when present.'],
					['code' => 'consent-not-array', 'message' => 'Property "b" declares x-openregister-consent but is type "unknown"; it must be type "array".'],
					['code' => 'consent-missing-purpose', 'message' => 'Property "b" x-openregister-consent must declare a non-empty "purpose".'],
				],
			],
		];
	}//end goldenCases()

	/**
	 * validate() returns exactly the golden findings.
	 *
	 * @param array $schema   The schema.
	 * @param array $findings The findings the old code returned.
	 *
	 * @return void
	 */
	#[DataProvider('goldenCases')]
	public function testFindingsMatchTheGolden(array $schema, array $findings): void {
		$this->assertSame($findings, (new ConsentAnnotationValidator())->validate($schema));
	}//end testFindingsMatchTheGolden()

	/**
	 * Mutation check: breaking the purpose of the allowed schema refuses it.
	 *
	 * @return void
	 */
	public function testTheGoldenCatchesAMutatedPurpose(): void {
		$schema = self::goldenCases()['allowed: valid annotation'][0];
		$schema['properties']['acts']['x-openregister-consent']['purpose'] = 42;

		$this->assertSame(
			[['code' => 'consent-missing-purpose', 'message' => 'Property "acts" x-openregister-consent must declare a non-empty "purpose".']],
			(new ConsentAnnotationValidator())->validate($schema)
		);
	}//end testTheGoldenCatchesAMutatedPurpose()
}//end class
