<?php

/**
 * Unit tests for the `action.run` entry: storable inputs only, the acting user, the outcome.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionAudit;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunOutcome;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunRecorder;
use PHPUnit\Framework\TestCase;

class ObjectActionRunRecorderTest extends TestCase {

	public function testTheEntryDigestsOnlyStorableInputsAsTheActingUser(): void {
		$inputs = new ObjectActionInputs(new OasRequestValidator());
		$gate   = $this->createMock(ObjectActionGate::class);
		$gate->method('userId')->willReturn('anna');
		$object = new ObjectEntity();
		$audit  = $this->createMock(ObjectActionAudit::class);
		$audit->expects($this->once())->method('record')->with(
			$object,
			$this->callback(fn ($definition): bool => $definition->key === 'sign' && $definition->kind === 'steps'),
			$this->callback(
				fn ($outcome): bool => $outcome->run === 'run-1'
					&& $outcome->changes === [['step' => 's', 'kind' => 'write']]
					&& $outcome->outcome === 'completed'
			),
			$inputs->digest(['note' => 'ok']),
			'anna'
		);
		$definition = ObjectActionDefinition::fromDeclaration(
			'sign',
			['steps' => [], 'inputs' => ['properties' => ['pin' => ['writeOnly' => true], 'note' => []]]]
		);

		(new ObjectActionRunRecorder($inputs, $audit, $gate))->record(
			$object,
			$definition,
			['note' => 'ok', 'pin' => '1234'],
			new ObjectActionRunOutcome('run-1', [['step' => 's', 'kind' => 'write']], 'completed')
		);
	}//end testTheEntryDigestsOnlyStorableInputsAsTheActingUser()
}//end class
