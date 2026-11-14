<?php

/**
 * ContentSnippetSource — a matched chunk and what is known about the file it came from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * The chunk text of a content hit and the four facts that decide whether it may be quoted.
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
 */
final class ContentSnippetSource {

	/**
	 * Constructor.
	 *
	 * @param int         $fileId           The file the chunk belongs to.
	 * @param string      $text             The chunk text.
	 * @param bool        $anonymisedCopy   Whether the file is an anonymised copy (not an original).
	 * @param string|null $verdict          The copy's stored verification verdict, or null when it has none.
	 * @param bool        $published        Whether the copy is published.
	 * @param bool        $readableByCaller Whether the caller may read the file.
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $text,
		public readonly bool $anonymisedCopy,
		public readonly ?string $verdict,
		public readonly bool $published,
		public readonly bool $readableByCaller,
	) {
	}//end __construct()
}//end class
