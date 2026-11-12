<?php

/**
 * ConsistencyRepairServiceInterface: what callers outside the Service component ask of ConsistencyRepairService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
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

namespace OCA\OpenRegister\Service\Operations;

use OCA\OpenRegister\Exception\RepairRefusedException;

/**
 * Planning and applying a consistency repair, as the operations controller
 * uses it. Only the methods that caller calls.
 */
interface ConsistencyRepairServiceInterface {

	/**
	 * Apply the repair of one probe.
	 *
	 * @param string $slug  The probe slug.
	 * @param string $actor The uid authorising and performing it.
	 *
	 * @return array<string, mixed> What was changed.
	 *
	 * @throws RepairRefusedException When no such probe exists, or nobody is named.
	 */
	public function apply(string $slug, string $actor): array;

	/**
	 * What the repair of one probe would do.
	 *
	 * @param string $slug The probe slug.
	 *
	 * @return array<string, mixed> The plan: the action, the table and the rows.
	 *
	 * @throws RepairRefusedException When no such probe exists.
	 */
	public function plan(string $slug): array;
}//end interface
