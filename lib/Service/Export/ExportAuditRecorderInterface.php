<?php

/**
 * ExportAuditRecorderInterface: the export audit ledger as callers outside the component use it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

/**
 * Records a completed or refused export on the hash-chained audit trail.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to ExportAuditRecorder.
 */
interface ExportAuditRecorderInterface {

	/**
	 * Record a completed export.
	 *
	 * @param string      $profile  The profile name, or `ad-hoc` when the caller named no profile.
	 * @param int         $rowCount How many rows left the instance.
	 * @param string      $format   The format written.
	 * @param string|null $valueMode The value mode the file was written in, when a profile chose one.
	 * @param int|null    $register The register exported.
	 * @param int|null    $schema   The schema exported.
	 * @param string|null $actorId  The principal the export ran as, when it is not the session user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function recordCompleted(
		string $profile,
		int $rowCount,
		string $format,
		?string $valueMode = null,
		?int $register = null,
		?int $schema = null,
		?string $actorId = null,
	): void;

	/**
	 * Record a refused export.
	 *
	 * @param string      $profile  The profile name, or `ad-hoc` when the caller named no profile.
	 * @param string      $rule     The rule that refused.
	 * @param string      $reason   The sentence the caller was given.
	 * @param int|null    $register The register that was asked for.
	 * @param int|null    $schema   The schema that was asked for.
	 * @param string|null $actorId  The principal that was refused, when it is not the session user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function recordRefused(
		string $profile,
		string $rule,
		string $reason,
		?int $register = null,
		?int $schema = null,
		?string $actorId = null,
	): void;
}//end interface
