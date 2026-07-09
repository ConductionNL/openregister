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

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Symfony\Component\Uid\Uuid;
use DateTime;
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
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1
 */
class SourcesController extends Controller
{
    /**
     * Constructor for the SourcesController
     *
     * @param string                       $appName              The name of the app
     * @param IRequest                     $request              The request object
     * @param IAppConfig                   $config               The app configuration object
     * @param SourceMapper                 $sourceMapper         The source mapper
     * @param IL10N                        $l10n                 The localization service
     * @param IUserSession                 $userSession          User session for admin checks
     * @param IGroupManager                $groupManager         Group manager for admin checks
     * @param ICrypto                      $crypto               Crypto service for databaseUrl encryption
     * @param SourceFetcherRegistry        $fetcherRegistry      Resolves the transport for a source type
     * @param HarvestPipelineService       $pipeline             Harvest pipeline orchestrator
     * @param DbalConnectionFactory        $connectionFactory    Opens read-only DBAL connections for database sources
     * @param DatabaseIntrospectionService $introspectionService Introspects a database source into a virtual register
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SourceMapper $sourceMapper,
        private readonly IL10N $l10n,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ICrypto $crypto,
        private readonly SourceFetcherRegistry $fetcherRegistry,
        private readonly HarvestPipelineService $pipeline,
        private readonly DbalConnectionFactory $connectionFactory,
        private readonly DatabaseIntrospectionService $introspectionService
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Check whether the currently authenticated user is a Nextcloud administrator.
     *
     * @return bool True if a user is signed in and belongs to the admin group.
     */
    private function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end isCurrentUserAdmin()

    /**
     * Serialize a source for the response, stripping databaseUrl for non-admins.
     *
     * @param Source $source The source entity to serialize.
     *
     * @return array<string, mixed> The serialized source data.
     */
    private function serializeSource(Source $source): array
    {
        $data = $source->jsonSerialize();
        if ($this->isCurrentUserAdmin() === false) {
            unset($data['databaseUrl']);
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
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1
     */
    public function index(): JSONResponse
    {
        // Get request parameters for filtering and searching.
        $params = $this->request->getParams();

        // Extract pagination and search parameters.
        $limit  = $this->getIntParam(params: $params, key: '_limit');
        $offset = $this->getIntParam(params: $params, key: '_offset');
        $page   = $this->getIntParam(params: $params, key: '_page');
        // Note: search parameter not currently used in this endpoint
        // Convert page to offset if provided.
        if ($page !== null && $limit !== null) {
            $offset = ($page - 1) * $limit;
        }

        // Remove special query params from filters.
        $filters = $params;
        unset($filters['_limit'], $filters['_offset'], $filters['_page'], $filters['_search'], $filters['_route']);

        // Return all sources that match the filters.
        // Strip databaseUrl from non-admin responses to prevent credential exposure.
        $sources = $this->sourceMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters
        );
        return new JSONResponse(
            data: [
                'results' => array_map(fn(Source $src) => $this->serializeSource(source: $src), $sources),
            ]
        );
    }//end index()

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
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1
     */
    public function show(string $id): JSONResponse
    {
        try {
            // Try to find the source by ID.
            $source = $this->sourceMapper->find(id: (int) $id);
            return new JSONResponse(data: $this->serializeSource(source: $source));
        } catch (DoesNotExistException $exception) {
            // Return a 404 error if the source doesn't exist.
            return new JSONResponse(data: ['error' => $this->l10n->t('Not Found')], statusCode: 404);
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
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1
     */
    public function create(): JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Admin privileges required')], statusCode: 403);
        }

        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove ID if present to ensure a new record is created.
        if (($data['id'] ?? null) !== null) {
            unset($data['id']);
        }

        // Credential custody split (dbal-virtual-registers D1): LEGACY harvest
        // sources keep encrypting their full databaseUrl at rest with ICrypto;
        // `type: database` (virtual register) sources store only NON-SECRET
        // connection parts in authConfig and custody the password behind the
        // CredentialStore seam, referenced by a `credential` UUID — a plaintext
        // password is never persisted on the row.
        $data = $this->sanitizeDatabaseSourceData(data: $data);

        // Encrypt databaseUrl at rest before persisting (legacy harvest path).
        if (isset($data['databaseUrl']) === true && $data['databaseUrl'] !== null && $data['databaseUrl'] !== '') {
            $data['databaseUrl'] = $this->crypto->encrypt((string) $data['databaseUrl']);
        }

        // Create a new source from the data.
        $source = $this->sourceMapper->createFromArray(object: $data);
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
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1
     */
    public function update(int $id): JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Admin privileges required')], statusCode: 403);
        }

        // Get request parameters.
        $data = $this->request->getParams();

        // Remove internal parameters (starting with '_').
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($data[$key]);
            }
        }

        // Remove immutable fields to prevent tampering.
        unset($data['id']);
        unset($data['organisation']);
        unset($data['owner']);
        unset($data['created']);

        // Custody split for database sources — see create() (dbal-virtual-registers D1).
        $data = $this->sanitizeDatabaseSourceData(data: $data);

        // Encrypt databaseUrl at rest before persisting (legacy harvest path).
        if (isset($data['databaseUrl']) === true && $data['databaseUrl'] !== null && $data['databaseUrl'] !== '') {
            $data['databaseUrl'] = $this->crypto->encrypt((string) $data['databaseUrl']);
        }

        // Update the source with the provided data.
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
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1
     */
    public function patch(int $id): JSONResponse
    {
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
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1
     */
    public function destroy(int $id): JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Admin privileges required')], statusCode: 403);
        }

        // Find the source by ID and delete it.
        $this->sourceMapper->delete($this->sourceMapper->find($id));

        // Return an empty response.
        return new JSONResponse(data: []);
    }//end destroy()

    /**
     * Trigger an immediate sync for a source ("Sync Now").
     *
     * Admin-only and organisation-scoped: the source is loaded via
     * SourceMapper::find(), which applies the organisation filter, so a
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
     *   organisation-scoped: the source is loaded via SourceMapper::find(),
     *   which applies the active-organisation filter and 404s on a foreign
     *   tenant's id, so a caller cannot trigger sync on an object outside
     *   their organisation.
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function syncNow(int $id): JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Admin privileges required')], statusCode: 403);
        }

        try {
            $source = $this->sourceMapper->find(id: $id);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Not Found')], statusCode: 404);
        }

        $fetcher = $this->fetcherRegistry->get((string) $source->getType());
        if ($fetcher === null) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('No sync transport available for this source type')],
                statusCode: 422
            );
        }

        $executionId = (string) Uuid::v4();

        try {
            $summary = $this->pipeline->run(
                source: $source,
                fetcher: $fetcher,
                executionId: $executionId,
                since: null
            );

            $source->setLastSyncStatus((string) ($summary['status'] ?? 'success'));
            $source->setLastSyncDate(new DateTime());
            $this->sourceMapper->update($source);

            return new JSONResponse(data: $summary);
        } catch (Throwable $e) {
            $source->setLastSyncStatus('failed');
            $source->setLastSyncDate(new DateTime());
            $this->sourceMapper->update($source);

            return new JSONResponse(
                data: ['error' => $this->l10n->t('Sync failed'), 'message' => $e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end syncNow()

    /**
     * Return the current sync status for a source.
     *
     * Organisation-scoped via SourceMapper::find() (per-object guard). Any
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
     *   SourceMapper::find(), which applies the active-organisation filter and
     *   404s on a foreign tenant's id. Only non-sensitive sync status is
     *   returned (no credentials), so any authenticated member of the owning
     *   organisation may read it.
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function syncStatus(int $id): JSONResponse
    {
        try {
            $source = $this->sourceMapper->find(id: $id);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Not Found')], statusCode: 404);
        }

        $lastSyncDate = $source->getLastSyncDate();

        $formattedLastSyncDate = null;
        if ($lastSyncDate !== null) {
            $formattedLastSyncDate = $lastSyncDate->format('c');
        }

        return new JSONResponse(
            data: [
                'id'           => $source->getId(),
                'uuid'         => $source->getUuid(),
                'syncEnabled'  => $source->getSyncEnabled(),
                'status'       => ($source->getLastSyncStatus() ?? 'never'),
                'lastSyncDate' => $formattedLastSyncDate,
                'syncInterval' => $source->getSyncInterval(),
            ]
        );
    }//end syncStatus()

    /**
     * Enforce the credential custody split for `type: database` sources.
     *
     * A virtual-register database source must never persist a plaintext secret:
     * any `password`/`secret` key submitted inside `authConfig` is stripped, and
     * `databaseUrl` (the legacy ICrypto-encrypted harvest field) is cleared so
     * the two paths cannot mix. The password belongs behind the CredentialStore
     * seam, referenced by the `authConfig.credential` UUID (design D1).
     *
     * @param array<string, mixed> $data The submitted source data.
     *
     * @return array<string, mixed> The sanitised source data.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    private function sanitizeDatabaseSourceData(array $data): array
    {
        if ((string) ($data['type'] ?? '') !== 'database') {
            return $data;
        }

        if (isset($data['authConfig']) === true && is_array($data['authConfig']) === true) {
            unset($data['authConfig']['password'], $data['authConfig']['secret']);
        }

        unset($data['databaseUrl']);

        return $data;
    }//end sanitizeDatabaseSourceData()

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
     *   organisation-scoped: the source is loaded via SourceMapper::find(),
     *   which applies the active-organisation filter and 404s on a foreign
     *   tenant's id. The response never contains the credential value.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testConnection(int $id): JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Admin privileges required')], statusCode: 403);
        }

        try {
            $source = $this->sourceMapper->find(id: $id);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Not Found')], statusCode: 404);
        }

        if ((string) $source->getType() !== 'database') {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Source is not a database source')],
                statusCode: 422
            );
        }

        try {
            $connection = $this->connectionFactory->getConnection(source: $source);
        } catch (DbalConnectionException $exception) {
            // Fail closed: credential/driver/config problem — source unreachable.
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Could not connect to the database source')],
                statusCode: 503
            );
        }

        try {
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (Throwable $exception) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('The database source returned an error')],
                statusCode: 502
            );
        }

        return new JSONResponse(data: ['success' => true]);
    }//end testConnection()

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
     *   organisation-scoped: the source is loaded via SourceMapper::find(),
     *   which applies the active-organisation filter and 404s on a foreign
     *   tenant's id. The response never contains the credential value.
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function introspect(int $id): JSONResponse
    {
        if ($this->isCurrentUserAdmin() === false) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Admin privileges required')], statusCode: 403);
        }

        try {
            $source = $this->sourceMapper->find(id: $id);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l10n->t('Not Found')], statusCode: 404);
        }

        if ((string) $source->getType() !== 'database') {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Source is not a database source')],
                statusCode: 422
            );
        }

        try {
            $summary = $this->introspectionService->introspect(source: $source);
        } catch (DbalConnectionException $exception) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Could not connect to the database source')],
                statusCode: 503
            );
        } catch (Throwable $exception) {
            return new JSONResponse(
                data: ['error' => $this->l10n->t('Introspection failed'), 'message' => $exception->getMessage()],
                statusCode: 502
            );
        }

        return new JSONResponse(data: $summary);
    }//end introspect()

    /**
     * Get integer parameter from params array or return null
     *
     * @param array<string, mixed> $params Parameters array
     * @param string               $key    Parameter key
     *
     * @return int|null Integer value or null
     *
     * @spec exclude Private pagination-param helper; the registry resource-CRUD contract is owned
     *   by retrofit-2026-05-24-b-ctrl-registry-views/tasks.md#task-1.
     */
    private function getIntParam(array $params, string $key): ?int
    {
        if (($params[$key] ?? null) !== null) {
            return (int) $params[$key];
        }

        return null;
    }//end getIntParam()
}//end class
