<?php

/**
 * IndexReconciliationReport: the last run of the search index probes, kept with its run time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use DateTimeInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\IAppConfig;

/**
 * Runs the five index probes and stores what they found (REQ-SQF-006).
 *
 * The report keeps each probe's count, at most {@see self::SAMPLE_SIZE} sample
 * rows, and whether it could run, under one app config key with the moment it
 * ran, so the operations console can show the last run without running the
 * probes on every page load.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
class IndexReconciliationReport {

	/**
	 * The app config key the report is stored under.
	 */
	public const CONFIG_KEY = 'index_reconciliation_report';

	/**
	 * How many sample rows a probe keeps in the stored report.
	 */
	public const SAMPLE_SIZE = 20;

	/**
	 * The prefix of the index probes' slugs.
	 */
	private const SLUG_PREFIX = 'index-';

	/**
	 * Constructor.
	 *
	 * @param ConsistencyCheckService $check     The read-only check holding the probes.
	 * @param IAppConfig              $appConfig Where the report is stored.
	 */
	public function __construct(
		private readonly ConsistencyCheckService $check,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Run the index probes and store the report.
	 *
	 * @param DateTimeInterface $ranAt When the run happened.
	 *
	 * @return array{ranAt: string, findings: list<array<string, mixed>>} The stored report.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function run(DateTimeInterface $ranAt): array {
		$findings = [];
		foreach ($this->check->slugs() as $slug) {
			if (str_starts_with($slug, self::SLUG_PREFIX) === false) {
				continue;
			}

			$finding = $this->check->checkOne(slug: $slug);
			if ($finding !== null) {
				$findings[] = self::sampled(finding: $finding);
			}
		}

		$report = ['ranAt' => $ranAt->format(DateTimeInterface::ATOM), 'findings' => $findings];
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string) json_encode($report));

		return $report;
	}//end run()

	/**
	 * The last stored report, or null when none ran yet.
	 *
	 * @return array{ranAt: string, findings: list<array<string, mixed>>}|null The report.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function latest(): ?array {
		$stored = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($stored) === false || is_string($stored['ranAt'] ?? null) === false || is_array($stored['findings'] ?? null) === false) {
			return null;
		}

		return ['ranAt' => $stored['ranAt'], 'findings' => array_values($stored['findings'])];
	}//end latest()

	/**
	 * A finding with its rows cut to the sample size.
	 *
	 * @param array<string, mixed> $finding The finding.
	 *
	 * @return array<string, mixed> The finding as stored.
	 */
	private static function sampled(array $finding): array {
		return [
			'slug' => $finding['slug'],
			'title' => $finding['title'],
			'count' => (int) $finding['count'],
			'samples' => array_slice(($finding['objects'] ?? []), 0, self::SAMPLE_SIZE),
			'unavailable' => ($finding['unavailable'] ?? null),
		];
	}//end sampled()
}//end class
