<?php

/**
 * ChunkOwnership: who owns the source a set of text chunks belongs to.
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
 * Owner and organisation of one chunk source.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final class ChunkOwnership {
	/**
	 * Bundle the ownership facts.
	 *
	 * @param string|null $owner        Owner identifier.
	 * @param string|null $organisation Organisation identifier.
	 */
	public function __construct(
		public readonly ?string $owner,
		public readonly ?string $organisation,
	) {
	}//end __construct()
}//end class
