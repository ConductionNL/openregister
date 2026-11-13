<?php

/**
 * OpenRegister Scheduled Report Run
 *
 * State of one run of a scheduled report while it is delivered.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCP\Files\Node;
use OCP\IUser;

/**
 * One run of a scheduled report: the report, its owner, the rendered export and its
 * filename, and what the Files and email legs did with it.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final class ScheduledReportRun {

	/**
	 * The delivery filename.
	 *
	 * @var string
	 */
	public string $filename = '';

	/**
	 * The rendered export.
	 *
	 * @var array{bytes: string, rowCount: int}
	 */
	public array $export = ['bytes' => '', 'rowCount' => 0];

	/**
	 * Whether the Files leg delivered.
	 *
	 * @var boolean
	 */
	public bool $delivered = false;

	/**
	 * The file the Files leg wrote, null when nothing was written.
	 *
	 * @var Node|null
	 */
	public ?Node $file = null;

	/**
	 * Why the email leg failed; null when it succeeded or was not requested.
	 *
	 * @var string|null
	 */
	public ?string $emailFailureReason = null;

	/**
	 * What the recipient expansion left out (members without an address, overflow), for the run record.
	 *
	 * @var array<int, string>
	 */
	public array $recipientNotes = [];

	/**
	 * Constructor.
	 *
	 * @param ScheduledReport $report The report being run.
	 * @param IUser           $owner  The owner the run is made for.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function __construct(
		public readonly ScheduledReport $report,
		public readonly IUser $owner,
	) {
	}//end __construct()

	/**
	 * The rendered bytes.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function bytes(): string {
		return $this->export['bytes'];
	}//end bytes()
}//end class
