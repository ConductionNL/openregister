<?php

/**
 * OpenRegister rules domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Rules\RuleEnablementService;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleTrialService;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;

/**
 * The domain services of the rules API.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RulesDomain {
	/**
	 * Bundle the services.
	 *
	 * @param RuleInventoryService  $inventory  Derives the rule list.
	 * @param RuleTrialService      $trials     Evaluates a rule without committing.
	 * @param RuleEnablementService $enablement Switches a rule off and on.
	 * @param RuleVocabulary        $vocabulary The published kinds, verdicts and actions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly RuleInventoryService $inventory,
		public readonly RuleTrialService $trials,
		public readonly RuleEnablementService $enablement,
		public readonly RuleVocabulary $vocabulary,
	) {
	}//end __construct()
}//end class
