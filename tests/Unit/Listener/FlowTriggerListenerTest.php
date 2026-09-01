<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\CaseItemTransitionedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Listener\FlowTriggerListener;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugs;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Listener\FlowTriggerListener
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerSlugs
 *
 * Every collaborator the tests execute is named in the uses-list because
 * `beStrictAboutCoverageMetadata` is on: an unlisted executed class marks the
 * test risky and PHPUnit then discards its coverage wholesale.
 *
 * @uses \OCA\OpenRegister\Db\CaseItem
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Event\CaseItemTransitionedEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses \OCA\OpenRegister\Event\ObjectLockedEvent
 * @uses \OCA\OpenRegister\Event\ObjectUnlockedEvent
 * @uses \OCA\OpenRegister\Event\ObjectRevertedEvent
 * @uses \OCA\OpenRegister\Event\ObjectTransitionedEvent
 */
class FlowTriggerListenerTest extends TestCase {
	private FlowTriggerService $triggers;

	private FlowTriggerListener $listener;

	protected function setUp(): void {
		$this->triggers = $this->createMock(FlowTriggerService::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$this->listener = new FlowTriggerListener($this->triggers, $session, $this->slugs());
	}

	/**
	 * A real FlowTriggerSlugs over mapper doubles.
	 *
	 * The mappers know exactly one register (`16` => `dossiq`) and one schema
	 * (`26` => `case`); anything else does not resolve, which per the resolver's
	 * contract passes the identifier through unchanged — so the pre-existing
	 * tests that fire on `reg`/`sch` keep asserting exactly what they did.
	 */
	private function slugs(): FlowTriggerSlugs {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if ((string)$id !== '16') {
					throw new DoesNotExistException('no such register');
				}

				$register = new Register();
				$register->setSlug('dossiq');

				return $register;
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				if ((string)$id !== '26') {
					throw new DoesNotExistException('no such schema');
				}

				$schema = new Schema();
				$schema->setSlug('case');

				return $schema;
			}
		);

		return new FlowTriggerSlugs($registers, $schemas, new NullLogger());
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

	/**
	 * A CaseItem's terminal transition (flow-cmmn-case-semantics) is fired
	 * through the SAME seam as every object event: the item's numeric
	 * register/schema ids arrive at the trigger service as the slugs the
	 * index stores. This branch lived in the retired EventCatalogListener,
	 * which fired the raw ids — the id/slug defect on the case path.
	 */
	public function testATerminalCaseItemFiresItsCatalogTriggerWithSlugs(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with(
				'case.item.completed',
				$this->callback(
					static fn (array $s): bool => ($s['register'] ?? null) === 'dossiq'
						&& ($s['schema'] ?? null) === 'case'
						&& ($s['uuid'] ?? null) === 'case-obj-1'
				),
				null
			)
			->willReturn(1);

		$item = new CaseItem();
		$item->setObjectUuid('case-obj-1');
		$item->setRegisterId(16);
		$item->setSchemaId(26);
		$item->setState(CaseItem::STATE_COMPLETED);

		$this->listener->handle(new CaseItemTransitionedEvent($item, 'active'));
	}

	/**
	 * A non-terminal transition names no catalog trigger and fires nothing —
	 * an available or active item is progress, not an event a flow starts on.
	 */
	public function testANonTerminalCaseItemTransitionFiresNothing(): void {
		$this->triggers->expects($this->never())->method('fire');

		$item = new CaseItem();
		$item->setObjectUuid('case-obj-1');
		$item->setRegisterId(16);
		$item->setSchemaId(26);
		$item->setState(CaseItem::STATE_ACTIVE);

		$this->listener->handle(new CaseItemTransitionedEvent($item, 'available'));
	}

	/**
	 * 🔴 THE DEFECT: an object event carries NUMERIC register/schema ids, and
	 * the trigger index holds SLUGS — an imported declaration cannot know an
	 * instance's row ids. The subject must therefore fire with the slugs, so a
	 * trigger declared as `dossiq`/`case` matches an object saved as `16`/`26`.
	 * Before the fix this fired `16`/`26` literally, and three case creations
	 * on a clean instance queued nothing.
	 */
	public function testNumericIdsFireAsTheirSlugs(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with(
				'object.created',
				$this->callback(
					static fn (array $s): bool => ($s['register'] ?? null) === 'dossiq'
						&& ($s['schema'] ?? null) === 'case'
						&& ($s['uuid'] ?? null) === 'obj-1'
				),
				null,
				[]
			)
			->willReturn(1);

		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('16');
		$object->setSchema('26');

		$this->listener->handle(new ObjectCreatedEvent($object));
	}

	/**
	 * An object already carrying slugs fires them unchanged: resolution is
	 * idempotent, and an identifier that resolves to nothing passes through
	 * rather than being blanked — a blank would silently unsubscribe the flow.
	 */
	public function testSlugsAndUnresolvablesPassThroughUnchanged(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with(
				'object.created',
				$this->callback(
					static fn (array $s): bool => ($s['register'] ?? null) === 'reg'
						&& ($s['schema'] ?? null) === 'sch'
				),
				null,
				[]
			)
			->willReturn(1);

		$this->listener->handle(new ObjectCreatedEvent($this->object()));
	}
}
