<?php

/**
 * OpenRegister connection report kind.
 *
 * The `connection-health` kind of a scheduled report: its options, and its run.
 * A run builds the report as whoever is in the session, which during a
 * scheduled run is the report's owner.
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

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Options and run of the connection-health report kind.
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
 */
class ConnectionReportKind {

	/**
	 * The kind of an object export, the default.
	 */
	public const EXPORT = 'export';

	/**
	 * The kind of the daily connection report.
	 */
	public const CONNECTION_HEALTH = 'connection-health';

	/**
	 * The kinds a scheduled report may have.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = [self::EXPORT, self::CONNECTION_HEALTH];

	/**
	 * The lookback when none is given, in hours.
	 */
	public const DEFAULT_LOOKBACK_HOURS = 24;

	/**
	 * The run record of a quiet day under onlyWhenAttention.
	 */
	public const QUIET_DAY = 'Nothing needed attention; no mail sent';

	/**
	 * Constructor.
	 *
	 * @param ConnectionHealthReportBuilder $builder Builds the report.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
	 */
	public function __construct(
		private readonly ConnectionHealthReportBuilder $builder,
	) {
	}//end __construct()

	/**
	 * Validate and normalise the options of a connection-health report.
	 *
	 * @param mixed $options The submitted options.
	 *
	 * @return array{onlyWhenAttention: bool, lookbackHours: int} The options.
	 *
	 * @throws InvalidArgumentException When they are not valid.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
	 */
	public static function options(mixed $options): array {
		if ($options === null) {
			$options = [];
		}

		if (is_array($options) === false) {
			throw new InvalidArgumentException('options must be an object.');
		}

		$quietDays = ($options['onlyWhenAttention'] ?? false);
		if (is_bool($quietDays) === false) {
			throw new InvalidArgumentException('options.onlyWhenAttention must be true or false.');
		}

		$lookback = ($options['lookbackHours'] ?? self::DEFAULT_LOOKBACK_HOURS);
		if (is_int($lookback) === false || $lookback < 1 || $lookback > 168) {
			throw new InvalidArgumentException('options.lookbackHours must be a whole number from 1 to 168.');
		}

		return ['onlyWhenAttention' => $quietDays, 'lookbackHours' => $lookback];
	}//end options()

	/**
	 * Run the report: the CSV with its row count, and what the mail says.
	 *
	 * @param array<string, mixed> $options The stored options.
	 * @param DateTimeImmutable    $now     The end of the lookback.
	 *
	 * @return array{bytes: string, rowCount: int, attention: int, subject: string, lines: array<int, string>, quiet: bool} The run.
	 *
	 * @throws ConnectionRegistryMissingException When register `integriq` does not exist.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
	 */
	public function run(array $options, DateTimeImmutable $now): array {
		$options = self::options(options: $options);
		$report = $this->builder->build(lookbackHours: $options['lookbackHours'], now: $now);

		return [
			'bytes' => $report->csv,
			'rowCount' => $report->rowCount,
			'attention' => $report->attention,
			'subject' => $report->subject,
			'lines' => $report->lines,
			'quiet' => ($options['onlyWhenAttention'] === true && $report->attention === 0),
		];
	}//end run()
}//end class
