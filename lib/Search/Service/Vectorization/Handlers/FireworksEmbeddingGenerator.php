<?php

/**
 * Fireworks Embedding Generator
 *
 * Embedding client for the OpenAI-compatible Fireworks AI API.
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

use CurlHandle;
use Exception;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use Psr\Log\LoggerInterface;

/**
 * Embedding generator for any Fireworks AI model.
 *
 * Built by EmbeddingGeneratorHandler for the 'fireworks' provider; it is not
 * restricted to the model names LLPhant knows.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
final class FireworksEmbeddingGenerator implements EmbeddingGeneratorInterface {
	/**
	 * Model name
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Configuration array
	 *
	 * @var array
	 */
	private array $config;

	/**
	 * Logger instance
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $config Configuration array with model, api_key and base_url
	 * @param LoggerInterface $logger Logger instance
	 *
	 * @return void
	 */
	public function __construct(array $config, LoggerInterface $logger) {
		$this->model = $config['model'];
		$this->config = $config;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Embed text using Fireworks AI API
	 *
	 * @param string $text Text to embed
	 *
	 * @return array<float> Embedding vector
	 *
	 * @throws \Exception If API call fails
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function embedText(string $text): array {
		$url = rtrim($this->config['base_url'] ?? 'https://api.fireworks.ai/inference/v1', '/') . '/embeddings';

		$this->logger->debug(
			message: '[EmbeddingGeneratorHandler] Calling Fireworks AI API',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'url' => $url,
				'model' => $this->model,
			]
		);

		$response = $this->send(handle: $this->openRequest(url: $url, text: $text));

		return $this->extractEmbedding(response: $response);
	}//end embedText()

	/**
	 * Get embedding length
	 *
	 * @return int Embedding length
	 *
	 * @psalm-return 768|1024
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function getEmbeddingLength(): int {
		// Return expected dimensions based on model.
		return match ($this->model) {
			'nomic-ai/nomic-embed-text-v1.5' => 768,
			'thenlper/gte-base' => 768,
			'thenlper/gte-large' => 1024,
			'WhereIsAI/UAE-Large-V1' => 1024,
			default => 768
		};
	}//end getEmbeddingLength()

	/**
	 * Embed a document
	 *
	 * @param Document $document Document to embed
	 *
	 * @return Document Embedded document
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function embedDocument(Document $document): Document {
		$document->embedding = $this->embedText(text: $document->content);
		return $document;
	}//end embedDocument()

	/**
	 * Embed multiple documents
	 *
	 * @param array<int,Document> $documents Documents to embed
	 *
	 * @return array<int,Document> Embedded documents
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function embedDocuments(array $documents): array {
		foreach ($documents as $document) {
			$document->embedding = $this->embedText(text: $document->content);
		}

		return $documents;
	}//end embedDocuments()

	/**
	 * Prepare the POST request that asks for the embedding of one text.
	 *
	 * @param string $url  Embeddings endpoint
	 * @param string $text Text to embed
	 *
	 * @return CurlHandle Configured handle, not yet executed
	 */
	private function openRequest(string $url, string $text): CurlHandle {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// Bound the request: without timeouts a hung/slow embedding
		// provider blocks the whole batch (and cron) until PHP's
		// max_execution_time kills it (optimize-vectorization-pipeline).
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->requestHeaders());
		curl_setopt($ch, CURLOPT_POSTFIELDS, $this->requestBody(text: $text));

		return $ch;
	}//end openRequest()

	/**
	 * Headers of the embeddings request.
	 *
	 * @return array<int, string> Header lines
	 */
	private function requestHeaders(): array {
		return [
			'Authorization: Bearer ' . $this->config['api_key'],
			'Content-Type: application/json',
		];
	}//end requestHeaders()

	/**
	 * JSON body of the embeddings request.
	 *
	 * @param string $text Text to embed
	 *
	 * @return string|false Encoded body
	 */
	private function requestBody(string $text): string|false {
		return json_encode(
			[
				'model' => $this->model,
				'input' => $text,
			]
		);
	}//end requestBody()

	/**
	 * Execute the request and return the body of a successful response.
	 *
	 * @param CurlHandle $handle Configured request
	 *
	 * @return string Response body
	 *
	 * @throws \Exception On a transport error, a non-200 status or a non-string body
	 */
	private function send(CurlHandle $handle): string {
		$response = curl_exec($handle);
		$httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
		$error = curl_error($handle);
		// No curl_close(): deprecated since PHP 8.0 and a no-op — the
		// CurlHandle object is freed when it goes out of scope.
		if ($error !== '') {
			throw new Exception("Fireworks API request failed: {$error}");
		}

		if ($httpCode !== 200) {
			throw new Exception("Fireworks API returned HTTP {$httpCode}: {$response}");
		}

		// Ensure $response is a string.
		if (is_string($response) === false) {
			throw new Exception('Fireworks API request failed: Invalid response type');
		}

		return $response;
	}//end send()

	/**
	 * Read the embedding vector out of a Fireworks response body.
	 *
	 * @param string $response Response body
	 *
	 * @return array<float> Embedding vector
	 *
	 * @throws \Exception If the body carries no embedding
	 */
	private function extractEmbedding(string $response): array {
		$data = json_decode($response, true);
		if (isset($data['data'][0]['embedding']) === false) {
			throw new Exception("Unexpected Fireworks API response format: {$response}");
		}

		return $data['data'][0]['embedding'];
	}//end extractEmbedding()
}//end class
