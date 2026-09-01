<?php

/**
 * Unit tests for TimeseriesRequestValidator.
 *
 * Covers the validation rules the ad-hoc timeseries aggregation
 * endpoint enforces before constructing an AggregationQuery.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/add-time-bucket-aggregation/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Aggregation\TimeseriesRequestValidator;
use PHPUnit\Framework\TestCase;

/**
 * No coverage metadata, deliberately. `coversDefaultClass` was here without a
 * single per-method `covers` to pair with it, so it named a default nothing
 * used — and under `beStrictAboutCoverageMetadata="true"` it still restricted
 * recording, discarding this file's coverage. See
 * {@see AggregationJoinAndCompositeGroupByTest} and #2847.
 *
 * THE ANNOTATION NAMES HERE CARRY NO LEADING AT-SIGN, ON PURPOSE. PHPUnit
 * parses this class docblock, so spelling one out re-declares the very metadata
 * the file exists to remove. Written literally, the method-scoped form was read
 * as a real annotation and CI failed all six PHPUnit cells with "is invalid" —
 * a comment about the bug becoming the bug.
 */
class TimeseriesRequestValidatorTest extends TestCase {

	private TimeseriesRequestValidator $validator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->validator = new TimeseriesRequestValidator();
	}//end setUp()

	/**
	 * A schema mock that returns a fixed property set.
	 *
	 * @param array<string, array<string, mixed>> $properties Property definitions keyed by name.
	 *
	 * @return Schema The configured schema mock.
	 */
	private function schemaWith(array $properties): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		return $schema;
	}//end schemaWith()

	/**
	 * Categorical groupBy: just a field. AggregationQuery.groupBy is
	 * set, dateBucket is null.
	 *
	 * @return void
	 */
	public function testCategoricalGroupByOnDeclaredFieldPasses(): void {
		$schema = $this->schemaWith(['status' => ['type' => 'string']]);

		$query = $this->validator->validate(
			input: ['field' => 'status'],
			schema: $schema
		);

		$this->assertSame('count', $query->metric);
		$this->assertSame('status', $query->getGroupByField());
		$this->assertFalse($query->hasDateBucket());
	}//end testCategoricalGroupByOnDeclaredFieldPasses()

	/**
	 * Time-bucket groupBy: dateBucket is set, groupBy is null.
	 *
	 * @return void
	 */
	public function testTimeBucketDayPasses(): void {
		$schema = $this->schemaWith(
			[
				'created' => ['type' => 'string', 'format' => 'date-time'],
			]
		);

		$query = $this->validator->validate(
			input: [
				'field' => 'created',
				'interval' => 'DAY',
				'from' => '2026-05-01T00:00:00Z',
				'to' => '2026-05-22T00:00:00Z',
			],
			schema: $schema
		);

		$this->assertTrue($query->hasDateBucket());
		$this->assertSame('day', $query->dateBucket['gap']);
		$this->assertNull($query->groupBy);
	}//end testTimeBucketDayPasses()

	/**
	 * @return void
	 */
	public function testEmptyFieldIsRejected(): void {
		$schema = $this->schemaWith(['status' => ['type' => 'string']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('`field` is required');

		$this->validator->validate(input: ['field' => ''], schema: $schema);
	}//end testEmptyFieldIsRejected()

	/**
	 * @return void
	 */
	public function testUnknownFieldIsRejected(): void {
		$schema = $this->schemaWith(['status' => ['type' => 'string']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('__totally_made_up');

		$this->validator->validate(
			input: ['field' => '__totally_made_up'],
			schema: $schema
		);
	}//end testUnknownFieldIsRejected()

	/**
	 * @return void
	 */
	public function testMagicMetadataFieldIsAllowed(): void {
		$schema = $this->schemaWith([]);

		$query = $this->validator->validate(
			input: [
				'field' => '_created',
				'interval' => 'DAY',
				'from' => '2026-05-01T00:00:00Z',
				'to' => '2026-05-22T00:00:00Z',
			],
			schema: $schema
		);

		$this->assertTrue($query->hasDateBucket());
	}//end testMagicMetadataFieldIsAllowed()

	/**
	 * @return void
	 */
	public function testSubDayIntervalOnDateOnlyFieldIsRejected(): void {
		$schema = $this->schemaWith(
			[
				'meetingDate' => ['type' => 'string', 'format' => 'date'],
			]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('sub-day interval');

		$this->validator->validate(
			input: [
				'field' => 'meetingDate',
				'interval' => 'HOUR',
				'from' => '2026-05-21T00:00:00Z',
				'to' => '2026-05-22T00:00:00Z',
			],
			schema: $schema
		);
	}//end testSubDayIntervalOnDateOnlyFieldIsRejected()

	/**
	 * @return void
	 */
	public function testIntervalWithoutBoundsIsRejected(): void {
		$schema = $this->schemaWith(
			[
				'created' => ['type' => 'string', 'format' => 'date-time'],
			]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('`from` and `to` are required');

		$this->validator->validate(
			input: ['field' => 'created', 'interval' => 'DAY'],
			schema: $schema
		);
	}//end testIntervalWithoutBoundsIsRejected()

	/**
	 * @return void
	 */
	public function testUnparseableBoundsAreRejected(): void {
		$schema = $this->schemaWith(
			[
				'created' => ['type' => 'string', 'format' => 'date-time'],
			]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('parseable ISO-8601');

		$this->validator->validate(
			input: [
				'field' => 'created',
				'interval' => 'DAY',
				'from' => 'not-a-date',
				'to' => 'also-not-a-date',
			],
			schema: $schema
		);
	}//end testUnparseableBoundsAreRejected()

	/**
	 * @return void
	 */
	public function testUnknownIntervalIsRejected(): void {
		$schema = $this->schemaWith(
			[
				'created' => ['type' => 'string', 'format' => 'date-time'],
			]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('`interval` MUST be one of');

		$this->validator->validate(
			input: [
				'field' => 'created',
				'interval' => 'CENTURY',
				'from' => '2026-01-01T00:00:00Z',
				'to' => '2026-12-31T00:00:00Z',
			],
			schema: $schema
		);
	}//end testUnknownIntervalIsRejected()

	/**
	 * @return void
	 */
	public function testNonCountMetricWithoutMetricFieldIsRejected(): void {
		$schema = $this->schemaWith(
			[
				'status' => ['type' => 'string'],
				'duration' => ['type' => 'number'],
			]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('`metricField` is required');

		$this->validator->validate(
			input: ['field' => 'status', 'metric' => 'sum'],
			schema: $schema
		);
	}//end testNonCountMetricWithoutMetricFieldIsRejected()

	/**
	 * @return void
	 */
	public function testNonCountMetricWithUnknownMetricFieldIsRejected(): void {
		$schema = $this->schemaWith(['status' => ['type' => 'string']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not a declared property');

		$this->validator->validate(
			input: [
				'field' => 'status',
				'metric' => 'sum',
				'metricField' => '__unknown',
			],
			schema: $schema
		);
	}//end testNonCountMetricWithUnknownMetricFieldIsRejected()

	/**
	 * @return void
	 */
	public function testSumOverDeclaredFieldPasses(): void {
		$schema = $this->schemaWith(
			[
				'status' => ['type' => 'string'],
				'duration' => ['type' => 'number'],
			]
		);

		$query = $this->validator->validate(
			input: [
				'field' => 'status',
				'metric' => 'sum',
				'metricField' => 'duration',
			],
			schema: $schema
		);

		$this->assertSame('sum', $query->metric);
		$this->assertSame('duration', $query->field);
		$this->assertSame('status', $query->getGroupByField());
	}//end testSumOverDeclaredFieldPasses()

	// -----------------------------------------------------------------------
	// Cumulative running total (REQ-AGG-103).
	// -----------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function testCumulativeTrueWithIntervalPasses(): void {
		$schema = $this->schemaWith(['created' => ['type' => 'string', 'format' => 'date-time']]);

		$query = $this->validator->validate(
			input: [
				'field' => 'created',
				'interval' => 'DAY',
				'from' => '2026-05-01T00:00:00Z',
				'to' => '2026-05-22T00:00:00Z',
				'cumulative' => 'true',
			],
			schema: $schema
		);

		$this->assertTrue($query->isCumulative());

	}//end testCumulativeTrueWithIntervalPasses()

	/**
	 * @return void
	 */
	public function testCumulativeAcceptsBooleanAndStringOneAsTruthy(): void {
		$schema = $this->schemaWith(['created' => ['type' => 'string', 'format' => 'date-time']]);

		$withBool = $this->validator->validate(
			input: [
				'field' => 'created',
				'interval' => 'DAY',
				'from' => '2026-05-01T00:00:00Z',
				'to' => '2026-05-22T00:00:00Z',
				'cumulative' => true,
			],
			schema: $schema
		);
		$withOne = $this->validator->validate(
			input: [
				'field' => 'created',
				'interval' => 'DAY',
				'from' => '2026-05-01T00:00:00Z',
				'to' => '2026-05-22T00:00:00Z',
				'cumulative' => '1',
			],
			schema: $schema
		);

		$this->assertTrue($withBool->isCumulative());
		$this->assertTrue($withOne->isCumulative());

	}//end testCumulativeAcceptsBooleanAndStringOneAsTruthy()

	/**
	 * @return void
	 */
	public function testCumulativeDefaultsToFalseWhenAbsent(): void {
		$schema = $this->schemaWith(['status' => ['type' => 'string']]);

		$query = $this->validator->validate(input: ['field' => 'status'], schema: $schema);

		$this->assertFalse($query->isCumulative());

	}//end testCumulativeDefaultsToFalseWhenAbsent()

	/**
	 * @return void
	 */
	public function testCumulativeWithoutIntervalIsRejected(): void {
		$schema = $this->schemaWith(['status' => ['type' => 'string']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('`cumulative` requires `interval`');

		$this->validator->validate(
			input: ['field' => 'status', 'cumulative' => 'true'],
			schema: $schema
		);

	}//end testCumulativeWithoutIntervalIsRejected()
}//end class
