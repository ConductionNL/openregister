<?php

/**
 * RuleScope: reading a rule's `scope` declaration and deciding whether a filter
 * (the values a caller is asking about) falls inside it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rules\RuleScope;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rules\RuleScope
 */
class RuleScopeTest extends TestCase {

	public function testAScopeIsAPropertyAndAScalarValueAsString(): void {
		$scope = new RuleScope();

		$this->assertSame(['property' => 'type', 'value' => 'permit'], $scope->fromDeclaration(['scope' => ['property' => 'type', 'value' => 'permit']]));
		$this->assertSame(['property' => 'year', 'value' => '2026'], $scope->fromDeclaration(['scope' => ['property' => 'year', 'value' => 2026]]));
		$this->assertSame(['property' => 'open', 'value' => '1'], $scope->fromDeclaration(['scope' => ['property' => 'open', 'value' => true]]));
	}

	public function testAMissingOrMalformedScopeIsNoScope(): void {
		$scope = new RuleScope();

		$this->assertNull($scope->fromDeclaration([]));
		$this->assertNull($scope->fromDeclaration(['scope' => 'type=permit']));
		$this->assertNull($scope->fromDeclaration(['scope' => ['property' => '', 'value' => 'x']]));
		$this->assertNull($scope->fromDeclaration(['scope' => ['property' => 5, 'value' => 'x']]));
		$this->assertNull($scope->fromDeclaration(['scope' => ['property' => 'type']]));
		$this->assertNull($scope->fromDeclaration(['scope' => ['property' => 'type', 'value' => ['permit']]]));
	}

	public function testAnUnscopedRuleOrAnEmptyFilterAdmitsEverything(): void {
		$scope = new RuleScope();

		$this->assertTrue($scope->admits(null, ['type' => 'other']));
		$this->assertTrue($scope->admits(['property' => 'type', 'value' => 'permit'], []));
	}

	public function testAFilterIsAdmittedOnlyWhenItCarriesTheScopedValue(): void {
		$scope = new RuleScope();
		$permits = ['property' => 'year', 'value' => '2026'];

		$this->assertTrue($scope->admits($permits, ['year' => 2026]));
		$this->assertFalse($scope->admits($permits, ['year' => '2025']));
		$this->assertFalse($scope->admits($permits, ['type' => '2026']));
		$this->assertFalse($scope->admits($permits, ['year' => ['2026']]));
	}
}
