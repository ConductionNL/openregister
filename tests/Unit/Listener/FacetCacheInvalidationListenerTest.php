<?php

/**
 * OpenRegister FacetCacheInvalidationListenerTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\FacetCacheInvalidationListener;
use OCA\OpenRegister\Service\Object\FacetCacheVersion;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every object-write event must reach the freshness counter.
 *
 * The wiring between an event and the counter is the part that can quietly
 * not exist: a listener that receives the event and bumps nothing produces
 * exactly the behaviour openregister#3560 describes, and no error.
 */
final class FacetCacheInvalidationListenerTest extends TestCase {
	private FacetCacheVersion&MockObject $versions;

	private FacetCacheInvalidationListener $listener;

	/**
	 * Build the listener over a mocked counter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->versions = $this->createMock(FacetCacheVersion::class);
		$this->listener = new FacetCacheInvalidationListener($this->versions);
	}//end setUp()

	/**
	 * A created object bumps its own scope.
	 *
	 * @return void
	 */
	public function testACreateBumpsTheWrittenScope(): void {
		$this->versions->expects($this->once())->method('bump')->with('7', '42');

		$this->listener->handle(new ObjectCreatedEvent($this->object('7', '42')));
	}//end testACreateBumpsTheWrittenScope()

	/**
	 * An updated object bumps its own scope.
	 *
	 * @return void
	 */
	public function testAnUpdateBumpsTheWrittenScope(): void {
		$this->versions->expects($this->once())->method('bump')->with('7', '42');

		$this->listener->handle(new ObjectUpdatedEvent($this->object('7', '42')));
	}//end testAnUpdateBumpsTheWrittenScope()

	/**
	 * A deleted object bumps its own scope.
	 *
	 * The emptied bucket is half the reported defect: a category whose last row
	 * is gone must stop being offered as a place to go.
	 *
	 * @return void
	 */
	public function testADeleteBumpsTheWrittenScope(): void {
		$this->versions->expects($this->once())->method('bump')->with('7', '42');

		$this->listener->handle(new ObjectDeletedEvent($this->object('7', '42')));
	}//end testADeleteBumpsTheWrittenScope()

	/**
	 * An event carrying no object bumps nothing.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventBumpsNothing(): void {
		$this->versions->expects($this->never())->method('bump');

		$this->listener->handle(new class extends Event {
		});
	}//end testAnUnrelatedEventBumpsNothing()

	/**
	 * Build an object entity in a given scope.
	 *
	 * @param string $register Register id.
	 * @param string $schema   Schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function object(string $register, string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister($register);
		$object->setSchema($schema);

		return $object;
	}//end object()
}//end class
