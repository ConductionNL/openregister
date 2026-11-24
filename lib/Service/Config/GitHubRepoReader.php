<?php

/**
 * Reads files out of a GitHub repository at a ref: one file, the tree, or the whole package.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/store-through-federated-config
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The read half of a package on GitHub (gap G3 of store-through-federated-config).
 *
 * Every GET goes through the credential broker when the read carries a
 * credential, named for the read's app and acting user, and is retried
 * anonymously when the broker refuses it. A package is read with ONE tarball
 * download where possible: a file-by-file read of a large bundle costs one
 * call per file and runs into GitHub's rate limits. When the archive cannot be
 * read, the reader falls back to the tree and the contents API, bounded by
 * bytes and files, and says when that bound cut the package short.
 *
 * @spec openspec/changes/store-through-federated-config
 */
class GitHubRepoReader {

	/**
	 * The GitHub API host for anonymous reads.
	 */
	private const GITHUB_API = 'https://api.github.com';

	/**
	 * Where the package came from: one archive download.
	 */
	public const SOURCE_ARCHIVE = 'archive';

	/**
	 * Where the package came from: the tree and one contents read per file.
	 */
	public const SOURCE_TREE = 'tree';

	/**
	 * Where the package came from: nowhere, the repository could not be read.
	 */
	public const SOURCE_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param FederatedConfigTransport $transport The broker and the HTTP client.
	 * @param GitHubArchiveExtractor   $extractor Unpacks a tarball.
	 * @param LoggerInterface          $logger    The logger.
	 */
	public function __construct(
		private readonly FederatedConfigTransport $transport,
		private readonly GitHubArchiveExtractor $extractor,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The files of a repository a read wants, from one archive or else file by file.
	 *
	 * @param GitHubRepoRead     $read     The repository, ref and identity.
	 * @param array<int, string> $paths    Exact paths, and prefixes ending in `/`; [] wants every file.
	 * @param int                $maxBytes The most bytes the files may hold together.
	 * @param int                $maxFiles The most files a file-by-file read fetches.
	 *
	 * @return array{files: array<string, string>, truncated: bool, source: string}
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function files(GitHubRepoRead $read, array $paths, int $maxBytes, int $maxFiles): array {
		$wanted = self::matcher(paths: $paths);

		$archive = $this->archive(read: $read, wanted: $wanted, maxBytes: $maxBytes);
		if ($archive !== null) {
			return ['files' => $archive, 'truncated' => false, 'source' => self::SOURCE_ARCHIVE];
		}

		$blobs = $this->blobPaths(read: $read);
		if ($blobs === null) {
			return ['files' => [], 'truncated' => false, 'source' => self::SOURCE_NONE];
		}

		return $this->filesOneByOne(read: $read, paths: array_values(array_filter($blobs, $wanted)), maxBytes: $maxBytes, maxFiles: $maxFiles);
	}//end files()

	/**
	 * One file's contents at the read's ref, or null when it is absent or unreadable.
	 *
	 * @param GitHubRepoRead $read The repository, ref and identity.
	 * @param string         $path The file path inside the repository.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function file(GitHubRepoRead $read, string $path): ?string {
		$segments = self::pathSegments(path: $path);
		if ($segments === null) {
			return null;
		}

		$apiPath = '/contents/' . implode('/', array_map('rawurlencode', $segments));
		if ($read->ref !== null) {
			$apiPath .= '?ref=' . rawurlencode($read->ref);
		}

		$answer = $this->get(read: $read, path: $apiPath);
		$meta   = json_decode($answer['body'], true);
		if ($answer['ok'] === false || is_array($meta) === false || is_string($meta['content'] ?? null) === false) {
			return null;
		}

		$decoded = base64_decode(str_replace(["\n", "\r"], '', $meta['content']), true);
		if ($decoded === false) {
			return null;
		}

		return $decoded;
	}//end file()

	/**
	 * The paths of every file in the repository at the read's ref, or null when unreadable.
	 *
	 * @param GitHubRepoRead $read The repository, ref and identity.
	 *
	 * @return array<int, string>|null
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function blobPaths(GitHubRepoRead $read): ?array {
		$answer = $this->get(read: $read, path: '/git/trees/' . rawurlencode($read->treeRef()) . '?recursive=1');
		$tree   = json_decode($answer['body'], true);
		if ($answer['ok'] === false || is_array($tree) === false || is_array($tree['tree'] ?? null) === false) {
			return null;
		}

		$paths = [];
		foreach ($tree['tree'] as $entry) {
			if (is_array($entry) === true && ($entry['type'] ?? '') === 'blob' && is_string($entry['path'] ?? null) === true) {
				$paths[] = $entry['path'];
			}
		}

		return $paths;
	}//end blobPaths()

	/**
	 * GET a path below the read's repository: brokered with a credential, anonymously
	 * without one or when the broker refuses.
	 *
	 * @param GitHubRepoRead $read The repository and identity.
	 * @param string         $path The path below `/repos/<owner>/<name>`, with its query string.
	 *
	 * @return array{ok: bool, status: int, body: string, brokerUsed: bool}
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function get(GitHubRepoRead $read, string $path): array {
		$fullPath = $read->apiPath() . $path;
		if ($read->credentialId !== null) {
			try {
				$result = $this->transport->broker->request(
					credentialId: $read->credentialId,
					appId: $read->identity->appId,
					method: 'GET',
					path: $fullPath,
					headers: ['Accept' => 'application/vnd.github+json'],
					actingUserId: $read->identity->actingUserId
				);
				return self::answer(status: (int)($result['status'] ?? 0), body: (string)($result['body'] ?? ''), brokerUsed: true);
			} catch (Throwable $e) {
				$this->logger->debug(
					message: '[GitHubRepoReader] broker refused a read, retrying anonymously: ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		try {
			$response = $this->transport->clientService->newClient()->get(
				self::GITHUB_API . $fullPath,
				['headers' => ['Accept' => 'application/vnd.github+json'], 'http_errors' => false]
			);
			return self::answer(status: $response->getStatusCode(), body: (string)$response->getBody(), brokerUsed: false);
		} catch (Throwable $e) {
			return self::answer(status: 0, body: '', brokerUsed: false);
		}
	}//end get()

	/**
	 * The wanted files of the repository's tarball, or null when the archive is unavailable.
	 *
	 * @param GitHubRepoRead $read     The repository, ref and identity.
	 * @param callable       $wanted   Whether a path is wanted.
	 * @param int            $maxBytes The byte bound.
	 *
	 * @return array<string, string>|null
	 */
	private function archive(GitHubRepoRead $read, callable $wanted, int $maxBytes): ?array {
		$answer = $this->get(read: $read, path: '/tarball/' . rawurlencode($read->treeRef()));
		if ($answer['ok'] === false) {
			return null;
		}

		return $this->extractor->extract(body: $answer['body'], wanted: $wanted, maxBytes: $maxBytes);
	}//end archive()

	/**
	 * Read the given files one contents call each, stopping at the bounds.
	 *
	 * @param GitHubRepoRead     $read     The repository, ref and identity.
	 * @param array<int, string> $paths    The wanted file paths.
	 * @param int                $maxBytes The byte bound.
	 * @param int                $maxFiles The file bound.
	 *
	 * @return array{files: array<string, string>, truncated: bool, source: string}
	 */
	private function filesOneByOne(GitHubRepoRead $read, array $paths, int $maxBytes, int $maxFiles): array {
		sort($paths, SORT_STRING);
		$files     = [];
		$bytes     = 0;
		$truncated = false;
		foreach ($paths as $path) {
			if (count($files) >= $maxFiles) {
				$truncated = true;
				break;
			}

			$contents = $this->file(read: $read, path: $path);
			if ($contents === null) {
				continue;
			}

			if ($bytes + strlen($contents) > $maxBytes) {
				$truncated = true;
				break;
			}

			$bytes += strlen($contents);
			$files[$path] = $contents;
		}//end foreach

		if ($truncated === true) {
			$this->logger->warning(
				message: '[GitHubRepoReader] package read stopped at its bound; the files are incomplete.',
				context: ['repo' => $read->owner . '/' . $read->name, 'file' => __FILE__, 'line' => __LINE__]
			);
		}

		return ['files' => $files, 'truncated' => $truncated, 'source' => self::SOURCE_TREE];
	}//end filesOneByOne()

	/**
	 * A predicate over repository paths: exact paths, and prefixes ending in `/`; [] matches all.
	 *
	 * @param array<int, string> $paths The wanted paths and prefixes.
	 *
	 * @return callable(string): bool
	 */
	private static function matcher(array $paths): callable {
		$wanted = array_values(array_filter(array_map(static fn ($path): string => ltrim(trim((string)$path), '/'), $paths)));

		return static function (string $path) use ($wanted): bool {
			if (self::pathSegments(path: $path) === null) {
				return false;
			}

			if ($wanted === []) {
				return true;
			}

			foreach ($wanted as $entry) {
				if ($path === $entry || (str_ends_with($entry, '/') === true && str_starts_with($path, $entry) === true)) {
					return true;
				}
			}

			return false;
		};
	}//end matcher()

	/**
	 * The segments of a repository-relative path, or null when it is empty or leaves the repository.
	 *
	 * @param string $path The path.
	 *
	 * @return array<int, string>|null
	 */
	private static function pathSegments(string $path): ?array {
		if ($path === '' || str_contains($path, "\0") === true || str_contains($path, '\\') === true) {
			return null;
		}

		$segments = explode('/', ltrim($path, '/'));
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return null;
			}
		}

		return $segments;
	}//end pathSegments()

	/**
	 * A read's answer from its status and body.
	 *
	 * @param int    $status     The HTTP status, 0 when no answer came.
	 * @param string $body       The body.
	 * @param bool   $brokerUsed Whether the broker carried it.
	 *
	 * @return array{ok: bool, status: int, body: string, brokerUsed: bool}
	 */
	private static function answer(int $status, string $body, bool $brokerUsed): array {
		return ['ok' => ($status >= 200 && $status < 300), 'status' => $status, 'body' => $body, 'brokerUsed' => $brokerUsed];
	}//end answer()
}//end class
