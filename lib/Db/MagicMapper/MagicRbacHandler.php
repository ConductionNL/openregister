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
use OCA\OpenRegister\Service\Rbac\GrantLookup;
use OCA\OpenRegister\Service\Rbac\AuthorizationColumn;
use OCA\OpenRegister\Service\Rbac\DenyResolverInterface;
use OCA\OpenRegister\Service\Rbac\GrantedRows;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolverInterface;
use OCA\OpenRegister\Service\Rbac\RbacCollaborators;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\AuthorizationUnresolvableException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementModeInterface;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolverInterface;
use OCA\OpenRegister\Service\Rbac\RbacFallbacks;
use OCA\OpenRegister\Service\Rbac\ReadRuleTenancy;
use OCA\OpenRegister\Service\Rbac\RuleColumns;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
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
 * @SuppressWarnings(PHPMD.StaticAccess) McpScope::strip() is deliberately static and pure so BOTH rule
 *      interpreters apply the byte-identical transform. Injecting it would let this one be constructed without it and
 *      silently diverge from the PHP-layer evaluator on which entries are inert — the exact drift ADR-011 warns about.
 */
class MagicRbacHandler implements RelatedRowRbacInterface {

	/**
	 * Raw-SQL impossible predicate used to fail closed on the SQL-string RBAC path.
	 *
	 * Emitted when a `match` rule's dynamic variable resolves to null so the
	 * predicate is NOT silently dropped from the AND (#1953). Mirrors the
	 * QueryBuilder path's {@see RbacQueryMatch::impossible()} and ConditionMatcher's
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
	 * The compiler of `match` blocks into raw SQL, with its dialect; built on first use.
	 *
	 * @var RbacMatchSql|null
	 */
	private ?RbacMatchSql $matchSql = null;

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
	 * @param DenyEnforcementModeInterface|null $denyEnforcementMode The staging switch; nullable for the same reason.
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
		private readonly ?DenyEnforcementModeInterface $denyEnforcementMode = null,
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
	 * @return DenyResolverInterface The one reader of the deny grammar.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function denyResolver(): DenyResolverInterface {
		return ($this->resolvers->denyResolver ?? RbacCollaborators::denyResolver());
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
	 * @return DenyEnforcementModeInterface The one reader of `openregister.deny_enforcement`.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function denyEnforcementMode(): DenyEnforcementModeInterface {
		return ($this->denyEnforcementMode ?? RbacFallbacks::denyEnforcement(
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
	 * @return ObjectScopeResolverInterface The one definition of the scope vocabulary.
	 */
	private function objectScope(): ObjectScopeResolverInterface {
		return ($this->resolvers->objectScopeResolver ?? RbacFallbacks::objectScope());
	}//end objectScope()

	/**
	 * The compiler of `match` blocks into raw SQL.
	 *
	 * Built lazily: the platform it quotes for is read from the container on
	 * first use, and this class is constructed on paths that never need it.
	 *
	 * @return RbacMatchSql The compiler.
	 */
	private function matchSql(): RbacMatchSql {
		if ($this->matchSql === null) {
			$this->matchSql = new RbacMatchSql(
				matcher: $this->conditionMatcher,
				dialect: new RbacSqlDialect(container: $this->container, logger: $this->logger)
			);
		}

		return $this->matchSql;
	}//end matchSql()

	/**
	 * The compiler of `match` blocks into expressions of one query.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return RbacQueryMatch The compiler.
	 */
	private function queryMatch(IQueryBuilder $qb): RbacQueryMatch {
		return new RbacQueryMatch(qb: $qb, sql: $this->matchSql());
	}//end queryMatch()

	/**
	 * Fill a call with the session caller: user id and groups.
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return IUser|null The session user.
	 */
	private function identifyCaller(RbacAccessCall $call): ?IUser {
		$user = $this->userSession->getUser();
		$call->userId = $user?->getUID();

		$call->userGroups = [];
		if ($user !== null) {
			$call->userGroups = $this->groupManager->getUserGroupIds($user);
		}

		return $user;
	}//end identifyCaller()

	/**
	 * Resolve a call's effective authorization (schema-level, or the register cascade).
	 *
	 * @param RbacAccessCall $call The call; its authorization is set when it resolves.
	 *
	 * @return AuthorizationUnresolvableException|null Why it did not resolve, or null when it did.
	 */
	private function unresolvableAuthorization(RbacAccessCall $call): ?AuthorizationUnresolvableException {
		try {
			$call->authorization = $this->resolveSchemaAuthorization(schema: $call->schema);
			return null;
		} catch (AuthorizationUnresolvableException $e) {
			return $e;
		}
	}//end unresolvableAuthorization()

	/**
	 * Log that a call's authorization could not be resolved, and what the emitter does about it.
	 *
	 * @param RbacAccessCall                    $call  The call.
	 * @param AuthorizationUnresolvableException $error Why it did not resolve.
	 *
	 * @return void
	 */
	private function logUnresolvable(RbacAccessCall $call, AuthorizationUnresolvableException $error): void {
		$message = '[MagicRbacHandler] Authorization unresolvable; returning deny-all conditions (fail-closed)';
		if ($call instanceof RbacFilterCall) {
			$message = '[MagicRbacHandler] Authorization unresolvable; clamping query to deny-all (fail-closed)';
		}

		$this->logger->error(
			message: $message,
			context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $call->schema->getId(), 'action' => $call->action, 'error' => $error->getMessage()]
		);
	}//end logUnresolvable()

	/**
	 * The caller as the rule interpreters read it.
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return array{groups: array<int, string>, userId: string|null, inheritFromPublic: bool, columns: RuleColumns}
	 */
	private function ruleCaller(RbacAccessCall $call): array {
		return [
			'groups'            => $call->userGroups,
			'userId'            => $call->userId,
			'inheritFromPublic' => $this->authenticatedInheritsPublic(schema: $call->schema),
			'columns'           => $call->columns(),
		];
	}//end ruleCaller()

	/**
	 * The shared per-object grant resolver.
	 *
	 * Unlike the scope resolver this one is NOT stateless — it memoises per
	 * request — so the injected instance is strongly preferred and the fallback
	 * is resolved through the container to keep one instance per request. A
	 * fresh instance per call would re-read core's shares on every row-level
	 * decision.
	 *
	 * @return ObjectGrantResolverInterface|null The resolver, or null when unavailable.
	 */
	private function objectGrants(): ?ObjectGrantResolverInterface {
		if ($this->resolvers?->objectGrantResolver !== null) {
			return $this->resolvers->objectGrantResolver;
		}

		try {
			$resolved = RbacCollaborators::objectGrantsFrom(container: $this->container);
			if (($resolved instanceof ObjectGrantResolverInterface) === true) {
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
	 * Note the column prefix: this emitter feeds UNION members that carry no
	 * table alias (prefix ''), and subqueries over a second table, where an
	 * unqualified column would bind to the innermost FROM.
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return array<int, string> Raw SQL conditions, to be ORed with the rule conditions.
	 */
	private function ownerAdmitConditionsSql(RbacAccessCall $call): array {
		$ownerColumn = $call->columnPrefix . '_owner';
		$conditions = $this->ownerAdmitTerms(
			call: $call,
			ownerTerm: fn (string $ownerId): string => "{$ownerColumn} = " . $this->quoteValue(value: $ownerId)
		);

		$ownedByMyGroup = $this->ownedByMyGroupSql(call: $call);
		if ($ownedByMyGroup !== null) {
			$conditions[] = $ownedByMyGroup;
		}

		return $conditions;
	}//end ownerAdmitConditionsSql()

	/**
	 * The owner terms of both emitters: the caller's own rows, and system-owned
	 * rows when the caller is one of the system reader groups.
	 *
	 * @param RbacAccessCall $call      The call.
	 * @param callable       $ownerTerm Builds the term for one owner id.
	 *
	 * @return array<int, mixed> The terms, in that order.
	 */
	private function ownerAdmitTerms(RbacAccessCall $call, callable $ownerTerm): array {
		$terms = [];
		if ($call->userId !== null) {
			$terms[] = $ownerTerm($call->userId);
		}

		if ($this->shouldGrantSystemRowVisibility(userGroups: $call->userGroups) === true) {
			$terms[] = $ownerTerm($this->getSystemUserId());
		}

		return $terms;
	}//end ownerAdmitTerms()

	/**
	 * Whether the row is owned by one of the caller's groups, as raw SQL.
	 *
	 * THE OWNING GROUP IS AN OWNER, so it sits with the other owner admits
	 * rather than inside the not-private predicate: here the term is ORed over
	 * the whole query, there it would have been ANDed with the schema's rules.
	 * Only this placement matches what ObjectScopeResolverInterface::admitsUnconditionally()
	 * answers on a single read.
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return string|null The predicate, or null when the caller is in no group.
	 */
	private function ownedByMyGroupSql(RbacAccessCall $call): ?string {
		return $this->objectScope()->ownedByMyGroupSql(
			column: new AuthorizationColumn(name: $call->authorizationColumn(), isPostgres: $this->isPostgres()),
			quotedUserGroups: $this->quotedCallerGroups()
		);
	}//end ownedByMyGroupSql()

	/**
	 * The "this caller may reach this row at all" predicate, as raw SQL.
	 *
	 * Both list emitters call this ONE method, and it in turn calls the ONE
	 * builder on {@see ObjectScopeResolverInterface}. The QueryBuilder emitter wraps the
	 * result in `createFunction()` because QueryBuilder cannot express JSON
	 * member extraction portably — writing a second, QueryBuilder-native
	 * implementation would be a second definition of the vocabulary, which is
	 * how the two divergences in the predecessor change happened.
	 *
	 * Decision 180: a draft answers to its owner only, like a private object,
	 * unless the schema lets others see drafts. Added HERE, the one predicate
	 * both list emitters use, so the owner admits beside it keep the owner's
	 * own drafts in view on every path.
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return string The predicate.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
	 */
	private function reachableRowSqlFor(RbacAccessCall $call): string {
		$reachable = $this->objectScope()->notPrivateOrGrantedSql(
			notPrivateSql: $this->notPrivateSqlFor(call: $call),
			granted: new GrantedRows(
				uuidColumn: $call->columnPrefix . '_uuid',
				quotedUuids: $this->quotedGrantedUuids(userId: $call->userId, action: $call->action)
			)
		);

		if ($this->hidesDrafts(call: $call) === true) {
			$reachable = '(' . $reachable . ' AND ' . (new DraftStatusPolicy())->notADraftSql(columnPrefix: $call->columnPrefix) . ')';
		}

		return $reachable;
	}//end reachableRowSqlFor()

	/**
	 * The not-private term of the call's rows, as raw SQL.
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return string The predicate.
	 */
	private function notPrivateSqlFor(RbacAccessCall $call): string {
		return $this->objectScope()->notPrivateSql(
			column: new AuthorizationColumn(name: $call->authorizationColumn(), isPostgres: $this->isPostgres()),
			defaultPrivate: $this->objectScope()->schemaDefaultIsPrivate(schemaAuthorization: $call->authorization)
		);
	}//end notPrivateSqlFor()

	/**
	 * Whether the call keeps drafts to their owner.
	 *
	 * A table that could not be given its `_status` column cannot hold a draft,
	 * and naming the column would fail the query for everyone but an
	 * administrator (REQ-MTH-002: such a table degrades quietly).
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return bool True when the not-a-draft predicate applies.
	 */
	private function hidesDrafts(RbacAccessCall $call): bool {
		return (new DraftStatusPolicy())->draftsVisibleToOthers(schema: $call->schema) === false
			&& $this->statusColumnMissing(tableName: $call->tableName) === false;
	}//end hidesDrafts()

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
	 * @param RbacAccessCall $call The call: caller, action, authorization and column prefix.
	 *
	 * @return string|false|null The predicate, false when the caller is denied
	 *                           outright, or null when nothing is enforced yet.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function denyFilterSqlFor(RbacAccessCall $call): string|false|null {
		if ($this->denyEnforcementMode()->enforces() === false) {
			return null;
		}

		$principals = $this->denyResolver()->principalsFor(userId: $call->userId, userGroups: $call->userGroups);
		if ($this->unconditionallyDenied(call: $call, principals: $principals) === true) {
			return false;
		}

		return $this->conditionalDenyTerm(call: $call, principals: $principals);
	}//end denyFilterSqlFor()

	/**
	 * Whether a denial reaches the caller for the action whatever the row says.
	 *
	 * @param RbacAccessCall     $call       The call.
	 * @param array<int, string> $principals The caller's deny principals.
	 *
	 * @return bool True when the list returns nothing.
	 */
	private function unconditionallyDenied(RbacAccessCall $call, array $principals): bool {
		$unconditional = $this->denyResolver()->unconditionalDenial(authorization: $call->authorization, action: $call->action, principals: $principals);
		if ($unconditional === null) {
			return false;
		}

		$this->logger->info(
			message: '[MagicRbacHandler] A deny rule removes this action from the caller; the list returns nothing',
			context: ['file' => __FILE__, 'line' => __LINE__, 'action' => $call->action, 'userId' => $call->userId, 'principal' => $unconditional['principal']]
		);
		return true;
	}//end unconditionallyDenied()

	/**
	 * The row-level deny test ANDed with the negation of every conditional denial.
	 *
	 * @param RbacAccessCall     $call       The call.
	 * @param array<int, string> $principals The caller's deny principals.
	 *
	 * @return string|false The term, or false when a conditional denial will not compile.
	 */
	private function conditionalDenyTerm(RbacAccessCall $call, array $principals): string|false {
		$rowTest = $this->denyResolver()->notDeniedRowSql(
			columnName: $call->authorizationColumn(),
			action: $call->action,
			isPostgres: $this->isPostgres(),
			principals: $principals
		);

		$negated = $this->negatedConditionalDenials(call: $call, principals: $principals);
		if ($negated === false) {
			return false;
		}

		return (string)RbacMatchSql::joined(terms: [$rowTest, ...$negated], glue: ' AND ');
	}//end conditionalDenyTerm()

	/**
	 * The negation of every conditional denial that reaches the caller.
	 *
	 * @param RbacAccessCall     $call       The call.
	 * @param array<int, string> $principals The caller's deny principals.
	 *
	 * @return array<int, string>|false The negated predicates, or false when one will not compile.
	 */
	private function negatedConditionalDenials(RbacAccessCall $call, array $principals): array|false {
		$columns = $call->columns();
		$denials = $this->denyResolver()->conditionalDenials(authorization: $call->authorization, action: $call->action, principals: $principals);
		$negated = [];
		foreach ($denials as $denial) {
			$compiled = $this->denialPredicateSql(rule: $denial['rule'], columns: $columns);
			if ($compiled === null) {
				$this->logUncompilableDenial(call: $call, principal: $denial['principal']);
				return false;
			}

			$negated[] = "(NOT ({$compiled}))";
		}

		return $negated;
	}//end negatedConditionalDenials()

	/**
	 * Log a conditional denial that could not be compiled into SQL.
	 *
	 * @param RbacAccessCall $call      The call.
	 * @param mixed          $principal The principal the denial names.
	 *
	 * @return void
	 */
	private function logUncompilableDenial(RbacAccessCall $call, mixed $principal): void {
		$this->logger->warning(
			message: '[MagicRbacHandler] A conditional deny could not be compiled into SQL; denying the whole list (fail-closed)',
			context: ['file' => __FILE__, 'line' => __LINE__, 'action' => $call->action, 'userId' => $call->userId, 'principal' => $principal]
		);
	}//end logUncompilableDenial()

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
		if ($this->denialCompiles(rule: $rule, columns: $columns) === false) {
			return null;
		}

		return $this->matchSql()->matchConditions(block: $rule['match'], columnPrefix: $columns->prefix);
	}//end denialPredicateSql()

	/**
	 * Whether a conditional denial can be compiled: a rule with a match block
	 * on metadata records carry and on columns the table has.
	 *
	 * @param mixed       $rule    The denial rule.
	 * @param RuleColumns $columns The columns of the table.
	 *
	 * @return bool True when it compiles.
	 */
	private function denialCompiles(mixed $rule, RuleColumns $columns): bool {
		return $this->isMatchRule(rule: $rule) === true
			&& $this->conditionMatcher->namesUnknownMetadata(match: $rule['match']) === false
			&& $columns->admits(match: $rule['match']) === true;
	}//end denialCompiles()

	/**
	 * Whether a rule carries a match block.
	 *
	 * @param mixed $rule The rule as written.
	 *
	 * @return bool True for an array rule whose `match` is an array.
	 */
	private function isMatchRule(mixed $rule): bool {
		return is_array($rule) === true && is_array(($rule['match'] ?? null)) === true;
	}//end isMatchRule()

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
		$user = $this->identifyCaller(call: $call);

		// Admin users bypass all RBAC checks, and so does the userless system.
		if ($call->callerIsAdmin() === true || $this->isTrustedSystemCaller(user: $user) === true) {
			return;
		}

		// The checks run in order, and each one that clamps the query ends it.
		if ($this->resolveCallAuthorization(call: $call) === false || $this->applyDenyRules(call: $call) === false) {
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
		$unresolvable = $this->unresolvableAuthorization(call: $call);
		if ($unresolvable === null) {
			return true;
		}

		$this->logUnresolvable(call: $call, error: $unresolvable);
		$call->denyAll();
		return false;
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
		$notPrivate = $qb->createFunction($this->reachableRowSqlFor(call: $call));
		$ownerAdmits = $this->ownerAdmits(call: $call);

		if (empty($call->authorization) === true) {
			$this->logger->debug(
				message: '[MagicRbacHandler] No authorization configured, schema is open to non-private rows',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			$qb->andWhere($qb->expr()->orX(...$ownerAdmits, ...[$notPrivate]));
			return;
		}

		$this->applyAnyOf(call: $call, conditions: array_merge($ownerAdmits, $this->ruleConditions(call: $call, notPrivate: $notPrivate)));
	}//end applyAccessConditions()

	/**
	 * Grant access when ANY condition matches; deny outright when there is none.
	 *
	 * @param RbacFilterCall    $call       The call.
	 * @param array<int, mixed> $conditions The owner admits and the rule conditions.
	 *
	 * @return void
	 */
	private function applyAnyOf(RbacFilterCall $call, array $conditions): void {
		if ($conditions === []) {
			$this->logger->debug(
				message: '[MagicRbacHandler] No access conditions met, denying all',
				context: ['file' => __FILE__, 'line' => __LINE__, 'userId' => $call->userId, 'action' => $call->action]
			);
			$call->denyAll();
			return;
		}

		$call->qb->andWhere($call->qb->expr()->orX(...$conditions));
	}//end applyAnyOf()

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
		$denyTerm = $this->denyFilterSqlFor(call: $call);
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
		$ownerAdmits = $this->ownerAdmitTerms(
			call: $call,
			ownerTerm: fn (string $ownerId): mixed => $qb->expr()->eq('t._owner', $qb->createNamedParameter($ownerId))
		);

		$ownedByMyGroup = $this->ownedByMyGroupSql(call: $call);
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
		$rules = $this->actionRules(call: $call);
		$caller = ($this->ruleCaller(call: $call) + ['qb' => $call->qb]);

		$conditions = [];
		foreach ($rules as $rule) {
			$ruleCondition = $this->processAuthorizationRule(rule: $rule, caller: $caller);
			if ($ruleCondition === true) {
				return [...$conditions, $notPrivate];
			}

			if ($ruleCondition !== null && $ruleCondition !== false) {
				$conditions[] = $call->qb->expr()->andX($notPrivate, $ruleCondition);
			}
		}

		return $conditions;
	}//end ruleConditions()

	/**
	 * The rules the authorization block lists for the call's action.
	 *
	 * @param RbacFilterCall $call The call.
	 *
	 * @return mixed The rules as written; an empty list when the action is not configured.
	 */
	private function actionRules(RbacFilterCall $call): mixed {
		$rules = ($call->authorization[$call->action] ?? []);
		if (empty($rules) === true) {
			$this->logger->debug(
				message: '[MagicRbacHandler] Action not configured on a non-empty authorization block — failing closed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'action' => $call->action]
			);
		}

		return $rules;
	}//end actionRules()

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
			$fallback = $this->inheritFromPublicFallback();
			$this->logger->error(
				message: '[MagicRbacHandler] inheritFromPublic resolution failed; falling back to tenant default',
				context: ['file' => __FILE__, 'line' => __LINE__, 'default' => $fallback, 'error' => $e->getMessage()]
			);
			return $fallback;
		}
	}//end authenticatedInheritsPublic()

	/**
	 * The tenant posture for public inheritance, when the schema's own answer failed.
	 *
	 * Fails to the configured tenant posture rather than a hard-coded grant, so
	 * a cluster-wide lock-down (rbac.inherit_from_public_default = false) is not
	 * silently softened on this code path. Symmetric with
	 * PermissionHandlerInterface::resolveInheritFromPublic()'s own fail-safe.
	 *
	 * @return bool Whether authenticated users inherit `public` rights.
	 */
	private function inheritFromPublicFallback(): bool {
		try {
			return $this->container->get(PermissionHandlerInterface::class)->inheritFromPublicTenantDefault();
		} catch (\Throwable $inner) {
			return $this->appConfig->getValueBool(app: 'openregister', key: 'rbac.inherit_from_public_default', default: true);
		}
	}//end inheritFromPublicFallback()

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
		$compiler = $this->queryMatch(qb: $qb);
		$columns = new RuleColumns(schema: $schema);
		$branches = [];
		foreach (($this->crossOrganisationMatches(schema: $schema) ?? []) as $block) {
			$branches[] = $compiler->crossOrganisationBranch(columns: $columns, block: $block);
		}

		$branches = array_values(array_filter($branches, static fn (mixed $branch): bool => $branch !== null));
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
		$columns = new RuleColumns(schema: $schema);
		$branches = [];
		foreach (($this->crossOrganisationMatches(schema: $schema) ?? []) as $block) {
			$branches[] = $this->crossOrganisationBranchSql(columns: $columns, block: $block);
		}

		$branches = array_filter($branches, static fn (?string $branch): bool => $branch !== null);
		if ($branches === []) {
			return null;
		}

		return '(' . implode(' OR ', $branches) . ')';
	}//end getCrossOrganisationBranchSql()

	/**
	 * One cross-organisation branch as raw SQL.
	 *
	 * @param RuleColumns          $columns The columns of the schema's table.
	 * @param array<string, mixed> $block   The rule's match block.
	 *
	 * @return string|null The predicate; impossible for a key that names no column, null when nothing compiles.
	 */
	private function crossOrganisationBranchSql(RuleColumns $columns, array $block): ?string {
		if ($columns->admits(match: $block) === false) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		return $this->matchSql()->matchConditions(block: $block);
	}//end crossOrganisationBranchSql()

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
		$held = [];
		foreach ($this->ownReadRules(schema: $schema) as $rule) {
			if ($this->holdsCrossOrganisationRule(rule: $rule, caller: $caller) === true) {
				$held[] = ($rule['match'] ?? []);
			}
		}

		// A held marked rule without a match reads every organisation.
		if (in_array([], $held, true) === true) {
			return null;
		}

		return array_values(array_filter($held, static fn (mixed $block): bool => is_array($block)));
	}//end crossOrganisationMatches()

	/**
	 * Whether a read rule is marked cross-organisation and the caller holds it.
	 *
	 * @param mixed                $rule   The rule as written.
	 * @param array<string, mixed> $caller The caller.
	 *
	 * @return bool True when it lifts the organisation filter for its match.
	 */
	private function holdsCrossOrganisationRule(mixed $rule, array $caller): bool {
		return is_array($rule) === true
			&& (new ReadRuleTenancy())->isMarked(rule: $rule) === true
			&& $this->callerHoldsConditionalRule(rule: $rule, caller: $caller) === true;
	}//end holdsCrossOrganisationRule()

	/**
	 * The resolved read rules of a schema that has an authorization block of its own.
	 *
	 * @param Schema $schema The schema being read.
	 *
	 * @return array<int, mixed> The rules; none for a schema without its own block or an unresolvable one.
	 */
	private function ownReadRules(Schema $schema): array {
		if (empty(RbacCollaborators::withoutMcpScope(authorization: $schema->getAuthorization())) === true) {
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
	 * @param mixed $rule   Authorization rule (string or array)
	 * @param array $caller The caller: groups, userId, inheritFromPublic, the columns a `match` key may name, and the query.
	 *
	 * @return mixed True if unconditional access, SQL expression for conditional, null/false if no access
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function processAuthorizationRule(mixed $rule, array $caller): mixed {
		// Simple rule: just a group name string.
		if (is_string($rule) === true) {
			return $this->processSimpleRule(rule: $rule, caller: $caller);
		}

		// Conditional rule: object with 'group' (or a 'user' override) and optional 'match'.
		if ($this->isConditionalRule(rule: $rule) === true) {
			return $this->processConditionalRule(rule: $rule, caller: $caller);
		}

		$this->logger->warning(
			message: '[MagicRbacHandler] Invalid authorization rule format',
			context: ['file' => __FILE__, 'line' => __LINE__, 'rule' => $rule]
		);
		return null;
	}//end processAuthorizationRule()

	/**
	 * Whether a rule is a conditional one: an object naming a group or a user.
	 *
	 * @param mixed $rule The rule as written.
	 *
	 * @return bool True for a conditional rule.
	 */
	private function isConditionalRule(mixed $rule): bool {
		return is_array($rule) === true && (isset($rule['group']) === true || isset($rule['user']) === true);
	}//end isConditionalRule()

	/**
	 * Process a simple (unconditional) authorization rule
	 *
	 * `public` grants anonymous users always, authenticated users only when
	 * public inheritance is on; `authenticated` grants any signed-in user. A
	 * bare `user:<uid>` rule grants that one user (delegation, rbac-zaaktype):
	 * additive, never widening group access, and anonymous never qualifies.
	 *
	 * @param string $rule   Group name
	 * @param array  $caller The caller: groups, userId, inheritFromPublic.
	 *
	 * @return bool True if user has access, false otherwise
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function processSimpleRule(string $rule, array $caller): bool {
		if ($rule === 'public') {
			return $this->qualifiesForPublic(userId: $caller['userId'], inheritFromPublic: $caller['inheritFromPublic']);
		}

		if ($rule === 'authenticated') {
			return $caller['userId'] !== null;
		}

		return $this->matchesUserOverride(rule: $rule, userId: $caller['userId']) === true
			|| in_array($rule, $caller['groups'], true) === true;
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
		return $userId !== null && $userId !== '' && $rule === 'user:' . $userId;
	}//end matchesUserOverride()

	/**
	 * Process a conditional authorization rule
	 *
	 * @param array $rule   Rule with 'group' and optional 'match'
	 * @param array $caller The caller: groups, userId, inheritFromPublic, the columns a `match` key may name, and the query.
	 *
	 * @return mixed True if unconditional access, SQL expression for conditional, false if no access
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function processConditionalRule(array $rule, array $caller): mixed {
		$held = $this->heldRuleVerdict(rule: $rule, caller: $caller);
		if ($held !== null) {
			return $held;
		}

		$block = $rule['match'];

		// A key that names no column of this table admits no row.
		$compiler = $this->queryMatch(qb: $caller['qb']);
		if ($caller['columns']->admits(match: $block) === false) {
			return $compiler->impossible();
		}

		return $compiler->matchConditions(block: $block);
	}//end processConditionalRule()

	/**
	 * The verdict a conditional rule gives before its match is compiled.
	 *
	 * @param array $rule   Rule with 'group' (or 'user') and optional 'match'.
	 * @param array $caller The caller.
	 *
	 * @return bool|null False when the caller does not hold the rule, true when
	 *                   it holds without conditions, null when the rule's
	 *                   `match` (of any type) is left to compile.
	 */
	private function heldRuleVerdict(array $rule, array $caller): ?bool {
		// A caller outside the rule's group or named user is not given it.
		if ($this->callerQualifiesForRule(rule: $rule, caller: $caller) === false) {
			return false;
		}

		// No match conditions: unconditional access via this rule.
		$match = ($rule['match'] ?? null);
		if ($match === null || empty($match) === true) {
			return true;
		}

		return null;
	}//end heldRuleVerdict()

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
		if (isset($rule['user']) === true && is_string($rule['user']) === true) {
			// User-level override (delegation): { "user": "<uid>", "match": {...} }.
			return $this->matchesUserOverride(rule: 'user:' . $rule['user'], userId: $caller['userId']);
		}

		return $this->groupQualifies(group: ($rule['group'] ?? null), caller: $caller);
	}//end callerQualifiesForRule()

	/**
	 * Whether a conditional rule's group admits the caller.
	 *
	 * @param mixed $group  The rule's group, or null.
	 * @param array $caller The caller.
	 *
	 * @return bool True when it does.
	 */
	private function groupQualifies(mixed $group, array $caller): bool {
		if ($group === 'public') {
			return $this->qualifiesForPublic(userId: $caller['userId'], inheritFromPublic: $caller['inheritFromPublic']);
		}

		if ($group === 'authenticated' && $caller['userId'] !== null) {
			return true;
		}

		return $group !== null && in_array($group, $caller['groups'], true) === true;
	}//end groupQualifies()

	/**
	 * Check if a user has permission to perform an action on a schema
	 *
	 * This is a non-query version of the RBAC check for use in validation.
	 * Note: This method checks if user has ANY possible access to the schema.
	 * For conditional rules with match criteria, this returns true if the user
	 * qualifies for the group AND (when object data is supplied) ConditionMatcher
	 * confirms the object satisfies the match clause.
	 *
	 * The row's own `_authorization` and uuid are not in play here; a check on
	 * a stored row goes through {@see hasRowPermission()}, which reads them off
	 * the row.
	 *
	 * @param RbacPermissionAsk $ask The schema, the action, and optionally the object owner and data
	 *
	 * @return bool True if user has permission
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function hasPermission(RbacPermissionAsk $ask): bool {
		$check = $this->permissionCheckFor(schema: $ask->schema, action: $ask->action)
			->onObject(objectData: $ask->objectData, objectAuthorization: null)
			->identifiedBy(objectUuid: null);

		return $this->verdictOf(check: $check, objectOwner: $ask->objectOwner);
	}//end hasPermission()

	/**
	 * Check if a user has permission to perform an action on one stored row.
	 *
	 * The row supplies every object fact of the check: its owner, its match
	 * envelope, its own `_authorization` (carrying scope and deny) and its uuid
	 * (for per-object grants). Only the ask's schema and action are read; an
	 * owner or data on the ask never stands in for the row's.
	 *
	 * @param RbacPermissionAsk $ask The schema and the action
	 * @param ObjectEntity      $row The stored row
	 *
	 * @return bool True if user has permission
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function hasRowPermission(RbacPermissionAsk $ask, ObjectEntity $row): bool {
		$check = $this->permissionCheckFor(schema: $ask->schema, action: $ask->action)
			->onObject(objectData: $row->getMatchEnvelope(), objectAuthorization: $row->getAuthorization())
			->identifiedBy(objectUuid: $row->getUuid());

		return $this->verdictOf(check: $check, objectOwner: $row->getOwner());
	}//end hasRowPermission()

	/**
	 * The verdict of one prepared permission check.
	 *
	 * @param RbacPermissionCheck $check       The check, with its caller and object set.
	 * @param string|null         $objectOwner The object owner, for the ownership check.
	 *
	 * @return bool True if user has permission
	 */
	private function verdictOf(RbacPermissionCheck $check, ?string $objectOwner): bool {
		// Admin users have all permissions.
		if ($check->callerIsAdmin() === true) {
			return true;
		}

		// The cascaded block, resolved ONCE for the deny pass, the scope and the
		// rule chain, with one fail-closed path.
		if ($this->cascadeResolves(check: $check) === false) {
			return false;
		}

		return $this->objectVerdict(check: $check, objectOwner: $objectOwner);
	}//end verdictOf()

	/**
	 * Resolve the cascaded authorization block of a permission check.
	 *
	 * @param RbacPermissionCheck $check The check; its cascaded block is set when it resolves.
	 *
	 * @return bool False when the block is unresolvable, which refuses (fail-closed).
	 */
	private function cascadeResolves(RbacPermissionCheck $check): bool {
		try {
			$check->cascaded = $this->resolveSchemaAuthorization(schema: $check->schema);
			return true;
		} catch (AuthorizationUnresolvableException $e) {
			return false;
		}
	}//end cascadeResolves()

	/**
	 * The verdict for a non-admin caller once the cascaded block is known.
	 *
	 * The DENY pass runs BEFORE the owner bypass. A deny removes the verb inside
	 * its scope and nothing puts it back — and the object-level deny an author
	 * is most likely to write is precisely one about a row somebody owns.
	 * Administrators returned before and stay exempt, for the reason
	 * {@see \OCA\OpenRegister\Service\Object\PermissionHandlerInterface::denialFor()}
	 * gives: an access model that can deny its own administration away is
	 * recoverable only from the database. Then the owner, then the private
	 * scope, then the schema's rules.
	 *
	 * @param RbacPermissionCheck $check       The check.
	 * @param string|null         $objectOwner The object's owner, if any.
	 *
	 * @return bool True when the caller may perform the action.
	 */
	private function objectVerdict(RbacPermissionCheck $check, ?string $objectOwner): bool {
		if ($this->deniesHere(check: $check) === true) {
			return false;
		}

		if ($check->callerOwns(objectOwner: $objectOwner) === true) {
			return true;
		}

		return $this->privateObjectRefuses(check: $check) === false && $this->rulesGrant(check: $check) === true;
	}//end objectVerdict()

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
		if ($check->objectData === null
			|| $this->objectScope()->isPrivate(objectAuthorization: $check->objectAuthorization, schemaAuthorization: $check->cascaded) === false
		) {
			return false;
		}

		return $this->objectGrants()?->isGranted(lookup: new GrantLookup(userId: $check->userId, objectUuid: $check->objectUuid), action: $check->action) !== true;
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

		return $this->anyRuleGrants(check: $check, rules: $rules);
	}//end rulesGrant()

	/**
	 * Whether any of the rules grants the action.
	 *
	 * @param RbacPermissionCheck $check The check.
	 * @param mixed               $rules The rules for the action.
	 *
	 * @return bool True when one does.
	 */
	private function anyRuleGrants(RbacPermissionCheck $check, mixed $rules): bool {
		foreach ($rules as $rule) {
			if ($this->ruleGrants(check: $check, rule: $rule) === true) {
				return true;
			}
		}

		return false;
	}//end anyRuleGrants()

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

		if ($this->isConditionalRule(rule: $rule) === false || $this->qualifiesForConditionalRule(check: $check, rule: $rule) === false) {
			return false;
		}

		return $this->conditionalRuleMatches(check: $check, rule: $rule);
	}//end ruleGrants()

	/**
	 * Whether a conditional rule the caller holds matches the object.
	 *
	 * Without match conditions, or without an object to test, the rule grants.
	 *
	 * @param RbacPermissionCheck $check The check.
	 * @param array               $rule  The rule.
	 *
	 * @return bool True when it grants.
	 */
	private function conditionalRuleMatches(RbacPermissionCheck $check, array $rule): bool {
		$block = ($rule['match'] ?? null);
		if ($block === null || empty($block) === true || $check->objectData === null) {
			return true;
		}

		return $this->conditionMatcher->objectMatchesConditions(object: $check->objectData, match: $block) === true;
	}//end conditionalRuleMatches()

	/**
	 * Whether a string rule names the caller.
	 *
	 * @param RbacPermissionCheck $check The permission check.
	 * @param string              $rule  The rule.
	 *
	 * @return bool True when the caller qualifies.
	 */
	private function stringRuleGrants(RbacPermissionCheck $check, string $rule): bool {
		return $this->pseudoGroupGrants(check: $check, principal: $rule) === true
			|| $this->matchesUserOverride(rule: $rule, userId: $check->userId) === true
			|| in_array($rule, $check->userGroups, true) === true;
	}//end stringRuleGrants()

	/**
	 * Whether a rule's `public` or `authenticated` principal admits the caller.
	 *
	 * @param RbacPermissionCheck $check     The check.
	 * @param mixed               $principal The rule's principal.
	 *
	 * @return bool True when it does.
	 */
	private function pseudoGroupGrants(RbacPermissionCheck $check, mixed $principal): bool {
		return ($principal === 'public' && $this->qualifiesForPublic(userId: $check->userId, inheritFromPublic: $check->inheritFromPublic) === true)
			|| ($principal === 'authenticated' && $check->userId !== null);
	}//end pseudoGroupGrants()

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
		return $this->pseudoGroupGrants(check: $check, principal: $group) === true
			|| ($group !== null && in_array($group, $check->userGroups, true) === true);
	}//end qualifiesForConditionalRule()

	/**
	 * Whether a deny rule removes this verb from this caller, for this row.
	 *
	 * The row-level PHP half of the deny, and the third of the four enforcement
	 * paths. It reads the SAME cascaded block the emitters read and the SAME
	 * object block the row carries, through the same {@see DenyResolverInterface}, so a
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

		$block = $this->mergedDenyBlock(check: $check);
		if ($block === null) {
			return false;
		}

		return $this->denyBlockSelects(check: $check, block: $block);
	}//end deniesHere()

	/**
	 * The deny block of the schema cascade merged with the object's own.
	 *
	 * @param RbacPermissionCheck $check The check.
	 *
	 * @return array|null The authorization-shaped block, or null when nothing is denied.
	 */
	private function mergedDenyBlock(RbacPermissionCheck $check): ?array {
		$resolver = $this->denyResolver();
		$merged = $resolver->mergeDeny(
			baseline: $resolver->denyBlock(authorization: $check->cascaded),
			override: $resolver->denyBlock(authorization: $check->objectAuthorization)
		);
		if ($merged === []) {
			return null;
		}

		return [DenyResolverInterface::DENY_KEY => $merged];
	}//end mergedDenyBlock()

	/**
	 * Whether a deny block removes the action from the caller on this object.
	 *
	 * @param RbacPermissionCheck $check The check.
	 * @param array               $block The merged deny block.
	 *
	 * @return bool True when a denial applies, unconditionally or by matching the object.
	 */
	private function denyBlockSelects(RbacPermissionCheck $check, array $block): bool {
		$resolver = $this->denyResolver();
		$principals = $resolver->principalsFor(userId: $check->userId, userGroups: $check->userGroups);
		if ($resolver->unconditionalDenial(authorization: $block, action: $check->action, principals: $principals) !== null) {
			return true;
		}

		if ($check->objectData === null) {
			return false;
		}

		return $this->anyDenialSelects(
			check: $check,
			denials: $resolver->conditionalDenials(authorization: $block, action: $check->action, principals: $principals)
		);
	}//end denyBlockSelects()

	/**
	 * Whether any conditional denial selects the object.
	 *
	 * @param RbacPermissionCheck $check   The check, with object data.
	 * @param iterable            $denials The conditional denials that reach the caller.
	 *
	 * @return bool True when one does.
	 */
	private function anyDenialSelects(RbacPermissionCheck $check, iterable $denials): bool {
		foreach ($denials as $denial) {
			if ($this->denialSelectsObject(check: $check, rule: $denial['rule']) === true) {
				return true;
			}
		}

		return false;
	}//end anyDenialSelects()

	/**
	 * Whether one conditional denial selects the object.
	 *
	 * @param RbacPermissionCheck $check The check, with object data.
	 * @param mixed               $rule  The denial rule.
	 *
	 * @return bool True when it selects; a rule without a match block selects nothing.
	 */
	private function denialSelectsObject(RbacPermissionCheck $check, mixed $rule): bool {
		return $this->isMatchRule(rule: $rule) === true
			&& $this->denialSelects(block: $rule['match'], objectData: (array)$check->objectData) === true;
	}//end denialSelectsObject()

	/**
	 * Whether a denial's clause selects the row: it matches, or it names metadata no record carries.
	 *
	 * @param array<string, mixed> $block      The clause.
	 * @param array<mixed>         $objectData The row as a rule reads it.
	 *
	 * @return bool True when the denial applies.
	 */
	private function denialSelects(array $block, array $objectData): bool {
		return $this->conditionMatcher->namesUnknownMetadata(match: $block, object: $objectData) === true
			|| $this->conditionMatcher->objectMatchesConditions(object: $objectData, match: $block) === true;
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
	 * @param Schema      $schema The schema of the rows the subquery reads.
	 * @param AliasedRows $rows   The alias the rows carry, the CRUD action and their bare magic table.
	 *
	 * @return string A predicate, always non-empty, safe to AND into a WHERE.
	 *
	 * @throws InvalidArgumentException When the alias is not a plain identifier.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function buildRbacPredicateForAlias(Schema $schema, AliasedRows $rows): string {
		$this->assertPredicateAlias(alias: $rows->alias);
		$result = $this->buildRbacConditionsSql(schema: $schema, action: $rows->action, columnPrefix: $rows->alias . '.', tableName: $rows->tableName);
		if (($result['bypass'] ?? false) === true) {
			return 'TRUE';
		}

		// Deny all is said out loud, because an omitted predicate reads as no
		// restriction and this is the opposite of that.
		return $this->anyCondition(conditions: ($result['conditions'] ?? []), none: 'FALSE');
	}//end buildRbacPredicateForAlias()

	/**
	 * Refuse an alias that is not a plain SQL identifier.
	 *
	 * @param string $alias The alias.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the alias is not a plain identifier.
	 */
	private function assertPredicateAlias(string $alias): void {
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $alias) !== 1) {
			throw new InvalidArgumentException(
				sprintf('\'%s\' is not a table alias an access predicate may be built for.', $alias)
			);
		}
	}//end assertPredicateAlias()

	/**
	 * Raw SQL conditions ORed in parentheses, or a stand-in when there are none.
	 *
	 * @param array<int, string> $conditions The conditions.
	 * @param string             $none       What denies all in the caller's dialect.
	 *
	 * @return string The predicate.
	 */
	private function anyCondition(array $conditions, string $none): string {
		if ($conditions === []) {
			return $none;
		}

		return '(' . implode(' OR ', $conditions) . ')';
	}//end anyCondition()

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

		return $this->anyCondition(conditions: ($result['conditions'] ?? []), none: '1 = 0');
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
		$call = new RbacAccessCall(schema: $schema, columnPrefix: $columnPrefix);
		$call->action = $action;
		$call->tableName = $tableName;
		return $this->accessConditions(call: $call);
	}//end buildRbacConditionsSql()

	/**
	 * The bypass flag and the SQL conditions of one access call; fails closed.
	 *
	 * @param RbacAccessCall $call The schema, action, column prefix and table of the read.
	 *
	 * @return array{bypass: bool, conditions: array<int, string>} The bypass flag and conditions.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function accessConditions(RbacAccessCall $call): array {
		if ($this->sqlFilterBypassed(call: $call) === true) {
			return ['bypass' => true, 'conditions' => []];
		}

		// Fail-closed: an unresolvable authorization yields the deny-all result
		// (`bypass => false` with no conditions) rather than the open bypass.
		$unresolvable = $this->unresolvableAuthorization(call: $call);
		if ($unresolvable !== null) {
			$this->logUnresolvable(call: $call, error: $unresolvable);
			return ['bypass' => false, 'conditions' => []];
		}

		return ['bypass' => false, 'conditions' => $this->conditionsSql(call: $call)];
	}//end accessConditions()

	/**
	 * Identify the caller, and say whether the raw-SQL filter lets it through unfiltered.
	 *
	 * Admin users bypass all RBAC checks, and so does a userless caller inside
	 * ObjectService::runAsSystem(). (This raw-SQL path has never carried the
	 * command-line arm of isTrustedSystemCaller(); that stays as it was.)
	 *
	 * @param RbacAccessCall $call The call; its caller is filled in.
	 *
	 * @return bool True when no filtering applies.
	 */
	private function sqlFilterBypassed(RbacAccessCall $call): bool {
		$user = $this->identifyCaller(call: $call);
		return $call->callerIsAdmin() === true
			|| ($this->isTrustedSystemCaller(user: $user) === true && SystemOperationContext::isActive() === true);
	}//end sqlFilterBypassed()

	/**
	 * The raw-SQL conditions for a non-admin caller whose authorization resolved.
	 *
	 * The deny term, the "not private" row predicate and the owner admits are
	 * the same pieces the QueryBuilder emitter uses. Without authorization the
	 * schema is open to all — but an individual OBJECT may still declare itself
	 * private, so this is no unconditional bypass. A non-empty block that does
	 * not list the action grants nothing past the owner conditions (fail-closed,
	 * consistent with applyRbacFilters and PermissionHandlerInterface).
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return array<int, string> Conditions to OR together; empty means deny all.
	 */
	private function conditionsSql(RbacAccessCall $call): array {
		$denyTerm = $this->denyFilterSqlFor(call: $call);
		if ($denyTerm === false) {
			return [];
		}

		$notPrivate = $this->reachableRowSqlFor(call: $call);
		$ownerAdmits = $this->ownerAdmitConditionsSql(call: $call);
		if (empty($call->authorization) === true) {
			return $this->withDenyTerm(conditions: array_merge($ownerAdmits, [$notPrivate]), denyTerm: $denyTerm);
		}

		$conditions = array_merge($ownerAdmits, $this->ruleConditionsSql(call: $call, notPrivate: $notPrivate));
		return $this->withDenyTerm(conditions: $conditions, denyTerm: $denyTerm);
	}//end conditionsSql()

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
	 * Turn the action's rules into raw-SQL conditions.
	 *
	 * Unconditional access via a rule reaches every NON-private row; a private
	 * one still answers only to its owner, and no later rule can widen past
	 * that. A conditional rule only ever decides a NON-private row: AND-ing it
	 * with the not-private predicate is what suppresses the schema's rules for
	 * a private one.
	 *
	 * @param RbacAccessCall $call       The call.
	 * @param string         $notPrivate The reachable-row predicate to AND each rule with.
	 *
	 * @return string[] SQL conditions to OR together.
	 */
	private function ruleConditionsSql(RbacAccessCall $call, string $notPrivate): array {
		$rules = $this->actionRulesSql(call: $call);
		$caller = $this->ruleCaller(call: $call);

		$conditions = [];
		foreach ($rules as $rule) {
			$ruleResult = $this->processAuthorizationRuleSql(rule: $rule, caller: $caller);
			if ($ruleResult === true) {
				return [...$conditions, $notPrivate];
			}

			if (is_string($ruleResult) === true) {
				$conditions[] = "({$notPrivate} AND {$ruleResult})";
			}
		}

		return $conditions;
	}//end ruleConditionsSql()

	/**
	 * The rules the authorization block lists for the call's action.
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return array The rules; an empty list when the action is not configured.
	 */
	private function actionRulesSql(RbacAccessCall $call): array {
		return ($call->authorization[$call->action] ?? []);
	}//end actionRulesSql()

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
	private function processAuthorizationRuleSql(mixed $rule, array $caller): mixed {
		// Simple rule: just a group name string.
		if (is_string($rule) === true) {
			return $this->processSimpleRule(rule: $rule, caller: $caller);
		}

		// Conditional rule: object with 'group' (or a 'user' override) and optional 'match'.
		if ($this->isConditionalRule(rule: $rule) === true) {
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
	private function processConditionalRuleSql(array $rule, array $caller): mixed {
		$held = $this->heldRuleVerdict(rule: $rule, caller: $caller);
		if ($held !== null) {
			return $held;
		}

		$block = $rule['match'];

		// A key that names no column of this table admits no row.
		if ($caller['columns']->admits(match: $block) === false) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		return $this->matchSql()->matchConditions(block: $block, columnPrefix: $caller['columns']->prefix);
	}//end processConditionalRuleSql()

	/**
	 * Whether the connected database is PostgreSQL.
	 *
	 * @return bool True when the platform is PostgreSQL; MariaDB syntax is the fallback.
	 */
	private function isPostgres(): bool {
		return $this->matchSql()->dialect->isPostgres();
	}//end isPostgres()

	/**
	 * Quote a value for safe use in raw SQL.
	 *
	 * @param mixed $value Value to quote.
	 *
	 * @return string Quoted value safe for SQL.
	 */
	private function quoteValue(mixed $value): string {
		return $this->matchSql()->dialect->quote(value: $value);
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
	 * {@see \OCA\OpenRegister\Service\Rbac\ObjectScopeResolverInterface} never handles an
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
		$call = new RbacAccessCall(schema: $schema);
		$call->action = $action;
		$this->identifyCaller(call: $call);
		if ($call->callerIsAdmin() === true) {
			return true;
		}

		if ($this->unresolvableAuthorization(call: $call) !== null) {
			return false;
		}

		return $this->authorizationAdmitsCaller(call: $call);
	}//end callerQualifiesForAction()

	/**
	 * Whether the call's resolved authorization admits the caller through some rule.
	 *
	 * @param RbacAccessCall $call The call.
	 *
	 * @return bool True for an open schema, or when a rule for the action admits the caller.
	 */
	private function authorizationAdmitsCaller(RbacAccessCall $call): bool {
		if (empty($call->authorization) === true) {
			return true;
		}

		$rules = ($call->authorization[$call->action] ?? []);
		if (is_array($rules) === false) {
			return false;
		}

		return $this->anyRuleAdmits(caller: $this->ruleCaller(call: $call), rules: $rules);
	}//end authorizationAdmitsCaller()

	/**
	 * Whether any rule admits the caller on some row.
	 *
	 * @param array $caller The caller.
	 * @param array $rules  The rules for the action.
	 *
	 * @return bool True when a rule does.
	 */
	private function anyRuleAdmits(array $caller, array $rules): bool {
		foreach ($rules as $rule) {
			if ($this->processAuthorizationRuleSql(rule: $rule, caller: $caller) !== false) {
				return true;
			}
		}

		return false;
	}//end anyRuleAdmits()

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
				readRules: ((RbacCollaborators::withoutMcpScope(authorization: $schema->getAuthorization()) ?? [])[$action] ?? [])
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
		$ownAuthorization = RbacCollaborators::withoutMcpScope(authorization: $schema->getAuthorization());
		if (empty($ownAuthorization) === false) {
			return false;
		}

		// Fail-closed: an unresolvable block admits nothing extra.
		$call = new RbacAccessCall(schema: $schema);
		$call->action = $action;
		if ($this->unresolvableAuthorization(call: $call) !== null) {
			return false;
		}

		return $this->callerQualifiesForBypassRule(call: $call);
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
	 * @param RbacAccessCall $call The call, with its resolved authorization.
	 *
	 * @return bool True when the organisation filter must also admit org-less rows.
	 */
	private function callerQualifiesForBypassRule(RbacAccessCall $call): bool {
		$this->identifyCaller(call: $call);

		// Admin users bypass all RBAC checks anyway.
		if ($call->callerIsAdmin() === true) {
			return true;
		}

		if (empty($call->authorization) === true || empty($call->authorization[$call->action] ?? []) === true) {
			return false;
		}

		return $this->rulesLiftOrganisationFilter(call: $call);
	}//end callerQualifiesForBypassRule()

	/**
	 * Whether the action's rules, as the caller holds them, lift the organisation filter.
	 *
	 * A `public` rule only grants an authenticated user a cross-tenant bypass
	 * when public inheritance is enabled; anonymous users always qualify. That
	 * keeps the bypass decision consistent with applyRbacFilters /
	 * buildRbacConditionsSql / hasPermission, which already gate `public`.
	 *
	 * @param RbacAccessCall $call The call, with rules for its action.
	 *
	 * @return bool True when they do.
	 */
	private function rulesLiftOrganisationFilter(RbacAccessCall $call): bool {
		$caller = $this->ruleCaller(call: $call);
		$verdicts = [];
		foreach ($call->authorization[$call->action] as $rule) {
			$verdicts[] = $this->ruleBypassVerdict(rule: $rule, caller: $caller);
		}

		return $this->verdictsLiftOrganisationFilter(verdicts: $verdicts);
	}//end rulesLiftOrganisationFilter()

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
			return $this->publicRuleVerdict(caller: $caller);
		}

		if (in_array($rule, $caller['groups'], true) === true) {
			return self::RULE_READS_ALL;
		}

		if ($this->processSimpleRule(rule: $rule, caller: $caller) === true) {
			return self::RULE_STAYS_IN_ORGANISATION;
		}

		return self::RULE_NOT_HELD;
	}//end simpleRuleVerdict()

	/**
	 * The verdict of a bare `public` rule: it reads everything, for whoever qualifies.
	 *
	 * @param array $caller The caller.
	 *
	 * @return string One of the RULE_* verdicts.
	 */
	private function publicRuleVerdict(array $caller): string {
		if ($this->qualifiesForPublic(userId: $caller['userId'], inheritFromPublic: $caller['inheritFromPublic']) === true) {
			return self::RULE_READS_ALL;
		}

		return self::RULE_NOT_HELD;
	}//end publicRuleVerdict()

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

		return $this->crossingRuleVerdict(rule: $rule);
	}//end conditionalRuleVerdict()

	/**
	 * The verdict of a held rule that crosses organisations: it does so only
	 * for a real group, not a named user or every signed-in user.
	 *
	 * @param array $rule The rule.
	 *
	 * @return string One of the RULE_* verdicts.
	 */
	private function crossingRuleVerdict(array $rule): string {
		$group = ($rule['group'] ?? null);
		if (isset($rule['user']) === false && is_string($group) === true && $group !== 'authenticated') {
			return self::RULE_CROSSES_ORGANISATIONS;
		}

		return self::RULE_NOT_HELD;
	}//end crossingRuleVerdict()

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
			return RbacCollaborators::withoutMcpScope(authorization: $schema->getAuthorization());
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
