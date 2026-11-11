<?php

/**
 * RetentionSweepInterface: what callers outside the Service component ask of RetentionSweepService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Retention
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Retention;

/**
 * Running one DSAR retention sweep, as the daily sweep job does. Only the method that caller calls.
 */
interface RetentionSweepInterface {

	/**
	 * Run a single retention pass.
	 *
	 * For every case whose `retainUntil` has passed and that is NOT under an
	 * active legal hold / immutable status: (dry-run) report it; else scrub its
	 * evidence PII via erase pseudonymise and hard-delete the dossier. Held or
	 * immutable expired cases are reported as skipped and left intact.
	 *
	 * RETENTION WINS OVER ERASURE. A case on a schema declaring
	 * `x-openregister-archival` is kept whatever its `retainUntil` says, and is
	 * reported in `withheld` with the ground and the wording to pass on. The
	 * refusal comes from
	 * {@see \OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard}, so it
	 * reads the same definition as every other delete door.
	 *
	 * @param bool $dryRun When true, report candidates without deleting/scrubbing.
	 *
	 * A case whose dossier delete fails is named in `failed`, never in
	 * `purged`: the summary is the record of what was destroyed.
	 *
	 * @return array{dryRun: bool, evaluated: int, purged: array<int, string>,
	 *               failed: array<int, string>,
	 *               skippedHeld: array<int, string>, withinWindow: int,
	 *               withheld: array<int, array<string, mixed>>, withheldCount: int}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Dry-run toggle mirrors AvgRetentionJob's contract.
	 *
	 * @spec openspec/specs/dsar-retention-sweep/spec.md
	 */
	public function runSweep(bool $dryRun = false): array;
}//end interface
