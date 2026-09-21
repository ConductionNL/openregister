<?php

/**
 * ExportGate: the one place an export path asks whether it may run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Turns "may this caller export" into the response an export endpoint returns.
 *
 * REQ-EXP-001 says EVERY export path checks the verb, and the first version of
 * this change checked it on three of them. The others each had their own
 * reason to be different, and each would have to grow its own copy of the
 * check, its own refusal shape and its own audit call. Three copies of a
 * control is how the fourth path ends up without one.
 *
 * So the check, the refusal body and the audit entry live here, and an export
 * endpoint is one call and one early return. The refusal shape is identical
 * across paths on purpose: a caller that learns to read one refusal reads all
 * of them.
 *
 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md#requirement-export-is-its-own-permission-verb-req-exp-001
 */
class ExportGate {

	/**
	 * Constructor.
	 *
	 * @param ExportRightService  $rights   The export verb.
	 * @param ExportAuditRecorder $recorder Where a refusal is recorded.
	 */
	public function __construct(
		private readonly ExportRightService $rights,
		private readonly ExportAuditRecorder $recorder,
	) {
	}//end __construct()

	/**
	 * The refusal this export should return, or null when it may run.
	 *
	 * @param Schema|null $schema     The schema being exported.
	 * @param string      $profile    What the audit entry calls this export.
	 * @param int|null    $registerId The register, for the audit entry.
	 *
	 * @return JSONResponse|null The refusal, or null when the export may run.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md#requirement-export-is-its-own-permission-verb-req-exp-001
	 */
	public function refusalFor(?Schema $schema, string $profile, ?int $registerId = null): ?JSONResponse {
		$refusal = $this->rights->refusalFor(schema: $schema);

		if ($refusal === null) {
			return null;
		}

		$this->record(
			refusal: $refusal,
			profile: $profile,
			registerId: $registerId,
			schemaId: $schema?->getId()
		);

		return new JSONResponse(data: $refusal->toResponseBody(), statusCode: $refusal->getStatusCode());

	}//end refusalFor()

	/**
	 * Record the refusal, without letting the record fail the refusal.
	 *
	 * The refusal is the control; the entry is the record of it. A trail that
	 * cannot be written must not turn a refusal into a 500, because a 500 and
	 * a 403 are acted on very differently by whoever meets them.
	 *
	 * @param ExportRefusedException $refusal    The refusal.
	 * @param string                 $profile    What this export is called.
	 * @param int|null               $registerId The register.
	 * @param int|null               $schemaId   The schema.
	 *
	 * @return void
	 */
	private function record(
		ExportRefusedException $refusal,
		string $profile,
		?int $registerId,
		?int $schemaId,
	): void {
		try {
			$this->recorder->recordRefused(
				profile: $profile,
				rule: $refusal->getRule(),
				reason: $refusal->getMessage(),
				register: $registerId,
				schema: $schemaId
			);
		} catch (Throwable $exception) {
			return;
		}

	}//end record()
}//end class
