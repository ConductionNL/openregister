<?php

/**
 * OpenRegister BulkSetActions
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

use OCA\OpenRegister\BulkAction\ExportWholeSetAction;
use OCA\OpenRegister\BulkAction\RestorePriorValuesActionInterface;
use OCA\OpenRegister\BulkAction\RunActionActionInterface;

/**
 * The built-in bulk actions that work on the selection as a set: extract, restore, run.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final class BulkSetActions {
	/**
	 * Bundle the actions.
	 *
	 * @param ExportWholeSetAction              $exportWholeSet The whole-set extract.
	 * @param RestorePriorValuesActionInterface $restore        The inverse of a reversible job.
	 * @param RunActionActionInterface          $runAction      A declared object action on a large selection.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function __construct(
		public readonly ExportWholeSetAction $exportWholeSet,
		public readonly RestorePriorValuesActionInterface $restore,
		public readonly RunActionActionInterface $runAction,
	) {
	}//end __construct()
}//end class
