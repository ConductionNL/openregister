<?php

/**
 * StateConditionEvaluatorInterface: what callers outside the component use of StateConditionEvaluator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Judge the conditions a lifecycle state puts on an object.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface StateConditionEvaluatorInterface {

	/**
	 * The refusal a move between two states earns, or null when it may proceed.
	 *
	 * Both halves are evaluated in the order they happen: the object leaves the
	 * old state before it enters the new one, so an `exit` refusal is reported
	 * ahead of an `entry` one. A move that stays in the same state is not a
	 * move and is evaluated as neither.
	 *
	 * @param StateSave     $save     The lifecycle annotation and the object as it would be saved.
	 * @param StateCrossing $crossing The state being left (null on a create) and the state being entered.
	 *
	 * @return array<string, mixed>|null The structured refusal, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function refusal(StateSave $save, StateCrossing $crossing): ?array;
}//end interface
