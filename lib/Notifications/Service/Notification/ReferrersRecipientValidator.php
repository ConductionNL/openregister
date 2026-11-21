<?php

/**
 * Schema-save validation of a `referrers` recipient (notifications-new-notes-and-referrers D-3).
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
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-address-the-people-behind-referring-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use Closure;

/**
 * A `referrers` entry names where the referring objects live and whom to reach on each.
 *
 * `register`, `schema` and `property` are non-empty strings; `of`, when
 * present, is a property of the triggering schema; `recipients` is a
 * non-empty list of entries of any other kind. A nested `referrers` is
 * refused, so the kind is one level deep by construction. Each refusal
 * names the field it is about.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-address-the-people-behind-referring-objects
 */
final class ReferrersRecipientValidator {

	/**
	 * The recipient kind this validator checks.
	 *
	 * @var string
	 */
	public const KIND = 'referrers';

	/**
	 * The keys that name the referring objects, each a required non-empty string.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED = ['register', 'schema', 'property'];

	/**
	 * Errors for a `referrers` entry.
	 *
	 * @param RecipientEntry $entry  The entry.
	 * @param Closure        $nested Errors for one nested entry: fn (RecipientEntry): array.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-a-nested-referrers-block-is-refused
	 */
	public function errors(RecipientEntry $entry, Closure $nested): array {
		$errors = [];
		foreach (self::REQUIRED as $key) {
			$value = $entry->value(key: $key);
			if (is_string($value) === false || trim($value) === '') {
				$errors[] = $entry->error(
					code: 'notification-recipient-referrers-missing',
					detail: sprintf('kind=referrers requires a non-empty %s.', $key)
				);
			}
		}

		return array_merge($errors, $this->ofErrors(entry: $entry), $this->nestedErrors(entry: $entry, nested: $nested));
	}//end errors()

	/**
	 * Errors for `of`, which, when present, must be a property of the triggering schema.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ofErrors(RecipientEntry $entry): array {
		if (is_array($entry->recipient) === false || array_key_exists('of', $entry->recipient) === false) {
			return [];
		}

		$of = $entry->value(key: 'of');
		if (is_string($of) === true && in_array($of, $entry->rule->schema->propKeys, true) === true) {
			return [];
		}

		$detail = sprintf('kind=referrers of "%s" is not declared on the schema.', is_scalar($of) === true ? (string)$of : '');
		return [$entry->error(code: 'notification-recipient-referrers-bad-of', detail: $detail)];
	}//end ofErrors()

	/**
	 * Errors for the nested `recipients`: a non-empty list, no `referrers`, each entry well-formed.
	 *
	 * @param RecipientEntry $entry  The entry.
	 * @param Closure        $nested Errors for one nested entry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function nestedErrors(RecipientEntry $entry, Closure $nested): array {
		$recipients = $entry->value(key: 'recipients');
		if (is_array($recipients) === false || $recipients === []) {
			return [$entry->error(code: 'notification-recipient-referrers-no-recipients', detail: 'kind=referrers requires a non-empty recipients list.')];
		}

		$errors = [];
		foreach ($recipients as $index => $recipient) {
			if (is_array($recipient) === true && ($recipient['kind'] ?? null) === self::KIND) {
				$detail   = sprintf('kind=referrers recipients[%s] may not be another referrers block.', (string)$index);
				$errors[] = $entry->error(code: 'notification-recipient-referrers-nested', detail: $detail);
				continue;
			}

			$errors = array_merge($errors, $nested(new RecipientEntry(rule: $entry->rule, index: $index, recipient: $recipient)));
		}

		return $errors;
	}//end nestedErrors()
}//end class
