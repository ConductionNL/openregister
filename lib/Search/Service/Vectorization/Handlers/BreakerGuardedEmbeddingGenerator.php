<?php

/**
 * An embedding generator that calls the provider through the `llm` breaker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use OCA\OpenRegister\Kernel\Contracts\DependencyBreakerInterface;

/**
 * Wraps a provider's embedding generator so every embedding call goes through
 * the `llm` breaker (design D-3): refused at once while it is open, and each
 * exception from the provider counted as a failure.
 *
 * `getEmbeddingLength()` is passed through unguarded: it is the vector size,
 * not an embedding, and callers ask it when building indexes.
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
final class BreakerGuardedEmbeddingGenerator implements EmbeddingGeneratorInterface {

	/**
	 * Constructor.
	 *
	 * @param EmbeddingGeneratorInterface $inner   The provider's generator.
	 * @param DependencyBreakerInterface   $breaker The breaker the `llm` key lives on.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EmbeddingGeneratorInterface $inner,
		private readonly DependencyBreakerInterface $breaker,
	) {
	}//end __construct()

	/**
	 * The provider's own generator, unwrapped.
	 *
	 * @return EmbeddingGeneratorInterface The generator.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function inner(): EmbeddingGeneratorInterface {
		return $this->inner;
	}//end inner()

	/**
	 * Embed one text through the breaker.
	 *
	 * @param string $text The text.
	 *
	 * @return float[] The embedding.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function embedText(string $text): array {
		return $this->breaker->call(
			key: $this->breaker->llmKey(),
			call: fn (): array => $this->inner->embedText($text)
		);
	}//end embedText()

	/**
	 * Embed one document through the breaker.
	 *
	 * @param Document $document The document.
	 *
	 * @return Document The document with its embedding.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function embedDocument(Document $document): Document {
		return $this->breaker->call(
			key: $this->breaker->llmKey(),
			call: fn (): Document => $this->inner->embedDocument($document)
		);
	}//end embedDocument()

	/**
	 * Embed documents through the breaker, as one call.
	 *
	 * @param Document[] $documents The documents.
	 *
	 * @return Document[] The documents with their embeddings.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function embedDocuments(array $documents): array {
		return $this->breaker->call(
			key: $this->breaker->llmKey(),
			call: fn (): array => $this->inner->embedDocuments($documents)
		);
	}//end embedDocuments()

	/**
	 * The vector size, from the provider's generator.
	 *
	 * @return int The embedding length.
	 *
	 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
	 */
	public function getEmbeddingLength(): int {
		return $this->inner->getEmbeddingLength();
	}//end getEmbeddingLength()
}//end class
