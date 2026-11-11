<?php

/**
 * OpenRegister rule run stores
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

use OCA\OpenRegister\Db\RuleRunMapperInterface;
use OCA\OpenRegister\Db\RuleRunSummaryMapperInterface;

/**
 * Where rule runs and their summaries are written.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleRunStores {
	/**
	 * Bundle the collaborators.
	 *
	 * @param RuleRunMapperInterface        $runs      The detail log.
	 * @param RuleRunSummaryMapperInterface $summaries Last run and last error per rule.
	 */
	public function __construct(
		public readonly RuleRunMapperInterface $runs,
		public readonly RuleRunSummaryMapperInterface $summaries,
	) {
	}//end __construct()
}//end class
