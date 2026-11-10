<?php

/**
 * The caller a scheduled report is accessed as.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The caller a scheduled report is read, changed or deleted as: their uid and whether they are an administrator.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final class ReportCaller {
	/**
	 * Hold the values.
	 *
	 * @param string $uid     The calling user's uid.
	 * @param bool   $isAdmin Whether the caller is a Nextcloud administrator.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function __construct(
		public readonly string $uid,
		public readonly bool $isAdmin,
	) {
	}//end __construct()
}//end class
