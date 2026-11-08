<?php

/**
 * PermissionHandler - RBAC and Permission Management Handler
 *
 * Handles all permission checking, RBAC enforcement, and multi-tenancy filtering.
 * This handler centralizes authorization logic that was previously scattered
 * throughout ObjectService, making security policies more maintainable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-model-hierarchy-register-schema-object-property
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-named-role-definitions-on-registers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ActionEvaluatedEvent;
use OCA\OpenRegister\Event\CustomScopeEvaluatedEvent;
use OCA\OpenRegister\Event\CustomScopeEvaluatingEvent;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionDataServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices;
use OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixPersonValues;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps;
use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\ProvenanceResolver;
use OCA\OpenRegister\Service\Rbac\RoleBlockExpander;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PermissionHandler class
 *
 * Handles permission operations including:
 * - RBAC permission checking
 * - User and group authorization
 * - Multi-tenancy filtering
 * - Object ownership verification
 *
 * Conditional match evaluation (rules with a `match` clause) is delegated to
 * {@see \OCA\OpenRegister\Service\ConditionMatcher} — the single shared PHP-side
 * matcher used across the RBAC stack (ADR-011). Do not reimplement condition
 * evaluation locally. For SQL-side conditional filtering (list endpoints), see
 * {@see \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler::applyRbacFilters()}.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)           Three over, from the private-scope work:
 *   objectScope() / objectGrants() resolve the two shared resolvers, and privateScopeVerdict()
 *   is the scope gate. Splitting them out would put part of one access decision in another
 *   class, which is exactly the drift this change exists to prevent.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Permission evaluation requires per-action and per-role branching
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    RBAC methods are cohesive units; splitting scatters the security policy without reducing it
 * @SuppressWarnings(PHPMD.NPathComplexity)          RBAC rules handle user/group/owner/public/conditional combos - cartesian product drives NPath
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   RBAC needs IUserSession, IUserManager, IGroupManager, ConditionMatcher, Register/Schema mappers
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     All RBAC logic is centralised per ADR-011; splitting would re-scatter the security policy
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     stripMcpScope()/mcpOfferedActions() are public so the SQL-layer
 *      interpreter applies the IDENTICAL transform; a private copy is how two evaluators drift apart
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class PermissionHandler implements PermissionHandlerInterface {

	/**
	 * Per-request cache for register authorization lookups.
	 *
	 * Maps register ID to its authorization array (or null if no authorization).
	 * Avoids repeated DB queries when checking permissions for multiple schemas
	 * in the same register within a single request.
	 *
	 * @var array<int, array|null>
	 */
	private array $cachedRegisterAuth = [];

	/**
	 * Per-request cache of the resolved inheritFromPublic value, keyed by schema id.
	 *
	 * @var array<int, bool>
	 */
	private array $cachedInheritFromPublic = [];

	/**
	 * Per-request cache for register configuration (roles).
	 *
	 * Maps register ID to its configuration array.
	 *
	 * @var array<int, array|null>
	 */
	private array $cachedRegisterConfig = [];

	/**
	 * Per-request memoisation cache for hasPermission() verdicts.
	 *
	 * Hot list endpoints invoke `hasPermission()` once per row. The schema-level
	 * authorization plus user/group membership is identical across rows, so we
	 * cache the verdict keyed on the inputs that actually influence it:
	 * `(userId|null, schemaId, action, objectOwner|null, objectUuid|null)`.
	 *
	 * Object UUID is part of the key so conditional rules with `match` clauses
	 * still re-evaluate per object — same UUID within a request guarantees same
	 * underlying object data, which is the only safe reuse window.
	 *
	 * Implements the "scope caching for performance" requirement of the
	 * rbac-scopes spec (see openspec/changes/rbac-scopes/specs/rbac-scopes/spec.md).
	 *
	 * @var array<string, bool>
	 */
	private array $permissionCache = [];

	/**
	 * Cache keys whose refusal came from an explicit deny or an unresolvable block.
	 *
	 * Such a refusal is final: a declared action's elevation never turns it into a grant.
	 *
	 * @var array<string, true>
	 */
	private array $finalRefusals = [];

	/**
	 * Whether the last evaluatePermission() refused through an explicit deny or an unresolvable block.
	 *
	 * @var bool
	 */
	private bool $lastRefusalFinal = false;

	/**
	 * The person-source reader for department matrices, built on first use.
	 *
	 * @var DepartmentMatrixPersonValues|null
	 */
	private ?DepartmentMatrixPersonValues $personValues = null;

	/**
	 * The asker of app-contributed matrix rows, built on first use.
	 *
	 * @var MatrixRowsFromApps|null
	 */
	private ?MatrixRowsFromApps $matrixRowsFromApps = null;

	/**
	 * The five canonical action verbs the static rule chain knows.
	 * Anything outside this set is treated as a custom verb and
	 * routed through `CustomScopeEvaluatingEvent` so consuming apps
	 * can contribute a verdict (per the rbac-scopes change, decision
	 * 2026-05-02 option A).
	 *
	 * @var string[]
	 */
	private const CANONICAL_ACTIONS = [
		'read',
		'create',
		'update',
		'delete',
		'list',
		// `destroy` is a SECOND, narrower right than `delete`. Deleting puts an
		// object in the trash, where it can come back; destroying ends it. They
		// were one check until the delete window landed, which is why "admin
		// SHOULD be enforced" was the only thing the spec could say about the
		// destructive verb. See DestroyRightService.
		'destroy',
		// `export` is a SECOND, narrower right than `read`. Reading a record and
		// taking the set off the instance are different acts, and an export
		// gated behind `read` is an export every reader holds. Deliberately NOT
		// in the fail-closed write lists below: an export reads, and shipping it
		// denied would break every instance on upgrade (design D-2). See
		// ExportRightService for the read fallback that keeps that promise.
		'export',
		// `assign` joins the canonical set HERE as well as in
		// PermissionCatalogue, and the duplication is the point: this list
		// decides whether a verb dispatches a custom-scope evaluation, and
		// the catalogue decides whether a block may name it. A verb in one
		// and not the other is canonical on one path and custom on the
		// other, which is two answers to "what kind of verb is this" and
		// exactly the divergence the catalogue exists to end (row 13.40).
		'assign',
		// The file verbs, canonical on this path for the same reason.
		'readFiles',
		'addFiles',
		'deleteFiles',
		// The grants list of an object, canonical on this path for the same
		// reason. It never resolves default-open: AccessReviewGate only asks
		// when the cascaded block writes it.
		'readGrants',
	];

	/**
	 * The reserved scope token marking an action as one that MAY be offered to
	 * an agent.
	 *
	 * 🔴 It is a DESCRIPTION of a surface, never a grant. Whether a specific
	 * agent holds a right stays resolved in Hermiq against that agent's own
	 * grants — Nextcloud groups are per USER and cannot separate two agents
	 * owned by one person, so RBAC is structurally unable to answer the
	 * question and must not appear to.
	 *
	 * Named `mcp` rather than `agents` deliberately: "agents" is a credible
	 * domain group in a commercial deployment (sales agents), and a special
	 * token that collides with a real group surfaces as a privilege bug. That
	 * naming is a mitigation, not a guarantee — nothing stops an administrator
	 * creating a group called `mcp` either, which is why
	 * {@see hasGroupPermission()} refuses the match outright rather than
	 * relying on the name being unlikely.
	 *
	 * @var string
	 */
	public const SCOPE_MCP = 'mcp';

	/**
	 * Write actions that fail closed for anonymous callers.
	 *
	 * For an anonymous principal (no resolved Nextcloud user), these actions are
	 * denied unless the schema's `authorization` explicitly grants the `public`
	 * group the action. This closes the implicit default-open write hole (#1955)
	 * while preserving schemas that opt in to public submissions. Object reads are
	 * intentionally NOT listed here — read default-open is a separate policy
	 * question and is unchanged by this constant.
	 *
	 * @var string[]
	 */
	private const ANONYMOUS_FAIL_CLOSED_WRITE_ACTIONS = [
		'create',
		'update',
		'delete',
		'destroy',
		'addFiles',
		'deleteFiles',
	];

	/**
	 * Write actions affected by the `enforce_default_closed` opt-in flag.
	 *
	 * When `IAppConfig::getValueBool('openregister', 'enforce_default_closed')`
	 * is set to `true`, schemas without an `authorization` block AND without an
	 * explicit `public: true` opt-in default-CLOSED for these actions for
	 * authenticated principals (admin still bypasses; object-owner still
	 * bypasses). Reads remain default-open since `@PublicPage` is the OR-wide
	 * read model.
	 *
	 * The flag defaults to `false` for BC: the fleet ships ~15 leaf apps whose
	 * `*_register.json` files do not yet declare authorization blocks. Flipping
	 * the default would brick them overnight. The flag is the opt-in path to
	 * the next-major default flip — see the tracking issue in the PR
	 * description.
	 *
	 * @var string[]
	 */
	private const DEFAULT_CLOSED_WRITE_ACTIONS = [
		'create',
		'update',
		'delete',
		'destroy',
	];

	/**
	 * App identifier used for `IAppConfig` lookups.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * `IAppConfig` key for the default-closed enforcement opt-in flag.
	 *
	 * Default value (when unset): `false` (preserves Wave-11 behaviour).
	 *
	 * @var string
	 */
	public const CONFIG_ENFORCE_DEFAULT_CLOSED = 'enforce_default_closed';

	/**
	 * User session for getting current user.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * User manager for getting user objects.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * Group manager for checking user groups.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Mapper for schema operations.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Mapper for object entity operations.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectEntityMapper;

	/**
	 * Shared PHP-side match evaluator (ADR-011).
	 *
	 * @var ConditionMatcher
	 */
	private readonly ConditionMatcher $conditionMatcher;

	/**
	 * App config for the inheritFromPublic tenant default and the `enforce_default_closed` opt-in flag (Wave-12 Fix 2).
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Logger for permission auditing.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Container for lazy loading services.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * Optional dispatcher for custom-scope events.
	 *
	 * @var \OCP\EventDispatcher\IEventDispatcher|null
	 */
	private readonly ?\OCP\EventDispatcher\IEventDispatcher $eventDispatcher;

	/**
	 * Shared object-scope resolver; null means a fresh one.
	 *
	 * @var ObjectScopeResolver|null
	 */
	private readonly ?ObjectScopeResolver $objectScopeResolver;

	/**
	 * Shared per-object grant resolver; null means a fresh one.
	 *
	 * @var ObjectGrantResolver|null
	 */
	private readonly ?ObjectGrantResolver $objectGrantResolver;

	/**
	 * Shared deny-grammar reader; null means a fresh one.
	 *
	 * @var DenyResolver|null
	 */
	private readonly ?DenyResolver $denyResolver;

	/**
	 * The staging switch; null means one over the app config.
	 *
	 * @var DenyEnforcementMode|null
	 */
	private readonly ?DenyEnforcementMode $denyEnforcementMode;

	/**
	 * The grantable set.
	 *
	 * @var PermissionCatalogue|null
	 */
	private readonly ?PermissionCatalogue $permissionCatalogue;

	/**
	 * Reads an entry's end and the area it is confined to.
	 *
	 * @var GrantConstraints|null
	 */
	private readonly ?GrantConstraints $grantConstraints;

	/**
	 * Access derived from identity claims; null means none.
	 *
	 * @var DerivedGrantStore|null
	 */
	private readonly ?DerivedGrantStore $derivedGrantStore;

	/**
	 * Reads a derived grant in one area.
	 *
	 * @var DerivedGrantResolver|null
	 */
	private readonly ?DerivedGrantResolver $derivedGrantResolver;

	/**
	 * Reads the grant the request's API token carries.
	 *
	 * @var TokenGrantSource|null
	 */
	private readonly ?TokenGrantSource $tokenGrantSource;

	/**
	 * Narrows the block to that token's grant.
	 *
	 * @var TokenGrantNarrower|null
	 */
	private readonly ?TokenGrantNarrower $tokenGrantNarrower;

	/**
	 * PermissionHandler constructor.
	 *
	 * The collaborators arrive in four bundles, autowired by the DI container.
	 * Every one that was nullable before still is, inside its bundle, so an
	 * unresolvable optional collaborator still lands as null here.
	 *
	 * @param PermissionCallerServices $caller Session, users, groups and app config.
	 * @param PermissionDataServices   $data   Mappers, container and logger.
	 * @param PermissionRuleServices   $rules  Match evaluator, dispatcher and scope resolvers.
	 * @param PermissionGrantServices  $grants Deny and derived-grant collaborators.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		PermissionCallerServices $caller,
		PermissionDataServices $data,
		PermissionRuleServices $rules,
		PermissionGrantServices $grants,
	) {
		$this->userSession = $caller->userSession;
		$this->userManager = $caller->userManager;
		$this->groupManager = $caller->groupManager;
		$this->schemaMapper = $data->schemaMapper;
		$this->objectEntityMapper = $data->objectEntityMapper;
		$this->conditionMatcher = $rules->conditionMatcher;
		$this->appConfig = $caller->appConfig;
		$this->logger = $data->logger;
		$this->container = $data->container;
		$this->eventDispatcher = $rules->eventDispatcher;
		$this->objectScopeResolver = $rules->objectScopeResolver;
		$this->objectGrantResolver = $rules->objectGrantResolver;
		$this->denyResolver = $grants->deny->denyResolver;
		$this->denyEnforcementMode = $grants->deny->denyEnforcementMode;
		$this->permissionCatalogue = $grants->deny->permissionCatalogue;
		$this->grantConstraints = $grants->deny->grantConstraints;
		$this->derivedGrantStore = $grants->derived->derivedGrantStore;
		$this->derivedGrantResolver = $grants->derived->derivedGrantResolver;
		$this->tokenGrantSource = $grants->derived->tokenGrantSource;
		$this->tokenGrantNarrower = $grants->derived->tokenGrantNarrower;
	}//end __construct()

	/**
	 * The shared deny resolver.
	 *
	 * Nullable-with-default for the same reason as the scope resolver above: a
	 * new required constructor argument is a fatal at every existing
	 * construction site, and this class is built by hand in a dozen tests. The
	 * resolver is a stateless value object, so a fresh instance is equivalent to
	 * the injected one.
	 *
	 * @return DenyResolver The one reader of the deny grammar.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function denyResolver(): DenyResolver {
		return ($this->denyResolver ?? new DenyResolver());
	}//end denyResolver()

	/**
	 * The shared object-scope resolver.
	 *
	 * Nullable-with-default in the constructor so adding it does not become a
	 * fatal at every existing construction site. The resolver is a stateless
	 * value object, so falling back to a fresh instance is equivalent to the
	 * injected one.
	 *
	 * @return ObjectScopeResolver The one definition of the scope vocabulary.
	 */
	private function objectScope(): ObjectScopeResolver {
		return ($this->objectScopeResolver ?? new ObjectScopeResolver());
	}//end objectScope()

	/**
	 * The shared per-object grant resolver.
	 *
	 * Unlike the scope resolver this one memoises per request, so the container
	 * instance is used rather than a fresh one — a new instance per call would
	 * re-read core's shares on every decision.
	 *
	 * @return ObjectGrantResolver|null The resolver, or null when unavailable.
	 */
	private function objectGrants(): ?ObjectGrantResolver {
		if ($this->objectGrantResolver !== null) {
			return $this->objectGrantResolver;
		}

		try {
			$resolved = $this->container->get(ObjectGrantResolver::class);
			if (($resolved instanceof ObjectGrantResolver) === true) {
				return $resolved;
			}

			return null;
		} catch (\Throwable $e) {
			// Fail CLOSED: no resolver means no grants, which hides objects
			// rather than exposing them.
			return null;
		}
	}//end objectGrants()

	/**
	 * Check if current user has permission to perform action on schema
	 *
	 * Implements the RBAC permission checking logic:
	 * - Admin group always has all permissions
	 * - Object owner always has all permissions for their specific objects
	 * - If no authorization configured, all users have all permissions
	 * - If authorization is configured but the action is not granted, access is
	 *   denied (fail-closed)
	 * - Otherwise, check if user's groups match the required groups for the action
	 *
	 * Property-level RBAC (fine-grained per-property authorization arrays, plus
	 * `writeOnly` secret stripping) is implemented in
	 * {@see \OCA\OpenRegister\Service\PropertyRbacHandler} and applied on the
	 * read path by {@see \OCA\OpenRegister\Service\Object\RenderObject::renderEntity()}.
	 * This handler owns object/schema-level RBAC only.
	 *
	 * @param Schema $schema The schema to check permissions for.
	 * @param string $action The CRUD action (create, read, update, delete).
	 * @param string|null $userId Optional user ID (defaults to current user).
	 * @param string|null $objectOwner Optional object owner for ownership check.
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param ObjectEntity|null $object Optional object entity for conditional authorization matching.
	 * @param string|null $register The register written to, for a check that names no object (a create, a delete by id).
	 *                              Only a declared action's elevation reads it.
	 *
	 * @return bool True if user has permission, false otherwise
	 *
	 * @throws Exception If user session is invalid or user groups cannot be determined
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC flag follows established API patterns
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function hasPermission(
		Schema $schema,
		string $action,
		?string $userId = null,
		?string $objectOwner = null,
		bool $_rbac = true,
		?ObjectEntity $object = null,
		?string $register = null,
	): bool {
		// RBAC disabled bypasses all permission checks. Explicitly-scoped system
		// operations (config imports at app boot, repair steps, webcron jobs run via
		// ObjectService::runAsSystem()) are trusted the same way CLI cron already is:
		// these run without a user session, and the anonymous fail-closed policy
		// (#1955) would otherwise deny the app maintaining its own objects on every boot.
		if ($_rbac === false || SystemOperationContext::isActive() === true) {
			return true;
		}

		return $this->answer(
			ask: (new PermissionAsk(schema: $schema, action: $action))
				->askedBy(userId: $userId, objectOwner: $objectOwner)
				->about(object: $object, register: $register)
		);
	}//end hasPermission()

	/**
	 * The answer to a question: the draft rule, then the cache, then the rule chain.
	 *
	 * A draft answers to its owner only (decision 180). The read predicate of the
	 * list queries keeps it from everybody else, but a check that is handed the
	 * stored object (bulk save, bulk delete, the readable audit trail, a transition,
	 * a file) is a second door onto the same object and must say the same. Asked
	 * before the cache: an object that is not a draft costs one comparison.
	 *
	 * @param PermissionAsk $ask The question.
	 *
	 * @return bool The answer.
	 */
	private function answer(PermissionAsk $ask): bool {
		if ($this->draftIsKeptFromCaller(ask: $ask) === true) {
			return false;
		}

		$cacheKey = $this->buildPermissionCacheKey(ask: $ask);
		if ($cacheKey !== null && array_key_exists($cacheKey, $this->permissionCache) === true) {
			return $this->rememberedAnswer(ask: $ask, cacheKey: $cacheKey);
		}

		return $this->freshAnswer(ask: $ask, cacheKey: $cacheKey);
	}//end answer()

	/**
	 * The answer to a question this request already decided.
	 *
	 * @param PermissionAsk $ask      The question.
	 * @param string        $cacheKey Its key in the per-request cache.
	 *
	 * @return bool The answer.
	 */
	private function rememberedAnswer(PermissionAsk $ask, string $cacheKey): bool {
		if (isset($this->finalRefusals[$cacheKey]) === true) {
			return false;
		}

		return $this->elevated(verdict: $this->permissionCache[$cacheKey], ask: $ask);
	}//end rememberedAnswer()

	/**
	 * Decide a question, remember the verdict and report the decision.
	 *
	 * Telemetry comes after the verdict is final and for BOTH outcomes: a refusal
	 * is what an audit rule most wants to hear. It sits here, on the cache MISS
	 * path, because the event marks a DECISION, and a memoised repeat within the
	 * same request is not a new one.
	 *
	 * @param PermissionAsk $ask      The question.
	 * @param string|null   $cacheKey Its key in the per-request cache, null when it must not be cached.
	 *
	 * @return bool The answer.
	 */
	private function freshAnswer(PermissionAsk $ask, ?string $cacheKey): bool {
		$verdict = $this->evaluatePermission(ask: $ask);
		$final   = $this->lastRefusalFinal;
		$this->rememberVerdict(verdict: $verdict, cacheKey: $cacheKey);
		$this->dispatchActionEvaluated(ask: $ask, granted: $verdict);

		if ($final === true) {
			return false;
		}

		return $this->elevated(verdict: $verdict, ask: $ask);
	}//end freshAnswer()

	/**
	 * Keep a verdict for the rest of the request, with whether its refusal is final.
	 *
	 * @param bool        $verdict  The verdict.
	 * @param string|null $cacheKey Its key, null when it must not be cached.
	 *
	 * @return void
	 */
	private function rememberVerdict(bool $verdict, ?string $cacheKey): void {
		if ($cacheKey === null) {
			return;
		}

		$this->permissionCache[$cacheKey] = $verdict;
		if ($this->lastRefusalFinal === true) {
			$this->finalRefusals[$cacheKey] = true;
		}
	}//end rememberVerdict()

	/**
	 * The verdict, or true when a declared action's open elevation lists this write.
	 *
	 * Asked only after the person's own rights refused, and never cached: the
	 * elevation belongs to one action run, not to the person. A write that names
	 * no register is never elevated, because an entry is scoped to one register.
	 *
	 * @param bool          $verdict The person's own verdict.
	 * @param PermissionAsk $ask     The question, with the schema, register and verb written to.
	 *
	 * @return bool The verdict after elevation.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionElevationScope is the request-scoped frame the run opened.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	private function elevated(bool $verdict, PermissionAsk $ask): bool {
		if ($verdict === true || $ask->register === null || $ask->register === '') {
			return $verdict;
		}

		return ObjectActionElevationScope::allows(schemaId: $ask->schema->getId(), registerId: $ask->register, verb: $ask->action);
	}//end elevated()

	/**
	 * Whether the object is a draft that its schema keeps from this caller, whatever the action.
	 *
	 * The one rule every explicit draft guard asks
	 * (DraftStatusPolicy::keptFromCaller()): an administrator and a system
	 * caller without a user (occ, cron, repair steps, a SystemOperationContext)
	 * are not kept from a draft, the draft's owner is not, and everybody else is,
	 * including an anonymous web caller. A schema that sets
	 * `draftsVisible: true` shows its drafts to anyone who may read the
	 * schema. This is NOT identical to the read predicate in one respect: the
	 * list query (MagicRbacHandler) also admits the owning group's members and
	 * the system user's rows outside its draft clause, while this rule knows the
	 * draft's owner only. It is deliberately the stricter of the two for those
	 * rows, so a group-owned draft that a list shows is still refused here.
	 * Every verb is refused, not only `read`: somebody who cannot
	 * read a draft cannot change, delete or read the history of it either.
	 *
	 * @param PermissionAsk $ask The question.
	 *
	 * @return bool True when the caller is refused every action on this object.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
	 */
	private function draftIsKeptFromCaller(PermissionAsk $ask): bool {
		if ($ask->object === null || $ask->object->isDraft() === false) {
			return false;
		}

		$subject = $this->callerId(userId: $ask->userId);

		return (new DraftCallerRule())->keptFromCaller(
			object: $ask->object,
			schema: $ask->schema,
			userId: $subject,
			isAdmin: in_array('admin', $this->groupsOf(userId: $subject), true),
			commandLine: $this->isCommandLine()
		);
	}//end draftIsKeptFromCaller()

	/**
	 * Whether PHP runs on the command line. A seam, so a test can read as a web request.
	 *
	 * @return bool True under the CLI SAPI.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	protected function isCommandLine(): bool {
		return PHP_SAPI === 'cli';
	}//end isCommandLine()

	/**
	 * Dispatch the per-action telemetry event.
	 *
	 * Best-effort in every direction: no dispatcher means no event, and a
	 * listener that throws is caught and logged. Audit must never be able to
	 * change an access decision — and a decision that has already been cached
	 * and returned cannot be un-made by an observer anyway, so swallowing here
	 * keeps the failure honest instead of surfacing it as a permission error.
	 *
	 * @param PermissionAsk $ask     The question.
	 * @param bool          $granted The final verdict.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	private function dispatchActionEvaluated(PermissionAsk $ask, bool $granted): void {
		if ($this->eventDispatcher === null) {
			return;
		}

		try {
			$this->eventDispatcher->dispatchTyped(
				new ActionEvaluatedEvent(schema: $ask->schema, action: $ask->action, granted: $granted, actor: $ask->userId, object: $ask->object)
			);
		} catch (\Throwable $e) {
			$this->warnWith(
				message: '[PermissionHandler] ActionEvaluatedEvent dispatch failed; verdict unaffected',
				facts: ['action' => $ask->action, 'granted' => $granted, 'error' => $e->getMessage()]
			);
		}
	}//end dispatchActionEvaluated()

	/**
	 * Log a warning with where it was raised.
	 *
	 * @param string              $message The message.
	 * @param array<string,mixed> $facts   What the log line reports.
	 *
	 * @return void
	 */
	private function warnWith(string $message, array $facts): void {
		$this->logger->warning(message: $message, context: ['file' => __FILE__, 'line' => __LINE__] + $facts);
	}//end warnWith()

	/**
	 * Build a stable cache key for hasPermission().
	 *
	 * Returns null when caching is unsafe:
	 *  - schema has no ID (unsaved entity);
	 *  - an object is supplied without a UUID (transient/in-memory entity whose
	 *    data could differ between calls and whose conditional-rule verdict
	 *    therefore cannot be reused).
	 *
	 * SECURITY: when the schema's authorization block contains any `match` rule,
	 * the verdict depends on the *current* object data, which may change within a
	 * single request via saveObject() / TransitionEngine. Cache reuse keyed on the
	 * (stable) object UUID would otherwise serve a pre-mutation verdict to a
	 * post-mutation re-check, so such schemas are never cached.
	 *
	 * The key holds the RESOLVED subject, not the argument. A null user means "use
	 * the current user", which evaluatePermission() then reads from the session, so
	 * keying on the argument gave an admin-defaulted call and an anonymous call the
	 * same key `u_` with different verdicts. That matters wherever the subject
	 * changes within a request: runAsAnonymous() and runAs() both do.
	 *
	 * @param PermissionAsk $ask The question.
	 *
	 * @return string|null Cache key, or null to bypass cache.
	 */
	private function buildPermissionCacheKey(PermissionAsk $ask): ?string {
		$schemaId = $ask->schema->getId();
		if ($schemaId === null || $this->schemaHasMatchRule(schema: $ask->schema) === true) {
			return null;
		}

		$objectKey = $this->objectCacheScope(object: $ask->object);
		if ($objectKey === null) {
			return null;
		}

		$subject = $this->callerId(userId: $ask->userId);

		return sprintf('s%d|a%s|u%s|o%s|i%s', $schemaId, $ask->action, ($subject ?? '_anon'), ($ask->objectOwner ?? '_'), $objectKey);
	}//end buildPermissionCacheKey()

	/**
	 * The object's part of the cache key; null when caching is unsafe for it.
	 *
	 * An object without a stable identity is not cached. Neither is one carrying a
	 * per-object `_authorization`: that overrides the schema baseline and may have
	 * been changed earlier in the same request.
	 *
	 * @param ObjectEntity|null $object The object, if the question names one.
	 *
	 * @return string|null The key part, `_` for no object.
	 */
	private function objectCacheScope(?ObjectEntity $object): ?string {
		if ($object === null) {
			return '_';
		}

		$uuid = $object->getUuid();
		if ($uuid === null || $uuid === '' || empty($object->getAuthorization()) === false) {
			return null;
		}

		return $uuid;
	}//end objectCacheScope()

	/**
	 * Detect whether a schema's authorization block contains any
	 * conditional `match` rules.
	 *
	 * Used to disable the per-request permission cache for schemas
	 * whose verdict depends on the current object data — see
	 * {@see buildPermissionCacheKey()}.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return bool True when at least one authorization entry carries
	 *              a non-empty `match` block.
	 *
	 * @SuppressWarnings(PHPMD.UnusedLocalVariable) `$_` is the conventional ignore name for the unused foreach key
	 */
	private function schemaHasMatchRule(Schema $schema): bool {
		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false || $authorization === []) {
			return false;
		}

		foreach ($authorization as $entries) {
			if (is_array($entries) === false) {
				continue;
			}

			foreach ($entries as $entry) {
				if (is_array($entry) === true
					&& isset($entry['match']) === true
					&& empty($entry['match']) === false
				) {
					return true;
				}
			}
		}

		return false;
	}//end schemaHasMatchRule()

	/**
	 * Evaluate the full RBAC rule chain for a permission check.
	 *
	 * This is the uncached implementation of {@see hasPermission()} — extracted
	 * so the public entry point can short-circuit on a per-request memoisation
	 * cache without obscuring the evaluation order. The order is: resolve the
	 * rules (fail-closed), the deny pass, the private scope, the caller (anonymous
	 * or unknown callers answer to `public`), the administrator bypass, derived
	 * groups, custom verbs, and then the grants.
	 *
	 * @param PermissionAsk $ask The question.
	 *
	 * @return bool True if the user has permission.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function evaluatePermission(PermissionAsk $ask): bool {
		// $activeOrganisation is resolved by ConditionMatcher itself (via OrganisationService).
		$check = $ask->check();

		if ($this->resolveCheckAuthorization(check: $check) === false
			|| $this->deniedByRule(check: $check) === true
		) {
			$this->lastRefusalFinal = true;
			return false;
		}

		$verdict = $this->grantVerdict(check: $check);
		$this->lastRefusalFinal = false;

		return $verdict;
	}//end evaluatePermission()

	/**
	 * The chain after the deny pass: the private scope, the caller, the administrator bypass, derived groups, custom verbs and the grants.
	 *
	 * @param PermissionCheck $check The check, its authorization resolved.
	 *
	 * @return bool True if the user has permission.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function grantVerdict(PermissionCheck $check): bool {
		$verdict = ($this->privateScopeVerdict(check: $check) ?? $this->callerVerdict(check: $check));
		if ($verdict !== null) {
			return $verdict;
		}

		// Admin group always has all permissions.
		if (in_array('admin', $check->userGroups) === true) {
			return true;
		}

		$this->addDerivedGroups(check: $check);

		return ($this->customVerbVerdict(check: $check) ?? $this->grantedByRules(check: $check));
	}//end grantVerdict()

	/**
	 * Resolve the effective authorization block onto the check; false when it cannot be resolved.
	 *
	 * Fail-closed: when the effective authorization cannot be resolved the action is
	 * DENIED. Previously the resolver swallowed the error and returned null, which read
	 * as "no rules configured" and granted every action to every caller (CWE-863).
	 * Per-object `_authorization` (when present) overrides schema/register rules
	 * action-by-action, hence the `object:` argument; see resolveAuthorization().
	 *
	 * @param PermissionCheck $check The check
	 *
	 * @return bool Whether the authorization was resolved
	 */
	private function resolveCheckAuthorization(PermissionCheck $check): bool {
		try {
			$check->authorization = $this->resolveAuthorization(schema: $check->schema, object: $check->object);
		} catch (AuthorizationUnresolvableException $e) {
			$this->logger->error(
				message: '[PermissionHandler] Authorization unresolvable; denying action (fail-closed)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $check->schema->getId(),
					'action' => $check->action,
					'userId' => $check->userId,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}

		return true;
	}//end resolveCheckAuthorization()

	/**
	 * The DENY pass: whether an enforced explicit deny removes the verb.
	 *
	 * Evaluated after the cascade has resolved and before the scope, the rules, the
	 * owner bypass and the custom-verb vote, because a deny is not a grant with a lower
	 * score: it removes the verb inside its scope and nothing puts it back.
	 * Administrators are exempt inside {@see denialFor()}: an access model that can deny
	 * its own administration away is recoverable only from the database. It costs
	 * nothing on an instance that declares no deny. In `staging` mode (the default,
	 * D15) the denial is recorded and the chain carries on; the switch is read in
	 * {@see enforcedDenialFor()} so this call site and the list query in
	 * MagicRbacHandler cannot drift apart on it.
	 *
	 * @param PermissionCheck $check The check
	 *
	 * @return bool Whether the action is denied
	 */
	private function deniedByRule(PermissionCheck $check): bool {
		$denial = $this->enforcedDenialFor(
			authorization: $check->authorization,
			action: $check->action,
			userId: $check->userId,
			object: $check->object,
			context: ['schemaId' => $check->schema->getId(), 'path' => 'object']
		);
		if ($denial === null) {
			return false;
		}

		$this->logger->info(
			message: '[PermissionHandler] Action denied by an explicit deny rule',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaId' => $check->schema->getId(),
				'action' => $check->action,
				'userId' => $check->userId,
				'principal' => $denial['principal'],
				'rule' => $denial['rule'],
			]
		);

		return true;
	}//end deniedByRule()

	/**
	 * Resolve the caller onto the check; the verdict for an anonymous or unknown caller.
	 *
	 * A caller without a session answers to the anonymous rules; a user id that names
	 * no user is treated as `public`. Otherwise the user id and groups are set on the
	 * check and null is returned so the chain continues.
	 *
	 * @param PermissionCheck $check The check
	 *
	 * @return bool|null The verdict, or null to continue
	 */
	private function callerVerdict(PermissionCheck $check): ?bool {
		if ($check->userId === null) {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return $this->anonymousVerdict(check: $check);
			}

			$check->userId = $user->getUID();
		}

		$userObj = $this->userManager->get($check->userId);
		if ($userObj === null) {
			return $this->publicGrantForStranger(check: $check);
		}

		$check->userGroups = $this->groupManager->getUserGroupIds($userObj);

		return null;
	}//end callerVerdict()

	/**
	 * The verdict for a caller without a session.
	 *
	 * Fail-closed object writes for anonymous callers (#1955): create, update and
	 * delete are denied unless the schema explicitly grants the `public` group that
	 * action, so declared public-submission schemas still accept anonymous writes.
	 * Authenticated users are unaffected. Otherwise the `public` group decides.
	 *
	 * @param PermissionCheck $check The check
	 *
	 * @return bool
	 */
	private function anonymousVerdict(PermissionCheck $check): bool {
		if (in_array(needle: $check->action, haystack: self::ANONYMOUS_FAIL_CLOSED_WRITE_ACTIONS, strict: true) === true
			&& $this->publicGroupExplicitlyGranted(authorization: $check->authorization, action: $check->action) === false
		) {
			return false;
		}

		return $this->publicGrantForStranger(check: $check);
	}//end anonymousVerdict()

	/**
	 * Whether the `public` group grants the action to a caller who is not a known user.
	 *
	 * @param PermissionCheck $check The check
	 *
	 * @return bool
	 */
	private function publicGrantForStranger(PermissionCheck $check): bool {
		return $this->hasGroupPermission(
			authorization: $check->authorization,
			groupId: 'public',
			action: $check->action,
			userId: null,
			userGroup: null,
			objectOwner: $check->objectOwner,
			objectData: $check->objectData,
			objectOrganisation: $check->objectOrganisation
		);
	}//end publicGrantForStranger()

	/**
	 * Fold the groups derived at sign-in into the caller's groups.
	 *
	 * Done after the administrator check, so a derivation rule can never make somebody
	 * an administrator. They are ordinary group ids matched by the ordinary rules, so
	 * nothing downstream has to know they were derived (task 8.1).
	 *
	 * @param PermissionCheck $check The check
	 *
	 * @return void
	 */
	private function addDerivedGroups(PermissionCheck $check): void {
		$derived = $this->derivedGroupsFor(userId: $check->userId, schema: $check->schema);
		if ($derived !== []) {
			$check->userGroups = array_values(array_unique(array_merge($check->userGroups, $derived)));
		}
	}//end addDerivedGroups()

	/**
	 * The verdict for a custom action verb, or null to continue with the rules.
	 *
	 * Verbs outside the canonical five are routed through a listener-driven dispatch so
	 * consuming apps can decide verbs they own (e.g. ZGW `besluit_nemen`); the first
	 * vote wins. This MUST precede the `authenticated` grant, which is default-open for
	 * a schema without an authorization block and would otherwise return true before
	 * any listener is consulted. Administrators are decided before this and stay
	 * un-vetoable.
	 *
	 * A declared verb that nobody voted on fails closed, naming the app that owes the
	 * listener (design D-2): falling through would let the schema's own rules answer
	 * for a verb the declaring app said it owns.
	 *
	 * @param PermissionCheck $check The check
	 *
	 * @return bool|null The verdict, or null to continue
	 */
	private function customVerbVerdict(PermissionCheck $check): ?bool {
		if (in_array(needle: $check->action, haystack: self::CANONICAL_ACTIONS, strict: true) === true
			|| $this->eventDispatcher === null
		) {
			return null;
		}

		$verdict = $this->dispatchCustomScopeEvaluation(check: $check);
		if ($verdict !== null) {
			return $verdict;
		}

		$declaration = $this->permissionCatalogue()->declarationFor($check->action);
		if ($declaration === null || $declaration['canonical'] !== false) {
			return null;
		}

		return $this->refuseUnevaluatedVerb(check: $check, declaration: $declaration);
	}//end customVerbVerdict()

	/**
	 * Refuse a declared custom verb nobody voted on, naming the app that owes the listener.
	 *
	 * @param PermissionCheck      $check       The check
	 * @param array<string, mixed> $declaration The verb's declaration
	 *
	 * @return false
	 */
	private function refuseUnevaluatedVerb(PermissionCheck $check, array $declaration): bool {
		$this->logger->warning(
			message: '[PermissionHandler] A declared custom verb has no evaluator; refusing',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'action' => $check->action,
				'declaredBy' => $declaration['app'],
				'schemaId' => $check->schema->getId(),
				'userId' => $check->userId,
			]
		);

		return false;
	}//end refuseUnevaluatedVerb()

	/**
	 * Whether a grant admits the caller; a refusal is logged with the rule behind it.
	 *
	 * The grants, in order: the `authenticated` pseudo-group (any logged-in user, also
	 * one without groups, symmetric with MagicRbacHandler), a user-level override
	 * through the sentinel group id '' (which only the user-override branch of
	 * hasGroupPermission can match), each of the caller's groups, and `public` unless
	 * inheritFromPublic is switched off for the schema, register or tenant.
	 *
	 * @param PermissionCheck $check The check
	 *
	 * @return bool
	 */
	private function grantedByRules(PermissionCheck $check): bool {
		foreach (['authenticated', '', ...$check->userGroups] as $groupId) {
			if ($this->groupGrants(check: $check, groupId: $groupId) === true) {
				return true;
			}
		}

		if ($this->resolveInheritFromPublic(schema: $check->schema) === true
			&& $this->groupGrants(check: $check, groupId: 'public') === true
		) {
			return true;
		}

		// The refusal names the rule that produced it (task 3.3).
		$this->logRefusal(check: $check);

		return false;
	}//end grantedByRules()

	/**
	 * Whether one group grants the action to the signed-in caller.
	 *
	 * @param PermissionCheck $check   The check
	 * @param string          $groupId The group id
	 *
	 * @return bool
	 */
	private function groupGrants(PermissionCheck $check, string $groupId): bool {
		return $this->hasGroupPermission(
			authorization: $check->authorization,
			groupId: $groupId,
			action: $check->action,
			userId: $check->userId,
			objectOwner: $check->objectOwner,
			objectData: $check->objectData,
			objectOrganisation: $check->objectOrganisation
		);
	}//end groupGrants()

	/**
	 * Record a refusal with the rule behind it.
	 *
	 * `rule` is the entry list written for this verb in the cascaded block: the
	 * grant the caller is not in, which is the thing an administrator opens.
	 * When the block names no such key, the refusal came from a policy rather
	 * than from a rule, and `rule` is null with `reason` saying which.
	 *
	 * Info level, like the deny refusal beside it. A refusal is ordinary
	 * traffic; it is only worth a warning when it surprises somebody, and this
	 * log exists so that it does not.
	 *
	 * @param PermissionCheck $check The refused check: its cascaded block, verb, caller,
	 *                               caller's groups, schema and the row, when there was one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function logRefusal(PermissionCheck $check): void {
		$authorization = $check->authorization;
		$action = $check->action;
		$userId = $check->userId;
		$userGroups = $check->userGroups;
		$schema = $check->schema;
		$object = $check->object;
		$rule = null;
		$reason = 'no authorization block names this verb';
		if (is_array($authorization) === true && isset($authorization[$action]) === true) {
			$rule = $authorization[$action];
			$reason = 'the rule for this verb does not name this caller';
		}

		$this->logger->info(
			message: '[PermissionHandler] Action refused; the rule that decided it is named below',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schemaId' => $schema->getId(),
				'objectUuid' => $object?->getUuid(),
				'action' => $action,
				'userId' => $userId,
				'principals' => $userGroups,
				'rule' => $rule,
				'reason' => $reason,
			]
		);
	}//end logRefusal()

	/**
	 * The deny rule that is allowed to change this answer, or null.
	 *
	 * The ENFORCED verdict, which is the pure resolution in
	 * {@see denialFor()} read through the switch in
	 * {@see \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode}. Every
	 * enforcement point calls this one and none of them reads the switch itself,
	 * so the object read and the list query cannot end up in different modes
	 * inside one request.
	 *
	 * THE THREE MODES, in the order they cost anything:
	 *
	 *  - `off`       nothing is resolved and nothing is recorded.
	 *  - `staging`   the denial is resolved, recorded and dropped. The default.
	 *  - `enforcing` the denial is resolved and returned.
	 *
	 * The administrator exemption and the precedence rule live one method down,
	 * in the resolution itself, because they are properties of the deny and not
	 * of the rollout.
	 *
	 * @param array|null           $authorization The already-cascaded authorization block.
	 * @param string               $action        The verb being decided.
	 * @param string|null          $userId        The caller, or null to resolve from the session.
	 * @param ObjectEntity|null    $object        The row under evaluation, when there is one.
	 * @param array<string, mixed> $context       Where this happened, for the staging record.
	 *
	 * @return array{rule: mixed, principal: string, action: string, conditional: bool}|null
	 *         The denial that decides it, or null when none reaches this caller
	 *         or the instance is not enforcing yet.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function enforcedDenialFor(
		?array $authorization,
		string $action,
		?string $userId = null,
		?ObjectEntity $object = null,
		array $context = [],
	): ?array {
		$mode = $this->denyEnforcementMode();

		// `off`. The incident switch. Nothing is resolved and nothing is
		// recorded, because the reason an administrator reaches for this value
		// is that the deny is refusing people it should not.
		if ($mode->resolves() === false) {
			return null;
		}

		$denial = $this->denialFor(
			authorization: $authorization,
			action: $action,
			userId: $userId,
			object: $object
		);
		if ($denial === null) {
			return null;
		}

		if ($mode->enforces() === true) {
			return $denial;
		}

		// `staging`. The denial is real, and it is recorded, and it changes
		// nothing. Returning null here rather than skipping the resolution above
		// is the whole difference between a dry run and a disabled feature.
		$mode->record(
			denial: $denial,
			action: $action,
			userId: $this->callerId(userId: $userId),
			context: $context
		);

		return null;
	}//end enforcedDenialFor()

	/**
	 * The verbs this caller may exercise on one row.
	 *
	 * Resolved through {@see hasPermission()}, verb by verb, which is the same
	 * decision the read itself made and is memoised per request on
	 * `(user, schema, action, owner, uuid)`. So a record returned with its
	 * actions costs the resolutions the client would have had to provoke
	 * anyway, and the client stops guessing: today it either renders a delete
	 * button nobody may press, or hides one somebody may (design D-9).
	 *
	 * THE SCHEMA VERBS ARE NOT IN THE LIST. `create`, `list` and `manage` are
	 * answers about the schema and the register, not about this row, and a
	 * record that carried them would invite a client to read them as rights ON
	 * the row. The row verbs are `read`, `update`, `delete`, `destroy` and every
	 * custom verb an app declared, because those are the ones an object screen
	 * offers.
	 *
	 * @param Schema            $schema The schema the row belongs to.
	 * @param ObjectEntity|null $object The row, when the question is about one.
	 * @param string|null       $userId The caller, or null to resolve from the session.
	 *
	 * @return array<int, string> The verbs, in catalogue order.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function permittedActionsFor(
		Schema $schema,
		?ObjectEntity $object = null,
		?string $userId = null,
	): array {
		$permitted = [];
		foreach ($this->rowVerbs() as $verb) {
			$granted = $this->hasPermission(
				schema: $schema,
				action: $verb,
				userId: $userId,
				objectOwner: $object?->getOwner(),
				object: $object
			);
			if ($granted === true) {
				$permitted[] = $verb;
			}
		}

		return $permitted;
	}//end permittedActionsFor()

	/**
	 * The catalogue verbs that are answers about a row.
	 *
	 * @return array<int, string> The row verbs, in catalogue order.
	 */
	private function rowVerbs(): array {
		$schemaOnly = ['create', 'list', 'manage'];

		return array_values(
			array_filter(
				$this->permissionCatalogue()->verbs(),
				static fn (string $verb): bool => in_array($verb, $schemaOnly, true) === false
			)
		);
	}//end rowVerbs()

	/**
	 * Why this caller may or may not do each of these verbs.
	 *
	 * The answer per action names the rule that decided it: the object's own
	 * block, the schema rule, the register default, the named role, or the deny
	 * that removed it. A security officer asking "why can this person update
	 * this dossier" got a yes before this existed, which answers a different
	 * question, and an absence had no reason at all.
	 *
	 * THE COST IS A FIELD, NOT A PASS. The cascade is resolved once here, the
	 * same resolution the verdict uses, and the levels are then read back for
	 * the naming. Nothing is evaluated twice (ADR-009).
	 *
	 * STAGED DENIES RIDE ALONG. Below `enforcing` the grant stands and the deny
	 * that would remove it is reported as `stagedDeny`, so the field that says
	 * why a person may act also says what stops them the day the switch moves.
	 *
	 * @param Schema             $schema  The schema being reported on.
	 * @param array<int, string> $actions The verbs to report.
	 * @param string|null        $userId  The caller, or null to resolve from the session.
	 * @param ObjectEntity|null  $object  The row, when the question is about one.
	 *
	 * @return array<string, array<string, mixed>> The provenance, keyed by action.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function provenanceFor(Schema $schema, array $actions, ?string $userId = null, ?ObjectEntity $object = null): array {
		$userId = $this->callerId(userId: $userId);
		$principals = $this->denyResolver()->principalsFor(userId: $userId, userGroups: $this->groupsOf(userId: $userId));

		try {
			$cascaded = $this->resolveAuthorization(schema: $schema, object: $object);
		} catch (\Throwable $e) {
			// An unresolvable cascade is reported as unresolvable rather than as
			// an absence. "Nobody granted you this" and "the rules could not be
			// read" send an administrator to two different screens.
			return array_fill_keys($actions, ['source' => 'unresolvable', 'granted' => false]);
		}

		$blocks = $this->provenanceBlocks(schema: $schema, object: $object);
		$denials = [];
		foreach ($actions as $action) {
			$denials[$action] = $this->denialFor(authorization: $cascaded, action: $action, userId: $userId, object: $object);
		}

		$resolver = new ProvenanceResolver(denyResolver: $this->denyResolver());
		$enforced = $this->denyEnforcementMode()->enforces();

		return $resolver->forActions(actions: $actions, principals: $principals, blocks: $blocks, denials: $denials, enforced: $enforced);
	}//end provenanceFor()

	/**
	 * The blocks provenance reads: object, schema, register and the register's role definitions.
	 *
	 * @param Schema            $schema The schema.
	 * @param ObjectEntity|null $object The object, when there is one.
	 *
	 * @return array<string, array|null> The blocks.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function provenanceBlocks(Schema $schema, ?ObjectEntity $object): array {
		$register = $this->getRegisterForSchema(schema: $schema);
		$registerAuthorization = null;
		if ($register !== null) {
			$registerAuthorization = $this->getRegisterAuthorization(registerId: $register->getId());
		}

		return [
			'object' => $object?->getAuthorization(),
			'schema' => $schema->getAuthorization(),
			'register' => $registerAuthorization,
			'roleDefinitions' => $this->getRoleDefinitionsForSchema(schema: $schema),
		];
	}//end provenanceBlocks()

	/**
	 * The named caller, or the session user when none is named.
	 *
	 * @param string|null $userId The named caller.
	 *
	 * @return string|null The caller, null when anonymous.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function callerId(?string $userId): ?string {
		return ($userId ?? $this->userSession->getUser()?->getUID());
	}//end callerId()

	/**
	 * The groups of a user; none for an anonymous caller or a uid that names nobody.
	 *
	 * @param string|null $userId The user.
	 *
	 * @return array<int, string> The group ids.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function groupsOf(?string $userId): array {
		if ($userId === null) {
			return [];
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return [];
		}

		return $this->groupManager->getUserGroupIds($user);
	}//end groupsOf()

	/**
	 * The grantable permission set.
	 *
	 * Nullable-with-default like the resolvers above. A fresh instance built
	 * from the same dispatcher answers the same catalogue, and one built
	 * WITHOUT a dispatcher answers the canonical verbs alone, which is the
	 * fail-closed direction: it can only make a verb unknown, never grantable.
	 *
	 * @return PermissionCatalogue The set that can be granted here.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function permissionCatalogue(): PermissionCatalogue {
		return ($this->permissionCatalogue ?? new PermissionCatalogue(eventDispatcher: $this->eventDispatcher));
	}//end permissionCatalogue()

	/**
	 * The deny enforcement switch.
	 *
	 * Nullable-with-default in the constructor for the same reason as the
	 * resolvers above, and falling back to a fresh instance is equivalent
	 * because it reads the same app-config key.
	 *
	 * @return DenyEnforcementMode The one reader of `openregister.deny_enforcement`.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function denyEnforcementMode(): DenyEnforcementMode {
		return ($this->denyEnforcementMode ?? new DenyEnforcementMode(
			appConfig: $this->appConfig,
			logger: $this->logger
		));
	}//end denyEnforcementMode()

	/**
	 * The deny rule that removes this verb from this caller, or null.
	 *
	 * The PURE resolution, with no reference to the enforcement switch. It
	 * answers "which rule denies this", which is the question provenance asks in
	 * every mode, including `staging` where the answer is reported and not
	 * applied. Callers that want the enforced verdict call
	 * {@see enforcedDenialFor()} instead.
	 *
	 * This is the whole of the negative half of the model, and it is one method
	 * so that the object read, the relation-path check and both list emitters
	 * cannot honour different halves of it.
	 *
	 * PRECEDENCE. A deny is checked before every grant, including the owner
	 * bypass and a grant inherited from an ancestor object. Deny wins; that is
	 * the only rule that keeps the answer predictable, and letting a more
	 * specific grant put the verb back is how a deny quietly stops working.
	 *
	 * THE ADMINISTRATOR IS EXEMPT, on purpose and in one place. A deny that
	 * reached the `admin` group could remove the right to edit the rules that
	 * removed it, and an instance in that state is recoverable only from the
	 * database. The save-time refusal in {@see
	 * \OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator} guards the
	 * register's own `manage` verb; this guards the platform administrator.
	 *
	 * A CONDITIONAL DENY NEEDS A ROW. An entry carrying a `match` clause removes
	 * the verb only for the objects the clause selects, so with no object in
	 * hand it denies nothing: the caller is asking about the schema, not about a
	 * row, and refusing there would turn a row-scoped rule into a blanket one.
	 *
	 * @param array|null        $authorization The already-cascaded authorization block.
	 * @param string            $action        The verb being decided.
	 * @param string|null       $userId        The caller, or null to resolve from the session.
	 * @param ObjectEntity|null $object        The row under evaluation, when there is one.
	 *
	 * @return array{rule: mixed, principal: string, action: string, conditional: bool}|null
	 *         The denial that decided it, or null when no deny reaches this caller.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function denialFor(?array $authorization, string $action, ?string $userId = null, ?ObjectEntity $object = null): ?array {
		$resolver = $this->denyResolver();

		// The cheap exit, and the backwards-compatibility promise made
		// structural: a block with no `deny` key costs one array lookup and
		// resolves exactly as it did before this capability existed.
		if ($resolver->declaresAnyDeny(authorization: $authorization) === false) {
			return null;
		}

		$userId = $this->callerId(userId: $userId);
		$userGroups = $this->groupsOf(userId: $userId);
		if (in_array(needle: 'admin', haystack: $userGroups, strict: true) === true) {
			return null;
		}

		$principals = $resolver->principalsFor(userId: $userId, userGroups: $userGroups);

		$question = new DenialQuestion(authorization: $authorization, action: $action);

		return $this->denialAnswer(question: $question->about(principals: $principals, object: $object));
	}//end denialFor()

	/**
	 * The unconditional denial of the question, else the first conditional one that selects its object.
	 *
	 * @param DenialQuestion $question The question.
	 *
	 * @return array|null The denial.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function denialAnswer(DenialQuestion $question): ?array {
		$unconditional = $this->denyResolver()->unconditionalDenial(
			authorization: $question->authorization,
			action: $question->action,
			principals: $question->principals
		);
		if ($unconditional !== null || $question->object === null) {
			return $unconditional;
		}

		return $this->firstMatchingConditionalDenial(question: $question, object: $question->object);
	}//end denialAnswer()

	/**
	 * The first row-scoped denial whose `match` clause selects this row.
	 *
	 * The clause is evaluated through the shared {@see ConditionMatcher}, the
	 * same evaluator the grants beside it use (ADR-011). A fourth match
	 * evaluator here would be a second reading of the same grammar, and the two
	 * would only meet when a denial failed to bite. A clause on metadata no
	 * record carries can never match, so it selects every row: the deny fails
	 * closed rather than never firing.
	 *
	 * @param DenialQuestion $question The block, verb and principals.
	 * @param ObjectEntity   $object   The row under evaluation.
	 *
	 * @return array{rule: mixed, principal: string, action: string, conditional: bool}|null The denial, or null.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function firstMatchingConditionalDenial(DenialQuestion $question, ObjectEntity $object): ?array {
		$conditional = $this->denyResolver()->conditionalDenials(
			authorization: $question->authorization,
			action: $question->action,
			principals: $question->principals
		);
		if ($conditional === []) {
			return null;
		}

		$envelope = $object->getMatchEnvelope();
		foreach ($conditional as $denial) {
			$clause = ($denial['rule']['match'] ?? null);
			if (is_array($clause) === true && $this->denialSelects(match: $clause, envelope: $envelope) === true) {
				return $denial;
			}
		}

		return null;
	}//end firstMatchingConditionalDenial()

	/**
	 * Whether a denial's clause selects the row: it matches, or it names metadata no record carries.
	 *
	 * @param array<string, mixed> $match    The clause.
	 * @param array<mixed>         $envelope The row as a rule reads it.
	 *
	 * @return bool True when the denial applies.
	 */
	private function denialSelects(array $match, array $envelope): bool {
		return $this->conditionMatcher->namesUnknownMetadata(match: $match, object: $envelope) === true
			|| $this->conditionMatcher->objectMatchesConditions(object: $envelope, match: $match) === true;
	}//end denialSelects()

	/**
	 * Decide a private object, or decline to decide.
	 *
	 * Returns `true` (admit), `false` (deny), or `null` meaning "this object is
	 * not private — carry on with the normal rule chain". The null case is the
	 * overwhelmingly common one and does no user lookup at all, so an object
	 * that never declared a scope costs nothing.
	 *
	 * The check's authorization is the ALREADY-CASCADED block from
	 * {@see resolveAuthorization()}, in which the object's own `_authorization`
	 * has replaced the schema's keys. Reading the scope from it therefore gets
	 * the object-over-schema precedence for free, and gets it from the same
	 * value the rule chain is about to use.
	 *
	 * A check with NO object, or a create, is never gated. A scope is a property of an object,
	 * so with no object there is nothing to be private — and gating here would
	 * turn a schema whose DEFAULT is private into a schema nobody can create in,
	 * which inverts the meaning of a default.
	 *
	 * @param PermissionCheck $check The check, its authorization cascaded; a grant only counts when it carries the check's action.
	 *
	 * @return bool|null The verdict, or null when the object is not private.
	 */
	private function privateScopeVerdict(PermissionCheck $check): ?bool {
		// A create is asked about the incoming data (openregister#4094), which
		// is not an object yet and so cannot be private.
		$object = $check->object;
		if ($object === null || $check->action === 'create') {
			return null;
		}

		if ($this->objectScope()->declaredScope(authorization: $check->authorization) !== ObjectScopeResolver::SCOPE_PRIVATE) {
			return null;
		}

		$userId = $this->callerId(userId: $check->userId);
		if ($this->privateObjectAdmits(check: $check, userId: $userId) === true) {
			return true;
		}

		// A grant makes a private object behave, for this caller, as an ordinary
		// one — so decline to decide and let the rule chain run, because the
		// schema stays the CEILING (design D3b). `private` narrows and a grant
		// re-opens within that ceiling; neither can admit somebody the schema
		// refuses.
		if ($this->objectGrants()?->isGranted(userId: $userId, objectUuid: $object->getUuid(), action: $check->action) === true) {
			return null;
		}

		return false;
	}//end privateScopeVerdict()

	/**
	 * Whether the private object admits the caller outright: its owner, its owning group, an administrator.
	 *
	 * The OWNING GROUP is read off the object's own block, never off the cascaded
	 * one: a schema-level `ownerGroup` would hand every object of that schema to
	 * one group, which is a rule, not an owner.
	 *
	 * @param PermissionCheck $check  The check, about an object.
	 * @param string|null     $userId The resolved caller.
	 *
	 * @return bool True when the caller is admitted.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function privateObjectAdmits(PermissionCheck $check, ?string $userId): bool {
		return $this->objectScope()->admitsUnconditionally(
			userId: $userId,
			userGroups: $this->groupsOf(userId: $userId),
			objectOwner: ($check->objectOwner ?? $check->object->getOwner()),
			authorization: $check->object->getAuthorization()
		);
	}//end privateObjectAdmits()

	/**
	 * Dispatch `CustomScopeEvaluatingEvent` and collect a listener
	 * verdict. Returns null when no listener voted so the caller can
	 * fall through to the standard rule chain.
	 *
	 * @param PermissionCheck $check The check, its caller's groups resolved.
	 *
	 * @return bool|null Listener verdict, or null when no listener voted.
	 */
	private function dispatchCustomScopeEvaluation(PermissionCheck $check): ?bool {
		$event = new CustomScopeEvaluatingEvent(
			schema: $check->schema,
			action: $check->action,
			userId: $check->userId,
			userGroups: $check->userGroups,
			object: $check->object
		);
		if ($this->listenersRan(event: $event) === false) {
			return false;
		}

		return $this->listenerVerdict(check: $check, event: $event);
	}//end dispatchCustomScopeEvaluation()

	/**
	 * Run the custom-scope listeners; false when one crashed.
	 *
	 * SECURITY: fail CLOSED. A listener exception means the app that owns the
	 * verdict for this verb is unavailable. For verbs the standard rule chain has no
	 * opinion on (the common case for custom verbs like ZGW `besluit_nemen`), falling
	 * through to "deny by default" is acceptable, but for verbs where a listener has
	 * previously voted ALLOW, returning null lets the standard chain re-decide and
	 * potentially open access. The caller treats this false as a deny vote so a
	 * crashed listener cannot upgrade-to-allow.
	 *
	 * @param CustomScopeEvaluatingEvent $event The event.
	 *
	 * @return bool True when every listener ran.
	 */
	private function listenersRan(CustomScopeEvaluatingEvent $event): bool {
		try {
			$this->eventDispatcher?->dispatchTyped($event);
		} catch (Exception $e) {
			$this->warnWith(
				message: '[PermissionHandler] CustomScopeEvaluatingEvent dispatch failed — denying',
				facts: ['action' => $event->getAction(), 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end listenersRan()

	/**
	 * The listeners' vote, reported to telemetry; null when nobody voted.
	 *
	 * @param PermissionCheck            $check The check.
	 * @param CustomScopeEvaluatingEvent $event The event after the listeners ran.
	 *
	 * @return bool|null The vote.
	 */
	private function listenerVerdict(PermissionCheck $check, CustomScopeEvaluatingEvent $event): ?bool {
		if ($event->hasVerdict() === false) {
			return null;
		}

		$verdict = (bool) $event->getVerdict();
		$this->warnWhenVerbUndeclared(check: $check, verdict: $verdict);
		$this->dispatchCustomScopeEvaluated(check: $check, verdict: $verdict);

		return $verdict;
	}//end listenerVerdict()

	/**
	 * Report a listener deciding a verb no app declares.
	 *
	 * The opposite configuration error to a declared verb nobody evaluates, and the
	 * more dangerous one: the verb works, and it is invisible to every role editor
	 * and every audit that reads the catalogue. It is NOT refused here, because
	 * refusing would break the apps that vote today and were written before
	 * declarations existed. It is reported, which is what design D-2 asks for: a
	 * configuration error surfaced, never a silent grant.
	 *
	 * @param PermissionCheck $check   The check.
	 * @param bool            $verdict The listener's verdict.
	 *
	 * @return void
	 */
	private function warnWhenVerbUndeclared(PermissionCheck $check, bool $verdict): void {
		if ($this->permissionCatalogue()->isGrantable($check->action) === true) {
			return;
		}

		$this->warnWith(
			message: '[PermissionHandler] A listener decided a verb no app declares; it cannot be offered or audited',
			facts: ['action' => $check->action, 'verdict' => $verdict, 'schemaId' => $check->schema->getId()]
		);
	}//end warnWhenVerbUndeclared()

	/**
	 * Dispatch the paired telemetry event for a listener's verdict. Best-effort;
	 * listener exceptions are caught and logged so telemetry can never block the
	 * permission verdict.
	 *
	 * @param PermissionCheck $check   The check.
	 * @param bool            $verdict Final verdict.
	 *
	 * @return void
	 */
	private function dispatchCustomScopeEvaluated(PermissionCheck $check, bool $verdict): void {
		try {
			$this->eventDispatcher?->dispatchTyped(
				new CustomScopeEvaluatedEvent(schema: $check->schema, action: $check->action, userId: $check->userId, verdict: $verdict, fromListener: true)
			);
		} catch (Exception $e) {
			$this->warnWith(
				message: '[PermissionHandler] CustomScopeEvaluatedEvent dispatch failed',
				facts: ['action' => $check->action, 'error' => $e->getMessage()]
			);
		}
	}//end dispatchCustomScopeEvaluated()

	/**
	 * Reset the per-request permission verdict cache.
	 *
	 * Long-running CLI processes (e.g. background jobs that span multiple
	 * requests within a single PHP process) can call this to invalidate the
	 * memoised verdicts without instantiating a new handler.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
	 */
	public function clearPermissionCache(): void {
		$this->permissionCache = [];
		$this->finalRefusals = [];
	}//end clearPermissionCache()

	/**
	 * Clear the per-request inheritFromPublic resolution cache.
	 *
	 * The cache keys purely on schema id, which is collision-free only while
	 * schema/register/IAppConfig authorization is immutable mid-request. Any
	 * path that mutates authorization and then re-reads it within the same
	 * request must bust this cache to avoid serving a stale verdict.
	 *
	 * @param int|null $schemaId Specific schema to evict, or null to clear all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function clearInheritFromPublicCache(?int $schemaId = null): void {
		if ($schemaId === null) {
			$this->cachedInheritFromPublic = [];
			return;
		}

		unset($this->cachedInheritFromPublic[$schemaId]);
	}//end clearInheritFromPublicCache()

	/**
	 * Check permission and throw exception if not granted
	 *
	 * @param Schema $schema Schema to check permissions for.
	 * @param string $action Action to check permission for.
	 * @param string|null $userId User ID to check permissions for.
	 * @param string|null $objectOwner Object owner ID.
	 * @param bool $_rbac Whether to enforce RBAC checks.
	 * @param ObjectEntity|null $object Optional object entity for conditional authorization matching.
	 * @param string|null $register The register written to, for a check that names no object.
	 *
	 * @return void
	 *
	 * @throws Exception If permission is not granted
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC flag follows established API patterns
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function checkPermission(
		Schema $schema,
		string $action,
		?string $userId = null,
		?string $objectOwner = null,
		bool $_rbac = true,
		?ObjectEntity $object = null,
		?string $register = null,
	): void {
		if ($this->hasPermission(
			schema: $schema,
			action: $action,
			userId: $userId,
			objectOwner: $objectOwner,
			_rbac: $_rbac,
			object: $object,
			register: $register
		) === false
		) {
			$user = $this->userSession->getUser();
			$userName = 'Anonymous';
			if ($user !== null) {
				$userName = $user->getDisplayName();
			}

			// Use NotAuthorizedException (HTTP 403) rather than a generic
			// Exception so controllers map an RBAC denial to a 403 Forbidden
			// response instead of leaking a 500 Internal Server Error. The
			// class extends \Exception, so any existing `catch (Exception)`
			// call sites remain backward-compatible.
			throw new NotAuthorizedException(
				message: "User '{$userName}' does not have permission to '{$action}' objects in schema '{$schema->getTitle()}'"
			);
		}
	}//end checkPermission()

	/*
	 * NOTE: there is deliberately no object-level read filter here.
	 *
	 * Listing is filtered in SQL by MagicRbacHandler::buildRbacConditionsSql()
	 * (action: 'read'), called from MagicSearchHandler — unauthorised rows are
	 * never loaded, which also keeps pagination correct. A post-load filter in
	 * this class would duplicate that gate and silently disagree with it.
	 *
	 * A `filterObjectsForPermissions()` method used to live at this spot. It had
	 * no production caller and gated visibility on `create`, so it read as the
	 * live read gate while never running — which cost a full investigation to
	 * establish (openspec/changes/fix-object-read-visibility-gate/design.md).
	 * Please do not reintroduce it; extend the SQL gate instead.
	 */

	/**
	 * Filter UUIDs based on RBAC and multi-tenancy permissions
	 *
	 * Takes an array of UUIDs, loads the corresponding objects, and filters them
	 * based on current user permissions and organization context.
	 *
	 * @param array<string> $uuids Array of object UUIDs to filter.
	 * @param bool $_rbac Whether to apply RBAC filtering.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 *
	 * @return string[] Filtered array of UUIDs
	 *
	 * @psalm-return list<string>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC/multitenancy flags follow established API patterns
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function filterUuidsForPermissions(array $uuids, bool $_rbac, bool $_multitenancy): array {
		$caller = $this->bulkCaller(rbac: $_rbac, multitenancy: $_multitenancy);

		// This must be the CROSS-TABLE lookup. `MagicMapper::findAll()` needs a
		// Register AND a Schema entity to know which magic table to read, and
		// returns `[]` — after a log warning and nothing else — when it has
		// neither. This handler has neither, so asking it here resolved nothing
		// for every input: the filter answered `[]` unconditionally, and its one
		// caller, ObjectService::deleteObjects(), then ran zero loop iterations.
		// `POST /api/bulk/{register}/{schema}/delete` accordingly deleted nothing
		// and reported nothing, answering `requested 1, deleted 0, skipped 0`.
		//
		// A bulk delete is cross-table by nature — its UUIDs may sit in any magic
		// table — which is why deleteObjects() already resolves the very same
		// UUIDs through findMultipleAcrossAllMagicTables() a few lines later.
		// Soft-deleted rows are included: bulk-deleting an already-trashed object
		// is how it is destroyed for good.
		$objects = $this->objectEntityMapper->findMultipleAcrossAllMagicTables(
			uuids: $uuids,
			includeDeleted: true
		);

		$filteredUuids = [];
		foreach ($objects as $object) {
			if ($this->bulkKeeps(caller: $caller, object: $object) === true && $object->getUuid() !== null) {
				$filteredUuids[] = $object->getUuid();
			}
		}

		return $filteredUuids;
	}//end filterUuidsForPermissions()

	/**
	 * The session caller of a bulk filter.
	 *
	 * An administrator reads every draft, so is not kept from one here (the same
	 * check hasPermission() and the read predicate make).
	 *
	 * @param bool $rbac         Whether RBAC filtering is asked for.
	 * @param bool $multitenancy The caller's multitenancy flag.
	 *
	 * @return BulkCaller The caller.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flags are the caller's posture, passed through.
	 */
	private function bulkCaller(bool $rbac, bool $multitenancy): BulkCaller {
		$user   = $this->userSession->getUser();
		$caller = new BulkCaller(userId: $user?->getUID(), commandLine: $this->isCommandLine());

		$caller->organisation       = $this->getActiveOrganisationForContext();
		$caller->checksOrganisation = $this->organisationCheckApplies(multitenancy: $multitenancy, activeOrganisation: $caller->organisation);
		$caller->isAdmin    = ($user !== null && in_array('admin', $this->groupManager->getUserGroupIds($user), true) === true);
		$caller->checksRbac = ($rbac === true && $caller->userId !== null);

		return $caller;
	}//end bulkCaller()

	/**
	 * Whether the bulk filter keeps an object.
	 *
	 * @param BulkCaller   $caller The caller.
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when the object stays in the list.
	 */
	private function bulkKeeps(BulkCaller $caller, ObjectEntity $object): bool {
		if ($caller->checksRbac === true && $this->bulkRbacAdmits(caller: $caller, object: $object) === false) {
			return false;
		}

		return $caller->checksOrganisation === false
			|| $this->belongsToOtherOrganisation(object: $object, activeOrganisation: $caller->organisation) === false;
	}//end bulkKeeps()

	/**
	 * Whether the caller may delete the object at object level.
	 *
	 * Another user's draft is left out like an object that does not exist, so the
	 * delete does not announce it. An object whose schema is gone is left out too.
	 * Property-level RBAC lives in PropertyRbacHandler on the read path.
	 *
	 * @param BulkCaller   $caller The caller.
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True when object-level RBAC admits it.
	 */
	private function bulkRbacAdmits(BulkCaller $caller, ObjectEntity $object): bool {
		$schemaId = $object->getSchema();
		if ($schemaId === null) {
			return true;
		}

		try {
			$schema = $this->schemaMapper->find($schemaId);

			return $caller->keptFromDraft(object: $object, schema: $schema) === false
				&& $this->hasPermission(schema: $schema, action: 'delete', userId: $caller->userId, objectOwner: $object->getOwner());
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return false;
		}
	}//end bulkRbacAdmits()

	/**
	 * Whether the bulk filter compares each object's organisation.
	 *
	 * Inside an organisation context it always does, and an unknown
	 * organisation then keeps only objects without one.
	 *
	 * @param bool        $multitenancy       The caller's multitenancy flag.
	 * @param string|null $activeOrganisation The caller's organisation, if any.
	 *
	 * @return bool True when objects of other organisations are dropped.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the caller's posture, passed through.
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is an ambient scope marker, read statically by design.
	 */
	private function organisationCheckApplies(bool $multitenancy, ?string $activeOrganisation): bool {
		if (OrganisationContext::multitenancy(requested: $multitenancy) === false) {
			return false;
		}

		return $activeOrganisation !== null || OrganisationContext::isActive() === true;
	}//end organisationCheckApplies()

	/**
	 * Whether an object carries an organisation other than the caller's.
	 *
	 * @param ObjectEntity $object             The object.
	 * @param string|null  $activeOrganisation The caller's organisation, if any.
	 *
	 * @return bool True when the object belongs to another organisation.
	 */
	private function belongsToOtherOrganisation(ObjectEntity $object, ?string $activeOrganisation): bool {
		$objectOrganisation = $object->getOrganisation();

		return $objectOrganisation !== null && $objectOrganisation !== $activeOrganisation;
	}//end belongsToOtherOrganisation()

	/**
	 * Get the active organisation UUID for the current context
	 *
	 * @return string|null The active organisation UUID or null if none set
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function getActiveOrganisationForContext(): ?string {
		try {
			// Use container to lazy load OrganisationService to avoid circular dependencies.
			$organisationService = $this->container->get('OCA\\OpenRegister\\Service\\OrganisationService');

			// Active organisations include the parent chain; the first is the primary one.
			$orgUuids = $organisationService->getUserActiveOrganisations();
			if (empty($orgUuids) === false) {
				return $orgUuids[0];
			}

			return $organisationService->getActiveOrganisation()?->getUuid();
		} catch (Exception $e) {
			$this->warnWith(message: '[PermissionHandler] Failed to get active organisation', facts: ['error' => $e->getMessage()]);
			return null;
		}
	}//end getActiveOrganisationForContext()

	/**
	 * Check if a specific group has permission for a CRUD action on a schema
	 *
	 * Rules:
	 * - Admin group always has all permissions
	 * - Object owner always has all permissions for their specific objects
	 * - If no authorization is set, everyone has permission
	 * - If authorization is set but the action is not listed, no one has
	 *   permission (fail-closed) — except the admin/owner bypasses above
	 *
	 * Deduplication note:
	 *   Conditional match evaluation (rules with a `match` clause) is delegated to
	 *   {@see \OCA\OpenRegister\Service\ConditionMatcher}. ConditionMatcher is the
	 *   single PHP-side conditional-match evaluator used across the RBAC stack:
	 *   PropertyRbacHandler (property-level), PermissionHandler (schema-level), and
	 *   MagicRbacHandler::hasPermission() (row-level PHP path). The SQL emission
	 *   path in MagicRbacHandler::applyRbacFilters() is the only specialised
	 *   interpreter of the same rule grammar — it produces SQL WHERE fragments
	 *   instead of PHP verdicts. Do not introduce a fourth match evaluator here.
	 *
	 * @param array|null $authorization The schema's authorization array
	 * @param string $groupId The group ID to check
	 * @param string $action The CRUD action (create, read, update, delete)
	 * @param string|null $userId Optional user ID for owner check
	 * @param string|null $userGroup Optional user group for admin check
	 * @param string|null $objectOwner Optional object owner for ownership check
	 * @param array|null $objectData Optional object data for conditional matching
	 * @param string|null $objectOrganisation Optional object organisation (folded into @self.organisation)
	 *
	 * @return bool True if the group has permission
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function hasGroupPermission(
		?array $authorization,
		string $groupId,
		string $action,
		?string $userId = null,
		?string $userGroup = null,
		?string $objectOwner = null,
		?array $objectData = null,
		?string $objectOrganisation = null,
	): bool {
		$request = (new GroupRuleRequest(authorization: $authorization, action: $action))
			->forGroup(groupId: $groupId, userGroup: $userGroup)
			->forUser(userId: $userId, objectOwner: $objectOwner)
			->onObject(data: $objectData, organisation: $objectOrganisation);

		return $this->groupRuleVerdict(request: $request);
	}//end hasGroupPermission()

	/**
	 * The verdict of {@see hasGroupPermission()}: the token ceiling, the bypasses, then the rules.
	 *
	 * @param GroupRuleRequest $request The question.
	 *
	 * @return bool True if the group has permission.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function groupRuleVerdict(GroupRuleRequest $request): bool {
		if ($this->tokenCeilingRefuses(request: $request) === true) {
			return false;
		}

		if ($this->bypassesGroupRules(request: $request) === true) {
			return true;
		}

		if (empty($request->authorization) === true || $request->publicOptIn() === true) {
			return $this->noRulesVerdict(request: $request);
		}

		return $this->anyRuleEntryGrants(request: $request);
	}//end groupRuleVerdict()

	/**
	 * Whether the token's ceiling refuses the action.
	 *
	 * 🔴 CONSULTED FIRST, AHEAD OF THE ADMIN AND OWNER BYPASSES. Both of those
	 * return true without looking at the block at all, so a grant that only
	 * rewrote the block would narrow a supplier and leave the administrator who
	 * issued them the token unnarrowed, and would let any token write its
	 * holder's OWN objects, which is most of what a supplier's token touches. A
	 * grant is a filter over its holder's rights, and a filter that the most
	 * privileged caller escapes is not one (row Q13.20, D-1).
	 *
	 * @param GroupRuleRequest $request The question.
	 *
	 * @return bool True when the ceiling refuses.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function tokenCeilingRefuses(GroupRuleRequest $request): bool {
		return ($this->tokenGrantNarrower !== null
			&& $this->tokenGrantNarrower->markerPermits(authorization: $request->authorization, action: $request->action) === false);
	}//end tokenCeilingRefuses()

	/**
	 * Whether the caller passes without the block being read: the admin group, or the object's owner.
	 *
	 * @param GroupRuleRequest $request The question.
	 *
	 * @return bool True for a bypass.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function bypassesGroupRules(GroupRuleRequest $request): bool {
		if ($request->groupId === 'admin' || $request->userGroup === 'admin') {
			return true;
		}

		return ($request->userId !== null && $request->objectOwner === $request->userId);
	}//end bypassesGroupRules()

	/**
	 * The verdict for a schema without rules, or one that opts in with `public: true`.
	 *
	 * Without an explicit opt-in, a write answers to the `enforce_default_closed`
	 * policy: refused when the operator has opted in through IAppConfig, otherwise
	 * still granted with a deprecation warning ahead of the next-major default flip
	 * (see the {@see CONFIG_ENFORCE_DEFAULT_CLOSED} docblock). Admin and owner have
	 * already passed by this point.
	 *
	 * @param GroupRuleRequest $request The question.
	 *
	 * @return bool True when the action is open.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function noRulesVerdict(GroupRuleRequest $request): bool {
		if ($request->publicOptIn() === true
			|| in_array(needle: $request->action, haystack: self::DEFAULT_CLOSED_WRITE_ACTIONS, strict: true) === false
		) {
			return true;
		}

		if ($this->isDefaultClosedEnforced() === true) {
			return false;
		}

		$this->logDefaultOpenDeprecation(action: $request->action);

		return true;
	}//end noRulesVerdict()

	/**
	 * Whether any entry of the action's rule list grants the request.
	 *
	 * Fail-closed: once a schema opts into authorization (non-empty block), an action
	 * that is not explicitly listed is denied, including for the `public` pseudo-group.
	 * `empty()` rather than `isset()` additionally denies an explicitly empty rule list
	 * (`"create": []`), which reads as "grant to nobody".
	 *
	 * 🔴 The `mcp` scope never matches a caller, whatever the caller is in. A block
	 * reaching here should already have been stripped (see stripMcpScope()), but
	 * hasGroupPermission() is public and is called with raw blocks from outside this
	 * class, so the refusal is enforced at the point of match as well. It closes an
	 * administrator creating a real Nextcloud group called `mcp`, which would turn
	 * every schema that documented an agent surface into a grant to its members.
	 *
	 * @param GroupRuleRequest $request The question.
	 *
	 * @return bool True when an entry grants.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function anyRuleEntryGrants(GroupRuleRequest $request): bool {
		if (empty($request->authorization[$request->action]) === true) {
			return false;
		}

		$entries = new GroupRuleEntries(conditionMatcher: $this->conditionMatcher);
		foreach ($request->authorization[$request->action] as $entry) {
			if (self::isMcpScopeEntry(entry: $entry) === false && $entries->grants(entry: $entry, request: $request) === true) {
				return true;
			}
		}

		return false;
	}//end anyRuleEntryGrants()

	/**
	 * Determine whether the `public` group is EXPLICITLY granted an action.
	 *
	 * Unlike {@see hasGroupPermission()}, this does NOT treat a missing
	 * `authorization` block or a missing action entry as a grant (the
	 * default-open behaviour that {@see hasGroupPermission()} relies on for
	 * authenticated users). It returns true only when the schema's
	 * `authorization[$action]` list contains a `public` reference — either as a
	 * bare string entry (`"public"`) or as a complex entry whose `group` is
	 * `public` (with or without a `match` clause). This is the opt-in signal for
	 * anonymous-write fail-closed scoping (#1955): the conditional `match`, if
	 * present, is still evaluated downstream by {@see hasGroupPermission()}.
	 *
	 * @param array|null $authorization The schema's authorization array.
	 * @param string $action The CRUD action being checked.
	 *
	 * @return bool True only when `public` is explicitly listed for the action.
	 */
	private function publicGroupExplicitlyGranted(?array $authorization, string $action): bool {
		$rules = ($authorization[$action] ?? null);
		if (is_array($rules) === false) {
			return false;
		}

		foreach ($rules as $entry) {
			if (self::entryNamesGroup(entry: $entry, group: 'public') === true) {
				return true;
			}
		}

		return false;
	}//end publicGroupExplicitlyGranted()

	/**
	 * Whether the `enforce_default_closed` opt-in flag is active.
	 *
	 * Reads `IAppConfig::getValueBool('openregister', 'enforce_default_closed', false)`.
	 * Returns `false` when no IAppConfig is wired (legacy tests using the
	 * 8-arg constructor) so behaviour stays default-open by default.
	 *
	 * Wave-12 Fix 2.
	 *
	 * @return bool True when the flag is set; false otherwise (BC default).
	 */
	private function isDefaultClosedEnforced(): bool {
		// Wave-12 injected IAppConfig as an optional trailing constructor arg and
		// guarded `$this->appConfig === null` here for BC with the then-current
		// 8-arg signature. On this lineage IAppConfig is already a REQUIRED
		// constructor dependency (it also backs the inheritFromPublic tenant
		// default), so that guard is unreachable by construction and has been
		// dropped rather than kept as an always-false comparison. The try/catch
		// below still preserves the BC default-open on an unreachable appconfig.
		try {
			return $this->appConfig->getValueBool(
				app: self::APP_ID,
				key: self::CONFIG_ENFORCE_DEFAULT_CLOSED,
				default: false
			);
		} catch (\Throwable $e) {
			// Defensive: if appconfig is unreachable, preserve BC default-open.
			return false;
		}
	}//end isDefaultClosedEnforced()

	/**
	 * Tracks per-process which (action) keys we've already deprecation-warned for.
	 *
	 * Avoids flooding the log on hot list endpoints — we only want one entry per
	 * action per request lifecycle. Resets implicitly with the PHP-FPM worker.
	 *
	 * @var array<string, bool>
	 */
	private array $deprecationWarnedActions = [];

	/**
	 * Emit a one-shot deprecation warning when a schema with no `authorization`
	 * block is granted a write action under the legacy default-open behaviour.
	 *
	 * Surfaces the gap leaf-app maintainers will need to address ahead of the
	 * next-major default flip (tracking issue in PR description). Quieted to
	 * one entry per action per request to avoid log noise on hot paths.
	 *
	 * Wave-12 Fix 2.
	 *
	 * @param string $action The CRUD action being permitted under default-open.
	 *
	 * @return void
	 */
	private function logDefaultOpenDeprecation(string $action): void {
		if (isset($this->deprecationWarnedActions[$action]) === true) {
			return;
		}

		$this->deprecationWarnedActions[$action] = true;
		$messageParts = [
			'[PermissionHandler] DEPRECATION: schema without an authorization block grants ',
			$action,
			' to any authenticated user. Set the IAppConfig flag ',
			self::APP_ID,
			':',
			self::CONFIG_ENFORCE_DEFAULT_CLOSED,
			' to true to require an explicit authorization block (or set `"public": true` on the schema to keep open writes).',
		];
		$this->logger->warning(
			message: implode('', $messageParts),
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'action' => $action,
				'flag' => self::CONFIG_ENFORCE_DEFAULT_CLOSED,
			]
		);
	}//end logDefaultOpenDeprecation()

	/**
	 * Get all groups that have permission for a specific action
	 *
	 * @param array|null $authorization The schema's authorization array
	 * @param string $action The CRUD action to check
	 *
	 * @return array Array of group IDs that have permission, or empty array if all groups have permission
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function getAuthorizedGroups(?array $authorization, string $action): array {
		// If no authorization is set, return empty array (meaning all groups).
		if (empty($authorization) === true) {
			return [];
		}

		// If action is not specified, return empty array (meaning all groups).
		if (isset($authorization[$action]) === false) {
			return [];
		}

		// Return the specific groups that have permission.
		return $authorization[$action] ?? [];
	}//end getAuthorizedGroups()

	/**
	 * Return the list of user IDs authorised to read a given object.
	 *
	 * This is used by NotifyPushListener to fan out per-user push events without
	 * iterating every Nextcloud user with a per-user permission check. The approach
	 * is query-based:
	 *
	 * 1. Resolve the effective authorization for the object's schema.
	 * 2. Extract the groups that have `read` permission.
	 * 3. Fetch member user IDs for each group via IGroupManager (one DB call per group).
	 * 4. Return the deduplicated union.
	 *
	 * Special cases:
	 *   - No authorization configured → empty array (caller should treat as "all users"
	 *     and broadcast or skip push, depending on policy).
	 *   - Authorization exists but object owner is known → always include the owner.
	 *   - `public` group in the read list → empty array (caller should treat as broadcast).
	 *   - `admin` group in the read list → empty array (caller should treat as broadcast
	 *     or resolve admin members separately).
	 *
	 * @param ObjectEntity $object The object whose authorised readers are requested.
	 *
	 * @return array<string> Deduplicated list of user IDs, or empty array when the
	 *                       object is publicly readable (no restriction) or the schema
	 *                       cannot be resolved.
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-3
	 */
	public function getReadableByUsers(ObjectEntity $object): array {
		// Null means "open / broadcast" or unresolvable: no targeted user list.
		$groupIds = $this->readerGroupIds(object: $object);
		if ($groupIds === null) {
			return [];
		}

		$userIds = $this->collectUsersFromGroups(groupIds: $groupIds);

		// Include the object owner regardless of group membership.
		$owner = $object->getOwner();
		if ($owner !== null && $owner !== '') {
			$userIds[] = $owner;
		}

		return array_values(array_unique($userIds));
	}//end getReadableByUsers()

	/**
	 * The object's schema for the reader list; null when it has none or the lookup fails.
	 *
	 * System-internal lookup: bypass RBAC + multitenancy. The listener that calls
	 * getReadableByUsers is emitting push notifications, not enforcing user-facing
	 * access on the schema itself. Without this bypass, SchemaMapper::find throws
	 * "Schema not found" when the request user's tenant doesn't own the schema
	 * (notably any OR object event that crosses tenant boundaries) and the listener
	 * silently no-ops with an empty reader list: no push fires. Issue #1454.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaForReaders(ObjectEntity $object): ?Schema {
		$schemaId = $object->getSchema();
		if ($schemaId === null) {
			return null;
		}

		try {
			return $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->warnWith(
				message: '[PermissionHandler] getReadableByUsers: schema lookup failed',
				facts: ['schemaId' => $schemaId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaForReaders()

	/**
	 * The groups that may read the schema's objects.
	 *
	 * Fail-closed: an unresolvable authorization must not broadcast, so it answers
	 * like an open schema, with no targeted user list.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string>|null The group ids, null for no targeted list.
	 */
	private function readerGroupIds(ObjectEntity $object): ?array {
		$schema = $this->schemaForReaders(object: $object);
		if ($schema === null) {
			return null;
		}

		try {
			return $this->resolveReadGroupIds(authorization: $this->resolveAuthorization(schema: $schema));
		} catch (AuthorizationUnresolvableException $e) {
			$this->logger->error(
				message: '[PermissionHandler] Authorization unresolvable; no readable users resolved (fail-closed)',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schema->getId(), 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end readerGroupIds()

	/**
	 * Resolve the list of authorised group IDs from an authorization block.
	 *
	 * Returns null (meaning "open / broadcast") when:
	 *   - the authorization block is empty
	 *   - no `read` key is present
	 *   - the read entry list is empty
	 *   - `public` or `admin` appears in the group list
	 *
	 * @param array<string,mixed>|null $authorization Resolved authorization array.
	 *
	 * @return array<string>|null Unique group IDs, or null when broadcast.
	 */
	private function resolveReadGroupIds(?array $authorization): ?array {
		if (empty($authorization) === true) {
			return null;
		}

		if (isset($authorization['read']) === false) {
			return null;
		}

		$readEntries = $authorization['read'];
		if (is_array($readEntries) === false || $readEntries === []) {
			return null;
		}

		$groupIds = array_unique($this->extractGroupIdsFromReadEntries(readEntries: $readEntries));

		$isBroadcast = in_array('public', $groupIds, true) || in_array('admin', $groupIds, true);
		if ($isBroadcast === true) {
			return null;
		}

		return $groupIds;
	}//end resolveReadGroupIds()

	/**
	 * Extract group identifier strings from a read-rule entry list.
	 *
	 * Each entry is either a plain group-id string or an array with a `group` key.
	 *
	 * @param array<mixed> $readEntries Raw entries from the `read` authorization key.
	 *
	 * @return array<string> Group identifier strings (may contain duplicates).
	 */
	private function extractGroupIdsFromReadEntries(array $readEntries): array {
		$groupIds = [];
		foreach ($readEntries as $entry) {
			if (is_string($entry) === true) {
				$groupIds[] = $entry;
				continue;
			}

			if (is_array($entry) === true && isset($entry['group']) === true && is_string($entry['group']) === true) {
				$groupIds[] = $entry['group'];
			}
		}

		return $groupIds;
	}//end extractGroupIdsFromReadEntries()

	/**
	 * Collect the user IDs of all members of the given Nextcloud groups.
	 *
	 * Groups that cannot be resolved are silently skipped.
	 *
	 * @param array<string> $groupIds List of Nextcloud group identifiers.
	 *
	 * @return array<string> Flat list of user IDs (may contain duplicates).
	 */
	private function collectUsersFromGroups(array $groupIds): array {
		$userIds = [];
		foreach ($groupIds as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$userIds[] = $user->getUID();
			}
		}

		return $userIds;
	}//end collectUsersFromGroups()

	/**
	 * Resolve the effective authorization for a schema.
	 *
	 * Precedence (highest wins):
	 *   1. Per-object `_authorization` (column on ObjectEntity)
	 *      — overrides schema/register rules for that specific object only,
	 *        merged action-by-action (an object that defines `update` overrides
	 *        the schema's `update`; actions absent from the object inherit from
	 *        the schema).
	 *   2. Schema-level `authorization` block.
	 *   3. Parent register's `authorization` block.
	 *   4. `null` (no rules configured → default-open under
	 *      {@see hasGroupPermission()} unless the
	 *      {@see CONFIG_ENFORCE_DEFAULT_CLOSED} flag is set).
	 *
	 * Role references in the authorization are expanded to action-level
	 * permissions.
	 *
	 * Wave-12 Fix 5: per-object `_authorization` was dead storage before this
	 * change. The audit at `/tmp/wave11-or-engine-primitives.md` Section F
	 * documents the gap.
	 *
	 * @param Schema $schema The schema to resolve authorization for.
	 * @param ObjectEntity|null $object Optional object entity whose `_authorization`
	 *                                  column (when non-empty) overrides
	 *                                  schema/register rules action-by-action.
	 *
	 * @return array|null The effective authorization array, or null if none configured.
	 *
	 * Returns null when NO authorization is configured anywhere in the cascade
	 * (callers treat that as open). It THROWS when the cascade could not be
	 * evaluated — callers MUST treat that as deny, never as "no rules".
	 *
	 * @throws AuthorizationUnresolvableException When the register cascade cannot be resolved. Callers MUST deny.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md#requirement-authorization-resolution-fails-closed
	 */
	public function resolveAuthorization(Schema $schema, ?ObjectEntity $object = null): ?array {
		$authorization = self::stripMcpScope(
			authorization: $this->resolveAuthorizationRaw(schema: $schema, object: $object)
		);

		// THE DEPARTMENT BY ROLE MATRIX IS COMPILED HERE, and here only (row
		// B13). This method is the one step every path takes — the object read,
		// the relation check and both list emitters all resolve through it, and
		// `MagicRbacHandler::resolveSchemaAuthorization()` delegates to it —
		// so a matrix row becomes an ordinary conditional scope before anything
		// evaluates anything. That is what makes the PHP verdict and the SQL
		// verdict identical BY CONSTRUCTION rather than by two implementations
		// agreeing, which is the property rbac-scopes requires and the one a
		// second enforcement path would quietly break.
		$authorization = $this->compileDepartmentMatrix(authorization: $authorization, schema: $schema);

		// THE END AND THE AREA ARE READ HERE, with the mcp strip, because this
		// is the one step every path takes: the object read, the relation check
		// and both list emitters all resolve through this method. A grant that
		// expired somewhere else in the cascade would be a grant that expires on
		// one surface and not on another (tasks 8.2 and 8.4).
		//
		// The area costs a register lookup, so it is only resolved when the
		// block actually declares a constraint. An instance that writes neither
		// pays one array scan.
		$constraints = $this->grantConstraints();
		if ($constraints->declaresAnyConstraint(authorization: $authorization) === false) {
			return $this->narrowByToken(authorization: $authorization, schema: $schema);
		}

		return $this->narrowByToken(
			authorization: $constraints->apply(authorization: $authorization, area: $this->areaOf(schema: $schema)),
			schema: $schema
		);
	}//end resolveAuthorization()

	/**
	 * The authorization block as DECLARED in the cascade (schema or register,
	 * then the object's own block), before the department matrix is compiled,
	 * expiries applied or a token narrows it.
	 *
	 * For a question about which rules a schema has opted into, not about what
	 * they grant now: a matrix row past its `until`, or provider rows that did
	 * not arrive, compile to nothing, and a regime decided from the compiled
	 * block would fall away with them.
	 *
	 * @param Schema            $schema The schema.
	 * @param ObjectEntity|null $object The object, whose own block layers on top.
	 *
	 * @return array<string, mixed>|null The declared block, or null when none is configured.
	 *
	 * @throws AuthorizationUnresolvableException When the register cascade cannot be resolved. Callers MUST deny.
	 *
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/file-actions/spec.md
	 */
	public function resolveDeclaredAuthorization(Schema $schema, ?ObjectEntity $object = null): ?array {
		return self::stripMcpScope(
			authorization: $this->resolveAuthorizationRaw(schema: $schema, object: $object)
		);
	}//end resolveDeclaredAuthorization()

	/**
	 * Intersect the resolved block with the grant of the token in force.
	 *
	 * Applied HERE, at the end of the one step every path takes, for the same
	 * reason the department matrix is compiled here: the object read, the
	 * relation check and both list emitters resolve through this method, and
	 * `MagicRbacHandler` delegates to it. A grant applied anywhere else would
	 * be a grant that binds on one surface and not on another (row Q13.20).
	 *
	 * @param array<string, mixed>|null $authorization The resolved block.
	 * @param Schema                    $schema        The schema being resolved.
	 *
	 * @return array<string, mixed>|null The block to evaluate.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	private function narrowByToken(?array $authorization, Schema $schema): ?array {
		if ($this->tokenGrantNarrower === null) {
			return $authorization;
		}

		$grant = $this->tokenGrantSource?->current();
		if ($grant === null) {
			return $authorization;
		}

		$registerSlug = null;
		try {
			$register = $this->getRegisterForSchema(schema: $schema);
			if ($register !== null) {
				$registerSlug = $register->getSlug();
			}
		} catch (Throwable $e) {
			// A register we cannot name is a register the grant cannot be
			// checked against. That narrows rather than widens: a grant scoped
			// by register will not cover it.
			$registerSlug = null;
		}

		return $this->tokenGrantNarrower->narrow(
			authorization: $authorization,
			grant: $grant,
			schemaSlug: $schema->getSlug(),
			registerSlug: $registerSlug
		);
	}//end narrowByToken()

	/**
	 * Compile the schema's department matrix into the block, if it declares one.
	 *
	 * The caller's own values are resolved from their Nextcloud groups by the
	 * declared prefix. THE PERSON-SCHEMA USER SOURCE IS NOT RESOLVED HERE and a
	 * matrix declaring one compiles nothing rather than compiling something
	 * narrower: reading a person object to decide authorization means resolving
	 * an object through the resolver that is mid-decision, and half a rule is
	 * worse than none. `tasks.md` records it as open.
	 *
	 * A failure compiles NOTHING and leaves the block as it was. That is the
	 * fail-closed direction for a matrix, which only ever ADDS ways to be
	 * admitted: without it the caller falls back to whatever the schema said
	 * before, and nobody is admitted by an error.
	 *
	 * A matrix that declares `rowsFrom` gets the rows its provider app adds
	 * at check time beside its declared rows (MatrixRowsFromApps).
	 *
	 * @param array<string, mixed>|null $authorization The resolved block.
	 * @param Schema                    $schema        The schema the block belongs to.
	 *
	 * @return array<string, mixed>|null The block, with the matrix's rules in it.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function compileDepartmentMatrix(?array $authorization, Schema $schema): ?array {
		$matrix = ($authorization[DepartmentMatrixCompiler::KEY] ?? null);
		if (is_array($matrix) === false) {
			return $authorization;
		}

		try {
			$matrix = $this->matrixRowsFromApps()->withAppRows(schema: $schema, matrix: $matrix);
			$compiler = new DepartmentMatrixCompiler(declaredActions: $schema->declaredActionNames());

			$userId = $this->callerId(userId: null);
			$userGroups = $this->groupsOf(userId: $userId);

			$source = $matrix['userSource'] ?? null;
			if (is_array($source) === false) {
				$source = null;
			}

			// Both sources may be declared; the caller's own values are the
			// union. The person source is read only when it is declared.
			$ownValues = $compiler->valuesFromGroups(source: $source, userGroups: $userGroups);
			if (trim((string)($source['schema'] ?? '')) !== '') {
				$ownValues = array_values(
					array_unique(array_merge($ownValues, $this->personValues()->valuesFor(source: $source, userId: $userId)))
				);
			}

			return $compiler->merge(
				authorization: $authorization,
				compiled: $compiler->compile(matrix: $matrix, ownValues: $ownValues)
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[PermissionHandler] Could not compile a department matrix; the schema keeps its own rules',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return $authorization;
		}
	}//end compileDepartmentMatrix()

	/**
	 * The asker of app-contributed matrix rows, built once.
	 *
	 * Built here rather than injected for the same reason as the resolvers
	 * above: a new required constructor argument is a fatal at every existing
	 * construction site.
	 *
	 * @return MatrixRowsFromApps The asker of app-contributed matrix rows.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function matrixRowsFromApps(): MatrixRowsFromApps {
		$this->matrixRowsFromApps ??= new MatrixRowsFromApps(
			dispatcher: $this->eventDispatcher,
			validator: new DepartmentMatrixValidator(),
			logger: $this->logger
		);

		return $this->matrixRowsFromApps;
	}//end matrixRowsFromApps()

	/**
	 * The reader of a matrix user's own values from a person schema, built once.
	 *
	 * @return DepartmentMatrixPersonValues The reader.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md#requirement-a-schema-declares-a-department-by-role-matrix-keyed-on-an-object-field
	 */
	private function personValues(): DepartmentMatrixPersonValues {
		$this->personValues ??= new DepartmentMatrixPersonValues(
			schemas: $this->schemaMapper,
			registers: $this->container->get(RegisterMapperInterface::class),
			objects: $this->objectEntityMapper,
			logger: $this->logger
		);

		return $this->personValues;
	}//end personValues()

	/**
	 * The shared reader of an entry's end and its area.
	 *
	 * Nullable-with-default in the constructor for the same reason as the
	 * resolvers above: a new required argument is a fatal at every existing
	 * construction site, and this is a stateless value object.
	 *
	 * @return GrantConstraints The reader.
	 */
	private function grantConstraints(): GrantConstraints {
		return ($this->grantConstraints ?? new GrantConstraints());
	}//end grantConstraints()

	/**
	 * Where the question is being asked, by slug and by id.
	 *
	 * Both spellings, because a rule is written with a slug and generated with
	 * an id, and a rule that works until somebody writes the other one is a
	 * failure nobody connects back to the rule.
	 *
	 * @param Schema $schema The schema being resolved.
	 *
	 * @return array<string, array<int, string>> Keys `register` and `schema`.
	 */
	private function areaOf(Schema $schema): array {
		$area = [
			'schema' => array_values(
				array_filter([$schema->getSlug(), (string)$schema->getId()], static fn (mixed $v): bool => (string)$v !== '')
			),
			'register' => [],
		];

		try {
			$register = $this->getRegisterForSchema(schema: $schema);
			if ($register !== null) {
				$area['register'] = array_values(
					array_filter(
						[$register->getSlug(), (string)$register->getId()],
						static fn (mixed $v): bool => (string)$v !== ''
					)
				);
			}
		} catch (\Throwable $e) {
			// A register that cannot be resolved leaves the area unnamed, and an
			// entry scoped to a register then matches nothing. Fail closed: a
			// scoped grant whose area is unknown must not act as an unscoped one.
			$area['register'] = [];
		}

		return $area;
	}//end areaOf()

	/**
	 * The groups this caller holds here by derivation, on top of their own.
	 *
	 * Purely additive, like every other derived thing: it never removes a group
	 * a Nextcloud administrator put somebody in, and everything it adds is
	 * scoped and re-derived at the next sign-in (task 8.1).
	 *
	 * @param string|null $userId The caller.
	 * @param Schema      $schema The schema being decided.
	 *
	 * @return array<int, string> The derived group ids.
	 */
	private function derivedGroupsFor(?string $userId, Schema $schema): array {
		if ($userId === null || $this->derivedGrantStore === null) {
			return [];
		}

		try {
			$grants = $this->derivedGrantStore->grantsFor(userId: $userId);
			if ($grants === []) {
				return [];
			}

			$resolver = ($this->derivedGrantResolver ?? new DerivedGrantResolver());

			// 🔴 A DERIVED GROUP IS NEVER A RESERVED ONE. `admin` bypasses every
			// rule in this class, and `public`, `authenticated` and `mcp` are
			// pseudo-groups the matcher reads specially. A rule that derived one
			// of them from a claim would hand an identity provider the power to
			// make somebody an administrator of this instance, which is the one
			// escalation this mechanism could introduce.
			$reserved = ['admin', 'public', 'authenticated', self::SCOPE_MCP];

			return array_values(
				array_filter(
					$resolver->groupsFor(
						grants: $grants,
						area: $this->areaOf(schema: $schema),
						constraints: $this->grantConstraints()
					),
					static fn (string $group): bool => in_array($group, $reserved, true) === false
				)
			);
		} catch (\Throwable $e) {
			// Fail closed: a derivation that cannot be read grants nothing,
			// which is the direction that cannot let somebody in by accident.
			$this->logger->warning(
				message: '[PermissionHandler] Could not read the derived grants; resolving without them',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'userId' => $userId,
					'error' => $e->getMessage(),
				]
			);

			return [];
		}//end try
	}//end derivedGroupsFor()

	/**
	 * Resolve the cascade WITHOUT stripping the `mcp` scope.
	 *
	 * Separated so the strip is a single, unmissable step on the way out
	 * rather than four edits spread over the cascade's return points.
	 *
	 * @param Schema           $schema The schema to resolve authorization for.
	 * @param ObjectEntity|null $object Optional object whose own block overrides action-by-action.
	 *
	 * @return array|null The cascaded authorization array, mcp scope included.
	 *
	 * @throws AuthorizationUnresolvableException When the register cascade cannot be resolved. Callers MUST deny.
	 */
	private function resolveAuthorizationRaw(Schema $schema, ?ObjectEntity $object = null): ?array {
		$schemaAuthorization = $schema->getAuthorization();

		// Compute the schema-or-register baseline first.
		if (empty($schemaAuthorization) === false) {
			$baseline = $this->expandRoles(authorization: $schemaAuthorization, schema: $schema);
		} else {
			$baseline = null;
			$register = $this->getRegisterForSchema(schema: $schema);
			if ($register !== null) {
				$registerAuth = $this->getRegisterAuthorization(registerId: $register->getId());
				if (empty($registerAuth) === false) {
					$baseline = $this->expandRoles(authorization: $registerAuth, schema: $schema);
				}
			}
		}

		// Wave-12 Fix 5: layer per-object overrides on top.
		if ($object === null) {
			return $baseline;
		}

		$objectAuth = $object->getAuthorization();
		if (empty($objectAuth) === false && is_array($objectAuth) === true) {
			$expandedObjectAuth = $this->expandRoles(authorization: $objectAuth, schema: $schema);
			if ($baseline === null) {
				return $expandedObjectAuth;
			}

			// Action-by-action override: keys present on the object replace the
			// schema/register values for the same key. Keys NOT on the object
			// inherit from the baseline. This lets a schema author publish a
			// base policy and let individual objects narrow or widen specific
			// actions (e.g. seal an audited record by overriding `update` /
			// `delete` to `["admin"]` only).
			$merged = array_replace($baseline, $expandedObjectAuth);

			// 🔴 The `deny` key is the ONE key that unions rather than
			// replaces. Under `array_replace`, an object declaring its own deny
			// would DROP the deny written on its schema — so writing a deny on
			// the object would REMOVE one, and "a deny is not overridden by a
			// narrower rule" would hold at every level except the one an author
			// edits most. Unioning is what makes the precedence rule true of
			// the whole cascade rather than of part of it.
			$mergedDeny = $this->denyResolver()->mergeDeny(
				baseline: $this->denyResolver()->denyBlock(authorization: $baseline),
				override: $this->denyResolver()->denyBlock(authorization: $expandedObjectAuth)
			);
			if ($mergedDeny !== []) {
				$merged[DenyResolver::DENY_KEY] = $mergedDeny;
			}

			return $merged;
		}

		return $baseline;
	}//end resolveAuthorizationRaw()

	/**
	 * Remove the `mcp` scope from a block, leaving enforcement exactly as it
	 * was before the annotation was added.
	 *
	 * The scope is removed so it can never satisfy a caller. What it does NOT
	 * do is make the block disappear: an action key emptied by the strip stays
	 * as `[]`, which the grammar reads as "grant this action to nobody", and a
	 * block that held nothing but offers remains a non-empty block.
	 *
	 * 🔴 That shape is deliberate, and an earlier cut of this method got it
	 * backwards. Collapsing an offers-only block to `null` made it "no
	 * authorization configured" — which is default-OPEN — so a schema annotated
	 * `{"read": ["mcp"]}` became readable by every authenticated user AND by
	 * anonymous callers. An author documenting an agent surface would have
	 * published the schema instead of restricting it.
	 *
	 * The reasoning that produced it was: a descriptive annotation should not
	 * change enforcement, so an mcp-only block should evaluate as though absent.
	 * The flaw is the direction of the failure. Treating the annotation as
	 * enforcement-bearing can only ever DENY — loud, visible, quickly fixed.
	 * Treating it as absent can GRANT — silent, and to anonymous callers. Between
	 * a fail-safe and a fail-open reading of an ambiguous block, the block gets
	 * the fail-safe one.
	 *
	 * So: writing an `mcp` entry into `authorization` opts the schema into
	 * authorization, exactly as writing any other rule does. To record an agent
	 * surface WITHOUT touching enforcement, use the `x-openregister-mcp` dialect,
	 * which is where the surface belongs and where every live declaration on the
	 * instance already is.
	 *
	 * Control keys that are not rule lists (notably the `public: true` opt-in,
	 * read back off the RESOLVED block) are carried through untouched.
	 *
	 * Static and pure so both rule interpreters — this one and the SQL-layer
	 * {@see \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler} — can apply the
	 * identical transform and cannot drift apart on it.
	 *
	 * @param array|null $authorization The block to strip, or null.
	 *
	 * @return array|null The block with the mcp scope removed, or null when
	 *                    nothing enforceable remains.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public static function stripMcpScope(?array $authorization): ?array {
		if ($authorization === null || $authorization === []) {
			return $authorization;
		}

		$stripped = [];

		foreach ($authorization as $key => $rules) {
			// Not a rule list (inheritFromPublic, public, objectScope, ...):
			// carry it through untouched. These are behaviour toggles read at
			// runtime, and the mcp scope has no business editing them.
			if (is_array($rules) === false) {
				$stripped[$key] = $rules;
				continue;
			}

			// The deny block is a block of rule lists, not one rule list.
			// Handing it to the list stripper would ask "is this entry the mcp
			// scope" of an entire action's list, which is always no, and would
			// reindex the block's action keys on the way through.
			if ($key === DenyResolver::DENY_KEY) {
				$stripped[$key] = self::stripMcpScope(authorization: $rules);
				continue;
			}

			// 🔴 A CONTROL KEY THAT HAPPENS TO BE AN ARRAY IS NOT A RULE LIST,
			// and reading it as one does not fail — it REINDEXES. The reindex
			// is what made the whole department matrix inert: `matrix` is the
			// map `{field, userSource, rows}`, `stripMcpFromRuleList()` walks
			// its VALUES and appends them to a fresh list, and what reached
			// `compileDepartmentMatrix()` was `['department', {...}, [...]]`
			// with every key gone. `$matrix['field']` was then absent,
			// `compile()` returned nothing, `merge()` left the block alone, and
			// the schema ended up granting `read` to nobody at all. Measured on
			// a live instance 2026-09-19: an identical rule written by hand as
			// a conditional scope narrowed the list correctly, and the same
			// rule expressed as a matrix admitted no one.
			//
			// `roles` has exactly the same shape and was losing its role names
			// the same way.
			//
			// The comment above about carrying control keys through untouched
			// was already the intent; it only covered the ones that are not
			// arrays.
			if (in_array($key, PermissionCatalogue::CONTROL_KEYS, true) === true) {
				$stripped[$key] = $rules;
				continue;
			}

			$stripped[$key] = self::stripMcpFromRuleList(rules: $rules);
		}//end foreach

		return $stripped;
	}//end stripMcpScope()

	/**
	 * Strip the mcp scope from one action's rule list.
	 *
	 * An emptied list stays `[]` rather than being dropped. The grammar reads
	 * `[]` as "grant this action to nobody", which is the fail-safe reading of
	 * an action whose only rule was an offer to agents.
	 *
	 * @param array $rules One action's rule list.
	 *
	 * @return array The surviving rules, possibly empty.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	private static function stripMcpFromRuleList(array $rules): array {
		$kept = [];
		foreach ($rules as $entry) {
			if (self::isMcpScopeEntry(entry: $entry) === true) {
				continue;
			}

			$kept[] = $entry;
		}

		return $kept;
	}//end stripMcpFromRuleList()

	/**
	 * Read back the actions a block offers to agents.
	 *
	 * This is the half of the annotation that survives as DATA: the
	 * grantable-rights index serves it to Hermiq as the menu of rights that
	 * could be granted. It answers "what may be offered here", and never
	 * "who holds it".
	 *
	 * @param array|null $authorization The block to read.
	 *
	 * @return array<int, string> The offered action names, in declaration order.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public static function mcpOfferedActions(?array $authorization): array {
		if ($authorization === null || $authorization === []) {
			return [];
		}

		$offered = [];
		foreach ($authorization as $action => $rules) {
			if (is_array($rules) === false || is_string($action) === false) {
				continue;
			}

			foreach ($rules as $entry) {
				if (self::isMcpScopeEntry(entry: $entry) === true) {
					$offered[] = $action;
					break;
				}
			}
		}

		return $offered;
	}//end mcpOfferedActions()

	/**
	 * Whether a single authorization entry names the `mcp` scope.
	 *
	 * Covers both spellings the rule grammar accepts — the bare string and the
	 * `{"group": "mcp"}` object — because they are independent match branches
	 * and a guard that knows only one of them is a guard with a hole.
	 *
	 * @param mixed $entry One entry from an action's rule list.
	 *
	 * @return bool True when the entry names the mcp scope.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	private static function isMcpScopeEntry(mixed $entry): bool {
		return self::entryNamesGroup(entry: $entry, group: self::SCOPE_MCP);
	}//end isMcpScopeEntry()

	/**
	 * Whether a single authorization entry names the group, as a bare string or as `{"group": ...}`.
	 *
	 * @param mixed  $entry One entry from an action's rule list.
	 * @param string $group The group.
	 *
	 * @return bool True when the entry names the group.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private static function entryNamesGroup(mixed $entry, string $group): bool {
		if (is_array($entry) === true) {
			return (($entry['group'] ?? null) === $group);
		}

		return ($entry === $group);
	}//end entryNamesGroup()

	/**
	 * Resolve whether authenticated users inherit `public` group rights for a schema.
	 *
	 * Cascade (first explicit boolean wins): schema authorization →
	 * register authorization → tenant-wide IAppConfig
	 * `openregister.rbac.inherit_from_public_default` → hard-coded `true`.
	 * A `null` (or any non-boolean) value at a level is treated as "unset" so
	 * the cascade falls through. Anonymous users are never affected by this flag.
	 *
	 * @param Schema $schema The schema being evaluated.
	 *
	 * @return bool True when authenticated users inherit `public` rights.
	 *
	 * @spec openspec/changes/rbac-disable-public-inheritance/tasks.md
	 */
	public function resolveInheritFromPublic(Schema $schema): bool {
		$schemaId = $schema->getId();
		if ($schemaId !== null && array_key_exists($schemaId, $this->cachedInheritFromPublic) === true) {
			return $this->cachedInheritFromPublic[$schemaId];
		}

		// Resolve defensively: any unexpected failure falls back to the
		// tenant-wide default rather than propagating. This keeps the fail-mode
		// symmetric with MagicRbacHandler::authenticatedInheritsPublic() — both
		// callers degrade to the configured tenant posture (not a hard-coded
		// grant), so a cluster-wide lock-down is not silently softened by an
		// exception on one of the two RBAC code paths.
		try {
			$resolved = null;

			// Step 1: schema-level authorization (strict boolean; non-bool = unset).
			$auth = $schema->getAuthorization();
			if (is_array($auth) === true && array_key_exists('inheritFromPublic', $auth) === true) {
				$resolved = $this->coerceStrictBoolOrLog(value: $auth['inheritFromPublic'], level: 'schema', schemaId: $schemaId);
			}

			// Step 2: register-level authorization. Conservative across multiple
			// registers — see resolveRegisterInheritFromPublic().
			if ($resolved === null) {
				$resolved = $this->resolveRegisterInheritFromPublic(schemaId: $schemaId);
			}

			// Step 3: tenant-wide IAppConfig default.
			if ($resolved === null) {
				$resolved = $this->inheritFromPublicTenantDefault();
			}
		} catch (\Throwable $e) {
			$resolved = $this->inheritFromPublicTenantDefault();
			$this->logger->error(
				message: '[PermissionHandler] inheritFromPublic resolution failed; falling back to tenant default',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $schemaId,
					'default' => $resolved,
					'error' => $e->getMessage(),
				]
			);
		}//end try

		if ($schemaId !== null) {
			$this->cachedInheritFromPublic[$schemaId] = $resolved;
		}

		return $resolved;
	}//end resolveInheritFromPublic()

	/**
	 * Read the tenant-wide inheritFromPublic default from IAppConfig.
	 *
	 * This is both the terminal cascade step and the fail-safe used when
	 * resolution errors out. When IAppConfig itself is unreachable it logs at
	 * error level and returns `true` (pre-PR posture) so a config-store outage
	 * does not hard-deny every authenticated read.
	 *
	 * @return bool The configured tenant default (true when unconfigured/unreachable).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function inheritFromPublicTenantDefault(): bool {
		try {
			// IAppConfig tolerates string forms ("true"/"1") here.
			return $this->appConfig->getValueBool(app: 'openregister', key: 'rbac.inherit_from_public_default', default: true);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[PermissionHandler] IAppConfig unreachable resolving inheritFromPublic default; assuming true',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return true;
		}
	}//end inheritFromPublicTenantDefault()

	/**
	 * Resolve the register-level inheritFromPublic value, conservatively.
	 *
	 * A schema can belong to multiple registers. Rather than depending on the
	 * undefined scan order of "first register wins" — which would make a
	 * security verdict non-deterministic across nodes/restores — every register
	 * containing the schema is consulted and the most-restrictive explicit value
	 * wins: a single `false` disables inheritance even if other registers say
	 * `true`. Returns null when no register sets the flag (cascade falls through).
	 *
	 * @param int|null $schemaId The schema id (null when the schema is unsaved).
	 *
	 * @return bool|null false if any register disables inheritance, true if some
	 *                   enable it and none disable, null when no register sets it.
	 */
	private function resolveRegisterInheritFromPublic(?int $schemaId): ?bool {
		if ($schemaId === null) {
			return null;
		}

		$registerIds = [];
		try {
			$registerMapper = $this->container->get(RegisterMapperInterface::class);
			$registerIds = $registerMapper->getAllRegisterIdsWithSchema(schemaId: $schemaId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[PermissionHandler] Failed to list registers for schema; skipping register cascade',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId, 'error' => $e->getMessage()]
			);
			return null;
		}

		$sawTrue = false;
		foreach ($registerIds as $registerId) {
			try {
				$registerAuth = $this->getRegisterAuthorization(registerId: $registerId);
			} catch (AuthorizationUnresolvableException $e) {
				// Fail-closed: most-restrictive-wins, so an unresolvable register
				// disables `public` inheritance rather than silently skipping it.
				return false;
			}

			if (is_array($registerAuth) === false || array_key_exists('inheritFromPublic', $registerAuth) === false) {
				continue;
			}

			$value = $this->coerceStrictBoolOrLog(value: $registerAuth['inheritFromPublic'], level: 'register', schemaId: $schemaId);
			if ($value === false) {
				// Most-restrictive wins: one explicit false disables inheritance.
				return false;
			}

			if ($value === true) {
				$sawTrue = true;
			}
		}//end foreach

		if ($sawTrue === true) {
			return true;
		}

		return null;
	}//end resolveRegisterInheritFromPublic()

	/**
	 * Strictly coerce an inheritFromPublic cascade value to bool, or null when unset.
	 *
	 * Only a literal `true`/`false` counts. Anything else (string "false",
	 * int 0, etc.) is treated as "unset" and logged — PHP's loose `(bool)
	 * "false" === true` would otherwise silently invert the gate on a
	 * seed/migration/CLI write that bypasses the schema validator.
	 *
	 * @param mixed $value The stored value.
	 * @param string $level The cascade level ('schema' | 'register'), for logging.
	 * @param int|null $schemaId The schema id, for logging.
	 *
	 * @return bool|null The boolean value, or null when the value is not a real boolean.
	 */
	private function coerceStrictBoolOrLog(mixed $value, string $level, ?int $schemaId): ?bool {
		if ($value === true || $value === false) {
			return $value;
		}

		if ($value !== null) {
			$this->logger->warning(
				message: '[PermissionHandler] Non-boolean inheritFromPublic value ignored; treating as unset',
				context: [
					'level' => $level,
					'schemaId' => $schemaId,
					'type' => get_debug_type($value),
				]
			);
		}

		return null;
	}//end coerceStrictBoolOrLog()

	/**
	 * Get the parent register for a schema.
	 *
	 * Uses RegisterMapper::getFirstRegisterWithSchema() to find the register
	 * that contains the given schema.
	 *
	 * The register is loaded with multitenancy and RBAC scoping DISABLED. This
	 * is an authorization-policy lookup, not a tenant-scoped data read: we only
	 * need the register entity to read its authorization cascade so the caller
	 * can make a security decision. The sibling id lookup
	 * (getFirstRegisterWithSchema → getAllRegisterIdsWithSchema) is already
	 * unscoped and global by design, so re-applying the active-organisation
	 * filter here would make a legitimately-linked register unresolvable purely
	 * because the caller's active-organisation pointer differs from the
	 * register's organisation — throwing DoesNotExistException, which the catch
	 * below re-throws as AuthorizationUnresolvableException and FAIL-CLOSES,
	 * denying even open-schema access for a cross-org (or transient
	 * active-org-changed) session. Object-row multitenancy isolation is enforced
	 * separately on the data reads (organisation column filters + the RBAC
	 * handler's active-organisation condition), so resolving the register's
	 * policy unscoped here does NOT weaken tenant isolation.
	 *
	 * @param Schema $schema The schema to find the register for.
	 *
	 * @return Register|null The parent register, or null if not found.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-scope-model-hierarchy-register-schema-object-property
	 */
	private function getRegisterForSchema(Schema $schema): ?Register {
		try {
			$registerMapper = $this->container->get(RegisterMapperInterface::class);
			$registerId = $registerMapper->getFirstRegisterWithSchema($schema->getId());
			if ($registerId === null) {
				return null;
			}

			return $registerMapper->find(id: $registerId, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			// Fail-closed: this method logged but still returned null, and null here
			// means "schema belongs to no register" -> open. Logging a fail-open does
			// not make it safe. A genuine "no register" answer still returns null
			// above (getFirstRegisterWithSchema() === null); only the ERROR path
			// throws.
			$this->logger->error(
				message: '[PermissionHandler] Failed to get register for schema; denying access (fail-closed)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $schema->getId(),
					'error' => $e->getMessage(),
				]
			);
			throw new AuthorizationUnresolvableException(
				message: 'Unable to resolve register for schema ' . $schema->getId(),
				code: 0,
				previous: $e
			);
		}//end try
	}//end getRegisterForSchema()

	/**
	 * Get register authorization with per-request caching.
	 *
	 * Caches the authorization array for each register ID to avoid
	 * repeated database lookups within a single request.
	 *
	 * Fails CLOSED (CWE-863): when the register cannot be resolved this throws
	 * rather than returning null. `null` from this method means "the register
	 * has no authorization configured" — a real answer that callers treat as
	 * open. A resolver error is NOT that answer, and must never be reported as
	 * one. The failure is also NOT cached: a transient mapper outage must not
	 * be frozen into a permanent per-request verdict.
	 *
	 * @param int $registerId The register ID to get authorization for.
	 *
	 * @return array|null The register's authorization array, or null when the
	 *                    register genuinely has none configured.
	 *
	 * @throws AuthorizationUnresolvableException When the register's authorization cannot be determined. Callers MUST deny.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md#requirement-authorization-resolution-fails-closed
	 */
	private function getRegisterAuthorization(int $registerId): ?array {
		if (array_key_exists($registerId, $this->cachedRegisterAuth) === true) {
			return $this->cachedRegisterAuth[$registerId];
		}

		try {
			$registerMapper = $this->container->get(RegisterMapperInterface::class);
			// Multitenancy/RBAC scoping DISABLED: this loads the register purely to
			// read its authorization + configuration policy for a security decision.
			// Org-scoping this lookup would make a legitimate register unresolvable
			// whenever the caller's active-organisation pointer differs from the
			// register's organisation, fail-closing on legitimate access. Tenant
			// isolation of object rows is enforced separately on the data reads.
			$register = $registerMapper->find(id: $registerId, _rbac: false, _multitenancy: false);
			$auth = $register->getAuthorization();

			$this->cachedRegisterAuth[$registerId] = $auth;
			$this->cachedRegisterConfig[$registerId] = $register->getConfiguration();

			return $auth;
		} catch (\Throwable $e) {
			// Log — a sibling resolver (getRegisterForSchema) already logs on this
			// exact shape; this one silently swallowed, so an authorization outage
			// left no trace at all while granting full permissions.
			$this->logger->error(
				message: '[PermissionHandler] Failed to resolve register authorization; denying access (fail-closed)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'registerId' => $registerId,
					'error' => $e->getMessage(),
				]
			);

			// Deliberately NOT cached: caching the failure would turn a transient
			// error into a permanent verdict for the rest of the request.
			throw new AuthorizationUnresolvableException(
				message: 'Unable to resolve authorization for register ' . $registerId,
				code: 0,
				previous: $e
			);
		}//end try
	}//end getRegisterAuthorization()

	/**
	 * Get register configuration with per-request caching.
	 *
	 * @param int $registerId The register ID to get configuration for.
	 *
	 * @return array|null The register's configuration array.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-named-role-definitions-on-registers
	 */
	private function getRegisterConfiguration(int $registerId): ?array {
		if (array_key_exists($registerId, $this->cachedRegisterConfig) === true) {
			return $this->cachedRegisterConfig[$registerId];
		}

		// Calling getRegisterAuthorization populates both caches. Configuration is
		// not an authorization verdict, so an unresolvable register yields null
		// ("unknown configuration") here — the resolver has already logged it.
		try {
			$this->getRegisterAuthorization(registerId: $registerId);
		} catch (AuthorizationUnresolvableException $e) {
			return null;
		}

		return $this->cachedRegisterConfig[$registerId] ?? null;
	}//end getRegisterConfiguration()

	/**
	 * Expand role references in an authorization block to action-level permissions.
	 *
	 * If the authorization contains a 'roles' key mapping role names to group arrays,
	 * this method resolves each role's actions from the parent register's configuration
	 * and merges the resulting group-to-action mappings into the authorization.
	 *
	 * Example input:
	 *   authorization: { "roles": { "viewer": ["public"], "editor": ["behandelaars"] } }
	 *   register roles: [{ name: "viewer", actions: ["read"] }, { name: "editor", actions: ["read","create","update"] }]
	 *
	 * Example output:
	 *   { "read": ["public", "behandelaars"], "create": ["behandelaars"], "update": ["behandelaars"] }
	 *
	 * @param array $authorization The authorization block to expand.
	 * @param Schema $schema The schema (used to find parent register for role definitions).
	 *
	 * @return array The authorization with roles expanded to action-level entries.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function expandRoles(array $authorization, Schema $schema): array {
		// A deny block uses the SAME grammar as the grants beside it, `roles`
		// included, so it is expanded through this same expander rather than a
		// second one. The recursion is one level deep by construction: the
		// nested call is given a block with its own `deny` key removed, so a
		// `deny.deny` written by hand is dropped rather than descended into.
		$deny = ($authorization[DenyResolver::DENY_KEY] ?? null);
		if (is_array($deny) === true && $deny !== []) {
			unset($deny[DenyResolver::DENY_KEY]);
			$authorization[DenyResolver::DENY_KEY] = $this->expandRoles(authorization: $deny, schema: $schema);
		}

		if (is_array($authorization['roles'] ?? null) === false) {
			return $authorization;
		}

		return (new RoleBlockExpander(logger: $this->logger, schemaId: $schema->getId()))->expand(
			authorization: $authorization,
			definitions: $this->getRoleDefinitionsForSchema(schema: $schema)
		);
	}//end expandRoles()

	/**
	 * Get role definitions for a schema from its parent register.
	 *
	 * Looks up the parent register's configuration.roles array.
	 *
	 * @param Schema $schema The schema to find role definitions for.
	 *
	 * @return array Array of role definitions, each with 'name', 'description', 'actions'.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-named-role-definitions-on-registers
	 */
	private function getRoleDefinitionsForSchema(Schema $schema): array {
		$register = $this->getRegisterForSchema(schema: $schema);
		if ($register === null) {
			return [];
		}

		$config = $this->getRegisterConfiguration(registerId: $register->getId());
		if ($config === null || isset($config['roles']) === false) {
			return [];
		}

		return $config['roles'];
	}//end getRoleDefinitionsForSchema()

	/**
	 * Decide whether a caller may perform a lifecycle transition that
	 * declares a per-transition `authorization` list.
	 *
	 * This is the declarative counterpart to the `requires` PHP-guard seam on
	 * `x-openregister-lifecycle` transitions: a schema author lists the
	 * Nextcloud group ids (and/or register-named roles) permitted to drive a
	 * given transition, and OpenRegister enforces it on the standard
	 * `saveObject()` path WITHOUT the author having to ship a guard class.
	 *
	 * Resolution rules (reusing the SAME `IGroupManager` membership check that
	 * every other RBAC verdict in this handler trusts):
	 *  - The `admin` group always passes (mirrors {@see hasGroupPermission()}).
	 *  - A bare string entry is a literal NC group id; the caller passes when
	 *    {@see IGroupManager::isInGroup()} is true for it.
	 *  - An entry shaped `{ "role": "<name>" }` is expanded to the NC group ids
	 *    assigned to that register-named role via the schema's
	 *    `authorization.roles` map (the same `roles: {name: [groups]}` syntax
	 *    {@see expandRoles()} consumes), then matched the same way.
	 *
	 * Fail-closed posture (CWE-863): an EMPTY list authorises nobody; an
	 * anonymous caller (null/unknown uid) is denied; an entry that cannot be
	 * resolved to a concrete group is skipped (never widens access). A
	 * transition WITHOUT an `authorization` list never reaches this method —
	 * the listener only calls it when the key is present — so existing
	 * transitions are completely unaffected (additive).
	 *
	 * @param array<int, mixed> $authorizationList Group ids / `{role}` entries permitted to perform the transition.
	 * @param string|null $userId Caller uid, or null for an anonymous principal.
	 * @param Schema $schema Schema the transition belongs to (for named-role expansion).
	 *
	 * @return bool True when the caller is authorised; false (fail-closed) otherwise.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Anonymous guard + admin bypass + literal-vs-role entry handling are each one irreducible branch.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
	 */
	public function isTransitionAuthorized(array $authorizationList, ?string $userId, Schema $schema): bool {
		// Fail-closed: an empty list authorises nobody.
		if ($authorizationList === []) {
			return false;
		}

		// Anonymous callers can never satisfy a group-based transition gate.
		if ($userId === null || $userId === '') {
			return false;
		}

		$userObj = $this->userManager->get($userId);
		if ($userObj === null) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($userObj);

		// Admin bypass — consistent with every other verdict in this handler.
		if (in_array(needle: 'admin', haystack: $userGroups, strict: true) === true) {
			return true;
		}

		$userGroupSet = array_flip($userGroups);
		$roleGroupMap = null;

		foreach ($authorizationList as $entry) {
			// Literal NC group id.
			if (is_string($entry) === true) {
				if ($entry !== '' && isset($userGroupSet[$entry]) === true) {
					return true;
				}

				continue;
			}

			// Named-role indirection: { "role": "<name>" } → assigned groups.
			if (is_array($entry) === true && isset($entry['role']) === true && is_string($entry['role']) === true) {
				if ($roleGroupMap === null) {
					$roleGroupMap = $this->resolveTransitionRoleGroups(schema: $schema);
				}

				$groupsForRole = ($roleGroupMap[$entry['role']] ?? []);
				foreach ($groupsForRole as $groupId) {
					if (is_string($groupId) === true && isset($userGroupSet[$groupId]) === true) {
						return true;
					}
				}
			}
		}//end foreach

		return false;
	}//end isTransitionAuthorized()

	/**
	 * Build a `roleName => [groupId, ...]` map from a schema's
	 * `authorization.roles` assignment, used to expand `{ "role": "<name>" }`
	 * entries on lifecycle transitions.
	 *
	 * The role→group assignment lives on the schema's authorization block
	 * (`authorization.roles: { "<name>": ["<group>", ...] }`) — the same
	 * compact syntax {@see expandRoles()} reads. Only the assignment is needed
	 * here (not the register-level action set), so this is a thin, targeted
	 * read rather than a re-run of the full role-hierarchy expansion.
	 *
	 * @param Schema $schema Schema whose authorization block carries the role assignment.
	 *
	 * @return array<string, array<int, string>> Map of role name to assigned NC group ids.
	 */
	private function resolveTransitionRoleGroups(Schema $schema): array {
		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false || isset($authorization['roles']) === false
			|| is_array($authorization['roles']) === false
		) {
			return [];
		}

		$map = [];
		foreach ($authorization['roles'] as $roleName => $groups) {
			if (is_string($roleName) === false) {
				continue;
			}

			$map[$roleName] = array_values(array_filter((array)$groups, 'is_string'));
		}

		return $map;
	}//end resolveTransitionRoleGroups()
}//end class
