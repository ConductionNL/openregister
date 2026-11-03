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
