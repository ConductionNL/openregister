<?php

/**
 * OpenRegister BulkEditActions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\BulkAction\ApplyRuleActionInterface;
use OCA\OpenRegister\BulkAction\AssignActionInterface;
use OCA\OpenRegister\BulkAction\SetPropertiesActionInterface;

/**
 * The built-in bulk actions that edit the selected objects.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final class BulkEditActions {
	/**
	 * Bundle the actions.
	 *
	 * @param SetPropertiesActionInterface $setProperties The bulk attribute write.
	 * @param AssignActionInterface        $assign        The bulk redistribution.
	 * @param ApplyRuleActionInterface     $applyRule     The rule replay.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function __construct(
		public readonly SetPropertiesActionInterface $setProperties,
		public readonly AssignActionInterface $assign,
		public readonly ApplyRuleActionInterface $applyRule,
	) {
	}//end __construct()
}//end class
