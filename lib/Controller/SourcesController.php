<?php

/**
 * Class SourcesController
 *
 * Controller for managing source operations in the OpenRegister app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use Doctrine\DBAL\Connection;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\SourcePayload;
use OCA\OpenRegister\Controller\Support\SourcesControllerServices;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception;
use OCP\IL10N;
use OCP\IRequest;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Class SourcesController
 *
 * Controller for managing source operations.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Resource controller: CRUD plus the
 *   sync (syncNow/syncStatus) and virtual-register (testConnection/introspect)
 *   actions all belong to the /api/sources resource surface.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Each action carries its own
 *   admin guard + error mapping; splitting the resource across controllers would
 *   duplicate the guards without reducing real complexity.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
class SourcesController extends Controller {
	/**
	 * The controller's injected services.
	 *
	 * @var SourcesControllerServices
	 */
	private readonly SourcesControllerServices $services;

	/**
	 * The source mapper
	 *
	 * @var SourceMapperInterface
	 */
	private readonly SourceMapperInterface $sourceMapper;

	/**
	 * The localization service
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * The session bundle, which answers the admin check.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * Cleans and secures a submitted source before it is persisted.
	 *
	 * @var SourcePayload
	 */
	private readonly SourcePayload $payload;

	/**
	 * Constructor for the SourcesController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param SourcesControllerServices $services The sources controller services.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		SourcesControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->services = $services;
		$this->sourceMapper = $services->mappers->sourceMapper;
		$this->l10n = $services->platform->l10n;
		$this->session = $services->session;
		$this->payload = new SourcePayload(crypto: $services->platform->crypto);
	}//end __construct()

	/**
	 * Check whether the currently authenticated user is a Nextcloud administrator.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		return $this->session->isAdmin();
	}//end isCurrentUserAdmin()

	/**
	 * Serialize a source for the response, stripping databaseUrl for non-admins.
	 *
	 * @param Source $source The source entity to serialize.
	 *
	 * @return array<string, mixed> The serialized source data.
	 */
	private function serializeSource(Source $source): array {
		$data = $source->jsonSerialize();
		if ($this->isCurrentUserAdmin() === false) {
			unset($data['databaseUrl']);
			return $data;
		}

		// Database sources: admins get the NON-SECRET connection parts back so
		// the edit UI can rehydrate (jsonSerialize only exposes the
		// `authConfigured` boolean — a custody-era default that made every UI
		// save silently wipe the connection settings). The password/secret are
		// never persisted for this type; strip defensively anyway.
		if ((string)$source->getType() === 'database') {
			$authConfig = ($source->getAuthConfig() ?? []);
			unset($authConfig['password'], $authConfig['secret']);
			$data['authConfig'] = $authConfig;
		}

		return $data;
	}//end serializeSource()

	/**
	 * Retrieves a list of all sources
	 *
	 * This method returns a JSON response containing an array of all sources in the system.
	 *
	 * @return JSONResponse A JSON response containing the list of sources
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, array{results: array<int, array<string, mixed>>}, array{}>
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function index(): JSONResponse {
		// Get request parameters for filtering and searching.
		$params = $this->request->getParams();

		// Remove special query params from filters.
		$filters = $params;
		unset($filters['_limit'], $filters['_offset'], $filters['_page'], $filters['_search'], $filters['_route']);

		// Return all sources that match the filters.
		// Strip databaseUrl from non-admin responses to prevent credential exposure.
		$limit = $this->getIntParam(params: $params, key: '_limit');
		$sources = $this->sourceMapper->findAll(
			limit: $limit,
			offset: $this->pageOffset(params: $params, limit: $limit),
			filters: $filters
		);
		return new JSONResponse(
			data: ['results' => array_map(fn (Source $src) => $this->serializeSource(source: $src), $sources)]
		);
	}//end index()

	/**
	 * The offset a list request asks for: `_page` (with `_limit`) wins over `_offset`.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param int|null $limit The requested page size.
	 *
	 * @return int|null The offset, or null when none was asked for.
	 */
	private function pageOffset(array $params, ?int $limit): ?int {
		$page = $this->getIntParam(params: $params, key: '_page');
		if ($page !== null && $limit !== null) {
			return (($page - 1) * $limit);
		}

		return $this->getIntParam(params: $params, key: '_offset');
	}//end pageOffset()

	/**
	 * Retrieves a single source by its ID
	 *
	 * This method returns a JSON response containing the details of a specific source.
	 *
	 * @param string $id The ID of the source to retrieve
	 *
	 * @return JSONResponse JSON response with source data or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function show(string $id): JSONResponse {
		try {
			// Try to find the source by ID.
			$source = $this->sourceMapper->find(id: (int)$id);
			return new JSONResponse(data: $this->serializeSource(source: $source));
		} catch (DoesNotExistException $exception) {
			// Return a 404 error if the source doesn't exist.
			return $this->fail(message: $this->l10n->t('Not Found'), status: 404);
		}
	}//end show()

	/**
	 * Creates a new source
	 *
	 * This method creates a new source based on POST data.
	 *
	 * @return JSONResponse A JSON response containing the created source
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, Source, array<never, never>>
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function create(): JSONResponse {
		$refusal = $this->adminRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$source = $this->sourceMapper->createFromArray(object: $this->payload->forCreate(data: $this->request->getParams()));
		return new JSONResponse(data: $this->serializeSource(source: $source));
	}//end create()

	/**
	 * Updates an existing source
	 *
	 * This method updates an existing source based on its ID.
	 *
	 * @param int $id The ID of the source to update
	 *
	 * @return JSONResponse A JSON response containing the updated source details
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, Source, array<never, never>>
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function update(int $id): JSONResponse {
		$refusal = $this->adminRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$data = $this->payload->forUpdate(data: $this->request->getParams());
		$source = $this->sourceMapper->updateFromArray(id: $id, object: $data);
		return new JSONResponse(data: $this->serializeSource(source: $source));
	}//end update()

	/**
	 * Patch (partially update) a source
	 *
	 * @param int $id The ID of the source to patch
	 *
	 * @return JSONResponse The updated source data
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, Source, array<never, never>>
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function patch(int $id): JSONResponse {
		return $this->update(id: $id);
	}//end patch()

	/**
	 * Deletes a source
	 *
	 * This method deletes a source based on its ID.
	 *
	 * @param int $id The ID of the source to delete
	 *
	 * @return JSONResponse An empty JSON response
	 *
	 * @throws Exception If there is an error deleting the source
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, array<never, never>, array<never, never>>
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function destroy(int $id): JSONResponse {
		$refusal = $this->adminRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		// Find the source by ID and delete it, answering with an empty body.
		$this->sourceMapper->delete($this->sourceMapper->find($id));
		return new JSONResponse(data: []);
	}//end destroy()

	/**
	 * Trigger an immediate sync for a source ("Sync Now").
	 *
	 * Admin-only and organisation-scoped: the source is loaded via
	 * SourceMapperInterface::find(), which applies the organisation filter, so a
	 * non-owning admin receives a 404 rather than acting on another tenant's
	 * source (per-object guard, no IDOR). The harvest pipeline runs inline
	 * when a transport is available; sources without a registered fetcher are
	 * rejected rather than silently dropped.
	 *
	 * @param int $id The source id to sync
	 *
	 * @return JSONResponse The sync execution summary
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Admin-gated (isCurrentUserAdmin → 403) AND
	 *   organisation-scoped: the source is loaded via SourceMapperInterface::find(),
	 *   which applies the active-organisation filter and 404s on a foreign
	 *   tenant's id, so a caller cannot trigger sync on an object outside
	 *   their organisation.
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function syncNow(int $id): JSONResponse {
		$source = $this->adminSource(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		$fetcher = $this->services->domain->fetcherRegistry->get((string)$source->getType());
		if ($fetcher === null) {
			return $this->fail(message: $this->l10n->t('No sync transport available for this source type'), status: 422);
		}

		return $this->runSync(source: $source, fetcher: $fetcher);
	}//end syncNow()

	/**
	 * Run the harvest pipeline inline and record its outcome on the source.
	 *
	 * @param Source $source The source to sync.
	 * @param SourceFetcherInterface $fetcher The transport for its type.
	 *
	 * @return JSONResponse The sync summary, or a 500 when the run failed.
	 */
	private function runSync(Source $source, SourceFetcherInterface $fetcher): JSONResponse {
		$executionId = (string)Uuid::v4();

		try {
			$summary = $this->services->domain->pipeline->run(source: $source, fetcher: $fetcher, executionId: $executionId, since: null);
			$this->recordSync(source: $source, status: (string)($summary['status'] ?? 'success'));
			return new JSONResponse(data: $summary);
		} catch (Throwable $e) {
			$this->recordSync(source: $source, status: 'failed');
			return new JSONResponse(
				data: ['error' => $this->l10n->t('Sync failed'), 'message' => $e->getMessage()],
				statusCode: 500
			);
		}//end try
	}//end runSync()

	/**
	 * Persist a sync outcome and its moment on the source.
	 *
	 * @param Source $source The synced source.
	 * @param string $status The outcome.
	 *
	 * @return void
	 */
	private function recordSync(Source $source, string $status): void {
		$source->setLastSyncStatus($status);
		$source->setLastSyncDate(new DateTime());
		$this->sourceMapper->update($source);
	}//end recordSync()

	/**
	 * Return the current sync status for a source.
	 *
	 * Organisation-scoped via SourceMapperInterface::find() (per-object guard). Any
	 * authenticated member of the owning organisation may read sync status;
	 * no credentials are exposed.
	 *
	 * @param int $id The source id
	 *
	 * @return JSONResponse The sync status payload
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Organisation-scoped read: the source is loaded via
	 *   SourceMapperInterface::find(), which applies the active-organisation filter and
	 *   404s on a foreign tenant's id. Only non-sensitive sync status is
	 *   returned (no credentials), so any authenticated member of the owning
	 *   organisation may read it.
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function syncStatus(int $id): JSONResponse {
		try {
			$source = $this->sourceMapper->find(id: $id);
		} catch (DoesNotExistException $exception) {
			return $this->fail(message: $this->l10n->t('Not Found'), status: 404);
		}

		return new JSONResponse(data: $this->syncStatusOf(source: $source));
	}//end syncStatus()

	/**
	 * The non-sensitive sync status of a source; no credentials are exposed.
	 *
	 * @param Source $source The source.
	 *
	 * @return array<string, mixed> The sync status payload.
	 */
	private function syncStatusOf(Source $source): array {
		return [
			'id' => $source->getId(),
			'uuid' => $source->getUuid(),
			'syncEnabled' => $source->getSyncEnabled(),
			'status' => ($source->getLastSyncStatus() ?? 'never'),
			'lastSyncDate' => $source->getLastSyncDate()?->format('c'),
			'syncInterval' => $source->getSyncInterval(),
		];
	}//end syncStatusOf()

	/**
	 * Test the connection to a `type: database` source.
	 *
	 * Resolves the password through the credential custody seam, opens a
	 * read-only DBAL connection and runs a trivial read. Never exposes the
	 * password. A connection failure maps to 503 (unreachable); an upstream
	 * query error maps to 502 — never a bare 500.
	 *
	 * @param int $id The source id to test.
	 *
	 * @return JSONResponse Success, or a 502/503 error with a non-sensitive message.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Admin-gated (isCurrentUserAdmin → 403) AND
	 *   organisation-scoped: the source is loaded via SourceMapperInterface::find(),
	 *   which applies the active-organisation filter and 404s on a foreign
	 *   tenant's id. The response never contains the credential value.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function testConnection(int $id): JSONResponse {
		$source = $this->databaseSource(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		try {
			$connection = $this->services->domain->connectionFactory->getConnection(source: $source);
		} catch (DbalConnectionException $exception) {
			// Fail closed: credential/driver/config problem — source unreachable.
			return $this->fail(message: $this->l10n->t('Could not connect to the database source'), status: 503);
		}

		return $this->probe(connection: $connection);
	}//end testConnection()

	/**
	 * Run a trivial read over an open connection.
	 *
	 * @param Connection $connection The read-only connection.
	 *
	 * @return JSONResponse Success, or a 502 when the source answered with an error.
	 */
	private function probe(Connection $connection): JSONResponse {
		try {
			$connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
		} catch (Throwable $exception) {
			return $this->fail(message: $this->l10n->t('The database source returned an error'), status: 502);
		}

		return new JSONResponse(data: ['success' => true]);
	}//end probe()

	/**
	 * Introspect a `type: database` source into a virtual register + schemas.
	 *
	 * Idempotent: re-running updates the existing register/schemas in place.
	 * Never exposes the password.
	 *
	 * @param int $id The source id to introspect.
	 *
	 * @return JSONResponse The introspection summary, or a 502/503 error.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Admin-gated (isCurrentUserAdmin → 403) AND
	 *   organisation-scoped: the source is loaded via SourceMapperInterface::find(),
	 *   which applies the active-organisation filter and 404s on a foreign
	 *   tenant's id. The response never contains the credential value.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function introspect(int $id): JSONResponse {
		$source = $this->databaseSource(id: $id);
		if ($source instanceof JSONResponse) {
			return $source;
		}

		try {
			$summary = $this->services->domain->introspectionService->introspect(source: $source);
		} catch (DbalConnectionException $exception) {
			return $this->fail(message: $this->l10n->t('Could not connect to the database source'), status: 503);
		} catch (Throwable $exception) {
			return $this->introspectionFailed(exception: $exception);
		}

		return new JSONResponse(data: $summary);
	}//end introspect()

	/**
	 * Log an unexpected introspection failure and answer a fixed 502.
	 *
	 * Never echo raw exception text to the client (it may carry DSN fragments
	 * or SQL); log it server-side and return a fixed message.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse The 502.
	 */
	private function introspectionFailed(Throwable $exception): JSONResponse {
		$this->session->logger->error(
			'[SourcesController] introspection failed: ' . $exception->getMessage(),
			['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception]
		);
		return $this->fail(message: $this->l10n->t('Introspection failed'), status: 502);
	}//end introspectionFailed()

	/**
	 * The admin-gated, organisation-scoped `type: database` source, or the refusal.
	 *
	 * @param int $id The source id.
	 *
	 * @return Source|JSONResponse The source, or a 403/404/422 response.
	 */
	private function databaseSource(int $id): Source|JSONResponse {
		$source = $this->adminSource(id: $id);
		if ($source instanceof JSONResponse || (string)$source->getType() === 'database') {
			return $source;
		}

		return $this->fail(message: $this->l10n->t('Source is not a database source'), status: 422);
	}//end databaseSource()

	/**
	 * The source, loaded only for an admin through the organisation-scoped mapper.
	 *
	 * @param int $id The source id.
	 *
	 * @return Source|JSONResponse The source, or a 403/404 response.
	 */
	private function adminSource(int $id): Source|JSONResponse {
		$refusal = $this->adminRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->sourceMapper->find(id: $id);
		} catch (DoesNotExistException $exception) {
			return $this->fail(message: $this->l10n->t('Not Found'), status: 404);
		}
	}//end adminSource()

	/**
	 * The 403 for a caller who is not a Nextcloud administrator, or null for an admin.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function adminRefusal(): ?JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return $this->fail(message: $this->l10n->t('Admin privileges required'), status: 403);
		}

		return null;
	}//end adminRefusal()

	/**
	 * An error response with a translated message.
	 *
	 * @param string $message The translated message.
	 * @param int $status The HTTP status.
	 *
	 * @return JSONResponse The error response.
	 */
	private function fail(string $message, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $message], statusCode: $status);
	}//end fail()

	/**
	 * Get integer parameter from params array or return null
	 *
	 * @param array<string, mixed> $params Parameters array
	 * @param string $key Parameter key
	 *
	 * @return int|null Integer value or null
	 *
	 * @spec exclude Private pagination-param helper; the registry resource-CRUD contract is owned
	 *   by retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1.
	 */
	private function getIntParam(array $params, string $key): ?int {
		if (($params[$key] ?? null) !== null) {
			return (int)$params[$key];
		}

		return null;
	}//end getIntParam()
}//end class
