<?php

/**
 * OpenRegister AppHost — Schedule Manifest
 *
 * Parses and validates the top-level `schedules[]` array of a host app's
 * manifest into {@see ScheduleDescriptor} value objects, mirroring the
 * observability engine's manifest → descriptor pattern. Invalid entries never
 * throw out of {@see fromManifest}: they are collected into `$diagnostics` and
 * dropped, so one malformed schedule can never break the reconciler sweep.
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
 * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
 */
final class ScheduleManifest
{
    /**
     * Constructor.
     *
     * @param string               $applicationId The owning application identifier (app id or OR object uuid).
     * @param ScheduleDescriptor[] $schedules     Parsed, structurally-valid schedules.
     * @param string[]             $diagnostics   Rejected-entry diagnostics (never thrown).
     */
    public function __construct(
        public readonly string $applicationId,
        public readonly array $schedules,
        public readonly array $diagnostics=[]
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
     * @param string                     $applicationId The owning application identifier.
     * @param array<string, mixed>       $manifest      The decoded manifest.
     * @param CronScheduleEvaluator|null $cron          Cron evaluator (rejects unparseable cron when supplied).
     *
     * @return self
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public static function fromManifest(string $applicationId, array $manifest, ?CronScheduleEvaluator $cron=null): self
    {
        $raw = $manifest['schedules'] ?? null;
        if (is_array($raw) === false) {
            return new self(applicationId: $applicationId, schedules: [], diagnostics: []);
        }

        $schedules   = [];
        $diagnostics = [];

        foreach ($raw as $index => $entry) {
            if (is_array($entry) === false) {
                $diagnostics[] = sprintf('Schedule #%d is not an object.', (int) $index);
                continue;
            }

            try {
                $descriptor = ScheduleDescriptor::fromArray(raw: $entry, index: (int) $index);
            } catch (ScheduleValidationException $e) {
                $diagnostics[] = $e->getMessage();
                continue;
            }

            if ($descriptor->isCron() === true && $cron !== null && $cron->isValid((string) $descriptor->cron) === false) {
                $diagnostics[] = sprintf('Schedule "%s" has an unparseable cron expression.', $descriptor->id);
                continue;
            }

            $schedules[] = $descriptor;
        }//end foreach

        return new self(applicationId: $applicationId, schedules: $schedules, diagnostics: $diagnostics);
    }//end fromManifest()
}//end class
