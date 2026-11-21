<?php

/**
 * EmbeddingProbe
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
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

namespace OCA\OpenRegister\Search\Service\Vectorization;

/**
 * One trial embedding: which provider, with which settings, on which text.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class EmbeddingProbe {
	/**
	 * Describe a probe.
	 *
	 * @param string $provider Provider to test (openai, fireworks, ollama).
	 * @param array  $config   Provider settings as the settings screen sends them (model, apiKey, baseUrl or url).
	 * @param string $testText Text to embed.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly array $config,
		public readonly string $testText = 'Test.',
	) {
	}//end __construct()

	/**
	 * The model the probe names, or 'unknown'.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function model(): mixed {
		return $this->config['model'] ?? 'unknown';
	}//end model()

	/**
	 * The custom config generateEmbeddingWithCustomConfig() reads.
	 *
	 * @return array{provider: string, model: mixed, apiKey: mixed, baseUrl: mixed}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function customConfig(): array {
		return [
			'provider' => $this->provider,
			'model' => $this->config['model'] ?? null,
			'apiKey' => $this->config['apiKey'] ?? null,
			'baseUrl' => $this->config['baseUrl'] ?? $this->config['url'] ?? null,
		];
	}//end customConfig()
}//end class
