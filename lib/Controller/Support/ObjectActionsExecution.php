<?php

/**
 * OpenRegister object actions domain
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

use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulkInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistoryInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunnerInterface;

/**
 * The domain services of the object actions API.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
 */
final class ObjectActionsExecution {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectActionRunnerInterface  $runner  Runs one action on one object.
	 * @param ObjectActionHistoryInterface $history Reads the runs recorded on an object.
	 * @param ObjectActionBulkInterface    $bulk    Hands a large selection to a bulk job.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
	 */
	public function __construct(
		public readonly ObjectActionRunnerInterface $runner,
		public readonly ObjectActionHistoryInterface $history,
		public readonly ObjectActionBulkInterface $bulk,
	) {
	}//end __construct()
}//end class
