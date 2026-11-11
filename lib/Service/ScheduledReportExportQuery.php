<?php

/**
 * OpenRegister Scheduled Report Export Query
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * What a scheduled report exports and as whom: the register, the optional
 * schema, the saved filters, and the owner whose access shapes the file.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final class ScheduledReportExportQuery {
	/**
	 * Build the query.
	 *
	 * @param Register            $register The register.
	 * @param Schema|null         $schema   The schema, if any.
	 * @param array<string,mixed> $filters  The filter map.
	 * @param IUser               $owner    The impersonated owner.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly ?Schema $schema,
		public readonly array $filters,
		public readonly IUser $owner,
	) {
	}//end __construct()
}//end class
