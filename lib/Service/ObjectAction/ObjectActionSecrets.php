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
 * passes it through {@see redact()} first.
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
	 * The shortest secret also redacted where it is embedded in a longer
	 * string; a shorter one is redacted only where it is the whole value.
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
	 * Replace every held value of this reference inside a run's data by
	 * `[redacted]`: a value that equals a secret, and, for a secret of at least
	 * {@see MIN_EMBEDDED_LENGTH} characters, a secret embedded in a longer
	 * string (a message a step built from it). Objects are left as they are.
	 *
	 * @param mixed $data The data to store.
	 * @param mixed $ref  The reference the run's context carries.
	 *
	 * @return mixed The data, without the held values.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public static function redact(mixed $data, mixed $ref): mixed {
		$secrets = [];
		foreach (self::read(ref: $ref) as $value) {
			if ((is_string($value) === true || is_int($value) === true || is_float($value) === true) && (string)$value !== '') {
				$secrets[] = (string)$value;
			}
		}

		if ($secrets === []) {
			return $data;
		}

		return self::redactValues(data: $data, secrets: $secrets);
	}//end redact()

	/**
	 * Walk the data, replacing the secrets.
	 *
	 * @param mixed              $data    The data.
	 * @param array<int, string> $secrets The secret values.
	 *
	 * @return mixed The data, redacted.
	 */
	private static function redactValues(mixed $data, array $secrets): mixed {
		if (is_array($data) === true) {
			foreach ($data as $key => $value) {
				$data[$key] = self::redactValues(data: $value, secrets: $secrets);
			}

			return $data;
		}

		if (is_string($data) === false && is_int($data) === false && is_float($data) === false) {
			return $data;
		}

		return self::redactScalar(value: $data, secrets: $secrets);
	}//end redactValues()

	/**
	 * One scalar without the secrets: the whole value when it is one, else
	 * each long-enough secret embedded in a string.
	 *
	 * @param string|int|float   $value   The value.
	 * @param array<int, string> $secrets The secret values.
	 *
	 * @return string|int|float The value, redacted.
	 */
	private static function redactScalar(string|int|float $value, array $secrets): string|int|float {
		if (in_array((string)$value, $secrets, true) === true) {
			return self::REDACTED;
		}

		if (is_string($value) === false) {
			return $value;
		}

		foreach ($secrets as $secret) {
			if (strlen($secret) >= self::MIN_EMBEDDED_LENGTH) {
				$value = str_replace($secret, self::REDACTED, $value);
			}
		}

		return $value;
	}//end redactScalar()

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
