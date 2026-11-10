<?php

/**
 * OpenRegister rule trial run
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
 * One rule tried against one payload, inside RuleTrialService.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleTrialRun {
	/**
	 * Start the run.
	 *
	 * @param RuleDescriptor       $rule       The rule to evaluate.
	 * @param array<string, mixed> $payload    The object payload.
	 * @param string|null          $objectUuid The object evaluated, when it was a stored one.
	 */
	public function __construct(
		public readonly RuleDescriptor $rule,
		public readonly array $payload,
		public readonly ?string $objectUuid = null,
	) {
	}//end __construct()
}//end class
