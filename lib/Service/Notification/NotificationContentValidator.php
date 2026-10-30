<?php

/**
 * Schema-save validation of a notification rule's `actions` and `message`.
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
 * Validates the `actions` and `message` of one `x-openregister-notifications` rule.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class NotificationContentValidator {

	/**
	 * Valid action `target.kind` values (foundation contract / ADR-031 dialect).
	 *
	 * @var array<int, string>
	 */
	private const VALID_ACTION_TARGET_KINDS = ['object-detail', 'route', 'url', 'task-verb'];

	/**
	 * The lifecycle verbs a `task-verb` action target may name. A target
	 * names a VERB, never an author-composed URL, so a rule cannot aim a
	 * notification button anywhere it likes (flow-task-inbox-projections,
	 * design D-1).
	 *
	 * @var array<int, string>
	 */
	private const VALID_TASK_VERBS = ['claim', 'unclaim', 'complete', 'resolve', 'cancel'];

	/**
	 * Hard cap on declared action buttons — the Web Notification API renders
	 * at most two action buttons on the desktop OS popup.
	 *
	 * @var int
	 */
	private const MAX_ACTIONS = 2;

	/**
	 * Validate the optional `actions[]` array (foundation contract / ADR-031).
	 *
	 * Rules:
	 *  - `actions` MUST be an array; more than 2 entries → notification-too-many-actions.
	 *  - each action's `label` MUST be a per-locale map with at least one
	 *    non-empty locale value → otherwise notification-action-bad-label.
	 *  - each action's `target.kind` MUST be one of object-detail | route | url | task-verb
	 *    → otherwise notification-action-bad-target.
	 *  - a `task-verb` target MUST name a known lifecycle verb in `verb`
	 *    → otherwise notification-action-bad-target, naming the value.
	 *
	 * @param mixed  $actions Raw value of the `actions` key.
	 * @param string $name    Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function validateActions(mixed $actions, string $name): array {
		if (is_array($actions) === false) {
			return [
				[
					'code' => 'notification-action-bad-target',
					'message' => sprintf('Notification "%s" actions must be an array.', $name),
				],
			];
		}

		$errors = [];
		if (count($actions) > self::MAX_ACTIONS) {
			$errors[] = [
				'code' => 'notification-too-many-actions',
				'message' => sprintf(
					'Notification "%s" declares %d actions; the Web Notification API renders at most %d.',
					$name,
					count($actions),
					self::MAX_ACTIONS
				),
			];
		}

		foreach ($actions as $idx => $action) {
			$where  = sprintf('Notification "%s" action[%s]', $name, (string)$idx);
			$errors = array_merge($errors, $this->actionErrors(action: $action, where: $where));
		}

		return $errors;
	}//end validateActions()

	/**
	 * Errors for one declared action: its shape, label, `primary` flag and target.
	 *
	 * @param mixed  $action The raw action.
	 * @param string $where  Diagnostic prefix naming the rule and the action index.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function actionErrors(mixed $action, string $where): array {
		if (is_array($action) === false) {
			return [['code' => 'notification-action-bad-target', 'message' => $where.' must be an object.']];
		}

		$errors = [];
		if ($this->hasNonEmptyLocaleValue(label: ($action['label'] ?? null)) === false) {
			$errors[] = [
				'code' => 'notification-action-bad-label',
				'message' => $where.' label must be a per-locale map with at least one non-empty value.',
			];
		}

		if (array_key_exists('primary', $action) === true && is_bool($action['primary']) === false) {
			$errors[] = ['code' => 'notification-action-bad-target', 'message' => $where.' primary must be a boolean.'];
		}

		return array_merge($errors, $this->actionTargetErrors(target: ($action['target'] ?? null), where: $where));
	}//end actionErrors()

	/**
	 * Whether a label is a per-locale map with at least one non-empty string.
	 *
	 * @param mixed $label The raw label.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function hasNonEmptyLocaleValue(mixed $label): bool {
		if (is_array($label) === false) {
			return false;
		}

		foreach ($label as $localeValue) {
			if (is_string($localeValue) === true && $localeValue !== '') {
				return true;
			}
		}

		return false;
	}//end hasNonEmptyLocaleValue()

	/**
	 * Errors for an action target: a recognised kind, and a known verb for a task-verb.
	 *
	 * @param mixed  $target The raw target.
	 * @param string $where  Diagnostic prefix naming the rule and the action index.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function actionTargetErrors(mixed $target, string $where): array {
		$targetKind = '';
		if (is_array($target) === true) {
			$targetKind = (string)($target['kind'] ?? '');
		}

		if (in_array($targetKind, self::VALID_ACTION_TARGET_KINDS, true) === false) {
			return [
				[
					'code' => 'notification-action-bad-target',
					'message' => sprintf(
						'%s target.kind "%s" is not in [%s].',
						$where,
						$targetKind,
						implode(', ', self::VALID_ACTION_TARGET_KINDS)
					),
				],
			];
		}

		if ($targetKind === 'task-verb') {
			return $this->validateTaskVerbTarget(target: $target, where: $where);
		}

		return [];
	}//end actionTargetErrors()

	/**
	 * Validate a `task-verb` action target: it MUST name a lifecycle verb from
	 * the closed list, never an author-composed URL.
	 *
	 * @param mixed  $target The raw target (already known to be kind task-verb).
	 * @param string $where  Diagnostic prefix naming the rule and the action index.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
	 */
	private function validateTaskVerbTarget(mixed $target, string $where): array {
		$verb = '';
		if (is_array($target) === true) {
			$verb = (string)($target['verb'] ?? '');
		}

		if (in_array($verb, self::VALID_TASK_VERBS, true) === true) {
			return [];
		}

		return [
			[
				'code' => 'notification-action-bad-target',
				'message' => sprintf(
					'%s target.verb "%s" is not a task lifecycle verb in [%s].',
					$where,
					$verb,
					implode(', ', self::VALID_TASK_VERBS)
				),
			],
		];
	}//end validateTaskVerbTarget()

	/**
	 * Validate the optional `message` field (notification body).
	 *
	 * Mirrors the `subject` shape contract exactly: either a single
	 * non-empty template string OR a per-locale map ({nl: "...", en: "..."}
	 * optionally prefixed with `defaultLocale: <code>`). Every malformed
	 * shape returns a single `notification-bad-message` error so leaf
	 * authors get one canonical error code for a broken body template.
	 *
	 * @param mixed  $message Raw value of the `message` key.
	 * @param string $name    Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function validateMessage(mixed $message, string $name): array {
		if (is_array($message) === true) {
			return $this->messageMapErrors(message: $message, name: $name);
		}

		if (is_string($message) === false) {
			return [$this->badMessage(message: sprintf('Notification "%s" message must be a string or a per-locale map.', $name))];
		}

		if ($message === '') {
			return [$this->badMessage(message: sprintf('Notification "%s" message must be a non-empty string when present.', $name))];
		}

		return [];
	}//end validateMessage()

	/**
	 * Errors for a per-locale message map: at least one locale, each a
	 * non-empty string, and a `defaultLocale` that names one of them.
	 *
	 * @param array<int|string, mixed> $message The message map.
	 * @param string                   $name    Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function messageMapErrors(array $message, string $name): array {
		$errors     = [];
		$localeKeys = array_filter(
			array_keys($message),
			static fn ($key): bool => $key !== 'defaultLocale' && is_string($key) === true
		);
		if (count($localeKeys) === 0) {
			$errors[] = $this->badMessage(
				message: sprintf('Notification "%s" message map must declare at least one locale (e.g. nl, en).', $name)
			);
		}

		foreach ($localeKeys as $localeKey) {
			if (is_string($message[$localeKey]) === false || $message[$localeKey] === '') {
				$errors[] = $this->badMessage(
					message: sprintf('Notification "%s" message for locale "%s" must be a non-empty string.', $name, $localeKey)
				);
			}
		}

		$defaultLocale = $this->undeclaredDefaultLocale(map: $message);
		if ($defaultLocale !== null) {
			$errors[] = $this->badMessage(
				message: sprintf(
					'Notification "%s" message defaultLocale "%s" is not declared in the message map.',
					$name,
					$defaultLocale
				)
			);
		}

		return $errors;
	}//end messageMapErrors()

	/**
	 * The declared `defaultLocale` of a subject or message map when it does
	 * not name a locale the map declares, as it appears in a diagnostic.
	 *
	 * A non-scalar is named by its type, since casting it would only print
	 * "Array".
	 *
	 * @param array<mixed, mixed> $map The subject or message map.
	 *
	 * @return string|null Null when the map declares no default or a declared one.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function undeclaredDefaultLocale(array $map): ?string {
		if (isset($map['defaultLocale']) === false) {
			return null;
		}

		$defaultLocale = $map['defaultLocale'];
		if (is_string($defaultLocale) === true && isset($map[$defaultLocale]) === true) {
			return null;
		}

		if (is_scalar($defaultLocale) === true) {
			return (string)$defaultLocale;
		}

		return get_debug_type($defaultLocale);
	}//end undeclaredDefaultLocale()

	/**
	 * A `notification-bad-message` error.
	 *
	 * @param string $message The diagnostic.
	 *
	 * @return array{code: string, message: string}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function badMessage(string $message): array {
		return ['code' => 'notification-bad-message', 'message' => $message];
	}//end badMessage()
}//end class
