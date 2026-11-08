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

use Closure;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCA\OpenRegister\Controller\Support\ScopesControllerServices;

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
	 * What an administrator is told about each action: held by bypass, not by rule.
	 *
	 * @var array<string, mixed>
	 */
	private const ADMIN_PROVENANCE = ['granted' => true, 'source' => 'admin', 'rule' => null, 'principal' => 'admin', 'role' => null];

	/**
	 * The user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Register lookup.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema lookup.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * RBAC evaluator that
	 *
	 * @var PermissionHandlerInterface
	 */
	private readonly PermissionHandlerInterface $permissionHandler;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application identifier.
	 * @param IRequest $request Active HTTP request.
	 * @param ControllerSession $session Session, group manager and logger.
	 * @param ScopesControllerServices $services The services, bundled.
	 *                                             owns the rule chain.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ControllerSession $session,
		ScopesControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->registerMapper = $services->registerMapper;
		$this->schemaMapper = $services->schemaMapper;
		$this->permissionHandler = $services->permissionHandler;

		$this->userSession = $session->userSession;
		$this->groupManager = $session->groupManager;
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
	 * `PermissionHandlerInterface::hasPermission`.
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
		$groups = $this->groupsOf(user: $user);
		$isAdmin = in_array('admin', $groups, true);

		$registers = $this->resolveRegisters(filter: $register);
		$schemas = $this->resolveSchemas(filter: $schema);
		$scopes = $this->scopesFor(registers: $registers, schemas: $schemas, userId: $userId, isAdmin: $isAdmin);

		return new JSONResponse(['user' => $userId, 'isAdmin' => $isAdmin, 'groups' => array_values($groups), 'scopes' => $scopes]);

	}//end index()

	/**
	 * The caller's group ids; none for an unauthenticated caller.
	 *
	 * @param IUser|null $user The session user.
	 *
	 * @return array<int|string, string> The group ids.
	 */
	private function groupsOf(?IUser $user): array {
		if ($user === null) {
			return [];
		}

		return $this->groupManager->getUserGroupIds($user);
	}//end groupsOf()

	/**
	 * Every (register, schema) pair the caller holds at least one action on.
	 *
	 * @param Register[] $registers The registers reported on.
	 * @param Schema[] $schemas The schemas reported on.
	 * @param string|null $userId Active user (null = unauthenticated probe).
	 * @param bool $isAdmin Whether the caller is in the `admin` group.
	 *
	 * @return array<int, array<string, mixed>> The scope entries.
	 */
	private function scopesFor(array $registers, array $schemas, ?string $userId, bool $isAdmin): array {
		$scopes = [];
		foreach ($registers as $reg) {
			foreach ($schemas as $sch) {
				$scope = $this->scopeFor(register: $reg, schema: $sch, userId: $userId, isAdmin: $isAdmin);
				if ($scope !== null) {
					$scopes[] = $scope;
				}
			}
		}

		return $scopes;
	}//end scopesFor()

	/**
	 * The scope entry for one pair, or null when the schema is not in the register or nothing is held.
	 *
	 * `actions` keeps its shape, exactly. Every feature gate in the fleet reads
	 * it as a list of strings, and provenance is added BESIDE it rather than
	 * inside it so none of them has to change to keep working.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 * @param string|null $userId Active user (null = unauthenticated probe).
	 * @param bool $isAdmin Whether the caller is in the `admin` group.
	 *
	 * @return array<string, mixed>|null The scope entry, or null.
	 */
	private function scopeFor(Register $register, Schema $schema, ?string $userId, bool $isAdmin): ?array {
		if (in_array($schema->getId(), ($register->getSchemas() ?? []), false) === false) {
			return null;
		}

		$actions = $this->collectActionsForUser(schema: $schema, userId: $userId, isAdmin: $isAdmin);
		if ($actions === []) {
			return null;
		}

		return [
			'register' => $register->getSlug(),
			'schema' => $schema->getSlug(),
			'actions' => $actions,
			'provenance' => $this->provenanceFor(schema: $schema, userId: $userId, isAdmin: $isAdmin),
		];
	}//end scopeFor()

	/**
	 * Resolve the registers in scope for the response.
	 *
	 * SECURITY: the multitenancy filter stays on, so the discovery endpoint
	 * cannot enumerate registers across tenants; only RBAC is bypassed because
	 * the body computes the per-caller permission verdict downstream.
	 *
	 * @param string|null $filter Optional register filter (id|uuid|slug).
	 *
	 * @return Register[] Registers that should be reported on.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-5
	 */
	private function resolveRegisters(?string $filter): array {
		if ($this->isFilter(filter: $filter) === true) {
			return $this->orNothing(read: fn (): array => [$this->registerMapper->find($filter, _rbac: false)]);
		}

		return $this->orNothing(read: fn (): array => $this->registerMapper->findAll(_rbac: false));

	}//end resolveRegisters()

	/**
	 * Resolve the schemas in scope for the response.
	 *
	 * SECURITY: see resolveRegisters() — multitenancy stays on.
	 *
	 * @param string|null $filter Optional schema filter (id|uuid|slug).
	 *
	 * @return Schema[] Schemas that should be reported on.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-5
	 */
	private function resolveSchemas(?string $filter): array {
		if ($this->isFilter(filter: $filter) === true) {
			return $this->orNothing(read: fn (): array => [$this->schemaMapper->find($filter, _rbac: false)]);
		}

		return $this->orNothing(read: fn (): array => $this->schemaMapper->findAll(_rbac: false));

	}//end resolveSchemas()

	/**
	 * Whether a narrowing filter was given.
	 *
	 * @param string|null $filter The filter.
	 *
	 * @return bool True for a non-empty filter.
	 */
	private function isFilter(?string $filter): bool {
		return $filter !== null && $filter !== '';
	}//end isFilter()

	/**
	 * Run a lookup, answering nothing when it fails.
	 *
	 * @param Closure(): array<int, mixed> $read The lookup.
	 *
	 * @return array<int, mixed> What it found, or an empty list.
	 */
	private function orNothing(Closure $read): array {
		try {
			return $read();
		} catch (\Throwable $e) {
			return [];
		}
	}//end orNothing()

	/**
	 * The rule behind each of this caller's answers on one schema.
	 *
	 * The verdict above says WHAT the caller may do. This says WHY, per action:
	 * the register default, the schema rule, the named role, the per-object
	 * grant, or the deny that removed it. A security officer asking "why can
	 * this person update this dossier" got a yes before, which answers a
	 * different question.
	 *
	 * An administrator holds everything by bypass, not by rule, and saying
	 * so is more useful than naming a rule that did not decide. A report
	 * that invented a rule here would send somebody looking for it.
	 *
	 * @param Schema      $schema  Schema being reported on.
	 * @param string|null $userId  Active user (null = unauthenticated probe).
	 * @param bool        $isAdmin Whether the caller is in the `admin` group.
	 *
	 * @return array<string, array<string, mixed>> The provenance, keyed by action.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function provenanceFor(Schema $schema, ?string $userId, bool $isAdmin): array {
		if ($isAdmin === true) {
			return array_fill_keys(self::ACTIONS, self::ADMIN_PROVENANCE);
		}

		try {
			return $this->permissionHandler->provenanceFor(schema: $schema, actions: self::ACTIONS, userId: $userId);
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
	 * admin-bypass branch in `PermissionHandlerInterface::hasPermission`.
	 *
	 * @param Schema $schema Schema being evaluated.
	 * @param string|null $userId Active user (null = unauthenticated probe).
	 * @param bool $isAdmin Whether the caller is in the `admin` group.
	 *
	 * @return array<int, string> Permitted action vocabulary.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function collectActionsForUser(Schema $schema, ?string $userId, bool $isAdmin): array {
		if ($isAdmin === true) {
			return self::ACTIONS;
		}

		return array_values(
			array_filter(
				self::ACTIONS,
				fn (string $action): bool => $this->grants(schema: $schema, action: $action, userId: $userId)
			)
		);
	}//end collectActionsForUser()

	/**
	 * Whether the RBAC chain grants one action on a schema; a failing probe grants nothing.
	 *
	 * @param Schema $schema Schema being evaluated.
	 * @param string $action The canonical action.
	 * @param string|null $userId Active user (null = unauthenticated probe).
	 *
	 * @return bool True when granted.
	 */
	private function grants(Schema $schema, string $action, ?string $userId): bool {
		try {
			// The owner and object stay at their null defaults: this is a schema-level probe.
			$granted = $this->permissionHandler->hasPermission(schema: $schema, action: $action, userId: $userId, _rbac: true);
		} catch (\Throwable $e) {
			return false;
		}

		return $granted === true;
	}//end grants()
}//end class
