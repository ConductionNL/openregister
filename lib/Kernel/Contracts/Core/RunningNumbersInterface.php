<?php
/**
 * RunningNumbersInterface: instance-wide running numbers, as object save asks for them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCP\DB\Exception as DbException;

/**
 * The counter behind generated identifiers.
 *
 * Object save (Core) issues a generated identifier on create and, when a
 * caller supplies one, moves the counter past it. It asks for one number per
 * scope key, instance wide; Configuration keeps the counters and binds its
 * answer in its own wiring.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */
interface RunningNumbersInterface {

	/**
	 * Reserve the next number of a scope, exactly once across concurrent callers.
	 *
	 * @param string $scopeKey The scope, for example a sequence name and its period.
	 *
	 * @return int The reserved number.
	 *
	 * @throws DbException When the counter cannot be read or advanced.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function reserveNext(string $scopeKey): int;

	/**
	 * Move a scope's counter past a number a caller supplied, never backwards.
	 *
	 * @param string $scopeKey The scope, for example a sequence name and its period.
	 * @param int    $reserved The number now taken.
	 *
	 * @return void
	 *
	 * @throws DbException When the counter cannot be read or advanced.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function advanceTo(string $scopeKey, int $reserved): void;
}//end interface
