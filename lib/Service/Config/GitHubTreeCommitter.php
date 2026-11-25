<?php

/**
 * Commits many files to a GitHub branch in one commit, on top of what is there.
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
use RuntimeException;
use Throwable;

/**
 * The write half of a package on GitHub (gap G4 of store-through-federated-config).
 *
 * Uses the Git Data API, never the contents API: the branch tip is resolved,
 * every file is uploaded as a blob, one tree is built on the tip's tree
 * (`base_tree`, so files outside the package survive), one commit is made on
 * the tip, and the branch is moved to it. That works on an existing repository
 * as well as a new one, and the commit sha comes back to the caller.
 *
 * A blob upload answered without a sha stops the publish at once, naming the
 * file, before any tree is built: a tree entry with an empty sha would make
 * GitHub reject the whole tree with a message that names no file.
 *
 * Every call goes through the credential broker, named for the request's app
 * and acting user; this class never holds a token.
 */
class GitHubTreeCommitter {

	/**
	 * The longest repository path accepted.
	 */
	private const MAX_PATH_LENGTH = 200;

	/**
	 * Constructor.
	 *
	 * @param FederatedConfigTransport $transport The broker.
	 * @param LoggerInterface          $logger    The logger.
	 */
	public function __construct(
		private readonly FederatedConfigTransport $transport,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Commit the files to the target branch and move the branch to the new commit.
	 *
	 * @param BrokeredPublishTarget $destination The repository, branch and broker credential.
	 * @param array<string, string> $files       Repository path => contents; an unsafe path is skipped.
	 * @param string                $message     The commit message.
	 *
	 * @return array{commit: string, files: int} The new commit's sha and how many files it wrote.
	 *
	 * @throws RuntimeException When no file is publishable or a GitHub call fails.
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function commit(BrokeredPublishTarget $destination, array $files, string $message): array {
		$entries = self::safeFiles(files: $files, logger: $this->logger);
		if ($entries === []) {
			throw new RuntimeException('GitHub publish: no file with a publishable path.');
		}

		$branch = rawurlencode($destination->target->branch);
		$repo   = '/repos/' . $destination->target->repo;

		$ref = $this->json(destination: $destination, method: 'GET', path: $repo . '/git/ref/heads/' . $branch);
		$tip = (string)($ref['object']['sha'] ?? '');
		if ($tip === '') {
			throw new RuntimeException(sprintf('GitHub publish: the branch %s has no tip to commit on.', $destination->target->branch));
		}

		$tipCommit = $this->json(destination: $destination, method: 'GET', path: $repo . '/git/commits/' . rawurlencode($tip));
		$baseTree  = (string)($tipCommit['tree']['sha'] ?? '');

		$tree = [];
		foreach ($entries as $path => $contents) {
			$sha    = $this->blob(destination: $destination, path: $path, contents: $contents);
			$tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $sha];
		}

		$treeBody = ['tree' => $tree];
		if ($baseTree !== '') {
			$treeBody['base_tree'] = $baseTree;
		}

		$treeAnswer = $this->json(destination: $destination, method: 'POST', path: $repo . '/git/trees', body: $treeBody);
		$treeSha    = $this->requiredSha(answer: $treeAnswer, what: 'tree creation');

		$commitBody   = ['message' => $message, 'tree' => $treeSha, 'parents' => [$tip]];
		$commitAnswer = $this->json(destination: $destination, method: 'POST', path: $repo . '/git/commits', body: $commitBody);
		$commit       = $this->requiredSha(answer: $commitAnswer, what: 'commit creation');

		$this->json(destination: $destination, method: 'PATCH', path: $repo . '/git/refs/heads/' . $branch, body: ['sha' => $commit]);

		return ['commit' => $commit, 'files' => count($tree)];
	}//end commit()

	/**
	 * Upload one file as a blob and return its sha, failing on the file's own path when none comes back.
	 *
	 * @param BrokeredPublishTarget $destination The repository and broker credential.
	 * @param string                $path        The file's repository path (for the message).
	 * @param string                $contents    The file contents.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When GitHub answers without a sha.
	 */
	private function blob(BrokeredPublishTarget $destination, string $path, string $contents): string {
		$answer = $this->json(
			destination: $destination,
			method: 'POST',
			path: '/repos/' . $destination->target->repo . '/git/blobs',
			body: ['content' => base64_encode($contents), 'encoding' => 'base64']
		);

		$sha = (string)($answer['sha'] ?? '');
		if ($sha === '') {
			throw new RuntimeException(sprintf('GitHub publish: the blob upload for "%s" returned no sha.', $path));
		}

		return $sha;
	}//end blob()

	/**
	 * The `sha` of an answer, or a failure naming the step.
	 *
	 * @param array<string, mixed> $answer The decoded answer.
	 * @param string               $what   The step, for the message.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the answer holds no sha.
	 */
	private function requiredSha(array $answer, string $what): string {
		$sha = (string)($answer['sha'] ?? '');
		if ($sha === '') {
			throw new RuntimeException('GitHub publish: ' . $what . ' returned no sha.');
		}

		return $sha;
	}//end requiredSha()

	/**
	 * Make one brokered call and decode its 2xx JSON answer.
	 *
	 * @param BrokeredPublishTarget     $destination The broker credential and identity.
	 * @param string                    $method      The HTTP method.
	 * @param string                    $path        The API path.
	 * @param array<string, mixed>|null $body        The JSON body, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the broker refuses or GitHub answers outside 2xx.
	 */
	private function json(BrokeredPublishTarget $destination, string $method, string $path, ?array $body = null): array {
		$encoded = null;
		if ($body !== null) {
			$encoded = (string)json_encode($body);
		}

		try {
			$answer = $this->transport->broker->request(
				credentialId: $destination->credentialId,
				appId: $destination->identity->appId,
				method: $method,
				path: $path,
				headers: ['Accept' => 'application/vnd.github+json', 'Content-Type' => 'application/json'],
				body: $encoded,
				actingUserId: $destination->identity->actingUserId
			);
		} catch (Throwable $e) {
			throw new RuntimeException(sprintf('GitHub publish: %s %s was refused: %s', $method, $path, $e->getMessage()), 0, $e);
		}

		$status = (int)($answer['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(sprintf('GitHub publish: %s %s failed (%d).', $method, $path, $status));
		}

		$decoded = json_decode((string)($answer['body'] ?? ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end json()

	/**
	 * The files whose paths stay inside the repository, sorted by path; others are skipped with a warning.
	 *
	 * @param array<mixed, mixed> $files  Path => contents.
	 * @param LoggerInterface     $logger Where a skipped path is reported.
	 *
	 * @return array<string, string>
	 */
	private static function safeFiles(array $files, LoggerInterface $logger): array {
		$safe = [];
		foreach ($files as $path => $contents) {
			$path = (string)$path;
			if (self::isSafePath(path: $path) === false) {
				$logger->warning(
					message: '[GitHubTreeCommitter] skipped a file whose path leaves the repository.',
					context: ['path' => substr($path, 0, self::MAX_PATH_LENGTH), 'file' => __FILE__, 'line' => __LINE__]
				);
				continue;
			}

			$safe[$path] = (string)$contents;
		}

		ksort($safe, SORT_STRING);
		return $safe;
	}//end safeFiles()

	/**
	 * Whether a path is relative, named segment by segment, and not too long.
	 *
	 * @param string $path The path.
	 *
	 * @return bool
	 */
	private static function isSafePath(string $path): bool {
		if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH || str_starts_with($path, '/') === true
			|| str_contains($path, '\\') === true || str_contains($path, "\0") === true
		) {
			return false;
		}

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return false;
			}
		}

		return true;
	}//end isSafePath()
}//end class
