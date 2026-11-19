<?php

/**
 * The archival declaration checks are contributed in their slots and refuse what the mapper refused.
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

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Listener\ArchivalDeclarationChecksListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Listener\ArchivalDeclarationChecksListener
 */
final class ArchivalDeclarationChecksListenerTest extends TestCase {

	/**
	 * The listener under test.
	 *
	 * @param LoggerInterface|null $logger The logger it gets.
	 *
	 * @return ArchivalDeclarationChecksListener
	 */
	private function listener(?LoggerInterface $logger = null): ArchivalDeclarationChecksListener {
		return new ArchivalDeclarationChecksListener(logger: ($logger ?? new NullLogger()));
	}//end listener()

	/**
	 * The MDTO mapping, archival and retention checks take their own slots.
	 *
	 * @return void
	 */
	public function testItContributesTheThreeArchivalSlots(): void {
		$this->assertSame(
			[SchemaDeclarationOrder::MDTO_MAPPING, SchemaDeclarationOrder::ARCHIVAL, SchemaDeclarationOrder::RETENTION],
			self::contributedSlots(listener: $this->listener())
		);
	}//end testItContributesTheThreeArchivalSlots()

	/**
	 * A schema without archival declarations passes all three.
	 *
	 * @return void
	 */
	public function testASchemaWithoutDeclarationsPasses(): void {
		self::runContributed(listener: $this->listener(), schema: self::schemaWith([]));
		$this->assertTrue(true);
	}//end testASchemaWithoutDeclarationsPasses()

	/**
	 * A mapping that reads a property the schema lacks refuses the save.
	 *
	 * @return void
	 */
	public function testAMappingOfAnAbsentPropertyIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('[mdto-mapping-unknown-property]');
		$this->listener()->checkMdtoMapping(schema: self::schemaWith(['x-openregister-mdto-mapping' => ['naam' => ['property' => 'nope']]]));
	}//end testAMappingOfAnAbsentPropertyIsRefused()

	/**
	 * An unknown archival key is logged and ignored; a malformed retention refuses.
	 *
	 * @return void
	 */
	public function testAnUnknownArchivalKeyWarnsAndAMalformedRetentionRefuses(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('unknown key "bogus"'));
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('x-openregister-archival.retention contains unknown key "0"');
		$this->listener(logger: $logger)->checkArchival(
			schema: self::schemaWith(['x-openregister-archival' => ['retention' => [['condition' => 'x', 'retention' => 'P1Y', 'reason' => 'x']], 'bogus' => 1]])
		);
	}//end testAnUnknownArchivalKeyWarnsAndAMalformedRetentionRefuses()

	/**
	 * A sound archival block passes without a warning.
	 *
	 * @return void
	 */
	public function testASoundArchivalBlockPasses(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$this->listener(logger: $logger)->checkArchival(schema: self::schemaWith(['x-openregister-archival' => ['retention' => ['default' => 'P30D']]]));
		$this->assertTrue(true);
	}//end testASoundArchivalBlockPasses()

	/**
	 * The contributed retention check refuses an inheritance from a non-reference property.
	 *
	 * @return void
	 */
	public function testTheContributedRetentionCheckRefuses(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('x-openregister-retention');
		self::runContributed(listener: $this->listener(), schema: self::schemaWith(['x-openregister-retention' => ['inheritAppraisalFrom' => ['title']]]));
	}//end testTheContributedRetentionCheckRefuses()

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
