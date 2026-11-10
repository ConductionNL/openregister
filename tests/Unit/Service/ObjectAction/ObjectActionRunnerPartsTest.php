<?php

/**
 * Unit tests for the runner's dependency bundles and its run outcome value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionFlowEngines;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunnerChecks;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunOutcome;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionStepsFlow;
use PHPUnit\Framework\TestCase;

class ObjectActionRunnerPartsTest extends TestCase {

	public function testTheBundlesHandOutWhatTheyAreGiven(): void {
		$definitions = $this->createMock(ObjectActionDefinitions::class);
		$gate        = $this->createMock(ObjectActionGate::class);
		$inputs      = $this->createMock(ObjectActionInputs::class);
		$checks      = new ObjectActionRunnerChecks($definitions, $gate, $inputs);

		$steps   = $this->createMock(ObjectActionStepsFlow::class);
		$flows   = $this->createMock(FlowService::class);
		$macros  = $this->createMock(MacroActionResolver::class);
		$engines = new ObjectActionFlowEngines($steps, $flows, $macros);

		$this->assertSame([$definitions, $gate, $inputs], [$checks->definitions, $checks->gate, $checks->inputs]);
		$this->assertSame([$steps, $flows, $macros], [$engines->steps, $engines->flows, $engines->macros]);
	}//end testTheBundlesHandOutWhatTheyAreGiven()

	public function testAnOutcomeKeepsItsRunChangesAndOutcome(): void {
		$outcome = new ObjectActionRunOutcome(null, [], 'completed');

		$this->assertSame([null, [], 'completed'], [$outcome->run, $outcome->changes, $outcome->outcome]);
	}//end testAnOutcomeKeepsItsRunChangesAndOutcome()
}//end class
