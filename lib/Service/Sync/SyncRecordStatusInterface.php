<?php

/**
 * OpenRegister Sync Record Status Interface
 *
 * The per-record status vocabulary and its transition table, as constants,
 * so code outside the Sync component (the SyncRecordMapper) reads them
 * without naming the concrete SyncRecordStatus class.
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
 * Sync record status vocabulary and allowed forward transitions.
 *
 * A status `$to` may follow `$from` exactly when it is listed in
 * `TRANSITIONS[$from]`; an unknown `$from` has no entry and allows nothing.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
interface SyncRecordStatusInterface {

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
	public const TRANSITIONS = [
		self::PENDING => [self::FETCHED, self::FETCH_ERROR, self::SKIPPED],
		self::FETCHED => [self::IMPORTED, self::UNCHANGED, self::IMPORT_ERROR, self::CONFLICT, self::SKIPPED],
		self::FETCH_ERROR => [self::FETCHED, self::PERMANENT_ERROR, self::SKIPPED],
		self::IMPORT_ERROR => [self::IMPORTED, self::PERMANENT_ERROR, self::SKIPPED],
		self::CONFLICT => [self::IMPORTED, self::UNCHANGED, self::SKIPPED],
		// Terminal states.
		self::IMPORTED => [],
		self::UNCHANGED => [],
		self::SKIPPED => [],
		self::PERMANENT_ERROR => [],
	];
}//end interface
