<?php

/**
 * One issue found while validating a generated OpenAPI specification.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Where an OAS issue sits, what it says, and its stable machine code.
 *
 * The severity is not part of the issue: the report records the same issue
 * as an error, a warning or an auto-correction (ADR-011 rule 2).
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-9
 */
final class OasIssue {

	/**
	 * Constructor
	 *
	 * @param string $path    JSON Pointer path identifying the location.
	 * @param string $message Human-readable description.
	 * @param string $code    Stable machine code (one of the report's CODE_* constants).
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $message,
		public readonly string $code,
	) {
	}//end __construct()
}//end class
