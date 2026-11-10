<?php

/**
 * TextExtractionSources: the stores TextExtractionService reads its sources from.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * The two kinds of source text is extracted from: files and objects.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final class TextExtractionSources {
	/**
	 * Bundle the source stores.
	 *
	 * @param TextExtractionStorage $storage The file and database stores.
	 * @param TextExtractionObjects $objects The object, schema and register mappers.
	 */
	public function __construct(
		public readonly TextExtractionStorage $storage,
		public readonly TextExtractionObjects $objects,
	) {
	}//end __construct()
}//end class
