<?php

/**
 * OpenRegister Scheduled report delivery
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

use OCP\IConfig;
use OCP\Mail\IMailer;

/**
 * How a finished report reaches people: through the owner (Files and
 * notification) and by email.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final class ScheduledReportDelivery {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ScheduledReportOwnerServices $owner  Owner-facing services (Files, notifications, impersonation).
	 * @param IMailer                      $mailer Sends the email-delivery leg (deliveryMode email|both).
	 * @param IConfig                      $config Resolves the instance's default mail sender.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function __construct(
		public readonly ScheduledReportOwnerServices $owner,
		public readonly IMailer $mailer,
		public readonly IConfig $config,
	) {
	}//end __construct()
}//end class
