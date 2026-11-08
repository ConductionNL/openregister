<?php

/**
 * GroupReconcilerInterface: what callers outside the Service component ask of GroupReconciler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
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

namespace OCA\OpenRegister\Service\Authorization;

/**
 * Running one group reconciliation sweep, as the reconciler job does. Only the method that caller calls.
 */
interface GroupReconcilerInterface {

	/**
	 * Run one reconciliation sweep.
	 *
	 * Never throws — this runs from a background job, where an exception would
	 * abort the whole tick and take unrelated work with it.
	 *
	 * @return array{declared: string[], created: string[]} What was declared and what was created.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function reconcile(): array;
}//end interface
