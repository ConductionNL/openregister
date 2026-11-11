<?php

/**
 * SubjectQuery — which data subject to look for, and how to match them.
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
 * The subject value, the optional GdprEntity type, and the match mode a discovery runs under.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class SubjectQuery {
	/**
	 * Hold the query.
	 *
	 * @param string      $subjectId Subject identifier value (email, …).
	 * @param string|null $type      Optional GdprEntity type filter.
	 * @param string      $mode      `exact` (default) or `ilike`.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly string $subjectId,
		public readonly ?string $type = null,
		public readonly string $mode = 'exact',
	) {
	}//end __construct()
}//end class
