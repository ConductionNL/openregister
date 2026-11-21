<?php
/**
 * AuditProcessContextInterface: the process work in flight that an audit row records.
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

use OCA\OpenRegister\Db\AuditTrail;

/**
 * What the audit trail asks about the flow run and the lifecycle move in flight.
 *
 * The audit trail (Compliance) asks while it builds a row; Processes runs the
 * flows and the lifecycle moves and binds its answer in its own wiring. Every
 * method answers "nothing in flight" when the process side cannot tell, so a
 * row is never lost to an unwired frame.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface AuditProcessContextInterface {

	/**
	 * Stamp the flow run, node and step in flight onto the row; leave it untouched outside a run.
	 *
	 * @param AuditTrail $auditTrail The row being built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function stampFlowFrame(AuditTrail $auditTrail): void;

	/**
	 * The automatic lifecycle action being applied right now, or null for a move a user asked for.
	 *
	 * @return string|null The action name, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function automaticTransition(): ?string;

	/**
	 * The kind of lifecycle move in flight for an object, when the audit names it (a declared reopen), or null.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return string|null The kind, or null for an ordinary write.
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md#requirement-a-declared-reopen-is-the-only-move-out-of-a-terminal-state
	 */
	public function transitionKindFor(string $uuid): ?string;
}//end interface
