<?php

/**
 * OpenRegister FileHitSearch
 *
 * The file arm of the unified search: chunks of extracted file text that
 * match the term, each resolved to its file and to the object it is attached
 * to, kept only when the caller may read that object.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-searches-file-content-beside-object-data
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds file hits over the file-chunk table: the database path of the file arm.
 *
 * The chunk query is bounded by the page size, so a file search costs one
 * bounded chunk read plus a lookup per distinct file, whatever the size of
 * the chunk table.
 */
final class FileHitSearch {

	/**
	 * The kind of a hit from object data.
	 */
	public const KIND_OBJECT = 'object';

	/**
	 * The kind of a hit from a file's text.
	 */
	public const KIND_FILE = 'file';

	/**
	 * The backend that answers the file arm: the file-chunk table in the database.
	 */
	public const BACKEND = 'database';

	/**
	 * Characters of context on either side of the term in an excerpt.
	 */
	private const EXCERPT_CONTEXT = 60;

	/**
	 * Characters of an excerpt when the chunk does not contain the term verbatim.
	 */
	private const EXCERPT_FALLBACK = 120;

	/**
	 * Constructor.
	 *
	 * @param ChunkMapperInterface $chunks  The file-chunk table.
	 * @param FileMapperInterface  $files   The file cache: a file's name and owning object.
	 * @param MagicMapperInterface $objectMapper The objects, read with RBAC and tenancy applied.
	 * @param LoggerInterface      $logger  Where an unresolvable hit is noted.
	 */
	public function __construct(
		private readonly ChunkMapperInterface $chunks,
		private readonly FileMapperInterface $files,
		private readonly MagicMapperInterface $objectMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The file hits for a term among the given schemas, one per file, best chunk first.
	 *
	 * A chunk whose file has no owning object, whose object the caller may not
	 * read, or whose object's schema is not among the searched schemas is
	 * dropped: a file hit obeys the same RBAC and `searchable` predicate as an
	 * object hit, because it is reached only through its readable object.
	 *
	 * @param string    $term      The search term.
	 * @param list<int> $schemaIds The searchable schemas, already narrowed by the scopes.
	 * @param int       $limit     The page size; the chunk query reads at most this many chunks.
	 *
	 * @return list<array{fileId: int, fileName: string, excerpt: string, object: ObjectEntity}> The hits.
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-searches-file-content-beside-object-data
	 */
	public function search(string $term, array $schemaIds, int $limit): array {
		$term = trim($term);
		if ($term === '' || $limit <= 0 || $schemaIds === []) {
			return [];
		}

		$chunkHits = $this->chunks->searchByKeyword(
			search: new KeywordChunkSearch(query: $term, limit: $limit, filters: ['source_type' => 'file'], allowUnrankedFallback: true)
		);

		$allowed = array_flip(array_map('intval', $schemaIds));
		$hits    = [];
		foreach ($chunkHits as $chunk) {
			$fileId = (int) ($chunk['entity_id'] ?? 0);
			if (($chunk['entity_type'] ?? 'file') !== 'file' || $fileId <= 0 || isset($hits[$fileId]) === true) {
				continue;
			}

			$object = $this->readableOwner(fileId: $fileId);
			if ($object === null || isset($allowed[(int) $object->getSchema()]) === false) {
				continue;
			}

			$hits[$fileId] = [
				'fileId'   => $fileId,
				'fileName' => $this->fileName(fileId: $fileId),
				'excerpt'  => self::excerpt(text: (string) ($chunk['chunk_text'] ?? ''), term: $term),
				'object'   => $object,
			];
		}//end foreach

		return array_values($hits);
	}//end search()

	/**
	 * The object a file is attached to, when the caller may read it.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return ObjectEntity|null The object; null when there is none or it is not readable.
	 */
	private function readableOwner(int $fileId): ?ObjectEntity {
		try {
			$uuid = $this->files->findOwningObjectUuid(fileId: $fileId);
			if ($uuid === null || $uuid === '') {
				return null;
			}

			$object = $this->objectMapper->find(identifier: $uuid, _rbac: true, _multitenancy: true);
		} catch (Throwable $e) {
			$this->logger->debug(
				'[FileHitSearch] A matching file chunk was left out: its object could not be read: {error}',
				['fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($object->isSoftDeleted() === true) {
			return null;
		}

		return $object;
	}//end readableOwner()

	/**
	 * A file's name, empty when the file cache cannot say.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return string The name.
	 */
	private function fileName(int $fileId): string {
		try {
			return (string) ($this->files->getFile($fileId)['name'] ?? '');
		} catch (Throwable $e) {
			return '';
		}
	}//end fileName()

	/**
	 * The part of a chunk around the first occurrence of the term.
	 *
	 * @param string $text The chunk text.
	 * @param string $term The term.
	 *
	 * @return string The excerpt, with an ellipsis where the chunk was cut.
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-searches-file-content-beside-object-data
	 */
	public static function excerpt(string $text, string $term): string {
		$text     = trim((string) preg_replace('/\s+/u', ' ', $text));
		$position = mb_stripos($text, $term);
		if ($position === false) {
			$fragment = mb_substr($text, 0, self::EXCERPT_FALLBACK);
			if (mb_strlen($text) > self::EXCERPT_FALLBACK) {
				$fragment .= '…';
			}

			return $fragment;
		}

		$start    = max(0, ($position - self::EXCERPT_CONTEXT));
		$end      = min(mb_strlen($text), ($position + mb_strlen($term) + self::EXCERPT_CONTEXT));
		$fragment = mb_substr($text, $start, ($end - $start));
		if ($start > 0) {
			$fragment = '…'.$fragment;
		}

		if ($end < mb_strlen($text)) {
			$fragment .= '…';
		}

		return $fragment;
	}//end excerpt()
}//end class
