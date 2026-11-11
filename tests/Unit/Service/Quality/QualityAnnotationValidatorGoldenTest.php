<?php

/**
 * QualityAnnotationValidatorGoldenTest
 *
 * Characterisation test pinning every error the quality annotation validator
 * emits (code, message and order), written against the code before it was
 * split into smaller steps.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Service\Quality\QualityAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QualityAnnotationValidatorGoldenTest extends TestCase {

	/**
	 * Shapes and the exact errors they produce.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array{code: string, message: string}>}>
	 */
	public static function shapes(): array {
		$q = static fn ($annotation): array => ['properties' => [], 'x-openregister-quality' => $annotation];

		return [
			'absent' => [['properties' => []], []],
			'not object' => [$q('x'), [['code' => 'quality.not-object', 'message' => 'x-openregister-quality must be an object.']]],
			'rules missing' => [$q([]), [['code' => 'quality.no-rules', 'message' => 'x-openregister-quality requires a non-empty "rules" array.']]],
			'rules not array' => [$q(['rules' => 'x']), [['code' => 'quality.no-rules', 'message' => 'x-openregister-quality requires a non-empty "rules" array.']]],
			'rule not object' => [$q(['rules' => ['x']]), [['code' => 'quality.rule-not-object', 'message' => 'Rule #0 must be an object.']]],
			'unknown type' => [
				$q(['rules' => [['type' => 'bogus', 'field' => 'a']]]),
				[['code' => 'quality.unknown-type', 'message' => 'Rule #0 has unknown type "bogus" (expected one of: required, format, freshness).']],
			],
			'missing type' => [
				$q(['rules' => [['field' => 'a']]]),
				[['code' => 'quality.unknown-type', 'message' => 'Rule #0 has unknown type "" (expected one of: required, format, freshness).']],
			],
			'missing field' => [
				$q(['rules' => [['type' => 'required', 'weight' => 'x']]]),
				[['code' => 'quality.missing-field', 'message' => 'Rule #0 (required) requires a "field".']],
			],
			'bad weight' => [
				$q(['rules' => [['type' => 'required', 'field' => 'a', 'weight' => 'heavy']]]),
				[['code' => 'quality.bad-weight', 'message' => 'Rule #0 "weight" must be numeric.']],
			],
			'numeric string weight' => [$q(['rules' => [['type' => 'required', 'field' => 'a', 'weight' => '2']]]), []],
			'format missing spec and bad weight' => [
				$q(['rules' => [['type' => 'format', 'field' => 'a', 'weight' => 'x', 'format' => '', 'pattern' => '']]]),
				[
					['code' => 'quality.bad-weight', 'message' => 'Rule #0 "weight" must be numeric.'],
					['code' => 'quality.format-missing-spec', 'message' => 'Rule #0 (format) requires "format" or "pattern".'],
				],
			],
			'format pattern only' => [$q(['rules' => [['type' => 'format', 'field' => 'a', 'pattern' => '^x$']]]), []],
			'format named only' => [$q(['rules' => [['type' => 'format', 'field' => 'a', 'format' => 'email']]]), []],
			'format ignores half life' => [$q(['rules' => [['type' => 'format', 'field' => 'a', 'format' => 'email', 'halfLifeDays' => 'x']]]), []],
			'freshness bad half life' => [
				$q(['rules' => [['type' => 'freshness', 'field' => 'a', 'halfLifeDays' => 'long']]]),
				[['code' => 'quality.bad-half-life', 'message' => 'Rule #0 "halfLifeDays" must be numeric.']],
			],
			'freshness numeric half life' => [$q(['rules' => [['type' => 'freshness', 'field' => 'a', 'halfLifeDays' => '30']]]), []],
			'required ignores half life' => [$q(['rules' => [['type' => 'required', 'field' => 'a', 'halfLifeDays' => 'x']]]), []],
			'thresholds not object' => [
				$q(['rules' => [['type' => 'required', 'field' => 'a']], 'thresholds' => 'x']),
				[['code' => 'quality.thresholds-not-object', 'message' => 'x-openregister-quality "thresholds" must be an object.']],
			],
			'thresholds both bad' => [
				$q(['rules' => [['type' => 'required', 'field' => 'a']], 'thresholds' => ['fair' => 'x', 'good' => 'y']]),
				[
					['code' => 'quality.bad-threshold', 'message' => 'Threshold "good" must be numeric.'],
					['code' => 'quality.bad-threshold', 'message' => 'Threshold "fair" must be numeric.'],
				],
			],
			'thresholds valid' => [$q(['rules' => [['type' => 'required', 'field' => 'a']], 'thresholds' => ['good' => 0.9, 'fair' => '0.4']]), []],
			'errors keep rule order then thresholds' => [
				$q(
					[
						'rules' => [
							['type' => 'freshness', 'field' => 'a', 'halfLifeDays' => 'x'],
							'nope',
							['type' => 'format', 'field' => 'b'],
						],
						'thresholds' => ['good' => 'z'],
					]
				),
				[
					['code' => 'quality.bad-half-life', 'message' => 'Rule #0 "halfLifeDays" must be numeric.'],
					['code' => 'quality.rule-not-object', 'message' => 'Rule #1 must be an object.'],
					['code' => 'quality.format-missing-spec', 'message' => 'Rule #2 (format) requires "format" or "pattern".'],
					['code' => 'quality.bad-threshold', 'message' => 'Threshold "good" must be numeric.'],
				],
			],
		];
	}//end shapes()

	/**
	 * The validator emits exactly the pinned errors.
	 *
	 * @param array<string, mixed> $shape Schema shape.
	 * @param array<int, array{code: string, message: string}> $expected Errors.
	 *
	 * @return void
	 */
	#[DataProvider('shapes')]
	public function testErrorsArePinned(array $shape, array $expected): void {
		$this->assertSame($expected, (new QualityAnnotationValidator())->validate($shape));
	}//end testErrorsArePinned()
}//end class
