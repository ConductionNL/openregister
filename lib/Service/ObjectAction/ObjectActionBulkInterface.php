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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\BulkJobRefusedException;

/**
 * Starting an action over a selection as a bulk job, as the object-actions
 * controller uses it. Only the method that caller calls.
 */
interface ObjectActionBulkInterface {

	/**
	 * Preview a bulk job running an action over a selection.
	 *
	 * @param Schema               $schema    The selection's schema.
	 * @param string               $register  The register as the caller named it.
	 * @param string               $schemaRef The schema as the caller named it.
	 * @param string               $action    The action key.
	 * @param list<string>         $ids       The selection.
	 * @param array<string, mixed> $inputs    The inputs.
	 *
	 * @return BulkJob The previewed job.
	 *
	 * @throws BulkJobRefusedException When the ceiling or a guard refuses the selection.
	 * @throws InvalidArgumentException When nobody is signed in to run it as.
	 */
	public function start(
		Schema $schema,
		string $register,
		string $schemaRef,
		string $action,
		array $ids,
		array $inputs,
	): BulkJob;
}//end interface
