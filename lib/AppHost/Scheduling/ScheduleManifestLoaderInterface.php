<?php

/**
 * ScheduleManifestLoaderInterface: what callers outside the AppHost component use of ScheduleManifestLoader.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Scheduling
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

namespace OCA\OpenRegister\AppHost\Scheduling;

/**
 * Reads the `schedules[]` manifests installed apps declare.
 */
interface ScheduleManifestLoaderInterface {

	/**
	 * Enumerate all installed on-disk apps that declare a `schedules[]` block.
	 *
	 * @return ScheduleManifest[] One entry per installed app with schedules declared.
	 */
	public function loadAllOnDisk(): array;
}//end interface
