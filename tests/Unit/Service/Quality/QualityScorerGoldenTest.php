<?php

/**
 * QualityScorerGoldenTest
 *
 * Characterisation test pinning the scorer's rule skipping, format resolution
 * and freshness edge cases, written against the code before it was split into
 * smaller steps. Every instant is passed in explicitly; nothing reads the clock.
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

use DateTimeImmutable;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QualityScorerGoldenTest extends TestCase {

	/**
	 * Objects, rules and the exact score they produce.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, mixed>, 2: float}>
	 */
	public static function cases(): array {
		$required = ['type' => 'required', 'field' => 'a'];

		return [
			'non-array rule skipped' => [['a' => ''], ['x', $required], 0.0],
			'empty type skipped' => [['a' => ''], [['field' => 'a'], $required], 0.0],
			'zero weight skipped' => [['a' => 'x'], [['type' => 'required', 'field' => 'b', 'weight' => 0], $required], 1.0],
			'negative weight skipped' => [['a' => 'x'], [['type' => 'required', 'field' => 'b', 'weight' => -3], $required], 1.0],
			'non-numeric weight defaults' => [['a' => 'x'], [['type' => 'required', 'field' => 'b', 'weight' => 'heavy'], $required], 0.5],
			'numeric string weight' => [['a' => 'x'], [['type' => 'required', 'field' => 'b', 'weight' => '3'], $required], 0.25],
			'only skipped rules is compliant' => [[], ['x', ['field' => 'a'], ['type' => 'required', 'field' => 'a', 'weight' => 0]], 1.0],
			'unknown type scores zero' => [['a' => 'x'], [['type' => 'bogus', 'field' => 'a'], $required], 0.5],
			'rounded to four decimals' => [['a' => 'x'], [$required, ['type' => 'required', 'field' => 'b'], ['type' => 'required', 'field' => 'c']], 0.3333],
			'required whitespace absent' => [['a' => '  '], [$required], 0.0],
			'required empty array absent' => [['a' => []], [$required], 0.0],
			'required false present' => [['a' => false], [$required], 1.0],
			'required empty field name' => [['' => 'x'], [['type' => 'required', 'field' => '']], 0.0],
			'required dotted through scalar' => [['a' => 'x'], [['type' => 'required', 'field' => 'a.b']], 0.0],
			'format non-scalar' => [['a' => ['x']], [['type' => 'format', 'field' => 'a', 'pattern' => '.*']], 0.0],
			'format unknown named no pattern' => [['a' => 'x'], [['type' => 'format', 'field' => 'a', 'format' => 'iban']], 0.0],
			'format named url' => [['a' => 'https://example.org'], [['type' => 'format', 'field' => 'a', 'format' => 'url']], 1.0],
			'format named date' => [['a' => '2026-01-02T10:00'], [['type' => 'format', 'field' => 'a', 'format' => 'date']], 1.0],
			'format pattern overrides named' => [['a' => 'abc'], [['type' => 'format', 'field' => 'a', 'format' => 'email', 'pattern' => '^abc$']], 1.0],
			'format empty pattern keeps named' => [['a' => 'abc'], [['type' => 'format', 'field' => 'a', 'format' => 'email', 'pattern' => '']], 0.0],
			'format non-string pattern ignored' => [['a' => 'abc'], [['type' => 'format', 'field' => 'a', 'pattern' => 5]], 0.0],
			'format slash escaped' => [['a' => 'a/b'], [['type' => 'format', 'field' => 'a', 'pattern' => '^a/b$']], 1.0],
			'format broken pattern' => [['a' => 'a'], [['type' => 'format', 'field' => 'a', 'pattern' => '(']], 0.0],
			'format int value' => [['a' => 123], [['type' => 'format', 'field' => 'a', 'pattern' => '^\d+$']], 1.0],
			'format no match' => [['a' => 'abc'], [['type' => 'format', 'field' => 'a', 'pattern' => '^\d+$']], 0.0],
			'freshness future' => [['a' => '2026-02-01T00:00:00Z'], [['type' => 'freshness', 'field' => 'a']], 1.0],
			'freshness same instant' => [['a' => '2026-01-01T00:00:00Z'], [['type' => 'freshness', 'field' => 'a']], 1.0],
			'freshness non-scalar' => [['a' => ['x']], [['type' => 'freshness', 'field' => 'a']], 0.0],
			'freshness absent' => [[], [['type' => 'freshness', 'field' => 'a']], 0.0],
			'freshness unparseable' => [['a' => 'not a date'], [['type' => 'freshness', 'field' => 'a']], 0.0],
			'freshness zero half life' => [['a' => '2025-12-01T00:00:00Z'], [['type' => 'freshness', 'field' => 'a', 'halfLifeDays' => 0]], 0.0],
			'freshness negative half life string' => [['a' => '2025-12-01T00:00:00Z'], [['type' => 'freshness', 'field' => 'a', 'halfLifeDays' => '-5']], 0.0],
			'freshness half life string' => [['a' => '2025-12-02T00:00:00Z'], [['type' => 'freshness', 'field' => 'a', 'halfLifeDays' => '30']], 0.5],
			'freshness default half life' => [['a' => '2025-07-05T00:00:00Z'], [['type' => 'freshness', 'field' => 'a']], 0.5],
			'freshness bad half life defaults' => [['a' => '2025-07-05T00:00:00Z'], [['type' => 'freshness', 'field' => 'a', 'halfLifeDays' => 'x']], 0.5],
		];
	}//end cases()

	/**
	 * The scorer yields exactly the pinned score.
	 *
	 * @param array<string, mixed> $object Object payload.
	 * @param array<int, mixed> $rules Rules.
	 * @param float $expected Score.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testScoreIsPinned(array $object, array $rules, float $expected): void {
		$now = new DateTimeImmutable('2026-01-01T00:00:00Z');
		$this->assertSame($expected, (new QualityScorer())->score($object, $rules, $now));
	}//end testScoreIsPinned()
}//end class
