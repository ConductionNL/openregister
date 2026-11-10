<?php

/**
 * Schema-save validation of a notification rule's `recipients`.
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
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifierInterface;

/**
 * Validates the `recipients` of one `x-openregister-notifications` rule.
 */
final class NotificationRecipientValidator {

	/**
	 * The recipient kinds a rule may address.
	 *
	 * @var array<int, string>
	 */
	private const VALID_RECIPIENT_KINDS = [
		'users',
		'field',
		'groups',
		'role',
		'relation',
		'object-acl',
		'expression',
		'watchers',
		'parties',
		ReferencedAddressNotifierInterface::KIND,
	];

	/**
	 * Errors for `recipients`: at least one, each well-formed for its kind.
	 *
	 * @param NotificationRuleContext $rule The rule.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function validate(NotificationRuleContext $rule): array {
		$recipients = ($rule->spec['recipients'] ?? []);
		if (is_array($recipients) === false || count($recipients) === 0) {
			return [$this->noRecipientsError(rule: $rule)];
		}

		$errors = [];
		foreach ($recipients as $index => $recipient) {
			$entry  = new RecipientEntry(rule: $rule, index: $index, recipient: $recipient);
			$errors = array_merge($errors, $this->validateRecipient(entry: $entry));
		}

		return $errors;
	}//end validate()

	/**
	 * The error for a rule that declares no recipients at all.
	 *
	 * @param NotificationRuleContext $rule The rule.
	 *
	 * @return array{code: string, message: string}
	 */
	private function noRecipientsError(NotificationRuleContext $rule): array {
		return [
			'code' => 'notification-no-recipients',
			'message' => sprintf('Notification "%s" must declare at least one recipient.', $rule->name),
		];
	}//end noRecipientsError()

	/**
	 * Errors for one recipient entry.
	 *
	 * The watchers block is spelled `{"watchers": true}` and carries no
	 * `kind`. Anything but boolean true is refused rather than coerced: a rule
	 * that addresses nobody should say so at save time.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateRecipient(RecipientEntry $entry): array {
		if (is_array($entry->recipient) === false) {
			return [$entry->error(code: 'notification-recipient-malformed', detail: 'must be an object.')];
		}

		if (array_key_exists('watchers', $entry->recipient) === true) {
			return $this->watchersErrors(entry: $entry);
		}

		return $this->kindErrors(entry: $entry);
	}//end validateRecipient()

	/**
	 * Errors for a watchers block, which must be exactly `{"watchers": true}`.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function watchersErrors(RecipientEntry $entry): array {
		if ($entry->value(key: 'watchers') === true) {
			return [];
		}

		return [$entry->error(code: 'notification-recipient-watchers-not-true', detail: 'watchers must be boolean true.')];
	}//end watchersErrors()

	/**
	 * Errors for an entry with a `kind`: the kind must be known, then the entry well-formed for it.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function kindErrors(RecipientEntry $entry): array {
		$kind = (string)($entry->value(key: 'kind') ?? '');
		if (in_array($kind, self::VALID_RECIPIENT_KINDS, true) === false) {
			$detail = sprintf('kind "%s" not in [%s].', $kind, implode(', ', self::VALID_RECIPIENT_KINDS));
			return [$entry->error(code: 'notification-bad-recipient-kind', detail: $detail)];
		}

		return $this->kindCheckErrors(entry: $entry, kind: $kind);
	}//end kindErrors()

	/**
	 * Errors from the check for one known kind; kinds without a check pass.
	 *
	 * @param RecipientEntry $entry The entry.
	 * @param string         $kind  The entry's kind, already known to be valid.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function kindCheckErrors(RecipientEntry $entry, string $kind): array {
		return match ($kind) {
			'groups', 'users' => $this->namedListErrors(entry: $entry, kind: $kind),
			'field' => $this->fieldRecipientErrors(entry: $entry),
			ReferencedAddressNotifierInterface::KIND => $this->emailRecipientErrors(entry: $entry),
			'object-acl' => $this->aclRecipientErrors(entry: $entry),
			'role' => $this->roleRecipientErrors(entry: $entry),
			'expression' => $this->expressionRecipientErrors(entry: $entry),
			default => [],
		};
	}//end kindCheckErrors()

	/**
	 * Errors for a `groups` or `users` entry that names nobody.
	 *
	 * An empty list can never resolve to anybody, so it is a stub or a typo
	 * and is refused here. A non-empty group that is empty today is not
	 * refused: declared groups ship empty on purpose, and that case is
	 * recorded at dispatch instead (see RuleReachRecorder).
	 *
	 * @param RecipientEntry $entry The entry.
	 * @param string         $kind  `groups` or `users`, also the list key.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function namedListErrors(RecipientEntry $entry, string $kind): array {
		$named = $entry->value(key: $kind);
		if (is_array($named) === true && $named !== []) {
			return [];
		}

		$detail = sprintf(
			'is kind "%s" but names no %s, so it can never resolve to anybody. An unstaffed group is fine and is '
			.'reported at dispatch; an empty list is a stub.',
			$kind,
			$kind
		);

		return [$entry->error(code: 'notification-recipient-names-nobody', detail: $detail)];
	}//end namedListErrors()

	/**
	 * Errors for a `field` entry, whose field must be declared on the schema.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function fieldRecipientErrors(RecipientEntry $entry): array {
		$field = (string)($entry->value(key: 'field') ?? '');
		if ($field !== '' && in_array($field, $entry->rule->schema->propKeys, true) === true) {
			return [];
		}

		return [$this->unknownFieldError(entry: $entry, field: $field)];
	}//end fieldRecipientErrors()

	/**
	 * The error for a field that is not declared on the schema.
	 *
	 * @param RecipientEntry $entry The entry.
	 * @param string         $field The field name, for the message.
	 *
	 * @return array{code: string, message: string}
	 */
	private function unknownFieldError(RecipientEntry $entry, string $field): array {
		$detail = sprintf('field "%s" is not declared on the schema.', $field);
		return $entry->error(code: 'notification-recipient-field-unknown', detail: $detail);
	}//end unknownFieldError()

	/**
	 * Errors for an `object-acl` entry, whose permission must be read or manage.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function aclRecipientErrors(RecipientEntry $entry): array {
		$perm = (string)($entry->value(key: 'permission') ?? '');
		if (in_array($perm, ['read', 'manage'], true) === true) {
			return [];
		}

		$detail = sprintf('kind=object-acl requires permission in [read, manage]; got "%s".', $perm);
		return [$entry->error(code: 'notification-recipient-acl-bad-permission', detail: $detail)];
	}//end aclRecipientErrors()

	/**
	 * Errors for a `role` entry: a role name, assigned by the schema when it assigns any.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function roleRecipientErrors(RecipientEntry $entry): array {
		$role     = (string)($entry->value(key: 'role') ?? '');
		$declared = $entry->rule->schema->declaredRoles;
		if ($role === '') {
			return [$entry->error(code: 'notification-recipient-role-missing', detail: 'kind=role requires a non-empty role name.')];
		}

		if ($declared === [] || in_array($role, $declared, true) === true) {
			return [];
		}

		$detail = sprintf('role "%s" is not assigned by the schema authorization; declared: [%s].', $role, implode(', ', $declared));
		return [$entry->error(code: 'notification-recipient-role-unknown', detail: $detail)];
	}//end roleRecipientErrors()

	/**
	 * Errors for an `expression` entry, which must name a resolver.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function expressionRecipientErrors(RecipientEntry $entry): array {
		if ((string)($entry->value(key: 'resolver') ?? '') !== '') {
			return [];
		}

		$detail = 'kind=expression requires a resolver string (DI tag or FQCN).';
		return [$entry->error(code: 'notification-recipient-expression-no-resolver', detail: $detail)];
	}//end expressionRecipientErrors()

	/**
	 * Errors for one `email` recipient entry (REQ-ERO-007).
	 *
	 * The first path segment must be a declared property, and when the path
	 * goes through a reference that property must declare the `$ref` the
	 * notifier compares the referenced object's schema with (without it the
	 * rule would be saved and then never send); the path may follow at most
	 * ReferencedAddressNotifier::MAX_HOPS references, and the rule must
	 * send e-mail: an `email` entry on a rule without the email channel would
	 * reach nobody, every time, in silence.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	private function emailRecipientErrors(RecipientEntry $entry): array {
		$segments = $this->emailSegments(entry: $entry);
		if ($segments === []) {
			$detail = 'kind=email requires a `field` naming the address, e.g. "supplierRef.contactEmail".';
			return [$entry->error(code: 'notification-recipient-email-field-missing', detail: $detail)];
		}

		return array_merge(
			$this->emailFirstSegmentErrors(entry: $entry, segments: $segments),
			$this->emailDepthErrors(entry: $entry, segments: $segments),
			$this->emailChannelErrors(entry: $entry)
		);
	}//end emailRecipientErrors()

	/**
	 * The trimmed address path of an `email` entry.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return string
	 */
	private function emailPath(RecipientEntry $entry): string {
		return trim((string)($entry->value(key: 'field') ?? ''));
	}//end emailPath()

	/**
	 * The non-empty segments of an `email` entry's address path.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, string>
	 */
	private function emailSegments(RecipientEntry $entry): array {
		$segments = explode('.', $this->emailPath(entry: $entry));
		return array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));
	}//end emailSegments()

	/**
	 * Errors for the first path segment: declared, and with a `$ref` when the path goes through it.
	 *
	 * @param RecipientEntry     $entry    The entry.
	 * @param array<int, string> $segments The non-empty path segments, at least one.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function emailFirstSegmentErrors(RecipientEntry $entry, array $segments): array {
		if (in_array($segments[0], $entry->rule->schema->propKeys, true) === false) {
			return [$this->unknownFieldError(entry: $entry, field: $segments[0])];
		}

		if (count($segments) === 1) {
			return [];
		}

		return $this->referenceWithoutRefErrors(entry: $entry, first: $segments[0]);
	}//end emailFirstSegmentErrors()

	/**
	 * The error for an e-mail path whose first segment goes through a reference that declares no `$ref`.
	 *
	 * @param RecipientEntry $entry The entry.
	 * @param string         $first The first segment, a declared property.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function referenceWithoutRefErrors(RecipientEntry $entry, string $first): array {
		if ($this->declaresRef(entry: $entry, first: $first) === true) {
			return [];
		}

		$detail = sprintf(
			'field "%s" goes through "%s", which declares no `$ref`: '
			. 'the referenced object cannot be checked against a schema, so the rule would never send.',
			$this->emailPath(entry: $entry),
			$first
		);
		return [$entry->error(code: 'notification-recipient-email-reference-has-no-ref', detail: $detail)];
	}//end referenceWithoutRefErrors()

	/**
	 * Whether the property a path goes through declares a non-empty `$ref`.
	 *
	 * @param RecipientEntry $entry The entry.
	 * @param string         $first The first segment, a declared property.
	 *
	 * @return bool
	 */
	private function declaresRef(RecipientEntry $entry, string $first): bool {
		$declared = ($entry->rule->schema->properties[$first] ?? null);
		$ref      = null;
		if (is_array($declared) === true) {
			$ref = ($declared['$ref'] ?? null);
		}

		return is_string($ref) === true && trim($ref) !== '';
	}//end declaresRef()

	/**
	 * The error for an e-mail path that follows more than MAX_HOPS references.
	 *
	 * @param RecipientEntry     $entry    The entry.
	 * @param array<int, string> $segments The non-empty path segments.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function emailDepthErrors(RecipientEntry $entry, array $segments): array {
		if (count($segments) <= (ReferencedAddressNotifierInterface::MAX_HOPS + 1)) {
			return [];
		}

		$detail = sprintf('field "%s" follows more than %d references.', $this->emailPath(entry: $entry), ReferencedAddressNotifierInterface::MAX_HOPS);
		return [$entry->error(code: 'notification-recipient-email-path-too-deep', detail: $detail)];
	}//end emailDepthErrors()

	/**
	 * The error for an `email` entry on a rule that sends no e-mail.
	 *
	 * @param RecipientEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function emailChannelErrors(RecipientEntry $entry): array {
		if (in_array('email', (array)($entry->rule->spec['channels'] ?? []), true) === true) {
			return [];
		}

		$detail = 'is kind=email but the rule has no "email" channel, so it would reach nobody.';
		return [$entry->error(code: 'notification-recipient-email-needs-email-channel', detail: $detail)];
	}//end emailChannelErrors()
}//end class
