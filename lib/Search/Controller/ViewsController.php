<?php

/**
 * ViewsController
 *
 * Controller for managing saved search views across multiple registers and schemas.
 * Provides CRUD operations for views that store search configurations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Search\Controller;

use OCA\OpenRegister\Search\Controller\Support\ViewPatch;
use OCA\OpenRegister\Search\Db\View;
use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\ViewPresentationService;
use OCA\OpenRegister\Search\Service\ViewService;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCA\OpenRegister\Exception\InvalidViewAlertException;
use OCA\OpenRegister\Search\Controller\Support\ViewsControllerServices;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenRegister\Service\Rbac\ViewerReachCheckInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing saved search views
 *
 * This controller handles operations for creating, reading, updating, and deleting
 * saved search views. Views allow users to save complex search configurations
 * including multiple registers, schemas, filters, and display settings.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 * @spec openspec/specs/faceting-configuration/spec.md
 */
class ViewsController extends Controller {

	/**
	 * The view service for managing views
	 *
	 * @var ViewService
	 */
	private ViewService $viewService;

	/**
	 * The view presentation service for kanban/calendar board data
	 *
	 * @var ViewPresentationService
	 */
	private ViewPresentationService $viewPresentationService;

	/**
	 * The logger interface
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Who is asking, and how far they reach over views.
	 *
	 * @var ViewerReachCheckInterface
	 */
	private ViewerReachCheckInterface $viewers;

	/**
	 * Constructor.
	 *
	 * @param string                  $appName  The application name.
	 * @param IRequest                $request  The current request.
	 * @param ViewsControllerServices $services The saved-views API services.
	 */
	public function __construct(string $appName, IRequest $request, ViewsControllerServices $services) {
		parent::__construct(appName: $appName, request: $request);

		$this->viewService             = $services->viewService;
		$this->viewPresentationService = $services->viewPresentationService;
		$this->logger                  = $services->logger;
		$this->viewers                 = $services->viewers;
	}//end __construct()

	/**
	 * The view behind an id, resolved as the caller's own.
	 *
	 * `ViewService::find()` takes the owner and refuses anything else with a
	 * `DoesNotExistException`, which every endpoint here answers as a 404.
	 * That is the per-object predicate for a view: a caller who does not own
	 * it gets the same answer as one asking for a view that does not exist,
	 * so no endpoint can be used to discover which view ids exist.
	 *
	 * Named rather than inlined at five call sites, so the predicate is one
	 * thing a reader can find and one thing a change has to go through.
	 *
	 * @param string $id     The view id.
	 * @param string $userId The caller.
	 *
	 * @return View The view.
	 *
	 * @throws DoesNotExistException When the view is not this caller's.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	private function requireOwnedView(string $id, string $userId): View {
		return $this->viewService->find(id: $id, owner: $userId);
	}//end requireOwnedView()

	/**
	 * The view behind an id when it reaches this caller (owned, shared with a
	 * group, public, or admin); anything else is the 404 of a missing view.
	 *
	 * @param string $id     The view id.
	 * @param string $userId The caller.
	 *
	 * @return View The view.
	 *
	 * @throws DoesNotExistException When the view does not reach this caller.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	private function requireReachableView(string $id, string $userId): View {
		$view = $this->viewService->findById(id: $id);
		if ($this->viewers->reaches(view: $view->jsonSerialize(), reach: $this->viewers->reachOf(userId: $userId)) === false) {
			throw new DoesNotExistException('View not found or access denied');
		}

		return $view;
	}//end requireReachableView()

	/**
	 * Refuse a share list that names a group that does not exist, or is malformed.
	 *
	 * Absent `sharedWith` is not refused: it leaves the shares as they are.
	 *
	 * @param array $data The request body.
	 *
	 * @return JSONResponse|null A 400 naming the findings, or null when the shares may be stored.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	private function refuseInvalidShares(array $data): ?JSONResponse {
		if (array_key_exists('sharedWith', $data) === false) {
			return null;
		}

		$findings = $this->viewers->shareFindings(sharedWith: $data['sharedWith']);
		if ($findings === []) {
			return null;
		}

		return new JSONResponse(
			data: [
				'error' => 'The view cannot be shared this way: '.implode(' ', array_column($findings, 'message')),
				'findings' => $findings,
			],
			statusCode: 400
		);
	}//end refuseInvalidShares()

	/**
	 * The answer to a refused save: the alert's own status naming its field, 400 otherwise.
	 *
	 * @param InvalidArgumentException $e The refusal.
	 *
	 * @return JSONResponse
	 */
	private function refusalFor(InvalidArgumentException $e): JSONResponse {
		if ($e instanceof InvalidViewAlertException) {
			return new JSONResponse(data: ['error' => $e->getMessage(), 'field' => $e->getField()] + $e->getDetails(), statusCode: $e->getStatus());
		}

		return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
	}//end refusalFor()

	/**
	 * Refuse an update that changes fields this caller does not own.
	 *
	 * Answers a response to RETURN, or null when the update may proceed. The refusal
	 * NAMES the fields, because a member needs to know which field was refused.
	 *
	 * Only fields whose VALUE changes are judged: the edit screen sends the
	 * whole view. The caller resolves the view with requireReachableView().
	 *
	 * @param View $view The stored view.
	 * @param string $userId The caller.
	 * @param array<string, mixed> $data The request body.
	 * @param array<string, mixed>|null $savedAs The body as the save will write it, when that differs (PUT writes `isPublic` false when omitted).
	 *
	 * @return JSONResponse|null The refusal, or null when allowed.
	 *
	 * @throws InvalidViewAlertException When the alert names a recipient this caller may not add.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	private function refuseForbiddenViewFields(View $view, string $userId, array $data, ?array $savedAs=null): ?JSONResponse {
		// Only keys that name a view property are judged, so a `_limit` or
		// routing key on the body cannot refuse an update a member may make.
		$fields = array_intersect_key(
			$data,
			array_flip(
				[
					'name',
					'description',
					'owner',
					'isPublic',
					'isDefault',
					'query',
					'presentation',
					'alert',
					'sharedWith',
				]
			)
		);

		$refused = $this->viewers->refusedFields(
			view: $view->jsonSerialize(),
			reach: $this->viewers->reachOf(userId: $userId),
			update: $fields
		);

		if ($refused === []) {
			$this->viewers->assertMayNameRecipients(view: $view->jsonSerialize(), reach: $this->viewers->reachOf(userId: $userId), data: ($savedAs ?? $data));
			return null;
		}

		return new JSONResponse(
			data: [
				'error' => 'You may not change these fields on this view: ' . implode(', ', $refused),
				'fields' => $refused,
			],
			statusCode: 403
		);
	}//end refuseForbiddenViewFields()

	/**
	 * Get all views for the current user
	 *
	 * This method retrieves all saved views that belong to the current user,
	 * as well as any public views shared by other users.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with views or error
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-8
	 */
	public function index(): JSONResponse {
		try {
			$userId = $this->viewers->currentUid();

			if (empty($userId) === true) {
				return $this->unauthenticated();
			}

			$params = $this->request->getParams();

			// Extract pagination and search parameters (for future use).
			$limit = null;
			if (($params['_limit'] ?? null) !== null) {
				$limit = (int)$params['_limit'];
			}

			$offset = null;
			if (($params['_offset'] ?? null) !== null) {
				$offset = (int)$params['_offset'];
			}

			$page = null;
			if (($params['_page'] ?? null) !== null) {
				$page = (int)$params['_page'];
			}

			// Note: search parameter not currently used in this endpoint.
			// Ledger row 9.4: the caller's own views, the ones shared with a
			// group they are in, and the public ones, each carrying the access
			// they hold on it.
			$views = $this->viewService->findAllFor(reach: $this->viewers->reachOf(userId: $userId));

			// Apply client-side pagination if parameters are provided.
			$total = count($views);
			if ($limit !== null) {
				if ($page !== null) {
					$offset = ($page - 1) * $limit;
				}

				$sliceOffset = 0;
				if ($offset !== null) {
					$sliceOffset = $offset;
				}

				$views = array_slice(array: $views, offset: $sliceOffset, length: $limit);
			}

			return new JSONResponse(
				data: [
					'results' => array_map(fn ($view) => $view->jsonSerialize(), $views),
					'total' => $total,
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ViewsController] Error fetching views',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: [
					'error' => 'Failed to fetch views',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end index()

	/**
	 * Get a specific view by ID
	 *
	 * @param string $id The view ID (UUID or numeric ID)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with view or error
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-8
	 */
	public function show(string $id): JSONResponse {
		try {
			$userId = $this->viewers->currentUid();

			if (empty($userId) === true) {
				return $this->unauthenticated();
			}

			$view = $this->requireOwnedView(id: $id, userId: $userId);

			return new JSONResponse(
				data: [
					'view' => $view->jsonSerialize(),
				]
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(
				data: [
					'error' => 'View not found',
				],
				statusCode: 404
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ViewsController] Error fetching view',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: [
					'error' => 'Failed to fetch view',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end show()

	/**
	 * Create a new view
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with created view or error
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-8
	 */
	public function create(): JSONResponse {
		try {
			return $this->createView();
		} catch (InvalidArgumentException $e) {
			return $this->refusalFor(e: $e);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ViewsController] Error creating view',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(data: ['error' => 'Failed to create view', 'message' => $e->getMessage()], statusCode: 500);
		}
	}//end create()

	/**
	 * Create a view owned by the caller, after the body checks.
	 *
	 * @return JSONResponse The created view, or the refusal.
	 */
	private function createView(): JSONResponse {
		$userId = $this->viewers->currentUid();
		if (empty($userId) === true) {
			return $this->unauthenticated();
		}

		$write       = new ViewPatch();
		$write->data = $this->request->getParams();
		return $this->refuseInvalidWrite(write: $write) ?? $this->saveNew(userId: $userId, write: $write);
	}//end createView()

	/**
	 * Save a new view for its owner, once the owner may name its recipients.
	 *
	 * @param string    $userId The owner.
	 * @param ViewPatch $write  The request body.
	 *
	 * @return JSONResponse The created view (201).
	 */
	private function saveNew(string $userId, ViewPatch $write): JSONResponse {
		$data = $write->data;
		$this->viewers->assertMayNameRecipients(view: ['owner' => $userId], reach: $this->viewers->reachOf(userId: $userId), data: $data);
		$view = $this->viewService->create(
			name: $data['name'],
			description: $data['description'] ?? '',
			owner: $userId,
			isPublic: $data['isPublic'] ?? false,
			isDefault: $data['isDefault'] ?? false,
			query: $write->query(),
			presentation: $write->presentation(),
			sharedWith: $write->shares(),
			alert: ViewAlert::declaredIn(body: $data)
		);
		return new JSONResponse(data: ['view' => $view->jsonSerialize()], statusCode: 201);
	}//end saveNew()

	/**
	 * Refuse a create or full update without a name, without a query, or with invalid shares.
	 *
	 * @param ViewPatch $write The request body.
	 *
	 * @return JSONResponse|null The refusal, or null when the body may be saved.
	 */
	private function refuseInvalidWrite(ViewPatch $write): ?JSONResponse {
		$missing = $write->missingField();
		if ($missing !== null) {
			return new JSONResponse(data: ['error' => $missing], statusCode: 400);
		}

		return $this->refuseInvalidShares(data: $write->data);
	}//end refuseInvalidWrite()

	/**
	 * Update an existing view
	 *
	 * @param string $id The view ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated view or error
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-8
	 */
	public function update(string $id): JSONResponse {
		try {
			return $this->updateView(id: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'View not found'], statusCode: 404);
		} catch (InvalidArgumentException $e) {
			return $this->refusalFor(e: $e);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ViewsController] Error updating view',
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(data: ['error' => 'Failed to update view', 'message' => $e->getMessage()], statusCode: 500);
		}
	}//end update()

	/**
	 * Replace a view the caller reaches, after the field guard and the body checks.
	 *
	 * The field guard runs before anything is saved: without it any caller reaching a
	 * public view could rename it, rewrite its query or un-publish it. The owner and an
	 * administrator pass it.
	 *
	 * @param string $id View ID.
	 *
	 * @return JSONResponse The updated view, or the refusal.
	 */
	private function updateView(string $id): JSONResponse {
		$userId = $this->viewers->currentUid();
		if (empty($userId) === true) {
			return $this->unauthenticated();
		}

		$update       = new ViewPatch();
		$update->id   = $id;
		$update->data = $this->request->getParams();
		$update->view = $this->requireReachableView(id: $id, userId: $userId);
		$savedAs      = ($update->data + ['isPublic' => false]);
		$refusal      = $this->refuseForbiddenViewFields(view: $update->view, userId: $userId, data: $update->data, savedAs: $savedAs);
		return $refusal ?? $this->refuseInvalidWrite(write: $update) ?? $this->saveUpdate(update: $update);
	}//end updateView()

	/**
	 * Save a full update; a write member saves the owner's view.
	 *
	 * @param ViewPatch $update The route id, stored view and request body.
	 *
	 * @return JSONResponse The updated view.
	 */
	private function saveUpdate(ViewPatch $update): JSONResponse {
		$data = $update->data;
		$view = $this->viewService->update(
			id: $update->id,
			name: $data['name'],
			description: $data['description'] ?? '',
			owner: $update->view->getOwner(),
			isPublic: $data['isPublic'] ?? false,
			isDefault: $data['isDefault'] ?? false,
			query: $update->query(),
			presentation: $update->presentation(),
			sharedWith: $update->shares(),
			alert: ViewAlert::declaredIn(body: $data)
		);
		return new JSONResponse(data: ['view' => $view->jsonSerialize()]);
	}//end saveUpdate()

	/**
	 * The 401 answer for a request without a session user.
	 *
	 * @return JSONResponse The 401 response.
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(data: ['error' => 'User not authenticated'], statusCode: 401);
	}//end unauthenticated()

	/**
	 * Patch view details (partial update)
	 *
	 * Updates only the fields provided in the request.
	 * This is different from PUT (update) which requires all fields.
	 *
	 * @param string $id View ID.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with patched view or error
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-8
	 */
	public function patch(string $id): JSONResponse {
		try {
			return $this->patchView(id: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'View not found'], statusCode: 404);
		} catch (InvalidArgumentException $e) {
			return $this->refusalFor(e: $e);
		} catch (\Exception $e) {
			return $this->patchFailed(id: $id, e: $e);
		}//end try
	}//end patch()

	/**
	 * Patch a view the caller reaches, after the field guard.
	 *
	 * @param string $id View ID.
	 *
	 * @return JSONResponse The patched view, or the refusal.
	 */
	private function patchView(string $id): JSONResponse {
		$userId = $this->viewers->currentUid();
		if (empty($userId) === true) {
			return $this->unauthenticated();
		}

		$patch       = new ViewPatch();
		$patch->id   = $id;
		$patch->view = $this->requireReachableView(id: $id, userId: $userId);
		$patch->data = $this->request->getParams();

		// The same guard as `update()`. Leaving it off here would have
		// left the hole open behind a different verb, and this method
		// additionally carries `@NoCSRFRequired`.
		$refusal = $this->refuseForbiddenViewFields(view: $patch->view, userId: $userId, data: $patch->data);
		return $refusal ?? $this->savePatch(patch: $patch);
	}//end patchView()

	/**
	 * Save the patch, keeping the stored value of every field the request leaves out.
	 *
	 * @param ViewPatch $patch The route id, stored view and request body.
	 *
	 * @return JSONResponse The patched view, or the share refusal.
	 */
	private function savePatch(ViewPatch $patch): JSONResponse {
		$view         = $patch->view;
		$data         = $patch->data;
		$query        = $patch->query() ?? ($view->getQuery() ?? []);
		$presentation = $patch->presentation() ?? $view->getPresentation();
		$shareRefusal = $this->refuseInvalidShares(data: $data);
		if ($shareRefusal !== null) {
			return $shareRefusal;
		}

		$updatedView = $this->viewService->update(
			id: $patch->id,
			name: $data['name'] ?? $view->getName() ?? '',
			description: $data['description'] ?? $view->getDescription() ?? '',
			owner: $view->getOwner(),
			isPublic: $data['isPublic'] ?? $view->getIsPublic(),
			isDefault: $data['isDefault'] ?? $view->getIsDefault(),
			query: $query,
			favoredBy: $data['favoredBy'] ?? $view->getFavoredBy(),
			presentation: $presentation,
			sharedWith: $patch->shares(),
			alert: ViewAlert::declaredIn(body: $data)
		);
		return new JSONResponse(data: ['view' => $updatedView->jsonSerialize()]);
	}//end savePatch()

	/**
	 * Log a failed patch and answer 500.
	 *
	 * @param string     $id View ID.
	 * @param \Exception $e  The failure.
	 *
	 * @return JSONResponse The 500 response.
	 */
	private function patchFailed(string $id, \Exception $e): JSONResponse {
		$this->logger->error(
			message: '[ViewsController] Error patching view',
			context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'exception' => $e->getMessage()]
		);
		return new JSONResponse(data: ['error' => 'Failed to patch view', 'message' => $e->getMessage()], statusCode: 500);
	}//end patchFailed()

	/**
	 * Delete a view
	 *
	 * @param string $id The view ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with delete confirmation or error
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-8
	 */
	public function destroy(string $id): JSONResponse {
		try {
			$userId = $this->viewers->currentUid();

			if (empty($userId) === true) {
				return $this->unauthenticated();
			}

			// The service refuses everybody who neither owns the view nor administers the instance.
			$reach = $this->viewers->reachOf(userId: $userId);
			$this->viewService->delete(id: $id, reach: $reach);

			return new JSONResponse(
				data: [
					'message' => 'View deleted successfully',
				],
				statusCode: 204
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(
				data: [
					'error' => 'View not found',
				],
				statusCode: 404
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ViewsController] Error deleting view',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: [
					'error' => 'Failed to delete view',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end destroy()

	/**
	 * Get the kanban board (columns + paginated cards) for a kanban view
	 *
	 * Read-only: this endpoint only derives columns and fetches cards. There
	 * is deliberately NO "move card" write here — moving a card between
	 * columns MUST go through the existing guarded object PATCH/PUT API
	 * (RBAC + `x-openregister-lifecycle`), never a bespoke endpoint
	 * (REQ-VIEW-KANBAN-03).
	 *
	 * @param string $id The view ID (UUID or numeric ID)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with kanban board data or error
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
	 */
	public function kanban(string $id): JSONResponse {
		try {
			$userId = $this->viewers->currentUid();

			if (empty($userId) === true) {
				return $this->unauthenticated();
			}

			$view = $this->requireOwnedView(id: $id, userId: $userId);
			$board = $this->viewPresentationService->getKanbanBoard(
				view: $view,
				requestParams: $this->request->getParams()
			);

			return new JSONResponse(data: $board);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(
				data: [
					'error' => 'View not found',
				],
				statusCode: 404
			);
		} catch (InvalidArgumentException $e) {
			return $this->refusalFor(e: $e);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ViewsController] Error building kanban board',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: [
					'error' => 'Failed to build kanban board',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end kanban()

	/**
	 * Get the objects for a calendar view within a visible date range
	 *
	 * @param string $id The view ID (UUID or numeric ID)
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with calendar range data or error
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-calendar-plots-objects-by-a-date-field-over-a-range-req-view-cal-04
	 */
	public function calendar(string $id): JSONResponse {
		try {
			$userId = $this->viewers->currentUid();

			if (empty($userId) === true) {
				return $this->unauthenticated();
			}

			$params = $this->request->getParams();
			$rangeStart = $params['start'] ?? $params['rangeStart'] ?? null;
			$rangeEnd = $params['end'] ?? $params['rangeEnd'] ?? null;

			if (empty($rangeStart) === true || empty($rangeEnd) === true) {
				return new JSONResponse(
					data: [
						'error' => 'Both start and end (the visible date range) are required',
					],
					statusCode: 400
				);
			}

			$view = $this->requireOwnedView(id: $id, userId: $userId);
			// The whole request is deliberately NOT handed on. The service
			// took a `$requestParams` array it `unset()` on its first line,
			// reserved for a filter passthrough nobody wrote, and an argument
			// that is thrown away is an argument a reader has to check before
			// they can rule it out.
			$result = $this->viewPresentationService->getCalendarObjects(
				view: $view,
				rangeStart: $rangeStart,
				rangeEnd: $rangeEnd
			);

			return new JSONResponse(data: $result);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(
				data: [
					'error' => 'View not found',
				],
				statusCode: 404
			);
		} catch (InvalidArgumentException $e) {
			return $this->refusalFor(e: $e);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ViewsController] Error querying calendar range',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				data: [
					'error' => 'Failed to query calendar range',
					'message' => $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end calendar()
}//end class
