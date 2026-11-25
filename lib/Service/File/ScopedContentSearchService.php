<?php

/**
 * OpenRegister Scoped Content Search Service
 *
 * Lets an app find files by a word in their extracted text, for one user and
 * within the files that app lists. Only files the user can read are searched;
 * the answer names, per file, its best rank and a short snippet with the words
 * marked, and which of the listed files have no extracted text yet.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\File
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/scoped-file-content-search/specs/scoped-file-content-search/spec.md#requirement-an-app-can-search-the-content-of-the-files-one-user-may-read-req-sfcs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Files\Db\FileChunkSearch;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keyword search over the extracted text of the files one user may read.
 *
 * @spec openspec/changes/scoped-file-content-search/specs/scoped-file-content-search/spec.md#requirement-an-app-can-search-the-content-of-the-files-one-user-may-read-req-sfcs-001
 */
class ScopedContentSearchService {

	/**
	 * The most file ids one search looks at.
	 *
	 * @var integer
	 */
	public const MAX_FILES = 500;

	/**
	 * The most chunks read per searched file.
	 *
	 * @var integer
	 */
	public const CHUNKS_PER_FILE = 5;

	/**
	 * The most characters of text around the first match in a snippet.
	 *
	 * @var integer
	 */
	public const SNIPPET_LENGTH = 160;

	/**
	 * Constructor.
	 *
	 * @param FileChunkSearch $chunks     The extracted text chunks of given files.
	 * @param IRootFolder     $rootFolder Resolves what a user can read.
	 * @param LoggerInterface $logger     Logs a file that could not be resolved.
	 */
	public function __construct(
		private readonly FileChunkSearch $chunks,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Search the extracted text of the listed files the user can read.
	 *
	 * @param string       $words   The words to look for.
	 * @param array<mixed> $fileIds The files the app lists; at most MAX_FILES are looked at.
	 * @param string       $userId  The user the search is for.
	 *
	 * @return array{results: list<array{fileId: int, rank: float, snippet: string}>, unextracted: list<int>}
	 *         One hit per file, best rank first, the snippet HTML-escaped with the words in `<mark>`;
	 *         and the readable listed files without extracted text.
	 *
	 * @spec openspec/changes/scoped-file-content-search/specs/scoped-file-content-search/spec.md#requirement-an-app-can-search-the-content-of-the-files-one-user-may-read-req-sfcs-001
	 */
	public function searchFiles(string $words, array $fileIds, string $userId): array {
		$readable = $this->readableFileIds(fileIds: $fileIds, userId: $userId);
		if ($readable === []) {
			return ['results' => [], 'unextracted' => []];
		}

		$withText    = $this->chunks->fileIdsWithChunks(fileIds: $readable);
		$unextracted = array_values(array_diff($readable, $withText));

		$words = trim($words);
		if ($words === '' || $withText === []) {
			return ['results' => [], 'unextracted' => $unextracted];
		}

		$rows = $this->chunks->searchFileChunks(
			words: $words,
			fileIds: $withText,
			limit: count($withText) * self::CHUNKS_PER_FILE
		);

		return ['results' => $this->bestPerFile(rows: $rows, words: $words), 'unextracted' => $unextracted];
	}//end searchFiles()

	/**
	 * The listed ids, as integers, that resolve to a file in the user's own view.
	 *
	 * @param array<mixed> $fileIds The ids as given.
	 * @param string       $userId  The user.
	 *
	 * @return list<int> The readable file ids, in the given order.
	 */
	private function readableFileIds(array $fileIds, string $userId): array {
		$userFolder = $this->userFolder(userId: $userId);
		if ($userFolder === null) {
			return [];
		}

		$ids = [];
		foreach (array_slice($fileIds, 0, self::MAX_FILES) as $fileId) {
			if (is_int($fileId) === true || (is_string($fileId) === true && ctype_digit($fileId) === true)) {
				$ids[(int)$fileId] = (int)$fileId;
			}
		}

		$ids = array_filter($ids, static fn (int $fileId): bool => $fileId > 0);

		return array_values(array_filter($ids, fn (int $fileId): bool => $this->isReadable(userFolder: $userFolder, fileId: $fileId)));
	}//end readableFileIds()

	/**
	 * The user's own folder, or null for no or an unknown user.
	 *
	 * @param string $userId The user.
	 *
	 * @return Folder|null The folder.
	 */
	private function userFolder(string $userId): ?Folder {
		if (trim($userId) === '') {
			return null;
		}

		try {
			return $this->rootFolder->getUserFolder($userId);
		} catch (Throwable $e) {
			$this->logger->info(
				message: '[ScopedContentSearch] No files for user {user}: {error}',
				context: ['user' => $userId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end userFolder()

	/**
	 * Whether a file id resolves to a readable node in the user's folder.
	 *
	 * @param Folder $userFolder The user's folder.
	 * @param int    $fileId     The file id.
	 *
	 * @return bool True when the user can read it.
	 */
	private function isReadable(Folder $userFolder, int $fileId): bool {
		try {
			$node = $userFolder->getFirstNodeById($fileId);
		} catch (Throwable $e) {
			return false;
		}

		return $node !== null && $node->isReadable() === true;
	}//end isReadable()

	/**
	 * One hit per file: its best-ranked chunk, as a marked snippet.
	 *
	 * @param list<array{fileId: int, rank: float, text: string}> $rows  The matching chunks, best first.
	 * @param string                                              $words The searched words.
	 *
	 * @return list<array{fileId: int, rank: float, snippet: string}> The hits, best rank first.
	 */
	private function bestPerFile(array $rows, string $words): array {
		$best = [];
		foreach ($rows as $row) {
			$fileId = $row['fileId'];
			if (isset($best[$fileId]) === true && $best[$fileId]['rank'] >= $row['rank']) {
				continue;
			}

			$best[$fileId] = ['fileId' => $fileId, 'rank' => $row['rank'], 'snippet' => self::snippet(text: $row['text'], words: $words)];
		}

		$hits = array_values($best);
		usort($hits, static fn (array $left, array $right): int => $right['rank'] <=> $left['rank']);

		return $hits;
	}//end bestPerFile()

	/**
	 * A short, HTML-escaped piece of the text around the first word found, the words in `<mark>`.
	 *
	 * @param string $text  The chunk text.
	 * @param string $words The searched words.
	 *
	 * @return string The snippet.
	 *
	 * @spec openspec/changes/scoped-file-content-search/specs/scoped-file-content-search/spec.md#requirement-an-app-can-search-the-content-of-the-files-one-user-may-read-req-sfcs-001
	 */
	public static function snippet(string $text, string $words): string {
		$text  = trim((string)preg_replace('/\s+/u', ' ', $text));
		$split = preg_split('/\s+/u', $words);
		if ($split === false) {
			$split = [];
		}

		$terms = array_values(array_filter($split, static fn (string $term): bool => $term !== ''));

		$start = 0;
		foreach ($terms as $term) {
			$found = mb_stripos($text, $term);
			if ($found !== false) {
				$start = max(0, $found - intdiv(self::SNIPPET_LENGTH, 4));
				break;
			}
		}

		$piece = mb_substr($text, $start, self::SNIPPET_LENGTH);
		if ($start > 0) {
			$piece = '…' . $piece;
		}

		if ($start + self::SNIPPET_LENGTH < mb_strlen($text)) {
			$piece .= '…';
		}

		$escaped = htmlspecialchars($piece, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		if ($terms === []) {
			return $escaped;
		}

		// One pass over all terms, longest first, so a mark is never marked again.
		$quoted = array_map(
			static fn (string $term): string => preg_quote(htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/'),
			$terms
		);
		usort($quoted, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

		return (string)preg_replace('/' . implode('|', $quoted) . '/iu', '<mark>$0</mark>', $escaped);
	}//end snippet()
}//end class
