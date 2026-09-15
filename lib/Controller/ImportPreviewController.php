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
use OCA\OpenRegister\BackgroundJob\ImportPreviewRunner;
use OCA\OpenRegister\Db\ImportPreview;
use OCA\OpenRegister\Db\ImportPreviewMapper;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Import\ConflictPolicy;
use OCA\OpenRegister\Service\Import\ImportPreviewRefusedException;
use OCA\OpenRegister\Service\Import\ImportPreviewService;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
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
	 * Constructor.
	 *
	 * @param string $appName Application name.
	 * @param IRequest $request HTTP request.
	 * @param ImportPreviewService $service The preview lifecycle.
	 * @param ImportPreviewMapper $previewMapper Preview persistence.
	 * @param ImportPreviewRowMapper $rowMapper Per-row decision persistence.
	 * @param SourceRowReader $reader The file reader, for hashing and staging.
	 * @param RegisterMapper $registerMapper Register lookup.
	 * @param IJobList $jobList The background queue.
	 * @param IUserSession $userSession Current-user session.
	 * @param IGroupManager $groupManager Group manager.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ImportPreviewService $service,
		private readonly ImportPreviewMapper $previewMapper,
		private readonly ImportPreviewRowMapper $rowMapper,
		private readonly SourceRowReader $reader,
		private readonly RegisterMapper $registerMapper,
		private readonly IJobList $jobList,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
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
						'id' => ConflictPolicy::CREATE_ONLY,
						'label' => 'Create only',
						'description' => 'Every row creates. A row that matches an existing object is refused.',
					],
					[
						'id' => ConflictPolicy::UPDATE_ONLY,
						'label' => 'Update only',
						'description' => 'Only matched rows are written. A row that matches nothing is skipped.',
					],
					[
						'id' => ConflictPolicy::UPSERT,
						'label' => 'Upsert',
						'description' => 'A matched row updates, an unmatched row creates.',
					],
					[
						'id' => ConflictPolicy::REFUSE_ON_CONFLICT,
						'label' => 'Refuse on conflict',
						'description' => 'A matched row is refused. Unmatched rows create.',
					],
				],
				'default' => ConflictPolicy::DEFAULT_POLICY,
				'formats' => SourceRowReader::FORMATS,
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
			state: $state,
			limit: $limit,
			offset: $offset
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

		$params = [
			'register' => $register,
			'schema' => $this->request->getParam('schema'),
			'filePath' => ($uploadedFile['tmp_name'] ?? ''),
			'sourceName' => ($uploadedFile['name'] ?? ''),
			'format' => $this->request->getParam('format'),
			'policy' => $this->request->getParam('policy'),
			'matchKey' => $this->request->getParam('matchKey'),
			'packSlug' => $this->request->getParam('mapping'),
		];

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
	}//end create()

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
			decision: $decision,
			limit: $limit,
			offset: $offset
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

		$sourceHash = $this->sourceHashFromRequest();

		try {
			$async = filter_var($this->request->getParam('async', false), FILTER_VALIDATE_BOOLEAN);

			if ($async === true) {
				$this->jobList->add(
					ImportPreviewRunner::class,
					[
						'preview_id' => (int)$preview->getId(),
						'phase' => ImportPreviewRunner::PHASE_COMMIT,
						'source_hash' => $sourceHash,
					]
				);

				return new JSONResponse(data: $preview->jsonSerialize(), statusCode: 202);
			}

			$committed = $this->service->commit(
				preview: $preview,
				sourceHash: $sourceHash,
				currentUser: $this->userSession->getUser()
			);

			return new JSONResponse(data: $committed->jsonSerialize());
		} catch (ImportPreviewRefusedException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 409);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 400);
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: 500);
		}//end try
	}//end commit()

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
			ImportPreviewRunner::class,
			[
				'preview_id' => (int)$preview->getId(),
				'phase' => ImportPreviewRunner::PHASE_PREVIEW,
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
	private function sourceHashFromRequest(): ?string {
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

		try {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		} catch (Throwable $exception) {
			return false;
		}

		foreach ($userGroups as $groupId) {
			foreach ($authorization['manage'] as $entry) {
				if (is_string($entry) === true && $entry === $groupId) {
					return true;
				}

				if (is_array($entry) === true && ($entry['group'] ?? null) === $groupId) {
					return true;
				}
			}
		}

		return false;
	}//end mayManage()

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
