<?php

/**
 * OpenRegister Scheduled Report Notice
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

/**
 * A notification a scheduled report sends its owner: the subject the
 * Notifier renders, and the parameters that subject is rendered with.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final class ScheduledReportNotice {
	/**
	 * Build the notice.
	 *
	 * @param string              $subject    The notification subject.
	 * @param array<string,mixed> $parameters The subject parameters.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function __construct(
		public readonly string $subject,
		public readonly array $parameters,
	) {
	}//end __construct()
}//end class
