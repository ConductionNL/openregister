<?php

/**
 * The bulk save path must withhold lifecycle events during a system operation.
 *
 * This test exists because the first attempt at that gate did NOT cover this
 * path. Gating MagicMapper::insert()/update() looked complete — a live probe
 * showed one event outside SystemOperationContext and zero inside — while a
 * configuration import kept fanning out to eight apps through
 * emitChunkSideEffects(), which the probe never exercised. The gate read as
 * applied and was not.
 *
 * A live re-probe could not settle it either: the bulk path defaults to
 * `_events: false`, so a naive check sees zero events and calls it a pass
 * without ever proving an event could fire. Hence a unit test, with the
 * negative control alongside the assertion rather than in a separate run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Proves emitChunkSideEffects() is gated by SystemOperationContext.
 */
class SaveObjectsSystemOperationTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var SaveObjects
	 */
	private SaveObjects $service;

	/**
	 * Dispatcher whose calls are counted.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Build SaveObjects with only the collaborators this path touches.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->dispatcher = $this->createMock(IEventDispatcher::class);

		$this->service = new SaveObjects(
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SaveObject::class),
			$this->createMock(IUserSession::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			null,
			null,
			null,
			$this->dispatcher,
			// No AuditTrailMapper: this test is about EVENTS, and a null mapper
			// skips the audit branch entirely rather than needing it stubbed.
			null
		);

	}//end setUp()

	/**
	 * Invoke the private side-effect emitter with one created entity.
	 *
	 * @return void
	 */
	private function emitOneCreate(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('11111111-2222-3333-4444-555555555555');

		$method = new ReflectionMethod(SaveObjects::class, 'emitChunkSideEffects');
		$method->setAccessible(true);
		$method->invoke(
			$this->service,
			['created' => [$entity], 'updated' => []],
			true
		);

	}//end emitOneCreate()

	/**
	 * NEGATIVE CONTROL: outside a system operation the event is dispatched.
	 *
	 * Without this, the assertion below passes for free — an emitter that never
	 * dispatched anything, for any reason, would look exactly like a working
	 * gate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testBulkPathDispatchesOutsideASystemOperation(): void {
		$this->dispatcher->expects($this->once())->method('dispatchTyped');

		$this->emitOneCreate();

		$this->assertFalse(
			SystemOperationContext::isActive(),
			'The control must run with no system operation active'
		);

	}//end testBulkPathDispatchesOutsideASystemOperation()

	/**
	 * Inside a system operation the bulk path dispatches nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testBulkPathWithholdsEventsDuringASystemOperation(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		SystemOperationContext::run(
			function (): void {
				$this->emitOneCreate();
			}
		);

	}//end testBulkPathWithholdsEventsDuringASystemOperation()

	/**
	 * The gate is scoped to the operation, not sticky for the process.
	 *
	 * A suppression that outlived its context would silence every later save in
	 * the request — the same outage as the fan-out, arrived at from the other
	 * side and much harder to notice.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function testEventsResumeAfterTheSystemOperationEnds(): void {
		$this->dispatcher->expects($this->once())->method('dispatchTyped');

		SystemOperationContext::run(
			function (): void {
				$this->emitOneCreate();
			}
		);

		// Outside again: this one MUST reach the dispatcher.
		$this->emitOneCreate();

	}//end testEventsResumeAfterTheSystemOperationEnds()
}//end class
