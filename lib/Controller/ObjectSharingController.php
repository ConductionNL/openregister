<?php

/**
 * Object sharing controller — set an object's scope, and grant or revoke it.
 *
 * The read side of this capability is enforced inside RBAC, on all four paths
 * ({@see \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver} and
 * {@see \OCA\OpenRegister\Service\Rbac\ObjectGrantResolver}). This is the write
 * side, and it is deliberately narrow.
 *
 * WHY THESE ENDPOINTS EXIST AT ALL. `_authorization` cannot be written through an
 * ordinary object save: non-admin writes have it stripped, and the object write
 * path omits the column so a routine update carries the stored value forward
 * rather than destroying it. Both of those are correct — per-object RBAC must not
 * be reachable through a data field. So changing an object's scope needs its own
 * owner-checked entry point, which is this.
 *
 * EVERY METHOD IS OWNER-OR-ADMIN. Two guards apply in order, and both are needed:
 *
 *  1. `guardObjectAccess()` resolves the object through `ObjectService`, which
 *     applies register RBAC and multitenancy. An object the caller cannot READ
 *     resolves to null and the request is refused with 404 — existence is not
 *     leaked (the IDOR guard the integrations endpoints already use).
 *  2. `ObjectSharingService` then requires the caller to be the OWNER or an
 *     administrator, using the same resolver the read side uses, so "who may
 *     change sharing" and "who is admitted unconditionally" cannot drift.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Owner-checked endpoints for an object's scope and its grants.
 */
class ObjectSharingController extends Controller
{
    /**
     * Constructor.
     *
     * @param string               $appName        App name.
     * @param IRequest             $request        Request.
     * @param ObjectService        $objectService  Resolves an object through the RBAC boundary.
     * @param RegisterMapper       $registerMapper Resolves the register entity.
     * @param SchemaMapper         $schemaMapper   Resolves the schema entity.
     * @param ObjectSharingService $sharing        Performs the owner-checked writes.
     * @param LoggerInterface      $logger         Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectSharingService $sharing,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Read one object's effective scope.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object uuid.
     *
     * @return JSONResponse The scope.
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function scope(string $register, string $schema, string $id): JSONResponse
    {
        $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
        if ($object instanceof JSONResponse) {
            return $object;
        }

        $block = ($object->getAuthorization() ?? []);
        $scope = null;
        if (is_array($block) === true) {
            $scope = ($block['scope'] ?? null);
        }

        return new JSONResponse(['scope' => $scope]);
    }//end scope()

    /**
     * Set one object's scope.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object uuid.
     *
     * @return JSONResponse The stored block, or an error.
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function setScope(string $register, string $schema, string $id): JSONResponse
    {
        $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
        if ($object instanceof JSONResponse) {
            return $object;
        }

        $scope = $this->request->getParam('scope');
        if (is_string($scope) === false) {
            return new JSONResponse(['message' => 'A scope is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $block = $this->sharing->setScope(
                register: $this->registerMapper->find($this->objectService->getRegister()),
                schema: $this->schemaMapper->find($this->objectService->getSchema()),
                object: $object,
                scope: $scope
            );
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->unexpected(exception: $e, context: 'setScope');
        }

        return new JSONResponse(['scope' => ($block['scope'] ?? null)]);
    }//end setScope()

    /**
     * List the grants on one object.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object uuid.
     *
     * @return JSONResponse The grants, or an error.
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function shares(string $register, string $schema, string $id): JSONResponse
    {
        $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
        if ($object instanceof JSONResponse) {
            return $object;
        }

        try {
            return new JSONResponse(['results' => $this->sharing->listGrants(object: $object)]);
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            return $this->unexpected(exception: $e, context: 'shares');
        }
    }//end shares()

    /**
     * Grant one principal access to one object.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object uuid.
     *
     * @return JSONResponse The created grant, or an error.
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function createShare(string $register, string $schema, string $id): JSONResponse
    {
        $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
        if ($object instanceof JSONResponse) {
            return $object;
        }

        $type       = (string) ($this->request->getParam('type') ?? 'user');
        $shareWith  = (string) ($this->request->getParam('shareWith') ?? '');
        $permission = $this->request->getParam('permissions');

        // Default to READ. A grant that silently carried write would be the
        // wrong direction to guess in.
        $permissions = 1;
        if (is_numeric($permission) === true) {
            $permissions = (int) $permission;
        }

        try {
            $grant = $this->sharing->grant(
                object: $object,
                type: $type,
                shareWith: $shareWith,
                permissions: $permissions
            );
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->unexpected(exception: $e, context: 'createShare');
        }

        return new JSONResponse($grant, Http::STATUS_CREATED);
    }//end createShare()

    /**
     * Revoke one grant.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object uuid.
     * @param string $shareId  The grant to revoke.
     *
     * @return JSONResponse Empty on success, or an error.
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function destroyShare(string $register, string $schema, string $id, string $shareId): JSONResponse
    {
        $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
        if ($object instanceof JSONResponse) {
            return $object;
        }

        try {
            $this->sharing->revoke(object: $object, shareId: $shareId);
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->unexpected(exception: $e, context: 'destroyShare');
        }

        return new JSONResponse([], Http::STATUS_NO_CONTENT);
    }//end destroyShare()

    /**
     * Resolve the target object through the RBAC boundary.
     *
     * An object the caller cannot READ is refused with 404 rather than 403, so
     * this endpoint cannot be used to probe which object ids exist.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object uuid.
     *
     * @return ObjectEntity|JSONResponse The object, or the response to return.
     */
    private function resolveObject(string $register, string $schema, string $id): ObjectEntity|JSONResponse
    {
        $this->objectService->setRegister($register);
        $this->objectService->setSchema($schema);
        $this->objectService->setObject($id);

        try {
            $object = $this->objectService->getObject();
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
        }

        if (($object instanceof ObjectEntity) === false) {
            return new JSONResponse(['message' => 'Object not found'], Http::STATUS_NOT_FOUND);
        }

        return $object;
    }//end resolveObject()

    /**
     * Log an unexpected failure and return a generic error.
     *
     * The message is never echoed to the client — it can carry storage paths and
     * share internals.
     *
     * @param \Throwable $exception The failure.
     * @param string     $context   Short label for the log line.
     *
     * @return JSONResponse A generic 500.
     */
    private function unexpected(\Throwable $exception, string $context): JSONResponse
    {
        $this->logger->error(
            message: '[ObjectSharingController] '.$context.': '.$exception->getMessage(),
            context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $exception]
        );

        return new JSONResponse(
            ['message' => 'Could not complete the sharing request'],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }//end unexpected()
}//end class
