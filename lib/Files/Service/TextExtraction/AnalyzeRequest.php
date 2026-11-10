<?php

/**
 * AnalyzeRequest: the text one analyze backend is asked about, in that backend's dialect.
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
 * One analyze request: what is detected (the query) and how the backend
 * names and scores it (the dialect). The request body is built from it and
 * the backend's results are read back with it.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final readonly class AnalyzeRequest {
	/**
	 * Bundle the request.
	 *
	 * @param EntityDetectionQuery $query   The analysed text, the entity filter and the minimum confidence.
	 * @param AnalyzeDialect       $dialect The backend's language, entity names, method and default confidence.
	 */
	public function __construct(
		public EntityDetectionQuery $query,
		public AnalyzeDialect $dialect,
	) {
	}//end __construct()
}//end class
