<?php

/**
 * The scoped elevation an action may declare, and why a declaration is refused.
 *
 * ```json
 * "elevate": [ { "register": "publication", "schema": "publication", "verbs": ["create", "update"] } ]
 * ```
 *
 * After the person's right on the subject and the precondition have passed,
 * a write inside the run that hits a listed `(register, schema, verb)` and that
 * the person's own rights refuse may be retried as the system. Nothing else
 * is ever elevated. The save refuses a wildcard in any position, and refuses
 * `elevate` on an action no authorization rule grants, since an elevated
 * action open to everyone is a privilege escalation by configuration.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * Reads and shape-checks `elevate` declarations.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
final class ObjectActionElevation {

	/**
	 * The verbs an `elevate` entry may name.
	 *
	 * @var list<string>
	 */
	public const VERBS = ['create', 'update', 'delete'];

	/**
	 * Refusals about one action's `elevate`.
	 *
	 * @param string                    $key           The action key.
	 * @param array<string, mixed>      $definition    The declared entry.
	 * @param array<string, mixed>|null $authorization The authorization block; null skips the grant check.
	 *
	 * @return list<string> The refusals.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public static function refusals(string $key, array $definition, ?array $authorization): array {
		if (array_key_exists('elevate', $definition) === false) {
			return [];
		}

		$elevate = $definition['elevate'];
		if (is_array($elevate) === false || array_is_list($elevate) === false || $elevate === []) {
			return [sprintf('Action "%s": "elevate" must be a non-empty list of {register, schema, verbs}.', $key)];
		}

		if (($definition['mode'] ?? 'sync') === 'async') {
			return [sprintf('Action "%s": an elevated action runs sync; a queued run would execute after its elevation has ended.', $key)];
		}

		foreach ($elevate as $entry) {
			$refusal = self::entryRefusal(key: $key, entry: $entry);
			if ($refusal !== null) {
				return [$refusal];
			}
		}

		return self::grantRefusals(key: $key, authorization: $authorization);
	}//end refusals()

	/**
	 * Refuse an elevation no authorization rule grants.
	 *
	 * @param string                    $key           The action key.
	 * @param array<string, mixed>|null $authorization The authorization block; null skips the check.
	 *
	 * @return list<string> The refusals.
	 */
	private static function grantRefusals(string $key, ?array $authorization): array {
		if ($authorization === null || empty($authorization[$key] ?? null) === false) {
			return [];
		}

		return [
			sprintf(
				'Action "%s": "elevate" needs an authorization rule granting "%s"; an elevated action open to everyone is a privilege escalation.',
				$key,
				$key
			),
		];
	}//end grantRefusals()

	/**
	 * The `elevate` entries of a declaration, normalised.
	 *
	 * @param array<string, mixed> $definition The declared entry.
	 *
	 * @return list<array{register: string, schema: string, verbs: list<string>}> The entries.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public static function entriesOf(array $definition): array {
		$elevate = [];
		foreach ((array)($definition['elevate'] ?? []) as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$elevate[] = [
				'register' => (string)($entry['register'] ?? ''),
				'schema' => (string)($entry['schema'] ?? ''),
				'verbs' => array_values(array_map('strval', (array)($entry['verbs'] ?? []))),
			];
		}

		return $elevate;
	}//end entriesOf()

	/**
	 * The refusal one `elevate` entry earns.
	 *
	 * @param string $key   The action key.
	 * @param mixed  $entry The entry.
	 *
	 * @return string|null The refusal.
	 */
	private static function entryRefusal(string $key, mixed $entry): ?string {
		if (is_array($entry) === false) {
			return sprintf('Action "%s": every "elevate" entry must be {register, schema, verbs}.', $key);
		}

		if (self::hasWildcard(entry: $entry) === true) {
			return sprintf('Action "%s": "elevate" may not use a wildcard.', $key);
		}

		foreach (['register', 'schema'] as $field) {
			if (is_string($entry[$field] ?? null) === false || trim((string)$entry[$field]) === '') {
				return sprintf('Action "%s": every "elevate" entry must name a register and a schema.', $key);
			}
		}

		$verbs = ($entry['verbs'] ?? null);
		if (is_array($verbs) === false || $verbs === [] || array_diff($verbs, self::VERBS) !== []) {
			return sprintf('Action "%s": "elevate" verbs may only be %s.', $key, implode(', ', self::VERBS));
		}

		return null;
	}//end entryRefusal()

	/**
	 * Whether any field of an entry holds a wildcard.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return bool True when one does.
	 */
	private static function hasWildcard(array $entry): bool {
		$named = [($entry['register'] ?? null), ($entry['schema'] ?? null)];
		if (is_array($entry['verbs'] ?? null) === true) {
			$named = array_merge($named, $entry['verbs']);
		}

		foreach ($named as $value) {
			if (is_string($value) === true && str_contains($value, '*') === true) {
				return true;
			}
		}

		return false;
	}//end hasWildcard()
}//end class
