<?php

/**
 * OpenRegister AppHost — Schedule Manifest
 *
 * Parses and validates the top-level `schedules[]` array of a host app's
 * manifest into {@see ScheduleDescriptor} value objects, mirroring the
 * observability engine's manifest → descriptor pattern. Invalid entries never
 * throw out of {@see fromManifest}: they are collected into `$diagnostics` and
 * dropped, so one malformed schedule can never stop the others applying.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

/**
 * Validated set of schedules declared by a single application's manifest.
 *
 * @spec openspec/specs/apphost-scheduling/spec.md
 */
final class ScheduleManifest {
	/**
	 * Constructor.
	 *
	 * @param string $applicationId The owning application identifier (app id or OR object uuid).
	 * @param ScheduleDescriptor[] $schedules Parsed, structurally-valid schedules.
	 * @param string[] $diagnostics Rejected-entry diagnostics (never thrown).
	 */
	public function __construct(
		public readonly string $applicationId,
		public readonly array $schedules,
		public readonly array $diagnostics = [],
	) {
	}//end __construct()

	/**
	 * Build a schedule manifest from a decoded application manifest.
	 *
	 * Each `schedules[]` entry is validated structurally by
	 * {@see ScheduleDescriptor::fromArray}; a `cron` entry is additionally
	 * checked for parseability via the evaluator when one is supplied. Rejected
	 * entries are collected into diagnostics and omitted from `$schedules`.
	 *
	 * @param string $applicationId The owning application identifier.
	 * @param array<string, mixed> $manifest The decoded manifest.
	 * @param CronScheduleEvaluator|null $cron Cron evaluator (rejects unparseable cron when supplied).
	 *
	 * @return self
	 *
	 * @spec openspec/specs/apphost-scheduling/spec.md
	 */
	public static function fromManifest(string $applicationId, array $manifest, ?CronScheduleEvaluator $cron = null): self {
		$parsed = self::parse(raw: $manifest['schedules'] ?? null, cron: $cron);
		return new self(applicationId: $applicationId, schedules: $parsed['schedules'], diagnostics: $parsed['diagnostics']);
	}//end fromManifest()

	/**
	 * Split a `schedules[]` block into accepted schedules and diagnostics.
	 *
	 * @param mixed                      $raw  The block; anything but an array holds no schedules.
	 * @param CronScheduleEvaluator|null $cron Cron evaluator (rejects unparseable cron when supplied).
	 *
	 * @return array{schedules: list<ScheduleDescriptor>, diagnostics: list<string>}
	 */
	private static function parse(mixed $raw, ?CronScheduleEvaluator $cron): array {
		$parsed = ['schedules' => [], 'diagnostics' => []];
		if (is_array($raw) === false) {
			return $parsed;
		}

		foreach ($raw as $index => $entry) {
			$result = self::checkCron(result: self::descriptor(entry: $entry, index: (int)$index), cron: $cron);
			if (is_string($result) === true) {
				$parsed['diagnostics'][] = $result;
				continue;
			}

			$parsed['schedules'][] = $result;
		}

		return $parsed;
	}//end parse()

	/**
	 * The descriptor of one entry, or the diagnostic that rejects it.
	 *
	 * @param mixed $entry The entry.
	 * @param int   $index Its position in the block.
	 *
	 * @return ScheduleDescriptor|string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function descriptor(mixed $entry, int $index): ScheduleDescriptor|string {
		if (is_array($entry) === false) {
			return sprintf('Schedule #%d is not an object.', $index);
		}

		try {
			return ScheduleDescriptor::fromArray(raw: $entry, index: $index);
		} catch (ScheduleValidationException $e) {
			return $e->getMessage();
		}
	}//end descriptor()

	/**
	 * Reject a cron descriptor whose expression the evaluator cannot parse.
	 *
	 * @param ScheduleDescriptor|string  $result A descriptor, or a diagnostic that passes through.
	 * @param CronScheduleEvaluator|null $cron   Cron evaluator; without one nothing is rejected.
	 *
	 * @return ScheduleDescriptor|string
	 */
	private static function checkCron(ScheduleDescriptor|string $result, ?CronScheduleEvaluator $cron): ScheduleDescriptor|string {
		if (is_string($result) === true || $cron === null || $result->isCron() === false) {
			return $result;
		}

		if ($cron->isValid((string)$result->cron) === true) {
			return $result;
		}

		return sprintf('Schedule "%s" has an unparseable cron expression.', $result->id);
	}//end checkCron()
}//end class
