<?php

/**
 * OpenRegister connection registry missing exception.
 *
 * Thrown when the connection report is asked to read integriq's register and it
 * is not there, so the run fails with a named reason instead of mailing an
 * empty "all clear".
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

use RuntimeException;

/**
 * The connection registry (register `integriq`) does not exist on this instance.
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
 */
class ConnectionRegistryMissingException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
	 */
	public function __construct() {
		parent::__construct(message: 'integriq is not installed, so there are no connection rows to report on');
	}//end __construct()
}//end class
