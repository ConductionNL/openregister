<?php

/**
 * SubjectExportRequest — one request for a data subject's portable export.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Export;

/**
 * The subject, the optional PII type filter and the data subject request it answers.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class SubjectExportRequest {
	/**
	 * Hold the request.
	 *
	 * @param string      $subject   The trimmed data subject.
	 * @param string|null $type      Optional PII type filter.
	 * @param string|null $requestId The data subject request this answers.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly string $subject,
		public readonly ?string $type,
		public readonly ?string $requestId,
	) {
	}//end __construct()
}//end class
