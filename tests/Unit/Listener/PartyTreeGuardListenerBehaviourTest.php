<?php

/**
 * The party tree guard, branch by branch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\PartyTreeGuardListener;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyPropertyNames;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A save that would put a party under a parent the tree refuses is vetoed.
 *
 * @covers \OCA\OpenRegister\Listener\PartyTreeGuardListener
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses \OCA\OpenRegister\Event\WriteVetoState
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Party\PartyDefinition
 * @uses \OCA\OpenRegister\Service\Party\PartyPropertyNames
 * @uses \OCA\OpenRegister\Service\Party\PartyMergeRules
 */
final class PartyTreeGuardListenerBehaviourTest extends TestCase {

	private PartyServiceInterface&MockObject $parties;

	/**
	 * Fresh service per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parties = $this->createMock(PartyServiceInterface::class);
	}//end setUp()

	/**
	 * A party object with the given data.
	 *
	 * @param array $data The data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function party(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('child-1');
		$object->setObject($data);
		return $object;
	}//end party()

	/**
	 * A definition that nests through `parent`.
	 *
	 * @return PartyDefinition The definition.
	 */
	private function nesting(): PartyDefinition {
		return new PartyDefinition(properties: new PartyPropertyNames(parentProperty: 'parent'));
	}//end nesting()

	/**
	 * Run the listener on an event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 */
	private function handle(Event $event): void {
		(new PartyTreeGuardListener(parties: $this->parties))->handle($event);
	}//end handle()

	/**
	 * A refused parent on create vetoes with the service's message and code.
	 *
	 * @return void
	 */
	public function testARefusedParentVetoesTheCreate(): void {
		$this->parties->method('definitionFor')->willReturn($this->nesting());
		$this->parties->expects($this->once())->method('assertParentAllowed')->with('child-1', 'parent-9')
			->willThrowException(new InvalidArgumentException('cycle', 422));

		$event = new ObjectCreatingEvent(object: $this->party(data: ['parent' => ' parent-9 ']));
		$this->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['message' => 'cycle', 'code' => 422], $event->getErrors());
	}//end testARefusedParentVetoesTheCreate()

	/**
	 * An allowed parent on create lets the save through.
	 *
	 * @return void
	 */
	public function testAnAllowedParentLetsTheCreateThrough(): void {
		$this->parties->method('definitionFor')->willReturn($this->nesting());
		$this->parties->expects($this->once())->method('assertParentAllowed')->with('child-1', 'parent-9');

		$event = new ObjectCreatingEvent(object: $this->party(data: ['parent' => 'parent-9']));
		$this->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAnAllowedParentLetsTheCreateThrough()

	/**
	 * On update the new object's parent is the one checked.
	 *
	 * @return void
	 */
	public function testARefusedParentVetoesTheUpdateUsingTheNewObject(): void {
		$this->parties->method('definitionFor')->willReturn($this->nesting());
		$this->parties->expects($this->once())->method('assertParentAllowed')->with('child-1', 'new-parent')
			->willThrowException(new InvalidArgumentException('too deep', 3));

		$event = new ObjectUpdatingEvent(
			newObject: $this->party(data: ['parent' => 'new-parent']),
			oldObject: $this->party(data: ['parent' => 'old-parent'])
		);
		$this->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['message' => 'too deep', 'code' => 3], $event->getErrors());
	}//end testARefusedParentVetoesTheUpdateUsingTheNewObject()

	/**
	 * An allowed parent on update lets the save through.
	 *
	 * @return void
	 */
	public function testAnAllowedParentLetsTheUpdateThrough(): void {
		$this->parties->method('definitionFor')->willReturn($this->nesting());
		$this->parties->expects($this->once())->method('assertParentAllowed');

		$event = new ObjectUpdatingEvent(newObject: $this->party(data: ['parent' => 'p']));
		$this->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnAllowedParentLetsTheUpdateThrough()

	/**
	 * No parent, no definition, or a kind that does not nest: nothing is asked.
	 *
	 * @return void
	 */
	public function testNothingIsAskedWithoutAProposedParent(): void {
		$this->parties->method('definitionFor')->willReturnOnConsecutiveCalls(
			null,
			new PartyDefinition(),
			$this->nesting(),
			$this->nesting()
		);
		$this->parties->expects($this->never())->method('assertParentAllowed');

		foreach ([['parent' => 'p'], ['parent' => 'p'], ['parent' => '  '], []] as $data) {
			$event = new ObjectCreatingEvent(object: $this->party(data: $data));
			$this->handle($event);
			$this->assertFalse($event->isPropagationStopped());
		}
	}//end testNothingIsAskedWithoutAProposedParent()

	/**
	 * Other events are ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->parties->expects($this->never())->method('definitionFor');
		$event = new Event();

		$this->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testOtherEventsAreIgnored()
}//end class
