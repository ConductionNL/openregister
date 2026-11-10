<?php

/**
 * OpenRegister Import Preview Controller
 *
 * The HTTP surface of a previewed import: the policy catalogue, taking a
 * preview, reading its counts and its per-row decisions, and committing it.
 *
 * Every route is owner-scoped, and a preview of an import into a register the
 * caller may not manage is refused before the file is read. Creating a
 * preview writes nothing to the register: the only thing it writes is the
 * record of what it would do.
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
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Import\ImportCommit;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\Controller\Support\ImportPreviewControllerServices;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapperInterface;
use OCA\OpenRegister\Db\ImportPreviewRowMapperInterface;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\Import\ConflictPolicyNames;
use OCA\OpenRegister\Service\Import\ImportPreviewRefusedException;
use OCA\OpenRegister\Service\Import\ImportPreviewServiceInterface;
use OCA\OpenRegister\Service\Import\SourceRowReaderInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * ImportPreviewController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A REST surface over the
 * preview record, its rows, the policy catalogue and the lifecycle service.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Six endpoints over one
 * resource. Splitting them across controllers to move the number under the
 * threshold would put the same ownership check in two places, which is the
 * failure the threshold exists to prevent.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor injection of
 * the six collaborators those six endpoints need.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
class ImportPreviewController extends Controller {

	/**
	 * The largest page of rows one request returns.
	 *
	 * @var int
	 */
	private const MAX_PAGE = 500;

	/**
	 * The preview lifecycle.
	 *
	 * @var ImportPreviewServiceInterface
	 */
	private readonly ImportPreviewServiceInterface $service;

	/**
	 * Preview persistence.
	 *
	 * @var ImportPreviewMapperInterface
	 */
	private readonly ImportPreviewMapperInterface $previewMapper;

	/**
	 * Per-row decision persistence.
	 *
	 * @var ImportPreviewRowMapperInterface
	 */
	private readonly ImportPreviewRowMapperInterface $rowMapper;

	/**
	 * The file reader, for hashing and staging.
	 *
	 * @var SourceRowReaderInterface
	 */
	private readonly SourceRowReaderInterface $reader;

	/**
	 * Register lookup.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * The background queue.
	 *
	 * @var IJobList
	 */
	private readonly IJobList $jobList;

	/**
	 * Current-user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param ImportPreviewControllerServices $services The import preview controller services.
	 */
	public function __construct(
		IRequest $request,
		ImportPreviewControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->service = $services->domain->service;
		$this->previewMapper = $services->mappers->previewMapper;
		$this->rowMapper = $services->mappers->rowMapper;
		$this->reader = $services->domain->reader;
		$this->registerMapper = $services->mappers->registerMapper;
		$this->jobList = $services->platform->jobList;
		$this->userSession = $services->platform->userSession;
		$this->groupManager = $services->platform->groupManager;
	}//end __construct()

	/**
	 * The conflict policies this instance understands.
	 *
	 * @return JSONResponse The catalogue.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function policies(): JSONResponse {
		if ($this->currentUid() === null) {
			return $this->authRequired();
		}

		return new JSONResponse(
			data: [
				'results' => [
					[
						'id' => ConflictPolicyNames::CREATE_ONLY,
						'label' => 'Create only',
						'description' => 'Every row creates. A row that matches an existing object is refused.',
					],
					[
						'id' => ConflictPolicyNames::UPDATE_ONLY,
						'label' => 'Update only',
						'description' => 'Only matched rows are written. A row that matches nothing is skipped.',
					],
					[
						'id' => ConflictPolicyNames::UPSERT,
						'label' => 'Upsert',
						'description' => 'A matched row updates, an unmatched row creates.',
					],
					[
						'id' => ConflictPolicyNames::REFUSE_ON_CONFLICT,
						'label' => 'Refuse on conflict',
						'description' => 'A matched row is refused. Unmatched rows create.',
					],
				],
				'default' => ConflictPolicyNames::DEFAULT_POLICY,
				'formats' => SourceRowReaderInterface::FORMATS,
			]
		);
	}//end policies()

	/**
	 * The caller's own previews, newest first.
	 *
	 * @return JSONResponse The previews.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$uid = $this->currentUid();

		if ($uid === null) {
			return $this->authRequired();
		}

		$state = $this->optionalParam(name: 'state');
		$limit = min((int)$this->request->getParam('limit', 50), self::MAX_PAGE);
		$offset = (int)$this->request->getParam('offset', 0);

		$previews = $this->previewMapper->findByActor(
			createdBy: $uid,
			query: new ListQuery(limit: $limit, offset: $offset, filters: ['state' => $state])
		);

		return new JSONResponse(data: ['results' => $previews]);
	}//end index()

	/**
	 * Take a preview of an import. Writes nothing to the register.
	 *
	 * @return JSONResponse The preview, with its counts.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		$uid = $this->currentUid();

		if ($uid === null) {
			return $this->authRequired();
		}

		$uploadedFile = $this->request->getUploadedFile('file');

		if ($uploadedFile === null) {
			return new JSONResponse(data: ['error' => 'No file uploaded'], statusCode: 400);
		}

		$registerParam = $this->request->getParam('register');
		$register = $this->findRegister(value: $registerParam);

		if ($register === null) {
			return new JSONResponse(
				data: ['error' => 'Register "'.(string)$registerParam.'" was not found'],
				statusCode: 404
			);
		}

		if ($this->mayManage(register: $register) === false) {
			return new JSONResponse(
				data: ['error' => 'User does not have permission to manage this register'],
				statusCode: 403
			);
		}

		return $this->runPreview(
			params: [
				'register' => $register,
				'schema' => $this->request->getParam('schema'),
				'filePath' => ($uploadedFile['tmp_name'] ?? ''),
				'sourceName' => ($uploadedFile['name'] ?? ''),
				'format' => $this->request->getParam('format'),
				'policy' => $this->request->getParam('policy'),
				'matchKey' => $this->request->getParam('matchKey'),
				'packSlug' => $this->request->getParam('mapping'),
			]
		);
	}//end create()

	/**
	 * Build the preview now, or stage it and queue it when the request asks for async.
	 *
	 * @param array<string, mixed> $params The preview parameters.
	 *
	 * @return JSONResponse 201 with the preview, 202 for a queued one, or 400 / 500.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function runPreview(array $params): JSONResponse {
		$async = filter_var($this->request->getParam('async', false), FILTER_VALIDATE_BOOLEAN);

		try {
			if ($async === false) {
				$preview = $this->service->preview(params: $params, currentUser: $this->userSession->getUser());

				return new JSONResponse(data: $preview->jsonSerialize(), statusCode: 201);
			}

			return $this->enqueuePreview(params: $params);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 500);
		}//end try
	}//end runPreview()

	/**
	 * One preview, with its state and its counts.
	 *
	 * @param int $id The preview id.
	 *
	 * @return JSONResponse The preview.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): JSONResponse {
		$preview = $this->ownedPreview(id: $id);

		if ($preview instanceof JSONResponse) {
			return $preview;
		}

		return new JSONResponse(data: $preview->jsonSerialize());
	}//end show()

	/**
	 * One page of a preview's per-row decisions.
	 *
	 * @param int $id The preview id.
	 *
	 * @return JSONResponse The rows.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rows(int $id): JSONResponse {
		$preview = $this->ownedPreview(id: $id);

		if ($preview instanceof JSONResponse) {
			return $preview;
		}

		$decision = $this->optionalParam(name: 'decision');
		$limit = min((int)$this->request->getParam('limit', 100), self::MAX_PAGE);
		$offset = (int)$this->request->getParam('offset', 0);

		$rows = $this->rowMapper->findByPreview(
			previewId: (int)$preview->getId(),
			query: new ListQuery(limit: $limit, offset: $offset, filters: ['decision' => $decision])
		);

		return new JSONResponse(
			data: [
				'results' => $rows,
				'total' => $preview->getTotal(),
			]
		);
	}//end rows()

	/**
	 * Apply the decisions a preview made.
	 *
	 * @param int $id The preview id.
	 *
	 * @return JSONResponse The committed preview, or the refusal.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function commit(int $id): JSONResponse {
		$preview = $this->ownedPreview(id: $id);

		if ($preview instanceof JSONResponse) {
			return $preview;
		}

		return $this->runCommit(preview: $preview, sourceHash: $this->sourceHashFromRequest());
	}//end commit()

	/**
	 * Commit the preview now, or queue the commit when the request asks for async.
	 *
	 * @param ImportPreview $preview    The caller's own preview.
	 * @param string|null   $sourceHash The source file's hash, or null when none was sent.
	 *
	 * @return JSONResponse 200 with the committed preview, 202 for a queued commit, or 409 / 400 / 500.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function runCommit(ImportPreview $preview, string|null $sourceHash): JSONResponse {
		try {
			$async = filter_var($this->request->getParam('async', false), FILTER_VALIDATE_BOOLEAN);

			if ($async === true) {
				$this->jobList->add(
					JobHandles::IMPORT_PREVIEW_RUNNER,
					[
						'preview_id' => (int)$preview->getId(),
						'phase' => JobHandles::IMPORT_PREVIEW_PHASE_COMMIT,
						'source_hash' => $sourceHash,
					]
				);

				return new JSONResponse(data: $preview->jsonSerialize(), statusCode: 202);
			}

			$committed = $this->service->commit(
				preview: $preview,
				commit: new ImportCommit(sourceHash: $sourceHash, currentUser: $this->userSession->getUser())
			);

			return new JSONResponse(data: $committed->jsonSerialize());
		} catch (ImportPreviewRefusedException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 409);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 500);
		}//end try
	}//end runCommit()

	/**
	 * Stage the upload and queue the preview, so a large file is decided off
	 * the request.
	 *
	 * @param array<string, mixed> $params The preview parameters.
	 *
	 * @return JSONResponse The pending preview.
	 */
	private function enqueuePreview(array $params): JSONResponse {
		$preview = $this->service->createPreview(
			params: $params,
			currentUser: $this->userSession->getUser()
		);

		$staged = $this->reader->stage(
			filePath: (string)$params['filePath'],
			token: (string)$preview->getUuid()
		);

		$preview->setSourcePath($staged);
		$this->previewMapper->persist(preview: $preview);

		$this->jobList->add(
			JobHandles::IMPORT_PREVIEW_RUNNER,
			[
				'preview_id' => (int)$preview->getId(),
				'phase' => JobHandles::IMPORT_PREVIEW_PHASE_PREVIEW,
			]
		);

		return new JSONResponse(data: $preview->jsonSerialize(), statusCode: 202);
	}//end enqueuePreview()

	/**
	 * The sha256 of the file this commit names, from a re-uploaded file or
	 * from an explicit parameter.
	 *
	 * @return string|null The hash, or null when the request names none.
	 */
	private function sourceHashFromRequest(): string|null {
		$uploadedFile = $this->request->getUploadedFile('file');

		if ($uploadedFile !== null && ($uploadedFile['tmp_name'] ?? '') !== '') {
			return $this->reader->hash(filePath: (string)$uploadedFile['tmp_name']);
		}

		$hash = $this->request->getParam('sourceHash');

		if ($hash === null || $hash === '') {
			return null;
		}

		return (string)$hash;
	}//end sourceHashFromRequest()

	/**
	 * Load a preview the caller may read.
	 *
	 * @param int $id The preview id.
	 *
	 * @return ImportPreview|JSONResponse The preview, or the refusal.
	 */
	private function ownedPreview(int $id): ImportPreview|JSONResponse {
		$uid = $this->currentUid();

		if ($uid === null) {
			return $this->authRequired();
		}

		try {
			$preview = $this->previewMapper->find($id);
		} catch (DoesNotExistException $exception) {
			return new JSONResponse(data: ['error' => 'Import preview not found'], statusCode: 404);
		}

		if ($preview->getCreatedBy() !== $uid && $this->groupManager->isAdmin($uid) === false) {
			return new JSONResponse(data: ['error' => 'Import preview not found'], statusCode: 404);
		}

		return $preview;
	}//end ownedPreview()

	/**
	 * Resolve the register named in the request.
	 *
	 * @param mixed $value The register reference.
	 *
	 * @return Register|null The register, or null when it resolves to nothing.
	 */
	private function findRegister(mixed $value): ?Register {
		if ($value === null || $value === '') {
			return null;
		}

		try {
			return $this->registerMapper->find($value);
		} catch (Throwable $exception) {
			return null;
		}
	}//end findRegister()

	/**
	 * Whether the current user has manage permission on a register.
	 *
	 * Default-SECURE: a register with no `manage` authorization rule can only
	 * be managed by administrators. When manage rules are present, membership
	 * of one of the listed groups grants permission (admins always pass).
	 * Mirrors `RegistersController::checkRegisterManagePermission()`.
	 *
	 * @param Register $register The register the import would write into.
	 *
	 * @return bool True when the caller may manage this register.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function mayManage(Register $register): bool {
		$user = $this->userSession->getUser();

		if ($user === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		$authorization = $register->getAuthorization();

		if (empty($authorization) === true || isset($authorization['manage']) === false) {
			return false;
		}

		return $this->inManageGroup(user: $user, entries: $authorization['manage']);
	}//end mayManage()

	/**
	 * Whether one of the user's groups is named by the register's manage rule.
	 *
	 * A group lookup that fails is a refusal.
	 *
	 * @param IUser $user    The caller.
	 * @param mixed $entries The register's `manage` entries.
	 *
	 * @return bool True when one of the user's groups is named.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function inManageGroup(IUser $user, mixed $entries): bool {
		try {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		} catch (Throwable $exception) {
			return false;
		}

		foreach ($userGroups as $groupId) {
			if ($this->entriesNameGroup(entries: $entries, groupId: $groupId) === true) {
				return true;
			}
		}

		return false;
	}//end inManageGroup()

	/**
	 * Whether any manage entry names this group.
	 *
	 * @param mixed $entries The register's `manage` entries.
	 * @param mixed $groupId One of the caller's group ids.
	 *
	 * @return bool True when an entry names the group.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function entriesNameGroup(mixed $entries, mixed $groupId): bool {
		foreach ($entries as $entry) {
			if ($this->entryNamesGroup(entry: $entry, groupId: $groupId) === true) {
				return true;
			}
		}

		return false;
	}//end entriesNameGroup()

	/**
	 * Whether one manage entry names this group, as a bare id or as `{group: id}`.
	 *
	 * @param mixed $entry   One manage entry.
	 * @param mixed $groupId One of the caller's group ids.
	 *
	 * @return bool True when the entry names the group.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function entryNamesGroup(mixed $entry, mixed $groupId): bool {
		if (is_string($entry) === true && $entry === $groupId) {
			return true;
		}

		return is_array($entry) === true && ($entry['group'] ?? null) === $groupId;
	}//end entryNamesGroup()

	/**
	 * One request parameter, with an empty string read as absent.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value, or null when the request omits it.
	 */
	private function optionalParam(string $name): ?string {
		$value = $this->request->getParam($name);

		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end optionalParam()

	/**
	 * The current user's uid, or null when nobody is signed in.
	 *
	 * @return string|null The uid.
	 */
	private function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end currentUid()

	/**
	 * The refusal for an unauthenticated caller.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function authRequired(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
	}//end authRequired()
}//end class
