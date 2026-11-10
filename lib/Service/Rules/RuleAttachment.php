<?php

/**
 * OpenRegister rule attachment
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * A rule declaration attached to a trigger, as TransitionDocument judges it.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
final class RuleAttachment {
	/**
	 * Name the attachment.
	 *
	 * @param string               $ruleName    The rule, for the message.
	 * @param array<string, mixed> $declaration Its declaration.
	 * @param string|null          $trigger     The trigger it is attached to.
	 */
	public function __construct(
		public readonly string $ruleName,
		public readonly array $declaration,
		public readonly ?string $trigger,
	) {
	}//end __construct()

	/**
	 * Whether the declaration says the rule reads the prior value.
	 *
	 * @return bool True only for a literal `true`.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	public function requiresPrior(): bool {
		return ($this->declaration[TransitionDocument::REQUIRES_PRIOR] ?? false) === true;
	}//end requiresPrior()
}//end class
