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
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Service\Archival\SelectielijstImportServiceInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Throwable;
use OCA\OpenRegister\Controller\Support\SelectielijstControllerServices;

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
	 * The refusal of a diff that cannot tell which two versions to compare.
	 */
	private const DIFF_NEEDS_VERSIONS = 'Name the version to compare with "to", and the one to compare against '
		. 'with "from" or by setting the version in use';

	/**
	 * The user session, group manager and logger.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * Imports, versions and diffs a list.
	 *
	 * @var SelectielijstImportServiceInterface
	 */
	private readonly SelectielijstImportServiceInterface $selectielijst;

	/**
	 * Names the version in force.
	 *
	 * @var ArchivalSettingsReaderInterface
	 */
	private readonly ArchivalSettingsReaderInterface $settingsHandler;

	/**
	 * Constructor.
	 *
	 * @param string                     $appName         The app name.
	 * @param IRequest                   $request         The request.
	 * @param SelectielijstControllerServices $services The services, bundled.
	 * @param ControllerSession $session Session, group manager and logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		SelectielijstControllerServices $services,
		ControllerSession $session,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->selectielijst = $services->selectielijst;
		$this->settingsHandler = $services->settingsHandler;

		$this->session = $session;
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

		return $this->importContents(contents: $contents, filename: $filename);
	}//end import()

	/**
	 * Parse and store a submitted list.
	 *
	 * @param string $contents The file contents.
	 * @param string $filename The file name, or '' when none was given.
	 *
	 * @return JSONResponse What was stored, or a 400/500 error.
	 */
	private function importContents(string $contents, string $filename): JSONResponse {
		$source = $this->sourceName(filename: $filename);

		try {
			$rows = $this->selectielijst->parse(contents: $contents, filename: $filename);
			$result = $this->selectielijst->import(rows: $rows, version: (string)$this->request->getParam('version', ''), source: $source);
		} catch (InvalidArgumentException $e) {
			return $this->refusal(error: $e->getMessage());
		} catch (Throwable $e) {
			$this->session->logger->error('[SelectielijstController] Import failed: ' . $e->getMessage());
			return $this->refusal(error: 'The selectielijst could not be imported', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
	}//end importContents()

	/**
	 * The source a stored list records: its file name, or null when it came without one.
	 *
	 * @param string $filename The file name, or ''.
	 *
	 * @return string|null The source.
	 */
	private function sourceName(string $filename): ?string {
		if ($filename === '') {
			return null;
		}

		return $filename;
	}//end sourceName()

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

		$from = $this->fromVersion();
		$to = (string)$this->request->getParam('to', '');

		if ($from === '' || $to === '') {
			return $this->refusal(error: self::DIFF_NEEDS_VERSIONS);
		}

		return $this->compare(from: $from, to: $to);
	}//end diff()

	/**
	 * The version a diff compares against: `from`, else the version in use, else ''.
	 *
	 * @return string The version.
	 */
	private function fromVersion(): string {
		$from = (string)$this->request->getParam('from', '');
		if ($from === '') {
			$from = (string)$this->versionInUse();
		}

		return $from;
	}//end fromVersion()

	/**
	 * The diff between two stored versions.
	 *
	 * @param string $from The version compared against.
	 * @param string $to The version compared.
	 *
	 * @return JSONResponse The changed rows, or a 400 naming the missing version.
	 */
	private function compare(string $from, string $to): JSONResponse {
		try {
			return new JSONResponse(
				data: $this->selectielijst->diff(from: $from, to: $to),
				statusCode: Http::STATUS_OK
			);
		} catch (InvalidArgumentException $e) {
			return $this->refusal(error: $e->getMessage());
		}
	}//end compare()

	/**
	 * An error response with its reason.
	 *
	 * @param string $error The reason.
	 * @param int $status The HTTP status.
	 *
	 * @return JSONResponse The error response.
	 */
	private function refusal(string $error, int $status=Http::STATUS_BAD_REQUEST): JSONResponse {
		return new JSONResponse(data: ['error' => $error], statusCode: $status);
	}//end refusal()

	/**
	 * The file the caller sent, however they sent it.
	 *
	 * @return array{0: string|null, 1: string} The contents and the filename.
	 */
	private function readSubmittedFile(): array {
		$filename = (string)$this->request->getParam('filename', '');
		$uploaded = $this->request->getUploadedFile('file');

		if (is_array($uploaded) === true && isset($uploaded['tmp_name']) === true) {
			return $this->readUpload(path: (string)$uploaded['tmp_name'], filename: (string)($uploaded['name'] ?? $filename));
		}

		$inline = $this->request->getParam('contents');
		if (is_string($inline) === true && trim($inline) !== '') {
			return [$inline, $filename];
		}

		return [null, $filename];
	}//end readSubmittedFile()

	/**
	 * The contents of an uploaded file, or null when it is unreadable or blank.
	 *
	 * Readability is checked BEFORE the read, rather than silencing the read
	 * with `@`: an unreadable upload is worth reporting, and a suppressed
	 * warning hides which of the two went wrong.
	 *
	 * @param string $path The uploaded file's temporary path.
	 * @param string $filename The name it was uploaded under.
	 *
	 * @return array{0: string|null, 1: string} The contents and the filename.
	 */
	private function readUpload(string $path, string $filename): array {
		if (is_readable($path) === false) {
			return [null, $filename];
		}

		$contents = file_get_contents($path);
		if ($contents === false || trim($contents) === '') {
			return [null, $filename];
		}

		return [$contents, $filename];
	}//end readUpload()

	/**
	 * The selectielijst version this instance applies, or null.
	 *
	 * @return string|null The version.
	 */
	private function versionInUse(): ?string {
		try {
			$version = ($this->settingsHandler->getArchivalSettingsOnly()['selectielijstVersion'] ?? null);
		} catch (Throwable $e) {
			$this->session->logger->warning('[SelectielijstController] Could not read the archival settings: ' . $e->getMessage());
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
		$user = $this->session->userSession->getUser();
		if ($user === null) {
			return $this->refusal(error: 'Niet geauthenticeerd', status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->administersArchive(uid: $user->getUID()) === false) {
			return $this->refusal(error: 'Onvoldoende rechten: archivaris rol is vereist', status: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end checkArchivistRole()

	/**
	 * Whether the user is in the archivist group or is an admin.
	 *
	 * @param string $uid The user id.
	 *
	 * @return bool True when the user may administer the archival rules.
	 */
	private function administersArchive(string $uid): bool {
		$isArchivist = $this->session->groupManager->isInGroup($uid, self::ARCHIVIST_GROUP);
		$isAdmin = $this->session->groupManager->isAdmin($uid);

		return $isArchivist === true || $isAdmin === true;
	}//end administersArchive()
}//end class
