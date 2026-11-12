<?php

/**
 * ProblemDescription — the descriptive fields of an RFC 7807 problem document.
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
 * What a problem document says beyond its status and title: the explanation,
 * the problem-class URI and the occurrence URI.
 *
 * @spec openspec/specs/oas-validation/spec.md "Error responses include problem details (API-46 / RFC 7807)"
 */
final readonly class ProblemDescription {

	/**
	 * Constructor.
	 *
	 * @param string $detail   Per-occurrence explanation; '' = omit.
	 * @param string $type     Problem-class URI; '' or the default means `about:blank`.
	 * @param string $instance Instance URI; '' = omit.
	 */
	public function __construct(
		public string $detail = '',
		public string $type = ProblemDetailsBuilder::DEFAULT_TYPE,
		public string $instance = '',
	) {
	}//end __construct()
}//end class
