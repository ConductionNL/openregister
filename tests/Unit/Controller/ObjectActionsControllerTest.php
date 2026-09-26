<?php

/**
 * Unit tests for invoking a declared macro action on one object.
 *
 * The authorisation is the point: the action's own right must be checked BEFORE
 * anything is queued, because a run started and then refused inside has already
 * written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectActionsController;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowNextHint;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectActionsControllerTest extends TestCase {

	private ObjectService&MockObject $objects;

	private SchemaMapper&MockObject $schemas;

	private PermissionHandler&MockObject $permissions;

	private FlowService&MockObject $flows;

	private ObjectActionsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->permissions = $this->createMock(PermissionHandler::class);
		$this->flows = $this->createMock(FlowService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		// The REAL resolver over the same two doubles the controller used to
		// take directly. It reads the schema's own declarations, and a double
		// of it would answer whatever a test asked for — including a binding
		// the schema never declared, which is the refusal these tests exist
		// to pin.
		$this->controller = new ObjectActionsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->objects,
			new MacroActionResolver(schemas: $this->schemas, flows: $this->flows),
			$this->permissions,
			$this->flows,
			$session,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A real Schema carrying one macro binding: Entity getters are magic and a
	 * mock cannot answer getConfiguration().
	 *
	 * @param array $declaration The declared action.
	 *
	 * @return Schema
	 */
	private function schema(array $declaration = ['macro' => true, 'flow' => 'flow-1']): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setTitle('Zaak');
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					'close-and-notify' => array_merge(
						['name' => 'Close and notify', 'description' => 'Close it and tell them.'],
						$declaration
					),
				],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * The object the macro runs against.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('5');
		$object->setRegister('3');
		$object->setOwner('bob');
		return $object;
	}//end object()

	/**
	 * A finished run.
	 *
	 * @return FlowRun
	 */
	private function finishedRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-9');
		$run->setStatus('completed');
		return $run;
	}//end finishedRun()

	/**
	 * The happy path: the run's id, its outcome and the hint.
	 *
	 * @return void
	 */
	public function testAnAuthorisedMacroRunsAndAnswersTheRunAndTheHint(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->schemas->method('find')->willReturn($this->schema());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->flows->method('run')->willReturn($this->finishedRun());

		$flow = new Flow();
		$flow->setNodes([['type' => FlowNextHint::MANUAL_TRIGGER, 'config' => ['next' => 'next']]]);
		$this->flows->method('find')->willReturn($flow);

		$response = $this->controller->invoke('zaken', 'zaak', 'obj-1', 'close-and-notify');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['run' => 'run-9', 'outcome' => 'completed', 'action' => 'close-and-notify', 'next' => 'next'],
			$response->getData()
		);
	}//end testAnAuthorisedMacroRunsAndAnswersTheRunAndTheHint()

	/**
	 * A caller without the action's right is refused, and NOTHING is queued.
	 *
	 * Paired with the happy path on purpose: a controller that refused
	 * everything would pass this test on its own.
	 *
	 * @return void
	 */
	public function testACallerWithoutTheActionsRightQueuesNothing(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->schemas->method('find')->willReturn($this->schema());
		$this->permissions->method('hasPermission')->willReturn(false);
		$this->flows->expects($this->never())->method('run');

		$response = $this->controller->invoke('zaken', 'zaak', 'obj-1', 'close-and-notify');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testACallerWithoutTheActionsRightQueuesNothing()

	/**
	 * The right is checked on the ACTION the caller named, not on `update`.
	 *
	 * Checking a CRUD verb instead would let anyone who may edit a case run
	 * every macro bound to it, which is the whole point of declaring an action.
	 *
	 * @return void
	 */
	public function testTheRightCheckedIsTheActionsOwn(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->schemas->method('find')->willReturn($this->schema());
		$this->flows->method('run')->willReturn($this->finishedRun());
		$this->flows->method('find')->willReturn(new Flow());

		$seen = null;
		$this->permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action) use (&$seen): bool {
				$seen = $action;
				return true;
			}
		);

		$this->controller->invoke('zaken', 'zaak', 'obj-1', 'close-and-notify');

		$this->assertSame('close-and-notify', $seen);
	}//end testTheRightCheckedIsTheActionsOwn()

	/**
	 * An action the schema does not bind to a flow is a 404, and runs nothing.
	 *
	 * The caller does not get to name the flow: the binding is the schema's.
	 *
	 * @return void
	 */
	public function testAnActionWithNoBindingRunsNothing(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->schemas->method('find')->willReturn($this->schema());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->flows->expects($this->never())->method('run');

		$response = $this->controller->invoke('zaken', 'zaak', 'obj-1', 'some-other-action');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnActionWithNoBindingRunsNothing()

	/**
	 * A declaration that names a flow without `macro: true` is not a binding.
	 *
	 * @return void
	 */
	public function testAFlowWithoutMacroTrueIsNotInvokable(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->schemas->method('find')->willReturn($this->schema(['flow' => 'flow-1']));
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->flows->expects($this->never())->method('run');

		$response = $this->controller->invoke('zaken', 'zaak', 'obj-1', 'close-and-notify');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAFlowWithoutMacroTrueIsNotInvokable()

	/**
	 * A missing object is a 404 before any permission is consulted.
	 *
	 * @return void
	 */
	public function testAMissingObjectIsNotFound(): void {
		$this->objects->method('find')->willReturn(null);
		$this->permissions->expects($this->never())->method('hasPermission');

		$response = $this->controller->invoke('zaken', 'zaak', 'gone', 'close-and-notify');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAMissingObjectIsNotFound()

	/**
	 * A flow that refuses answers 422 with its reason, not a 500.
	 *
	 * @return void
	 */
	public function testARefusedRunAnswersItsReason(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->schemas->method('find')->willReturn($this->schema());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->flows->method('run')->willThrowException(new \RuntimeException('step 3 dead-ends'));

		$response = $this->controller->invoke('zaken', 'zaak', 'obj-1', 'close-and-notify');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('step 3 dead-ends', $response->getData()['error']);
	}//end testARefusedRunAnswersItsReason()

	/**
	 * A hint nobody can read is `stay`: the one answer that cannot move
	 * somebody somewhere they did not ask to go.
	 *
	 * @return void
	 */
	public function testAnUnreadableHintIsStay(): void {
		$this->objects->method('find')->willReturn($this->object());
		$this->schemas->method('find')->willReturn($this->schema());
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->flows->method('run')->willReturn($this->finishedRun());
		$this->flows->method('find')->willThrowException(new \RuntimeException('gone'));

		$response = $this->controller->invoke('zaken', 'zaak', 'obj-1', 'close-and-notify');

		$this->assertSame(FlowNextHint::STAY, $response->getData()['next']);
	}//end testAnUnreadableHintIsStay()
}//end class
