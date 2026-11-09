<?php

/**
 * ErasurePreviewRun — the one erasure request a preview classifies against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Service\Gdpr\SubjectQuery;

/**
 * The subject, the type filter and the normalised mode of one preview.
 *
 * Built by ErasurePreviewService::preview() and never handed out of it.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class ErasurePreviewRun {
	/**
	 * Hold the request being previewed.
	 *
	 * @param string      $subjectId The trimmed subject value.
	 * @param string|null $type      Optional GdprEntity type filter.
	 * @param string      $eraseMode The normalised mode.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly string $subjectId,
		public readonly ?string $type,
		public readonly string $eraseMode,
	) {
	}//end __construct()

	/**
	 * The discovery this preview runs over.
	 *
	 * @return SubjectQuery The subject and type, exact match.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function query(): SubjectQuery {
		return new SubjectQuery(subjectId: $this->subjectId, type: $this->type);
	}//end query()
}//end class
