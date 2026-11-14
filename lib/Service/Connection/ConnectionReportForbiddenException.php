<?php

/**
 * OpenRegister connection report forbidden exception.
 *
 * A connection report reads integriq's administrator-only connection rows, so
 * only an administrator may create or change one. Not a RuntimeException: the
 * controller answers those as "not found", and this is a refusal.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Connection;

use Exception;

/**
 * A non-administrator tried to create or change a connection report.
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
 */
class ConnectionReportForbiddenException extends Exception {
	/**
	 * Constructor.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
	 */
	public function __construct() {
		parent::__construct(message: 'Only administrators can create or change a connection report.');
	}//end __construct()
}//end class
