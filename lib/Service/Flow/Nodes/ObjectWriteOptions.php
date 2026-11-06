<?php

/**
 * The option vocabulary of an object-write step, and how its values are read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * Reads the keys of an object-write step's configuration.
 */
final class ObjectWriteOptions {

	/**
	 * Drop a mapped key whose template resolves to nothing (the default).
	 *
	 * @var string
	 */
	public const ON_MISSING_OMIT = 'omit';

	/**
	 * Fail the item when a mapped key's template resolves to nothing.
	 *
	 * @var string
	 */
	public const ON_MISSING_FAIL = 'fail';

	/**
	 * Accepted `onMissing` values.
	 *
	 * @var string[]
	 */
	public const ON_MISSING = [self::ON_MISSING_OMIT, self::ON_MISSING_FAIL];

	/**
	 * A delete that matched nothing fails the item (the default).
	 *
	 * @var string
	 */
	public const ON_NO_MATCH_ERROR = 'error';

	/**
	 * A delete that matched nothing is a no-op emitting `deleted: false`.
	 *
	 * @var string
	 */
	public const ON_NO_MATCH_SKIP = 'skip';

	/**
	 * Accepted `onNoMatch` values.
	 *
	 * @var string[]
	 */
	public const ON_NO_MATCH = [self::ON_NO_MATCH_ERROR, self::ON_NO_MATCH_SKIP];

	/**
	 * A create whose identifier is already taken overwrites it (the default).
	 *
	 * This is `saveObject()`'s long-standing upsert behaviour and stays the
	 * default: it is silent, so there is no way to enumerate which existing
	 * flows depend on it.
	 *
	 * @var string
	 */
	public const ON_CONFLICT_OVERWRITE = 'overwrite';

	/**
	 * A create whose identifier is already taken FAILS the item.
	 *
	 * Opt in with `onConflict: fail` when the write is a CLAIM — a lock, a
	 * slot, a lease, a queue position. There, "it already existed" is the whole
	 * answer, and overwriting it means two flow runs both believe they won
	 * while the loser is never told. See openregister#2210.
	 *
	 * Safe for mutual exclusion since openregister#2215: the database arbitrates
	 * through the `_uuid` unique constraint, and the losing writer is told.
	 * Measured through the node on 2026-07-31: twelve simultaneous flow runs
	 * claiming one identifier produced `1 completed / 11 stopped`, each loser
	 * carrying `An object with identifier "…" already exists`, and exactly ONE
	 * row in the table.
	 *
	 * @var string
	 */
	public const ON_CONFLICT_FAIL = 'fail';

	/**
	 * Accepted `onConflict` values.
	 *
	 * @var string[]
	 */
	public const ON_CONFLICT = [self::ON_CONFLICT_OVERWRITE, self::ON_CONFLICT_FAIL];

	/**
	 * Each option's accepted values; the first is its default.
	 *
	 * @var array<string, string[]>
	 */
	private const OPTIONS = [
		'onMissing' => self::ON_MISSING,
		'onNoMatch' => self::ON_NO_MATCH,
		'onConflict' => self::ON_CONFLICT,
	];

	/**
	 * Read an option, falling back to its default.
	 *
	 * @param array  $config The step configuration.
	 * @param string $key    The option key.
	 *
	 * @return string The option value.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function option(array $config, string $key): string {
		$allowed = self::OPTIONS[$key];
		$value = ($config[$key] ?? null);
		if (is_string($value) === true && in_array($value, $allowed, true) === true) {
			return $value;
		}

		return $allowed[0];
	}//end option()
}//end class
