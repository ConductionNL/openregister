<?php

/**
 * ProfileExportRun
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\ExportProfile;

/**
 * One run of a saved export profile: the profile and the principal it runs as.
 *
 * Built by ExportProfileService::run and shared by its permission check and
 * its audit records; it never leaves that class.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ProfileExportRun {

	/**
	 * Name the run.
	 *
	 * @param ExportProfile $profile  The profile being run.
	 * @param string        $actorUid The principal the export runs as.
	 */
	public function __construct(
		public readonly ExportProfile $profile,
		public readonly string $actorUid,
	) {
	}//end __construct()
}//end class
