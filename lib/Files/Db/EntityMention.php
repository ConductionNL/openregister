<?php

/**
 * EntityMention: where one catalogue entity is mentioned inside one chunk.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

/**
 * One mention of a catalogue entity: the entity, the chunk, and the span within that chunk.
 *
 * Together with a file id this is the dedup key of an entity relation row,
 * which `EntityRelationMapperInterface::existsForFileAtPosition()` probes.
 */
final class EntityMention {

	/**
	 * Catalogue entity id.
	 *
	 * @var integer
	 */
	public readonly int $entityId;

	/**
	 * Chunk row id the span is relative to.
	 *
	 * @var integer
	 */
	public readonly int $chunkId;

	/**
	 * Position of the first byte of the mention within the chunk.
	 *
	 * @var integer
	 */
	public readonly int $positionStart;

	/**
	 * Position one past the last byte of the mention within the chunk.
	 *
	 * @var integer
	 */
	public readonly int $positionEnd;

	/**
	 * Constructor.
	 *
	 * @param int $entityId      Catalogue entity id.
	 * @param int $chunkId       Chunk row id the span is relative to.
	 * @param int $positionStart Position of the first byte of the mention within the chunk.
	 * @param int $positionEnd   Position one past the last byte of the mention within the chunk.
	 */
	public function __construct(int $entityId, int $chunkId, int $positionStart, int $positionEnd) {
		$this->entityId      = $entityId;
		$this->chunkId       = $chunkId;
		$this->positionStart = $positionStart;
		$this->positionEnd   = $positionEnd;
	}//end __construct()

	/**
	 * The mention of an entity at one chunk match.
	 *
	 * @param int                                                    $entityId Catalogue entity id.
	 * @param array{chunkId: int, positionStart: int, positionEnd: int} $match    One match of `ChunkTextMatcher::match()`.
	 *
	 * @return self
	 */
	public static function atMatch(int $entityId, array $match): self {
		return new self(
			entityId: $entityId,
			chunkId: $match['chunkId'],
			positionStart: $match['positionStart'],
			positionEnd: $match['positionEnd']
		);
	}//end atMatch()
}//end class
