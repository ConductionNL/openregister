<?php

/**
 * OpenRegister AppHost — Schedule Descriptor
 *
 * Validated value object for a single manifest `schedules[]` entry. A schedule
 * carries a stable `id`, exactly one of `interval` (positive integer seconds)
 * or `cron` (a cron expression), an `action` type (resolved server-side to a
 * vetted job class — never a manifest-supplied FQCN), an `arguments` object and
 * an `enabled` flag (default true). It carries NO execution identity — the
 * running user is resolved from the owning application, never the manifest.
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
 * A single validated schedule declaration.
 *
 * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
 */
final class ScheduleDescriptor
{
    /**
     * Constructor.
     *
     * @param string               $id              Stable schedule id (unique per application).
     * @param int|null             $intervalSeconds Positive interval in seconds, or null for cron schedules.
     * @param string|null          $cron            Cron expression, or null for interval schedules.
     * @param string               $action          Allow-listed action type (e.g. `openconnector:synchronization`).
     * @param array<string, mixed> $arguments       Arguments passed to the resolved action.
     * @param bool                 $enabled         Whether the schedule is active (default true).
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function __construct(
        public readonly string $id,
        public readonly ?int $intervalSeconds,
        public readonly ?string $cron,
        public readonly string $action,
        public readonly array $arguments=[],
        public readonly bool $enabled=true
    ) {
    }//end __construct()

    /**
     * Whether this is an interval-cadence schedule.
     *
     * @return bool
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function isInterval(): bool
    {
        return $this->intervalSeconds !== null;
    }//end isInterval()

    /**
     * Whether this is a cron-cadence schedule.
     *
     * @return bool
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function isCron(): bool
    {
        return $this->cron !== null;
    }//end isCron()

    /**
     * Build a descriptor from a raw manifest entry, validating its structure.
     *
     * Rejects (throws) an entry that lacks a non-empty `id` or `action`, or that
     * declares neither or both of `interval`/`cron`, or a non-positive
     * `interval`. Cron *parseability* is validated separately by
     * {@see ScheduleManifest} via the cron evaluator, so this method stays free
     * of the cron library.
     *
     * @param array<string, mixed> $raw   The raw schedule entry.
     * @param int                  $index Index of the entry within `schedules[]` (for diagnostics).
     *
     * @return self
     *
     * @throws ScheduleValidationException When the entry is structurally invalid.
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public static function fromArray(array $raw, int $index): self
    {
        $id = $raw['id'] ?? null;
        if (is_string($id) === false || trim($id) === '') {
            throw new ScheduleValidationException(sprintf('Schedule #%d is missing a non-empty "id".', $index));
        }

        $action = $raw['action'] ?? null;
        if (is_string($action) === false || trim($action) === '') {
            throw new ScheduleValidationException(sprintf('Schedule "%s" is missing a non-empty "action".', $id));
        }

        [$intervalSeconds, $cron] = self::parseCadence(raw: $raw, id: $id);

        $arguments = $raw['arguments'] ?? [];
        if (is_array($arguments) === false) {
            $arguments = [];
        }

        return new self(
            id: $id,
            intervalSeconds: $intervalSeconds,
            cron: $cron,
            action: $action,
            arguments: $arguments,
            enabled: (($raw['enabled'] ?? true) !== false)
        );
    }//end fromArray()

    /**
     * Parse and validate the cadence: exactly one of interval/cron.
     *
     * @param array<string, mixed> $raw The raw schedule entry.
     * @param string               $id  The schedule id (for messages).
     *
     * @return array{0: int|null, 1: string|null} Tuple of [intervalSeconds, cron].
     *
     * @throws ScheduleValidationException When neither/both are present or a value is invalid.
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    private static function parseCadence(array $raw, string $id): array
    {
        $hasInterval = array_key_exists('interval', $raw) === true && $raw['interval'] !== null;
        $hasCron     = array_key_exists('cron', $raw) === true && $raw['cron'] !== null;

        if ($hasInterval === $hasCron) {
            throw new ScheduleValidationException(
                sprintf('Schedule "%s" must declare exactly one of "interval" or "cron".', $id)
            );
        }

        if ($hasInterval === true) {
            $interval = $raw['interval'];
            if (is_int($interval) === false || $interval <= 0) {
                throw new ScheduleValidationException(
                    sprintf('Schedule "%s" has an invalid "interval" (must be a positive integer of seconds).', $id)
                );
            }

            return [$interval, null];
        }

        $cronRaw = $raw['cron'];
        if (is_string($cronRaw) === false || trim($cronRaw) === '') {
            throw new ScheduleValidationException(
                sprintf('Schedule "%s" has an empty "cron" expression.', $id)
            );
        }

        return [null, $cronRaw];
    }//end parseCadence()
}//end class
