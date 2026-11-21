<?php

/**
 * The unique-hint declaration check is contributed in its slot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Exception\UniqueHintException;
use OCA\OpenRegister\Listener\UniqueHintDeclarationCheckListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\UniqueHintDeclarationCheckListener
 */
final class UniqueHintDeclarationCheckListenerTest extends TestCase {

	/**
	 * The listener under test.
	 *
	 * @return UniqueHintDeclarationCheckListener
	 */
	private function listener(): UniqueHintDeclarationCheckListener {
		return new UniqueHintDeclarationCheckListener();
	}//end listener()

	/**
	 * The check takes the unique-hint slot.
	 *
	 * @return void
	 */
	public function testItContributesTheUniqueHintSlot(): void {
		$this->assertSame([SchemaDeclarationOrder::UNIQUE_HINT], self::contributedSlots(listener: $this->listener()));
	}//end testItContributesTheUniqueHintSlot()

	/**
	 * A nomination of an absent property refuses with the errors attached.
	 *
	 * @return void
	 */
	public function testANominationOfAnAbsentPropertyIsRefused(): void {
		try {
			self::runContributed(listener: $this->listener(), schema: self::schemaWith(['x-openregister-unique-hint' => ['absent']]));
			$this->fail('The save went through.');
		} catch (UniqueHintException $refusal) {
			$this->assertStringStartsWith('x-openregister-unique-hint: ', $refusal->getMessage());
			$this->assertNotSame([], $refusal->getErrors());
		}
	}//end testANominationOfAnAbsentPropertyIsRefused()

	/**
	 * No nomination, or one of a declared property, passes.
	 *
	 * @return void
	 */
	public function testNoNominationOrASoundOnePasses(): void {
		self::runContributed(listener: $this->listener(), schema: self::schemaWith([]));
		$this->listener()->checkUniqueHint(schema: self::schemaWith(['x-openregister-unique-hint' => ['name']]));
		$this->assertTrue(true);
	}//end testNoNominationOrASoundOnePasses()

	/**
	 * The slots the listener contributes to the collect event.
	 *
	 * @param object $listener The listener.
	 *
	 * @return array<int, int> The positions, in the order added.
	 */
	private static function contributedSlots(object $listener): array {
		$event = new SchemaDeclarationChecksEvent();
		$listener->handle(event: $event);
		return array_map(static fn ($check): int => $check->position(), $event->checks());
	}//end contributedSlots()

	/**
	 * Run the contributed check of one slot on a schema.
	 *
	 * @param object $listener The listener.
	 * @param Schema $schema   The schema being saved.
	 *
	 * @return void
	 */
	private static function runContributed(object $listener, Schema $schema): void {
		$event = new SchemaDeclarationChecksEvent();
		$listener->handle(event: $event);
		foreach ($event->checks() as $check) {
			$check->check(schema: $schema, discarded: new DiscardedAnnotations());
		}
	}//end runContributed()

	/**
	 * A schema with these properties and declarations.
	 *
	 * @param array<string, mixed> $configuration The declarations.
	 *
	 * @return Schema
	 */
	private static function schemaWith(array $configuration): Schema {
		$schema = new Schema();
		$schema->setSlug('checked');
		$schema->setProperties(['title' => ['type' => 'string'], 'name' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schemaWith()

	/**
	 * Any other event is left alone.
	 *
	 * @return void
	 */
	public function testAnotherEventGetsNoChecks(): void {
		$event = $this->createMock(Event::class);
		$event->expects($this->never())->method($this->anything());
		$this->listener()->handle(event: $event);
		$this->assertTrue(true);
	}//end testAnotherEventGetsNoChecks()
}//end class
