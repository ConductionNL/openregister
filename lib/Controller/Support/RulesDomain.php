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

use OCA\OpenRegister\Service\Rules\RuleEnablementServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleInventoryServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleTrialServiceInterface;
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
	 * @param RuleInventoryServiceInterface  $inventory  Derives the rule list.
	 * @param RuleTrialServiceInterface      $trials     Evaluates a rule without committing.
	 * @param RuleEnablementServiceInterface $enablement Switches a rule off and on.
	 * @param RuleVocabulary        $vocabulary The published kinds, verdicts and actions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly RuleInventoryServiceInterface $inventory,
		public readonly RuleTrialServiceInterface $trials,
		public readonly RuleEnablementServiceInterface $enablement,
		public readonly RuleVocabulary $vocabulary,
	) {
	}//end __construct()
}//end class
