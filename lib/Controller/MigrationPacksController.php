<?php

/**
 * OpenRegister Migration Packs Controller
 *
 * REST CRUD for MigrationPack rows plus JSON file import/export so packs can
 * be shared between instances. Reads (`index`/`show`/`export`) are available
 * to any authenticated user — the import endpoint's `packId` parameter needs
 * to resolve a pack for any user with manage-permission on the target
 * register, not just admins. Mutations (`create`/`update`/`destroy`/`import`)
 * are admin-gated, mirroring `ConfigurationsController::import()`'s
 * admin-only gate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Service\MigrationPackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * MigrationPacksController handles CRUD + JSON import/export for migration packs.
 *
 * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
 */
class MigrationPacksController extends Controller
{
    /**
     * Constructor.
     *
     * @param string               $appName      Application name.
     * @param IRequest             $request      HTTP request.
     * @param MigrationPackService $service      Business logic.
     * @param IUserSession         $userSession  Current-user session.
     * @param IGroupManager        $groupManager Group manager (admin check).
     * @param LoggerInterface      $logger       Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MigrationPackService $service,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Resolve the current user's uid, or null when anonymous.
     *
     * @return ?string
     */
    private function resolveUserId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end resolveUserId()

    /**
     * Whether the current user is a Nextcloud administrator.
     *
     * @return bool
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
     * Build a 401 response for anonymous callers.
     *
     * @return JSONResponse
     */
    private function authRequiredResponse(): JSONResponse
    {
        return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
    }//end authRequiredResponse()

    /**
     * Build a 403 response for non-admin callers on an admin-only action.
     *
     * @return JSONResponse
     */
    private function adminRequiredResponse(): JSONResponse
    {
        return new JSONResponse(data: ['error' => 'Administrator privileges required'], statusCode: 403);
    }//end adminRequiredResponse()

    /**
     * List every migration pack. Any authenticated user may browse packs to
     * pick one for an import request.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->authRequiredResponse();
        }

        $packs = $this->service->findAll();
        $items = array_map(static fn($pack) => $pack->jsonSerialize(), $packs);

        return new JSONResponse(data: ['results' => $items, 'total' => count($items)]);
    }//end index()

    /**
     * Get a single migration pack.
     *
     * @param int $id The migration pack id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->authRequiredResponse();
        }

        try {
            $pack = $this->service->find(id: $id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Migration pack not found'], statusCode: 404);
        }

        return new JSONResponse(data: $pack->jsonSerialize());
    }//end show()

    /**
     * Download a migration pack's definition as a standalone JSON file, so it
     * can be shared with (and imported into) another instance.
     *
     * @param int $id The migration pack id.
     *
     * @return JSONResponse|DataDownloadResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function export(int $id): JSONResponse|DataDownloadResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->authRequiredResponse();
        }

        try {
            $pack = $this->service->find(id: $id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Migration pack not found'], statusCode: 404);
        }

        $json     = json_encode($pack->getDefinitionArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $filename = $pack->getPackSlug().'.json';

        return new DataDownloadResponse($json, $filename, 'application/json');
    }//end export()

    /**
     * Import a migration pack definition from an uploaded JSON file. Admin only.
     * Upserts by the document's own `id` — re-importing an updated pack
     * updates the existing row.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function import(): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->authRequiredResponse();
        }

        if ($this->isCurrentUserAdmin() === false) {
            return $this->adminRequiredResponse();
        }

        $uploadedFile = $this->request->getUploadedFile('file');
        if (empty($uploadedFile['tmp_name']) === true) {
            return new JSONResponse(data: ['error' => 'No file uploaded'], statusCode: 400);
        }

        $raw     = file_get_contents($uploadedFile['tmp_name']);
        $decoded = null;
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
        }

        if (is_array($decoded) === false) {
            return new JSONResponse(data: ['error' => 'Uploaded file is not a valid JSON object'], statusCode: 400);
        }

        try {
            $pack = $this->service->importDefinition(definition: $decoded, ownerUid: $userId);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[MigrationPacksController] Error importing migration pack: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'trace' => $e->getTraceAsString()]
            );
            return new JSONResponse(data: ['error' => 'Failed to import migration pack'], statusCode: 500);
        }

        return new JSONResponse(data: $pack->jsonSerialize(), statusCode: 201);
    }//end import()

    /**
     * Create a migration pack. Admin only.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->authRequiredResponse();
        }

        if ($this->isCurrentUserAdmin() === false) {
            return $this->adminRequiredResponse();
        }

        $definition = $this->request->getParams();
        foreach (array_keys($definition) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($definition[$key]);
            }
        }

        try {
            $pack = $this->service->create(definition: $definition, ownerUid: $userId);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[MigrationPacksController] Error creating migration pack: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'trace' => $e->getTraceAsString()]
            );
            return new JSONResponse(data: ['error' => 'Failed to create migration pack'], statusCode: 500);
        }

        return new JSONResponse(data: $pack->jsonSerialize(), statusCode: 201);
    }//end create()

    /**
     * Update an existing migration pack. Admin only.
     *
     * @param int $id The migration pack id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(int $id): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->authRequiredResponse();
        }

        if ($this->isCurrentUserAdmin() === false) {
            return $this->adminRequiredResponse();
        }

        $definition = $this->request->getParams();
        foreach (array_keys($definition) as $key) {
            if (str_starts_with($key, '_') === true) {
                unset($definition[$key]);
            }
        }

        try {
            $pack = $this->service->update(id: $id, definition: $definition);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Migration pack not found'], statusCode: 404);
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[MigrationPacksController] Error updating migration pack: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()]
            );
            return new JSONResponse(data: ['error' => 'Failed to update migration pack'], statusCode: 500);
        }

        return new JSONResponse(data: $pack->jsonSerialize());
    }//end update()

    /**
     * Delete a migration pack. Admin only.
     *
     * @param int $id The migration pack id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/migration-mapping-packs/specs/migration-mapping-packs/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function destroy(int $id): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return $this->authRequiredResponse();
        }

        if ($this->isCurrentUserAdmin() === false) {
            return $this->adminRequiredResponse();
        }

        try {
            $this->service->delete(id: $id);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Migration pack not found'], statusCode: 404);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[MigrationPacksController] Error deleting migration pack: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()]
            );
            return new JSONResponse(data: ['error' => 'Failed to delete migration pack'], statusCode: 500);
        }

        return new JSONResponse(data: null, statusCode: 204);
    }//end destroy()
}//end class
