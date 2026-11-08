<?php

/**
 * AnalyzeCall: one HTTP POST to an analyze endpoint.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * One analyze request: where it goes, what it sends and how it is logged.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final readonly class AnalyzeCall {
	/**
	 * Bundle the request.
	 *
	 * @param string             $url          The full URL to POST to.
	 * @param array              $requestBody  The request body to JSON-encode.
	 * @param string             $serviceName  Human-readable service name for log messages.
	 * @param array<int, string> $extraHeaders Additional header lines, such as credentials.
	 */
	public function __construct(
		public string $url,
		public array $requestBody,
		public string $serviceName,
		public array $extraHeaders=[],
	) {
	}//end __construct()
}//end class
