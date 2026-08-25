<?php

/**
 * Unit tests for `metrics` on an ANNOTATION.
 *
 * The multi-metric machinery — AggregationQuery::$metrics,
 * tryNativeMultiMetric(), computeMetrics() and the grouped
 * computeGrouped(metrics:) branch — already existed and is reached by the
 * ad-hoc controller. The annotation path simply never read the key, so a
 * schema could not ask for what the engine could already compute.
 *
 * These tests pin the validator half: an entry the runner cannot execute must
 * be refused at SAVE time. That matters more than it looks. A rejected metric
 * that slips through does not throw at read time — it comes back as a missing
 * figure in an otherwise well-formed envelope, which is indistinguishable from
 * a genuine zero.
 *
 * NOTE ON COVERAGE METADATA: deliberately none. This suite runs with
 * `beStrictAboutCoverageMetadata="true"`, under which a test whose @covers
 * names some classes but which touches any other unit is marked risky and has
 * its coverage DISCARDED WHOLESALE. Measured on the sibling aggregation file:
 * 38/1621 statements with @covers, 661/1621 with none. See issue #2847.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Validation of the `metrics` annotation key.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AggregationAnnotationMetricsTest extends TestCase {

	/**
	 * Validate one aggregation spec against a schema declaring `amount_a`,
	 * `amount_b` and `programme`.
	 *
	 * @param array<string, mixed> $spec The aggregation body.
	 *
	 * @return array<int, string> The error codes raised, in order.
	 */
	private function codesFor(array $spec): array {
		$schema = [
			'properties' => [
				'amount_a' => ['type' => 'number'],
				'amount_b' => ['type' => 'number'],
				'programme' => ['type' => 'string'],
			],
			'x-openregister-aggregations' => ['agg' => $spec],
		];

		$errors = (new AggregationAnnotationValidator())->validate(schema: $schema);
		return array_map(static fn (array $e): string => (string)$e['code'], $errors);

	}//end codesFor()

	/**
	 * Two sums over one grouping validate — the shape that motivated this.
	 *
	 * @return void
	 */
	public function testTwoSumsOverOneGroupingValidate(): void {
		self::assertSame([], $this->codesFor([
			'metrics' => [
				['metric' => 'sum', 'field' => 'amount_a'],
				['metric' => 'sum', 'field' => 'amount_b'],
			],
			'groupBy' => ['programme'],
		]));

	}//end testTwoSumsOverOneGroupingValidate()

	/**
	 * A `metrics` spec needs no `metric`, and is not failed for lacking one.
	 *
	 * Without the early branch this reports `aggregation-bad-metric` for a
	 * spec that is entirely well formed.
	 *
	 * @return void
	 */
	public function testMetricsSpecIsNotFailedForMissingSingularMetric(): void {
		$codes = $this->codesFor([
			'metrics' => [['metric' => 'count']],
		]);

		self::assertNotContains('aggregation-bad-metric', $codes);
		self::assertSame([], $codes);

	}//end testMetricsSpecIsNotFailedForMissingSingularMetric()

	/**
	 * `count` needs no field; the field-requiring metrics do.
	 *
	 * @return void
	 */
	public function testFieldIsRequiredOnlyForMetricsThatNeedOne(): void {
		self::assertSame([], $this->codesFor(['metrics' => [['metric' => 'count']]]));

		self::assertSame(
			['aggregation-metrics-field-missing'],
			$this->codesFor(['metrics' => [['metric' => 'sum']]])
		);

	}//end testFieldIsRequiredOnlyForMetricsThatNeedOne()

	/**
	 * A field the schema does not declare is refused, naming its index.
	 *
	 * The index matters: with several entries, "some field is wrong" is not
	 * an actionable message.
	 *
	 * @return void
	 */
	public function testUndeclaredFieldIsRefused(): void {
		self::assertSame(
			['aggregation-metrics-field-not-in-schema'],
			$this->codesFor([
				'metrics' => [
					['metric' => 'sum', 'field' => 'amount_a'],
					['metric' => 'sum', 'field' => 'no_such_property'],
				],
			])
		);

	}//end testUndeclaredFieldIsRefused()

	/**
	 * A metric outside the closed vocabulary is refused.
	 *
	 * @return void
	 */
	public function testMetricOutsideTheVocabularyIsRefused(): void {
		self::assertSame(
			['aggregation-metrics-bad-metric'],
			$this->codesFor(['metrics' => [['metric' => 'median', 'field' => 'amount_a']]])
		);

	}//end testMetricOutsideTheVocabularyIsRefused()

	/**
	 * Shapes that are not a non-empty list are refused.
	 *
	 * @return void
	 */
	public function testMalformedMetricsShapesAreRefused(): void {
		foreach ([[], 'sum', ['metric' => 'sum'], 5] as $shape) {
			self::assertSame(
				['aggregation-metrics-malformed'],
				$this->codesFor(['metrics' => $shape]),
				'shape: ' . var_export($shape, true)
			);
		}

	}//end testMalformedMetricsShapesAreRefused()

	/**
	 * A non-object entry inside the list is refused.
	 *
	 * @return void
	 */
	public function testNonObjectEntryIsRefused(): void {
		self::assertSame(
			['aggregation-metrics-entry-malformed'],
			$this->codesFor(['metrics' => [['metric' => 'count'], 'sum']])
		);

	}//end testNonObjectEntryIsRefused()

	/**
	 * groupBy is still validated on a `metrics` spec.
	 *
	 * The early branch must not become a way to smuggle an invalid grouping
	 * past the checker.
	 *
	 * @return void
	 */
	public function testGroupByIsStillValidatedOnAMetricsSpec(): void {
		self::assertSame(
			['aggregation-groupby-field-unknown'],
			$this->codesFor([
				'metrics' => [['metric' => 'sum', 'field' => 'amount_a']],
				'groupBy' => ['no_such_property'],
			])
		);

	}//end testGroupByIsStillValidatedOnAMetricsSpec()

	/**
	 * Every entry is reported, not just the first.
	 *
	 * A validator that stops at the first bad entry turns one fix-and-retry
	 * cycle into several.
	 *
	 * @return void
	 */
	public function testEveryBadEntryIsReported(): void {
		self::assertSame(
			['aggregation-metrics-bad-metric', 'aggregation-metrics-field-not-in-schema'],
			$this->codesFor([
				'metrics' => [
					['metric' => 'median', 'field' => 'amount_a'],
					['metric' => 'sum', 'field' => 'nope'],
				],
			])
		);

	}//end testEveryBadEntryIsReported()
}//end class
