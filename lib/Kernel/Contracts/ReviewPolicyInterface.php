<?php
/**
 * ReviewPolicyInterface: what callers outside Processes read of a review policy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The review policy of one schema, or of the instance for files without one.
 *
 * Implemented by \OCA\OpenRegister\Service\Decision\ReviewPolicy. The decision
 * request events carry this read interface instead of the Processes value
 * class (ADR-012 Rule 2, ADR-013 Rule 1: events name Kernel types only).
 */
interface ReviewPolicyInterface {

	/**
	 * Whether a decision belongs to the person who took it.
	 *
	 * @return bool True for `decider` ownership.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function isDeciderOwned(): bool;

	/**
	 * The supervisor groups.
	 *
	 * @return array<string> Group ids.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function getSupervisors(): array;

	/**
	 * Whether another reviewer's decisions are hidden.
	 *
	 * @return bool True when hiding is on.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-schema-can-hide-one-reviewers-decisions-from-another-req-rod-002
	 */
	public function hidesOthers(): bool;

	/**
	 * The policy as a declaration.
	 *
	 * @return array{ownership: string, supervisors: array<string>, hideOthers: bool}
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function toArray(): array;
}//end interface
