<?php

/**
 * An approval chain: its compiled template and the positions its tier resolved to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The compiled template of an approval chain, with the positions its tier resolved to.
 *
 * The resolved tier's positions win over the template's own; without a
 * resolved tier the template's positions are the chain.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
final readonly class ResolvedChain {

	/**
	 * @param array<string, mixed>                  $template      The compiled template (name, positions, outcomes, ...).
	 * @param array<int, array<string, mixed>>|null $tierPositions The resolved tier's positions; null takes the template's.
	 */
	public function __construct(
		public array $template,
		public ?array $tierPositions = null,
	) {
	}//end __construct()

	/**
	 * The positions the sequence walks: the resolved tier's, else the template's.
	 *
	 * @return array<int|string, mixed> The positions, in order.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function positions(): array {
		return ($this->tierPositions ?? (array)($this->template['positions'] ?? []));
	}//end positions()
}//end class
