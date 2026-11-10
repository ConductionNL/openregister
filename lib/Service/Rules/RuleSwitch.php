<?php

/**
 * OpenRegister rule switch
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

use OCA\OpenRegister\Db\Schema;

/**
 * Switch one declared rule of a schema off or on.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleSwitch {
	/**
	 * Name the switch.
	 *
	 * @param Schema $schema  The schema the rule is declared on.
	 * @param string $ruleId  The derived rule id.
	 * @param bool   $enabled What to switch it to.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $ruleId,
		public readonly bool $enabled,
	) {
	}//end __construct()
}//end class
