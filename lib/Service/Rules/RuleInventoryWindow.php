<?php

/**
 * OpenRegister RuleInventoryWindow
 *
 * Merges a rule's run summary onto its inventory entry, measured against the idle window.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTime;
use OCA\OpenRegister\Db\RuleRunSummary;

/**
 * One inventory read's idle window: how long a rule may go unevaluated before it is flagged.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleInventoryWindow {

	/**
	 * What an entry says before any summary is merged on.
	 *
	 * A rule that has never been evaluated has not run inside the window
	 * either. Saying "unknown" here would hide exactly the rule an
	 * administrator most wants to see: one that was written, saved and has
	 * never once been reached.
	 *
	 * @var array<string, null|false>
	 */
	private const NEVER_RUN = [
		'lastRun' => null,
		'lastVerdict' => null,
		'lastError' => null,
		'lastErrorAt' => null,
		'ranInsideWindow' => false,
	];

	/**
	 * The window's length in days, always at least one.
	 *
	 * @var integer
	 */
	private readonly int $days;

	/**
	 * The moment a run must be at or after to count as inside the window.
	 *
	 * @var DateTime
	 */
	private readonly DateTime $cutoff;

	/**
	 * Constructor.
	 *
	 * @param int      $idleDays How long a rule may go unevaluated before it is flagged.
	 * @param DateTime $now      The moment to measure the idle window from; it is not modified.
	 *
	 * @return void
	 */
	public function __construct(int $idleDays, DateTime $now) {
		$this->days = max(1, $idleDays);
		$this->cutoff = (clone $now)->modify('-' . $this->days . ' days');
	}//end __construct()

	/**
	 * The inventory row for one rule: its descriptor with its summary merged on.
	 *
	 * @param RuleDescriptor      $descriptor The rule.
	 * @param RuleRunSummary|null $summary    Its last run and last error, or null when it has never run.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function rowOf(RuleDescriptor $descriptor, ?RuleRunSummary $summary): array {
		$row = array_merge($descriptor->jsonSerialize(), self::NEVER_RUN);
		if ($summary !== null) {
			$row = array_merge($row, $summary->jsonSerialize());
			$row['id'] = $descriptor->getId();
			$row['schema'] = $descriptor->getSchemaSlug();
			$lastRun = $summary->getLastRun();
			$row['ranInsideWindow'] = ($lastRun !== null && $lastRun >= $this->cutoff);
		}

		$row['idleWindowDays'] = $this->days;

		return $row;
	}//end rowOf()
}//end class
