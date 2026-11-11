<?php

/**
 * Characterisation tests for ObjectTransitionedEvent's accessors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use PHPUnit\Framework\TestCase;

/**
 * Pins what each accessor returns for positional and named construction.
 */
class ObjectTransitionedEventTest extends TestCase {

	/**
	 * Every accessor returns the value it was built with; automatic defaults to false.
	 *
	 * @return void
	 */
	public function testAccessorsReturnConstructedValuesAndAutomaticDefaultsFalse(): void {
		$object = new ObjectEntity();
		$event  = new ObjectTransitionedEvent(object: $object, move: new TransitionMove(action: 'publish', from: 'draft', to: 'published'), scope: new RegisterSchemaRef(register: 'reg', schema: 'sch'), actor: new TransitionActor(userId: 'alice'));

		self::assertSame($object, $event->getObject());
		self::assertSame('publish', $event->getAction());
		self::assertSame('draft', $event->getFrom());
		self::assertSame('published', $event->getTo());
		self::assertSame('alice', $event->getUserId());
		self::assertSame('reg', $event->getRegister());
		self::assertSame('sch', $event->getSchema());
		self::assertFalse($event->isAutomatic());
		self::assertFalse($event->isPropagationStopped());
	}//end testAccessorsReturnConstructedValuesAndAutomaticDefaultsFalse()

	/**
	 * Named construction with a system actor and an automatic move.
	 *
	 * @return void
	 */
	public function testNamedConstructionCarriesNullUserAndAutomaticMove(): void {
		$event = new ObjectTransitionedEvent(
			object: new ObjectEntity(),
			move: new TransitionMove(action: 'archive', from: 'published', to: 'archived'),
			scope: new RegisterSchemaRef(register: 'r', schema: 's'),
			actor: new TransitionActor(userId: null, automatic: true),
		);

		self::assertNull($event->getUserId());
		self::assertTrue($event->isAutomatic());
		self::assertSame('archived', $event->getTo());
	}//end testNamedConstructionCarriesNullUserAndAutomaticMove()
	/**
	 * Without an actor the move is a system one, and an integer scope reads back as strings.
	 *
	 * @return void
	 */
	public function testMissingActorAndIntegerScopeDefaults(): void {
		$event = new ObjectTransitionedEvent(
			object: new ObjectEntity(),
			move: new TransitionMove(action: 'close', from: 'open', to: 'closed'),
			scope: new RegisterSchemaRef(register: 3, schema: 12),
		);

		self::assertNull($event->getUserId());
		self::assertFalse($event->isAutomatic());
		self::assertSame('3', $event->getRegister());
		self::assertSame('12', $event->getSchema());
		self::assertSame('closed', $event->getTo());
	}//end testMissingActorAndIntegerScopeDefaults()
}//end class
