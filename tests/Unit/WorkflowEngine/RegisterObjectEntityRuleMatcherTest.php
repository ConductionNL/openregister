<?php

/**
 * RegisterObjectEntity::prepareRuleMatcher: an object event sets the subject, any other event does not.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\WorkflowEngine;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\WorkflowEngine\RegisterObjectEntity;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of prepareRuleMatcher.
 */
class RegisterObjectEntityRuleMatcherTest extends TestCase {

	/**
	 * The entity under test.
	 *
	 * @return RegisterObjectEntity
	 */
	private function entity(): RegisterObjectEntity {
		return new RegisterObjectEntity($this->createMock(IL10N::class), $this->createMock(IURLGenerator::class));
	}//end entity()

	/**
	 * An object event stashes the object and hands it to the matcher as the subject.
	 *
	 * @return void
	 */
	public function testAnObjectEventSetsTheSubject(): void {
		$object = new ObjectEntity();
		$entity = $this->entity();
		$matcher = $this->createMock(IRuleMatcher::class);
		$matcher->expects($this->once())->method('setEntitySubject')->with($entity, $object);

		$entity->prepareRuleMatcher($matcher, ObjectCreatedEvent::class, new ObjectCreatedEvent($object));

		$this->assertSame($object, $entity->getObject());
	}//end testAnObjectEventSetsTheSubject()

	/**
	 * Any other event leaves the matcher and the stashed object alone.
	 *
	 * @return void
	 */
	public function testAnyOtherEventSetsNothing(): void {
		$first = new ObjectEntity();
		$entity = $this->entity();
		$entity->prepareRuleMatcher($this->createMock(IRuleMatcher::class), ObjectDeletedEvent::class, new ObjectDeletedEvent($first));

		$matcher = $this->createMock(IRuleMatcher::class);
		$matcher->expects($this->never())->method('setEntitySubject');
		$entity->prepareRuleMatcher($matcher, Event::class, new Event());

		$this->assertSame($first, $entity->getObject());
	}//end testAnyOtherEventSetsNothing()
}//end class
