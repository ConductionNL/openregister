<?php

/**
 * The `writeOnly` inputs of a run in progress, held for this request only.
 *
 * A sensitive input (a PIN, a one-time code) must reach the steps that use
 * it and must never be written to the run's stored context or the audit
 * digest. So it never enters the context: the context carries only an opaque
 * reference, and the `openregister.action-inputs` node exchanges that
 * reference for the values here. The values die with the request, which is
 * why an action with a writeOnly input cannot run async (the schema save
 * refuses that combination).
 *
 * The steps carry the values in their items, so whatever stores a run (its
 * log of step inputs and outputs, its step rows, its final items, its context)
 * passes it through {@see redactItems()} and {@see redactLog()} first (by
 * key), and {@see redactCopies()} (a whole string equal to a secret of four
 * or more characters), so no timestamp, uuid or counter is cut into.
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * Request-scoped holder of writeOnly action inputs.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */
final class ObjectActionSecrets {

	/**
	 * The run-context key carrying the reference.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'inputsRef';

	/**
	 * What a held value is replaced by in stored run data.
	 */
	public const REDACTED = '[redacted]';

	/**
	 * The shortest secret also looked for outside its own key: as a whole
	 * copied string value, or as a whole token in a free text.
	 */
	public const MIN_EMBEDDED_LENGTH = 4;

	/**
	 * Held values by reference.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $held = [];

	/**
	 * Hold values and answer the reference that reads them back.
	 *
	 * @param array<string, mixed> $values The writeOnly inputs.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public static function hold(array $values): string {
		$ref = bin2hex(random_bytes(16));
		self::$held[$ref] = $values;

		return $ref;
	}//end hold()

	/**
	 * The values held under a reference, or none.
	 *
	 * @param mixed $ref The reference from the run context.
	 *
	 * @return array<string, mixed> The values.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public static function read(mixed $ref): array {
		if (is_string($ref) === false) {
			return [];
		}

		return (self::$held[$ref] ?? []);
	}//end read()

	/**
	 * Items with the held inputs of this reference redacted where the
	 * `openregister.action-inputs` node puts them: `json.inputs.<name>`.
	 *
	 * BY KEY, NEVER BY VALUE. A secret "1" or "2026" also occurs in counters,
	 * timestamps and uuids, and those carry what a resumed run needs; only
	 * the named input is replaced.
	 *
	 * @param array<int|string, mixed> $items Flow items.
	 * @param mixed                    $ref   The reference the run's context carries.
	 *
	 * @return array<int|string, mixed> The items.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public static function redactItems(array $items, mixed $ref): array {
		$names = array_keys(self::read(ref: $ref));
		if ($names === []) {
			return $items;
		}

		foreach ($items as $index => $item) {
			if (is_array($item) === false || is_array($item['json']['inputs'] ?? null) === false) {
				continue;
			}

			foreach ($names as $name) {
				if (array_key_exists($name, $item['json']['inputs']) === true) {
					$item['json']['inputs'][$name] = self::REDACTED;
				}
			}

			$items[$index] = $item;
		}

		return $items;
	}//end redactItems()

	/**
	 * Log entries with the item samples of each step (`input`, `output`) redacted.
	 *
	 * @param array<int, mixed> $log The entries.
	 * @param mixed             $ref The reference.
	 *
	 * @return array<int, mixed> The entries.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public static function redactLog(array $log, mixed $ref): array {
		foreach ($log as $index => $entry) {
			foreach (['input', 'output'] as $side) {
				if (is_array($entry) === true && is_array($entry[$side]['items'] ?? null) === true) {
					$entry[$side]['items'] = self::redactItems(items: $entry[$side]['items'], ref: $ref);
				}
			}

			$log[$index] = $entry;
		}

		return $log;
	}//end redactLog()

	/**
	 * A free text (an error message) with every held value of at least
	 * {@see MIN_EMBEDDED_LENGTH} characters replaced where it stands as a whole
	 * token: not inside a longer run of letters, digits or dashes, so a date
	 * or a uuid that contains the same digits is left alone.
	 *
	 * @param string|null $text The text.
	 * @param mixed       $ref  The reference.
	 *
	 * @return string|null The text.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public static function redactText(?string $text, mixed $ref): ?string {
		if ($text === null) {
			return null;
		}

		foreach (self::copyableSecrets(ref: $ref) as $secret) {
			$pattern = '/(?<![A-Za-z0-9-])' . preg_quote($secret, '/') . '(?![A-Za-z0-9-])/';
			$text    = (string)preg_replace($pattern, self::REDACTED, $text);
		}

		return $text;
	}//end redactText()

	/**
	 * The data with every STRING whose whole value equals a held value of at
	 * least {@see MIN_EMBEDDED_LENGTH} characters replaced: the copies a later
	 * step made (`json.code` set from `{{inputs.pin}}`).
	 *
	 * Exact match only, never a substring, so a timestamp or a uuid that
	 * contains the same digits stays intact; integers and objects are never
	 * touched, and a shorter secret is left to the key pass. The limit: a
	 * secret embedded inside a longer string is not found.
	 *
	 * @param mixed $data The data.
	 * @param mixed $ref  The reference.
	 *
	 * @return mixed The data.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public static function redactCopies(mixed $data, mixed $ref): mixed {
		$secrets = self::copyableSecrets(ref: $ref);
		if ($secrets === []) {
			return $data;
		}

		return self::replaceCopies(data: $data, secrets: $secrets);
	}//end redactCopies()

	/**
	 * Walk the data, replacing whole-string copies.
	 *
	 * @param mixed              $data    The data.
	 * @param array<int, string> $secrets The secrets.
	 *
	 * @return mixed The data.
	 */
	private static function replaceCopies(mixed $data, array $secrets): mixed {
		if (is_string($data) === true && in_array($data, $secrets, true) === true) {
			return self::REDACTED;
		}

		if (is_array($data) === false) {
			return $data;
		}

		foreach ($data as $key => $value) {
			$data[$key] = self::replaceCopies(data: $value, secrets: $secrets);
		}

		return $data;
	}//end replaceCopies()

	/**
	 * Whether a held value occurs in the data: a string equal to it, or, for a
	 * value of at least {@see MIN_EMBEDDED_LENGTH} characters, containing it.
	 *
	 * @param mixed $data The data.
	 * @param mixed $ref  The reference.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public static function occursIn(mixed $data, mixed $ref): bool {
		$secrets = self::secretStrings(ref: $ref);
		if ($secrets === []) {
			return false;
		}

		$found = false;
		$data  = (array)$data;
		array_walk_recursive(
			$data,
			static function (mixed $value) use ($secrets, &$found): void {
				if ($found === true || is_string($value) === false) {
					return;
				}

				foreach ($secrets as $secret) {
					if ($value === $secret || (strlen($secret) >= self::MIN_EMBEDDED_LENGTH && str_contains($value, $secret) === true)) {
						$found = true;
						return;
					}
				}
			}
		);

		return $found;
	}//end occursIn()

	/**
	 * The held values long enough to be looked for outside their own key.
	 *
	 * @param mixed $ref The reference.
	 *
	 * @return array<int, string>
	 */
	private static function copyableSecrets(mixed $ref): array {
		return array_values(
			array_filter(
				self::secretStrings(ref: $ref),
				static fn (string $secret): bool => strlen($secret) >= self::MIN_EMBEDDED_LENGTH
			)
		);
	}//end copyableSecrets()

	/**
	 * The held values as non-empty strings.
	 *
	 * @param mixed $ref The reference.
	 *
	 * @return array<int, string>
	 */
	private static function secretStrings(mixed $ref): array {
		$secrets = [];
		foreach (self::read(ref: $ref) as $value) {
			if ((is_string($value) === true || is_int($value) === true || is_float($value) === true) && (string)$value !== '') {
				$secrets[] = (string)$value;
			}
		}

		return $secrets;
	}//end secretStrings()

	/**
	 * Forget the values held under a reference.
	 *
	 * @param string $ref The reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public static function release(string $ref): void {
		unset(self::$held[$ref]);
	}//end release()
}//end class
