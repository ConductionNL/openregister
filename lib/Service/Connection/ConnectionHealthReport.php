<?php

/**
 * OpenRegister connection health report.
 *
 * What one connection report run produced: how many rows need attention, the
 * mail subject and body lines, and the CSV attachment with the same rows.
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
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-the-report-says-what-needs-attention-and-what-each-source-delivered
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Connection;

/**
 * The built connection report.
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-the-report-says-what-needs-attention-and-what-each-source-delivered
 */
final class ConnectionHealthReport {
	/**
	 * Constructor.
	 *
	 * @param int                $attention How many rows need attention.
	 * @param string             $subject   The mail subject.
	 * @param array<int, string> $lines     The mail body lines.
	 * @param string             $csv       The CSV attachment, UTF-8 with byte order mark.
	 * @param int                $rowCount  The CSV data rows.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-the-report-says-what-needs-attention-and-what-each-source-delivered
	 */
	public function __construct(
		public readonly int $attention,
		public readonly string $subject,
		public readonly array $lines,
		public readonly string $csv,
		public readonly int $rowCount,
	) {
	}//end __construct()
}//end class
