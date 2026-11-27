<?php

/**
 * Whether a file's content was text-searched, stated on its search hits.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use Throwable;

/**
 * Marks file search hits with `textSearched`.
 *
 * A file above the extraction ceiling has one chunk, its metadata chunk
 * (index -1), whose text names `extraction_status: skipped-too-large`. Such a
 * file is found by its name and metadata but its content was never read, so
 * its hits carry `textSearched: false`; every other file hit carries `true`.
 *
 * A hit on a text chunk (index 0 or above, with its text) proves the content
 * was read; a hit on the metadata chunk is read from its own text. Any other
 * hit (a vector hit without its chunk) is looked up once per file.
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */
class TextSearchedMarker {

	/**
	 * The ceiling that kept each looked-up file closed, or 0 when it was read, by file id.
	 *
	 * @var array<string, int>
	 */
	private array $looked = [];

	/**
	 * Constructor.
	 *
	 * @param ChunkMapperInterface $chunkMapper Reads a file's chunks when a hit does not carry them.
	 */
	public function __construct(
		private readonly ChunkMapperInterface $chunkMapper,
	) {
	}//end __construct()

	/**
	 * The hits, each file hit with `textSearched`; other hits unchanged.
	 *
	 * @param array<int, mixed> $results The search hits.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-1-3
	 */
	public function mark(array $results): array {
		foreach ($results as $index => $hit) {
			if (is_array($hit) === false || ($hit['entity_type'] ?? null) !== 'file' || isset($hit['entity_id']) === false) {
				continue;
			}

			$results[$index]['textSearched'] = ($this->ceilingOfHit(hit: $hit) === null);
		}

		return $results;
	}//end mark()

	/**
	 * The ceiling that kept a file closed, from its chunks; null when its content was read.
	 *
	 * @param array<int, mixed> $chunks The file's chunks.
	 *
	 * @return int|null The ceiling in bytes, or null.
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-1-3
	 */
	public static function skippedCeiling(array $chunks): ?int {
		foreach ($chunks as $chunk) {
			if (($chunk instanceof Chunk) === true && $chunk->getChunkIndex() === -1) {
				return self::ceilingInMetadata(text: $chunk->getTextContent());
			}
		}

		return null;
	}//end skippedCeiling()

	/**
	 * The ceiling a metadata chunk's text records for a skipped file, or null.
	 *
	 * @param mixed $text The metadata chunk's text: the JSON summary of the file.
	 *
	 * @return int|null
	 */
	private static function ceilingInMetadata(mixed $text): ?int {
		if (is_string($text) === false) {
			return null;
		}

		$summary = json_decode($text, true);
		if (is_array($summary) === false || ($summary['extraction_status'] ?? null) !== ExtractionCeiling::SKIPPED_TOO_LARGE) {
			return null;
		}

		return (int) ($summary['ceiling_bytes'] ?? 0);
	}//end ceilingInMetadata()

	/**
	 * The ceiling that kept the hit's file closed, or null when its content was read.
	 *
	 * @param array<string, mixed> $hit A file hit.
	 *
	 * @return int|null
	 */
	private function ceilingOfHit(array $hit): ?int {
		$chunkIndex = ($hit['chunk_index'] ?? null);
		$chunkText  = ($hit['chunk_text'] ?? null);
		if (is_int($chunkIndex) === true && is_string($chunkText) === true) {
			if ($chunkIndex >= 0) {
				return null;
			}

			if ($chunkIndex === -1 && is_array(json_decode($chunkText, true)) === true) {
				return self::ceilingInMetadata(text: $chunkText);
			}
		}

		return $this->lookedUpCeiling(fileId: (string) $hit['entity_id']);
	}//end ceilingOfHit()

	/**
	 * The ceiling read from the file's stored chunks, once per file; null when it was read or cannot be told.
	 *
	 * @param string $fileId The file id.
	 *
	 * @return int|null
	 */
	private function lookedUpCeiling(string $fileId): ?int {
		if (array_key_exists($fileId, $this->looked) === false) {
			$ceiling = null;
			if (ctype_digit($fileId) === true) {
				try {
					$ceiling = self::skippedCeiling(chunks: $this->chunkMapper->findBySource('file', (int) $fileId));
				} catch (Throwable) {
					// A file whose chunks cannot be read is not marked as skipped: the hit itself shows text was found.
					$ceiling = null;
				}
			}

			$this->looked[$fileId] = ($ceiling ?? 0);
		}

		if ($this->looked[$fileId] === 0) {
			return null;
		}

		return $this->looked[$fileId];
	}//end lookedUpCeiling()
}//end class
