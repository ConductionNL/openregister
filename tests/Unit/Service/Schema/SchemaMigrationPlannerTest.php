<?php

/**
 * Unit tests for SchemaMigrationPlanner.
 *
 * Pure tests (no NC container) covering plan validation, each transform
 * (rename/setDefault/cast/drop/compute), preview-style non-mutation, the
 * no-data-loss guard (a failed transform never partially writes the
 * object), and the uncastable-value failure path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\SchemaMigrationPlanner;
use PHPUnit\Framework\TestCase;

class SchemaMigrationPlannerTest extends TestCase {
	private SchemaMigrationPlanner $planner;

	protected function setUp(): void {
		parent::setUp();
		$this->planner = new SchemaMigrationPlanner();
	}

	public function testValidatePlanRejectsEmpty(): void {
		$problems = $this->planner->validatePlan([]);
		$this->assertNotEmpty($problems);
	}

	public function testValidatePlanRejectsUnknownOp(): void {
		$problems = $this->planner->validatePlan([['op' => 'frobnicate', 'field' => 'x']]);
		$this->assertNotEmpty($problems);
		$this->assertStringContainsString('unknown op', $problems[0]);
	}

	public function testValidatePlanRejectsMissingFields(): void {
		$this->assertNotEmpty($this->planner->validatePlan([['op' => 'rename', 'from' => 'a']]));
		$this->assertNotEmpty($this->planner->validatePlan([['op' => 'cast', 'field' => 'a', 'to' => 'frob']]));
		$this->assertNotEmpty($this->planner->validatePlan([['op' => 'compute', 'field' => 'a']]));
	}

	public function testValidatePlanAcceptsValidPlan(): void {
		$plan = [
			['op' => 'rename', 'from' => 'fullname', 'to' => 'name'],
			['op' => 'setDefault', 'field' => 'status', 'value' => 'active'],
			['op' => 'cast', 'field' => 'age', 'to' => 'integer'],
			['op' => 'drop', 'field' => 'legacy'],
			['op' => 'compute', 'field' => 'label', 'template' => '{{ name }}'],
		];
		$this->assertSame([], $this->planner->validatePlan($plan));
	}

	public function testRename(): void {
		$result = $this->planner->apply(
			['fullname' => 'Ada'],
			[['op' => 'rename', 'from' => 'fullname', 'to' => 'name']]
		);

		$this->assertFalse($result->isFailed());
		$this->assertTrue($result->isChanged());
		$this->assertSame(['name' => 'Ada'], $result->getData());
	}

	public function testSetDefaultOnlyWhenMissingOrNull(): void {
		$r1 = $this->planner->apply([], [['op' => 'setDefault', 'field' => 'status', 'value' => 'active']]);
		$this->assertSame(['status' => 'active'], $r1->getData());

		$r2 = $this->planner->apply(['status' => 'closed'], [['op' => 'setDefault', 'field' => 'status', 'value' => 'active']]);
		$this->assertSame(['status' => 'closed'], $r2->getData());
		$this->assertFalse($r2->isChanged());
	}

	public function testCastStringToInteger(): void {
		$result = $this->planner->apply(['age' => '42'], [['op' => 'cast', 'field' => 'age', 'to' => 'integer']]);
		$this->assertSame(['age' => 42], $result->getData());
	}

	public function testCastBoolean(): void {
		$result = $this->planner->apply(['flag' => 'yes'], [['op' => 'cast', 'field' => 'flag', 'to' => 'boolean']]);
		$this->assertTrue($result->getData()['flag']);
	}

	public function testCastDateWithFormat(): void {
		$result = $this->planner->apply(
			['d' => '15-06-2026'],
			[['op' => 'cast', 'field' => 'd', 'to' => 'date', 'format' => 'd-m-Y']]
		);
		$this->assertFalse($result->isFailed());
		$this->assertStringStartsWith('2026-06-15', $result->getData()['d']);
	}

	public function testDrop(): void {
		$result = $this->planner->apply(['a' => 1, 'b' => 2], [['op' => 'drop', 'field' => 'b']]);
		$this->assertSame(['a' => 1], $result->getData());
	}

	public function testComputeDefaultTemplate(): void {
		$result = $this->planner->apply(
			['first' => 'Ada', 'last' => 'Lovelace'],
			[['op' => 'compute', 'field' => 'name', 'template' => '{{ first }} {{ last }}']]
		);
		$this->assertSame('Ada Lovelace', $result->getData()['name']);
	}

	public function testComputeInjectedRenderer(): void {
		$planner = new SchemaMigrationPlanner(
			static function (string $tpl, array $ctx): string {
				return strtoupper($ctx['name'] ?? '');
			}
		);
		$result = $planner->apply(['name' => 'ada'], [['op' => 'compute', 'field' => 'shout', 'template' => 'x']]);
		$this->assertSame('ADA', $result->getData()['shout']);
	}

	public function testUncastableValueFailsAndPreservesOriginal(): void {
		$input = ['age' => 'unknown', 'name' => 'Ada'];
		$result = $this->planner->apply(
			$input,
			[
				['op' => 'rename', 'from' => 'name', 'to' => 'fullname'],
				['op' => 'cast', 'field' => 'age', 'to' => 'integer'],
			]
		);

		// No-data-loss guard: a failed transform returns the ORIGINAL
		// data untouched, never a half-applied object.
		$this->assertTrue($result->isFailed());
		$this->assertStringContainsString('integer', (string)$result->getFailure());
		$this->assertSame($input, $result->getData());
		$this->assertFalse($result->isChanged());
	}

	public function testApplyDoesNotMutateInput(): void {
		$input = ['fullname' => 'Ada'];
		$this->planner->apply($input, [['op' => 'rename', 'from' => 'fullname', 'to' => 'name']]);
		// Caller's array is unchanged (preview never mutates).
		$this->assertSame(['fullname' => 'Ada'], $input);
	}

	public function testChainOfTransforms(): void {
		$result = $this->planner->apply(
			['fullname' => 'Ada', 'age' => '40'],
			[
				['op' => 'rename', 'from' => 'fullname', 'to' => 'name'],
				['op' => 'cast', 'field' => 'age', 'to' => 'integer'],
				['op' => 'setDefault', 'field' => 'status', 'value' => 'active'],
			]
		);

		$this->assertFalse($result->isFailed());
		$this->assertEqualsCanonicalizing(['name' => 'Ada', 'age' => 40, 'status' => 'active'], $result->getData());
	}
}
