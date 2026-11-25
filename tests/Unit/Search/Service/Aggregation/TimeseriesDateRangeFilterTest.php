<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Search\Service\Aggregation\TimeseriesDateRangeFilter}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/specs/unit-test-coverage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesDateRangeFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A named date field bounds a categorical count to the half-open period
 * `[from, to)`; a request without one is left as it was, and every request
 * that would give two answers to "which period" is refused.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\TimeseriesDateRangeFilter
 */
class TimeseriesDateRangeFilterTest extends TestCase {

	/**
	 * A schema with one date, one date-time and one plain string property.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'opened' => ['type' => 'string', 'format' => 'date'],
				'closedAt' => ['type' => 'string', 'format' => 'date-time'],
				'district' => ['type' => 'string'],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * Without `dateField` the filter comes back unchanged, whatever else the request carries.
	 *
	 * @return void
	 */
	public function testNoDateFieldLeavesTheFilterUnchanged(): void {
		$filter = ['district' => 'Noord'];
		$input  = ['dateField' => '   ', 'from' => 'nonsense', 'interval' => 'month'];

		$this->assertSame($filter, (new TimeseriesDateRangeFilter())->apply(input: $input, schema: self::schema(), filter: $filter));
	}//end testNoDateFieldLeavesTheFilterUnchanged()

	/**
	 * A declared date property gets `gte` from and `lt` to, beside the rest of the filter.
	 *
	 * @return void
	 */
	public function testADeclaredDateFieldIsBoundedHalfOpen(): void {
		$result = (new TimeseriesDateRangeFilter())->apply(
			input: ['dateField' => ' opened ', 'from' => '2026-03-01', 'to' => '2026-04-01'],
			schema: self::schema(),
			filter: ['district' => 'Noord']
		);

		$this->assertSame(['district' => 'Noord', 'opened' => ['gte' => '2026-03-01', 'lt' => '2026-04-01']], $result);
	}//end testADeclaredDateFieldIsBoundedHalfOpen()

	/**
	 * A metadata date column needs no declaration, and an empty interval is no interval.
	 *
	 * @return void
	 */
	public function testAMetadataColumnIsAcceptedWithoutADeclaration(): void {
		$result = (new TimeseriesDateRangeFilter())->apply(
			input: ['dateField' => '_created', 'from' => '2026-01-01T00:00:00Z', 'to' => '2026-02-01T00:00:00Z', 'interval' => ''],
			schema: new Schema(),
			filter: []
		);

		$this->assertSame(['_created' => ['gte' => '2026-01-01T00:00:00Z', 'lt' => '2026-02-01T00:00:00Z']], $result);
	}//end testAMetadataColumnIsAcceptedWithoutADeclaration()

	/**
	 * Other operators already on the field are kept beside the period.
	 *
	 * @return void
	 */
	public function testAnotherOperatorOnTheFieldIsKept(): void {
		$result = (new TimeseriesDateRangeFilter())->apply(
			input: ['dateField' => 'closedAt', 'from' => '2026-03-01', 'to' => '2026-04-01'],
			schema: self::schema(),
			filter: ['closedAt' => ['ne' => null]]
		);

		$this->assertSame(['closedAt' => ['ne' => null, 'gte' => '2026-03-01', 'lt' => '2026-04-01']], $result);
	}//end testAnotherOperatorOnTheFieldIsKept()

	/**
	 * The requests refused, each with the words that tell the caller why.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
	 */
	public static function refusedRequests(): array {
		$march = ['from' => '2026-03-01', 'to' => '2026-04-01'];

		return [
			'with an interval' => [['dateField' => 'opened', 'interval' => 'month'] + $march, [], 'with `interval`'],
			'an undeclared property' => [['dateField' => 'missing'] + $march, [], 'is not a declared property'],
			'a property that is no date' => [['dateField' => 'district'] + $march, [], 'it has "unspecified"'],
			'no to' => [['dateField' => 'opened', 'from' => '2026-03-01'], [], '`from` and `to` are required'],
			'no from' => [['dateField' => 'opened', 'to' => '2026-03-01'], [], '`from` and `to` are required'],
			'an unparseable bound' => [['dateField' => 'opened', 'from' => 'not a date', 'to' => '2026-04-01'], [], 'parseable ISO-8601'],
			'an empty period' => [['dateField' => 'opened', 'from' => '2026-04-01', 'to' => '2026-04-01'], [], 'must lie before `to`'],
			'a reversed period' => [['dateField' => 'opened', 'from' => '2026-05-01', 'to' => '2026-04-01'], [], 'must lie before `to`'],
			'matched by value' => [['dateField' => 'opened'] + $march, ['opened' => '2026-03-15'], 'is also matched by value'],
			'matched by a list' => [['dateField' => 'opened'] + $march, ['opened' => ['2026-03-15']], 'is also matched by value'],
			'already bounded below' => [['dateField' => 'opened'] + $march, ['opened' => ['gt' => '2026-01-01']], '`filter[opened][gt]`'],
			'already bounded above' => [['dateField' => 'opened'] + $march, ['opened' => ['lte' => '2026-12-31']], '`filter[opened][lte]`'],
		];
	}//end refusedRequests()

	/**
	 * Each refused request throws, naming its reason.
	 *
	 * @param array<string, mixed> $input    The request shape.
	 * @param array<string, mixed> $filter   The request's filter.
	 * @param string               $expected A fragment of the message.
	 *
	 * @return void
	 */
	#[DataProvider('refusedRequests')]
	public function testARefusedRequestNamesWhy(array $input, array $filter, string $expected): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($expected);

		(new TimeseriesDateRangeFilter())->apply(input: $input, schema: self::schema(), filter: $filter);
	}//end testARefusedRequestNamesWhy()

	/**
	 * A date-time property's actual format is named when it is not a date.
	 *
	 * @return void
	 */
	public function testTheWrongFormatIsNamed(): void {
		$schema = new Schema();
		$schema->setProperties(['when' => ['type' => 'string', 'format' => 'time']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must have format date or date-time; it has "time"');

		(new TimeseriesDateRangeFilter())->apply(input: ['dateField' => 'when', 'from' => '2026-03-01', 'to' => '2026-04-01'], schema: $schema, filter: []);
	}//end testTheWrongFormatIsNamed()
}//end class
