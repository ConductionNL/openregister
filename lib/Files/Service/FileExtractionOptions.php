<?php

/**
 * OpenRegister FileExtractionOptions.
 *
 * The optional settings of one file text extraction: whether to ignore up-to-date chunks and which entity types to detect.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * The optional settings of one file text extraction: whether to ignore up-to-date chunks and which entity types to detect.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class FileExtractionOptions {
	/**
	 * Constructor.
	 *
	 * @param bool                    $forceReExtract Re-extract even when the chunks are up to date.
	 * @param array<int, string>|null $entityTypes    Entity types to detect; null for all.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly bool $forceReExtract = false,
		public readonly ?array $entityTypes = null,
	) {
	}//end __construct()
}//end class
