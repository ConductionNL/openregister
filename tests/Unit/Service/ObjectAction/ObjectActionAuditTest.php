<?php

/**
 * Characterisation tests for the `action.run` audit entry: its context, its actor and a failed write.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionAudit;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunOutcome;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ObjectActionAuditTest extends TestCase {

	public function testTheEntryCarriesTheRunAsContextAndTheActingUser(): void {
		$object = new ObjectEntity();
		$trail  = $this->createMock(AuditTrailMapperInterface::class);
		$trail->expects($this->once())->method('createAuditTrailEntry')->with(
			$object,
			ObjectActionAudit::ACTION_RUN,
			[
				'declaredAction' => 'sign',
				'kind' => 'steps',
				'run' => 'run-1',
				'inputsDigest' => 'sha256:abc',
				'changes' => [['step' => 's', 'kind' => 'write']],
				'outcome' => 'completed',
			],
			'anna'
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		(new ObjectActionAudit($trail, $logger))->record(
			$object,
			ObjectActionDefinition::fromDeclaration('sign', ['steps' => []]),
			new ObjectActionRunOutcome('run-1', [['step' => 's', 'kind' => 'write']], 'completed'),
			'sha256:abc',
			'anna'
		);
	}//end testTheEntryCarriesTheRunAsContextAndTheActingUser()

	public function testATransitionHasNoRunAndMayHaveNoActor(): void {
		$object = new ObjectEntity();
		$trail  = $this->createMock(AuditTrailMapperInterface::class);
		$trail->expects($this->once())->method('createAuditTrailEntry')->with(
			$object,
			ObjectActionAudit::ACTION_RUN,
			[
				'declaredAction' => 'finish',
				'kind' => 'transition',
				'run' => null,
				'inputsDigest' => 'sha256:def',
				'changes' => [],
				'outcome' => 'completed',
			],
			null
		);

		(new ObjectActionAudit($trail, $this->createMock(LoggerInterface::class)))->record(
			$object,
			ObjectActionDefinition::fromDeclaration('finish', ['transition' => 'finish']),
			new ObjectActionRunOutcome(null, [], 'completed'),
			'sha256:def',
			null
		);
	}//end testATransitionHasNoRunAndMayHaveNoActor()

	public function testAFailedWriteIsLoggedAndNeverThrown(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->method('createAuditTrailEntry')->willThrowException(new RuntimeException('disk full'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[ObjectActionAudit] Could not write the audit entry for action "sign": disk full',
			['action' => 'sign', 'run' => 'run-1']
		);

		(new ObjectActionAudit($trail, $logger))->record(
			new ObjectEntity(),
			ObjectActionDefinition::fromDeclaration('sign', ['steps' => []]),
			new ObjectActionRunOutcome('run-1', [], 'failed'),
			'sha256:abc',
			'anna'
		);
		$this->addToAssertionCount(1);
	}//end testAFailedWriteIsLoggedAndNeverThrown()
}//end class
