<?php

/**
 * Golden outcomes of FilterParams::forAggregation() and ::warnUnknownKeys(), pinned before
 * their bodies were split into two-parameter helpers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Support\AggregationFilterRequest;
use OCA\OpenRegister\Support\FilterParams;
use OCA\OpenRegister\Support\UnknownFilterKeys;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden outcomes of the two widest FilterParams units.
 */
class FilterParamsInterfacingGoldenTest extends TestCase {

	/**
	 * Key order, the bracket value overriding a bare one in place, a numeric bracket
	 * key, and every unknown key reported once in the order it was met.
	 *
	 * @return void
	 */
	public function testForAggregationKeepsOrderOverridesAndReportsEachUnknownOnce(): void {
		$normalised = FilterParams::forAggregation(
			request: new AggregationFilterRequest(
				bracket: ['hours' => '8', 'ghost' => 'x', '_meta' => 'm', 'id' => 'u-1', 0 => 'zero', 'origni' => 'b'],
				params: ['origin' => 'manual', 'hours' => '1', 'origni' => 'a', 'metric' => 'sum', 'schema' => 's', '_x' => 1, 5 => 'n'],
				controlParams: FilterParams::AGGREGATION_CONTROL_PARAMS['value']
			),
			properties: ['origin' => [], 'hours' => []]
		);

		$this->assertSame(
			[
				'filter'  => ['origin' => 'manual', 'hours' => '8', 'ghost' => 'x', '_meta' => 'm', 'id' => 'u-1', 0 => 'zero', 'origni' => 'b'],
				'unknown' => ['origni', 'ghost'],
			],
			$normalised
		);
	}//end testForAggregationKeepsOrderOverridesAndReportsEachUnknownOnce()

	/**
	 * Nothing in, nothing out.
	 *
	 * @return void
	 */
	public function testForAggregationWithNothingGivesAnEmptyFilter(): void {
		$this->assertSame(
			['filter' => [], 'unknown' => []],
			FilterParams::forAggregation(request: new AggregationFilterRequest(bracket: [], params: [], controlParams: []), properties: [])
		);
	}//end testForAggregationWithNothingGivesAnEmptyFilter()

	/**
	 * The exact warning line and context.
	 *
	 * @return void
	 */
	public function testWarnUnknownKeysWritesTheExactLineAndContext(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[OpenRegister] aggregations#value: filter key(s) "a", "b" name no property of schema "sch" in register "reg",'
			. ' so they do not filter on a property. Check the spelling; an unknown filter key is meant to become an HTTP 400.',
			['app' => 'openregister', 'endpoint' => 'aggregations#value', 'register' => 'reg', 'schema' => 'sch', 'keys' => ['a', 'b']]
		);

		FilterParams::warnUnknownKeys(logger: $logger, unknown: new UnknownFilterKeys(keys: [3 => 'a', 7 => 'b'], endpoint: 'aggregations#value', table: new RegisterSchemaRef(register: 'reg', schema: 'sch')));
	}//end testWarnUnknownKeysWritesTheExactLineAndContext()

	/**
	 * A null logger is a no-op, with or without keys.
	 *
	 * @return void
	 */
	public function testWarnUnknownKeysWithoutALoggerDoesNothing(): void {
		FilterParams::warnUnknownKeys(logger: null, unknown: new UnknownFilterKeys(keys: ['a'], endpoint: 'objects#index', table: new RegisterSchemaRef(register: 'r', schema: 's')));

		$this->addToAssertionCount(1);
	}//end testWarnUnknownKeysWithoutALoggerDoesNothing()
}//end class
