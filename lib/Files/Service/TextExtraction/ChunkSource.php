<?php

/**
 * ChunkSource: the source a set of text chunks belongs to.
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
 * Source identity, ownership and timestamp shared by every chunk of one source.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final class ChunkSource {
	/**
	 * Owner identifier.
	 *
	 * @var string|null
	 */
	public readonly ?string $owner;

	/**
	 * Organisation identifier.
	 *
	 * @var string|null
	 */
	public readonly ?string $organisation;

	/**
	 * Bundle the source facts.
	 *
	 * @param string         $sourceType      Source type identifier (`file` or `object`).
	 * @param int            $sourceId        Source identifier.
	 * @param ChunkOwnership $ownership       Owner and organisation of the source.
	 * @param int            $sourceTimestamp Source modification timestamp.
	 *
	 * @phpstan-param non-empty-string $sourceType
	 * @psalm-param   non-empty-string $sourceType
	 */
	public function __construct(
		public readonly string $sourceType,
		public readonly int $sourceId,
		ChunkOwnership $ownership,
		public readonly int $sourceTimestamp,
	) {
		$this->owner        = $ownership->owner;
		$this->organisation = $ownership->organisation;
	}//end __construct()
}//end class
