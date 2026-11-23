<?php

/**
 * Unit tests for NotificationDeclarationCheckListener.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Notifications\Listener\NotificationDeclarationCheckListener;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeclarationException;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The listener contributes one check, in the notification slot, that follows the save's warn mode.
 *
 * @covers \OCA\OpenRegister\Notifications\Listener\NotificationDeclarationCheckListener
 * @covers \OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\NotificationAnnotationCheck
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck
 */
final class NotificationDeclarationCheckListenerTest extends TestCase {

	/**
	 * A schema with a url action that cannot be linked to.
	 *
	 * @return Schema
	 */
	private static function unlinkable(): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration([
			'x-openregister-notifications' => [
				'opened' => [
					'trigger' => ['type' => 'created'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'users', 'users' => ['admin']]],
					'subject' => 'Opened',
					'actions' => [['label' => ['nl' => 'Open'], 'target' => ['kind' => 'url', 'href' => 'call']]],
				],
			],
		]);

		return $schema;
	}//end unlinkable()

	/**
	 * Another event gets no check.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		(new NotificationDeclarationCheckListener(logger: new NullLogger()))->handle(event: new Event());
		$this->addToAssertionCount(1);
	}//end testAnotherEventIsIgnored()

	/**
	 * One check is contributed, in the notification slot.
	 *
	 * @return void
	 */
	public function testItContributesOneCheckInTheNotificationSlot(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new NotificationDeclarationCheckListener(logger: new NullLogger()))->handle(event: $event);

		$this->assertCount(1, $event->checks());
		$this->assertSame(SchemaDeclarationOrder::NOTIFICATIONS, $event->checks()[0]->position());
	}//end testItContributesOneCheckInTheNotificationSlot()

	/**
	 * By default an unlinkable url action refuses the save.
	 *
	 * @return void
	 */
	public function testAnUnlinkableActionIsRefusedByDefault(): void {
		$event = new SchemaDeclarationChecksEvent();
		$this->assertFalse($event->warnsOnUnlinkableNotificationActions());
		(new NotificationDeclarationCheckListener(logger: new NullLogger()))->handle(event: $event);

		$this->expectException(NotificationDeclarationException::class);
		$event->checks()[0]->check(schema: self::unlinkable(), discarded: new DiscardedAnnotations());
	}//end testAnUnlinkableActionIsRefusedByDefault()

	/**
	 * The warn mode is read when the check runs, so a mode set after collecting applies.
	 *
	 * @return void
	 */
	public function testTheWarnModeIsReadWhenTheCheckRuns(): void {
		$warns  = false;
		$event  = new SchemaDeclarationChecksEvent(warnsOnUnlinkable: static function () use (&$warns): bool {
			return $warns;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('target.href "call" is not an absolute URL'));
		(new NotificationDeclarationCheckListener(logger: $logger))->handle(event: $event);

		$warns = true;
		$this->assertTrue($event->warnsOnUnlinkableNotificationActions());
		$event->checks()[0]->check(schema: self::unlinkable(), discarded: new DiscardedAnnotations());
	}//end testTheWarnModeIsReadWhenTheCheckRuns()

	/**
	 * A schema without the annotation passes silently.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheAnnotationPasses(): void {
		$event  = new SchemaDeclarationChecksEvent();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		(new NotificationDeclarationCheckListener(logger: $logger))->handle(event: $event);

		$event->checks()[0]->check(schema: new Schema(), discarded: new DiscardedAnnotations());
	}//end testASchemaWithoutTheAnnotationPasses()
}//end class
