<?php

/**
 * ErasurePreviewServiceInterface: what callers outside the component use of ErasurePreviewService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
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

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\SubjectQuery;

/**
 * The erasure preview the erasure endpoints build.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ErasurePreviewServiceInterface {

	/**
	 * Preview an erasure: the counts, the named protected records, a digest.
	 *
	 * Writes nothing. Every collaborator it calls is a read, and the two that
	 * could refuse in a way the caller cannot see — the archival guard and the
	 * retention service — are called inside a catch that counts an unanswerable
	 * question as PROTECTED (D-2).
	 *
	 * @param SubjectQuery $subject   Subject identifier value (email, bsn, …) and optional GdprEntity type
	 *                                filter; the match is always exact.
	 * @param string       $eraseMode `pseudonymise` or `whole-object`.
	 *
	 * @return array<string, mixed> The preview: counts, items, protected, digest.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function preview(
		SubjectQuery $subject,
		string $eraseMode = DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE,
	): array;
}//end interface
