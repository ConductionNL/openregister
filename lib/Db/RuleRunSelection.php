<?php

/**
 * RuleRunSelection: which rule's runs a run-log read is about, and how they are narrowed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * One rule and the verdict/window filter its listing and its count share.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleRunSelection {

	/**
	 * Constructor.
	 *
	 * @param string        $ruleId The derived rule id.
	 * @param RuleRunFilter $filter The verdict and created window.
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly RuleRunFilter $filter=new RuleRunFilter(),
	) {
	}//end __construct()
}//end class
