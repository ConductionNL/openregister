<?php

/**
 * MagicMapper RBAC Handler
 *
 * This handler provides role-based access control (RBAC) filtering for dynamic
 * schema-based tables, providing RBAC logic optimized for schema-specific
 * table structures.
 *
 * KEY RESPONSIBILITIES:
 * - Apply RBAC permission filters to dynamic table queries
 * - Handle user authentication and authorization checks
 * - Support dynamic variables ($organisation, $userId, $now) in conditions
 * - Integrate with Nextcloud's user and group management
 * - Provide consistent security across all dynamic tables
 *
 * RBAC FEATURES:
 * - Schema-level authorization configuration
 * - User ownership validation
 * - Group-based access control
 * - Dynamic variable resolution ($now, $organisation, $userId)
 * - Admin override capabilities
 * - Unauthenticated user handling
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper RBAC capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ReadRuleTenancy;
use OCA\OpenRegister\Service\Rbac\RuleColumns;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * RBAC (Role-Based Access Control) handler for MagicMapper dynamic tables
 *
 * This class provides comprehensive RBAC filtering for dynamically created
 * schema-based tables, ensuring that users can only access objects they have
 * permission to view based on schema authorization configurations.
 *
 * Two enforcement paths live here:
 *   1. SQL emission — {@see applyRbacFilters()} and {@see buildRbacConditionsSql()}
 *      translate conditional rules into `WHERE` fragments for the list endpoint.
 *      This is the canonical row-level path and remains specialised to this class.
 *   2. PHP-side verdict — {@see hasPermission()} dispatches simple string rules
 *      locally (group-in-groups membership only) and delegates the `match:` branch
 *      of conditional rules to {@see \OCA\OpenRegister\Service\ConditionMatcher},
 *      the shared matcher used across the RBAC stack (ADR-011). New conditional
 *      operators or dynamic variables MUST be added to ConditionMatcher /
 *      OperatorEvaluator, not re-implemented here. The string-rule dispatch is
 *      intentionally kept in-class because it needs no operator vocabulary.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.StaticAccess) PermissionHandler::stripMcpScope() is deliberately static and pure so BOTH rule
 *      interpreters apply the byte-identical transform. Injecting it would let this one be constructed without it and
 *      silently diverge from the PHP-layer evaluator on which entries are inert — the exact drift ADR-011 warns about.
 */
class MagicRbacHandler {

	/**
	 * Raw-SQL impossible predicate used to fail closed on the SQL-string RBAC path.
	 *
	 * Emitted when a `match` rule's dynamic variable resolves to null so the
	 * predicate is NOT silently dropped from the AND (#1953). Mirrors the
	 * QueryBuilder path's {@see impossibleCondition()} and ConditionMatcher's
	 * fail-closed semantics.
	 *
	 * @var string
	 */
	private const IMPOSSIBLE_SQL_CONDITION = '1 = 0';

	/**
	 * Rule verdicts for the organisation filter, see {@see ruleBypassVerdict()}.
	 */
	private const RULE_NOT_HELD = 'not-held';
	private const RULE_READS_ALL = 'reads-all';
	private const RULE_CROSSES_ORGANISATIONS = 'crosses-organisations';
	private const RULE_STAYS_IN_ORGANISATION = 'stays-in-organisation';

	/**
	 * Memoised database-platform verdict for `$contains` SQL emission.
	 *
	 * Null until first resolved; the platform cannot change within a request.
	 *
	 * @var boolean|null
	 */
	private ?bool $isPostgresCache = null;

	/**
	 * Memoised database-provider verdict for how a string value is quoted.
	 *
	 * Null until first resolved; the provider cannot change within a request.
	 *
	 * @var boolean|null
	 */
	private ?bool $isMysqlCache = null;

	/**
	 * Constructor for MagicRbacHandler
	 *
	 * @param IUserSession $userSession User session for current user context
	 * @param IGroupManager $groupManager Group manager for user group operations
	 * @param IUserManager $userManager User manager for user operations
	 * @param IAppConfig $appConfig App configuration for RBAC settings
	 * @param ConditionMatcher $conditionMatcher Shared PHP-side match evaluator (ADR-011; SQL emitter stays here).
	 * @param ContainerInterface $container Container for service injection
	 * @param LoggerInterface $logger Logger for debugging
	 * @param RbacResolvers|null $resolvers The shared object-scope, per-object grant and deny-grammar resolvers,
	 *                                      bundled together; nullable so adding them is not a fatal at existing
	 *                                      construction sites.
	 * @param DenyEnforcementMode|null $denyEnforcementMode The staging switch; nullable for the same reason.
	 * @param MagicMetadataColumnHealer|null $metadataColumns Knows which metadata columns a table that could not be healed still lacks.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection; the optional
	 * collaborators are nullable so existing construction sites keep working.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IAppConfig $appConfig,
		private readonly ConditionMatcher $conditionMatcher,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ?RbacResolvers $resolvers = null,
		private readonly ?DenyEnforcementMode $denyEnforcementMode = null,
		private readonly ?MagicMetadataColumnHealer $metadataColumns = null,
	) {
	}//end __construct()

	/**
	 * The shared deny resolver.
	 *
	 * Nullable-with-default and stateless, exactly like the scope resolver: a
	 * fresh instance is equivalent to the injected one, and a new required
	 * constructor argument would be a fatal at every existing construction
	 * site.
	 *
	 * @return DenyResolver The one reader of the deny grammar.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function denyResolver(): DenyResolver {
		return ($this->resolvers->denyResolver ?? new DenyResolver());
	}//end denyResolver()

	/**
	 * The deny enforcement switch.
	 *
	 * Read here and in {@see \OCA\OpenRegister\Service\Object\PermissionHandlerInterface},
	 * and nowhere else, so the list query and the per-object read cannot end up
	 * in different modes inside one request. Nullable-with-default and
	 * equivalent when freshly built, like the resolvers above: it reads the same
	 * app-config key either way.
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
	 * The shared object-scope resolver.
	 *
	 * Nullable-with-default so adding it is not a fatal at every existing
	 * construction site. The resolver is a stateless value object, so a fresh
	 * instance is equivalent to the injected one.
	 *
	 * @return ObjectScopeResolver The one definition of the scope vocabulary.
	 */
	private function objectScope(): ObjectScopeResolver {
		return ($this->resolvers->objectScopeResolver ?? new ObjectScopeResolver());
	}//end objectScope()

	/**
	 * The shared per-object grant resolver.
	 *
	 * Unlike the scope resolver this one is NOT stateless — it memoises per
	 * request — so the injected instance is strongly preferred and the fallback
	 * is resolved through the container to keep one instance per request. A
	 * fresh instance per call would re-read core's shares on every row-level
	 * decision.
	 *
	 * @return ObjectGrantResolver|null The resolver, or null when unavailable.
	 */
	private function objectGrants(): ?ObjectGrantResolver {
		if ($this->resolvers?->objectGrantResolver !== null) {
			return $this->resolvers->objectGrantResolver;
		}

		try {
			$resolved = $this->container->get(ObjectGrantResolver::class);
			if (($resolved instanceof ObjectGrantResolver) === true) {
				return $resolved;
			}

			return null;
		} catch (Throwable $e) {
			// Fail CLOSED: no resolver means no grants, which hides objects
			// rather than exposing them.
			return null;
		}
	}//end objectGrants()

	/**
	 * The object UUIDs granted to the current caller, as quoted SQL literals.
	 *
	 * @param string|null $userId The caller.
	 * @param string $action The action being filtered; a grant only counts when it carries that permission.
	 *
	 * @return string[] Quoted UUID literals, empty when the caller holds none.
	 */
	private function quotedGrantedUuids(?string $userId, string $action): array {
		$grants = $this->objectGrants()?->grantedObjectUuidsFor(userId: $userId, action: $action);
		if (empty($grants) === true) {
			return [];
		}

		$quoted = [];
		foreach (array_keys($grants) as $uuid) {
			$quoted[] = $this->quoteValue(value: $uuid);
		}

		return $quoted;
	}//end quotedGrantedUuids()

	/**
	 * Whether the current caller holds any per-object grant.
	 *
	 * `MagicSearchHandler` uses this to force the organisation filter on when a
	 * grant is in play, so a grant cannot widen the tenant edge (design D3c).
	 * Exposed here rather than injecting the grant resolver into the search
	 * handler as well, so the grant set is resolved through ONE collaborator.
	 *
	 * @return bool True when the caller has at least one granted object.
	 */
	public function currentCallerHoldsObjectGrants(): bool {
		return $this->objectGrants()?->hasAnyGrant(userId: $this->getCurrentUserId()) === true;
	}//end currentCallerHoldsObjectGrants()

	/**
	 * The owner-admit conditions for the raw-SQL emitter.
	 *
	 * These apply whatever the object's scope says — an owner reaches their own
	 * object even when it is private, which is what stops an owner locking
	 * themselves out. Admins never get here (they return earlier);
	 * openregister#1617 covers the system-owner carve-out.
	 *
	 * Note the UNQUALIFIED column: this emitter feeds UNION members that carry
	 * no table alias, which is why it reads `_owner` and not `t._owner`.
	 *
	 * @param array $userGroups The caller's group IDs.
	 * @param string|null $userId The caller.
	 * @param string $columnPrefix Table alias to qualify each column with, empty for an unaliased
	 *                             UNION member. Unqualified, a column name still parses inside a
	 *                             subquery and binds to the innermost FROM, which is silently wrong
	 *                             the moment the related table does not carry it.
	 *
	 * @return string[] SQL conditions to OR together.
	 */
	private function ownerAdmitConditionsSql(array $userGroups, ?string $userId, string $columnPrefix = ''): array {
		$conditions = [];
		$ownerColumn = $columnPrefix . '_owner';

		if ($userId !== null) {
			$quotedUserId = $this->quoteValue(value: $userId);
			$conditions[] = "{$ownerColumn} = {$quotedUserId}";
		}

		if ($this->shouldGrantSystemRowVisibility(userGroups: $userGroups) === true) {
			$quotedSystemId = $this->quoteValue(value: $this->getSystemUserId());
			$conditions[] = "{$ownerColumn} = {$quotedSystemId}";
		}

		// THE OWNING GROUP IS AN OWNER, so it sits here with the other owner
		// admits rather than inside the not-private predicate: here the term is
		// ORed over the whole query, there it would have been ANDed with the
		// schema's rules. One of those two placements matches what
		// ObjectScopeResolver::admitsUnconditionally() answers on a single read
		// and the other does not.
		$ownedByMyGroup = $this->objectScope()->ownedByMyGroupSql(
			authColumn: $columnPrefix . '_authorization',
			isPostgres: $this->isPostgres(),
			quotedUserGroups: $this->quotedCallerGroups()
		);
		if ($ownedByMyGroup !== null) {
			$conditions[] = $ownedByMyGroup;
		}

		return $conditions;
	}//end ownerAdmitConditionsSql()

	/**
	 * The "this caller may reach this row at all" predicate, as raw SQL.
	 *
	 * Both list emitters call this ONE method, and it in turn calls the ONE
	 * builder on {@see ObjectScopeResolver}. The QueryBuilder emitter wraps the
	 * result in `createFunction()` because QueryBuilder cannot express JSON
	 * member extraction portably — writing a second, QueryBuilder-native
	 * implementation would be a second definition of the vocabulary, which is
	 * how the two divergences in the predecessor change happened.
	 *
	 * @param array|null $authorization The schema/register authorization block (no object).
	 * @param string $columnName The `_authorization` column as this emitter references it.
	 * @param string $uuidColumn The `_uuid` column as this emitter references it.
	 * @param string|null $userId The caller, for grant resolution.
	 * @param string $action The action being filtered; a grant only counts when it carries that permission.
	 * @param Schema|null $schema The schema, so its drafts can be kept to their owners (decision 180).
	 * @param string $columnPrefix Table alias with its dot for the `_status` column, empty when unaliased.
	 * @param string|null $tableName The bare magic table; a `_status` column it lacks is left out of the draft predicate.
	 *
	 * @return string A SQL predicate true for rows this caller may reach.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
	 */
	private function reachableRowSqlFor(
		?array $authorization,
		string $columnName,
		string $uuidColumn,
		?string $userId,
		string $action,
		?Schema $schema = null,
		string $columnPrefix = '',
		?string $tableName = null,
	): string {
		$reachable = $this->objectScope()->notPrivateOrGrantedSql(
			authColumn: $columnName,
			defaultPrivate: $this->objectScope()->schemaDefaultIsPrivate(schemaAuthorization: $authorization),
			isPostgres: $this->isPostgres(),
			uuidColumn: $uuidColumn,
			quotedUuids: $this->quotedGrantedUuids(userId: $userId, action: $action)
		);

		// Decision 180: a draft answers to its owner only, like a private
		// object, unless the schema lets others see drafts. Added HERE, the one
		// predicate both list emitters use, so the owner admits beside it keep
		// the owner's own drafts in view on every path.
		// A table that could not be given its `_status` column cannot hold a
		// draft, and naming the column would fail the query for everyone but an
		// administrator (REQ-MTH-002: such a table degrades quietly).
		$drafts = new DraftStatusPolicy();
		if ($schema !== null && $drafts->draftsVisibleToOthers(schema: $schema) === false && $this->statusColumnMissing(tableName: $tableName) === false) {
			$reachable = '(' . $reachable . ' AND ' . $drafts->notADraftSql(columnPrefix: $columnPrefix) . ')';
		}

		return $reachable;
	}//end reachableRowSqlFor()

	/**
	 * Whether a table is known to lack its `_status` column after an attempt to heal it.
	 *
	 * @param string|null $tableName Bare magic table name, null when unknown.
	 *
	 * @return bool True when the draft predicate must be left out for this table.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
	 */
	private function statusColumnMissing(?string $tableName): bool {
		if ($this->metadataColumns === null || $tableName === null || $tableName === '') {
			return false;
		}

		return in_array('_status', $this->metadataColumns->missingFor(tableName: $tableName), true);
	}//end statusColumnMissing()

	/**
	 * The deny term for one list query, or `false` when nothing is visible.
	 *
	 * This is D22 made mechanical. A denial checked on a fetched page gives a
	 * correct page of WRONG data: the total counts rows the caller may not read,
	 * every facet count is computed over them, and page three is missing rows
	 * page two should have shown. "Compiled into the query" and "checked on the
	 * result" are the same sentence in English and different products in
	 * practice, so the term goes in the WHERE clause and the engine does the
	 * filtering.
	 *
	 * The term is ANDed, never ORed. A deny that sat in the OR beside the
	 * grants would be put back by the next grant that matched — including the
	 * owner condition, which is exactly the row an object-level deny is most
	 * often written about.
	 *
	 * THREE ANSWERS, and the middle one is the point:
	 *
	 *   - `null` the instance is not enforcing denies yet, so the query carries
	 *     no deny predicate at all. See the staging note below.
	 *   - `false` a denial reaches this caller for this action whatever the
	 *     row says, so the query returns nothing. A schema-level deny, or a
	 *     conditional one this emitter could not compile.
	 *   - a predicate, the row-level test plus the negation of every
	 *     conditional denial that could be compiled.
	 *
	 * A conditional denial that will not compile denies everything rather than
	 * nothing, and so does one on metadata no record carries. That over-denies,
	 * which is visible the moment somebody looks for a row; the other direction
	 * leaks, which is not.
	 *
	 * STAGING (D15). The deny does not ship enforcing. Below `enforcing` this
	 * emitter adds no predicate, because a staged deny that still removed rows
	 * from a list would be enforcement wearing a different label, and because
	 * the total and the facet counts are exactly what it would change. The
	 * recording happens on the read path in
	 * {@see \OCA\OpenRegister\Service\Object\PermissionHandlerInterface}, where there is
	 * a row to name; a list has none yet at the point the SQL is built.
	 *
	 * @param array|null  $authorization The schema/register block (no object).
	 * @param string      $action        The verb being filtered.
	 * @param string|null $userId        The caller.
	 * @param string[]    $userGroups    The caller's group IDs.
	 * @param string      $columnName    The `_authorization` column as this emitter references it.
	 * @param RuleColumns $columns       The columns a `match` key may name, with their table alias.
	 *
	 * @return string|false|null The predicate, false when the caller is denied
	 *                           outright, or null when nothing is enforced yet.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function denyFilterSqlFor(
		?array $authorization,
		string $action,
		?string $userId,
		array $userGroups,
		string $columnName,
		RuleColumns $columns,
	): string|false|null {
		if ($this->denyEnforcementMode()->enforces() === false) {
			return null;
		}

		$resolver = $this->denyResolver();
		$principals = $resolver->principalsFor(userId: $userId, userGroups: $userGroups);

		$unconditional = $resolver->unconditionalDenial(
			authorization: $authorization,
			action: $action,
			principals: $principals
		);
		if ($unconditional !== null) {
			$this->logger->info(
				message: '[MagicRbacHandler] A deny rule removes this action from the caller; the list returns nothing',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'action' => $action,
					'userId' => $userId,
					'principal' => $unconditional['principal'],
				]
			);
			return false;
		}

		$terms = [
			$resolver->notDeniedRowSql(
				columnName: $columnName,
				action: $action,
				isPostgres: $this->isPostgres(),
				principals: $principals
			),
		];

		foreach ($resolver->conditionalDenials(
			authorization: $authorization,
			action: $action,
			principals: $principals
		) as $denial) {
			$match = $this->denialPredicateSql(rule: $denial['rule'], columns: $columns);

			if ($match === null) {
				$this->logger->warning(
					message: '[MagicRbacHandler] A conditional deny could not be compiled into SQL; denying the whole list (fail-closed)',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'action' => $action,
						'userId' => $userId,
						'principal' => $denial['principal'],
					]
				);
				return false;
			}

			$terms[] = "(NOT ({$match}))";
		}

		if (count($terms) === 1) {
			return $terms[0];
		}

		return '(' . implode(' AND ', $terms) . ')';
	}//end denyFilterSqlFor()

	/**
	 * The SQL of a conditional denial's clause, or null when it cannot select rows by column.
	 *
	 * A clause on metadata no record carries, or on a key that names no column of
	 * this table, gets null, so the caller denies the whole list: `NOT (1 = 0)`
	 * would let every row through.
	 *
	 * @param mixed       $rule    The denial's rule.
	 * @param RuleColumns $columns The columns a `match` key may name, with their table alias.
	 *
	 * @return string|null The predicate, or null.
	 */
	private function denialPredicateSql(mixed $rule, RuleColumns $columns): ?string {
		if (is_array($rule) === false || is_array(($rule['match'] ?? null)) === false
			|| $this->conditionMatcher->namesUnknownMetadata(match: $rule['match']) === true
			|| $columns->admits(match: $rule['match']) === false
		) {
			return null;
		}

		return $this->buildMatchConditionsSql(match: $rule['match'], columnPrefix: $columns->prefix);
	}//end denialPredicateSql()

	/**
	 * Apply RBAC filters to a query builder based on schema authorization
	 *
	 * This method implements the RBAC filtering logic with support for conditional rules:
	 * 1. If user is admin, no filtering is applied
	 * 2. If schema has no authorization, no filtering is applied (open access)
	 * 3. Rules can be simple (group name string) or conditional (object with group and match)
	 * 4. Simple rules grant access if user is in that group
	 * 5. Conditional rules grant access if user qualifies for group AND object matches conditions
	 * 6. Object owner always has access to their own objects
	 *
	 * @param IQueryBuilder $qb Query builder to modify
	 * @param Schema $schema Schema with authorization configuration
	 * @param string $action CRUD action to check (default: 'read')
	 * @param string|null $tableName The bare magic table being read, so a `_status` column it could not be
	 *                               given is left out of the draft predicate. Null keeps the predicate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function applyRbacFilters(
		IQueryBuilder $qb,
		Schema $schema,
		string $action = 'read',
		?string $tableName = null,
	): void {
		$call = new RbacFilterCall(qb: $qb, schema: $schema);
		$call->action = $action;
		$call->tableName = $tableName;

		$this->applyRbacFilterCall(call: $call);
	}//end applyRbacFilters()

	/**
	 * Apply the RBAC filter for one call, see {@see applyRbacFilters()}.
	 *
	 * Admins and trusted system callers are not filtered. A schema whose
	 * authorization cannot be resolved reads nothing.
	 *
	 * @param RbacFilterCall $call The query, schema, action and table being filtered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function applyRbacFilterCall(RbacFilterCall $call): void {
		$user = $this->userSession->getUser();
		$call->userId = $user?->getUID();

		$call->userGroups = [];
		if ($user !== null) {
			$call->userGroups = $this->groupManager->getUserGroupIds($user);
		}

		if (in_array('admin', $call->userGroups, true) === true) {
			return;
		}

		if ($this->isTrustedSystemCaller(user: $user) === true) {
			return;
		}

		if ($this->resolveCallAuthorization(call: $call) === false) {
			return;
		}

		if ($this->applyDenyRules(call: $call) === false) {
			return;
		}

		$this->applyAccessConditions(call: $call);
	}//end applyRbacFilterCall()

	/**
	 * Resolve the call's effective authorization onto the call.
	 *
	 * Fails closed: when the authorization cannot be resolved the query is
	 * clamped to no rows.
	 *
	 * @param RbacFilterCall $call The call being filtered.
	 *
	 * @return bool True when resolved; false when the query was denied.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function resolveCallAuthorization(RbacFilterCall $call): bool {
		try {
			$call->authorization = $this->resolveSchemaAuthorization(schema: $call->schema);
		} catch (AuthorizationUnresolvableException $e) {
			$this->logger->error(
				message: '[MagicRbacHandler] Authorization unresolvable; clamping query to deny-all (fail-closed)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $call->schema->getId(),
					'action' => $call->action,
					'error' => $e->getMessage(),
				]
			);
			$call->denyAll();
			return false;
		}

		return true;
	}//end resolveCallAuthorization()

	/**
	 * Narrow the query to the rows the caller's owner and access rules admit.
	 *
	 * Without authorization the schema is open to every row that is not
	 * private; with authorization, access is granted when ANY condition
	 * matches, and denied outright when none applies.
	 *
	 * @param RbacFilterCall $call The call being filtered, its authorization resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function applyAccessConditions(RbacFilterCall $call): void {
		$qb = $call->qb;
		$notPrivate = $qb->createFunction(
			$this->reachableRowSqlFor(
				authorization: $call->authorization,
				columnName: 't._authorization',
				uuidColumn: 't._uuid',
				userId: $call->userId,
				action: $call->action,
				schema: $call->schema,
				columnPrefix: 't.',
				tableName: $call->tableName
			)
		);

		$ownerAdmits = $this->ownerAdmits(call: $call);

		if (empty($call->authorization) === true) {
			$this->logger->debug(
				message: '[MagicRbacHandler] No authorization configured, schema is open to non-private rows',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			$qb->andWhere($qb->expr()->orX(...$ownerAdmits, ...[$notPrivate]));
			return;
		}

		$conditions = array_merge($ownerAdmits, $this->ruleConditions(call: $call, notPrivate: $notPrivate));

		if (empty($conditions) === true) {
			$this->logger->debug(
				message: '[MagicRbacHandler] No access conditions met, denying all',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'userId' => $call->userId,
					'action' => $call->action,
				]
			);
			$call->denyAll();
			return;
		}

		// Access is granted if ANY condition matches.
		$qb->andWhere($qb->expr()->orX(...$conditions));
	}//end applyAccessConditions()

	/**
	 * Apply the schema's deny rules to the query.
	 *
	 * @param RbacFilterCall $call The filter application.
	 *
	 * @return bool False when a deny rule refuses the caller everything and the query was clamped to no rows.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function applyDenyRules(RbacFilterCall $call): bool {
		$denyTerm = $this->denyFilterSqlFor(
			authorization: $call->authorization,
			action: $call->action,
			userId: $call->userId,
			userGroups: $call->userGroups,
			columnName: 't._authorization',
			columns: new RuleColumns(schema: $call->schema, prefix: 't.')
		);
		if ($denyTerm === false) {
			$call->denyAll();
			return false;
		}

		if ($denyTerm !== null) {
			$call->qb->andWhere($call->qb->createFunction($denyTerm));
		}

		return true;
	}//end applyDenyRules()

	/**
	 * The conditions under which the caller reaches a row as its owner: the
	 * caller's own rows, system-owned rows when the caller may see them, and
	 * rows owned by one of the caller's groups.
	 *
	 * @param RbacFilterCall $call The filter application.
	 *
	 * @return array The conditions.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function ownerAdmits(RbacFilterCall $call): array {
		$qb = $call->qb;
		$ownerAdmits = [];
		if ($call->userId !== null) {
			$ownerAdmits[] = $qb->expr()->eq('t._owner', $qb->createNamedParameter($call->userId));
		}

		if ($this->shouldGrantSystemRowVisibility(userGroups: $call->userGroups) === true) {
			$ownerAdmits[] = $qb->expr()->eq(
				't._owner',
				$qb->createNamedParameter($this->getSystemUserId())
			);
		}

		$ownedByMyGroup = $this->objectScope()->ownedByMyGroupSql(
			authColumn: 't._authorization',
			isPostgres: $this->isPostgres(),
			quotedUserGroups: $this->quotedCallerGroups()
		);
		if ($ownedByMyGroup !== null) {
			$ownerAdmits[] = $qb->createFunction($ownedByMyGroup);
		}

		return $ownerAdmits;
	}//end ownerAdmits()

	/**
	 * The conditions the schema's rules for the action grant.
	 *
	 * An unconditional grant reaches every non-private row, so no later rule
	 * can widen past it. A conditional grant decides only non-private rows: a
	 * private row answers to its owner and administrators alone, which is
	 * why each rule condition is AND-ed with the not-private predicate. An
	 * action missing from a non-empty authorization block grants nothing.
	 *
	 * @param RbacFilterCall $call       The filter application.
	 * @param mixed          $notPrivate The predicate that a row is not private to someone else.
	 *
	 * @return array The conditions.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function ruleConditions(RbacFilterCall $call, mixed $notPrivate): array {
		$rules = $call->authorization[$call->action] ?? [];
		if (empty($rules) === true) {
			$this->logger->debug(
				message: '[MagicRbacHandler] Action not configured on a non-empty authorization block — failing closed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'action' => $call->action]
			);
		}

		$caller = [
			'groups'            => $call->userGroups,
			'userId'            => $call->userId,
			'inheritFromPublic' => $this->authenticatedInheritsPublic(schema: $call->schema),
			'columns'           => new RuleColumns(schema: $call->schema, prefix: 't.'),
		];

		$conditions = [];
		foreach ($rules as $rule) {
			$ruleCondition = $this->processAuthorizationRule(qb: $call->qb, rule: $rule, caller: $caller);
			if ($ruleCondition === true) {
				$conditions[] = $notPrivate;
				break;
			}

			if ($ruleCondition !== null && $ruleCondition !== false) {
				$conditions[] = $call->qb->expr()->andX($notPrivate, $ruleCondition);
			}
		}

		return $conditions;
	}//end ruleConditions()

	/**
	 * Resolve whether authenticated users inherit `public` group rights for a schema.
	 *
	 * Delegates to PermissionHandlerInterface::resolveInheritFromPublic() (cascade:
	 * schema → register → IAppConfig → true) via the container so the SQL
	 * emitters and the PHP-side check honour the flag identically. Fails safe
	 * to `true` (the pre-flag behaviour) if resolution errors.
	 *
	 * @param Schema $schema The schema being filtered.
	 *
	 * @return bool True when authenticated users inherit `public` rights.
	 *
	 * @spec openspec/changes/rbac-disable-public-inheritance/tasks.md
	 */
	private function authenticatedInheritsPublic(Schema $schema): bool {
		try {
			return $this->container->get(PermissionHandlerInterface::class)->resolveInheritFromPublic(schema: $schema);
		} catch (\Throwable $e) {
			// Fail to the configured tenant posture rather than a hard-coded
			// grant, so a cluster-wide lock-down (rbac.inherit_from_public_default
			// = false) is not silently softened on this code path. Symmetric with
			// PermissionHandlerInterface::resolveInheritFromPublic()'s own fail-safe.
			$fallback = true;
			try {
				$fallback = $this->container->get(PermissionHandlerInterface::class)->inheritFromPublicTenantDefault();
			} catch (\Throwable $inner) {
				$fallback = $this->appConfig->getValueBool(
					app: 'openregister',
					key: 'rbac.inherit_from_public_default',
					default: true
				);
			}

			$this->logger->error(
				message: '[MagicRbacHandler] inheritFromPublic resolution failed; falling back to tenant default',
				context: ['file' => __FILE__, 'line' => __LINE__, 'default' => $fallback, 'error' => $e->getMessage()]
			);
			return $fallback;
		}//end try
	}//end authenticatedInheritsPublic()

	/**
	 * Decide whether the current principal qualifies for a `public` rule.
	 *
	 * Anonymous users (no userId) always qualify for `public`. Authenticated
	 * users qualify only when public inheritance is enabled for the schema.
	 *
	 * @param string|null $userId Current user ID (null = anonymous).
	 * @param bool $inheritFromPublic Resolved inheritFromPublic value.
	 *
	 * @return bool True when the principal qualifies for the `public` group.
	 */
	private function qualifiesForPublic(?string $userId, bool $inheritFromPublic): bool {
		return $userId === null || $inheritFromPublic === true;
	}//end qualifiesForPublic()

	/**
	 * Whether a `public` read rule lifts the organisation filter for the current caller.
	 *
	 * True for an anonymous caller and for an admin only. A signed-in caller
	 * reads across organisations only through a rule marked
	 * `crossOrganisation: true` ({@see crossOrganisationMatches()}).
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the `public` rule may lift the organisation filter.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The schema stays in the signature MagicMapper and ObjectService call.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function publicReadLiftsOrganisationFilter(Schema $schema): bool {
		if (OrganisationContext::isActive() === true) {
			return false;
		}

		return $this->getCurrentUserId() === null || $this->isAdmin() === true;
	}//end publicReadLiftsOrganisationFilter()

	/**
	 * Whether the organisation filter of a signed-in, non-admin caller is widened
	 * by the rows of the conditional `crossOrganisation` rules they hold.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return bool True when the filter stays and gets a cross-organisation branch.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function hasCrossOrganisationBranch(Schema $schema): bool {
		if (OrganisationContext::isActive() === true || $this->getCurrentUserId() === null || $this->isAdmin() === true) {
			return false;
		}

		$matches = $this->crossOrganisationMatches(schema: $schema);
		return $matches !== null && $matches !== [];
	}//end hasCrossOrganisationBranch()

	/**
	 * The rows the caller's conditional `crossOrganisation` rules admit, as a
	 * QueryBuilder expression to OR into the organisation filter.
	 *
	 * @param IQueryBuilder $qb     The query builder.
	 * @param Schema        $schema The schema being read.
	 *
	 * @return mixed The expression, or null when no rule renders.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function getCrossOrganisationBranch(IQueryBuilder $qb, Schema $schema): mixed {
		$branches = [];
		foreach (($this->crossOrganisationMatches(schema: $schema) ?? []) as $match) {
			$condition = $this->impossibleCondition(qb: $qb);
			if ((new RuleColumns(schema: $schema))->admits(match: $match) === true) {
				$condition = $this->buildMatchConditions(qb: $qb, match: $match);
			}

			if ($condition !== null) {
				$branches[] = $condition;
			}
		}

		if ($branches === []) {
			return null;
		}

		return $qb->expr()->orX(...$branches);
	}//end getCrossOrganisationBranch()

	/**
	 * The same rows as {@see getCrossOrganisationBranch()}, as SQL for the string-built paths.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return string|null The predicate, or null when no rule renders.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function getCrossOrganisationBranchSql(Schema $schema): ?string {
		$branches = [];
		foreach (($this->crossOrganisationMatches(schema: $schema) ?? []) as $match) {
			$condition = self::IMPOSSIBLE_SQL_CONDITION;
			if ((new RuleColumns(schema: $schema))->admits(match: $match) === true) {
				$condition = $this->buildMatchConditionsSql(match: $match);
			}

			if ($condition !== null) {
				$branches[] = $condition;
			}
		}

		if ($branches === []) {
			return null;
		}

		return '(' . implode(' OR ', $branches) . ')';
	}//end getCrossOrganisationBranchSql()

	/**
	 * The match clauses of the `crossOrganisation: true` read rules of the
	 * schema's own block that the current caller holds.
	 *
	 * Only a schema with a block of its own has such rules: a register's rules
	 * never take a caller across organisations. The block is read resolved, so
	 * role expansion and the rules a department matrix compiles count too. A
	 * block that cannot be resolved has none.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return array<int, array>|null The clauses, or null when a held marked rule has no match (it reads every row).
	 *                                A match that is not a list of conditions admits nothing.
	 */
	private function crossOrganisationMatches(Schema $schema): ?array {
		$caller = $this->currentCaller(schema: $schema);
		$matches = [];
		foreach ($this->ownReadRules(schema: $schema) as $rule) {
			if ((new ReadRuleTenancy())->isMarked(rule: $rule) === false
				|| $this->callerHoldsConditionalRule(rule: $rule, caller: $caller) === false
			) {
				continue;
			}

			$match = ($rule['match'] ?? []);
			if ($match === []) {
				return null;
			}

			if (is_array($match) === true) {
				$matches[] = $match;
			}
		}

		return $matches;
	}//end crossOrganisationMatches()

	/**
	 * The resolved read rules of a schema that has an authorization block of its own.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return array<int, mixed> The rules; none for a schema without its own block or an unresolvable one.
	 */
	private function ownReadRules(Schema $schema): array {
		if (empty(PermissionHandler::stripMcpScope(authorization: $schema->getAuthorization())) === true) {
			return [];
		}

		try {
			$resolved = $this->resolveSchemaAuthorization(schema: $schema);
		} catch (AuthorizationUnresolvableException $e) {
			return [];
		}

		return array_values((array)(($resolved ?? [])['read'] ?? []));
	}//end ownReadRules()

	/**
	 * The current caller as the rule qualification reads it.
	 *
	 * @param Schema $schema The schema, for the public-inheritance gate.
	 *
	 * @return array{groups: array<int, string>, userId: ?string, inheritFromPublic: bool} The caller.
	 */
	private function currentCaller(Schema $schema): array {
		return [
			'groups'            => $this->getCurrentUserGroups(),
			'userId'            => $this->getCurrentUserId(),
			'inheritFromPublic' => $this->authenticatedInheritsPublic(schema: $schema),
		];
	}//end currentCaller()

	/**
	 * Process a single authorization rule
	 *
	 * @param IQueryBuilder $qb Query builder
	 * @param mixed $rule Authorization rule (string or array)
	 * @param array $caller The caller: groups, userId, inheritFromPublic, and the columns a `match` key may name.
	 *
	 * @return mixed True if unconditional access, SQL expression for conditional, null/false if no access
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function processAuthorizationRule(
		IQueryBuilder $qb,
		mixed $rule,
		array $caller,
	): mixed {
		// Simple rule: just a group name string.
		if (is_string($rule) === true) {
			return $this->processSimpleRule(
				rule: $rule,
				userGroups: $caller['groups'],
				userId: $caller['userId'],
				inheritFromPublic: $caller['inheritFromPublic']
			);
		}

		// Conditional rule: object with 'group' (or a 'user' override) and
		// optional 'match'.
		if (is_array($rule) === true && (isset($rule['group']) === true || isset($rule['user']) === true)) {
			return $this->processConditionalRule(
				qb: $qb,
				rule: $rule,
				caller: $caller
			);
		}

		// Invalid rule format.
		$this->logger->warning(
			message: '[MagicRbacHandler] Invalid authorization rule format',
			context: ['file' => __FILE__, 'line' => __LINE__, 'rule' => $rule]
		);
		return null;
	}//end processAuthorizationRule()

	/**
	 * Process a simple (unconditional) authorization rule
	 *
	 * @param string $rule Group name
	 * @param array $userGroups User's group IDs
	 * @param string|null $userId Current user ID
	 * @param bool $inheritFromPublic Whether auth users inherit public rights
	 *
	 * @return bool True if user has access, false otherwise
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function processSimpleRule(string $rule, array $userGroups, ?string $userId, bool $inheritFromPublic): bool {
		// 'public' grants access to anonymous users always; authenticated users
		// only when public inheritance is enabled (inheritFromPublic).
		if ($rule === 'public') {
			return $this->qualifiesForPublic(userId: $userId, inheritFromPublic: $inheritFromPublic);
		}

		// 'authenticated' grants access to any logged-in user.
		if ($rule === 'authenticated') {
			return $userId !== null;
		}

		// User-level override (delegation): a bare `user:<uid>` rule grants the
		// action to that one user on the LIST path, mirroring PermissionHandlerInterface's
		// single-object verdict (rbac-zaaktype). Fail closed: anonymous users and
		// non-matching uids never qualify. Additive — never widens group access.
		if ($this->matchesUserOverride(rule: $rule, userId: $userId) === true) {
			return true;
		}

		// Check if user is in the specified group.
		if (in_array($rule, $userGroups, true) === true) {
			return true;
		}

		return false;
	}//end processSimpleRule()

	/**
	 * Determine whether a bare authorization rule is a `user:<uid>` override
	 * that grants access to the current user.
	 *
	 * Shared by the QueryBuilder, raw-SQL, and multitenancy-bypass paths so the
	 * three LIST emitters agree with PermissionHandlerInterface's single-object verdict.
	 * SECURITY: exact-uid match only; anonymous principals never match; the
	 * `user:` prefix with a blank uid never matches.
	 *
	 * @param string $rule Authorization rule string.
	 * @param string|null $userId Current user ID (null = anonymous).
	 *
	 * @return bool True when the rule is a user override for the current user.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function matchesUserOverride(string $rule, ?string $userId): bool {
		if ($userId === null || $userId === '') {
			return false;
		}

		if (str_starts_with($rule, 'user:') === false) {
			return false;
		}

		$targetUid = substr($rule, strlen('user:'));
		return $targetUid !== '' && $targetUid === $userId;
	}//end matchesUserOverride()

	/**
	 * Process a conditional authorization rule
	 *
	 * @param IQueryBuilder $qb Query builder
	 * @param array $rule Rule with 'group' and optional 'match'
	 * @param array $caller The caller: groups, userId, inheritFromPublic, and the columns a `match` key may name.
	 *
	 * @return mixed True if unconditional access, SQL expression for conditional, false if no access
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function processConditionalRule(
		IQueryBuilder $qb,
		array $rule,
		array $caller,
	): mixed {
		$match = $rule['match'] ?? null;

		// A caller outside the rule's group or named user is not given it.
		if ($this->callerQualifiesForRule(rule: $rule, caller: $caller) === false) {
			return false;
		}

		// If no match conditions, user has unconditional access via this rule.
		if ($match === null || empty($match) === true) {
			return true;
		}

		// A key that names no column of this table admits no row.
		if ($caller['columns']->admits(match: $match) === false) {
			return $this->impossibleCondition(qb: $qb);
		}

		// Build SQL conditions for the match criteria.
		return $this->buildMatchConditions(qb: $qb, match: $match);
	}//end processConditionalRule()

	/**
	 * Whether a conditional rule applies to the caller: as its named user, or through its group.
	 *
	 * `public` admits anonymous callers, and signed-in ones when public
	 * inheritance is on. `authenticated` is a pseudo-group nobody is a member
	 * of, so it admits any signed-in user here.
	 *
	 * @param array<string, mixed> $rule   The rule.
	 * @param array<string, mixed> $caller The caller's groups, userId and inheritFromPublic.
	 *
	 * @return bool True when the rule applies to the caller.
	 */
	private function callerQualifiesForRule(array $rule, array $caller): bool {
		$group = ($rule['group'] ?? null);
		$userId = $caller['userId'];

		if (isset($rule['user']) === true && is_string($rule['user']) === true) {
			// User-level override (delegation): { "user": "<uid>", "match": {...} }.
			return $this->matchesUserOverride(rule: 'user:' . $rule['user'], userId: $userId);
		}

		if ($group === 'public') {
			return $this->qualifiesForPublic(userId: $userId, inheritFromPublic: $caller['inheritFromPublic']);
		}

		if ($group === 'authenticated' && $userId !== null) {
			return true;
		}

		return $group !== null && in_array($group, $caller['groups'], true) === true;
	}//end callerQualifiesForRule()

	/**
	 * Build SQL conditions for match criteria
	 *
	 * A clause on metadata no record carries admits no row.
	 *
	 * @param IQueryBuilder $qb Query builder
	 * @param array $match Match conditions
	 *
	 * @return mixed SQL expression or null if invalid
	 */
	private function buildMatchConditions(IQueryBuilder $qb, array $match): mixed {
		if ($this->conditionMatcher->namesUnknownMetadata(match: $match) === true) {
			return $this->impossibleCondition(qb: $qb);
		}

		$conditions = [];

		foreach ($match as $property => $value) {
			$condition = $this->buildPropertyCondition(qb: $qb, property: $property, value: $value);
			if ($condition !== null) {
				$conditions[] = $condition;
			}
		}

		// If no valid conditions, return null.
		if (empty($conditions) === true) {
			$this->logger->debug(
				message: '[MagicRbacHandler] No valid match conditions built',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		// All conditions must match (AND logic).
		if (count($conditions) === 1) {
			return $conditions[0];
		}

		return $qb->expr()->andX(...$conditions);
	}//end buildMatchConditions()

	/**
	 * Build an IMPOSSIBLE SQL predicate (`1 = 0`) for the QueryBuilder path.
	 *
	 * Used to fail closed when a `match` rule's dynamic variable resolves to null
	 * (#1953): the predicate is included in the AND but can never be satisfied, so
	 * the rule grants no rows — matching the PHP/find path verdict rather than
	 * silently dropping the condition. Mirrors the existing impossible predicate in
	 * applyRbacFilters().
	 *
	 * @param IQueryBuilder $qb Query builder.
	 *
	 * @return mixed An always-false SQL expression.
	 */
	private function impossibleCondition(IQueryBuilder $qb): mixed {
		return $qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0));
	}//end impossibleCondition()

	/**
	 * Resolve dynamic variable values in match conditions.
	 *
	 * DELEGATES to the shared {@see ConditionMatcher::resolveToken()} — the same
	 * resolver the single-object verdict path uses — so the SQL emitter and the
	 * PHP verdict cannot disagree about what a token means.
	 *
	 * This method previously held its OWN copy that recognised only the bare
	 * tokens (`$organisation`, `$activeOrganisation`, `$userId`, `$user`, `$now`)
	 * and passed every DOTTED form through as a literal string. So a rule using
	 * `$user.groups` resolved to the user's groups on `find` and compared against
	 * the literal `'$user.groups'` on `list` — granting on one path and denying on
	 * the other. That is the divergence class ADR-011 exists to prevent and which
	 * already forced two fixes in this file (`$now`'s format,
	 * `unwrapResolvedRelation()`).
	 *
	 * Supports, via the shared resolver: `$organisation`, `$activeOrganisation`,
	 * `$organisation.<prop>`, `$userId`, `$user`, `$user.<prop>` (including
	 * `$user.groups`, which resolves to an ARRAY — see the array guard in the
	 * comparison emitter), and `$now`.
	 *
	 * @param mixed $value The value to resolve.
	 *
	 * @return mixed The resolved value, or null if the variable cannot be resolved.
	 */
	private function resolveDynamicValue(mixed $value): mixed {
		return $this->conditionMatcher->resolveDynamicValue(value: $value);
	}//end resolveDynamicValue()

	/**
	 * Build SQL condition for a single property match
	 *
	 * @param IQueryBuilder $qb Query builder
	 * @param string $property Property name
	 * @param mixed $value Value or operator object
	 *
	 * @return mixed SQL expression or null
	 */
	private function buildPropertyCondition(IQueryBuilder $qb, string $property, mixed $value): mixed {
		// Convert camelCase property to snake_case column name.
		$columnName = $this->propertyToColumnName(property: $property);

		// Resolve dynamic variables in the value.
		$resolvedValue = $this->resolveDynamicValue(value: $value);

		// Fail closed when a dynamic variable resolves to null (#1953): emit an
		// IMPOSSIBLE predicate (1 = 0) for this property rather than returning null.
		// Returning null would let buildMatchConditions() silently DROP the predicate
		// from the AND, degrading a multi-condition match rule to its surviving
		// static predicates and leaking objects on the LIST path that the PHP/find
		// path (ConditionMatcher) denies. The impossible predicate makes the LIST and
		// FIND verdicts identical (both deny) for unresolved variables.
		if ($value !== $resolvedValue && $resolvedValue === null) {
			return $this->impossibleCondition(qb: $qb);
		}

		// Simple value: equals comparison.
		if (is_string($resolvedValue) === true || is_numeric($resolvedValue) === true || is_bool($resolvedValue) === true) {
			return $qb->expr()->eq("t.{$columnName}", $this->bindScalar(qb: $qb, value: $resolvedValue));
		}

		// Operator object.
		if (is_array($resolvedValue) === true) {
			return $this->buildOperatorCondition(qb: $qb, columnName: $columnName, operators: $resolvedValue);
		}

		// Null value: is null check.
		if ($resolvedValue === null) {
			return $qb->expr()->isNull("t.{$columnName}");
		}

		return null;
	}//end buildPropertyCondition()

	/**
	 * Build SQL condition for operator-based match
	 *
	 * Every operator of the property is applied (AND), as OperatorEvaluator
	 * does on find: `{"$gte": 18, "$lt": 65}` used to list on its first
	 * operator alone. An operator that cannot be built emits the impossible
	 * predicate instead of being dropped, so a malformed rule denies on the
	 * list as it does on find (openregister#4089).
	 *
	 * @param IQueryBuilder $qb Query builder
	 * @param string $columnName Column name
	 * @param array $operators Operator conditions
	 *
	 * @return mixed SQL expression or null when there are no operators
	 */
	private function buildOperatorCondition(IQueryBuilder $qb, string $columnName, array $operators): mixed {
		$conditions = [];
		foreach ($operators as $operator => $operand) {
			$result = null;
			if (is_string($operator) === true) {
				$result = $this->buildSingleOperatorCondition(
					qb: $qb,
					columnName: $columnName,
					operator: $operator,
					operand: $operand
				);
			}

			$conditions[] = ($result ?? $this->impossibleCondition(qb: $qb));
		}//end foreach

		if (empty($conditions) === true) {
			return null;
		}

		if (count($conditions) === 1) {
			return $conditions[0];
		}

		return $qb->expr()->andX(...$conditions);
	}//end buildOperatorCondition()

	/**
	 * Build a single operator condition for QueryBuilder
	 *
	 * @param IQueryBuilder $qb Query builder
	 * @param string $columnName Column name
	 * @param string $operator Operator (e.g. '$eq', '$gt')
	 * @param mixed $operand Operand value
	 *
	 * @return mixed SQL expression or null if operator not handled
	 */
	private function buildSingleOperatorCondition(
		IQueryBuilder $qb,
		string $columnName,
		string $operator,
		mixed $operand,
	): mixed {
		// Comparison operators.
		$comparisonResult = $this->buildComparisonOperatorCondition(
			qb: $qb,
			columnName: $columnName,
			operator: $operator,
			operand: $operand
		);
		if ($comparisonResult !== null) {
			return $comparisonResult;
		}

		// Array operators ($in, $nin).
		$arrayResult = $this->buildArrayOperatorCondition(
			qb: $qb,
			columnName: $columnName,
			operator: $operator,
			operand: $operand
		);
		if ($arrayResult !== null) {
			return $arrayResult;
		}

		// Array-membership operator ($contains). The QueryBuilder cannot express
		// JSON-array containment on either platform, so this is emitted as a raw
		// fragment through createFunction() — built by the SAME method the
		// raw-SQL emitter uses, so the two list paths cannot drift apart.
		if ($operator === '$contains') {
			$fragment = $this->buildContainsOperatorConditionSql(
				columnName: 't.'.$this->quotedColumn(columnName: $columnName),
				operator: $operator,
				operand: $operand
			);
			if ($fragment === null) {
				return null;
			}

			return $qb->createFunction($fragment);
		}

		// Existence operator ($exists).
		if ($operator === '$exists') {
			if ($operand === true) {
				return $qb->expr()->isNotNull("t.{$columnName}");
			}

			return $qb->expr()->isNull("t.{$columnName}");
		}

		$this->logger->warning(
			message: '[MagicRbacHandler] Unknown operator',
			context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
		);

		return null;
	}//end buildSingleOperatorCondition()

	/**
	 * Build comparison operator condition ($eq, $ne, $gt, $gte, $lt, $lte) for QueryBuilder
	 *
	 * @param IQueryBuilder $qb Query builder
	 * @param string $columnName Column name
	 * @param string $operator Operator string
	 * @param mixed $operand Operand value
	 *
	 * @return mixed SQL expression or null if not a comparison operator
	 */
	private function buildComparisonOperatorCondition(
		IQueryBuilder $qb,
		string $columnName,
		string $operator,
		mixed $operand,
	): mixed {
		$comparisonMap = [
			'$eq' => 'eq',
			'$ne' => 'neq',
			'$gt' => 'gt',
			'$gte' => 'gte',
			'$lt' => 'lt',
			'$lte' => 'lte',
		];

		if (isset($comparisonMap[$operator]) === false) {
			return null;
		}

		// Resolve dynamic variables in the operand (e.g. "$now" → current datetime).
		$resolvedOperand = $this->resolveDynamicValue(value: $operand);

		// Fail closed on an ARRAY operand. Now that resolution is delegated to the
		// shared matcher, a token like `$user.groups` resolves to an array — and a
		// scalar comparison cannot express that. Emitting it anyway would stringify
		// the array to "Array" and compare against that literal, which is a
		// silent, wrong verdict rather than a refusal.
		if (is_array($resolvedOperand) === true) {
			$this->logger->warning(
				message: '[MagicRbacHandler] Array operand for a scalar comparison operator — emitting an impossible predicate',
				context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
			);
			return $this->impossibleCondition(qb: $qb);
		}

		$method = $comparisonMap[$operator];
		return $qb->expr()->{$method}("t.{$columnName}", $this->bindScalar(qb: $qb, value: $resolvedOperand));
	}//end buildComparisonOperatorCondition()

	/**
	 * Bind a scalar match value with the parameter type its PHP type needs.
	 *
	 * A bool bound with the default PARAM_STR is cast to string by PDO, so
	 * `false` reaches the database as '' and PostgreSQL refuses it for a boolean
	 * column: `invalid input syntax for type boolean: ""`. That 500ed every
	 * non-admin read of a schema whose read rule matches on `false` (hermiq's
	 * agent, `{"isPrivate": false}`), while the raw-SQL list path already wrote
	 * a FALSE literal. PARAM_BOOL sends a real boolean on PostgreSQL and 0/1 on
	 * MySQL/MariaDB, where boolean columns are integers.
	 *
	 * @param IQueryBuilder $qb    The query builder.
	 * @param mixed         $value The resolved scalar value.
	 *
	 * @return IParameter The named parameter placeholder.
	 *
	 * @spec exclude bug fix: a boolean RBAC match value was bound as the empty string on PostgreSQL
	 */
	private function bindScalar(IQueryBuilder $qb, mixed $value): IParameter {
		if (is_bool($value) === true) {
			return $qb->createNamedParameter($value, IQueryBuilder::PARAM_BOOL);
		}

		return $qb->createNamedParameter($value);
	}//end bindScalar()

	/**
	 * Build array operator condition ($in, $nin) for QueryBuilder
	 *
	 * @param IQueryBuilder $qb Query builder
	 * @param string $columnName Column name
	 * @param string $operator Operator string
	 * @param mixed $operand Operand value (expected array)
	 *
	 * @return mixed SQL expression or null if not an array operator or invalid operand
	 */
	private function buildArrayOperatorCondition(
		IQueryBuilder $qb,
		string $columnName,
		string $operator,
		mixed $operand,
	): mixed {
		$arrayMap = [
			'$in' => 'in',
			'$nin' => 'notIn',
		];

		if (isset($arrayMap[$operator]) === false) {
			return null;
		}

		// A map or a scalar is not a list of values: `in('Array')` matched
		// nothing by accident and `$nin` over it matched everything
		// (openregister#4089). Deny, as OperatorEvaluator does on find.
		if (is_array($operand) === false || array_is_list($operand) === false) {
			return $this->impossibleCondition(qb: $qb);
		}

		// An empty list: nothing is in it, and every present value is not.
		if (empty($operand) === true) {
			if ($operator === '$in') {
				return $this->impossibleCondition(qb: $qb);
			}

			return $qb->expr()->isNotNull("t.{$columnName}");
		}

		$method = $arrayMap[$operator];
		return $qb->expr()->{$method}(
			"t.{$columnName}",
			$qb->createNamedParameter($operand, IQueryBuilder::PARAM_STR_ARRAY)
		);
	}//end buildArrayOperatorCondition()

	/**
	 * The column name a `match` key renders to
	 *
	 * @param string $property The `match` key
	 *
	 * @return string The column name, safe whatever the key holds
	 */
	private function propertyToColumnName(string $property): string {
		return (new RuleColumns())->columnOf(key: $property);
	}//end propertyToColumnName()

	/**
	 * Quote a column name as this platform's identifier
	 *
	 * @param string $columnName The column name, from {@see propertyToColumnName()}
	 *
	 * @return string The quoted identifier
	 */
	private function quotedColumn(string $columnName): string {
		if ($this->isPostgres() === true) {
			return '"'.$columnName.'"';
		}

		return '`'.$columnName.'`';
	}//end quotedColumn()

	/**
	 * Check if a user has permission to perform an action on a schema
	 *
	 * This is a non-query version of the RBAC check for use in validation.
	 * Note: This method checks if user has ANY possible access to the schema.
	 * For conditional rules with match criteria, this returns true if the user
	 * qualifies for the group AND (when object data is supplied) ConditionMatcher
	 * confirms the object satisfies the match clause.
	 *
	 * @param Schema $schema Schema to check
	 * @param string $action CRUD action to check
	 * @param string|null $objectOwner Optional object owner for ownership check
	 * @param array|null $objectData Optional object data for conditional checks
	 * @param array|null $objectAuthorization Optional per-object `_authorization`, carrying its scope
	 * @param string|null $objectUuid Optional object UUID, for per-object grant resolution
	 *
	 * @return bool True if user has permission
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function hasPermission(
		Schema $schema,
		string $action,
		?string $objectOwner = null,
		?array $objectData = null,
		?array $objectAuthorization = null,
		?string $objectUuid = null,
	): bool {
		$check = $this->permissionCheckFor(schema: $schema, action: $action)
			->onObject(objectData: $objectData, objectAuthorization: $objectAuthorization)
			->identifiedBy(objectUuid: $objectUuid);

		// Admin users have all permissions.
		if ($check->callerIsAdmin() === true) {
			return true;
		}

		// The cascaded block, resolved ONCE for the deny pass, the scope and the
		// rule chain, with one fail-closed path.
		try {
			$check->cascaded = $this->resolveSchemaAuthorization(schema: $schema);
		} catch (AuthorizationUnresolvableException $e) {
			return false;
		}

		// The DENY pass, BEFORE the owner bypass. A deny removes the verb inside
		// its scope and nothing puts it back — and the object-level deny an
		// author is most likely to write is precisely one about a row somebody
		// owns. Administrators returned above and stay exempt, for the reason
		// {@see \OCA\OpenRegister\Service\Object\PermissionHandlerInterface::denialFor()}
		// gives: an access model that can deny its own administration away is
		// recoverable only from the database.
		if ($this->deniesHere(check: $check) === true) {
			return false;
		}

		if ($check->callerOwns(objectOwner: $objectOwner) === true) {
			return true;
		}

		if ($this->privateObjectRefuses(check: $check) === true) {
			return false;
		}

		return $this->rulesGrant(check: $check);
	}//end hasPermission()

	/**
	 * Start a permission check for the current caller.
	 *
	 * @param Schema $schema The schema checked.
	 * @param string $action The CRUD action checked.
	 *
	 * @return RbacPermissionCheck The check, carrying the caller's user ID and groups.
	 */
	private function permissionCheckFor(Schema $schema, string $action): RbacPermissionCheck {
		$user = $this->userSession->getUser();
		$userId = $user?->getUID();

		$userGroups = [];
		if ($user !== null) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		}

		return (new RbacPermissionCheck(schema: $schema, action: $action))->forCaller(userId: $userId, userGroups: $userGroups);
	}//end permissionCheckFor()

	/**
	 * Whether the `private` scope refuses the object to this caller.
	 *
	 * Owner and administrators both returned before this point, so a private
	 * object refuses anything reaching here unless a per-object grant admits
	 * the caller; the rule chain then still decides, because the schema stays
	 * the CEILING (design D3b). The scope is read from the cascaded block, the
	 * same one both list emitters use. Only an object can be private: without
	 * object data this is a schema-level check.
	 *
	 * @param RbacPermissionCheck $check The permission check.
	 *
	 * @return bool True when the object is private and the caller holds no grant.
	 */
	private function privateObjectRefuses(RbacPermissionCheck $check): bool {
		if ($check->objectData === null) {
			return false;
		}

		if ($this->objectScope()->isPrivate(
			objectAuthorization: $check->objectAuthorization,
			schemaAuthorization: $check->cascaded
		) === false
		) {
			return false;
		}

		return $this->objectGrants()?->isGranted(
			userId: $check->userId,
			objectUuid: $check->objectUuid,
			action: $check->action
		) !== true;
	}//end privateObjectRefuses()

	/**
	 * Whether the rule chain of the cascaded block grants the action.
	 *
	 * The cascaded block (schema-level, else the register's block with its roles
	 * expanded, with the `mcp` scope stripped) is the same block both list
	 * emitters filter on. An empty block is open to everyone; a non-empty block
	 * that does not list the action denies it (consistent with PermissionHandlerInterface).
	 *
	 * Conditional match evaluation is delegated to the shared
	 * {@see \OCA\OpenRegister\Service\ConditionMatcher} (ADR-011), which also
	 * serves PermissionHandlerInterface and PropertyRbacHandler; the SQL emitter handles
	 * the row-level path.
	 *
	 * @param RbacPermissionCheck $check The permission check.
	 *
	 * @return bool True when a rule grants the action.
	 */
	private function rulesGrant(RbacPermissionCheck $check): bool {
		if (empty($check->cascaded) === true) {
			return true;
		}

		$rules = ($check->cascaded[$check->action] ?? []);
		if (empty($rules) === true) {
			return false;
		}

		// Whether authenticated users inherit `public` rights, resolved once;
		// anonymous users always qualify for `public`.
		$check->inheritFromPublic = $this->authenticatedInheritsPublic(schema: $check->schema);

		foreach ($rules as $rule) {
			if ($this->ruleGrants(check: $check, rule: $rule) === true) {
				return true;
			}
		}

		return false;
	}//end rulesGrant()

	/**
	 * Whether one rule of the chain grants the action.
	 *
	 * A string rule is a direct group match, `public`, `authenticated` or a
	 * `user:<uid>` override. A conditional rule is an array with a `group` (or
	 * `user`) and an optional `match`, which must select the object when object
	 * data is supplied.
	 *
	 * @param RbacPermissionCheck $check The permission check.
	 * @param mixed               $rule  The rule.
	 *
	 * @return bool True when the rule grants the action.
	 */
	private function ruleGrants(RbacPermissionCheck $check, mixed $rule): bool {
		if (is_string($rule) === true) {
			return $this->stringRuleGrants(check: $check, rule: $rule);
		}

		if (is_array($rule) === false || (isset($rule['group']) === false && isset($rule['user']) === false)) {
			return false;
		}

		if ($this->qualifiesForConditionalRule(check: $check, rule: $rule) === false) {
			return false;
		}

		$match = ($rule['match'] ?? null);
		if ($match === null || empty($match) === true || $check->objectData === null) {
			return true;
		}

		return $this->conditionMatcher->objectMatchesConditions(object: $check->objectData, match: $match) === true;
	}//end ruleGrants()

	/**
	 * Whether a string rule names the caller.
	 *
	 * @param RbacPermissionCheck $check The permission check.
	 * @param string              $rule  The rule.
	 *
	 * @return bool True when the caller qualifies.
	 */
	private function stringRuleGrants(RbacPermissionCheck $check, string $rule): bool {
		return ($rule === 'public' && $this->qualifiesForPublic(userId: $check->userId, inheritFromPublic: $check->inheritFromPublic) === true)
			|| ($rule === 'authenticated' && $check->userId !== null)
			|| $this->matchesUserOverride(rule: $rule, userId: $check->userId) === true
			|| in_array($rule, $check->userGroups, true) === true;
	}//end stringRuleGrants()

	/**
	 * Whether the caller is the subject of a conditional rule.
	 *
	 * The `authenticated` pseudo-group admits any logged-in user, independent
	 * of real group membership, as both SQL emitters and PermissionHandlerInterface do.
	 *
	 * @param RbacPermissionCheck $check The permission check.
	 * @param array               $rule  The conditional rule.
	 *
	 * @return bool True when the caller qualifies for the rule.
	 */
	private function qualifiesForConditionalRule(RbacPermissionCheck $check, array $rule): bool {
		if (isset($rule['user']) === true && is_string($rule['user']) === true) {
			return $this->matchesUserOverride(rule: 'user:' . $rule['user'], userId: $check->userId);
		}

		$group = ($rule['group'] ?? null);
		return ($group === 'public' && $this->qualifiesForPublic(userId: $check->userId, inheritFromPublic: $check->inheritFromPublic) === true)
			|| ($group === 'authenticated' && $check->userId !== null)
			|| ($group !== null && in_array($group, $check->userGroups, true) === true);
	}//end qualifiesForConditionalRule()

	/**
	 * Whether a deny rule removes this verb from this caller, for this row.
	 *
	 * The row-level PHP half of the deny, and the third of the four enforcement
	 * paths. It reads the SAME cascaded block the emitters read and the SAME
	 * object block the row carries, through the same {@see DenyResolver}, so a
	 * relation-path check and a list cannot disagree about a denial.
	 *
	 * The two levels UNION rather than override: an object block that replaced
	 * the schema's deny would let an object undo a deny written above it.
	 *
	 * @param RbacPermissionCheck $check The caller, the verb, the row and the schema/register block.
	 *
	 * @return bool True when the verb is denied here.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function deniesHere(RbacPermissionCheck $check): bool {
		// Staging (D15). The mode is read here as well as in the two emitters,
		// through the same switch, because this is the third of the four
		// enforcement paths and a path that kept enforcing while the others
		// staged is the drift the single switch exists to prevent.
		if ($this->denyEnforcementMode()->enforces() === false) {
			return false;
		}

		$resolver = $this->denyResolver();

		$merged = $resolver->mergeDeny(
			baseline: $resolver->denyBlock(authorization: $check->cascaded),
			override: $resolver->denyBlock(authorization: $check->objectAuthorization)
		);
		if ($merged === []) {
			return false;
		}

		$block = [DenyResolver::DENY_KEY => $merged];
		$principals = $resolver->principalsFor(userId: $check->userId, userGroups: $check->userGroups);

		if ($resolver->unconditionalDenial(authorization: $block, action: $check->action, principals: $principals) !== null) {
			return true;
		}

		if ($check->objectData === null) {
			return false;
		}

		foreach ($resolver->conditionalDenials(
			authorization: $block,
			action: $check->action,
			principals: $principals
		) as $denial) {
			$rule = $denial['rule'];
			if (is_array($rule) === false || is_array(($rule['match'] ?? null)) === false) {
				continue;
			}

			if ($this->denialSelects(match: $rule['match'], objectData: $check->objectData) === true) {
				return true;
			}
		}

		return false;
	}//end deniesHere()

	/**
	 * Whether a denial's clause selects the row: it matches, or it names metadata no record carries.
	 *
	 * @param array<string, mixed> $match      The clause.
	 * @param array<mixed>         $objectData The row as a rule reads it.
	 *
	 * @return bool True when the denial applies.
	 */
	private function denialSelects(array $match, array $objectData): bool {
		return $this->conditionMatcher->namesUnknownMetadata(match: $match, object: $objectData) === true
			|| $this->conditionMatcher->objectMatchesConditions(object: $objectData, match: $match) === true;
	}//end denialSelects()

	/**
	 * The access predicate for one table, under one alias, as a single string.
	 *
	 * 🔴 THIS EXISTS SO A SUBQUERY OVER A SECOND SCHEMA CANNOT SKIP RBAC.
	 * `RelatedRowExistsClause` refuses to render without an access predicate,
	 * and this is the one it is meant to be given. Writing a second evaluator
	 * for the same question is how the two paths drift, and the one that ends
	 * up wider is the one that discloses, so this delegates to
	 * {@see buildRbacConditionsSql()} rather than re-deriving anything.
	 *
	 * 🔑 THE ALIAS IS NOT OPTIONAL HERE, AND THAT IS THE WHOLE POINT. The
	 * UNION callers take unqualified names because their members carry no
	 * alias. Inside `EXISTS (SELECT 1 FROM <related> r0 WHERE ...)` an
	 * unqualified `_owner` still parses, and binds to the innermost FROM, so it
	 * looks correct. It is correct by accident: the moment the related table
	 * lacks the column, SQL resolves the name against the OUTER query instead
	 * and the access check silently tests the wrong row. That failure is
	 * invisible, and it fails open.
	 *
	 * The two degenerate answers are returned as SQL literals rather than as an
	 * empty string, because an empty predicate AND-ed into a WHERE is not "no
	 * opinion", it is "admit everything":
	 *
	 * - a bypass (admin) becomes `TRUE`;
	 * - no conditions at all is DENY ALL and becomes `FALSE`, never `TRUE` and
	 *   never omitted.
	 *
	 * @param Schema $schema The schema of the rows the subquery reads.
	 * @param string $alias  The alias those rows carry in the subquery.
	 * @param string $action The CRUD action being filtered.
	 * @param string|null $tableName The bare magic table of the aliased rows; a `_status` column it lacks is left out of the draft predicate.
	 *
	 * @return string A predicate, always non-empty, safe to AND into a WHERE.
	 *
	 * @throws InvalidArgumentException When the alias is not a plain identifier.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function buildRbacPredicateForAlias(Schema $schema, string $alias, string $action = 'read', ?string $tableName = null): string {
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $alias) !== 1) {
			throw new InvalidArgumentException(
				sprintf('\'%s\' is not a table alias an access predicate may be built for.', $alias)
			);
		}

		$result = $this->buildRbacConditionsSql(
			schema: $schema,
			action: $action,
			columnPrefix: $alias . '.',
			tableName: $tableName
		);

		if (($result['bypass'] ?? false) === true) {
			return 'TRUE';
		}

		$conditions = ($result['conditions'] ?? []);
		if ($conditions === []) {
			// Deny all. Said out loud, because an omitted predicate reads as no
			// restriction and this is the opposite of that.
			return 'FALSE';
		}

		return '(' . implode(' OR ', $conditions) . ')';
	}//end buildRbacPredicateForAlias()

	/**
	 * The access predicate for a raw query over one magic table, unaliased.
	 *
	 * The single-table sibling of {@see buildRbacPredicateForAlias()}, for a
	 * caller that writes its own `SELECT ... FROM <magic table> WHERE ...`
	 * (the aggregation fast path) and must count exactly the rows a list read
	 * returns. Delegates to {@see buildRbacConditionsSql()} like its sibling,
	 * so there is one evaluator of the rule grammar, not two.
	 *
	 * @param Schema $schema The schema of the table being read.
	 * @param string $action The action the rows are filtered for (default: 'read').
	 * @param string|null $tableName The bare magic table being read; a `_status` column it lacks is left out of the draft predicate.
	 *
	 * @return string|null Null when no row restriction applies (an admin); otherwise
	 *                     a non-empty predicate safe to AND into a WHERE, `1 = 0`
	 *                     for deny-all (never an empty string, which would admit
	 *                     everything).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function buildRbacRowPredicateSql(Schema $schema, string $action = 'read', ?string $tableName = null): ?string {
		$result = $this->buildRbacConditionsSql(schema: $schema, action: $action, tableName: $tableName);
		if (($result['bypass'] ?? false) === true) {
			return null;
		}

		$conditions = ($result['conditions'] ?? []);
		if ($conditions === []) {
			return '1 = 0';
		}

		return '(' . implode(' OR ', $conditions) . ')';
	}//end buildRbacRowPredicateSql()

	/**
	 * Build RBAC conditions as raw SQL for use in UNION queries.
	 *
	 * This is the raw SQL equivalent of applyRbacFilters() for use in UNION-based
	 * queries where QueryBuilder cannot be used directly.
	 *
	 * @param Schema $schema Schema with authorization configuration.
	 * @param string $action CRUD action to check (default: 'read').
	 * @param string $columnPrefix Table alias to qualify each column with, empty for an unaliased
	 *                             UNION member. Unqualified, a column name still parses inside a
	 *                             subquery and binds to the innermost FROM, which is silently wrong
	 *                             the moment the related table does not carry it.
	 * @param string|null $tableName The bare magic table; a `_status` column it lacks is left out of the draft predicate.
	 *
	 * @return array{bypass: bool, conditions: string[]} Result with:
	 *                                                   - 'bypass' => true means no filtering needed (user has full access)
	 *                                                   - 'conditions' => SQL conditions to OR together, empty array means deny all
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) Mirrors applyRbacFilters dispatch; carries the system-owner carve-out (openregister#1617).
	 */
	public function buildRbacConditionsSql(Schema $schema, string $action = 'read', string $columnPrefix = '', ?string $tableName = null): array {
		$user = $this->userSession->getUser();
		$userId = $user?->getUID();

		// Get user groups.
		$userGroups = [];
		if ($user !== null) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		}

		// Admin users bypass all RBAC checks, and so does a userless caller inside
		// ObjectService::runAsSystem(). (This raw-SQL path has never carried the
		// command-line arm of isTrustedSystemCaller(); that stays as it was.)
		if (in_array('admin', $userGroups, true) === true
			|| ($this->isTrustedSystemCaller(user: $user) === true && SystemOperationContext::isActive() === true)
		) {
			return ['bypass' => true, 'conditions' => []];
		}

		// Get effective authorization (schema-level, or register cascade).
		// Fail-closed: an unresolvable authorization yields the deny-all result
		// (`bypass => false` with no conditions) rather than the open bypass.
		try {
			$authorization = $this->resolveSchemaAuthorization(schema: $schema);
		} catch (AuthorizationUnresolvableException $e) {
			$this->logger->error(
				message: '[MagicRbacHandler] Authorization unresolvable; returning deny-all conditions (fail-closed)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schemaId' => $schema->getId(),
					'action' => $action,
					'error' => $e->getMessage(),
				]
			);
			return ['bypass' => false, 'conditions' => []];
		}

		// Assemble the three shared SQL term pieces (deny term, "not private"
		// row predicate, owner-admit conditions). A null result signals that the
		// deny term excludes everything, i.e. the deny-all empty-conditions case.
		$terms = $this->buildRbacSqlTerms(
			authorization: $authorization,
			action: $action,
			userId: $userId,
			userGroups: $userGroups,
			columnPrefix: $columnPrefix,
			schema: $schema,
			tableName: $tableName
		);
		if ($terms === null) {
			return ['bypass' => false, 'conditions' => []];
		}

		$denyTerm    = $terms['denyTerm'];
		$notPrivate  = $terms['notPrivate'];
		$ownerAdmits = $terms['ownerAdmits'];

		// If no authorization is configured, the schema is open to all — but an
		// individual OBJECT may still declare itself private, so this is no
		// longer an unconditional bypass. Mirrors applyRbacFilters exactly.
		if (empty($authorization) === true) {
			return [
				'bypass' => false,
				'conditions' => $this->withDenyTerm(
					conditions: array_merge($ownerAdmits, [$notPrivate]),
					denyTerm: $denyTerm
				),
			];
		}

		// Get authorization rules for this action.
		$rules = $authorization[$action] ?? [];

		// Fail-closed: a non-empty authorization block that does not list this
		// action no longer bypasses filtering (no early `bypass => true`). Flow
		// falls through to the owner conditions; unmatched callers (e.g.
		// anonymous) get the deny-all empty-conditions result — consistent with
		// applyRbacFilters and PermissionHandlerInterface. Admins already returned; the
		// empty-block open-default above is unchanged.
		$conditions = array_merge(
			$ownerAdmits,
			$this->collectRuleConditionsSql(
				rules: $rules,
				schema: $schema,
				userGroups: $userGroups,
				userId: $userId,
				notPrivate: $notPrivate,
				columnPrefix: $columnPrefix
			)
		);

		// Return conditions (empty array means deny all).
		return [
			'bypass' => false,
			'conditions' => $this->withDenyTerm(conditions: $conditions, denyTerm: $denyTerm),
		];
	}//end buildRbacConditionsSql()

	/**
	 * Assemble the shared raw-SQL term pieces for {@see buildRbacConditionsSql()}.
	 *
	 * Builds the DENY term, the "not private" row predicate and the owner-admit
	 * conditions from the same emitters the QueryBuilder path uses. All three
	 * feed UNION members that carry no table alias, hence the UNQUALIFIED column
	 * names (`_owner`, not `t._owner`).
	 *
	 * The DENY term is folded into EVERY OR-ed condition by the caller rather
	 * than appended, which is the same AND the QueryBuilder emitter applies once
	 * to the whole query; appending it as one more alternative would let a denied
	 * row back in. When the deny term excludes everything the emitter returns
	 * `false`, which this helper surfaces as a null return so the caller can emit
	 * the deny-all empty-conditions result.
	 *
	 * @param array<string, mixed>|null $authorization The effective authorization block, or null when unconfigured.
	 * @param string $action The CRUD action being filtered.
	 * @param string|null $userId The current user identifier, or null when unauthenticated.
	 * @param string[] $userGroups The current user's group IDs.
	 * @param string $columnPrefix Table alias to qualify each column with, empty for an unaliased
	 *                             UNION member. Unqualified, a column name still parses inside a
	 *                             subquery and binds to the innermost FROM, which is silently wrong
	 *                             the moment the related table does not carry it.
	 * @param Schema|null $schema The schema, so its drafts can be kept to their owners (decision 180).
	 * @param string|null $tableName The bare magic table; a `_status` column it lacks is left out of the draft predicate.
	 *
	 * @return array{denyTerm: string|null, notPrivate: string, ownerAdmits: string[]}|null The term pieces, or null
	 *                                                                                       when the deny term
	 *                                                                                       excludes everything.
	 */
	private function buildRbacSqlTerms(
		?array $authorization,
		string $action,
		?string $userId,
		array $userGroups,
		string $columnPrefix = '',
		?Schema $schema = null,
		?string $tableName = null
	): ?array {
		$denyTerm = $this->denyFilterSqlFor(
			authorization: $authorization,
			action: $action,
			userId: $userId,
			userGroups: $userGroups,
			columnName: $columnPrefix . '_authorization',
			columns: new RuleColumns(schema: $schema, prefix: $columnPrefix)
		);
		if ($denyTerm === false) {
			return null;
		}

		$notPrivate = $this->reachableRowSqlFor(
			authorization: $authorization,
			columnName: $columnPrefix . '_authorization',
			uuidColumn: $columnPrefix . '_uuid',
			userId: $userId,
			action: $action,
			schema: $schema,
			columnPrefix: $columnPrefix,
			tableName: $tableName
		);

		$ownerAdmits = $this->ownerAdmitConditionsSql(
			userGroups: $userGroups,
			userId: $userId,
			columnPrefix: $columnPrefix
		);

		return [
			'denyTerm'    => $denyTerm,
			'notPrivate'  => $notPrivate,
			'ownerAdmits' => $ownerAdmits,
		];
	}//end buildRbacSqlTerms()

	/**
	 * Fold the deny term into every OR-ed condition.
	 *
	 * The caller of {@see buildRbacConditionsSql()} ORs what it is given, so a
	 * term that has to hold for EVERY row must be AND-ed into each condition
	 * rather than added beside them. Appending it as one more alternative would
	 * invert its meaning exactly: a denied row would be admitted by the term
	 * that was supposed to exclude it.
	 *
	 * @param string[]    $conditions The OR-ed conditions.
	 * @param string|null $denyTerm   The predicate every row must satisfy, or
	 *                                null when the instance is not enforcing
	 *                                denies yet (D15).
	 *
	 * @return string[] The same conditions, each narrowed by the deny term.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function withDenyTerm(array $conditions, ?string $denyTerm): array {
		if ($denyTerm === null) {
			return $conditions;
		}

		$narrowed = [];
		foreach ($conditions as $condition) {
			$narrowed[] = "({$condition} AND {$denyTerm})";
		}

		return $narrowed;
	}//end withDenyTerm()

	/**
	 * Turn one action's rules into raw-SQL conditions.
	 *
	 * Extracted from {@see buildRbacConditionsSql()} so that method stays under
	 * the length budget; the dispatch is unchanged.
	 *
	 * @param array $rules The rules for the action being filtered.
	 * @param Schema $schema The schema, for the inheritFromPublic resolve.
	 * @param array $userGroups The caller's group IDs.
	 * @param string|null $userId The caller.
	 * @param string $notPrivate The reachable-row predicate to AND each rule with.
	 * @param string $columnPrefix Table alias to qualify each column with, empty for an unaliased
	 *                             UNION member. Unqualified, a column name still parses inside a
	 *                             subquery and binds to the innermost FROM, which is silently wrong
	 *                             the moment the related table does not carry it.
	 *
	 * @return string[] SQL conditions to OR together.
	 */
	private function collectRuleConditionsSql(
		array $rules,
		Schema $schema,
		array $userGroups,
		?string $userId,
		string $notPrivate,
		string $columnPrefix = '',
	): array {
		// Resolve whether authenticated users inherit `public` rights once.
		$caller = [
			'groups'            => $userGroups,
			'userId'            => $userId,
			'inheritFromPublic' => $this->authenticatedInheritsPublic(schema: $schema),
			'columns'           => new RuleColumns(schema: $schema, prefix: $columnPrefix),
		];

		$conditions = [];
		foreach ($rules as $rule) {
			$ruleResult = $this->processAuthorizationRuleSql(rule: $rule, caller: $caller);

			if ($ruleResult === true) {
				// Unconditional access via this rule reaches every NON-private
				// row; a private one still answers only to its owner. No later
				// rule can widen past that, hence the break — and this is no
				// longer a `bypass => true` early return.
				$conditions[] = $notPrivate;
				break;
			}

			if (is_string($ruleResult) === true) {
				// A schema rule only ever decides a NON-private row; AND-ing
				// here is what suppresses the schema's rules for a private one.
				$conditions[] = "({$notPrivate} AND {$ruleResult})";
			}
		}//end foreach

		return $conditions;
	}//end collectRuleConditionsSql()

	/**
	 * Process a single authorization rule for raw SQL output.
	 *
	 * @param mixed $rule Authorization rule (string or array).
	 * @param array $caller The caller: groups, userId, inheritFromPublic, and the columns a `match` key may name.
	 *
	 * @return mixed True if unconditional access, SQL string for conditional, false if no access.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function processAuthorizationRuleSql(
		mixed $rule,
		array $caller,
	): mixed {
		// Simple rule: just a group name string.
		if (is_string($rule) === true) {
			return $this->processSimpleRule(
				rule: $rule,
				userGroups: $caller['groups'],
				userId: $caller['userId'],
				inheritFromPublic: $caller['inheritFromPublic']
			);
		}

		// Conditional rule: object with 'group' (or a 'user' override) and
		// optional 'match'.
		if (is_array($rule) === true && (isset($rule['group']) === true || isset($rule['user']) === true)) {
			return $this->processConditionalRuleSql(rule: $rule, caller: $caller);
		}

		return false;
	}//end processAuthorizationRuleSql()

	/**
	 * Process a conditional authorization rule for raw SQL output.
	 *
	 * @param array $rule Rule with 'group' and optional 'match'.
	 * @param array $caller The caller: groups, userId, inheritFromPublic, and the columns a `match` key may name.
	 *
	 * @return mixed True if unconditional access, SQL string for conditional, false if no access.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function processConditionalRuleSql(
		array $rule,
		array $caller,
	): mixed {
		$match = $rule['match'] ?? null;

		// A caller outside the rule's group or named user is not given it.
		if ($this->callerQualifiesForRule(rule: $rule, caller: $caller) === false) {
			return false;
		}

		// If no match conditions, user has unconditional access via this rule.
		if ($match === null || empty($match) === true) {
			return true;
		}

		// A key that names no column of this table admits no row.
		if ($caller['columns']->admits(match: $match) === false) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		// Build SQL conditions for the match criteria.
		return $this->buildMatchConditionsSql(match: $match, columnPrefix: $caller['columns']->prefix);
	}//end processConditionalRuleSql()

	/**
	 * Build SQL conditions for match criteria.
	 *
	 * A clause on metadata no record carries admits no row.
	 *
	 * @param array $match Match conditions.
	 * @param string $columnPrefix Table alias to qualify each column with, empty for an unaliased
	 *                             UNION member. Unqualified, a column name still parses inside a
	 *                             subquery and binds to the innermost FROM, which is silently wrong
	 *                             the moment the related table does not carry it.
	 *
	 * @return string|null SQL expression or null if invalid.
	 */
	private function buildMatchConditionsSql(array $match, string $columnPrefix = ''): ?string {
		if ($this->conditionMatcher->namesUnknownMetadata(match: $match) === true) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		$conditions = [];

		foreach ($match as $property => $value) {
			$condition = $this->buildPropertyConditionSql(property: $property, value: $value, columnPrefix: $columnPrefix);
			if ($condition !== null) {
				$conditions[] = $condition;
			}
		}

		// If no valid conditions, return null.
		if (empty($conditions) === true) {
			return null;
		}

		// All conditions must match (AND logic).
		if (count($conditions) === 1) {
			return $conditions[0];
		}

		return '(' . implode(' AND ', $conditions) . ')';
	}//end buildMatchConditionsSql()

	/**
	 * Build SQL condition for a single property match.
	 *
	 * @param string $property Property name.
	 * @param mixed $value Value or operator object.
	 * @param string $columnPrefix Table alias to qualify each column with, empty for an unaliased
	 *                             UNION member. Unqualified, a column name still parses inside a
	 *                             subquery and binds to the innermost FROM, which is silently wrong
	 *                             the moment the related table does not carry it.
	 *
	 * @return string|null SQL expression or null.
	 */
	private function buildPropertyConditionSql(string $property, mixed $value, string $columnPrefix = ''): ?string {
		// Convert camelCase property to snake_case column name.
		// The prefix qualifies it with a table alias when this predicate is
		// going inside a subquery over a SECOND table. Unqualified, the name
		// would still parse there and bind to the innermost FROM, which is
		// right by accident and silently wrong the moment the related table
		// does not carry the column: SQL then resolves it against the OUTER
		// row, so the access check would pass by testing the wrong record.
		$columnName = $columnPrefix . $this->quotedColumn(columnName: $this->propertyToColumnName(property: $property));

		// Resolve dynamic variables in the value.
		$resolvedValue = $this->resolveDynamicValue(value: $value);

		// Fail closed when a dynamic variable resolves to null (#1953): emit an
		// IMPOSSIBLE predicate (1 = 0) rather than returning null, which would let
		// buildMatchConditionsSql() drop the predicate from the AND and leak objects
		// on this LIST path. Mirrors the QueryBuilder path and ConditionMatcher.
		if ($value !== $resolvedValue && $resolvedValue === null) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		// Simple value: equals comparison.
		if (is_string($resolvedValue) === true || is_numeric($resolvedValue) === true) {
			$quotedValue = $this->quoteValue(value: $resolvedValue);
			return "{$columnName} = {$quotedValue}";
		}

		// Boolean value.
		if (is_bool($resolvedValue) === true) {
			$boolValue = 'FALSE';
			if ($resolvedValue === true) {
				$boolValue = 'TRUE';
			}

			return "{$columnName} = {$boolValue}";
		}

		// Operator object.
		if (is_array($resolvedValue) === true) {
			return $this->buildOperatorConditionSql(columnName: $columnName, operators: $resolvedValue);
		}

		// Null value: is null check.
		if ($resolvedValue === null) {
			return "{$columnName} IS NULL";
		}

		return null;
	}//end buildPropertyConditionSql()

	/**
	 * Build SQL condition for operator-based match.
	 *
	 * @param string $columnName Column name.
	 * @param array $operators Operator conditions.
	 *
	 * @return string|null SQL expression or null.
	 */
	private function buildOperatorConditionSql(string $columnName, array $operators): ?string {
		// Every operator applies (AND) and an unbuildable one denies; see
		// buildOperatorCondition() (openregister#4089).
		$conditions = [];
		foreach ($operators as $operator => $operand) {
			$result = null;
			if (is_string($operator) === true) {
				$result = $this->buildSingleOperatorConditionSql(
					columnName: $columnName,
					operator: $operator,
					operand: $operand
				);
			}

			if ($result === null) {
				$this->logger->warning(
					message: '[MagicRbacHandler] Unknown operator or operand — emitting an impossible predicate',
					context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
				);
			}

			$conditions[] = ($result ?? self::IMPOSSIBLE_SQL_CONDITION);
		}//end foreach

		if (empty($conditions) === true) {
			return null;
		}

		if (count($conditions) === 1) {
			return $conditions[0];
		}

		return '(' . implode(' AND ', $conditions) . ')';
	}//end buildOperatorConditionSql()

	/**
	 * Build a single operator condition as raw SQL
	 *
	 * @param string $columnName Column name
	 * @param string $operator Operator (e.g. '$eq', '$gt')
	 * @param mixed $operand Operand value
	 *
	 * @return string|null SQL expression or null if operator not handled
	 */
	private function buildSingleOperatorConditionSql(string $columnName, string $operator, mixed $operand): ?string {
		// Comparison operators.
		$comparisonResult = $this->buildComparisonOperatorConditionSql(
			columnName: $columnName,
			operator: $operator,
			operand: $operand
		);
		if ($comparisonResult !== null) {
			return $comparisonResult;
		}

		// Array operators ($in, $nin).
		$arrayResult = $this->buildArrayOperatorConditionSql(
			columnName: $columnName,
			operator: $operator,
			operand: $operand
		);
		if ($arrayResult !== null) {
			return $arrayResult;
		}

		// Array-membership operator ($contains) — the object's array must contain
		// the resolved value. Mirrors OperatorEvaluator::operatorContains().
		$containsResult = $this->buildContainsOperatorConditionSql(
			columnName: $columnName,
			operator: $operator,
			operand: $operand
		);
		if ($containsResult !== null) {
			return $containsResult;
		}

		// Existence operator ($exists).
		if ($operator === '$exists') {
			if ($operand === true) {
				return "{$columnName} IS NOT NULL";
			}

			return "{$columnName} IS NULL";
		}

		return null;
	}//end buildSingleOperatorConditionSql()

	/**
	 * Build comparison operator condition ($eq, $ne, $gt, $gte, $lt, $lte) as raw SQL
	 *
	 * @param string $columnName Column name
	 * @param string $operator Operator string
	 * @param mixed $operand Operand value
	 *
	 * @return string|null SQL expression or null if not a comparison operator
	 */
	private function buildComparisonOperatorConditionSql(
		string $columnName,
		string $operator,
		mixed $operand,
	): ?string {
		$comparisonMap = [
			'$eq' => '=',
			'$ne' => '!=',
			'$gt' => '>',
			'$gte' => '>=',
			'$lt' => '<',
			'$lte' => '<=',
		];

		if (isset($comparisonMap[$operator]) === false) {
			return null;
		}

		$sqlOperator = $comparisonMap[$operator];
		$resolvedOperand = $this->resolveDynamicValue(value: $operand);

		// Fail closed when a dynamic variable resolves to null (#1953): emit an
		// IMPOSSIBLE predicate (1 = 0) rather than dropping the condition.
		if ($operand !== $resolvedOperand && $resolvedOperand === null) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		// Fail closed on an ARRAY operand — see the QueryBuilder emitter's note:
		// a resolved `$user.groups` cannot be expressed as a scalar comparison,
		// and quoting it would compare against the literal string "Array".
		if (is_array($resolvedOperand) === true) {
			$this->logger->warning(
				message: '[MagicRbacHandler] Array operand for a scalar comparison operator — emitting an impossible predicate',
				context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
			);
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		$quotedValue = $this->quoteValue(value: $resolvedOperand);
		return "{$columnName} {$sqlOperator} {$quotedValue}";
	}//end buildComparisonOperatorConditionSql()

	/**
	 * Build array operator condition ($in, $nin) as raw SQL
	 *
	 * @param string $columnName Column name
	 * @param string $operator Operator string
	 * @param mixed $operand Operand value (expected array)
	 *
	 * @return string|null SQL expression or null if not an array operator or invalid operand
	 */
	private function buildArrayOperatorConditionSql(string $columnName, string $operator, mixed $operand): ?string {
		$arrayMap = [
			'$in' => 'IN',
			'$nin' => 'NOT IN',
		];

		if (isset($arrayMap[$operator]) === false) {
			return null;
		}

		// Not a list: deny, as the QueryBuilder path and find do (openregister#4089).
		if (is_array($operand) === false || array_is_list($operand) === false) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		if (empty($operand) === true) {
			if ($operator === '$in') {
				return self::IMPOSSIBLE_SQL_CONDITION;
			}

			return "{$columnName} IS NOT NULL";
		}

		$sqlKeyword = $arrayMap[$operator];
		$quotedValues = array_map(fn ($val) => $this->quoteValue(value: $val), $operand);
		return "{$columnName} {$sqlKeyword} (" . implode(', ', $quotedValues) . ')';
	}//end buildArrayOperatorConditionSql()

	/**
	 * Build a `$contains` condition as raw SQL: the column's JSON array must contain the operand.
	 *
	 * The row-level counterpart of {@see \OCA\OpenRegister\Service\OperatorEvaluator}'s
	 * `$contains`. Both MUST agree — a share honoured on `find` but dropped on
	 * `list` reads as an empty page, and the reverse leaks an object.
	 *
	 * Platform-branched, following ReferentialIntegrityService's precedent
	 * (`::jsonb @> to_jsonb(?::text)` on PostgreSQL, `JSON_CONTAINS(col,
	 * JSON_QUOTE(?))` on MariaDB) — the QueryBuilder cannot express either.
	 * `COALESCE(col, '[]')` makes a NULL column contain nothing, matching the PHP
	 * side's "a null or non-array property contains nothing".
	 *
	 * An array-valued operand becomes an OR of containments (ANY-intersection),
	 * so `$user.groups` matches when the column lists any one of them. An empty
	 * or null operand emits the IMPOSSIBLE predicate rather than being dropped:
	 * a dropped condition would leave the rule satisfied by everything else and
	 * fail OPEN.
	 *
	 * @param string $columnName Column name (already qualified/quoted by the caller).
	 * @param string $operator Operator string.
	 * @param mixed $operand Value(s) that must appear in the column's array.
	 *
	 * @return string|null SQL expression, or null when this is not a `$contains`.
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/specs/flow-sharing/spec.md#requirement-the-single-object-and-list-access-decisions-agree
	 */
	private function buildContainsOperatorConditionSql(string $columnName, string $operator, mixed $operand): ?string {
		if ($operator !== '$contains') {
			return null;
		}

		$resolved = $this->resolveDynamicValue(value: $operand);

		// Fail closed when a dynamic variable resolves to null, exactly as the
		// comparison emitter does — never drop the condition.
		if ($resolved === null) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		$candidates = [$resolved];
		if (is_array($resolved) === true) {
			$candidates = array_values(array_filter($resolved, static fn ($val) => $val !== null));
		}

		if (empty($candidates) === true) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		$orParts = [];
		foreach ($candidates as $candidate) {
			$orParts[] = $this->containsPredicateSql(columnName: $columnName, candidate: $candidate);
		}

		if (count($orParts) === 1) {
			return $orParts[0];
		}

		return '(' . implode(' OR ', $orParts) . ')';
	}//end buildContainsOperatorConditionSql()

	/**
	 * One platform-appropriate JSON-array containment predicate.
	 *
	 * @param string $columnName Column reference.
	 * @param mixed $candidate Single value that must appear in the array.
	 *
	 * @return string SQL predicate.
	 */
	private function containsPredicateSql(string $columnName, mixed $candidate): string {
		$quoted = $this->quoteValue(value: (string)$candidate);

		if ($this->isPostgres() === true) {
			return "COALESCE({$columnName}, '[]')::jsonb @> to_jsonb({$quoted}::text)";
		}

		return "JSON_CONTAINS(COALESCE({$columnName}, '[]'), JSON_QUOTE({$quoted}))";
	}//end containsPredicateSql()

	/**
	 * Whether the connected database is MySQL or MariaDB.
	 *
	 * @return bool True for those two; a provider that cannot be read counts as true,
	 *              because doubling a backslash is safe everywhere.
	 */
	private function isMysql(): bool {
		if ($this->isMysqlCache !== null) {
			return $this->isMysqlCache;
		}

		try {
			$provider = $this->container->get(IDBConnection::class)->getDatabaseProvider();
			$this->isMysqlCache = ($provider === IDBConnection::PLATFORM_MYSQL);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[MagicRbacHandler] Could not determine the database provider for quoting',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
			$this->isMysqlCache = true;
		}

		return $this->isMysqlCache;
	}//end isMysql()

	/**
	 * Whether the connected database is PostgreSQL.
	 *
	 * Resolved lazily through the container: this class is constructed on paths
	 * that do not need a connection, and adding one to the constructor would
	 * change its DI signature for every caller. Mirrors
	 * ReferentialIntegrityService's null-safe `get_debug_type()` detection, which
	 * also tolerates a mocked connection returning null in unit tests.
	 *
	 * Defaults to MariaDB syntax when the platform cannot be determined. That is
	 * the conservative direction for a security filter: an unparseable predicate
	 * errors the query rather than silently matching every row.
	 *
	 * @return bool True when the platform is PostgreSQL.
	 */
	private function isPostgres(): bool {
		if ($this->isPostgresCache !== null) {
			return $this->isPostgresCache;
		}

		try {
			$connection = $this->container->get(IDBConnection::class);
			$platform = $connection->getDatabasePlatform();

			$this->isPostgresCache = stripos(get_debug_type($platform), 'PostgreSQL') !== false;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[MagicRbacHandler] Could not determine the database platform for $contains — defaulting to MariaDB syntax',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
			$this->isPostgresCache = false;
		}

		return $this->isPostgresCache;
	}//end isPostgres()

	/**
	 * Quote a value for safe use in raw SQL.
	 *
	 * @param mixed $value Value to quote.
	 *
	 * @return string Quoted value safe for SQL.
	 */
	private function quoteValue(mixed $value): string {
		if ($value === null) {
			return 'NULL';
		}

		if (is_bool($value) === true) {
			if ($value === true) {
				return 'TRUE';
			}

			return 'FALSE';
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		// MySQL and MariaDB read a backslash as an escape inside a string, so a
		// value ending its quote through `\'` needs the backslash doubled as well.
		$escaped = (string)$value;
		if ($this->isMysql() === true) {
			$escaped = str_replace('\\', '\\\\', $escaped);
		}

		$escaped = str_replace("'", "''", $escaped);
		return "'{$escaped}'";
	}//end quoteValue()

	/**
	 * Whether this is a userless SYSTEM caller whose reads RBAC must not filter.
	 *
	 * Two userless contexts are the system rather than a caller: the command
	 * line (occ, repair steps, cron, background calculations), and code running
	 * inside ObjectService::runAsSystem() (SystemOperationContext), which
	 * PermissionHandlerInterface::hasPermission() already trusts. Without the second, a
	 * calculation run by a system write in a WEB request (dossiq's portal Woo
	 * intake resolving caseType and statusType) had every reference read
	 * clamped to nothing.
	 *
	 * Neither opens a user-facing read: a logged-in user is always filtered as
	 * that user, and a forced-anonymous evaluation (WOO-578) asked to be
	 * filtered as nobody, so it never counts as the system.
	 *
	 * @param IUser|null $user The session user.
	 *
	 * @return bool True when the caller is the system.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Both contexts are ambient static markers by design.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function isTrustedSystemCaller(?IUser $user): bool {
		if ($user !== null || AnonymousEvaluationContext::isActive() === true) {
			return false;
		}

		return $this->isCommandLine() === true || SystemOperationContext::isActive() === true;
	}//end isTrustedSystemCaller()

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
	 * Get the current user ID
	 *
	 * @return string|null The current user ID or null if not authenticated
	 */
	public function getCurrentUserId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end getCurrentUserId()

	/**
	 * The caller's group ids, quoted as SQL literals.
	 *
	 * Quoted here, beside the quoter, so the predicate builder on
	 * {@see \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver} never handles an
	 * unquoted value — the same contract `quotedGrantedUuids()` already honours.
	 *
	 * @return string[] Quoted group ids, empty when the caller is anonymous.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function quotedCallerGroups(): array {
		$quoted = [];
		foreach ($this->getCurrentUserGroups() as $groupId) {
			if (is_string($groupId) === false || $groupId === '') {
				continue;
			}

			$quoted[] = $this->quoteValue(value: $groupId);
		}

		return $quoted;
	}//end quotedCallerGroups()

	/**
	 * Get the current user's groups
	 *
	 * @return string[] Array of group IDs
	 */
	public function getCurrentUserGroups(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		return $this->groupManager->getUserGroupIds($user);
	}//end getCurrentUserGroups()

	/**
	 * Check if current user is admin
	 *
	 * @return bool True if user is in admin group
	 */
	public function isAdmin(): bool {
		return in_array('admin', $this->getCurrentUserGroups(), true);
	}//end isAdmin()

	/**
	 * Whether the current caller qualifies for at least one rule of an action.
	 *
	 * A schema-level answer to "can this caller see ANY row through this
	 * action", for a surface that then narrows to the rows themselves with
	 * {@see buildRbacConditionsSql()}. It differs from a schema-level
	 * hasPermission() in exactly one place: a conditional rule (`{group,
	 * match}`) the caller's group qualifies for counts, because its `match`
	 * is a ROW condition the predicate applies afterwards. A schema-level
	 * hasPermission() with no object cannot evaluate that match and refuses,
	 * which shut a reader whose access is "the rows about me" out of the
	 * aggregate over exactly those rows.
	 *
	 * Mirrors buildRbacConditionsSql(): admin qualifies, an unresolvable
	 * authorization does not (fail-closed), an empty block is open, and the
	 * rule dispatch is the same processAuthorizationRuleSql() the predicate
	 * uses, so the two cannot disagree about who a rule admits.
	 *
	 * @param Schema $schema The schema whose rules to consult.
	 * @param string $action The action whose rules to consult (default: 'read').
	 *
	 * @return bool True when at least one rule for the action admits the caller.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function callerQualifiesForAction(Schema $schema, string $action = 'read'): bool {
		$user = $this->userSession->getUser();
		$userId = $user?->getUID();

		$userGroups = [];
		if ($user !== null) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		}

		if (in_array('admin', $userGroups, true) === true) {
			return true;
		}

		try {
			$authorization = $this->resolveSchemaAuthorization(schema: $schema);
		} catch (AuthorizationUnresolvableException $e) {
			return false;
		}

		if (empty($authorization) === true) {
			return true;
		}

		$rules = ($authorization[$action] ?? []);
		if (is_array($rules) === false) {
			return false;
		}

		$caller = [
			'groups'            => $userGroups,
			'userId'            => $userId,
			'inheritFromPublic' => $this->authenticatedInheritsPublic(schema: $schema),
			'columns'           => new RuleColumns(schema: $schema),
		];
		foreach ($rules as $rule) {
			$verdict = $this->processAuthorizationRuleSql(rule: $rule, caller: $caller);
			if ($verdict !== false) {
				return true;
			}
		}

		return false;
	}//end callerQualifiesForAction()

	/**
	 * Whether the caller's read lifts the organisation filter entirely.
	 *
	 * True for an admin, for an anonymous caller on a schema whose own rules
	 * let `public` read every row, and for a caller who holds a read rule of the schema's
	 * own block that is marked `crossOrganisation: true` and has no match: that
	 * rule reads every row, so no organisation filter can narrow it. Every
	 * other rule keeps the filter; a marked rule with a match widens it instead
	 * ({@see hasCrossOrganisationBranch()}).
	 *
	 * @param Schema $schema The schema to check
	 * @param string $action The action to check (default: 'read')
	 *
	 * @return bool True when the organisation filter may be dropped.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function hasConditionalRulesBypassingMultitenancy(Schema $schema, string $action = 'read'): bool {
		// Code acting for an organisation stays inside it: no rule lifts the filter.
		if (OrganisationContext::isActive() === true) {
			return false;
		}

		if ($this->isAdmin() === true) {
			return true;
		}

		if ($this->getCurrentUserId() === null) {
			return (new ReadRuleTenancy())->readsPublicUnconditionally(
				readRules: ((PermissionHandler::stripMcpScope(authorization: $schema->getAuthorization()) ?? [])[$action] ?? [])
			);
		}

		return $action === 'read' && $this->crossOrganisationMatches(schema: $schema) === null;
	}//end hasConditionalRulesBypassingMultitenancy()

	/**
	 * Whether the register cascade admits the rows with NO organisation for the
	 * current caller.
	 *
	 * A schema without an authorization block of its own is governed by its
	 * register's block (the same resolved block {@see applyRbacFilters()}
	 * emits). For a caller that block grants, the organisation filter stays on
	 * and is widened by the rows whose organisation is empty, which belong to no
	 * tenant. Rows of other organisations stay hidden, exactly as they were
	 * before the cascade was consulted here. A schema with its own block answers
	 * false: its own rules decide through
	 * {@see hasConditionalRulesBypassingMultitenancy()}, unchanged.
	 *
	 * @param Schema $schema The schema being read.
	 * @param string $action The action (default: 'read').
	 *
	 * @return bool True when the organisation filter must also admit org-less rows.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public function admitsOrganisationlessRowsThroughCascade(Schema $schema, string $action = 'read'): bool {
		$ownAuthorization = PermissionHandler::stripMcpScope(authorization: $schema->getAuthorization());
		if (empty($ownAuthorization) === false) {
			return false;
		}

		// Fail-closed: an unresolvable block admits nothing extra.
		try {
			$resolved = $this->resolveSchemaAuthorization(schema: $schema);
		} catch (AuthorizationUnresolvableException $e) {
			return false;
		}

		return $this->callerQualifiesForBypassRule(schema: $schema, authorization: $resolved, action: $action);
	}//end admitsOrganisationlessRowsThroughCascade()

	/**
	 * Whether the current caller qualifies for a rule of the given block that
	 * lets RBAC, rather than the organisation filter, govern the rows.
	 *
	 * Asked only for the register cascade's rows with no organisation
	 * ({@see admitsOrganisationlessRowsThroughCascade()}). Rows of other
	 * organisations are decided by the explicit marker alone
	 * ({@see crossOrganisationMatches()}).
	 *
	 * @param Schema     $schema        The schema, for the public-inheritance gate.
	 * @param array|null $authorization The authorization block to read.
	 * @param string     $action        The action.
	 *
	 * @return bool True when the caller is an admin or qualifies for such a rule.
	 */
	private function callerQualifiesForBypassRule(Schema $schema, ?array $authorization, string $action): bool {
		$user = $this->userSession->getUser();

		// Get user groups.
		$userGroups = [];
		if ($user !== null) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		}

		// Admin users bypass all RBAC checks anyway.
		if (in_array('admin', $userGroups, true) === true) {
			return true;
		}

		if (empty($authorization) === true) {
			return false;
		}

		// Get authorization rules for this action.
		$rules = $authorization[$action] ?? [];
		if (empty($rules) === true) {
			return false;
		}

		// Resolve the public-inheritance gate for this schema. A `public` rule
		// only grants an authenticated user a cross-tenant bypass when public
		// inheritance is enabled; anonymous users always qualify. Threading this
		// through keeps the bypass decision consistent with applyRbacFilters /
		// buildRbacConditionsSql / hasPermission, which already gate `public`.
		$userId = $user?->getUID();
		$inheritFromPublic = $this->authenticatedInheritsPublic(schema: $schema);

		$caller = ['groups' => $userGroups, 'userId' => $userId, 'inheritFromPublic' => $inheritFromPublic];
		$verdicts = [];
		foreach ($rules as $rule) {
			$verdicts[] = $this->ruleBypassVerdict(rule: $rule, caller: $caller);
		}

		return $this->verdictsLiftOrganisationFilter(verdicts: $verdicts);
	}//end callerQualifiesForBypassRule()

	/**
	 * Whether the verdicts of every rule the caller holds let RBAC govern the rows.
	 *
	 * A qualifying simple rule reads every row, so it lifts the filter on its
	 * own. Otherwise the caller must qualify for at least one rule that crosses
	 * organisations and for no rule that stays inside one: the RBAC predicate
	 * ORs every qualifying rule, so lifting the filter for one rule lifts it for
	 * all of them.
	 *
	 * @param string[] $verdicts One RULE_* verdict per rule.
	 *
	 * @return bool True when the organisation filter may be dropped.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
	 */
	private function verdictsLiftOrganisationFilter(array $verdicts): bool {
		if (in_array(self::RULE_READS_ALL, $verdicts, true) === true) {
			return true;
		}

		return in_array(self::RULE_CROSSES_ORGANISATIONS, $verdicts, true) === true
			&& in_array(self::RULE_STAYS_IN_ORGANISATION, $verdicts, true) === false;
	}//end verdictsLiftOrganisationFilter()

	/**
	 * How one rule bears on the organisation filter for the current caller.
	 *
	 * @param mixed $rule   Authorization rule (string or array).
	 * @param array $caller The caller: `groups`, `userId` and `inheritFromPublic`.
	 *
	 * @return string One of the RULE_* verdicts.
	 */
	private function ruleBypassVerdict(mixed $rule, array $caller): string {
		if (is_string($rule) === true) {
			return $this->simpleRuleVerdict(rule: $rule, caller: $caller);
		}

		if (is_array($rule) === true) {
			return $this->conditionalRuleVerdict(rule: $rule, caller: $caller);
		}

		return self::RULE_NOT_HELD;
	}//end ruleBypassVerdict()

	/**
	 * The verdict of a simple (group name) rule.
	 *
	 * `public` (for a caller who qualifies) and a group the caller is in read
	 * every row. `authenticated` and a `user:<uid>` override grant the caller
	 * the rows of their own organisation only.
	 *
	 * @param string $rule   Group name.
	 * @param array  $caller The caller: `groups`, `userId` and `inheritFromPublic`.
	 *
	 * @return string One of the RULE_* verdicts.
	 */
	private function simpleRuleVerdict(string $rule, array $caller): string {
		if ($rule === 'public') {
			if ($this->qualifiesForPublic(userId: $caller['userId'], inheritFromPublic: $caller['inheritFromPublic']) === true) {
				return self::RULE_READS_ALL;
			}

			return self::RULE_NOT_HELD;
		}

		if (in_array($rule, $caller['groups'], true) === true) {
			return self::RULE_READS_ALL;
		}

		if ($this->processSimpleRule(
			rule: $rule,
			userGroups: $caller['groups'],
			userId: $caller['userId'],
			inheritFromPublic: $caller['inheritFromPublic']
		) === true
		) {
			return self::RULE_STAYS_IN_ORGANISATION;
		}

		return self::RULE_NOT_HELD;
	}//end simpleRuleVerdict()

	/**
	 * The verdict of a conditional rule.
	 *
	 * A rule whose match pins `_organisation` to the caller's organisation
	 * admits only rows of that organisation by itself, and a conditional
	 * `public` rule admits rows anyone may read, so neither lifts nor keeps
	 * the filter. Any other rule the caller holds stays inside the
	 * caller's organisation unless it crosses organisations ({@see ruleCrossesOrganisations()}) and the caller
	 * holds it through `public` or a group. A crossing rule held only through
	 * `authenticated` or a user override binds its rows to the caller's
	 * organisation by its own match, so it neither lifts nor keeps the filter.
	 *
	 * @param array $rule   Rule with `group` or `user`, and an optional `match`.
	 * @param array $caller The caller: `groups`, `userId` and `inheritFromPublic`.
	 *
	 * @return string One of the RULE_* verdicts.
	 */
	private function conditionalRuleVerdict(array $rule, array $caller): string {
		if ($this->callerHoldsConditionalRule(rule: $rule, caller: $caller) === false) {
			return self::RULE_NOT_HELD;
		}

		if (($rule['group'] ?? null) === 'public'
			|| $this->pinsCallerOrganisation(value: ($rule['match']['_organisation'] ?? null)) === true
		) {
			return self::RULE_NOT_HELD;
		}

		if ($this->ruleCrossesOrganisations(rule: $rule) === false) {
			return self::RULE_STAYS_IN_ORGANISATION;
		}

		$group = ($rule['group'] ?? null);
		if (isset($rule['user']) === false && is_string($group) === true && $group !== 'authenticated') {
			return self::RULE_CROSSES_ORGANISATIONS;
		}

		return self::RULE_NOT_HELD;
	}//end conditionalRuleVerdict()

	/**
	 * Whether the caller holds a conditional rule, by the qualification the
	 * RBAC filter itself applies ({@see processConditionalRule()}).
	 *
	 * @param array $rule   Rule with `group` or `user`.
	 * @param array $caller The caller: `groups`, `userId` and `inheritFromPublic`.
	 *
	 * @return bool True when the rule applies to the caller.
	 */
	private function callerHoldsConditionalRule(array $rule, array $caller): bool {
		if (isset($rule['user']) === true) {
			return is_string($rule['user']) === true
				&& $this->matchesUserOverride(rule: 'user:' . $rule['user'], userId: $caller['userId']) === true;
		}

		$group = ($rule['group'] ?? null);
		return match (true) {
			$group === 'public' => $this->qualifiesForPublic(userId: $caller['userId'], inheritFromPublic: $caller['inheritFromPublic']),
			$group === 'authenticated' => $caller['userId'] !== null,
			default => $group !== null && in_array($group, $caller['groups'], true) === true,
		};
	}//end callerHoldsConditionalRule()

	/**
	 * Whether a conditional rule's rows may come from other organisations.
	 *
	 * Two shapes cross: a rule that declares `"crossOrganisation": true`, and a
	 * rule whose match binds a field other than `_organisation` to the caller's
	 * organisation or membership (`{"aanbieder": "$organisation"}`,
	 * `{"administrationId": {"$memberOf": {...}}}`). A match on any other value,
	 * such as a case type or a status, selects rows inside the organisation.
	 * This reading decides only the register cascade's org-less rows.
	 *
	 * @param array $rule The conditional rule.
	 *
	 * @return bool True when the rule crosses organisations.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
	 */
	private function ruleCrossesOrganisations(array $rule): bool {
		$tenancy = new ReadRuleTenancy();
		return $tenancy->isMarked(rule: $rule) === true || $tenancy->bindsFieldToCallerTenancy(rule: $rule) === true;
	}//end ruleCrossesOrganisations()

	/**
	 * Whether a match value is the caller's organisation, see {@see ReadRuleTenancy::pinsCallerOrganisation()}.
	 *
	 * @param mixed $value The match value as written.
	 *
	 * @return bool True when the value equals the caller's organisation.
	 */
	private function pinsCallerOrganisation(mixed $value): bool {
		return (new ReadRuleTenancy())->pinsCallerOrganisation(value: $value);
	}//end pinsCallerOrganisation()

	/**
	 * Resolve the effective authorization for a schema.
	 *
	 * Delegates to PermissionHandlerInterface::resolveAuthorization() which handles
	 * register cascade and role expansion. Falls back to schema-only
	 * authorization if PermissionHandlerInterface is not available.
	 *
	 * @param Schema $schema The schema to resolve authorization for.
	 *
	 * @return array|null The effective authorization array.
	 *
	 * @throws AuthorizationUnresolvableException When authorization cannot be determined. Callers MUST deny.
	 */
	private function resolveSchemaAuthorization(Schema $schema): ?array {
		try {
			$permissionHandler = $this->container->get(PermissionHandlerInterface::class);
			return $permissionHandler->resolveAuthorization($schema);
		} catch (AuthorizationUnresolvableException $e) {
			// Fail-closed: propagate. Falling back to the schema's own (possibly
			// absent) authorization here would re-open the very hole the resolver
			// now closes — `empty($authorization)` below means "open to all".
			throw $e;
		} catch (\Throwable $e) {
			// Fallback to direct schema authorization if PermissionHandlerInterface unavailable.
			$this->logger->debug(
				message: '[MagicRbacHandler] PermissionHandler unavailable, using schema auth directly',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return PermissionHandler::stripMcpScope(authorization: $schema->getAuthorization());
		}
	}//end resolveSchemaAuthorization()

	/**
	 * Decide whether the current user qualifies for the system-row visibility
	 * carve-out (openregister#1617).
	 *
	 * Admins are NOT handled here — they bypass RBAC entirely at the top of
	 * the filter methods. This helper exists for non-admin users in groups
	 * configured under `openregister.systemReaderGroups`.
	 *
	 * @param string[] $userGroups The current user's group IDs.
	 *
	 * @return bool True if the user should see rows owned by the system identifier.
	 */
	private function shouldGrantSystemRowVisibility(array $userGroups): bool {
		if (empty($userGroups) === true) {
			return false;
		}

		$readerGroups = $this->resolveOrganisationService()?->getSystemReaderGroups() ?? [];
		if (empty($readerGroups) === true) {
			return false;
		}

		return count(array_intersect($userGroups, $readerGroups)) > 0;
	}//end shouldGrantSystemRowVisibility()

	/**
	 * Get the configured system identifier used as `_owner` for session-less
	 * writes.
	 *
	 * Falls back to {@see OrganisationService::SYSTEM_USER_ID_DEFAULT} when
	 * the OrganisationService is not available in the container (defensive
	 * default; matches the helper's own fallback path).
	 *
	 * @return string The system identifier.
	 */
	private function getSystemUserId(): string {
		$service = $this->resolveOrganisationService();
		if ($service === null) {
			return \OCA\OpenRegister\Service\OrganisationService::SYSTEM_USER_ID_DEFAULT;
		}

		return $service->getSystemUserId();
	}//end getSystemUserId()

	/**
	 * Lazy-load OrganisationService from the container.
	 *
	 * Matches the lazy-load pattern already in use elsewhere in this class
	 * (see e.g. line 415) — avoids constructor-level circular DI dependencies.
	 *
	 * @return \OCA\OpenRegister\Service\OrganisationServiceInterface|null The service or
	 *                                                            null if the container cannot resolve it (defensive).
	 */
	private function resolveOrganisationService(): ?\OCA\OpenRegister\Service\OrganisationServiceInterface {
		try {
			return $this->container->get(\OCA\OpenRegister\Service\OrganisationServiceInterface::class);
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[MagicRbacHandler] OrganisationService unavailable - skipping system-owner carve-out',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveOrganisationService()
}//end class
