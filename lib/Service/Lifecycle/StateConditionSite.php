<?php

/**
 * OpenRegister StateConditionSite
 *
 * Where one condition of `x-openregister-lifecycle.states` sits: the state,
 * the label an error message names it by, and the schema properties it reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The state, label and schema properties a state condition is validated in.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class StateConditionSite {

	/**
	 * Constructor.
	 *
	 * @param string               $state      The state name.
	 * @param string               $label      Where the condition sits, for the message.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $state,
		public readonly string $label,
		public readonly array $properties,
	) {
	}//end __construct()

	/**
	 * The same state and properties under another label.
	 *
	 * @param string $label Where the condition sits, for the message.
	 *
	 * @return self The site under the new label.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function withLabel(string $label): self {
		return new self(state: $this->state, label: $label, properties: $this->properties);
	}//end withLabel()
}//end class
