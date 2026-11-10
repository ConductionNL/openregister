<?php

/**
 * SchemaMigrationPlanner: what a failing plan returns (the original data plus the
 * steps applied before the failure), no-op steps that leave the result unchanged,
 * cast targets the caster does not support, and non-scalar template values.
 *
 * SchemaMigrationPlannerTest covers each transform on the happy path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
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

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner
 */
class SchemaMigrationPlannerFailureTest extends TestCase {

	public function testAnUnknownOpFailsTheRunKeepingTheOriginalDataAndTheStepsAlreadyApplied(): void {
		$data = ['a' => 1, 'b' => 2];

		$result = (new SchemaMigrationPlanner())->apply($data, [
			['op' => 'rename', 'from' => 'a', 'to' => 'c'],
			['op' => 'explode', 'field' => 'b'],
			['op' => 'drop', 'field' => 'b'],
		]);

		$this->assertTrue($result->isFailed());
		$this->assertSame('Unknown transform op "explode".', $result->getFailure());
		$this->assertSame($data, $result->getData());
		$this->assertFalse($result->isChanged());
		$this->assertSame(['rename a -> c'], $result->getApplied());
	}

	public function testStepsThatFindNothingToDoLeaveTheResultUnchanged(): void {
		$data = ['present' => 'x', 'empty' => null];

		$result = (new SchemaMigrationPlanner())->apply($data, [
			['op' => 'rename', 'from' => 'missing', 'to' => 'other'],
			['op' => 'cast', 'field' => 'empty', 'to' => 'integer'],
			['op' => 'cast', 'field' => 'missing', 'to' => 'integer'],
			['op' => 'setDefault', 'field' => 'present', 'value' => 'y'],
		]);

		$this->assertFalse($result->isFailed());
		$this->assertFalse($result->isChanged());
		$this->assertSame($data, $result->getData());
		$this->assertSame(['rename missing -> other', 'cast empty -> integer', 'cast missing -> integer', 'setDefault present'], $result->getApplied());
	}

	public function testACastToATypeTheCasterDoesNotKnowIsAPlanProblem(): void {
		$problems = (new SchemaMigrationPlanner())->validatePlan([
			['op' => 'cast', 'field' => 'size', 'to' => 'float'],
			['op' => 'cast', 'field' => 'size', 'to' => 'number'],
		]);

		$this->assertSame(['cast transform #0 has unsupported target type "float".'], $problems);
	}

	public function testANonArrayStepOrOneWithoutOpIsMissingItsOp(): void {
		$problems = (new SchemaMigrationPlanner())->validatePlan(['rename', ['field' => 'x'], ['op' => 'drop', 'field' => 'x']]);

		$this->assertSame(['Transform #0 is missing an "op" key.', 'Transform #1 is missing an "op" key.'], $problems);
	}

	public function testComputeAndSetDefaultNeedTheirKeysPresentEvenWhenEmpty(): void {
		$planner = new SchemaMigrationPlanner();

		$this->assertSame([], $planner->validatePlan([['op' => 'compute', 'field' => 'x', 'template' => ''], ['op' => 'setDefault', 'field' => 'y', 'value' => null]]));
		$this->assertSame(
			['compute transform #0 requires "field" and "template".', 'setDefault transform #1 requires "field" and "value".'],
			$planner->validatePlan([['op' => 'compute', 'field' => 'x'], ['op' => 'setDefault', 'field' => 'y']])
		);
	}

	public function testTheBuiltInTemplateRendersNonScalarAndMissingValuesAsEmpty(): void {
		$result = (new SchemaMigrationPlanner())->apply(
			['name' => 'Ada', 'tags' => ['x'], 'n' => 3],
			[['op' => 'compute', 'field' => 'label', 'template' => '{{ name }}/{{tags}}/{{missing}}/{{n}}']]
		);

		$this->assertSame('Ada///3', $result->getData()['label']);
		$this->assertTrue($result->isChanged());
	}
}
