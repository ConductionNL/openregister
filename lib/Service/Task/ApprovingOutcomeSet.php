<?php

/**
 * A declared set of approving outcomes, normalised.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The usable entries of a declared `approvingOutcomes` value: trimmed,
 * lowercased, without empty or non-string entries.
 *
 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
 */
final class ApprovingOutcomeSet {

	/**
	 * The normalised entries.
	 *
	 * @var array<int, string>
	 */
	private readonly array $entries;

	/**
	 * Constructor.
	 *
	 * @param mixed $declared The declared `approvingOutcomes` value.
	 */
	public function __construct(mixed $declared) {
		$entries = [];
		if (is_array($declared) === true) {
			foreach ($declared as $entry) {
				if (is_string($entry) === true && trim($entry) !== '') {
					$entries[] = strtolower(trim($entry));
				}
			}
		}

		$this->entries = $entries;

	}//end __construct()

	/**
	 * The usable entries.
	 *
	 * @return array<int, string> The entries; empty when none is usable.
	 *
	 * @spec openspec/specs/flow-approval-consolidation/spec.md#requirement-a-completion-that-neither-approves-nor-rejects-ends-the-sequence-as-undecided
	 */
	public function entries(): array {
		return $this->entries;
	}//end entries()
}//end class
