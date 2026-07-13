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
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-55
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-56
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-57
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Event\CustomScopeEvaluatedEvent;
use OCA\OpenRegister\Event\CustomScopeEvaluatingEvent;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Permission evaluation requires per-action and per-role branching
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    RBAC methods are cohesive units; splitting scatters the security policy without reducing it
 * @SuppressWarnings(PHPMD.NPathComplexity)          RBAC rules handle user/group/owner/public/conditional combos - cartesian product drives NPath
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   RBAC needs IUserSession, IUserManager, IGroupManager, ConditionMatcher, Register/Schema mappers
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     All RBAC logic is centralised per ADR-011; splitting would re-scatter the security policy
 */
class PermissionHandler
{

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
    ];

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
    ];

    /**
     * PermissionHandler constructor.
     *
     * @param IUserSession                               $userSession        User session for getting current user.
     * @param IUserManager                               $userManager        User manager for getting user objects.
     * @param IGroupManager                              $groupManager       Group manager for checking user groups.
     * @param SchemaMapper                               $schemaMapper       Mapper for schema operations.
     * @param MagicMapper                                $objectEntityMapper Mapper for object entity operations.
     * @param ConditionMatcher                           $conditionMatcher   Shared PHP-side match evaluator (ADR-011).
     * @param IAppConfig                                 $appConfig          App config for the inheritFromPublic tenant default.
     * @param LoggerInterface                            $logger             Logger for permission auditing.
     * @param ContainerInterface                         $container          Container for lazy loading services.
     * @param \OCP\EventDispatcher\IEventDispatcher|null $eventDispatcher    Optional dispatcher for custom-scope events.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly SchemaMapper $schemaMapper,
        private readonly MagicMapper $objectEntityMapper,
        private readonly ConditionMatcher $conditionMatcher,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
        private readonly ?\OCP\EventDispatcher\IEventDispatcher $eventDispatcher=null
    ) {
    }//end __construct()

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
     * TODO: Implement property-level RBAC checks
     * Properties can have their own authorization arrays that provide fine-grained access control.
     *
     * @param Schema            $schema      The schema to check permissions for.
     * @param string            $action      The CRUD action (create, read, update, delete).
     * @param string|null       $userId      Optional user ID (defaults to current user).
     * @param string|null       $objectOwner Optional object owner for ownership check.
     * @param bool              $_rbac       Whether to apply RBAC checks (default: true).
     * @param ObjectEntity|null $object      Optional object entity for conditional authorization matching.
     *
     * @return bool True if user has permission, false otherwise
     *
     * @throws Exception If user session is invalid or user groups cannot be determined
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) RBAC permission checks require multiple conditional paths
     * @SuppressWarnings(PHPMD.NPathComplexity)      User/group/owner permission combinations create many paths
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC flag follows established API patterns
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function hasPermission(
        Schema $schema,
        string $action,
        ?string $userId=null,
        ?string $objectOwner=null,
        bool $_rbac=true,
        ?ObjectEntity $object=null
    ): bool {
        // If RBAC is disabled, always return true (bypass all permission checks).
        if ($_rbac === false) {
            return true;
        }

        // Explicitly-scoped system operations (config imports at app boot,
        // repair steps, webcron jobs run via ObjectService::runAsSystem())
        // are trusted the same way CLI cron already is: these run without a
        // user session, and the anonymous fail-closed policy (#1955) would
        // otherwise deny the app maintaining its own objects on every boot.
        if (SystemOperationContext::isActive() === true) {
            return true;
        }

        // Build a per-request cache key. The object UUID (when present) is part
        // of the key so conditional rules with `match` clauses are still
        // re-evaluated per object — the cache only deduplicates repeated calls
        // with the exact same inputs within a request lifecycle.
        //
        // SAFETY: when an object is supplied without a UUID (e.g. transient
        // in-memory entity, draft, or unit-test stub), the cache is bypassed
        // entirely. Conditional rules read from $object->getObject(), which can
        // differ between calls for objects that share no stable identity.
        $cacheKey = $this->buildPermissionCacheKey(
            schemaId: $schema->getId(),
            action: $action,
            userId: $userId,
            objectOwner: $objectOwner,
            object: $object,
            schema: $schema
        );
        if ($cacheKey !== null && array_key_exists($cacheKey, $this->permissionCache) === true) {
            return $this->permissionCache[$cacheKey];
        }

        $verdict = $this->evaluatePermission(
            schema: $schema,
            action: $action,
            userId: $userId,
            objectOwner: $objectOwner,
            object: $object
        );

        if ($cacheKey !== null) {
            $this->permissionCache[$cacheKey] = $verdict;
        }

        return $verdict;
    }//end hasPermission()

    /**
     * Build a stable cache key for hasPermission().
     *
     * Returns null when caching is unsafe:
     *  - schema has no ID (unsaved entity);
     *  - an object is supplied without a UUID (transient/in-memory entity whose
     *    data could differ between calls and whose conditional-rule verdict
     *    therefore cannot be reused).
     *
     * @param int|null          $schemaId    Schema ID.
     * @param string            $action      CRUD action.
     * @param string|null       $userId      User ID (null = anonymous).
     * @param string|null       $objectOwner Object owner (null = no owner check).
     * @param ObjectEntity|null $object      Object entity (UUID is the cache scope).
     * @param Schema|null       $schema      Optional schema entity used to break the cache when conditional rules are present.
     *
     * @return string|null Cache key, or null to bypass cache.
     */
    private function buildPermissionCacheKey(
        ?int $schemaId,
        string $action,
        ?string $userId,
        ?string $objectOwner,
        ?ObjectEntity $object,
        ?Schema $schema=null
    ): ?string {
        if ($schemaId === null) {
            return null;
        }

        // SECURITY: when the schema's authorization block contains any
        // `match` rule, the verdict depends on the *current* object data
        // — which may change within a single request via saveObject() /
        // TransitionEngine. Cache reuse keyed on the (stable) object UUID
        // would otherwise serve a pre-mutation verdict to a post-mutation
        // re-check. Drop the cache for schemas with match rules so each
        // call re-evaluates the rule chain against fresh data.
        if ($schema !== null && $this->schemaHasMatchRule(schema: $schema) === true) {
            return null;
        }

        $objectUuid = null;
        if ($object !== null) {
            $objectUuid = $object->getUuid();
            if ($objectUuid === null || $objectUuid === '') {
                // Object supplied but has no stable identity — caching is unsafe.
                return null;
            }
        }

        return sprintf(
            's%d|a%s|u%s|o%s|i%s',
            $schemaId,
            $action,
            $userId ?? '_',
            $objectOwner ?? '_',
            $objectUuid ?? '_'
        );
    }//end buildPermissionCacheKey()

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
    private function schemaHasMatchRule(Schema $schema): bool
    {
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
     * cache without obscuring the evaluation order.
     *
     * @param Schema            $schema      Schema being checked.
     * @param string            $action      CRUD action.
     * @param string|null       $userId      User ID, or null to resolve from session.
     * @param string|null       $objectOwner Object owner UID, for ownership bypass.
     * @param ObjectEntity|null $object      Object entity for conditional matching.
     *
     * @return bool True if the user has permission.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) RBAC permission checks require multiple conditional paths
     * @SuppressWarnings(PHPMD.NPathComplexity)      User/group/owner permission combinations create many paths
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    private function evaluatePermission(
        Schema $schema,
        string $action,
        ?string $userId,
        ?string $objectOwner,
        ?ObjectEntity $object
    ): bool {
        // Resolve object context for conditional authorization matching.
        // $activeOrganisation is resolved by ConditionMatcher itself (via OrganisationService);
        // no per-call lookup is needed here anymore.
        $objectData         = null;
        $objectOrganisation = null;
        if ($object !== null) {
            $objectData         = $object->getObject();
            $objectOrganisation = $object->getOrganisation();
        }

        $authorization = $this->resolveAuthorization(schema: $schema);

        // Get current user if not provided.
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                // Fail-closed object writes for anonymous callers (#1955): a write
                // action (create/update/delete) is denied for an anonymous principal
                // unless the schema explicitly grants the `public` group that action.
                // This scopes the new denial strictly to anonymous principals —
                // authenticated users are unaffected (their default-open behaviour is
                // a separate, broader policy decision). Declared public-submission
                // schemas (public create/update rule) still allow anonymous writes.
                if (in_array(needle: $action, haystack: self::ANONYMOUS_FAIL_CLOSED_WRITE_ACTIONS, strict: true) === true
                    && $this->publicGroupExplicitlyGranted(authorization: $authorization, action: $action) === false
                ) {
                    return false;
                }//end if

                // For unauthenticated requests, check if 'public' group has permission.
                return $this->hasGroupPermission(
                    authorization: $authorization,
                    groupId: 'public',
                    action: $action,
                    userId: null,
                    userGroup: null,
                    objectOwner: $objectOwner,
                    objectData: $objectData,
                    objectOrganisation: $objectOrganisation
                );
            }//end if

            $userId = $user->getUID();
        }//end if

        // Get user object from user ID.
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            // User doesn't exist, treat as public.
            return $this->hasGroupPermission(
                authorization: $authorization,
                groupId: 'public',
                action: $action,
                userId: null,
                userGroup: null,
                objectOwner: $objectOwner,
                objectData: $objectData,
                objectOrganisation: $objectOrganisation
            );
        }

        $userGroups = $this->groupManager->getUserGroupIds($userObj);

        // Check if user is admin (admin group always has all permissions).
        if (in_array('admin', $userGroups) === true) {
            return true;
        }

        // 'authenticated' pseudo-group: any logged-in user qualifies,
        // independent of real group membership (so a user with NO groups still
        // matches). Evaluated here — symmetric with the SQL-layer
        // MagicRbacHandler, which already honours 'authenticated' — so a
        // single-object create/read/update/delete check and a list query agree
        // on schemas that grant an action to `authenticated`.
        if ($this->hasGroupPermission(
                authorization: $authorization,
                groupId: 'authenticated',
                action: $action,
                userId: $userId,
                objectOwner: $objectOwner,
                objectData: $objectData,
                objectOrganisation: $objectOrganisation
            ) === true
        ) {
            return true;
        }

        // Custom action verbs (anything outside the canonical 5) are
        // routed through a listener-driven dispatch so consuming apps
        // can contribute verdicts for verbs they own (e.g. ZGW
        // `besluit_nemen`). Listeners vote via
        // `CustomScopeEvaluatingEvent::allow() / deny()`; the first
        // verdict wins. When no listener votes, fall through to the
        // standard rule chain — most schemas won't have rules for
        // custom verbs, so this typically denies.
        $isCanonical = in_array(needle: $action, haystack: self::CANONICAL_ACTIONS, strict: true);
        if ($isCanonical === false && $this->eventDispatcher !== null) {
            $verdict = $this->dispatchCustomScopeEvaluation(
                schema: $schema,
                action: $action,
                userId: $userId,
                userGroups: $userGroups,
                object: $object
            );
            if ($verdict !== null) {
                return $verdict;
            }
        }

        // User-level override (delegation) check — evaluated independently of
        // group membership so a user with NO groups can still receive a
        // delegated grant. Uses the sentinel group id '' which never matches a
        // real group; only the user-override branch inside hasGroupPermission
        // can grant here. Additive and fail-closed (see userOverrideMatches()).
        if ($this->hasGroupPermission(
                authorization: $authorization,
                groupId: '',
                action: $action,
                userId: $userId,
                objectOwner: $objectOwner,
                objectData: $objectData,
                objectOrganisation: $objectOrganisation
            ) === true
        ) {
            return true;
        }

        // Check schema permissions for each user group.
        foreach ($userGroups as $groupId) {
            if ($this->hasGroupPermission(
                    authorization: $authorization,
                    groupId: $groupId,
                    action: $action,
                    userId: $userId,
                    objectOwner: $objectOwner,
                    objectData: $objectData,
                    objectOrganisation: $objectOrganisation
                ) === true
            ) {
                return true;
            }
        }//end foreach

        // Logged-in users normally also inherit at least the same rights as
        // 'public' users — unless the schema/register/tenant disabled public
        // inheritance for authenticated users (inheritFromPublic = false). The
        // flag only governs this authenticated-inherits-public step; anonymous
        // users are handled earlier and are never affected.
        if ($this->resolveInheritFromPublic(schema: $schema) === true
            && $this->hasGroupPermission(
                authorization: $authorization,
                groupId: 'public',
                action: $action,
                userId: $userId,
                objectOwner: $objectOwner,
                objectData: $objectData,
                objectOrganisation: $objectOrganisation
            ) === true
        ) {
            return true;
        }

        return false;
    }//end evaluatePermission()

    /**
     * Dispatch `CustomScopeEvaluatingEvent` and collect a listener
     * verdict. Returns null when no listener voted so the caller can
     * fall through to the standard rule chain.
     *
     * Always pairs with a `CustomScopeEvaluatedEvent` for telemetry
     * regardless of which path produced the verdict (listener vs
     * standard chain) — that's why this helper does not dispatch the
     * paired telemetry event itself; the caller emits it after the
     * final verdict is known.
     *
     * @param Schema            $schema     Schema being checked.
     * @param string            $action     Custom action verb.
     * @param string|null       $userId     User ID under evaluation.
     * @param string[]          $userGroups User group memberships.
     * @param ObjectEntity|null $object     Optional target object.
     *
     * @return bool|null Listener verdict, or null when no listener voted.
     */
    private function dispatchCustomScopeEvaluation(
        Schema $schema,
        string $action,
        ?string $userId,
        array $userGroups,
        ?ObjectEntity $object
    ): ?bool {
        if ($this->eventDispatcher === null) {
            return null;
        }

        $event = new CustomScopeEvaluatingEvent(
            schema: $schema,
            action: $action,
            userId: $userId,
            userGroups: $userGroups,
            object: $object
        );

        try {
            $this->eventDispatcher->dispatchTyped($event);
        } catch (Exception $e) {
            // SECURITY: fail CLOSED. A listener exception means the app
            // that owns the verdict for this verb is unavailable — for
            // verbs the standard rule chain has no opinion on (the
            // common case for custom verbs like ZGW `besluit_nemen`),
            // falling through to "deny by default" is acceptable, but
            // for verbs where a listener has previously voted ALLOW,
            // returning null lets the standard chain re-decide and
            // potentially open access. Treat the dispatcher exception
            // itself as a deny vote so a crashed listener cannot
            // upgrade-to-allow.
            $this->logger->warning(
                message: '[PermissionHandler] CustomScopeEvaluatingEvent dispatch failed — denying',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'action' => $action,
                    'error'  => $e->getMessage(),
                ]
            );
            return false;
        }//end try

        if ($event->hasVerdict() === false) {
            return null;
        }

        $verdict = $event->getVerdict();
        $this->dispatchCustomScopeEvaluated(
            schema: $schema,
            action: $action,
            userId: $userId,
            verdict: $verdict,
            fromListener: true
        );

        return $verdict;
    }//end dispatchCustomScopeEvaluation()

    /**
     * Dispatch the paired telemetry event. Best-effort; listener
     * exceptions are caught and logged so telemetry can never block
     * the permission verdict.
     *
     * @param Schema      $schema       Schema that was evaluated.
     * @param string      $action       Custom action verb.
     * @param string|null $userId       User ID under evaluation.
     * @param bool        $verdict      Final verdict.
     * @param bool        $fromListener True when the verdict came from a listener.
     *
     * @return void
     */
    private function dispatchCustomScopeEvaluated(
        Schema $schema,
        string $action,
        ?string $userId,
        bool $verdict,
        bool $fromListener
    ): void {
        if ($this->eventDispatcher === null) {
            return;
        }

        try {
            $this->eventDispatcher->dispatchTyped(
                new CustomScopeEvaluatedEvent(
                    schema: $schema,
                    action: $action,
                    userId: $userId,
                    verdict: $verdict,
                    fromListener: $fromListener
                )
            );
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[PermissionHandler] CustomScopeEvaluatedEvent dispatch failed',
                context: [
                    'file'   => __FILE__,
                    'line'   => __LINE__,
                    'action' => $action,
                    'error'  => $e->getMessage(),
                ]
            );
        }//end try
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
     * @spec openspec/changes/rbac-scopes/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
     */
    public function clearPermissionCache(): void
    {
        $this->permissionCache = [];
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
     */
    public function clearInheritFromPublicCache(?int $schemaId=null): void
    {
        if ($schemaId === null) {
            $this->cachedInheritFromPublic = [];
            return;
        }

        unset($this->cachedInheritFromPublic[$schemaId]);
    }//end clearInheritFromPublicCache()

    /**
     * Check permission and throw exception if not granted
     *
     * @param Schema            $schema      Schema to check permissions for.
     * @param string            $action      Action to check permission for.
     * @param string|null       $userId      User ID to check permissions for.
     * @param string|null       $objectOwner Object owner ID.
     * @param bool              $_rbac       Whether to enforce RBAC checks.
     * @param ObjectEntity|null $object      Optional object entity for conditional authorization matching.
     *
     * @return void
     *
     * @throws Exception If permission is not granted
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC flag follows established API patterns
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function checkPermission(
        Schema $schema,
        string $action,
        ?string $userId=null,
        ?string $objectOwner=null,
        bool $_rbac=true,
        ?ObjectEntity $object=null
    ): void {
        if ($this->hasPermission(
                schema: $schema,
                action: $action,
                userId: $userId,
                objectOwner: $objectOwner,
                _rbac: $_rbac,
                object: $object
            ) === false
        ) {
            $user     = $this->userSession->getUser();
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

    /**
     * Filter objects array based on RBAC and multi-tenancy permissions
     *
     * Removes objects from the array that the current user doesn't have permission to access
     * or that belong to a different organization in multi-tenant mode.
     *
     * @param array<array<string, mixed>> $objects       Array of objects to filter.
     * @param bool                        $_rbac         Whether to apply RBAC filtering.
     * @param bool                        $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return array[] Filtered array of objects
     *
     * @psalm-return list<array<string, mixed>>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Permission filtering requires multiple conditional checks
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC/multitenancy flags follow established API patterns
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function filterObjectsForPermissions(array $objects, bool $_rbac, bool $_multitenancy): array
    {
        $filteredObjects = [];
        $currentUser     = $this->userSession->getUser();
        $userId          = null;
        if ($currentUser !== null) {
            $userId = $currentUser->getUID();
        }

        $activeOrganisation = $this->getActiveOrganisationForContext();

        foreach ($objects as $object) {
            $self = $object['@self'] ?? [];

            // Check RBAC permissions if enabled.
            if ($_rbac === true && $userId !== null) {
                $objectOwner  = $self['owner'] ?? null;
                $objectSchema = $self['schema'] ?? null;

                if ($objectSchema !== null) {
                    try {
                        $schema = $this->schemaMapper->find($objectSchema);
                        // TODO: Add property-level RBAC check for 'create' action here.
                        // Check individual property permissions before allowing property values to be set.
                        if ($this->hasPermission(
                                schema: $schema,
                                action: 'create',
                                userId: $userId,
                                objectOwner: $objectOwner,
                                _rbac: $_rbac
                            ) === false
                        ) {
                            continue;
                            // Skip this object if user doesn't have permission.
                        }
                    } catch (Exception $e) {
                        // Skip objects with invalid schemas.
                        continue;
                    }//end try
                }//end if
            }//end if

            // Check multi-organization filtering if enabled.
            if ($_multitenancy === true && $activeOrganisation !== null) {
                $objectOrganisation = $self['organisation'] ?? null;
                if ($objectOrganisation !== null && $objectOrganisation !== $activeOrganisation) {
                    continue;
                    // Skip objects from different organizations.
                }
            }

            $filteredObjects[] = $object;
        }//end foreach

        return $filteredObjects;
    }//end filterObjectsForPermissions()

    /**
     * Filter UUIDs based on RBAC and multi-tenancy permissions
     *
     * Takes an array of UUIDs, loads the corresponding objects, and filters them
     * based on current user permissions and organization context.
     *
     * @param array<string> $uuids         Array of object UUIDs to filter.
     * @param bool          $_rbac         Whether to apply RBAC filtering.
     * @param bool          $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return string[] Filtered array of UUIDs
     *
     * @psalm-return list<string>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) UUID filtering with permission checks requires multiple conditions
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC/multitenancy flags follow established API patterns
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function filterUuidsForPermissions(array $uuids, bool $_rbac, bool $_multitenancy): array
    {
        $filteredUuids = [];
        $currentUser   = $this->userSession->getUser();
        $userId        = null;
        if ($currentUser !== null) {
            $userId = $currentUser->getUID();
        }

        $activeOrganisation = $this->getActiveOrganisationForContext();

        // Get objects for permission checking.
        $objects = $this->objectEntityMapper->findAll(ids: $uuids, includeDeleted: true);

        foreach ($objects as $object) {
            $objectUuid = $object->getUuid();

            // Check RBAC permissions if enabled.
            if ($_rbac === true && $userId !== null) {
                $objectOwner  = $object->getOwner();
                $objectSchema = $object->getSchema();

                if ($objectSchema !== null) {
                    try {
                        $schema = $this->schemaMapper->find($objectSchema);

                        // TODO: Add property-level RBAC check for 'delete' action here
                        // Check if user has permission to delete objects with specific property values.
                        if ($this->hasPermission(
                                schema: $schema,
                                action: 'delete',
                                userId: $userId,
                                objectOwner: $objectOwner,
                                _rbac: $_rbac
                            ) === false
                        ) {
                            continue;
                            // Skip this object - no permission.
                        }
                    } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                        // Skip this object - schema not found.
                        continue;
                    }//end try
                }//end if
            }//end if

            // Check multi-organization permissions if enabled.
            if ($_multitenancy === true && $activeOrganisation !== null) {
                $objectOrganisation = $object->getOrganisation();

                if ($objectOrganisation !== null && $objectOrganisation !== $activeOrganisation) {
                    // Skip this object - different organization.
                    continue;
                }
            }

            if ($objectUuid !== null) {
                $filteredUuids[] = $objectUuid;
            }
        }//end foreach

        return array_values(array_filter($filteredUuids, fn($uuid) => $uuid !== null));
    }//end filterUuidsForPermissions()

    /**
     * Get the active organisation UUID for the current context
     *
     * @return string|null The active organisation UUID or null if none set
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function getActiveOrganisationForContext(): ?string
    {
        try {
            // Use container to lazy load OrganisationService to avoid circular dependencies.
            $organisationService = $this->container->get('OCA\\OpenRegister\\Service\\OrganisationService');

            // Get active organisation including parent chain.
            $orgUuids = $organisationService->getUserActiveOrganisations();

            if (empty($orgUuids) === false) {
                // Return the first (primary) active organisation.
                return $orgUuids[0];
            }

            // Fallback: try to get just the active organisation.
            $activeOrg = $organisationService->getActiveOrganisation();
            if ($activeOrg !== null) {
                return $activeOrg->getUuid();
            }

            return null;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[PermissionHandler] Failed to get active organisation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }//end try
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
     * @param array|null  $authorization      The schema's authorization array
     * @param string      $groupId            The group ID to check
     * @param string      $action             The CRUD action (create, read, update, delete)
     * @param string|null $userId             Optional user ID for owner check
     * @param string|null $userGroup          Optional user group for admin check
     * @param string|null $objectOwner        Optional object owner for ownership check
     * @param array|null  $objectData         Optional object data for conditional matching
     * @param string|null $objectOrganisation Optional object organisation (folded into @self.organisation)
     *
     * @return bool True if the group has permission
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Rule entries: string, object, conditional, or nested group - each type is a distinct RBAC branch
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function hasGroupPermission(
        ?array $authorization,
        string $groupId,
        string $action,
        ?string $userId=null,
        ?string $userGroup=null,
        ?string $objectOwner=null,
        ?array $objectData=null,
        ?string $objectOrganisation=null
    ): bool {
        // Admin group always has all permissions.
        if ($groupId === 'admin' || $userGroup === 'admin') {
            return true;
        }

        // Object owner always has all permissions for their specific objects.
        if ($userId !== null && $objectOwner !== null && $objectOwner === $userId) {
            return true;
        }

        // If no authorization is set, everyone has all permissions.
        if (empty($authorization) === true) {
            return true;
        }

        // Fail-closed: once a schema opts into authorization (non-empty block),
        // an action that is not explicitly listed is denied — including for the
        // `public`/unauthenticated pseudo-group. Only the empty-block default
        // above still grants by default; the admin/owner bypasses precede this.
        if (empty($authorization[$action]) === true) {
            return false;
        }

        // Check each authorization entry for this action.
        foreach ($authorization[$action] as $entry) {
            // User-level override entry (delegation): a bare string `user:<uid>`
            // or a complex entry `{ "user": "<uid>", "match": {...} }` grants the
            // action to that one user independent of group membership. This is the
            // zaaktype-scoped DELEGATION primitive (rbac-zaaktype): it is purely
            // ADDITIVE — it can only WIDEN access for the named user and never
            // affects group rules. Fail-closed: only an exact uid match is honoured.
            if ($this->userOverrideMatches(
                    entry: $entry,
                    action: $action,
                    userId: $userId,
                    objectData: $objectData,
                    objectOrganisation: $objectOrganisation
                ) === true
            ) {
                return true;
            }

            // Simple string entry: direct group match.
            if (is_string($entry) === true) {
                if ($entry === $groupId) {
                    return true;
                }

                continue;
            }

            // Complex entry with match conditions.
            if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId) {
                // If no match conditions, the group match alone is sufficient.
                if (isset($entry['match']) === false || empty($entry['match']) === true) {
                    return true;
                }

                // Evaluate all match conditions (all must pass) via the shared ConditionMatcher.
                // Build the envelope so ConditionMatcher::getObjectValue() can resolve _organisation
                // (and any other _-prefixed @self field) by stripping the underscore and looking
                // up @self[<stripped>].
                //
                // Precedence: the `+` array union keeps any existing `@self.organisation` already
                // present in $objectData, and only falls back to the separately-passed
                // $objectOrganisation when @self has no `organisation` key. This matches the
                // pre-unification behaviour where the object's own @self was authoritative and
                // the explicit parameter was the fallback source.
                $envelope = ($objectData ?? []);
                if ($objectOrganisation !== null) {
                    $envelope['@self'] = (($envelope['@self'] ?? []) + ['organisation' => $objectOrganisation]);
                }

                if ($this->conditionMatcher->objectMatchesConditions(
                        object: $envelope,
                        match: $entry['match']
                    ) === true
                ) {
                    return true;
                }
            }//end if
        }//end foreach

        return false;
    }//end hasGroupPermission()

    /**
     * Determine whether an authorization entry is a user-level override that
     * grants the current user the requested action.
     *
     * User-level overrides implement zaaktype-scoped DELEGATION (rbac-zaaktype):
     * a permission granted to an individual user independent of group membership,
     * used for external advisors, temporary replacements, and escalation paths.
     * They are expressed inside the same schema `authorization[$action]` list as
     * either:
     *   - a bare string `"user:<uid>"`; or
     *   - a complex entry `{ "user": "<uid>", "match": { ... } }` whose optional
     *     `match` clause is evaluated by the shared {@see ConditionMatcher} (so an
     *     expiry can be encoded as e.g. `{ "_expires": { "$gt": "$now" } }` on the
     *     object, or any other object-data predicate).
     *
     * SECURITY — fail closed and never widen group access:
     *   - Only an EXACT uid match grants. A missing/blank uid, a non-matching uid,
     *     or a malformed entry returns false.
     *   - Anonymous principals (userId === null) can never match a user override.
     *   - The override is purely additive: returning false here lets the normal
     *     group/owner/public rule chain decide, so an override can only ADD access
     *     for the named user and can never remove a group's existing grant.
     *
     * @param mixed       $entry              A single authorization entry for the action.
     * @param string      $action             The CRUD action being checked (for match-create filtering).
     * @param string|null $userId             The current user ID (null = anonymous).
     * @param array|null  $objectData         Optional object data for conditional matching.
     * @param string|null $objectOrganisation Optional object organisation (folded into @self.organisation).
     *
     * @return bool True only when the entry is a user override for the current user
     *              and any attached match clause is satisfied.
     *
     * @spec openspec/specs/rbac-zaaktype/spec.md
     */
    private function userOverrideMatches(
        mixed $entry,
        string $action,
        ?string $userId,
        ?array $objectData=null,
        ?string $objectOrganisation=null
    ): bool {
        // Anonymous principals can never match a user-level override.
        if ($userId === null || $userId === '') {
            return false;
        }

        $targetUid = null;
        $match     = null;

        // Bare string form: "user:<uid>".
        if (is_string($entry) === true) {
            if (str_starts_with($entry, 'user:') === false) {
                return false;
            }

            $targetUid = substr($entry, strlen('user:'));
        } else if (is_array($entry) === true && isset($entry['user']) === true && is_string($entry['user']) === true) {
            // Complex form: { "user": "<uid>", "match": {...} }.
            $targetUid = $entry['user'];
            $match     = ($entry['match'] ?? null);
        } else {
            return false;
        }

        // Exact uid match required (fail closed on blank / mismatch).
        if ($targetUid === '' || $targetUid !== $userId) {
            return false;
        }

        // No match clause: the user override alone is sufficient.
        if ($match === null || is_array($match) === false || empty($match) === true) {
            return true;
        }

        // On create there is no existing object to match organisation against;
        // strip organisation predicates exactly as the group path relies on
        // ConditionMatcher to do elsewhere.
        if ($action === 'create') {
            $match = $this->conditionMatcher->filterOrganisationMatchForCreate(match: $match);
            if ($match === []) {
                return true;
            }
        }

        // Evaluate the match clause via the shared ConditionMatcher (ADR-011) —
        // same envelope construction as the group path so _organisation resolves.
        $envelope = ($objectData ?? []);
        if ($objectOrganisation !== null) {
            $envelope['@self'] = (($envelope['@self'] ?? []) + ['organisation' => $objectOrganisation]);
        }

        return $this->conditionMatcher->objectMatchesConditions(
            object: $envelope,
            match: $match
        );
    }//end userOverrideMatches()

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
     * @param string     $action        The CRUD action being checked.
     *
     * @return bool True only when `public` is explicitly listed for the action.
     */
    private function publicGroupExplicitlyGranted(?array $authorization, string $action): bool
    {
        if (empty($authorization) === true || isset($authorization[$action]) === false) {
            return false;
        }

        if (is_array($authorization[$action]) === false) {
            return false;
        }

        foreach ($authorization[$action] as $entry) {
            if (is_string($entry) === true && $entry === 'public') {
                return true;
            }

            if (is_array($entry) === true && ($entry['group'] ?? null) === 'public') {
                return true;
            }
        }

        return false;
    }//end publicGroupExplicitlyGranted()

    /**
     * Get all groups that have permission for a specific action
     *
     * @param array|null $authorization The schema's authorization array
     * @param string     $action        The CRUD action to check
     *
     * @return array Array of group IDs that have permission, or empty array if all groups have permission
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function getAuthorizedGroups(?array $authorization, string $action): array
    {
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
     *                        object is publicly readable (no restriction) or the schema
     *                        cannot be resolved.
     *
     * @spec openspec/changes/add-live-updates/tasks.md#task-3
     */
    public function getReadableByUsers(ObjectEntity $object): array
    {
        $schemaId = $object->getSchema();
        if ($schemaId === null) {
            return [];
        }

        try {
            // System-internal lookup: bypass RBAC + multitenancy. The listener
            // that calls getReadableByUsers is emitting push notifications, not
            // enforcing user-facing access on the schema itself. Without this
            // bypass, SchemaMapper::find throws "Schema not found" when the
            // request user's tenant doesn't own the schema (notably any OR
            // object event that crosses tenant boundaries) and the listener
            // silently no-ops with an empty reader list — no push fires. Issue #1454.
            $schema = $this->schemaMapper->find(
                id: $schemaId,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[PermissionHandler] getReadableByUsers: schema lookup failed',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $schemaId,
                    'error'    => $e->getMessage(),
                ]
            );
            return [];
        }//end try

        $authorization = $this->resolveAuthorization(schema: $schema);
        $groupIds      = $this->resolveReadGroupIds(authorization: $authorization);

        // Null means "open / broadcast" — no targeted user list.
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
    private function resolveReadGroupIds(?array $authorization): ?array
    {
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
    private function extractGroupIdsFromReadEntries(array $readEntries): array
    {
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
    private function collectUsersFromGroups(array $groupIds): array
    {
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
     * If the schema has its own authorization block, use it directly.
     * If not, fall back to the parent register's authorization block.
     * Role references in the authorization are expanded to action-level permissions.
     *
     * @param Schema $schema The schema to resolve authorization for.
     *
     * @return array|null The effective authorization array, or null if none configured.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function resolveAuthorization(Schema $schema): ?array
    {
        $authorization = $schema->getAuthorization();

        // If schema has its own authorization, expand roles and return.
        if (empty($authorization) === false) {
            return $this->expandRoles(authorization: $authorization, schema: $schema);
        }

        // Fall back to register authorization.
        $register = $this->getRegisterForSchema(schema: $schema);
        if ($register === null) {
            return null;
        }

        $registerAuth = $this->getRegisterAuthorization(registerId: $register->getId());
        if (empty($registerAuth) === false) {
            return $this->expandRoles(authorization: $registerAuth, schema: $schema);
        }

        return null;
    }//end resolveAuthorization()

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
    public function resolveInheritFromPublic(Schema $schema): bool
    {
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
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $schemaId,
                    'default'  => $resolved,
                    'error'    => $e->getMessage(),
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
     */
    public function inheritFromPublicTenantDefault(): bool
    {
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
    private function resolveRegisterInheritFromPublic(?int $schemaId): ?bool
    {
        if ($schemaId === null) {
            return null;
        }

        $registerIds = [];
        try {
            $registerMapper = $this->container->get(RegisterMapper::class);
            $registerIds    = $registerMapper->getAllRegisterIdsWithSchema(schemaId: $schemaId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[PermissionHandler] Failed to list registers for schema; skipping register cascade',
                context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId, 'error' => $e->getMessage()]
            );
            return null;
        }

        $sawTrue = false;
        foreach ($registerIds as $registerId) {
            $registerAuth = $this->getRegisterAuthorization(registerId: $registerId);
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
     * @param mixed    $value    The stored value.
     * @param string   $level    The cascade level ('schema' | 'register'), for logging.
     * @param int|null $schemaId The schema id, for logging.
     *
     * @return bool|null The boolean value, or null when the value is not a real boolean.
     */
    private function coerceStrictBoolOrLog(mixed $value, string $level, ?int $schemaId): ?bool
    {
        if ($value === true || $value === false) {
            return $value;
        }

        if ($value !== null) {
            $this->logger->warning(
                message: '[PermissionHandler] Non-boolean inheritFromPublic value ignored; treating as unset',
                context: [
                    'level'    => $level,
                    'schemaId' => $schemaId,
                    'type'     => get_debug_type($value),
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
     * @param Schema $schema The schema to find the register for.
     *
     * @return Register|null The parent register, or null if not found.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-55
     */
    private function getRegisterForSchema(Schema $schema): ?Register
    {
        try {
            $registerMapper = $this->container->get(RegisterMapper::class);
            $registerId     = $registerMapper->getFirstRegisterWithSchema($schema->getId());
            if ($registerId === null) {
                return null;
            }

            return $registerMapper->find($registerId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[PermissionHandler] Failed to get register for schema',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $schema->getId(),
                    'error'    => $e->getMessage(),
                ]
            );
            return null;
        }
    }//end getRegisterForSchema()

    /**
     * Get register authorization with per-request caching.
     *
     * Caches the authorization array for each register ID to avoid
     * repeated database lookups within a single request.
     *
     * @param int $registerId The register ID to get authorization for.
     *
     * @return array|null The register's authorization array.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-56
     */
    private function getRegisterAuthorization(int $registerId): ?array
    {
        if (array_key_exists($registerId, $this->cachedRegisterAuth) === true) {
            return $this->cachedRegisterAuth[$registerId];
        }

        try {
            $registerMapper = $this->container->get(RegisterMapper::class);
            $register       = $registerMapper->find($registerId);
            $auth           = $register->getAuthorization();

            $this->cachedRegisterAuth[$registerId]   = $auth;
            $this->cachedRegisterConfig[$registerId] = $register->getConfiguration();

            return $auth;
        } catch (\Throwable $e) {
            $this->cachedRegisterAuth[$registerId] = null;
            return null;
        }
    }//end getRegisterAuthorization()

    /**
     * Get register configuration with per-request caching.
     *
     * @param int $registerId The register ID to get configuration for.
     *
     * @return array|null The register's configuration array.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-57
     */
    private function getRegisterConfiguration(int $registerId): ?array
    {
        if (array_key_exists($registerId, $this->cachedRegisterConfig) === true) {
            return $this->cachedRegisterConfig[$registerId];
        }

        // Calling getRegisterAuthorization populates both caches.
        $this->getRegisterAuthorization(registerId: $registerId);

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
     * @param array  $authorization The authorization block to expand.
     * @param Schema $schema        The schema (used to find parent register for role definitions).
     *
     * @return array The authorization with roles expanded to action-level entries.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Role expansion: validate defs, resolve `extends` chains, merge action sets, guard malformed data
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-7
     */
    public function expandRoles(array $authorization, Schema $schema): array
    {
        if (isset($authorization['roles']) === false || is_array($authorization['roles']) === false) {
            return $authorization;
        }

        $roleAssignments = $authorization['roles'];
        unset($authorization['roles']);

        // Get role definitions from the parent register.
        $roleDefinitions = $this->getRoleDefinitionsForSchema(schema: $schema);
        if (empty($roleDefinitions) === true) {
            $this->logger->warning(
                message: '[PermissionHandler] Schema has role references but register has no role definitions',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $schema->getId(),
                ]
            );
            return $authorization;
        }

        // Build the lookup map with role-hierarchy support. A role
        // definition can declare `extends: <otherRoleName>` to inherit
        // that role's action set; the union is computed once and cached
        // so circular `extends` chains can't recurse forever. This is
        // the smallest expression of the spec's "Role Definitions and
        // Hierarchy" requirement: register-level roles declare an
        // optional `extends` reference, schemas keep their compact
        // `roles: {assignedRole: [groups]}` syntax, and the resolved
        // action set composes inherited + own actions transparently.
        $rawRoleMap = [];
        foreach ($roleDefinitions as $roleDef) {
            if (isset($roleDef['name']) === true) {
                $rawRoleMap[$roleDef['name']] = $roleDef;
            }
        }

        $roleMap = $this->resolveRoleHierarchy(rawRoleMap: $rawRoleMap, schema: $schema);

        // Expand each role assignment into action-level entries.
        foreach ($roleAssignments as $roleName => $groups) {
            if (isset($roleMap[$roleName]) === false) {
                $this->logger->warning(
                    message: '[PermissionHandler] Unknown role name referenced in authorization',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'roleName' => $roleName,
                        'schemaId' => $schema->getId(),
                    ]
                );
                continue;
            }

            $actions = $roleMap[$roleName];
            foreach ($actions as $action) {
                if (isset($authorization[$action]) === false) {
                    $authorization[$action] = [];
                }

                // Merge groups, avoiding duplicates.
                foreach ((array) $groups as $group) {
                    if (in_array($group, $authorization[$action], true) === false) {
                        $authorization[$action][] = $group;
                    }
                }
            }
        }//end foreach

        return $authorization;
    }//end expandRoles()

    /**
     * Flatten a role hierarchy into a `name => actions` map.
     *
     * Supports an optional `extends: <roleName>` (string) or `extends: [<roleName>, ...]`
     * (array of role names) on each role definition. Inherited actions
     * are merged with the role's own actions; cycles abort safely
     * (a `WARN`-logged shorter chain wins) so a misconfigured register
     * can't deadlock the request.
     *
     * @param array<string, array<string, mixed>> $rawRoleMap Role definitions keyed by name.
     * @param Schema                              $schema     Schema for diagnostic context.
     *
     * @return array<string, array<int, string>> Flat `name => actions` map.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Role hierarchy: missing-name guard, visited-node cycle check, and parent action set merging
     */
    private function resolveRoleHierarchy(array $rawRoleMap, Schema $schema): array
    {
        $resolved = [];

        foreach (array_keys($rawRoleMap) as $roleName) {
            $resolved[$roleName] = $this->collectRoleActions(
                roleName: $roleName,
                rawRoleMap: $rawRoleMap,
                visited: [],
                schema: $schema
            );
        }

        return $resolved;

    }//end resolveRoleHierarchy()

    /**
     * Walk the `extends` chain for a role and return the combined action set.
     *
     * @param string                              $roleName   Name of the role being resolved.
     * @param array<string, array<string, mixed>> $rawRoleMap Full role-definition map.
     * @param array<string, true>                 $visited    Roles already visited in this chain (cycle guard).
     * @param Schema                              $schema     Schema for diagnostic context.
     *
     * @return array<int, string> Deduplicated action list.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Collects actions: existence check, cycle guard, extends string/array, parent recursion, merging
     */
    private function collectRoleActions(
        string $roleName,
        array $rawRoleMap,
        array $visited,
        Schema $schema
    ): array {
        if (isset($rawRoleMap[$roleName]) === false) {
            return [];
        }

        if (isset($visited[$roleName]) === true) {
            $this->logger->warning(
                message: '[PermissionHandler] Cyclic role-hierarchy reference; ignoring repeat',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'roleName' => $roleName,
                    'schemaId' => $schema->getId(),
                ]
            );
            return [];
        }

        $visited[$roleName] = true;
        $definition         = $rawRoleMap[$roleName];

        $actions = [];

        // Inherit from `extends` first, so the role's own actions can
        // override (or just live alongside) inherited entries.
        $extends = $definition['extends'] ?? null;
        if ($extends !== null) {
            $parents = [$extends];
            if (is_array($extends) === true) {
                $parents = $extends;
            }

            foreach ($parents as $parent) {
                if (is_string($parent) === false || $parent === '') {
                    continue;
                }

                $inherited = $this->collectRoleActions(
                    roleName: $parent,
                    rawRoleMap: $rawRoleMap,
                    visited: $visited,
                    schema: $schema
                );
                foreach ($inherited as $inheritedAction) {
                    if (in_array($inheritedAction, $actions, true) === false) {
                        $actions[] = $inheritedAction;
                    }
                }//end foreach
            }//end foreach
        }//end if

        // Own actions on top.
        $ownActions = $definition['actions'] ?? [];
        if (is_array($ownActions) === true) {
            foreach ($ownActions as $ownAction) {
                if (is_string($ownAction) === true && in_array($ownAction, $actions, true) === false) {
                    $actions[] = $ownAction;
                }
            }
        }

        return $actions;

    }//end collectRoleActions()

    /**
     * Get role definitions for a schema from its parent register.
     *
     * Looks up the parent register's configuration.roles array.
     *
     * @param Schema $schema The schema to find role definitions for.
     *
     * @return array Array of role definitions, each with 'name', 'description', 'actions'.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-57
     */
    private function getRoleDefinitionsForSchema(Schema $schema): array
    {
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
     * @param string|null       $userId            Caller uid, or null for an anonymous principal.
     * @param Schema            $schema            Schema the transition belongs to (for named-role expansion).
     *
     * @return bool True when the caller is authorised; false (fail-closed) otherwise.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Anonymous guard + admin bypass + literal-vs-role entry handling are each one irreducible branch.
     *
     * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
     */
    public function isTransitionAuthorized(array $authorizationList, ?string $userId, Schema $schema): bool
    {
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
    private function resolveTransitionRoleGroups(Schema $schema): array
    {
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

            $map[$roleName] = array_values(array_filter((array) $groups, 'is_string'));
        }

        return $map;
    }//end resolveTransitionRoleGroups()
}//end class
