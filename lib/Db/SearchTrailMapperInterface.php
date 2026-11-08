<?php

/**
 * SearchTrailMapperInterface: the part of SearchTrailMapper that the search trail controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The methods of SearchTrailMapper that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SearchTrailMapperInterface {

	/**
	 * Clear all search trail logs, expired or not.
	 *
	 * The admin clear-all endpoint deletes every row; {@see clearLogs()} only
	 * removes the expired ones.
	 *
	 * @return bool True if any logs were deleted, false otherwise
	 *
	 * @throws \Exception Database operation exceptions
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function clearAllLogs(): bool;
}//end interface
