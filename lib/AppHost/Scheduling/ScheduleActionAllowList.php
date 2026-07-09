<?php

/**
 * OpenRegister AppHost — Schedule Action Allow-List
 *
 * A CLOSED, server-controlled map from a manifest `action` type to the vetted
 * PHP `jobClass` that executes it. A raw FQCN supplied in manifest data is NEVER
 * used as a `jobClass` — that would be arbitrary-code execution as the app owner
 * (design D-4, ADR-005). The allow-list is seeded with exactly one entry,
 * `openconnector:synchronization`; more actions are additive server-owned data
 * later, with no schema change.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
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
 * Closed allow-list mapping action types to vetted job classes.
 *
 * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
 */
class ScheduleActionAllowList
{
    /**
     * The closed action-type → vetted `jobClass` map.
     *
     * Kept as plain strings so referencing this map never autoloads the
     * cross-app job class (it is resolved lazily by OpenConnector's JobService
     * container at execution time, not by OpenRegister).
     *
     * @var array<string, string>
     */
    private const MAP = [
        'openconnector:synchronization' => 'OCA\\OpenConnector\\Action\\SynchronizationAction',
    ];

    /**
     * Resolve an action type to its vetted `jobClass`, or null when not allow-listed.
     *
     * @param string $action The manifest-declared action type.
     *
     * @return string|null The server-vetted job class, or null when the action is not allow-listed.
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function resolve(string $action): ?string
    {
        return self::MAP[$action] ?? null;
    }//end resolve()

    /**
     * Whether an action type is on the allow-list.
     *
     * @param string $action The manifest-declared action type.
     *
     * @return bool
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    public function isAllowed(string $action): bool
    {
        return isset(self::MAP[$action]);
    }//end isAllowed()
}//end class
