<?php

/**
 * ErasureRequest — one art-17 erasure, as the service decides it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

/**
 * Whose data is erased, filtered how, in which mode, and whether anything is written.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
 */
final class ErasureRequest {
	/**
	 * Hold the request.
	 *
	 * @param string      $subjectId Subject value.
	 * @param string|null $type      GdprEntity type filter.
	 * @param string      $eraseMode Erase mode (one of the ERASE_MODE_* constants).
	 * @param bool        $dryRun    Whether nothing is written.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
	 */
	public function __construct(
		public readonly string $subjectId,
		public readonly ?string $type = null,
		public readonly string $eraseMode = DataSubjectRequestServiceInterface::ERASE_MODE_PSEUDONYMISE,
		public readonly bool $dryRun = false,
	) {
	}//end __construct()
}//end class
