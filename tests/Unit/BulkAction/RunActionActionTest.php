<?php

/**
 * Unit tests for the bulk action that runs a declared object action per member.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BulkAction;

use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\RunActionAction;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RunActionActionTest extends TestCase {

	private ObjectActionRunner&MockObject $runner;

	private ObjectService&MockObject $objects;

	private RunActionAction $action;

	protected function setUp(): void {
		parent::setUp();

		$this->runner = $this->createMock(ObjectActionRunner::class);
		$this->objects = $this->createMock(ObjectService::class);
		$schemas = $this->createMock(MacroActionResolver::class);
		$schemas->method('loadSchema')->willReturn(new Schema());
		$this->action = new RunActionAction($this->runner, $schemas, $this->objects);
	}//end setUp()

	/**
	 * A ticket.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('t-1');
		$object->setRegister('3');
		$object->setSchema('7');
		return $object;
	}//end object()

	public function testThePreviewAsksTheRunsChecksAndRunsNothing(): void {
		$this->runner->expects($this->once())->method('check')->with($this->anything(), $this->anything(), 'assign-to-me', [])
			->willReturn(['refusal' => null, 'definition' => null, 'inputs' => []]);
		$this->runner->expects($this->never())->method('run');

		$result = $this->action->apply($this->object(), ['action' => 'assign-to-me'], false);

		$this->assertTrue($result->isApplied());
	}//end testThePreviewAsksTheRunsChecksAndRunsNothing()

	public function testAPreviewRefusalIsReportedPerMember(): void {
		$this->runner->method('check')->willReturn(
			['refusal' => ['status' => Http::STATUS_CONFLICT, 'body' => ['message' => 'Already assigned.']], 'definition' => null, 'inputs' => []]
		);

		$result = $this->action->apply($this->object(), ['action' => 'assign-to-me'], false);

		$this->assertSame('skipped', $result->getOutcome());
		$this->assertSame('Already assigned.', $result->getReason());
	}//end testAPreviewRefusalIsReportedPerMember()

	public function testTheCommitRunsAsThePersonWhoStartedTheJob(): void {
		$actor = $this->createMock(IUser::class);
		$this->objects->expects($this->once())->method('runAs')->with($actor)->willReturnCallback(static fn (IUser $user, callable $run) => $run());
		$this->runner->expects($this->once())->method('run')
			->with($this->anything(), $this->anything(), 'assign-to-me', ['note' => 'x'], ['uuid' => 't-1', 'register' => 'tickets', 'schema' => 'ticket'])
			->willReturn(['status' => Http::STATUS_OK, 'body' => []]);

		$result = $this->action->apply($this->object(), ['action' => 'assign-to-me', 'inputs' => ['note' => 'x'], 'register' => 'tickets', 'schema' => 'ticket'], true, $actor);

		$this->assertTrue($result->isApplied());
	}//end testTheCommitRunsAsThePersonWhoStartedTheJob()

	public function testAFailedRunIsAFailedMember(): void {
		$this->runner->method('run')->willReturn(['status' => Http::STATUS_UNPROCESSABLE_ENTITY, 'body' => ['error' => 'not-available']]);

		$result = $this->action->apply($this->object(), ['action' => 'hand-over'], true);

		$this->assertSame('failed', $result->getOutcome());
	}//end testAFailedRunIsAFailedMember()

	public function testParametersMustNameTheAction(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->action->validateParameters(['inputs' => []]);
	}//end testParametersMustNameTheAction()
}//end class
