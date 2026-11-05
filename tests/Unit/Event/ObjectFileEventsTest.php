<?php

/**
 * Tests for the events dispatched for an action on an object's file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Event\FileCopiedEvent;
use OCA\OpenRegister\Event\FileLockedEvent;
use OCA\OpenRegister\Event\FileMovedEvent;
use OCA\OpenRegister\Event\FileRenamedEvent;
use OCA\OpenRegister\Event\FileUnlockedEvent;
use OCA\OpenRegister\Event\FileVersionRestoredEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Each file-action event is an Event that hands back the object, file and data it was given.
 *
 * @covers \OCA\OpenRegister\Event\AbstractObjectFileEvent
 * @covers \OCA\OpenRegister\Event\FileCopiedEvent
 * @covers \OCA\OpenRegister\Event\FileLockedEvent
 * @covers \OCA\OpenRegister\Event\FileMovedEvent
 * @covers \OCA\OpenRegister\Event\FileRenamedEvent
 * @covers \OCA\OpenRegister\Event\FileUnlockedEvent
 * @covers \OCA\OpenRegister\Event\FileVersionRestoredEvent
 */
class ObjectFileEventsTest extends TestCase {

	/**
	 * The six file-action event classes.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function events(): array {
		return [
			'copied' => [FileCopiedEvent::class],
			'locked' => [FileLockedEvent::class],
			'moved' => [FileMovedEvent::class],
			'renamed' => [FileRenamedEvent::class],
			'unlocked' => [FileUnlockedEvent::class],
			'version restored' => [FileVersionRestoredEvent::class],
		];
	}

	/**
	 * The event keeps what it was constructed with, by name and by position, and data defaults to empty.
	 *
	 * @dataProvider events
	 *
	 * @param class-string $class The event class.
	 *
	 * @return void
	 */
	public function testTheEventHandsBackWhatItWasGiven(string $class): void {
		$named = new $class(objectUuid: 'obj-1', fileId: 7, data: ['from' => 'a']);
		$this->assertInstanceOf(Event::class, $named);
		$this->assertSame(['obj-1', 7, ['from' => 'a']], [$named->getObjectUuid(), $named->getFileId(), $named->getData()]);

		$bare = new $class('obj-2', 8);
		$this->assertSame(['obj-2', 8, []], [$bare->getObjectUuid(), $bare->getFileId(), $bare->getData()]);
	}
}
