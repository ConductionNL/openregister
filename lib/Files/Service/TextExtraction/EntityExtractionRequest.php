<?php

/**
 * OpenRegister entity extraction request
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * One request to extract the entities of a source's chunks.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-14
 */
final class EntityExtractionRequest {
	/**
	 * Constructor.
	 *
	 * @param string               $sourceType Source type identifier (file, object, etc.).
	 * @param int                  $sourceId   Source identifier.
	 * @param array<string, mixed> $options    Processing options: method, entity_types, confidence_threshold, context_window.
	 */
	public function __construct(
		public readonly string $sourceType,
		public readonly int $sourceId,
		public readonly array $options = [],
	) {
	}//end __construct()
}//end class
