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
		$event  = new ObjectTransitionedEvent($object, 'publish', 'draft', 'published', 'alice', 'reg', 'sch');

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
			action: 'archive',
			from: 'published',
			to: 'archived',
			userId: null,
			register: 'r',
			schema: 's',
			automatic: true,
		);

		self::assertNull($event->getUserId());
		self::assertTrue($event->isAutomatic());
		self::assertSame('archived', $event->getTo());
	}//end testNamedConstructionCarriesNullUserAndAutomaticMove()
}//end class
