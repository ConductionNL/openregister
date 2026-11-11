<?php

/**
 * Stored Vector
 *
 * One embedding together with what it embeds, as one row of the vector table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

/**
 * The entity, embedding, model and chunk one vector row is written from.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class StoredVector {

	/**
	 * Entity type ('object' or 'file').
	 *
	 * @var string
	 */
	public readonly string $entityType;

	/**
	 * Entity UUID.
	 *
	 * @var string
	 */
	public readonly string $entityId;

	/**
	 * Vector embedding (array of floats).
	 *
	 * @var array<mixed>
	 */
	public readonly array $embedding;

	/**
	 * Model used to generate the embedding.
	 *
	 * @var string
	 */
	public readonly string $model;

	/**
	 * Number of dimensions.
	 *
	 * @var integer
	 */
	public readonly int $dimensions;

	/**
	 * Chunk index (0 for objects, N for file chunks).
	 *
	 * @var integer
	 */
	public readonly int $chunkIndex;

	/**
	 * Total number of chunks.
	 *
	 * @var integer
	 */
	public readonly int $totalChunks;

	/**
	 * The text that was embedded.
	 *
	 * @var string|null
	 */
	public readonly ?string $chunkText;

	/**
	 * Additional metadata as associative array.
	 *
	 * @var array<mixed>
	 */
	public readonly array $metadata;

	/**
	 * Constructor.
	 *
	 * @param VectorSubject       $subject  The entity type and UUID.
	 * @param VectorPayload       $payload  The embedding, model and dimensions.
	 * @param VectorChunkPosition $position The chunk index, chunk count and text.
	 * @param array<mixed>        $metadata Additional metadata as associative array.
	 */
	public function __construct(
		VectorSubject $subject,
		VectorPayload $payload,
		VectorChunkPosition $position,
		array $metadata,
	) {
		$this->entityType  = $subject->entityType;
		$this->entityId    = $subject->entityId;
		$this->embedding   = $payload->embedding;
		$this->model       = $payload->model;
		$this->dimensions  = $payload->dimensions;
		$this->chunkIndex  = $position->chunkIndex;
		$this->totalChunks = $position->totalChunks;
		$this->chunkText   = $position->chunkText;
		$this->metadata    = $metadata;

	}//end __construct()
}//end class
