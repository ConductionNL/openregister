<?php
/**
 * TransitionInFlightInterface: the lifecycle transition being applied to an object right now.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Compliance
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Compliance;

/**
 * Answers which lifecycle transition is in flight for an object, for its audit row.
 *
 * The audit trail (Compliance) asks while it builds a row; the lifecycle's
 * request state (Processes) answers and is bound to this contract. Both
 * answers are request state: null means no such transition is in flight.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface TransitionInFlightInterface {

	/**
	 * The app a system transition of this object is running for, or null.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return string|null The app id, or null for an ordinary write.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function systemAppFor(string $uuid): ?string;

	/**
	 * The declared lifecycle action being applied to this object, or null.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return string|null The declared action, or null when none is in flight.
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md#requirement-a-declared-reopen-is-the-only-move-out-of-a-terminal-state
	 */
	public function declaredFor(string $uuid): ?string;
}//end interface
