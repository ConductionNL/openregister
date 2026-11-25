<?php

/**
 * The `noteAdded` trigger in the annotation validator (notifications-new-notes-and-referrers task 1.2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerValidator;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationTriggerValidator
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NoteAddedTriggerValidator
 */
class NotificationNoteAddedTriggerValidatorTest extends TestCase {

	private function errors(mixed $trigger): array {
		return (new NotificationTriggerValidator(filterParser: new ScheduledFilterParser()))->validate(name: 'onNote', trigger: $trigger);
	}

	public function testAPlainNoteAddedTriggerIsValid(): void {
		$this->assertSame([], $this->errors(['type' => 'noteAdded']));
	}

	public function testEachVisibilityIsValid(): void {
		$this->assertSame([], $this->errors(['type' => 'noteAdded', 'visibility' => 'public']));
		$this->assertSame([], $this->errors(['type' => 'noteAdded', 'visibility' => 'internal']));
	}

	public function testAnUnknownVisibilityIsRefused(): void {
		foreach (['private', '', null, ['public']] as $visibility) {
			$errors = $this->errors(['type' => 'noteAdded', 'visibility' => $visibility]);

			$this->assertSame(['notification-note-added-bad-visibility'], array_column($errors, 'code'));
			$this->assertStringContainsString('trigger.visibility', $errors[0]['message']);
		}
	}

	public function testAnyOtherKeyIsRefusedByName(): void {
		$errors = $this->errors(['type' => 'noteAdded', 'author' => 'bob', 'intervalSec' => 60]);

		$this->assertSame(['notification-note-added-bad-key', 'notification-note-added-bad-key'], array_column($errors, 'code'));
		$this->assertStringContainsString('trigger.author', $errors[0]['message']);
		$this->assertStringContainsString('trigger.intervalSec', $errors[1]['message']);
	}

	public function testNoteAddedIsReservedAsAnAppEvent(): void {
		$errors = $this->errors('noteAdded');

		$this->assertSame(['notification-app-event-reserved'], array_column($errors, 'code'));
	}

	public function testOtherTriggersStillIgnoreAVisibilityKey(): void {
		$this->assertSame([], $this->errors(['type' => 'created', 'visibility' => 'nonsense']));
	}
}
