<?php

/**
 * OpenRegister ExportOutcome
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\AuditTrail
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

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * The outcome of one export, completed or refused, for the audit trail.
 *
 * The operation object of AuditTrailMapper::recordExport().
 */
final class ExportOutcome {

	/**
	 * Constructor.
	 *
	 * @param string               $outcome Either `completed` or `refused`.
	 * @param array<string, mixed> $summary Profile, format, value mode, row count and, on a refusal, the reason.
	 * @param AuditedObject        $target  The register and schema exported, when known.
	 * @param string|null          $actorId The principal the export ran as, when it is not the session user.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly array $summary,
		public readonly AuditedObject $target = new AuditedObject(),
		public readonly ?string $actorId = null,
	) {
	}//end __construct()
}//end class
