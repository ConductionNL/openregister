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

namespace OCA\OpenRegister\Service\Notification;

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
		ReferencedAddressNotifier::KIND,
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
			return [
				[
					'code' => 'notification-no-recipients',
					'message' => sprintf('Notification "%s" must declare at least one recipient.', $rule->name),
				],
			];
		}

		$errors = [];
		foreach ($recipients as $index => $recipient) {
			$errors = array_merge($errors, $this->validateRecipient(rule: $rule, index: $index, recipient: $recipient));
		}

		return $errors;
	}//end validate()

	/**
	 * Errors for one recipient entry.
	 *
	 * The watchers block is spelled `{"watchers": true}` and carries no
	 * `kind`. Anything but boolean true is refused rather than coerced: a rule
	 * that addresses nobody should say so at save time.
	 *
	 * @param NotificationRuleContext $rule      The rule.
	 * @param int|string              $index     The entry's index.
	 * @param mixed                   $recipient The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function validateRecipient(NotificationRuleContext $rule, int|string $index, mixed $recipient): array {
		if (is_array($recipient) === false) {
			return [$this->recipientError(rule: $rule, index: $index, code: 'notification-recipient-malformed', detail: 'must be an object.')];
		}

		if (array_key_exists('watchers', $recipient) === true) {
			if ($recipient['watchers'] !== true) {
				$detail = 'watchers must be boolean true.';
				return [$this->recipientError(rule: $rule, index: $index, code: 'notification-recipient-watchers-not-true', detail: $detail)];
			}

			return [];
		}

		$kind = (string)($recipient['kind'] ?? '');
		if (in_array($kind, self::VALID_RECIPIENT_KINDS, true) === false) {
			$detail = sprintf('kind "%s" not in [%s].', $kind, implode(', ', self::VALID_RECIPIENT_KINDS));
			return [$this->recipientError(rule: $rule, index: $index, code: 'notification-bad-recipient-kind', detail: $detail)];
		}

		return match ($kind) {
			'groups', 'users' => $this->namedListErrors(rule: $rule, index: $index, recipient: $recipient, kind: $kind),
			'field' => $this->fieldRecipientErrors(rule: $rule, index: $index, recipient: $recipient),
			ReferencedAddressNotifier::KIND => $this->emailRecipientErrors(rule: $rule, index: (int)$index, recipient: $recipient),
			'object-acl' => $this->aclRecipientErrors(rule: $rule, index: $index, recipient: $recipient),
			'role' => $this->roleRecipientErrors(rule: $rule, index: $index, recipient: $recipient),
			'expression' => $this->expressionRecipientErrors(rule: $rule, index: $index, recipient: $recipient),
			default => [],
		};
	}//end validateRecipient()

	/**
	 * One recipient error, prefixed with the rule name and entry index.
	 *
	 * @param NotificationRuleContext $rule   The rule.
	 * @param int|string              $index  The entry's index.
	 * @param string                  $code   The error code.
	 * @param string                  $detail The message after `recipient[n] `.
	 *
	 * @return array{code: string, message: string}
	 */
	private function recipientError(NotificationRuleContext $rule, int|string $index, string $code, string $detail): array {
		return [
			'code' => $code,
			'message' => sprintf('Notification "%s" recipient[%d] ', $rule->name, $index).$detail,
		];
	}//end recipientError()

	/**
	 * Errors for a `groups` or `users` entry that names nobody.
	 *
	 * An empty list can never resolve to anybody, so it is a stub or a typo
	 * and is refused here. A non-empty group that is empty today is not
	 * refused: declared groups ship empty on purpose, and that case is
	 * recorded at dispatch instead (see RuleReachRecorder).
	 *
	 * @param NotificationRuleContext $rule      The rule.
	 * @param int|string              $index     The entry's index.
	 * @param array<string, mixed>    $recipient The entry.
	 * @param string                  $kind      `groups` or `users`, also the list key.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function namedListErrors(NotificationRuleContext $rule, int|string $index, array $recipient, string $kind): array {
		$named = ($recipient[$kind] ?? null);
		if (is_array($named) === true && $named !== []) {
			return [];
		}

		$detail = sprintf(
			'is kind "%s" but names no %s, so it can never resolve to anybody. An unstaffed group is fine and is '
			.'reported at dispatch; an empty list is a stub.',
			$kind,
			$kind
		);

		return [$this->recipientError(rule: $rule, index: $index, code: 'notification-recipient-names-nobody', detail: $detail)];
	}//end namedListErrors()

	/**
	 * Errors for a `field` entry, whose field must be declared on the schema.
	 *
	 * @param NotificationRuleContext $rule      The rule.
	 * @param int|string              $index     The entry's index.
	 * @param array<string, mixed>    $recipient The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function fieldRecipientErrors(NotificationRuleContext $rule, int|string $index, array $recipient): array {
		$field = (string)($recipient['field'] ?? '');
		if ($field !== '' && in_array($field, $rule->schema->propKeys, true) === true) {
			return [];
		}

		$detail = sprintf('field "%s" is not declared on the schema.', $field);
		return [$this->recipientError(rule: $rule, index: $index, code: 'notification-recipient-field-unknown', detail: $detail)];
	}//end fieldRecipientErrors()

	/**
	 * Errors for an `object-acl` entry, whose permission must be read or manage.
	 *
	 * @param NotificationRuleContext $rule      The rule.
	 * @param int|string              $index     The entry's index.
	 * @param array<string, mixed>    $recipient The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function aclRecipientErrors(NotificationRuleContext $rule, int|string $index, array $recipient): array {
		$perm = (string)($recipient['permission'] ?? '');
		if (in_array($perm, ['read', 'manage'], true) === true) {
			return [];
		}

		$detail = sprintf('kind=object-acl requires permission in [read, manage]; got "%s".', $perm);
		return [$this->recipientError(rule: $rule, index: $index, code: 'notification-recipient-acl-bad-permission', detail: $detail)];
	}//end aclRecipientErrors()

	/**
	 * Errors for a `role` entry: a role name, assigned by the schema when it assigns any.
	 *
	 * @param NotificationRuleContext $rule      The rule.
	 * @param int|string              $index     The entry's index.
	 * @param array<string, mixed>    $recipient The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function roleRecipientErrors(NotificationRuleContext $rule, int|string $index, array $recipient): array {
		$role     = (string)($recipient['role'] ?? '');
		$declared = $rule->schema->declaredRoles;
		if ($role === '') {
			$detail = 'kind=role requires a non-empty role name.';
			return [$this->recipientError(rule: $rule, index: $index, code: 'notification-recipient-role-missing', detail: $detail)];
		}

		if ($declared === [] || in_array($role, $declared, true) === true) {
			return [];
		}

		$detail = sprintf('role "%s" is not assigned by the schema authorization; declared: [%s].', $role, implode(', ', $declared));
		return [$this->recipientError(rule: $rule, index: $index, code: 'notification-recipient-role-unknown', detail: $detail)];
	}//end roleRecipientErrors()

	/**
	 * Errors for an `expression` entry, which must name a resolver.
	 *
	 * @param NotificationRuleContext $rule      The rule.
	 * @param int|string              $index     The entry's index.
	 * @param array<string, mixed>    $recipient The entry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function expressionRecipientErrors(NotificationRuleContext $rule, int|string $index, array $recipient): array {
		if ((string)($recipient['resolver'] ?? '') !== '') {
			return [];
		}

		$detail = 'kind=expression requires a resolver string (DI tag or FQCN).';
		return [$this->recipientError(rule: $rule, index: $index, code: 'notification-recipient-expression-no-resolver', detail: $detail)];
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
	 * @param NotificationRuleContext $rule      The rule.
	 * @param int                     $index     The entry's index, for messages.
	 * @param array<string, mixed>    $recipient The entry.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	private function emailRecipientErrors(NotificationRuleContext $rule, int $index, array $recipient): array {
		$errors = [];
		$path = trim((string)($recipient['field'] ?? ''));
		$segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));
		if ($segments === []) {
			return [[
				'code' => 'notification-recipient-email-field-missing',
				'message' => sprintf(
					'Notification "%s" recipient[%d] kind=email requires a `field` naming the address, e.g. "supplierRef.contactEmail".',
					$rule->name,
					$index
				),
			]];
		}

		if (in_array($segments[0], $rule->schema->propKeys, true) === false) {
			$errors[] = [
				'code' => 'notification-recipient-field-unknown',
				'message' => sprintf(
					'Notification "%s" recipient[%d] field "%s" is not declared on the schema.',
					$rule->name,
					$index,
					$segments[0]
				),
			];
		}

		if (count($segments) > 1 && in_array($segments[0], $rule->schema->propKeys, true) === true) {
			$errors = array_merge(
				$errors,
				$this->referenceWithoutRefErrors(
					declared: ($rule->schema->properties[$segments[0]] ?? null),
					path: $path,
					first: $segments[0],
					name: $rule->name,
					index: $index
				)
			);
		}

		if (count($segments) > (ReferencedAddressNotifier::MAX_HOPS + 1)) {
			$errors[] = [
				'code' => 'notification-recipient-email-path-too-deep',
				'message' => sprintf(
					'Notification "%s" recipient[%d] field "%s" follows more than %d references.',
					$rule->name,
					$index,
					$path,
					ReferencedAddressNotifier::MAX_HOPS
				),
			];
		}

		if (in_array('email', (array)($rule->spec['channels'] ?? []), true) === false) {
			$errors[] = [
				'code' => 'notification-recipient-email-needs-email-channel',
				'message' => sprintf(
					'Notification "%s" recipient[%d] is kind=email but the rule has no "email" channel, so it would reach nobody.',
					$rule->name,
					$index
				),
			];
		}

		return $errors;
	}//end emailRecipientErrors()

	/**
	 * The error for an e-mail path whose first segment goes through a reference that declares no `$ref`.
	 *
	 * @param mixed  $declared The property definition of the first segment.
	 * @param string $path     The whole path, for the message.
	 * @param string $first    The first segment.
	 * @param string $name     The rule name, for messages.
	 * @param int    $index    The entry's index, for messages.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function referenceWithoutRefErrors(mixed $declared, string $path, string $first, string $name, int $index): array {
		$ref = null;
		if (is_array($declared) === true) {
			$ref = ($declared['$ref'] ?? null);
		}

		if (is_string($ref) === true && trim($ref) !== '') {
			return [];
		}

		return [[
			'code' => 'notification-recipient-email-reference-has-no-ref',
			'message' => sprintf(
				'Notification "%s" recipient[%d] field "%s" goes through "%s", which declares no `$ref`: '
				. 'the referenced object cannot be checked against a schema, so the rule would never send.',
				$name,
				$index,
				$path,
				$first
			),
		]];
	}//end referenceWithoutRefErrors()
}//end class
