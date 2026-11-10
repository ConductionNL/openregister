<?php

/**
 * AnalyzeResponse: what an analyze backend answered over HTTP.
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
 * The raw answer of an analyze HTTP call: the status curl reported and the
 * body it returned (a string, or false when curl returned none).
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final readonly class AnalyzeResponse {
	/**
	 * Bundle the answer.
	 *
	 * @param mixed $status The HTTP status curl reported.
	 * @param mixed $body   The raw response body.
	 */
	public function __construct(
		public mixed $status,
		public mixed $body,
	) {
	}//end __construct()
}//end class
