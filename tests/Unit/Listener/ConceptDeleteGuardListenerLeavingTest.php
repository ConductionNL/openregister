<?php

/**
 * The concept delete guard, asked about a move.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Listener\ConceptDeleteGuardListener;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A value that may not be deleted may not move out of the vocabulary either.
 *
 * @covers \OCA\OpenRegister\Listener\ConceptDeleteGuardListener
 * @uses \OCA\OpenRegister\Event\ObjectLeavingEvent
 */
final class ConceptDeleteGuardListenerLeavingTest extends TestCase {

	private const SCHEME = 'https://example.org/scheme';

	private ConceptDeleteGuard&MockObject $guard;

	/**
	 * A guard that takes every object for a concept.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = $this->createMock(ConceptDeleteGuard::class);
		$this->guard->method('isConcept')->willReturn(true);
	}//end setUp()

	/**
	 * The event about a concept in the vocabulary.
	 *
	 * @return ObjectLeavingEvent The event, after the listener handled it.
	 */
	private function handled(): ObjectLeavingEvent {
		$object = new ObjectEntity();
		$object->setSchema('10');
		$object->setObject(['uri' => 'https://example.org/value', 'inScheme' => self::SCHEME]);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		$event = new ObjectLeavingEvent(object: $object);
		(new ConceptDeleteGuardListener(
			guard: $this->guard,
			concepts: $this->createStub(ConceptRepository::class),
			schemas: $schemas,
			logger: new NullLogger()
		))->handle($event);

		return $event;
	}//end handled()

	/**
	 * A value the product defines stays.
	 *
	 * @return void
	 */
	public function testASystemDefinedValueStays(): void {
		$this->guard->method('isSystemDefined')->willReturn(true);
		$this->guard->method('systemDefinedMessage')->willReturn('The product defines this value.');

		$event = $this->handled();

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('The product defines this value.', $event->getErrors()['message']);
	}//end testASystemDefinedValueStays()

	/**
	 * A value objects hold stays.
	 *
	 * @return void
	 */
	public function testAHeldValueStays(): void {
		$this->guard->method('usage')->willReturn(['count' => 2, 'holders' => []]);
		$this->guard->method('inUseMessage')->willReturn('Two objects hold this value.');

		$event = $this->handled();

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('Two objects hold this value.', $event->getErrors()['message']);
	}//end testAHeldValueStays()

	/**
	 * A value nobody holds may go.
	 *
	 * @return void
	 */
	public function testAValueNobodyHoldsMoves(): void {
		$this->guard->method('usage')->willReturn(['count' => 0, 'holders' => []]);

		self::assertFalse($this->handled()->isPropagationStopped());
	}//end testAValueNobodyHoldsMoves()
}//end class
