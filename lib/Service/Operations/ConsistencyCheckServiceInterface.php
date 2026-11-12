<?php

/**
 * ConsistencyCheckServiceInterface: what callers outside the Service component ask of ConsistencyCheckService.
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

/**
 * The read-only consistency check, as the operations controller and the
 * consistency-check job run it. Only the method those callers call.
 */
interface ConsistencyCheckServiceInterface {

	/**
	 * Run every consistency probe without writing.
	 *
	 * @return array<string, mixed> The findings, and what was checked.
	 */
	public function check(): array;
}//end interface
