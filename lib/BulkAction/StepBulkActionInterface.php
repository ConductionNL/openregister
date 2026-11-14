<?php

/**
 * OpenRegister StepBulkActionInterface
 *
 * A bulk action that takes one BulkStep.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
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

namespace OCA\OpenRegister\BulkAction;

/**
 * A bulk action in the parameter-object form.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
interface StepBulkActionInterface extends BulkActionInterface {
	/**
	 * What the action would do, or does, to one object.
	 *
	 * The preview and the commit call this same method, differing only in
	 * the step's `commit`. A second implementation for the rehearsal would be
	 * a promise rather than a rehearsal (D-1).
	 *
	 * @param BulkStep $step The object, the job's parameters, rehearsal or commit, and the actor.
	 *
	 * @return BulkActionResult What happened, or what would happen.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function apply(BulkStep $step): BulkActionResult;
}//end interface
