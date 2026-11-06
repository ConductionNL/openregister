<?php

/**
 * OpenRegister register publisher for GitHub
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Publishes a register's OpenAPI file to GitHub.
 *
 * Who may publish is decided by the controller.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-5
 */
final class RegisterGitHubPublisher {
	/**
	 * Keep the request, session and services the publisher reads.
	 *
	 * @param IRequest                    $request  The request naming the target.
	 * @param ControllerSession           $session  The session, for the logger.
	 * @param RegistersControllerServices $services The controller's services.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-5
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly ControllerSession $session,
		private readonly RegistersControllerServices $services,
	) {
	}//end __construct()

	/**
	 * Publish a register to the target the request names.
	 *
	 * @param Register $register The register.
	 *
	 * @return JSONResponse The commit and branch notes, or 400 without an owner and repo.
	 *
	 * @throws Exception When GitHub refuses the file.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-5
	 */
	public function publish(Register $register): JSONResponse {
		$target = $this->target(register: $register);
		if (empty($target['owner']) === true || empty($target['repo']) === true) {
			return new JSONResponse(data: ['error' => 'Owner and repo parameters are required'], statusCode: 400);
		}

		$this->logStart(register: $register, target: $target);
		$result = $this->pushOas(register: $register, target: $target);
		$notes  = $this->branchNotes(target: $target, defaultBranch: $this->defaultBranch(target: $target));
		return new JSONResponse(
			data: ['success' => true, 'message' => $notes['message'], 'registerId' => $register->getId()]
				+ ['commit_sha' => $result['commit_sha'], 'commit_url' => $result['commit_url'], 'file_url' => $result['file_url']] + $notes,
			statusCode: 200
		);
	}//end publish()

	/**
	 * The answer when the register to publish does not exist.
	 *
	 * @param int $id The register id.
	 *
	 * @return JSONResponse The 404.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-5
	 */
	public function registerMissing(int $id): JSONResponse {
		$this->session->logger->error(
			message: '[RegistersController] Register not found for publishing',
			context: ['file' => __FILE__, 'line' => __LINE__, 'register_id' => $id]
		);
		return new JSONResponse(data: ['error' => 'Register not found'], statusCode: 404);
	}//end registerMissing()

	/**
	 * The answer when publishing failed.
	 *
	 * @param Exception $error The failure.
	 *
	 * @return JSONResponse The 500.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-5
	 */
	public function failure(Exception $error): JSONResponse {
		$this->session->logger->error(
			message: '[RegistersController] Failed to publish register OAS to GitHub: ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return new JSONResponse(data: ['error' => 'Failed to publish register OAS: ' . $error->getMessage()], statusCode: 500);
	}//end failure()

	/**
	 * Commit a register's OpenAPI file to the target.
	 *
	 * The file is a pure OAS file, not a configuration file: no x-openregister metadata.
	 *
	 * @param Register                                                                                $register The register.
	 * @param array{owner: string, repo: string, path: string, branch: string, commitMessage: string} $target   The target.
	 *
	 * @return array<string, mixed> The commit GitHub reports.
	 */
	private function pushOas(Register $register, array $target): array {
		$oasData = $this->services->integrations->oasService->createOas((string) $register->getId());
		$result  = $this->services->integrations->githubService->publishConfiguration(
			owner: $target['owner'],
			repo: $target['repo'],
			path: $target['path'],
			branch: $target['branch'],
			content: json_encode($oasData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			commitMessage: $target['commitMessage'],
			fileSha: $this->fileSha(target: $target)
		);
		$this->logPushed(register: $register, target: $target + ['file_url' => $result['file_url'] ?? null]);
		return $result;
	}//end pushOas()

	/**
	 * Log the start of a publish.
	 *
	 * @param Register             $register The register.
	 * @param array<string, mixed> $target   The target.
	 *
	 * @return void
	 */
	private function logStart(Register $register, array $target): void {
		$this->session->logger->info(
			message: '[RegistersController] Publishing register OAS to GitHub',
			context: ['file' => __FILE__, 'line' => __LINE__, 'register_id' => $register->getId(), 'register_slug' => $register->getSlug()]
				+ array_intersect_key($target, array_flip(['owner', 'repo', 'path', 'branch']))
		);
	}//end logStart()

	/**
	 * Log a finished push.
	 *
	 * @param Register             $register The register.
	 * @param array<string, mixed> $target   The target and the file url.
	 *
	 * @return void
	 */
	private function logPushed(Register $register, array $target): void {
		$this->session->logger->info(
			message: "[RegistersController] Successfully published register OAS {$register->getTitle()} to GitHub",
			context: ['file' => __FILE__, 'line' => __LINE__] + array_intersect_key($target, array_flip(['owner', 'repo', 'branch', 'path', 'file_url']))
		);
	}//end logPushed()

	/**
	 * Where to publish a register's OAS, from the request.
	 *
	 * The path loses its leading slash and defaults to `<slug>_openregister.json`.
	 *
	 * @param Register $register The register to publish.
	 *
	 * @return array{owner: string, repo: string, path: string, branch: string, commitMessage: string} The target.
	 */
	private function target(Register $register): array {
		$data = $this->request->getParams();
		$path = ltrim($data['path'] ?? '', '/');
		if (empty($path) === true) {
			$path = ($register->getSlug() ?? 'register') . '_openregister.json';
		}

		return [
			'owner'         => $data['owner'] ?? '',
			'repo'          => $data['repo'] ?? '',
			'path'          => $path,
			'branch'        => $data['branch'] ?? 'main',
			'commitMessage' => $data['commitMessage'] ?? "Update register OAS: {$register->getTitle()}",
		];
	}//end target()

	/**
	 * The sha of the file at the publish target, null when it does not exist yet.
	 *
	 * @param array{owner: string, repo: string, path: string, branch: string} $target The publish target.
	 *
	 * @return string|null The sha.
	 */
	private function fileSha(array $target): ?string {
		try {
			$client = $this->services->integrations->githubService;
			return $client->getFileSha(owner: $target['owner'], repo: $target['repo'], path: $target['path'], branch: $target['branch']);
		} catch (Exception $e) {
			$this->session->logger->debug(
				message: '[RegistersController] File does not exist, will create new file',
				context: ['file' => __FILE__, 'line' => __LINE__, 'path' => $target['path']]
			);
			return null;
		}
	}//end fileSha()

	/**
	 * The default branch of the target repository, null when GitHub could not tell.
	 *
	 * @param array{owner: string, repo: string} $target The publish target.
	 *
	 * @return string|null The default branch.
	 */
	private function defaultBranch(array $target): ?string {
		try {
			$repoInfo = $this->services->integrations->githubService->getRepositoryInfo(owner: $target['owner'], repo: $target['repo']);
			return $repoInfo['default_branch'] ?? 'main';
		} catch (Exception $e) {
			$this->session->logger->warning(
				message: '[RegistersController] Could not fetch repository default branch',
				context: ['file' => __FILE__, 'line' => __LINE__, 'owner' => $target['owner'], 'repo' => $target['repo'], 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end defaultBranch()

	/**
	 * The branch part of a publish response, with its Code Search notes.
	 *
	 * @param array{branch: string} $target        The publish target.
	 * @param string|null           $defaultBranch The repository's default branch, if known.
	 *
	 * @return array{message: string, branch: string, default_branch: string|null, indexing_note: string} The fields.
	 */
	private function branchNotes(array $target, ?string $defaultBranch): array {
		return [
			'message'        => $this->publishMessage(branch: $target['branch'], defaultBranch: $defaultBranch),
			'branch'         => $target['branch'],
			'default_branch' => $defaultBranch,
			'indexing_note'  => $this->indexingNote(branch: $target['branch'], defaultBranch: $defaultBranch),
		];
	}//end branchNotes()

	/**
	 * The publish message; GitHub Code Search primarily indexes the default branch, so a publish elsewhere says so.
	 *
	 * @param string      $branch        The branch published to.
	 * @param string|null $defaultBranch The repository's default branch, if known.
	 *
	 * @return string The message.
	 */
	private function publishMessage(string $branch, ?string $defaultBranch): string {
		if ($defaultBranch === null || $defaultBranch === '' || $branch === $defaultBranch) {
			return 'Register OAS published successfully to GitHub. Note: GitHub Code Search may take a few minutes to index new files.';
		}

		return 'Register OAS published successfully to GitHub'
			. ". Note: Published to branch '{$branch}' (default is '{$defaultBranch}')."
			. ' GitHub Code Search primarily indexes the default branch. This may not appear in search results immediately.';
	}//end publishMessage()

	/**
	 * The note on when the file can be found.
	 *
	 * @param string      $branch        The branch published to.
	 * @param string|null $defaultBranch The repository's default branch, if known.
	 *
	 * @return string The note.
	 */
	private function indexingNote(string $branch, ?string $defaultBranch): string {
		if ($defaultBranch === null || $branch === $defaultBranch) {
			return 'File published successfully. GitHub Code Search indexing may take a few minutes.';
		}

		return "Published to non-default branch. For discovery, publish to '{$defaultBranch}' branch.";
	}//end indexingNote()
}//end class
