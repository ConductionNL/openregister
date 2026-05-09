<?php

/**
 * OpenRegister Scopes Controller
 *
 * Discovery endpoint for the user's effective RBAC scopes.
 *
 * Closes the rbac-scopes spec requirement "Scope Documentation and
 * Discovery API" — clients (frontend feature gates, OAuth2 token
 * exchange, downstream apps) call `GET /api/scopes` to learn which
 * (register, schema, action) tuples the current user is permitted to
 * perform without having to probe every endpoint.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Discovery endpoint for the current user's effective RBAC scopes.
 */
class ScopesController extends Controller
{

    /**
     * The five canonical RBAC actions the spec defines.
     *
     * Kept as a class constant so the discovery payload, OAS scope
     * generation, and permission probes all share the same vocabulary.
     *
     * @var array<int, string>
     */
    public const ACTIONS = ['read', 'create', 'update', 'delete', 'list'];

    /**
     * Constructor.
     *
     * @param string            $appName           Application identifier.
     * @param IRequest          $request           Active HTTP request.
     * @param IUserSession      $userSession       Current user session.
     * @param IGroupManager     $groupManager      Group manager for admin
     *                                             detection.
     * @param RegisterMapper    $registerMapper    Register lookup.
     * @param SchemaMapper      $schemaMapper      Schema lookup.
     * @param PermissionHandler $permissionHandler RBAC evaluator that
     *                                             owns the rule chain.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly PermissionHandler $permissionHandler,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Return the current user's effective scopes.
     *
     * Optional `register` (id|uuid|slug) and `schema` (id|uuid|slug)
     * query parameters narrow the response — useful for clients that
     * already know which surface they're rendering and don't need the
     * full matrix.
     *
     * Response shape:
     *
     *   {
     *     "user": "alice"|null,            // null for unauthenticated callers
     *     "isAdmin": false,
     *     "groups": ["users", "hr"],
     *     "scopes": [
     *       {
     *         "register": "decidesk",
     *         "schema": "meeting",
     *         "actions": ["read", "list"]
     *       },
     *       ...
     *     ]
     *   }
     *
     * Admin callers receive every (register, schema) pair with all five
     * actions populated — this matches the admin-bypass semantics in
     * `PermissionHandler::hasPermission`.
     *
     * @param string|null $register Optional register filter (id|uuid|slug).
     * @param string|null $schema   Optional schema filter (id|uuid|slug).
     *
     * @return JSONResponse The effective-scope envelope.
     *
     * @NoCSRFRequired
     */
    public function index(?string $register=null, ?string $schema=null): JSONResponse
    {
        $user    = $this->userSession->getUser();
        $userId  = $user?->getUID();
        $groups  = [];
        $isAdmin = false;
        if ($user !== null) {
            $groups  = $this->groupManager->getUserGroupIds($user);
            $isAdmin = in_array('admin', $groups, true);
        }

        $registers = $this->resolveRegisters(filter: $register);
        $schemas   = $this->resolveSchemas(filter: $schema);

        $scopes = [];
        foreach ($registers as $reg) {
            $registerSchemaIds = $reg->getSchemas() ?? [];
            foreach ($schemas as $sch) {
                if (in_array($sch->getId(), $registerSchemaIds, false) === false) {
                    continue;
                }

                $actions = $this->collectActionsForUser(
                    schema: $sch,
                    userId: $userId,
                    isAdmin: $isAdmin
                );
                if ($actions === []) {
                    continue;
                }

                $scopes[] = [
                    'register' => $reg->getSlug(),
                    'schema'   => $sch->getSlug(),
                    'actions'  => $actions,
                ];
            }//end foreach
        }//end foreach

        return new JSONResponse(
            [
                'user'    => $userId,
                'isAdmin' => $isAdmin,
                'groups'  => array_values($groups),
                'scopes'  => $scopes,
            ]
        );

    }//end index()

    /**
     * Resolve the registers in scope for the response.
     *
     * @param string|null $filter Optional register filter (id|uuid|slug).
     *
     * @return Register[] Registers that should be reported on.
     */
    private function resolveRegisters(?string $filter): array
    {
        if ($filter !== null && $filter !== '') {
            try {
                $register = $this->registerMapper->find(
                    $filter,
                    _rbac: false,
                    _multitenancy: false
                );
                if ($register === null) {
                    return [];
                }

                return [$register];
            } catch (\Throwable $e) {
                return [];
            }
        }

        try {
            return $this->registerMapper->findAll(
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            return [];
        }

    }//end resolveRegisters()

    /**
     * Resolve the schemas in scope for the response.
     *
     * @param string|null $filter Optional schema filter (id|uuid|slug).
     *
     * @return Schema[] Schemas that should be reported on.
     */
    private function resolveSchemas(?string $filter): array
    {
        if ($filter !== null && $filter !== '') {
            try {
                $schema = $this->schemaMapper->find(
                    $filter,
                    _rbac: false,
                    _multitenancy: false
                );
                if ($schema === null) {
                    return [];
                }

                return [$schema];
            } catch (\Throwable $e) {
                return [];
            }
        }

        try {
            return $this->schemaMapper->findAll(
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            return [];
        }

    }//end resolveSchemas()

    /**
     * Probe the permission chain for every canonical action.
     *
     * Admin callers short-circuit to the full action vocabulary —
     * mirrors the admin-bypass branch in `PermissionHandler::hasPermission`.
     *
     * @param Schema      $schema  Schema being evaluated.
     * @param string|null $userId  Active user (null = unauthenticated probe).
     * @param bool        $isAdmin Whether the caller is in the `admin`
     *                             group.
     *
     * @return array<int, string> Permitted action vocabulary.
     */
    private function collectActionsForUser(Schema $schema, ?string $userId, bool $isAdmin): array
    {
        if ($isAdmin === true) {
            return self::ACTIONS;
        }

        $allowed = [];
        foreach (self::ACTIONS as $action) {
            try {
                $granted = $this->permissionHandler->hasPermission(
                    schema: $schema,
                    action: $action,
                    userId: $userId,
                    objectOwner: null,
                    _rbac: true,
                    object: null
                );
            } catch (\Throwable $e) {
                $granted = false;
            }

            if ($granted === true) {
                $allowed[] = $action;
            }
        }

        return $allowed;

    }//end collectActionsForUser()
}//end class
