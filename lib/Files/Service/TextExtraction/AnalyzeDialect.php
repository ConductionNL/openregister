<?php

/**
 * AnalyzeDialect: how one analyze backend names entities and scores them.
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
 * The per-backend dialect of an analyze API (Presidio, OpenAnonymiser).
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final readonly class AnalyzeDialect {
	/**
	 * Bundle the dialect facts.
	 *
	 * @param string                     $language          Language code sent with the text.
	 * @param array<string, string>|null $nameMap           Our type to the backend's name; null uses Presidio's names.
	 * @param string                     $method            Detection method recorded on each entity.
	 * @param float                      $defaultConfidence Confidence used when a result carries no score.
	 */
	public function __construct(
		public string $language,
		public ?array $nameMap,
		public string $method,
		public float $defaultConfidence,
	) {
	}//end __construct()
}//end class
