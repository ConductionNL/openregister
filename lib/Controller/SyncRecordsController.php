<?php

/**
 * OpenRegister SyncRecordsController
 *
 * A source's sync records and the resolution of its conflicts.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Db\SyncRecordMapperInterface;
use OCA\OpenRegister\Service\Sync\ResolutionRefused;
use OCA\OpenRegister\Service\Sync\SyncRecordResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * The conflict queue of a source, and resolving one conflict or many.
 *
 * Open to every signed-in user of the source's organisation, with the
 * per-object update check before every write: a non-admin sees only records
 * whose object they may update, and a resolution of an object the caller may
 * not update is refused with 403 and leaves the record a conflict.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
class SyncRecordsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request  The request.
	 * @param SourceMapperInterface     $sources  The sources, scoped to the caller's organisation.
	 * @param SyncRecordMapperInterface $records  The sync records.
	 * @param SyncRecordResolver        $resolver The queue and the resolutions.
	 * @param ControllerSession         $session  The caller and the admin check.
	 */
	public function __construct(
		IRequest $request,
		private readonly SourceMapperInterface $sources,
		private readonly SyncRecordMapperInterface $records,
		private readonly SyncRecordResolver $resolver,
		private readonly ControllerSession $session,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The source's latest record per external id; `status` filters, conflicts carry mappedData.
	 *
	 * @param int $id The source id.
	 *
	 * @return JSONResponse `{results: [...]}`, 401 without a user, 404 for a source outside the caller's organisation.
	 *
	 * @no-admin-idor-exempt The source is loaded through SourceMapperInterface::find(), which applies the
	 *   caller's organisation and 404s on a foreign id; each record is shown to a non-admin only when they
	 *   may update its object.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(int $id): JSONResponse {
		$source = $this->source(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		$status = trim((string)$this->request->getParam('status', ''));
		if ($status === '') {
			$status = null;
		}

		return new JSONResponse(
			data: ['results' => $this->resolver->queue(source: $source, status: $status, isAdmin: $this->session->isAdmin())]
		);
	}//end index()

	/**
	 * Resolve one conflict: `action` is keep-local, use-harvested, merge (with `fields`) or discard.
	 *
	 * @param int $id       The source id.
	 * @param int $recordId The record id.
	 *
	 * @return JSONResponse The settled record; 403, 404, 409 or 422 when refused.
	 *
	 * @no-admin-idor-exempt Organisation-scoped source lookup, the record must belong to that source, and
	 *   SyncRecordResolver::resolve() checks the caller's update right on the object before any write.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	#[NoAdminRequired]
	public function resolve(int $id, int $recordId): JSONResponse {
		$source = $this->source(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		$outcome = $this->resolveOne(source: $source, recordId: $recordId, request: $this->request->getParams(), bulk: false);
		if (isset($outcome['error']) === true) {
			return new JSONResponse(data: ['error' => $outcome['error']], statusCode: $outcome['status']);
		}

		return new JSONResponse(data: $outcome['record']);
	}//end resolve()

	/**
	 * Resolve many conflicts; each is resolved on its own and one failure rolls back none of the others.
	 *
	 * Body: `{resolutions: [{recordId, action, fields?}, ...]}`.
	 *
	 * @param int $id The source id.
	 *
	 * @return JSONResponse `{results: [{recordId, status, record|error}]}`; 422 when the body holds no list.
	 *
	 * @no-admin-idor-exempt As resolve(): the update right is checked per item before its write.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	#[NoAdminRequired]
	public function resolveBulk(int $id): JSONResponse {
		$source = $this->source(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		$items = $this->request->getParam('resolutions');
		if (is_array($items) === false || $items === []) {
			return new JSONResponse(data: ['error' => 'resolutions must be a list of {recordId, action}'], statusCode: 422);
		}

		$results = [];
		foreach (array_values($items) as $item) {
			$item     = (array)$item;
			$recordId = (int)($item['recordId'] ?? 0);
			$outcome  = $this->resolveOne(source: $source, recordId: $recordId, request: $item, bulk: true);
			$results[] = ['recordId' => $recordId] + $outcome;
		}

		return new JSONResponse(data: ['results' => $results]);
	}//end resolveBulk()

	/**
	 * Resolve one record and report its outcome instead of throwing.
	 *
	 * @param Source               $source   The source.
	 * @param int                  $recordId The record id.
	 * @param array<string, mixed> $request  The action and fields.
	 * @param bool                 $bulk     Whether it is part of a bulk resolution.
	 *
	 * @return array{status: int, record?: array<string, mixed>, error?: string}
	 */
	private function resolveOne(Source $source, int $recordId, array $request, bool $bulk): array {
		$record = $this->records->findOfSource(sourceId: (int)$source->getId(), recordId: $recordId);
		if ($record === null) {
			return ['status' => 404, 'error' => 'This source has no such record'];
		}

		try {
			$settled = $this->resolver->resolve(
				source: $source,
				record: $record,
				request: $request,
				userId: (string)$this->session->userSession->getUser()?->getUID(),
				bulk: $bulk
			);
		} catch (ResolutionRefused $refused) {
			return ['status' => $refused->status, 'error' => $refused->getMessage()];
		} catch (Throwable $e) {
			$this->session->logger->warning(
				message: '[SyncRecordsController] Resolving record '.$recordId.' failed: '.$e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return ['status' => 500, 'error' => 'The resolution failed'];
		}

		return ['status' => 200, 'record' => $settled->jsonSerialize()];
	}//end resolveOne()

	/**
	 * The source in the caller's organisation, or the refusal.
	 *
	 * @param int $id The source id.
	 *
	 * @return Source|JSONResponse
	 */
	private function source(int $id): Source|JSONResponse {
		if ($this->session->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
		}

		try {
			return $this->sources->find(id: $id);
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['error' => 'Not Found'], statusCode: 404);
		}
	}//end source()
}//end class
