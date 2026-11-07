<?php

/**
 * OpenRegister Retrieved Source
 *
 * One search result of a RAG context retrieval, read as a chat source.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Mcp\Service\Chat;

/**
 * One search result read as a chat source: its name, text, score and the metadata of its type.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class RetrievedSource {

	/**
	 * The search result (entity_type, entity_id, metadata and the name and text fields).
	 *
	 * @var array<string|int, mixed>
	 */
	private array $result;

	/**
	 * Constructor
	 *
	 * @param array $result The search result.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(array $result) {
		$this->result = $result;
	}//end __construct()

	/**
	 * The source: id, type, name, similarity and text, with the metadata of its type.
	 *
	 * @return array<string, mixed> The source.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function toArray(): array {
		$result = $this->result;
		$source = [
			'id' => $result['entity_id'] ?? null,
			'type' => $result['entity_type'] ?? 'unknown',
			'name' => $this->name(),
			'similarity' => $result['similarity'] ?? $result['score'] ?? 1.0,
			'text' => $result['chunk_text'] ?? $result['text'] ?? '',
		];

		return $source + $this->typeMetadata(source: $source, metadata: $result['metadata'] ?? []);
	}//end toArray()

	/**
	 * The fields a source of its type takes from the metadata: object or file fields, or none.
	 *
	 * @param array<string, mixed> $source   The source with its id and type.
	 * @param mixed                $metadata The metadata of the search result, possibly a JSON string.
	 *
	 * @return array<string, mixed> The fields of the source's type.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function typeMetadata(array $source, mixed $metadata): array {
		$metadata = ($this->decodedMetadata(metadata: $metadata) ?? []);
		return match ($source['type']) {
			'object' => $this->objectMetadata(metadata: $metadata),
			'file' => $this->fileMetadata(metadata: $metadata, fileId: $source['id']),
			default => [],
		};
	}//end typeMetadata()

	/**
	 * Metadata as stored on a hit: a JSON string is decoded, anything else is already decoded.
	 *
	 * @param mixed $metadata The metadata of a search result.
	 *
	 * @return mixed The decoded metadata; null when a JSON string does not decode.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function decodedMetadata(mixed $metadata): mixed {
		if (is_string($metadata) === true) {
			return json_decode($metadata, true);
		}

		return $metadata;
	}//end decodedMetadata()

	/**
	 * The object fields of a source, read from its metadata.
	 *
	 * @param mixed $metadata The decoded metadata.
	 *
	 * @return array<string, mixed> The uuid, register, schema and uri.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function objectMetadata(mixed $metadata): array {
		return [
			'uuid' => $metadata['uuid'] ?? null,
			'register' => $metadata['register_id'] ?? $metadata['register'] ?? null,
			'schema' => $metadata['schema_id'] ?? $metadata['schema'] ?? null,
			'uri' => $metadata['uri'] ?? null,
		];
	}//end objectMetadata()

	/**
	 * The file fields of a source, read from its metadata.
	 *
	 * @param mixed $metadata The decoded metadata.
	 * @param mixed $fileId   The file id when the metadata names none.
	 *
	 * @return array<string, mixed> The file id, path and mime type.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function fileMetadata(mixed $metadata, mixed $fileId): array {
		return [
			'file_id' => $metadata['file_id'] ?? $fileId,
			'file_path' => $metadata['file_path'] ?? null,
			'mime_type' => $metadata['mime_type'] ?? null,
		];
	}//end fileMetadata()

	/**
	 * A human-readable name: the first filled name field of the result, then of its metadata.
	 *
	 * Falls back to entity type and ID if no name is found.
	 *
	 * @return string Human-readable source name
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function name(): string {
		$result = $this->result;
		$name = $this->firstFilled(values: $result, keys: ['title', 'name', 'filename']);
		if ($name === null && empty($result['metadata']) === false) {
			$metadata = $this->decodedMetadata(metadata: $result['metadata']);
			$name = $this->firstFilled(values: $metadata, keys: ['object_title', 'file_name', 'name', 'title']);
		}

		return $name ?? $this->fallbackName();
	}//end name()

	/**
	 * The first of the keys whose value is filled (not empty).
	 *
	 * @param mixed    $values The values to look in; anything that is not an array has none.
	 * @param string[] $keys   The keys, in order of preference.
	 *
	 * @return mixed The first filled value, or null when none is.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function firstFilled(mixed $values, array $keys): mixed {
		foreach ($keys as $key) {
			if (empty($values[$key]) === false) {
				return $values[$key];
			}
		}

		return null;
	}//end firstFilled()

	/**
	 * The name of a result without a name: its type and the start of its id, or 'Unknown Source'.
	 *
	 * @return string The fallback name.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function fallbackName(): string {
		$result = $this->result;
		if (empty($result['entity_id']) === false) {
			return ucfirst($result['entity_type'] ?? 'Item') . ' #' . substr($result['entity_id'], 0, 8);
		}

		return 'Unknown Source';
	}//end fallbackName()
}//end class
