<?php

/**
 * Schema-save validation of a `noteAdded` trigger (notifications-new-notes-and-referrers D-2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * A `noteAdded` trigger object carries only `type` and an optional `visibility`.
 *
 * Any other key is refused by name, so a rule that meant to narrow on
 * something the trigger cannot narrow on is not silently broader than its
 * author thinks.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */
final class NoteAddedTriggerValidator {

	/**
	 * The keys a `noteAdded` trigger object may carry.
	 *
	 * @var array<int, string>
	 */
	private const KEYS = ['type', 'visibility'];

	/**
	 * The note visibilities a `noteAdded` trigger may narrow to.
	 *
	 * @var array<int, string>
	 */
	private const VISIBILITIES = ['public', 'internal'];

	/**
	 * Errors for a `noteAdded` trigger: no key but `visibility`, which is `public` or `internal`.
	 *
	 * @param string               $name    The rule key.
	 * @param array<string, mixed> $trigger The declared trigger.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
	 */
	public function validate(string $name, array $trigger): array {
		$errors = [];
		foreach (array_keys($trigger) as $key) {
			if (in_array($key, self::KEYS, true) === false) {
				$errors[] = [
					'code' => 'notification-note-added-bad-key',
					'message' => sprintf('Notification "%s" trigger.type=noteAdded accepts only trigger.visibility; got trigger.%s.', $name, $key),
				];
			}
		}

		if (array_key_exists('visibility', $trigger) === true && in_array($trigger['visibility'], self::VISIBILITIES, true) === false) {
			$errors[] = [
				'code' => 'notification-note-added-bad-visibility',
				'message' => sprintf('Notification "%s" trigger.visibility must be one of [public, internal].', $name),
			];
		}

		return $errors;
	}//end validate()
}//end class
