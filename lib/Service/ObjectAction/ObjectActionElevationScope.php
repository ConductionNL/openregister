<?php

/**
 * The scoped elevation of one action run, for the duration of that run.
 *
 * Opened by {@see ObjectActionRunner} around the body of an action that
 * declares `elevate`, after the person's right on the subject and the
 * precondition have passed. While it is open, a write the person's own
 * rights refuse is allowed as the system when, and only when, it hits a
 * listed `(register, schema, verb)`. Every other refused write stays refused.
 * Each write let through is remembered, so its audit row can name the action
 * (`elevatedBy`), the person (`triggeredBy`) and `runAs: system` (ADR-099).
 *
 * Request-scoped and stacked, like {@see \OCA\OpenRegister\Service\WriteCause}:
 * the frame is popped in a `finally`, so a throwing run never leaves an
 * elevation open for whatever the request does next. The schema save refuses
 * `elevate` on an async action, because a queued run executes after this
 * frame is gone.
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
 * Holds the elevation of the action run in progress.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
final class ObjectActionElevationScope {

	/**
	 * Open frames, innermost last.
	 *
	 * @var list<array{action: string, person: string|null, entries: list<array<string, mixed>>, used: array<string, true>}>
	 */
	private static array $frames = [];

	/**
	 * Run an action body with its elevation open.
	 *
	 * @param ActionElevation $elevation The action, the person running it and its entries.
	 * @param callable        $operation The body.
	 *
	 * @return mixed Whatever the body returned.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public static function run(ActionElevation $elevation, callable $operation): mixed {
		self::$frames[] = ['action' => $elevation->action, 'person' => $elevation->person, 'entries' => $elevation->entries, 'used' => []];

		try {
			return $operation();
		} finally {
			array_pop(self::$frames);
		}
	}//end run()

	/**
	 * Whether a write the person's rights refused may go ahead as the system; marks the entry used.
	 *
	 * @param ElevatedWrite $write The schema, register and verb of the write.
	 *
	 * @return bool True when the open elevation lists it.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public static function permits(ElevatedWrite $write): bool {
		$frame = array_key_last(self::$frames);
		if ($frame === null || $write->schemaId === null || in_array($write->verb, ObjectActionElevation::VERBS, true) === false) {
			return false;
		}

		foreach (self::$frames[$frame]['entries'] as $entry) {
			if (self::matches(entry: $entry, write: $write) === true) {
				self::$frames[$frame]['used'][$write->schemaId . ':' . $write->verb] = true;
				return true;
			}
		}

		return false;
	}//end permits()

	/**
	 * The attribution an audit row of an elevated write carries, or null.
	 *
	 * @param string|int|null $schemaId The schema of the audited object.
	 * @param string          $verb     The audited action.
	 *
	 * @return array{elevatedBy: string, triggeredBy: string|null, runAs: string}|null The attribution.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public static function attributionFor(string|int|null $schemaId, string $verb): ?array {
		$frame = array_key_last(self::$frames);
		if ($frame === null || isset(self::$frames[$frame]['used'][$schemaId . ':' . $verb]) === false) {
			return null;
		}

		return [
			'elevatedBy' => self::$frames[$frame]['action'],
			'triggeredBy' => self::$frames[$frame]['person'],
			'runAs' => 'system',
		];
	}//end attributionFor()

	/**
	 * Whether an entry lists this write.
	 *
	 * A write that names no register (a create before it is placed) is matched
	 * on schema and verb; one that names a register must name the listed one.
	 *
	 * @param array{register: string, schema: string, verbs: list<string>} $entry The entry.
	 * @param ElevatedWrite                                                     $write The write, its schema known.
	 *
	 * @return bool True when it does.
	 */
	private static function matches(array $entry, ElevatedWrite $write): bool {
		if ($entry['schema'] !== (string)$write->schemaId || in_array($write->verb, $entry['verbs'], true) === false) {
			return false;
		}

		return ($write->registerId !== null && $entry['register'] === (string)$write->registerId);
	}//end matches()
}//end class
