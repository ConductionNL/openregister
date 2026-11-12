<?php

/**
 * SyncScheduleInterface: what callers outside the Service component ask of SyncScheduleService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
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

namespace OCA\OpenRegister\Service\Sync;

use DateTimeInterface;
use OCA\OpenRegister\Db\Source;

/**
 * Selecting the sources due for a sync, as the sync job does. Only the method that caller calls.
 */
interface SyncScheduleInterface {

	/**
	 * Select the subset of sources that are due for sync at $now.
	 *
	 * @param array<Source> $sources The candidate sources
	 * @param DateTimeInterface $now The reference moment
	 *
	 * @return list<Source> The sources that are due
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function selectDueSources(array $sources, DateTimeInterface $now): array;
}//end interface
