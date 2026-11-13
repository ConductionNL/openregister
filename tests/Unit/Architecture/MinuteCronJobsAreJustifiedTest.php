<?php

/**
 * A background job that runs every minute says why it must.
 *
 * Every minute-level job adds to the work an idle instance does. That work is
 * the floor that every write measurement has to subtract
 * (object-write-at-instance-floor, task 13). So a job may run at 60 seconds or
 * less only when its contract needs that drift bound. The reason is written
 * here, next to the job's name.
 *
 * The test reads each `setInterval(seconds: ...)` call in lib/. It resolves a
 * literal, simple arithmetic or a `self::CONSTANT` to a number of seconds. It
 * fails in three cases:
 * - a job resolves to 60 seconds or less and has no recorded reason;
 * - a recorded job no longer runs that often (a stale reason);
 * - an interval this test cannot resolve is not in the admin-configured list,
 *   whose defaults are checked here to be over a minute.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/object-write-at-instance-floor/tasks.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class MinuteCronJobsAreJustifiedTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Jobs allowed to run at 60 seconds or less, with the drift bound that requires it.
	 *
	 * @var array<string, string>
	 */
	private const MINUTE_JOBS = [
		'FlowRunWorker' => 'Triggers only record intent; a triggered flow waits for this drain, so a wider tick is added latency on every flow run.',
		'NotificationQueueFlushJob' => 'Releases quiet-hours and digest-held notifications at their due minute; a wider tick delays every held notification past its stated time.',
		'PresenceExpiryJob' => 'Must sweep faster than the 90 s presence window it enforces, or a closed tab stays on other readers\' lists for a whole extra window.',
		'ScheduledNotificationJob' => 'Scheduled notifications declare trigger.intervalSec down to 60 s; the tick is the floor that makes the shortest declared interval honest.',
	];

	/**
	 * Jobs whose interval comes from configuration, with the default constant that must exceed a minute.
	 *
	 * @var array<string, string>
	 */
	private const CONFIGURED_JOBS = [
		'DestructionCheckJob' => 'DEFAULT_INTERVAL',
		'TemporalCalculationSweepJob' => 'DEFAULT_INTERVAL_SECONDS',
		'TransferCheckJob' => 'DEFAULT_INTERVAL',
		'ReviewReminderJob' => 'DEFAULT_INTERVAL',
	];

	/**
	 * The resolved interval of every job that sets one, by class basename.
	 *
	 * @return array<string, int|null> Seconds, or null when not statically resolvable.
	 */
	private function intervals(): array {
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (preg_match_all('/setInterval\(\s*seconds:\s*([^;]+?)\);/', $source, $calls) === 0) {
				continue;
			}

			$name = $file->getBasename('.php');
			foreach ($calls[1] as $expression) {
				$seconds = $this->resolve(expression: trim($expression), source: $source);
				// A job with several calls (a default and a configured one) counts by its smallest static value.
				if (array_key_exists($name, $found) === false || ($seconds !== null && ($found[$name] === null || $seconds < $found[$name]))) {
					$found[$name] = $seconds;
				}
			}
		}

		ksort($found);
		return $found;
	}//end intervals()

	/**
	 * Seconds for a literal, integer arithmetic or a `self::CONSTANT` expression.
	 *
	 * @param string $expression The setInterval argument.
	 * @param string $source     The file it appears in.
	 *
	 * @return int|null The seconds, or null when the value is only known at run time.
	 */
	private function resolve(string $expression, string $source): ?int {
		if (preg_match('/^self::([A-Z_]+)$/', $expression, $constant) === 1) {
			if (preg_match('/const\s+(?:int\s+)?' . $constant[1] . '\s*=\s*([^;]+);/', $source, $value) !== 1) {
				return null;
			}

			$expression = trim($value[1]);
		}

		// Only products of integer literals occur (`86400 * 365`, `(6 * 3600)`).
		$factors = explode('*', str_replace(['(', ')', ' '], '', $expression));
		$seconds = 1;
		foreach ($factors as $factor) {
			if (ctype_digit($factor) === false) {
				return null;
			}

			$seconds *= (int)$factor;
		}

		return $seconds;
	}//end resolve()

	/**
	 * The default constant of a configured job, resolved from its class file.
	 *
	 * @param string $job      The job basename.
	 * @param string $constant The constant name.
	 *
	 * @return int|null The default in seconds.
	 */
	private function configuredDefault(string $job, string $constant): ?int {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			if ($file->getBasename() === $job . '.php') {
				return $this->resolve(expression: 'self::' . $constant, source: (string)file_get_contents($file->getPathname()));
			}
		}

		return null;
	}//end configuredDefault()

	/**
	 * The scan finds the jobs it is meant to police.
	 *
	 * @return void
	 */
	public function testTheScanSeesTheJobs(): void {
		$intervals = $this->intervals();
		$this->assertGreaterThan(30, count($intervals));
		$this->assertSame(86400, $intervals['DraftExpiryJob'] ?? null);
		$this->assertSame(300, $intervals['BlobMigrationJob'] ?? null);
	}//end testTheScanSeesTheJobs()

	/**
	 * Every job at 60 seconds or less has a recorded reason.
	 *
	 * @return void
	 */
	public function testEveryMinuteJobIsJustified(): void {
		$unjustified = [];
		foreach ($this->intervals() as $job => $seconds) {
			if ($seconds !== null && $seconds <= 60 && array_key_exists($job, self::MINUTE_JOBS) === false) {
				$unjustified[] = $job . ' (' . $seconds . ' s)';
			}
		}

		$this->assertSame([], $unjustified, 'Run these less often, or record in MINUTE_JOBS the drift bound that needs a minute.');
	}//end testEveryMinuteJobIsJustified()

	/**
	 * Every recorded reason belongs to a job that still runs that often.
	 *
	 * @return void
	 */
	public function testNoReasonIsStale(): void {
		$intervals = $this->intervals();
		foreach (array_keys(self::MINUTE_JOBS) as $job) {
			$this->assertArrayHasKey($job, $intervals, $job . ' no longer sets an interval; drop its reason.');
			$this->assertNotNull($intervals[$job]);
			$this->assertLessThanOrEqual(60, $intervals[$job], $job . ' now runs less often; drop its reason.');
		}
	}//end testNoReasonIsStale()

	/**
	 * A run-time interval belongs to a known configured job whose default exceeds a minute.
	 *
	 * @return void
	 */
	public function testConfiguredIntervalsDefaultAboveAMinute(): void {
		foreach ($this->intervals() as $job => $seconds) {
			if ($seconds === null) {
				$this->assertArrayHasKey($job, self::CONFIGURED_JOBS, $job . ' sets an interval this test cannot read; add it to CONFIGURED_JOBS with its default.');
			}
		}

		foreach (self::CONFIGURED_JOBS as $job => $constant) {
			$default = $this->configuredDefault(job: $job, constant: $constant);
			$this->assertNotNull($default, $job . '::' . $constant . ' was not found.');
			$this->assertGreaterThan(60, $default, $job . ' defaults to a minute or less.');
		}
	}//end testConfiguredIntervalsDefaultAboveAMinute()
}//end class
