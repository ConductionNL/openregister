<?php

/**
 * DecisionTablePlan: a decision-table node's mappings, result key and default outputs read from its config.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
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
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Flow\Nodes\DecisionTablePlan;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\DecisionTablePlan
 */
class DecisionTablePlanTest extends TestCase {

	public function testReadsTheConfiguredMappingsKeyAndDefaults(): void {
		$plan = new DecisionTablePlan(['hitPolicy' => 'FIRST'], [
			'inputMapping' => ['age' => 'data.age'],
			'outputMapping' => ['fee' => 'data.fee'],
			'resultKey' => '  decision ',
			'defaultOutputs' => ['fee' => 0],
		]);

		$this->assertSame(['hitPolicy' => 'FIRST'], $plan->table);
		$this->assertSame(['age' => 'data.age'], $plan->inputMapping());
		$this->assertSame(['fee' => 'data.fee'], $plan->outputMapping());
		$this->assertSame('decision', $plan->resultKey());
		$this->assertSame(['fee' => 0], $plan->defaultOutputs());
	}

	public function testAnEmptyConfigHasNoMappingsKeyOrDefaults(): void {
		$plan = new DecisionTablePlan([], []);

		$this->assertSame([], $plan->inputMapping());
		$this->assertSame([], $plan->outputMapping());
		$this->assertSame('', $plan->resultKey());
		$this->assertNull($plan->defaultOutputs());
	}

	public function testAScalarMappingIsWrappedInAList(): void {
		$plan = new DecisionTablePlan([], ['inputMapping' => 'data.age', 'resultKey' => 42, 'defaultOutputs' => false]);

		$this->assertSame(['data.age'], $plan->inputMapping());
		$this->assertSame('42', $plan->resultKey());
		$this->assertFalse($plan->defaultOutputs());
	}
}
