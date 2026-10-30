<?php

/**
 * OpenRegister Example Set Daily Refresh
 *
 * The sets an administrator marks for a daily refresh, and the run that
 * refreshes them. A set's dates resolve against the day it is installed or
 * refreshed, so a demo instance's "today" (notes recorded today, inspections
 * due today, a term that ends today) is only true on that day. Refreshing the
 * marked sets once a day moves all of it to the day it is viewed. Opt-in per
 * set: an instance with real data marks nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTimeImmutable;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\IAppConfig;
use Throwable;

/**
 * Marks sets for a daily refresh and refreshes them.
 *
 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
 */
class ExampleSetDailyRefresh {
	/**
	 * The app config key holding the marked set ids, as a JSON list.
	 */
	public const KEY = 'example_set_daily_refresh';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig          $appConfig Holds the marks.
	 * @param ExampleSetCatalogue $catalogue Gathers a set.
	 * @param ExampleSetInstaller $installer Refreshes it.
	 * @param ExampleSetRecord    $record    Says whether a set is installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ExampleSetCatalogue $catalogue,
		private readonly ExampleSetInstaller $installer,
		private readonly ExampleSetRecord $record,
	) {
	}//end __construct()

	/**
	 * The marked sets.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function sets(): array {
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::KEY, '[]'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_unique(array_filter($decoded, 'is_string')));
	}//end sets()

	/**
	 * Mark or unmark a set for the daily refresh.
	 *
	 * @param string $set   The set id.
	 * @param bool   $daily True to refresh it daily, false to stop.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the one fact the call sets.
	 *
	 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function mark(string $set, bool $daily): void {
		$sets = array_values(array_diff($this->sets(), [$set]));
		if ($daily === true) {
			$sets[] = $set;
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::KEY, (string) json_encode($sets));
	}//end mark()

	/**
	 * Refresh every marked set that is installed, against the day given (or today in the set's time zone).
	 *
	 * @param DateTimeImmutable|null $today The day to refresh against; null for today.
	 *
	 * @return array<string, array<string, mixed>> Per set: `ok`, and `skipped` or the refusals, dropped, missing and lost.
	 *
	 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function run(?DateTimeImmutable $today=null): array {
		$report = [];
		foreach ($this->sets() as $set) {
			if ($this->record->isEmpty(record: $this->record->read(set: $set)) === true) {
				$report[$set] = ['ok' => false, 'skipped' => 'not installed'];
				continue;
			}

			try {
				$plan   = ($this->catalogue->gather(set: $set) + ['refresh' => true]);
				$result = $this->installer->install(plan: $plan, today: $today);
			} catch (Throwable $e) {
				$report[$set] = ['ok' => false, 'skipped' => $e->getMessage()];
				continue;
			}

			$report[$set] = [
				'ok'       => $result['ok'],
				'refusals' => $result['refusals'],
				'dropped'  => $result['dropped'],
				'missing'  => $result['missing'],
				'lost'     => $result['lost'],
			];
		}//end foreach

		return $report;
	}//end run()
}//end class
