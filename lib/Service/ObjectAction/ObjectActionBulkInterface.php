<?php

/**
 * ObjectActionBulkInterface: what callers outside the Service component ask of ObjectActionBulk.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
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

namespace OCA\OpenRegister\Service\ObjectAction;

use InvalidArgumentException;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Exception\BulkJobRefusedException;

/**
 * Starting an action over a selection as a bulk job, as the object-actions
 * controller uses it. Only the method that caller calls.
 */
interface ObjectActionBulkInterface {

	/**
	 * Preview a bulk job running an action over a selection.
	 *
	 * @param ObjectActionSelection $selection The selection, its schema, and the register and schema as named.
	 * @param ObjectActionRequest   $request   The action key and the inputs.
	 *
	 * @return BulkJob The previewed job.
	 *
	 * @throws BulkJobRefusedException When the ceiling or a guard refuses the selection.
	 * @throws InvalidArgumentException When nobody is signed in to run it as.
	 */
	public function start(ObjectActionSelection $selection, ObjectActionRequest $request): BulkJob;
}//end interface
