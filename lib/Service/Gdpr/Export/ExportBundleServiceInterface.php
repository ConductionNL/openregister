<?php

/**
 * ExportBundleServiceInterface: what callers outside the Service component use of ExportBundleService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Export
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

namespace OCA\OpenRegister\Service\Gdpr\Export;

use RuntimeException;

/**
 * Generates, downloads and assembles the DSAR export bundle and regulator dossier, as the DSAR case endpoints do.
 */
interface ExportBundleServiceInterface {

	/**
	 * Generate the signed export bundle for a case and mint a one-time token.
	 *
	 * Assembles the subject data via the existing access-export primitive,
	 * renders it to PDF bytes, signs (or stub-signs) them, records the
	 * generation on the case (audited), and returns the signed bundle + a raw
	 * one-time download token bound to the case.
	 *
	 * @param string $caseUuid The case object uuid.
	 *
	 * @return array{caseUuid: string, contentHash: string, signed: bool, signatureState: string, downloadToken: string}
	 *
	 * @throws RuntimeException When the case cannot be loaded (absent or unauthorised).
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	public function generate(string $caseUuid): array;

	/**
	 * Redeem a one-time token and return the signed bundle bytes for download.
	 *
	 * The token is verified + BURNED (single-use) against the case scope; a
	 * replay is refused. On success the bundle is re-assembled and re-signed
	 * from the same RBAC-scoped case so the returned bytes match the recorded
	 * hash. Returns null when the token is invalid/expired/replayed or the case
	 * is not authorised (the caller maps null to a refusal).
	 *
	 * @param string $caseUuid The case the download is scoped to.
	 * @param string $token The raw one-time token.
	 *
	 * @return SignedBundle|null The signed bundle, or null when refused.
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	public function download(string $caseUuid, string $token): ?SignedBundle;

	/**
	 * Assemble the regulator dossier for a case.
	 *
	 * Reads the case under the caller's RBAC and reflects what was collected
	 * (the `evidence` sub-collection), what was redacted with grounds (the
	 * `redactions` sub-collection), and the case history (its immutable
	 * audit-trail entries). It never reaches data outside the caller's
	 * authorisation — it reads only the already-authorised case object + that
	 * object's own trail.
	 *
	 * @param string $caseUuid The case object uuid.
	 *
	 * @return array{caseUuid: string, subjectId: string, status: string, evidence: array, redactions: array, history: array}
	 *
	 * @throws RuntimeException When the case cannot be loaded (absent or unauthorised).
	 *
	 * @spec openspec/specs/dsar-export-bundle/spec.md
	 */
	public function assembleRegulatorDossier(string $caseUuid): array;
}//end interface
