<?php

/**
 * The permission catalogue, and what the deny would do if it were enforcing.
 *
 * Three reads, and they answer the questions an administrator has before they
 * write a single rule:
 *
 *  - `GET /api/permissions` what can be granted here at all. Until this
 *    answered, a role editor had nothing to offer, which is why every consumer
 *    in the fleet invented its own vocabulary in its own screen.
 *  - `GET /api/permissions/deny-preview` what enforcement would refuse. The
 *    deny ships staged (D15), and this is the report an administrator reads
 *    before turning it on.
 *  - `GET /api/permissions/compare-roles` what one role can do that another
 *    cannot, against the catalogue, so a role that quietly acquired a verb is
 *    visible rather than diffed by eye.
 *
 * WHY THE PREVIEW READS THE RULES AND NOT A LOG. Accumulated observations
 * answer "what has fired", and stop there. The denies nobody has exercised yet
 * are missing from that answer, and those are precisely the ones that surprise
 * somebody on the day the switch is flipped. This endpoint reads the rules as
 * written, so a deny nobody has hit is in the report the day it is saved.
 *
 * Auth posture. `index()` is `#[NoAdminRequired]`: the catalogue is a
 * vocabulary, not a secret.
 *
 * `denyPreview()`, `scopeAudit()` and `compareRoles()` are ADMIN-ONLY. They
 * used to carry `#[NoAdminRequired]` on the claim, written here, that they
 * "report only the rules of the registers and schemas the caller can already
 * resolve". They did not. Called without a filter they walk
 * `RegisterMapper::findAll()` / `SchemaMapper::findAll()` and report holders,
 * roles and deny principals for every register and schema in the organisation,
 * to any signed-in account.
 *
 * The scoping the old sentence described cannot currently be honoured: entity
 * RBAC is opt-in (`rbac_entity_enforcement`, off by default and deliberately
 * so), and with it off `hasRbacPermission()` answers true for any authenticated
 * caller — so "the registers the caller can resolve" is every register in the
 * organisation. Rather than invent a per-caller policy the app cannot enforce,
 * these three report to admins only, which is what an authorization-model
 * auditor is. No UI calls them; the routes exist for operators.
 *
 * If per-caller scoping is wanted later, the pattern is
 * `ScopesController::resolveRegisters()`: read with `_rbac: false`, keep
 * multitenancy ON, and compute the verdict per entity downstream.
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ScopeAudit;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Publishes the grantable permission set and the staged deny report.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class PermissionsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string              $appName        Application identifier.
	 * @param IRequest            $request        Active HTTP request.
	 * @param PermissionCatalogue $catalogue      The grantable set.
	 * @param DenyEnforcementMode $enforcement    The staging switch.
	 * @param DenyResolver        $denyResolver   The one reader of the deny grammar.
	 * @param RegisterMapper      $registerMapper Register lookup.
	 * @param SchemaMapper        $schemaMapper   Schema lookup.
	 * @param ScopeAudit          $audit          Assembles the per-rule audit.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PermissionCatalogue $catalogue,
		private readonly DenyEnforcementMode $enforcement,
		private readonly DenyResolver $denyResolver,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ScopeAudit $audit = new ScopeAudit(),
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Every permission that may be granted on this instance.
	 *
	 * Response shape:
	 *
	 *   {
	 *     "permissions": [
	 *       {"verb": "read", "app": "openregister", "canonical": true,
	 *        "levels": ["register", "schema", "object"],
	 *        "description": "Open one object and read its contents."},
	 *       ...
	 *     ],
	 *     "denyEnforcement": "staging",
	 *     "rejectedDeclarations": {}
	 *   }
	 *
	 * `rejectedDeclarations` is reported rather than dropped: an app whose verb
	 * is missing from a role editor should be able to read why, instead of
	 * finding an empty selector and guessing.
	 *
	 * @return JSONResponse The catalogue.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'permissions' => array_values($this->catalogue->all()),
				'denyEnforcement' => $this->enforcement->current(),
				'rejectedDeclarations' => $this->catalogue->rejectedDeclarations(),
			]
		);
	}//end index()

	/**
	 * What enforcement would refuse, read from the rules as written.
	 *
	 * Optional `register` and `schema` query parameters narrow the report the
	 * same way `GET /api/scopes` narrows its matrix.
	 *
	 * Each entry names where the rule lives, the verb it removes and the
	 * principal it names, which is exactly the triple an administrator needs to
	 * decide whether the rule says what they meant. A conditional rule is
	 * reported with its `match` clause, because "some rows" is a different
	 * promise from "every row" and the difference is invisible in a count.
	 *
	 * @param string|null $register Optional register filter (id|uuid|slug).
	 * @param string|null $schema   Optional schema filter (id|uuid|slug).
	 *
	 * @return JSONResponse The preview.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	#[NoCSRFRequired]
	public function denyPreview(?string $register = null, ?string $schema = null): JSONResponse {
		$rules = [];

		foreach ($this->registersFor(filter: $register) as $reg) {
			$rules = array_merge(
				$rules,
				$this->rulesIn(
					authorization: $reg->getAuthorization(),
					level: 'register',
					subject: (string)($reg->getSlug() ?? $reg->getTitle() ?? '')
				)
			);
		}

		foreach ($this->schemasFor(filter: $schema) as $sch) {
			$rules = array_merge(
				$rules,
				$this->rulesIn(
					authorization: $sch->getAuthorization(),
					level: 'schema',
					subject: (string)($sch->getSlug() ?? $sch->getTitle() ?? '')
				)
			);
		}

		return new JSONResponse(
			[
				'denyEnforcement' => $this->enforcement->current(),
				'enforcing' => $this->enforcement->enforces(),
				'ruleCount' => count($rules),
				'rules' => $rules,
			]
		);
	}//end denyPreview()

	/**
	 * The scope audit, per rule as well as per schema and per action.
	 *
	 * The audit answered per schema and per action before this: which groups
	 * hold `read` on `zaak`. That answer is a set of names with no rule behind
	 * it, so the reviewer who finds a group they did not expect has to go and
	 * discover WHERE it was granted, at four levels, and the finding is the
	 * expensive half of an access review.
	 *
	 * Each entry names the level the rule is written at, the role when it came
	 * through one, and whether the verb is in the catalogue at all. The denies
	 * are reported beside the grants rather than subtracted from them, with the
	 * enforcement mode, because below `enforcing` a deny has not started biting
	 * and a report that had already subtracted it would show a reviewer somebody
	 * as locked out while they are still working.
	 *
	 * @param string|null $register Optional register filter (id|uuid|slug).
	 * @param string|null $schema   Optional schema filter (id|uuid|slug).
	 *
	 * @return JSONResponse The audit.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	#[NoCSRFRequired]
	public function scopeAudit(?string $register = null, ?string $schema = null): JSONResponse {
		$rows = $this->audit->rows(
			registers: $this->registersFor(filter: $register),
			schemas: $this->schemasFor(filter: $schema)
		);

		return new JSONResponse(
			[
				'denyEnforcement' => $this->enforcement->current(),
				'schemaCount' => count($rows),
				'scopes' => $rows,
			]
		);
	}//end scopeAudit()

	/**
	 * Two roles side by side against the catalogue.
	 *
	 * The auditor's small question, and the one a role editor cannot answer
	 * today: what can the senior behandelaar do that the behandelaar cannot.
	 * Reading two role definitions and diffing them by eye is how that gets
	 * answered now, and it is how a role quietly acquires a verb nobody meant it
	 * to have.
	 *
	 * Verbs are reported against the catalogue, so a role naming a verb no app
	 * declares shows up as undeclared rather than as a right.
	 *
	 * @param string      $register The register holding the role definitions.
	 * @param string|null $roles    Comma-separated role names; all of them when omitted.
	 *
	 * @return JSONResponse The comparison.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	#[NoCSRFRequired]
	public function compareRoles(string $register, ?string $roles = null): JSONResponse {
		$found = $this->registersFor(filter: $register);
		if ($found === []) {
			return new JSONResponse(['error' => sprintf('No register named "%s".', $register)], 404);
		}

		$definitions = $this->roleActionsOf(register: $found[0]);

		$wanted = $this->requestedRoles(roles: $roles, available: array_keys($definitions));
		$missing = array_values(array_diff($wanted, array_keys($definitions)));
		$compared = array_values(array_intersect($wanted, array_keys($definitions)));

		$rows = [];
		foreach ($compared as $role) {
			$rows[$role] = [
				'actions' => $definitions[$role],
				'undeclared' => array_values(
					array_filter(
						$definitions[$role],
						fn (string $verb): bool => $this->catalogue->isGrantable($verb) === false
					)
				),
			];
		}

		return new JSONResponse(
			[
				'register' => $register,
				'roles' => $rows,
				'unknownRoles' => $missing,
				'shared' => $this->sharedActions(rows: $rows),
				'onlyIn' => $this->exclusiveActions(rows: $rows),
			]
		);
	}//end compareRoles()

	/**
	 * The role name to actions map a register declares.
	 *
	 * `extends` is not folded in here. The hierarchy is resolved in
	 * PermissionHandler, and a second walk of it in a reporting endpoint could
	 * disagree with the one that actually decides, which would make a comparison
	 * screen confidently wrong.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, array<int, string>> Role name to its declared actions.
	 */
	private function roleActionsOf(Register $register): array {
		$configuration = $register->getConfiguration();
		$definitions = [];
		if (is_array($configuration) === true) {
			$definitions = ($configuration['roles'] ?? []);
		}

		if (is_array($definitions) === false) {
			return [];
		}

		$map = [];
		foreach ($definitions as $definition) {
			if (is_array($definition) === false || isset($definition['name']) === false) {
				continue;
			}

			$actions = ($definition['actions'] ?? []);
			if (is_array($actions) === false) {
				$actions = [];
			}

			$map[(string)$definition['name']] = array_values(array_filter($actions, 'is_string'));
		}

		return $map;
	}//end roleActionsOf()

	/**
	 * The roles the caller asked about, or all of them.
	 *
	 * @param string|null        $roles     The comma-separated request.
	 * @param array<int, string> $available Every role the register declares.
	 *
	 * @return array<int, string> The role names to compare.
	 */
	private function requestedRoles(?string $roles, array $available): array {
		if ($roles === null || trim($roles) === '') {
			return $available;
		}

		return array_values(
			array_filter(
				array_map('trim', explode(',', $roles)),
				static fn (string $name): bool => $name !== ''
			)
		);
	}//end requestedRoles()

	/**
	 * The actions every compared role holds.
	 *
	 * @param array<string, array{actions: array<int, string>}> $rows The compared roles.
	 *
	 * @return array<int, string> The shared actions.
	 */
	private function sharedActions(array $rows): array {
		if ($rows === []) {
			return [];
		}

		$sets = array_map(static fn (array $row): array => $row['actions'], $rows);

		return array_values(array_intersect(...array_values($sets)));
	}//end sharedActions()

	/**
	 * The actions only one of the compared roles holds.
	 *
	 * This is the answer the question was actually asking: the verbs the senior
	 * role has and the ordinary one does not.
	 *
	 * @param array<string, array{actions: array<int, string>}> $rows The compared roles.
	 *
	 * @return array<string, array<int, string>> Role name to the actions only it holds.
	 */
	private function exclusiveActions(array $rows): array {
		$exclusive = [];
		foreach ($rows as $role => $row) {
			$others = [];
			foreach ($rows as $otherRole => $otherRow) {
				if ($otherRole !== $role) {
					$others = array_merge($others, $otherRow['actions']);
				}
			}

			$exclusive[$role] = array_values(array_diff($row['actions'], $others));
		}

		return $exclusive;
	}//end exclusiveActions()

	/**
	 * Flatten one block's deny rules into report entries.
	 *
	 * @param array|null $authorization The block as written.
	 * @param string     $level         Where the block lives.
	 * @param string     $subject       Which register or schema it belongs to.
	 *
	 * @return array<int, array<string, mixed>> One entry per verb and principal.
	 */
	private function rulesIn(?array $authorization, string $level, string $subject): array {
		$entries = [];

		foreach ($this->denyResolver->denyBlock(authorization: $authorization) as $action => $rules) {
			if (is_string($action) === false || is_array($rules) === false) {
				continue;
			}

			foreach ($rules as $rule) {
				$entries[] = [
					'level' => $level,
					'subject' => $subject,
					'action' => $action,
					'principal' => $this->principalOf(rule: $rule),
					'conditional' => (is_array($rule) === true && isset($rule['match']) === true),
					'match' => $this->matchOf(rule: $rule),
					'declared' => $this->catalogue->isGrantable($action),
				];
			}
		}

		return $entries;
	}//end rulesIn()

	/**
	 * The `match` clause of one deny entry, or null when it has none.
	 *
	 * Reported rather than summarised: "some rows" is a different promise from
	 * "every row", and the difference is invisible in a count.
	 *
	 * @param mixed $rule The entry as written.
	 *
	 * @return mixed The clause, or null.
	 */
	private function matchOf(mixed $rule): mixed {
		if (is_array($rule) === false) {
			return null;
		}

		return ($rule['match'] ?? null);
	}//end matchOf()

	/**
	 * The principal one deny entry names.
	 *
	 * @param mixed $rule The entry, a bare name or an object with a principal.
	 *
	 * @return string|null The principal, or null when the entry does not name one.
	 */
	private function principalOf(mixed $rule): ?string {
		if (is_string($rule) === true) {
			return $rule;
		}

		if (is_array($rule) === false) {
			return null;
		}

		foreach (['principal', 'group', 'role', 'user'] as $key) {
			$value = ($rule[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return null;
	}//end principalOf()

	/**
	 * The registers the report covers.
	 *
	 * @param string|null $filter Optional register filter.
	 *
	 * @return Register[] The registers.
	 */
	private function registersFor(?string $filter): array {
		try {
			if ($filter !== null && $filter !== '') {
				// The mapper throws when nothing matches; find() never
				// answers null. The catch below is what turns an unknown
				// filter into an empty report rather than a 500.
				return [$this->registerMapper->find($filter)];
			}

			return $this->registerMapper->findAll();
		} catch (\Throwable $e) {
			return [];
		}
	}//end registersFor()

	/**
	 * The schemas the report covers.
	 *
	 * @param string|null $filter Optional schema filter.
	 *
	 * @return Schema[] The schemas.
	 */
	private function schemasFor(?string $filter): array {
		try {
			if ($filter !== null && $filter !== '') {
				// See registersFor(): the mapper throws rather than answering null.
				return [$this->schemaMapper->find($filter)];
			}

			return $this->schemaMapper->findAll();
		} catch (\Throwable $e) {
			return [];
		}
	}//end schemasFor()
}//end class
