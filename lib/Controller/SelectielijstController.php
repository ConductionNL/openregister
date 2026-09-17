<?php

/**
 * OpenRegister Selectielijst Controller
 *
 * The archivist was handed a list as a file. This is where it comes in, where
 * the versions stored here are read back, and where a new one is compared with
 * the one actually in force before anybody switches to it.
 *
 * 🔴 ITS OWN CONTROLLER, NOT THREE MORE METHODS ON `ArchivalController`. That
 * class was at the configured class-length ceiling and these three took it over.
 * They are also a different surface: the destruction list is a worklist a
 * reviewer works through, and this is administration of the rules that put
 * things on it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Archival\SelectielijstImportService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Import, list and compare selectielijst versions.
 *
 * @psalm-suppress UnusedClass
 */
class SelectielijstController extends Controller {

	/**
	 * The group that may administer the archival rules.
	 */
	private const ARCHIVIST_GROUP = 'archivaris';

	/**
	 * Constructor.
	 *
	 * @param string                     $appName         The app name.
	 * @param IRequest                   $request         The request.
	 * @param SelectielijstImportService $selectielijst   Imports, versions and diffs a list.
	 * @param ObjectRetentionHandler     $settingsHandler Names the version in force.
	 * @param IUserSession               $userSession     Who is asking.
	 * @param IGroupManager              $groupManager    Whether they may.
	 * @param LoggerInterface            $logger          Reports an import that failed.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SelectielijstImportService $selectielijst,
		private readonly ObjectRetentionHandler $settingsHandler,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Import a selectielijst or classification plan from a file.
	 *
	 * POST /api/archival/selectielijst/import
	 *
	 * @return JSONResponse What was stored.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	#[NoAdminRequired]
	public function import(): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		[$contents, $filename] = $this->readSubmittedFile();
		if ($contents === null) {
			return new JSONResponse(
				data: ['error' => 'Send the selectielijst as an uploaded "file", or inline as "contents" with a "filename"'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$source = null;
		if ($filename !== '') {
			$source = $filename;
		}

		try {
			$rows = $this->selectielijst->parse(contents: $contents, filename: $filename);
			$result = $this->selectielijst->import(
				rows: $rows,
				version: (string)$this->request->getParam('version', ''),
				source: $source
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (Throwable $e) {
			$this->logger->error('[SelectielijstController] Import failed: ' . $e->getMessage());
			return new JSONResponse(
				data: ['error' => 'The selectielijst could not be imported'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
	}//end import()

	/**
	 * Which selectielijst versions are stored, and which one is applied.
	 *
	 * GET /api/archival/selectielijst/versions
	 *
	 * @return JSONResponse The versions and their row counts.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	#[NoAdminRequired]
	public function versions(): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		return new JSONResponse(
			data: [
				'versions' => $this->selectielijst->versions(),
				'inUse' => $this->versionInUse(),
			],
			statusCode: Http::STATUS_OK
		);
	}//end versions()

	/**
	 * Compare a newly imported selectielijst against the one in use.
	 *
	 * GET /api/archival/selectielijst/diff?from=&to=
	 *
	 * `from` defaults to the version in force, because comparing against what
	 * is actually applied is the question an archivist has before switching.
	 *
	 * @return JSONResponse The changed rows and what each change would do.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	#[NoAdminRequired]
	public function diff(): JSONResponse {
		$authCheck = $this->checkArchivistRole();
		if ($authCheck !== null) {
			return $authCheck;
		}

		$from = (string)$this->request->getParam('from', '');
		$to = (string)$this->request->getParam('to', '');

		if ($from === '') {
			$from = (string)$this->versionInUse();
		}

		if ($from === '' || $to === '') {
			return new JSONResponse(
				data: [
					'error' => 'Name the version to compare with "to", and the one to compare against '
						. 'with "from" or by setting the version in use',
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			return new JSONResponse(
				data: $this->selectielijst->diff(from: $from, to: $to),
				statusCode: Http::STATUS_OK
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}
	}//end diff()

	/**
	 * The file the caller sent, however they sent it.
	 *
	 * @return array{0: string|null, 1: string} The contents and the filename.
	 */
	private function readSubmittedFile(): array {
		$filename = (string)$this->request->getParam('filename', '');
		$uploaded = $this->request->getUploadedFile('file');

		if (is_array($uploaded) === true && isset($uploaded['tmp_name']) === true) {
			$path = (string)$uploaded['tmp_name'];
			$filename = (string)($uploaded['name'] ?? $filename);

			// Check readability BEFORE the read, rather than silencing the read
			// with `@`: an unreadable upload is worth reporting, and a
			// suppressed warning hides which of the two went wrong.
			if (is_readable($path) === false) {
				return [null, $filename];
			}

			$contents = file_get_contents($path);
			if ($contents === false || trim($contents) === '') {
				return [null, $filename];
			}

			return [$contents, $filename];
		}

		$inline = $this->request->getParam('contents');
		if (is_string($inline) === true && trim($inline) !== '') {
			return [$inline, $filename];
		}

		return [null, $filename];
	}//end readSubmittedFile()

	/**
	 * The selectielijst version this instance applies, or null.
	 *
	 * @return string|null The version.
	 */
	private function versionInUse(): ?string {
		try {
			$version = ($this->settingsHandler->getArchivalSettingsOnly()['selectielijstVersion'] ?? null);
		} catch (Throwable $e) {
			$this->logger->warning('[SelectielijstController] Could not read the archival settings: ' . $e->getMessage());
			return null;
		}

		if (is_string($version) === false || trim($version) === '') {
			return null;
		}

		return trim($version);
	}//end versionInUse()

	/**
	 * Refuse anybody who is not an archivist or an admin.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function checkArchivistRole(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'Niet geauthenticeerd'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$isArchivist = $this->groupManager->isInGroup($user->getUID(), self::ARCHIVIST_GROUP);
		$isAdmin = $this->groupManager->isAdmin($user->getUID());

		if ($isArchivist === false && $isAdmin === false) {
			return new JSONResponse(
				data: ['error' => 'Onvoldoende rechten: archivaris rol is vereist'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end checkArchivistRole()
}//end class
