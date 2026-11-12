<?php

/**
 * ProblemStatement — the problem an RFC 7807 document is built from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-validation/spec.md "Error responses include problem details (API-46 / RFC 7807)"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * The operation object of {@see ProblemDetailsBuilder::build()}: which problem
 * is being stated (status and title), how it is described, and the
 * extension members that travel with it.
 *
 * @spec openspec/specs/oas-validation/spec.md "Error responses include problem details (API-46 / RFC 7807)"
 */
final class ProblemStatement {
	/**
	 * State a problem.
	 *
	 * @param int                     $status      HTTP status code (4xx / 5xx).
	 * @param string                  $title       Short summary.
	 * @param ProblemDescription|null $description Detail, problem-class URI and instance URI; null = none of them.
	 * @param array                   $extensions  Free-form extension fields (e.g. `errors`, `code`).
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $title,
		public readonly ?ProblemDescription $description = null,
		public readonly array $extensions = [],
	) {
	}//end __construct()
}//end class
