<?php

/**
 * OpenRegister Sync Record Status
 *
 * Per-record status vocabulary and transition rules for the harvest
 * pipeline (gather -> fetch -> import). Kept as pure logic so the state
 * machine can be unit-tested without a live database or HTTP client.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Sync;

/**
 * Per-record sync status state machine.
 *
 * Mirrors CKAN's harvest object status model: records move forward
 * through the three pipeline stages and may end in a terminal state.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SyncRecordStatus
{

    /**
     * Record identified during the Gather stage, awaiting fetch.
     */
    public const PENDING = 'pending';

    /**
     * Full record data retrieved during the Fetch stage.
     */
    public const FETCHED = 'fetched';

    /**
     * Fetch failed for this record (transient or hard error).
     */
    public const FETCH_ERROR = 'fetch_error';

    /**
     * Record successfully created or updated in the target register.
     */
    public const IMPORTED = 'imported';

    /**
     * Record content unchanged since the last sync; no write performed.
     */
    public const UNCHANGED = 'unchanged';

    /**
     * Import failed (schema validation or persistence error).
     */
    public const IMPORT_ERROR = 'import_error';

    /**
     * Source and local copy both changed; awaiting manual resolution.
     */
    public const CONFLICT = 'conflict';

    /**
     * Record skipped (e.g. filtered out or beyond the execution limit).
     */
    public const SKIPPED = 'skipped';

    /**
     * Record failed after all retries were exhausted.
     */
    public const PERMANENT_ERROR = 'permanent_error';

    /**
     * Allowed forward transitions keyed by current status.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::PENDING         => [self::FETCHED, self::FETCH_ERROR, self::SKIPPED],
        self::FETCHED         => [self::IMPORTED, self::UNCHANGED, self::IMPORT_ERROR, self::CONFLICT, self::SKIPPED],
        self::FETCH_ERROR     => [self::FETCHED, self::PERMANENT_ERROR, self::SKIPPED],
        self::IMPORT_ERROR    => [self::IMPORTED, self::PERMANENT_ERROR, self::SKIPPED],
        self::CONFLICT        => [self::IMPORTED, self::UNCHANGED, self::SKIPPED],
        // Terminal states.
        self::IMPORTED        => [],
        self::UNCHANGED       => [],
        self::SKIPPED         => [],
        self::PERMANENT_ERROR => [],
    ];

    /**
     * All valid status values.
     *
     * @return list<string> The known statuses
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::FETCHED,
            self::FETCH_ERROR,
            self::IMPORTED,
            self::UNCHANGED,
            self::IMPORT_ERROR,
            self::CONFLICT,
            self::SKIPPED,
            self::PERMANENT_ERROR,
        ];
    }//end all()

    /**
     * Whether a value is a known status.
     *
     * @param string $status The status to check
     *
     * @return bool True when the status is recognised
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }//end isValid()

    /**
     * Whether a status is terminal (no further transitions allowed).
     *
     * @param string $status The status to check
     *
     * @return bool True when the status is terminal
     */
    public static function isTerminal(string $status): bool
    {
        return isset(self::TRANSITIONS[$status]) === true && self::TRANSITIONS[$status] === [];
    }//end isTerminal()

    /**
     * Whether a status counts as an error outcome.
     *
     * @param string $status The status to check
     *
     * @return bool True when the status represents a failure
     */
    public static function isError(string $status): bool
    {
        return in_array($status, [self::FETCH_ERROR, self::IMPORT_ERROR, self::PERMANENT_ERROR], true);
    }//end isError()

    /**
     * Whether moving from one status to another is allowed.
     *
     * @param string $from The current status
     * @param string $to   The target status
     *
     * @return bool True when the transition is permitted
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public static function canTransition(string $from, string $to): bool
    {
        if (self::isValid(status: $from) === false || self::isValid(status: $to) === false) {
            return false;
        }

        return in_array($to, (self::TRANSITIONS[$from] ?? []), true);
    }//end canTransition()
}//end class
