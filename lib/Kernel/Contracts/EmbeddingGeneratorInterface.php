<?php

/**
 * EmbeddingGeneratorInterface: generates embedding vectors for texts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Generates embedding vectors for texts. Implemented by Service\Vectorization\VectorEmbeddings.
 */
interface EmbeddingGeneratorInterface {

	/**
	 * Generate embeddings for multiple texts in batch
	 *
	 * Per-text failures are tolerated: the failing entry carries a null
	 * embedding and an error message instead of aborting the whole batch.
	 *
	 * @param array<string> $texts Array of texts to embed
	 * @param string|null $provider Embedding provider
	 *
	 * @return array<int, array{embedding: array<float>|null, model: string, dimensions: int, error?: string}> Array of embeddings
	 *
	 * @throws \Exception If batch embedding generation fails
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function generateBatchEmbeddings(array $texts, ?string $provider = null): array;
}//end interface
