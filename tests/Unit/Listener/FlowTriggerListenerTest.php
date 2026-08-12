<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Listener\FlowTriggerListener;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCP\EventDispatcher\Event;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class FlowTriggerListenerTest extends TestCase {
	private FlowTriggerService $triggers;

	private FlowTriggerListener $listener;

	protected function setUp(): void {
		$this->triggers = $this->createMock(FlowTriggerService::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$this->listener = new FlowTriggerListener($this->triggers, $session);
	}

	private function object(): ObjectEntity {
		$o = new ObjectEntity();
		$o->setUuid('obj-1');
		$o->setRegister('reg');
		$o->setSchema('sch');
		return $o;
	}

	public function testLockFiresTheLockedTrigger(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with('object.locked', $this->anything(), null, [])
			->willReturn(1);

		$this->listener->handle(new ObjectLockedEvent($this->object()));
	}

	public function testUnlockFiresTheUnlockedTrigger(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with('object.unlocked', $this->anything(), null, [])
			->willReturn(1);

		$this->listener->handle(new ObjectUnlockedEvent($this->object()));
	}

	public function testRevertFiresTheRevertedTrigger(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with('object.reverted', $this->anything(), null, [])
			->willReturn(1);

		$this->listener->handle(new ObjectRevertedEvent($this->object(), 'v1'));
	}

	public function testTransitionFiresAndCarriesTheStateChangeAsContext(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with(
				'object.transitioned',
				$this->callback(static fn (array $s): bool => ($s['uuid'] ?? null) === 'obj-1'),
				null,
				['action' => 'approve', 'from' => 'draft', 'to' => 'published']
			)
			->willReturn(1);

		$this->listener->handle(
			new ObjectTransitionedEvent($this->object(), 'approve', 'draft', 'published', null, 'reg', 'sch')
		);
	}

	public function testAnUnrelatedEventFiresNothing(): void {
		$this->triggers->expects($this->never())->method('fire');
		$this->listener->handle(new class extends Event {});
	}

	public function testCreateStillCarriesNoExtraContext(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with('object.created', $this->anything(), null, [])
			->willReturn(1);

		$this->listener->handle(new ObjectCreatedEvent($this->object()));
	}
}
