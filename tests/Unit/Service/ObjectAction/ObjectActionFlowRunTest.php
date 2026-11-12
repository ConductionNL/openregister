<?php

/**
 * Unit tests for the flow body: writeOnly inputs held only while the run starts, failures logged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionFlowEngines;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionFlowRun;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInvocation;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunRecorder;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionStepsFlow;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionTarget;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ObjectActionFlowRunTest extends TestCase {

	private FlowService&MockObject $flows;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->flows  = $this->createMock(FlowService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The flow body over mocked engines.
	 *
	 * @return ObjectActionFlowRun The body.
	 */
	private function body(): ObjectActionFlowRun {
		$steps = $this->createMock(ObjectActionStepsFlow::class);
		$steps->method('flowFor')->willReturn('steps-flow');

		return new ObjectActionFlowRun(
			new ObjectActionFlowEngines($steps, $this->flows, $this->createMock(MacroActionResolver::class)),
			new ObjectActionInputs(new OasRequestValidator()),
			$this->createMock(ObjectActionRunRecorder::class),
			$this->logger
		);
	}//end body()

	/**
	 * A steps action with a writeOnly pin.
	 *
	 * @return ObjectActionDefinition The action.
	 */
	private function sign(): ObjectActionDefinition {
		return ObjectActionDefinition::fromDeclaration('sign', ['steps' => [], 'inputs' => ['properties' => ['pin' => ['writeOnly' => true]]]]);
	}//end sign()

	public function testTheSecretIsHeldWhileTheRunStartsAndReleasedAfter(): void {
		$ref = null;
		$this->flows->method('runForAction')->willReturnCallback(
			function (string $uuid, \OCA\OpenRegister\Service\Flow\FlowRunOptions $options) use (&$ref): FlowRun {
				$this->assertSame('steps-flow', $uuid);
				$this->assertSame(['action' => 'sign', 'inputs' => []], array_diff_key($options->context, [ObjectActionSecrets::CONTEXT_KEY => true]));
				$ref = $options->context[ObjectActionSecrets::CONTEXT_KEY];
				$this->assertSame(['pin' => '1234'], ObjectActionSecrets::read($ref));
				$run = new FlowRun();
				$run->setUuid('run-1');
				$run->setStatus(FlowRun::STATUS_COMPLETED);
				return $run;
			}
		);

		$answer = $this->body()->run(new ObjectActionTarget(new ObjectEntity(), new Schema(), '', ''), new ObjectActionInvocation($this->sign(), ['pin' => '1234']));

		$this->assertSame(200, $answer['status']);
		$this->assertSame([], ObjectActionSecrets::read($ref));
	}//end testTheSecretIsHeldWhileTheRunStartsAndReleasedAfter()

	public function testAFailedStartIsLoggedAndAnswered422(): void {
		$this->flows->method('runForAction')->willThrowException(new RuntimeException('No engine.'));
		$this->logger->expects($this->once())->method('warning');

		$answer = $this->body()->run(new ObjectActionTarget(new ObjectEntity(), new Schema(), '', ''), new ObjectActionInvocation($this->sign(), []));

		$this->assertSame(['status' => 422, 'body' => ['error' => 'No engine.', 'action' => 'sign']], $answer);
	}//end testAFailedStartIsLoggedAndAnswered422()
}//end class
