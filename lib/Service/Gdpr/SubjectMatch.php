<?php

/**
 * SubjectMatch — one loaded object and the GdprEntity hits that pulled it in.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A data subject's match: the object, and the index hits that made it theirs.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class SubjectMatch {
	/**
	 * Hold the match.
	 *
	 * @param ObjectEntity      $object  The loaded object.
	 * @param array<int, array> $matched GdprEntity hits that triggered inclusion (each has a `value`).
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly array $matched,
	) {
	}//end __construct()
}//end class
