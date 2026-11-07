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
	 * @param string       $entityType  Entity type ('object' or 'file').
	 * @param string       $entityId    Entity UUID.
	 * @param array<mixed> $embedding   Vector embedding (array of floats).
	 * @param string       $model       Model used to generate the embedding.
	 * @param int          $dimensions  Number of dimensions.
	 * @param int          $chunkIndex  Chunk index (0 for objects, N for file chunks).
	 * @param int          $totalChunks Total number of chunks.
	 * @param string|null  $chunkText   The text that was embedded.
	 * @param array<mixed> $metadata    Additional metadata as associative array.
	 */
	public function __construct(
		string $entityType,
		string $entityId,
		array $embedding,
		string $model,
		int $dimensions,
		int $chunkIndex,
		int $totalChunks,
		?string $chunkText,
		array $metadata,
	) {
		$this->entityType  = $entityType;
		$this->entityId    = $entityId;
		$this->embedding   = $embedding;
		$this->model       = $model;
		$this->dimensions  = $dimensions;
		$this->chunkIndex  = $chunkIndex;
		$this->totalChunks = $totalChunks;
		$this->chunkText   = $chunkText;
		$this->metadata    = $metadata;

	}//end __construct()
}//end class
