<?php

/**
 * PermissionsAuditController - reading the authorization model as written
 *
 * Split out of PermissionsController. That class held two different things: a
 * catalogue any signed-in caller may read, and three reports that disclose the
 * authorization model and are therefore admin-only. Two auth postures in one
 * controller is a shape that invites getting one of them wrong, and the split
 * also lets the admin gate live here rather than being paid for by an endpoint
 * that does not need it.
 *
 * These three report to admins only, which is what an authorization-model
 * auditor is. They used to carry `#[NoAdminRequired]` on the claim that they
 * "report only the rules of the registers and schemas the caller can already
 * resolve". They did not. Called without a filter they walk
 * `RegisterMapper::findAll()` / `SchemaMapper::findAll()` and report holders,
 * roles and deny principals for every register and schema in the organisation,
 * to any signed-in account.
 *
 * The scoping that sentence described cannot currently be honoured: entity RBAC
 * is opt-in (`rbac_entity_enforcement`, off by default and deliberately so),
 * and with it off `hasRbacPermission()` answers true for any authenticated
 * caller - so "the registers the caller can resolve" is every register in the
 * organisation. Rather than invent a per-caller policy the app cannot enforce,
 * these report to admins only. No UI calls them; the routes exist for operators.
 *
 * Admin-only is reached the way the rest of this app does it -
 * `#[NoAdminRequired]` plus an explicit gate - rather than by omitting the
 * attribute and letting SecurityMiddleware refuse. Both are admin-only; only
 * the framework path throws `NotAdminException` and hands back a differently
 * shaped 403 than every other route here, and these are documented operator
 * routes that somebody will eventually script against.
 *
 * If per-caller scoping is wanted later, the pattern is
 * `ScopesController::resolveRegisters()`: read with `_rbac: false`, keep
 * multitenancy ON, and compute the verdict per entity downstream.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\PermissionsAuditControllerServices;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\AdminGateInterface;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementModeInterface;
use OCA\OpenRegister\Service\Rbac\DenyResolverInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\PermissionReportScopeInterface;
use OCA\OpenRegister\Service\Rbac\ScopeAuditInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only reports over the authorization model.
 */
class PermissionsAuditController extends Controller {

	/**
	 * The keys an object deny entry may name its principal under, by precedence.
	 *
	 * @var array<int, string>
	 */
	private const PRINCIPAL_KEYS = ['principal', 'group', 'role', 'user'];

	/**
	 * The grantable set, for the declared/undeclared split.
	 *
	 * @var PermissionCatalogueInterface
	 */
	private readonly PermissionCatalogueInterface $catalogue;

	/**
	 * The staging switch, reported beside every rule.
	 *
	 * @var DenyEnforcementModeInterface
	 */
	private readonly DenyEnforcementModeInterface $enforcement;

	/**
	 * The one reader of the deny grammar.
	 *
	 * @var DenyResolverInterface
	 */
	private readonly DenyResolverInterface $denyResolver;

	/**
	 * Resolves which registers and schemas a report covers.
	 *
	 * @var PermissionReportScopeInterface
	 */
	private readonly PermissionReportScopeInterface $scope;

	/**
	 * Assembles the per-rule audit.
	 *
	 * @var ScopeAuditInterface
	 */
	private readonly ScopeAuditInterface $audit;

	/**
	 * Answers whether the caller is an admin.
	 *
	 * @var AdminGateInterface
	 */
	private readonly AdminGateInterface $adminGate;

	/**
	 * Constructor.
	 *
	 * @param IRequest       $request        Active HTTP request.
	 * @param PermissionsAuditControllerServices $services The permissions audit controller services.
	 */
	public function __construct(
		IRequest $request,
		PermissionsAuditControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->catalogue = $services->rules->catalogue;
		$this->enforcement = $services->rules->enforcement;
		$this->denyResolver = $services->rules->denyResolver;
		$this->scope = $services->rules->scope;
		$this->audit = $services->scope->audit;
		$this->adminGate = $services->scope->adminGate;

	}//end __construct()

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
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function denyPreview(?string $register = null, ?string $schema = null): JSONResponse {
		// Reading what enforcement would refuse is admin-only (see the class docblock).
		if ($this->adminGate->isAdmin() === false) {
			return $this->adminGate->refusal(doing: 'read the authorization model');
		}

		$rules = $this->previewRules(register: $register, schema: $schema);

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
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function scopeAudit(?string $register = null, ?string $schema = null): JSONResponse {
		// The per-rule audit names holders and principals, so it is admin-only.
		if ($this->adminGate->isAdmin() === false) {
			return $this->adminGate->refusal(doing: 'read the authorization model');
		}

		$rows = $this->audit->rows(registers: $this->scope->registers(filter: $register), schemas: $this->scope->schemas(filter: $schema));

		return new JSONResponse(
			['denyEnforcement' => $this->enforcement->current(), 'schemaCount' => count($rows), 'scopes' => $rows]
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
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function compareRoles(string $register, ?string $roles = null): JSONResponse {
		// Comparing roles discloses the authorization model, so it is admin-only.
		if ($this->adminGate->isAdmin() === false) {
			return $this->adminGate->refusal(doing: 'read the authorization model');
		}

		$found = $this->scope->registers(filter: $register);
		if ($found === []) {
			return new JSONResponse(['error' => sprintf('No register named "%s".', $register)], 404);
		}

		$definitions = $this->roleActionsOf(register: $found[0]);
		$wanted = $this->requestedRoles(roles: $roles, available: array_keys($definitions));

		return new JSONResponse(['register' => $register] + $this->comparison(wanted: $wanted, definitions: $definitions));
	}//end compareRoles()

	/**
	 * The comparison of the wanted roles against what the register declares.
	 *
	 * The register as asked for leads the body; the caller puts it in front.
	 *
	 * @param array<int, string>                $wanted      The role names asked about.
	 * @param array<string, array<int, string>> $definitions Role name to its declared actions.
	 *
	 * @return array<string, mixed> The comparison body, without the register.
	 */
	private function comparison(array $wanted, array $definitions): array {
		$missing = array_values(array_diff($wanted, array_keys($definitions)));
		$rows = $this->compared(
			roles: array_values(array_intersect($wanted, array_keys($definitions))),
			definitions: $definitions
		);

		return [
			'roles' => $rows,
			'unknownRoles' => $missing,
			'shared' => $this->sharedActions(rows: $rows),
			'onlyIn' => $this->exclusiveActions(rows: $rows),
		];
	}//end comparison()

	/**
	 * Each compared role's actions, and those of them the catalogue does not declare.
	 *
	 * @param array<int, string>                $roles       The roles both asked about and declared.
	 * @param array<string, array<int, string>> $definitions Role name to its declared actions.
	 *
	 * @return array<string, array{actions: array<int, string>, undeclared: array<int, string>}> The rows.
	 */
	private function compared(array $roles, array $definitions): array {
		$rows = [];
		foreach ($roles as $role) {
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

		return $rows;
	}//end compared()

	/**
	 * The role name to actions map a register declares.
	 *
	 * `extends` is not folded in here. The hierarchy is resolved in
	 * PermissionHandlerInterface, and a second walk of it in a reporting endpoint could
	 * disagree with the one that actually decides, which would make a comparison
	 * screen confidently wrong.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, array<int, string>> Role name to its declared actions.
	 */
	private function roleActionsOf(Register $register): array {
		$map = [];
		foreach ($this->roleDefinitions(register: $register) as $definition) {
			if (is_array($definition) === false || isset($definition['name']) === false) {
				continue;
			}

			$map[(string)$definition['name']] = $this->declaredActions(definition: $definition);
		}

		return $map;
	}//end roleActionsOf()

	/**
	 * The role definitions a register's configuration carries, as written.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<mixed> The `roles` list, or none when it is not a list.
	 */
	private function roleDefinitions(Register $register): array {
		$configuration = $register->getConfiguration();
		$definitions = [];
		if (is_array($configuration) === true) {
			$definitions = ($configuration['roles'] ?? []);
		}

		if (is_array($definitions) === false) {
			return [];
		}

		return $definitions;
	}//end roleDefinitions()

	/**
	 * The string actions one role definition declares.
	 *
	 * @param array<string, mixed> $definition The role definition.
	 *
	 * @return array<int, string> Its actions.
	 */
	private function declaredActions(array $definition): array {
		$actions = ($definition['actions'] ?? []);
		if (is_array($actions) === false) {
			$actions = [];
		}

		return array_values(array_filter($actions, 'is_string'));
	}//end declaredActions()

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
	 * The deny rules of the registers, then of the schemas, a preview covers.
	 *
	 * @param string|null $register Optional register filter (id|uuid|slug).
	 * @param string|null $schema   Optional schema filter (id|uuid|slug).
	 *
	 * @return array<int, array<string, mixed>> The report entries.
	 */
	private function previewRules(?string $register, ?string $schema): array {
		return array_merge(
			$this->rulesOf(entities: $this->scope->registers(filter: $register), level: 'register'),
			$this->rulesOf(entities: $this->scope->schemas(filter: $schema), level: 'schema')
		);
	}//end previewRules()

	/**
	 * Flatten the deny rules of registers or schemas into report entries.
	 *
	 * @param array<int, Register|Schema> $entities The registers or schemas.
	 * @param string                                             $level    Where their blocks live.
	 *
	 * @return array<int, array<string, mixed>> One entry per verb and principal, in entity order.
	 */
	private function rulesOf(array $entities, string $level): array {
		$rules = [];
		foreach ($entities as $entity) {
			$rules = array_merge(
				$rules,
				$this->rulesIn(
					authorization: $entity->getAuthorization(),
					origin: ['level' => $level, 'subject' => (string)($entity->getSlug() ?? $entity->getTitle() ?? '')]
				)
			);
		}

		return $rules;
	}//end rulesOf()

	/**
	 * Flatten one block's deny rules into report entries.
	 *
	 * @param array|null                             $authorization The block as written.
	 * @param array{level: string, subject: string} $origin        Where the block lives, and which register or schema it belongs to.
	 *
	 * @return array<int, array<string, mixed>> One entry per verb and principal.
	 */
	private function rulesIn(?array $authorization, array $origin): array {
		$entries = [];
		foreach ($this->actionRules(authorization: $authorization) as $action => $rules) {
			foreach ($rules as $rule) {
				$entries[] = $this->entry(rule: $rule, at: $origin + ['action' => $action]);
			}
		}

		return $entries;
	}//end rulesIn()

	/**
	 * The deny lists of one block, keyed by verb, skipping malformed entries.
	 *
	 * @param array|null $authorization The block as written.
	 *
	 * @return array<string, array<mixed>> Verb to its deny list.
	 */
	private function actionRules(?array $authorization): array {
		$valid = [];
		foreach ($this->denyResolver->denyBlock(authorization: $authorization) as $action => $rules) {
			if (is_string($action) === true && is_array($rules) === true) {
				$valid[$action] = $rules;
			}
		}

		return $valid;
	}//end actionRules()

	/**
	 * One report entry for one deny rule.
	 *
	 * @param mixed                                                 $rule The rule as written.
	 * @param array{level: string, subject: string, action: string} $at   Where the block lives, which register or schema it
	 *                                                                    belongs to, and the verb the rule removes.
	 *
	 * @return array<string, mixed> The entry: the place first, then what the rule says.
	 */
	private function entry(mixed $rule, array $at): array {
		return $at + [
			'principal' => $this->principalOf(rule: $rule),
			'conditional' => (is_array($rule) === true && isset($rule['match']) === true),
			'match' => $this->matchOf(rule: $rule),
			'declared' => $this->catalogue->isGrantable($at['action']),
		];
	}//end entry()

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

		return $this->namedPrincipal(rule: $rule);
	}//end principalOf()

	/**
	 * The first non-empty name an object entry gives, by key precedence.
	 *
	 * @param array<string, mixed> $rule The entry.
	 *
	 * @return string|null The principal, or null when it names none.
	 */
	private function namedPrincipal(array $rule): ?string {
		foreach (self::PRINCIPAL_KEYS as $key) {
			if ($this->isName(value: ($rule[$key] ?? null)) === true) {
				return $rule[$key];
			}
		}

		return null;
	}//end namedPrincipal()

	/**
	 * Whether a value is a usable principal name.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a non-empty string.
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isName()


}//end class
