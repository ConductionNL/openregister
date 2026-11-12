<?php

/**
 * DedupAnnotationValidatorGoldenTest
 *
 * Characterisation test pinning every error the dedup annotation validator
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

use OCA\OpenRegister\Service\Quality\DedupAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DedupAnnotationValidatorGoldenTest extends TestCase {

	/**
	 * Shapes and the exact errors they produce.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array{code: string, message: string}>}>
	 */
	public static function shapes(): array {
		$d = static fn ($annotation): array => ['properties' => [], 'x-openregister-dedup' => $annotation];
		$ok = [['field' => 'name', 'method' => 'exact']];

		return [
			'absent' => [['properties' => []], []],
			'not object' => [$d(7), [['code' => 'dedup.not-object', 'message' => 'x-openregister-dedup must be an object.']]],
			'no rules' => [$d(['matchRules' => []]), [['code' => 'dedup.no-rules', 'message' => 'x-openregister-dedup requires a non-empty "matchRules" array.']]],
			'rules not array' => [$d(['matchRules' => 'x', 'threshold' => 'x']), [['code' => 'dedup.no-rules', 'message' => 'x-openregister-dedup requires a non-empty "matchRules" array.']]],
			'rule not object' => [$d(['matchRules' => ['x']]), [['code' => 'dedup.rule-not-object', 'message' => 'Match rule #0 must be an object.']]],
			'missing field before method' => [
				$d(['matchRules' => [['method' => 'bogus']]]),
				[['code' => 'dedup.missing-field', 'message' => 'Match rule #0 requires a "field".']],
			],
			'unknown method' => [
				$d(['matchRules' => [['field' => 'a', 'method' => 'fuzzy', 'weight' => 'x']]]),
				[['code' => 'dedup.unknown-method', 'message' => 'Match rule #0 has unknown method "fuzzy" (expected one of: exact, normalized, levenshtein).']],
			],
			'bad weight' => [
				$d(['matchRules' => [['field' => 'a', 'method' => 'levenshtein', 'weight' => 'x']]]),
				[['code' => 'dedup.bad-weight', 'message' => 'Match rule #0 "weight" must be numeric.']],
			],
			'valid minimal' => [$d(['matchRules' => $ok]), []],
			'valid full' => [
				$d(['matchRules' => $ok, 'threshold' => '0.8', 'blockingKeys' => ['a'], 'onCreate' => 'block', 'overrideGroups' => ['admin']]),
				[],
			],
			'null blocking keys and on create allowed' => [$d(['matchRules' => $ok, 'blockingKeys' => null, 'onCreate' => null]), []],
			'every top-level error in order' => [
				$d(
					[
						'matchRules' => [['field' => 'a', 'method' => 'exact', 'weight' => 'w'], 'x'],
						'threshold' => 'high',
						'blockingKeys' => 'name',
						'onCreate' => 'blocking',
						'overrideGroups' => 'admin',
					]
				),
				[
					['code' => 'dedup.bad-weight', 'message' => 'Match rule #0 "weight" must be numeric.'],
					['code' => 'dedup.rule-not-object', 'message' => 'Match rule #1 must be an object.'],
					['code' => 'dedup.bad-threshold', 'message' => 'x-openregister-dedup "threshold" must be numeric.'],
					['code' => 'dedup.bad-blocking-keys', 'message' => 'x-openregister-dedup "blockingKeys" must be an array.'],
					['code' => 'dedup.unknown-on-create', 'message' => 'x-openregister-dedup "onCreate" must be one of: warn, block.'],
					['code' => 'dedup.bad-override-groups', 'message' => 'x-openregister-dedup "overrideGroups" must be an array of group ids.'],
				],
			],
			'non-string on create' => [
				$d(['matchRules' => $ok, 'onCreate' => true]),
				[['code' => 'dedup.unknown-on-create', 'message' => 'x-openregister-dedup "onCreate" must be one of: warn, block.']],
			],
			'bad override group entries' => [
				$d(['matchRules' => $ok, 'overrideGroups' => ['', 'ok', 5, 'k' => null]]),
				[
					['code' => 'dedup.bad-override-group', 'message' => 'x-openregister-dedup "overrideGroups" entry #0 must be a non-empty group id.'],
					['code' => 'dedup.bad-override-group', 'message' => 'x-openregister-dedup "overrideGroups" entry #2 must be a non-empty group id.'],
					['code' => 'dedup.bad-override-group', 'message' => 'x-openregister-dedup "overrideGroups" entry #0 must be a non-empty group id.'],
				],
			],
			'empty override groups' => [$d(['matchRules' => $ok, 'overrideGroups' => []]), []],
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
		$this->assertSame($expected, (new DedupAnnotationValidator())->validate($shape));
	}//end testErrorsArePinned()
}//end class
