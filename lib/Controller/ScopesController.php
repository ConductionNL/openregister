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
class ScopesController extends Controller {

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
	 * @param string $appName Application identifier.
	 * @param IRequest $request Active HTTP request.
	 * @param IUserSession $userSession Current user session.
	 * @param IGroupManager $groupManager Group manager for admin
	 *                                    detection.
	 * @param RegisterMapper $registerMapper Register lookup.
	 * @param SchemaMapper $schemaMapper Schema lookup.
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
	 *         "actions": ["read", "list"],
	 *         "provenance": {
	 *           "read": {"granted": true, "source": "role", "role": "behandelaar",
	 *                    "principal": "behandelaars", "rule": ["behandelaars"]},
	 *           "update": {"granted": false, "source": "deny",
	 *                      "deny": {"principal": "waarnemers", "rule": "waarnemers"}}
	 *         }
	 *       },
	 *       ...
	 *     ]
	 *   }
	 *
	 * `actions` keeps its shape: a list of strings, unchanged, so every feature
	 * gate reading it keeps working. `provenance` sits beside it and names the
	 * rule behind each answer, including the deny behind an absence, because an
	 * absence with no reason is the hardest thing in this layer to debug.
	 *
	 * While the deny is staged (D15) an action carries `stagedDeny`: it is
	 * granted today, and that is the rule that removes it when the switch moves.
	 *
	 * Admin callers receive every (register, schema) pair with all five
	 * actions populated — this matches the admin-bypass semantics in
	 * `PermissionHandler::hasPermission`.
	 *
	 * @param string|null $register Optional register filter (id|uuid|slug).
	 * @param string|null $schema Optional schema filter (id|uuid|slug).
	 *
	 * @return JSONResponse The effective-scope envelope.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-5
	 */
	public function index(?string $register = null, ?string $schema = null): JSONResponse {
		$user = $this->userSession->getUser();
		$userId = $user?->getUID();
		$groups = [];
		$isAdmin = false;
		if ($user !== null) {
			$groups = $this->groupManager->getUserGroupIds($user);
			$isAdmin = in_array('admin', $groups, true);
		}

		$registers = $this->resolveRegisters(filter: $register);
		$schemas = $this->resolveSchemas(filter: $schema);

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

				// `actions` keeps its shape, exactly. Every feature gate in the
				// fleet reads it as a list of strings, and provenance is added
				// BESIDE it rather than inside it so none of them has to change
				// to keep working.
				$scopes[] = [
					'register' => $reg->getSlug(),
					'schema' => $sch->getSlug(),
					'actions' => $actions,
					'provenance' => $this->provenanceFor(
						schema: $sch,
						userId: $userId,
						isAdmin: $isAdmin
					),
				];
			}//end foreach
		}//end foreach

		return new JSONResponse(
			[
				'user' => $userId,
				'isAdmin' => $isAdmin,
				'groups' => array_values($groups),
				'scopes' => $scopes,
			]
		);

	}//end index()

	/**
	 * Resolve the registers in scope for the response.
	 *
	 * @param string|null $filter Optional register filter (id|uuid|slug).
	 *
	 * @return Register[] Registers that should be reported on.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-5
	 */
	private function resolveRegisters(?string $filter): array {
		if ($filter !== null && $filter !== '') {
			try {
				// SECURITY: keep multitenancy filter on so the discovery
				// endpoint cannot enumerate registers across tenants. Body
				// already short-circuits on anonymous + admin paths.
				$register = $this->registerMapper->find(
					$filter,
					_rbac: false
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
			// SECURITY: tenant-scope discovery via the default multitenancy
			// filter; only RBAC is bypassed because the body computes the
			// per-caller permission verdict downstream.
			return $this->registerMapper->findAll(
				_rbac: false
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-5
	 */
	private function resolveSchemas(?string $filter): array {
		if ($filter !== null && $filter !== '') {
			try {
				// SECURITY: see resolveRegisters() — keep multitenancy on.
				$schema = $this->schemaMapper->find(
					$filter,
					_rbac: false
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
			// SECURITY: see resolveRegisters() — keep multitenancy on.
			return $this->schemaMapper->findAll(
				_rbac: false
			);
		} catch (\Throwable $e) {
			return [];
		}

	}//end resolveSchemas()

	/**
	 * The rule behind each of this caller's answers on one schema.
	 *
	 * The verdict above says WHAT the caller may do. This says WHY, per action:
	 * the register default, the schema rule, the named role, the per-object
	 * grant, or the deny that removed it. A security officer asking "why can
	 * this person update this dossier" got a yes before, which answers a
	 * different question.
	 *
	 * @param Schema      $schema  Schema being reported on.
	 * @param string|null $userId  Active user (null = unauthenticated probe).
	 * @param bool        $isAdmin Whether the caller is in the `admin` group.
	 *
	 * @return array<string, array<string, mixed>> The provenance, keyed by action.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	private function provenanceFor(Schema $schema, ?string $userId, bool $isAdmin): array {
		// An administrator holds everything by bypass, not by rule, and saying
		// so is more useful than naming a rule that did not decide. A report
		// that invented a rule here would send somebody looking for it.
		if ($isAdmin === true) {
			return array_fill_keys(
				self::ACTIONS,
				['granted' => true, 'source' => 'admin', 'rule' => null, 'principal' => 'admin', 'role' => null]
			);
		}

		try {
			return $this->permissionHandler->provenanceFor(
				schema: $schema,
				actions: self::ACTIONS,
				userId: $userId
			);
		} catch (\Throwable $e) {
			// The verdict above already answered. A provenance that throws must
			// not take the scope list with it: a client that cannot read WHY is
			// inconvenienced, one that cannot read WHAT is broken.
			return [];
		}
	}//end provenanceFor()

	/**
	 * Probe the permission chain for every canonical action.
	 *
	 * Admin callers short-circuit to the full action vocabulary, mirroring the
	 * admin-bypass branch in `PermissionHandler::hasPermission`.
	 *
	 * @param Schema $schema Schema being evaluated.
	 * @param string|null $userId Active user (null = unauthenticated probe).
	 * @param bool $isAdmin Whether the caller is in the `admin` group.
	 *
	 * @return array<int, string> Permitted action vocabulary.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	private function collectActionsForUser(Schema $schema, ?string $userId, bool $isAdmin): array {
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
