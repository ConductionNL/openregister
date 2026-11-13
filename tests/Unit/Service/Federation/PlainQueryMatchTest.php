<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Service\Federation\PlainQueryMatch;
use PHPUnit\Framework\TestCase;

/**
 * A payload is admitted only when every condition is a plain value it carries.
 *
 * @covers \OCA\OpenRegister\Service\Federation\PlainQueryMatch
 */
class PlainQueryMatchTest extends TestCase {

	private PlainQueryMatch $match;

	protected function setUp(): void {
		$this->match = new PlainQueryMatch();
	}

	public function testAQueryWithoutConditionsAdmitsAnyPayload(): void {
		$this->assertTrue($this->match->admits(filters: [], data: ['status' => 'open']));
	}

	public function testAScalarConditionAdmitsTheSameValue(): void {
		$this->assertTrue($this->match->admits(filters: ['status' => 'open'], data: ['status' => 'open']));
	}

	public function testAScalarConditionRefusesAnotherValue(): void {
		$this->assertFalse($this->match->admits(filters: ['status' => 'open'], data: ['status' => 'closed']));
	}

	public function testValuesAreComparedAsStrings(): void {
		$this->assertTrue($this->match->admits(filters: ['year' => 2026], data: ['year' => '2026']));
		$this->assertTrue($this->match->admits(filters: ['year' => '2026'], data: ['year' => 2026]));
	}

	public function testAListConditionAdmitsAnyOfItsValues(): void {
		$filters = ['status' => ['open', 'pending']];

		$this->assertTrue($this->match->admits(filters: $filters, data: ['status' => 'pending']));
		$this->assertFalse($this->match->admits(filters: $filters, data: ['status' => 'closed']));
	}

	public function testEveryConditionMustHold(): void {
		$filters = ['status' => 'open', 'type' => 'zaak'];

		$this->assertTrue($this->match->admits(filters: $filters, data: ['status' => 'open', 'type' => 'zaak']));
		$this->assertFalse($this->match->admits(filters: $filters, data: ['status' => 'open', 'type' => 'besluit']));
	}

	public function testAPayloadWithoutThePropertyIsRefused(): void {
		$this->assertFalse($this->match->admits(filters: ['status' => 'open'], data: []));
	}

	public function testANonScalarPayloadValueIsRefused(): void {
		$this->assertFalse($this->match->admits(filters: ['status' => 'open'], data: ['status' => ['open']]));
	}

	public function testAnUnderscoredPropertyIsRefusedEvenWhenItMatches(): void {
		$this->assertFalse($this->match->admits(filters: ['_ids' => 'a'], data: ['_ids' => 'a']));
	}

	/** @return array<string, array{mixed}> */
	public static function unreadableConditions(): array {
		return [
			'an empty list'            => [[]],
			'an operator map'          => [['$gt' => 3]],
			'a list holding an array'  => [['open', ['nested']]],
			'a null'                   => [null],
		];
	}

	/**
	 * @dataProvider unreadableConditions
	 *
	 * @param mixed $wanted The condition.
	 */
	public function testAConditionThatIsNotPlainAdmitsNothing(mixed $wanted): void {
		$this->assertFalse($this->match->admits(filters: ['status' => $wanted], data: ['status' => 'open']));
	}
}
