<?php

/**
 * OpenRegister AggregationRunner
 *
 * Loads a schema's `x-openregister-aggregations` annotation, fetches the
 * matched objects via the existing findAll path (RBAC + multi-tenancy
 * still applied), and computes the metric in PHP.
 *
 * v1 trades performance for simplicity: Postgres-native aggregation
 * (GROUP BY) is the fast path; PHP fallback covers non-Postgres setups.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-4
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs a named aggregation against a schema.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 *   The runner orchestrates four execution paths (named / cross-schema /
 *   ad-hoc / cached) across three backends (Postgres / MySQL / SQLite +
 *   PHP fallback). The method count rises with each platform-specific
 *   helper; extracting them into a separate class would require passing
 *   the full constructor dependency graph through.
 */
class AggregationRunner
{

    /**
     * Hard cap on the number of rows the PHP fallback path will hydrate
     * per request. The native Postgres path (tryNativeAggregation) does
     * the work in SQL and is unaffected. Picked at 10 000 to keep peak
     * memory bounded against an authenticated-caller request flood that
     * varies the filter to bypass the AggregationCache.
     */
    private const PHP_FALLBACK_ROW_CAP = 10000;

    /**
     * Constructor.
     *
     * @param MagicMapper          $magicMapper         Magic-table mapper used for the PHP fallback path.
     * @param RegisterMapper       $registerMapper      Register loader.
     * @param SchemaMapper         $schemaMapper        Schema loader.
     * @param PlaceholderResolver  $placeholders        Resolves dynamic placeholders inside filters.
     * @param IDBConnection        $db                  Database connection for the Postgres-native fast path.
     * @param AggregationCache     $cache               60s aggregation result cache.
     * @param PermissionHandler    $permissionHandler   RBAC verdict on the schema's `list` action.
     * @param IUserSession         $userSession         Active session, for the RBAC + cache-key user scope.
     * @param OrganisationService  $organisationService Active-organisation lookup for the cache key.
     * @param TranslationHandler   $translationHandler  Resolves translatable group keys to the negotiated language.
     * @param LanguageService      $languageService     Request-scoped language negotiation (Accept-Language / _lang).
     * @param LoggerInterface|null $logger              Optional logger for diagnostics.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly PlaceholderResolver $placeholders,
        private readonly IDBConnection $db,
        private readonly AggregationCache $cache,
        private readonly PermissionHandler $permissionHandler,
        private readonly IUserSession $userSession,
        private readonly OrganisationService $organisationService,
        private readonly TranslationHandler $translationHandler,
        private readonly LanguageService $languageService,
        private readonly ?LoggerInterface $logger=null
    ) {
    }//end __construct()

    /**
     * Run the named aggregation on the given (register, schema).
     *
     * When the aggregation spec declares a `from` key the call is
     * transparently delegated to {@see runCrossSchema()}: the named
     * schema is the aggregation *source*, the current schema supplies
     * the optional `@self.*` parent-reference values via `$parentRow`.
     *
     * @param string               $registerRef Register slug/uuid/id.
     * @param string               $schemaRef   Schema slug/uuid/id.
     * @param string               $name        Aggregation name (key in the annotation).
     * @param bool                 $bypassRbac  Internal-system mode: skip the F04 list-permission gate.
     *                                          Pass `true` ONLY from non-controller callers that already
     *                                          hold an authoritative reason to compute the aggregation
     *                                          (e.g. report rendering for a viewer who has dashboard read,
     *                                          threshold listeners reacting to a write event).
     *                                          Defaults to `false` so HTTP-driven callers (the
     *                                          controller) keep the F04 verdict.
     * @param array<string, mixed> $parentRow   Parent object's field values, used to resolve `@self.<field>`
     *                                          references in a cross-schema `where` clause.  Pass the
     *                                          plain object array from the parent read path.  Ignored when
     *                                          the aggregation spec has no `from` key.
     *
     * @return array{
     *   name: string,
     *   metric: string,
     *   field: ?string,
     *   value?: int|float|null,
     *   groups?: array<int, array{key: mixed, value: int|float|null}>
     * }
     *
     * @throws RuntimeException When the schema/aggregation is missing.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Internal-mode toggle, intentional.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    public function run(
        string $registerRef,
        string $schemaRef,
        string $name,
        bool $bypassRbac=false,
        array $parentRow=[]
    ): array {
        $schema   = $this->loadSchema(schemaRef: $schemaRef);
        $register = $this->loadRegister(registerRef: $registerRef);

        // SECURITY: gate aggregation behind list-permission on the schema
        // before any native or fallback path executes. Without this gate,
        // the native PG path would compute COUNT/SUM/AVG over rows the
        // caller has no read permission for (cross-tenant + cross-RBAC
        // statistics leak). NotAuthorizedException maps to HTTP 403 in
        // AggregationController; using RuntimeException here would mask
        // it as 404.
        //
        // NOTE: this gate is schema-level (matches `list`). For schemas
        // whose authorization config also declares per-object ACL rules
        // (`object-acl` / conditional rules), the aggregate value still
        // rolls up rows the caller cannot read row-by-row. A future
        // hardening step is to add a per-row hasPermission(read) filter
        // in the PHP fallback path and a derived WHERE clause (or
        // bailout to PHP) in the native path. Tracked alongside the
        // aggregations-backend-native follow-up.
        //
        // Non-controller callers (ReportRenderService for dashboard
        // widgets, AggregationThresholdListener for fire-once threshold
        // crossings) pass `bypassRbac: true` because they already hold
        // an authoritative reason that's separate from the active session
        // (a viewer with dashboard read, a write-event reaction).
        $userId = $this->userSession->getUser()?->getUID();
        if ($bypassRbac === false && $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'list',
            userId: $userId,
            objectOwner: null,
            _rbac: true,
            object: null
        ) === false
        ) {
            throw new NotAuthorizedException(
                message: sprintf(
                    'You do not have permission to aggregate schema "%s".',
                    $schemaRef
                )
            );
        }

        $annotation = $this->getAnnotation(schema: $schema);
        if ($annotation === null) {
            throw new RuntimeException(
                sprintf('Schema "%s" does not declare x-openregister-aggregations.', $schemaRef)
            );
        }

        if (isset($annotation[$name]) === false || is_array($annotation[$name]) === false) {
            throw new RuntimeException(sprintf('Aggregation "%s" is not declared on this schema.', $name));
        }

        $spec = $annotation[$name];

        // Cross-schema aggregation: delegate to dedicated path when `from`
        // is declared.  Supports the new DSL (`from`, `where`, `select`) in
        // addition to the legacy DSL (`filter`, `metric`).  The delegation
        // happens *after* the RBAC/annotation guards above so the same
        // permission gates cover both intra- and cross-schema calls.
        $fromSchema = ($spec['from'] ?? null);
        if (is_string($fromSchema) === true && $fromSchema !== '') {
            return $this->runCrossSchema(
                parentRegister: $register,
                parentSchema: $schema,
                name: $name,
                spec: $spec,
                parentRow: $parentRow,
                bypassRbac: $bypassRbac
            );
        }

        // Support `select` as an alias for `metric` (new DSL) and
        // `where` as an alias for `filter` (new DSL) so callers can
        // use either vocabulary on intra-schema specs too.
        $metric  = (string) ($spec['metric'] ?? $spec['select'] ?? '');
        $field   = ($spec['field'] ?? null);
        $filter  = (array) ($spec['filter'] ?? $spec['where'] ?? []);
        $groupBy = ($spec['groupBy'] ?? null);

        // Normalized groupBy spec (array or null) — used both for the native
        // path argument and for translatable group-key projection.
        $groupByArg = null;
        if (is_array($groupBy) === true) {
            $groupByArg = $groupBy;
        }

        $resolvedFilter = $this->placeholders->resolveArray($filter);

        // Cache lookup: the resolved filter (with placeholders concrete)
        // is the cache key together with the user's RBAC scope. 60s TTL.
        // SECURITY: cache key MUST include userId + active organisation —
        // two callers with different RBAC verdicts on the same (metric,
        // field, filter) tuple would otherwise read each other's results.
        $activeOrg = $this->organisationService->getActiveOrganisation();
        $cacheKey  = [
            'metric'  => $metric,
            'field'   => $field,
            'filter'  => $resolvedFilter,
            'groupBy' => $groupBy,
            'userId'  => $userId,
            'org'     => $activeOrg?->getUuid(),
        ];
        $cached    = $this->cache->get(
            registerSlug: (string) $register->getSlug(),
            schemaSlug: (string) $schema->getSlug(),
            name: $name,
            filter: $cacheKey
        );
        if ($cached !== null) {
            $cached['cached'] = true;
            // Cache stores raw group keys (language-agnostic key), project on read.
            return $this->projectTranslatableGroupKeys(
                envelope: $cached,
                schema: $schema,
                register: $register,
                groupBy: $groupByArg
            );
        }

        // Try the Postgres-native fast path. Falls back to PHP when the
        // query shape isn't supported (operator filters, complex values,
        // non-Postgres DB, etc).
        $nativeFieldArg = null;
        if (is_string($field) === true) {
            $nativeFieldArg = $field;
        }

        $nativeGroupByArg = $groupByArg;

        $native = $this->tryNativeAggregation(
            register: $register,
            schema: $schema,
            metric: $metric,
            field: $nativeFieldArg,
            filter: $resolvedFilter,
            groupBy: $nativeGroupByArg
        );
        if ($native !== null) {
            // R05: native Postgres aggregates over the full set, so
            // `truncated` is structurally always false here. Surface
            // the key explicitly so client code can branch on
            // `result.truncated` regardless of backend.
            $nativeFieldValue = null;
            if (is_string($field) === true) {
                $nativeFieldValue = $field;
            }

            $result = [
                'name'      => $name,
                'metric'    => $metric,
                'field'     => $nativeFieldValue,
                'backend'   => 'postgres',
                'truncated' => false,
            ] + $native;
            $this->cache->set(
                registerSlug: (string) $register->getSlug(),
                schemaSlug: (string) $schema->getSlug(),
                name: $name,
                filter: $cacheKey,
                result: $result
            );
            return $this->projectTranslatableGroupKeys(
                envelope: $result,
                schema: $schema,
                register: $register,
                groupBy: $nativeGroupByArg
            );
        }//end if

        // Fall back: pull objects and filter in PHP.
        //
        // Capped at PHP_FALLBACK_ROW_CAP to bound the per-request memory
        // footprint — the cache is keyed on the resolved filter, so any
        // authenticated caller can otherwise force a fresh hydrate by
        // varying a filter parameter. The native Postgres path is the
        // intended steady state; the fallback is a development /
        // non-Postgres safety net, not a production aggregation engine.
        // Aggregations whose source table exceeds the cap are surfaced
        // with `truncated: true` so callers know the value is partial
        // (or 503 from the controller in a future hardening step).
        $objects   = $this->magicMapper->findAllInRegisterSchemaTable(
            register: $register,
            schema: $schema,
            limit: self::PHP_FALLBACK_ROW_CAP
        );
        $truncated = count($objects) >= self::PHP_FALLBACK_ROW_CAP;

        $rows = [];
        foreach ($objects as $entity) {
            // The findAllInRegisterSchemaTable mapper returns ObjectEntity[];
            // getObject() resolves each row to the inner data array.
            $rows[] = $entity->getObject();
        }

        // Apply post-filter for operator shapes the underlying mapper
        // doesn't natively support (e.g. gte/lte). Equality filters were
        // already applied above, so re-applying them is a no-op.
        $rows = $this->applyFilter(rows: $rows, filter: $resolvedFilter);

        $phpFallbackField = null;
        if (is_string($field) === true) {
            $phpFallbackField = $field;
        }

        $result = [
            'name'      => $name,
            'metric'    => $metric,
            'field'     => $phpFallbackField,
            'backend'   => 'php-fallback',
            'truncated' => $truncated,
        ];

        if (is_array($groupBy) === true && isset($groupBy['field']) === true) {
            $result['groups'] = $this->computeGrouped(
                rows: $rows,
                metric: $metric,
                field: $field,
                groupField: (string) $groupBy['field']
            );
        }

        if (isset($result['groups']) === false) {
            $result['value'] = $this->computeMetric(rows: $rows, metric: $metric, field: $field);
        }

        $this->cache->set(
            registerSlug: (string) $register->getSlug(),
            schemaSlug: (string) $schema->getSlug(),
            name: $name,
            filter: $cacheKey,
            result: $result
        );
        return $this->projectTranslatableGroupKeys(
            envelope: $result,
            schema: $schema,
            register: $register,
            groupBy: $groupByArg
        );
    }//end run()

    /**
     * Execute an ad-hoc aggregation query — REST + GraphQL entry point.
     *
     * Where {@see run()} loads a named aggregation from the schema's
     * `x-openregister-aggregations` annotation and executes it, this
     * method takes a fully-built `AggregationQuery` value object from
     * the caller (the REST `/aggregate/timeseries` controller or the
     * GraphQL `groupBy` resolver) and runs it directly against the
     * register/schema's magic table.
     *
     * The execution pipeline is the same as `run()`:
     *
     * 1. RBAC gate via `PermissionHandler::hasPermission(list)`.
     * 2. Read-through cache lookup via `AggregationCache::getAdhoc()` —
     *    cache key derives from `sha1(json_encode($query->toArray()))`
     *    prefixed with `adhoc:`. 60 s TTL. Invalidated on every
     *    object lifecycle event by `AggregationCacheInvalidationListener`.
     * 3. Postgres / MySQL / SQLite native fast path via
     *    `tryNativeAggregation()` — picks the matching bucketing
     *    expression (`date_trunc` / `DATE_FORMAT` / `strftime`).
     * 4. PHP fallback bucketing when the native path returns null
     *    (unrecognised engine, table miss, etc).
     *
     * Field allow-listing (only declared schema properties + the
     * `_created/_updated/_deleted_at` magic-table metadata cols) is
     * the caller's responsibility (REST controller / GraphQL resolver)
     * because the relevant 400-error message lives at that layer.
     *
     * @param Register         $register The register the schema belongs to.
     * @param Schema           $schema   The schema being aggregated.
     * @param AggregationQuery $query    The fully-validated query value object.
     *
     * @return array<string, mixed> Either `{value, backend, cached}` (ungrouped)
     *                              or `{groups, backend, cached}` (grouped /
     *                              time-bucketed). `cached` flips to `true`
     *                              on the second identical request within
     *                              the 60 s TTL window.
     *
     * @throws NotAuthorizedException When the caller lacks list-permission on the schema.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *   RBAC + placeholder-resolve + cache-read + native-or-fallback dispatch +
     *   cache-write. Splitting each step into its own helper would obscure
     *   the order of operations that matters for the security gate and
     *   cache semantics.
     * @SuppressWarnings(PHPMD.StaticAccess)
     *   `AggregationQuery::create()` is a fail-fast static factory.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    public function runAdhoc(
        Register $register,
        Schema $schema,
        AggregationQuery $query
    ): array {
        // RBAC gate — identical predicate to run().
        $userId = $this->userSession->getUser()?->getUID();
        if ($this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'list',
            userId: $userId,
            objectOwner: null,
            _rbac: true,
            object: null
        ) === false
        ) {
            throw new NotAuthorizedException(
                message: sprintf(
                    'You do not have permission to aggregate schema "%s".',
                    (string) $schema->getSlug()
                )
            );
        }

        // Resolve placeholders inside the filter (e.g. $now, $startOfMonth)
        // so the native SQL path can bind concrete values. The resolved
        // filter feeds both the cache key and the SQL bindings.
        $resolvedFilter = $this->placeholders->resolveArray($query->filter);
        $resolvedQuery  = AggregationQuery::create(
            metric: $query->metric,
            field: $query->field,
            filter: $resolvedFilter,
            groupBy: $query->groupBy,
            dateBucket: $query->dateBucket
        );

        // Read-through cache. Identical query + filter + RBAC scope →
        // same cache key → 60 s TTL hit. Cache miss falls through to the
        // native-or-fallback dispatch below and the result is stored on
        // the way out. Invalidation is event-driven via
        // AggregationCacheInvalidationListener which evicts the entire
        // `openregister_aggregations` cache on every object-write event.
        $cached = $this->cache->getAdhoc(
            registerSlug: (string) $register->getSlug(),
            schemaSlug: (string) $schema->getSlug(),
            query: $resolvedQuery
        );
        if ($cached !== null) {
            $cached['cached'] = true;
            // The cache stores RAW group keys (the cache key is language-
            // agnostic), so translatable keys must be projected on every
            // read, including cache hits.
            return $this->projectTranslatableGroupKeys(
                envelope: $cached,
                schema: $schema,
                register: $register,
                groupBy: $query->groupBy
            );
        }

        // Try the Postgres / MySQL / SQLite native fast path. The runner
        // detects the database platform and emits the matching native
        // bucketing expression (date_trunc / DATE_FORMAT / strftime).
        // Returns null on unsupported query shapes or unrecognised
        // engines, signaling fall-through to the PHP bucketer below.
        $native = $this->tryNativeAggregation(
            register: $register,
            schema: $schema,
            metric: $query->metric,
            field: $query->field,
            filter: $resolvedFilter,
            groupBy: $query->groupBy,
            dateBucket: $query->dateBucket
        );

        if ($native !== null) {
            $envelope = [
                'backend' => $this->detectDatabasePlatform(),
                'cached'  => false,
            ] + $native;
            // Cache the RAW (un-projected) group keys — the cache key does
            // not encode the negotiated language, so language projection
            // must happen after the cache boundary on every read.
            $this->cache->setAdhoc(
                registerSlug: (string) $register->getSlug(),
                schemaSlug: (string) $schema->getSlug(),
                query: $resolvedQuery,
                result: $envelope
            );
            return $this->projectTranslatableGroupKeys(
                envelope: $envelope,
                schema: $schema,
                register: $register,
                groupBy: $query->groupBy
            );
        }//end if

        // PHP fallback path. Pull the RBAC-filtered row set + bucket in
        // PHP. Correctness path; the native paths above are the
        // production target.
        $envelope = $this->bucketInPhp(
            register: $register,
            schema: $schema,
            metric: $query->metric,
            field: $query->field,
            filter: $resolvedFilter,
            groupBy: $query->groupBy,
            dateBucket: $query->dateBucket
        );
        $this->cache->setAdhoc(
            registerSlug: (string) $register->getSlug(),
            schemaSlug: (string) $schema->getSlug(),
            query: $resolvedQuery,
            result: $envelope
        );
        return $this->projectTranslatableGroupKeys(
            envelope: $envelope,
            schema: $schema,
            register: $register,
            groupBy: $query->groupBy
        );

    }//end runAdhoc()

    /**
     * Project translatable group keys in a grouped-aggregation envelope to
     * the negotiated display language.
     *
     * `GET /api/objects/aggregations/{register}/{schema}/grouped` groups on
     * a single field via SQL `GROUP BY` (native path) or PHP bucketing
     * (fallback). When that field is `translatable: true`, the stored value
     * is a language-keyed map (e.g. `{"nl":"Foo","en":"Bar"}`), so each
     * group `key` comes back as the raw map (native: a JSON string; PHP: an
     * associative array) instead of the single projected string a normal
     * read returns.
     *
     * This projects each such key to the caller's negotiated language,
     * reusing {@see TranslationHandler::resolveTranslationsForRender()} for
     * the language chain / fallback logic rather than reimplementing it.
     *
     * No-op when:
     * - the envelope carries no `groups`;
     * - there is no single scalar `groupBy.field`;
     * - the groupBy field is NOT translatable on the schema;
     * - `?_translations=all` is requested (keys are returned verbatim).
     *
     * @param array<string, mixed>      $envelope The aggregation result envelope.
     * @param Schema                    $schema   The schema being aggregated.
     * @param Register                  $register The owning register (language config).
     * @param array<string, mixed>|null $groupBy  The groupBy spec ({field: ...}).
     *
     * @return array<string, mixed> The envelope with translatable group keys projected.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *   The method is a chain of cheap short-circuit guards (no groups /
     *   no groupBy field / not translatable / _translations=all) before
     *   the projection loop; each guard adds a branch but keeps the hot
     *   path a single early return.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function projectTranslatableGroupKeys(
        array $envelope,
        Schema $schema,
        Register $register,
        ?array $groupBy
    ): array {
        if (isset($envelope['groups']) === false || is_array($envelope['groups']) === false) {
            return $envelope;
        }

        if ($groupBy === null || isset($groupBy['field']) === false) {
            return $envelope;
        }

        $groupField = (string) $groupBy['field'];

        // Only project when the grouped field is actually translatable.
        // getTranslatableProperties() is cheap and this keeps NON-translatable
        // grouped fields byte-for-byte unchanged.
        $translatableProps = $this->translationHandler->getTranslatableProperties(schema: $schema);
        if (in_array($groupField, $translatableProps, true) === false) {
            return $envelope;
        }

        // `?_translations=all` returns keys verbatim.
        if ($this->languageService->shouldReturnAllTranslations() === true) {
            return $envelope;
        }

        foreach ($envelope['groups'] as $index => $group) {
            if (is_array($group) === false || array_key_exists('key', $group) === false) {
                continue;
            }

            $envelope['groups'][$index]['key'] = $this->projectSingleTranslatableKey(
                rawKey: $group['key'],
                groupField: $groupField,
                schema: $schema,
                register: $register
            );
        }

        return $envelope;

    }//end projectTranslatableGroupKeys()

    /**
     * Project a single grouped key value to the negotiated language.
     *
     * Normalizes the raw key to a language-keyed map (native path yields a
     * JSON string, PHP path an array), then delegates to
     * {@see TranslationHandler::resolveTranslationsForRender()} by wrapping
     * the map under the grouped field name and reading the resolved value
     * back. Non-map keys (already scalar, e.g. legacy untranslated rows) are
     * returned unchanged.
     *
     * @param mixed    $rawKey     The raw group key (JSON string or array).
     * @param string   $groupField The grouped field name.
     * @param Schema   $schema     The schema being aggregated.
     * @param Register $register   The owning register (language config).
     *
     * @return mixed The projected scalar value, or the original key when not a map.
     *
     * @spec openspec/changes/retrofit-2026-04-28-object-lifecycle/tasks.md#task-1
     */
    private function projectSingleTranslatableKey(
        mixed $rawKey,
        string $groupField,
        Schema $schema,
        Register $register
    ): mixed {
        $map = $rawKey;

        // Native SQL returns the JSON column value as a string; decode it.
        if (is_string($rawKey) === true) {
            $decoded = json_decode($rawKey, true);
            if (is_array($decoded) === true) {
                $map = $decoded;
            }
        }

        // Not a language-keyed map (scalar / legacy untranslated) — leave it.
        if (is_array($map) === false) {
            return $rawKey;
        }

        $resolved = $this->translationHandler->resolveTranslationsForRender(
            objectData: [$groupField => $map],
            schema: $schema,
            register: $register
        );

        return $resolved[$groupField] ?? $rawKey;

    }//end projectSingleTranslatableKey()

    /**
     * Convenience: run an ad-hoc aggregation by register/schema ref.
     *
     * Mirrors {@see run()}'s ref-based call shape but for the ad-hoc
     * path. Lets the REST controller call without re-implementing the
     * mapper-lookup glue.
     *
     * @param string           $registerRef Register slug/uuid/id.
     * @param string           $schemaRef   Schema slug/uuid/id.
     * @param AggregationQuery $query       The fully-validated query.
     *
     * @return array<string, mixed> Result envelope (see runAdhoc()).
     *
     * @throws RuntimeException        When the register or schema cannot be resolved.
     * @throws NotAuthorizedException  When the caller lacks list-permission.
     *
     * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-2
     */
    public function runAdhocByRef(
        string $registerRef,
        string $schemaRef,
        AggregationQuery $query
    ): array {
        $schema   = $this->loadSchema(schemaRef: $schemaRef);
        $register = $this->loadRegister(registerRef: $registerRef);

        return $this->runAdhoc(register: $register, schema: $schema, query: $query);

    }//end runAdhocByRef()

    /**
     * Convenience: load a schema by ref. Public surface to let the
     * REST controller validate field allow-lists against
     * `Schema::getProperties()` before constructing the AggregationQuery
     * (we want a 400 from the validation layer, not a 404 from inside
     * runAdhocByRef()).
     *
     * @param string $schemaRef Schema slug/uuid/id.
     *
     * @return Schema The loaded schema.
     *
     * @throws RuntimeException When the schema can't be found.
     *
     * @spec exclude Thin public convenience wrapper over the private loadSchema mapper lookup; exposed only so
     *              the REST controller can validate field allow-lists before building an AggregationQuery.
     *              No business logic of its own.
     */
    public function findSchema(string $schemaRef): Schema
    {
        return $this->loadSchema(schemaRef: $schemaRef);

    }//end findSchema()

    /**
     * PHP fallback bucketer for non-Postgres databases (SQLite tests,
     * MySQL dev boxes).
     *
     * Pulls the RBAC-filtered row set via MagicMapper, applies the
     * filter clauses the native path would emit as SQL, then groups in
     * PHP using either the categorical groupBy field OR the dateBucket
     * gap polyfill (`strtotime` + `gmdate`).
     *
     * Marked `backend: "php-fallback"` in the response so callers know
     * the query did not hit a native engine.
     *
     * @param Register                  $register   Register.
     * @param Schema                    $schema     Schema.
     * @param string                    $metric     One of count/sum/avg/min/max.
     * @param string|null               $field      Metric field (null for count).
     * @param array<string, mixed>      $filter     Already placeholder-resolved filter map.
     * @param array<string, mixed>|null $groupBy    Optional categorical groupBy spec.
     * @param array<string, mixed>|null $dateBucket Optional time-bucket spec.
     *
     * @return array<string, mixed> Either `{value, backend, cached}` or
     *                              `{groups, backend, cached}` mirroring the
     *                              Postgres native-path shape.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *   The branching mirrors the Postgres SQL path: dateBucket vs.
     *   groupBy vs. ungrouped, each with the metric / filter pipeline.
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    private function bucketInPhp(
        Register $register,
        Schema $schema,
        string $metric,
        ?string $field,
        array $filter,
        ?array $groupBy,
        ?array $dateBucket
    ): array {
        $objects = $this->magicMapper->findAllInRegisterSchemaTable(
            register: $register,
            schema: $schema,
            limit: self::PHP_FALLBACK_ROW_CAP
        );

        $rows = [];
        foreach ($objects as $entity) {
            // The findAllInRegisterSchemaTable mapper returns ObjectEntity[];
            // getObject() resolves each row to the inner data array.
            $rows[] = $entity->getObject();
        }

        // Apply the filter clauses in PHP — same operator vocabulary
        // (in / gt / gte / lt / lte / ne) as the SQL path.
        $rows = $this->applyFilter(rows: $rows, filter: $filter);

        if ($dateBucket !== null) {
            // Restrict to rows whose bucket field falls in [start, end).
            $bucketField = (string) $dateBucket['field'];
            $start       = strtotime((string) $dateBucket['start']);
            $end         = strtotime((string) $dateBucket['end']);
            $gap         = (string) $dateBucket['gap'];
            $buckets     = [];

            foreach ($rows as $row) {
                $raw = ($row[$bucketField] ?? null);
                if ($raw === null) {
                    continue;
                }

                $stamp = strtotime((string) $raw);
                if (is_numeric($raw) === true) {
                    $stamp = (int) $raw;
                }

                if ($stamp === false || $stamp < $start || $stamp >= $end) {
                    continue;
                }

                $key = $this->truncateTimestamp(timestamp: $stamp, gap: $gap);
                $buckets[$key] ??= [];
                $buckets[$key][] = $row;
            }

            // Compute the metric per bucket.
            $groups = [];
            ksort($buckets);
            foreach ($buckets as $key => $bucketRows) {
                $groups[] = [
                    'key'   => $key,
                    'value' => $this->computeMetric(rows: $bucketRows, metric: $metric, field: $field),
                ];
            }

            return [
                'groups'  => $groups,
                'backend' => 'php-fallback',
                'cached'  => false,
            ];
        }//end if

        if ($groupBy !== null && isset($groupBy['field']) === true) {
            $groups = $this->computeGrouped(
                rows: $rows,
                metric: $metric,
                field: $field,
                groupField: (string) $groupBy['field']
            );

            return [
                'groups'  => $groups,
                'backend' => 'php-fallback',
                'cached'  => false,
            ];
        }

        return [
            'value'   => $this->computeMetric(rows: $rows, metric: $metric, field: $field),
            'backend' => 'php-fallback',
            'cached'  => false,
        ];

    }//end bucketInPhp()

    /**
     * Polyfill for Postgres `date_trunc($gap, ts)::text` over a Unix
     * timestamp. Returns an ISO-8601-UTC `Y-m-d\TH:i:s\Z` bucket label
     * keyed at the start of the gap.
     *
     * @param int    $timestamp Unix timestamp.
     * @param string $gap       One of AggregationQuery::DATE_BUCKET_GAPS.
     *
     * @return string ISO-8601-UTC bucket label at the gap-start.
     */
    private function truncateTimestamp(int $timestamp, string $gap): string
    {
        // `week` and `quarter` need bespoke handling below — the rest
        // map directly to a gmdate format string.
        $format = match ($gap) {
            'minute'  => 'Y-m-d\TH:i:00\Z',
            'hour'    => 'Y-m-d\TH:00:00\Z',
            'day'     => 'Y-m-d\T00:00:00\Z',
            'week'    => null,
            'month'   => 'Y-m-01\T00:00:00\Z',
            'quarter' => null,
            'year'    => 'Y-01-01\T00:00:00\Z',
            default   => 'Y-m-d\T00:00:00\Z',
        };

        if ($gap === 'week') {
            // ISO week starts Monday. Use gmdate('N') to find the day of
            // the week then back-shift.
            $dayOfWeek   = (int) gmdate('N', $timestamp);
            $weekStartTs = ($timestamp - (($dayOfWeek - 1) * 86400));
            return gmdate('Y-m-d\T00:00:00\Z', $weekStartTs);
        }

        if ($gap === 'quarter') {
            // Truncate to the first month of the quarter.
            $month  = (int) gmdate('n', $timestamp);
            $qStart = ((int) (($month - 1) / 3) * 3) + 1;
            $year   = (int) gmdate('Y', $timestamp);
            return sprintf('%04d-%02d-01T00:00:00Z', $year, $qStart);
        }

        return gmdate($format, $timestamp);

    }//end truncateTimestamp()

    /**
     * Compute a single scalar metric over the given rows.
     *
     * @param array<int, array<string, mixed>> $rows   Already-filtered rows.
     * @param string                           $metric One of count/sum/avg/min/max/count_distinct.
     * @param mixed                            $field  Field name to aggregate over (ignored for count).
     *
     * @return int|float|null The metric result, or null when no rows match.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    private function computeMetric(array $rows, string $metric, mixed $field): int|float|null
    {
        $sumReducer = fn(float $a, float $b) => $a + $b;
        $minReducer = fn(float $a, float $b) => min($a, $b);
        $maxReducer = fn(float $a, float $b) => max($a, $b);
        $distinct   = array_unique(
            array_filter(
                array_map(fn(array $r) => $r[(string) $field] ?? null, $rows),
                fn($v) => $v !== null
            ),
            SORT_REGULAR
        );

        return match ($metric) {
            'count'          => count($rows),
            'sum'            => $this->reduceNumeric(
                rows: $rows,
                field: (string) $field,
                reducer: $sumReducer,
                initial: 0.0
            ),
            'avg'            => $this->avg(rows: $rows, field: (string) $field),
            'min'            => $this->reduceNumeric(
                rows: $rows,
                field: (string) $field,
                reducer: $minReducer,
                initial: null
            ),
            'max'            => $this->reduceNumeric(
                rows: $rows,
                field: (string) $field,
                reducer: $maxReducer,
                initial: null
            ),
            'count_distinct' => count($distinct),
            default          => null,
        };//end match
    }//end computeMetric()

    /**
     * Compute a grouped metric, bucketing rows by `$groupField`.
     *
     * @param array<int, array<string, mixed>> $rows       Already-filtered rows.
     * @param string                           $metric     One of count/sum/avg/min/max/count_distinct.
     * @param mixed                            $field      Field to aggregate over.
     * @param string                           $groupField Field used as the bucket key.
     *
     * @return array<int, array{key: mixed, value: int|float|null}>
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    private function computeGrouped(array $rows, string $metric, mixed $field, string $groupField): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $bucket = $row[$groupField] ?? null;
            $key    = json_encode($bucket);
            if (is_scalar($bucket) === true) {
                $key = (string) $bucket;
            }

            if (isset($buckets[$key]) === false) {
                $buckets[$key] = ['key' => $bucket, 'rows' => []];
            }

            $buckets[$key]['rows'][] = $row;
        }

        $out = [];
        foreach ($buckets as $b) {
            $out[] = [
                'key'   => $b['key'],
                'value' => $this->computeMetric(rows: $b['rows'], metric: $metric, field: $field),
            ];
        }

        return $out;
    }//end computeGrouped()

    /**
     * Reduce a numeric column using the given binary reducer.
     *
     * @param array<int, array<string, mixed>> $rows    Rows to reduce.
     * @param string                           $field   Column to read.
     * @param callable                         $reducer Binary reducer applied to (acc, value).
     * @param mixed                            $initial Initial accumulator value (null is allowed).
     *
     * @return int|float|null The reduced value, or null when no numeric rows were seen.
     */
    private function reduceNumeric(array $rows, string $field, callable $reducer, mixed $initial): int|float|null
    {
        $acc   = $initial;
        $count = 0;
        foreach ($rows as $row) {
            $value = $row[$field] ?? null;
            if (is_numeric($value) === false) {
                continue;
            }

            $count++;
            if ($acc === null) {
                $acc = (float) $value;
                continue;
            }

            $acc = $reducer((float) $acc, (float) $value);
        }

        if ($count === 0 && $acc === null) {
            return null;
        }

        return $acc;
    }//end reduceNumeric()

    /**
     * Compute the arithmetic mean of a numeric column.
     *
     * @param array<int, array<string, mixed>> $rows  Rows to average.
     * @param string                           $field Column to read.
     *
     * @return float|null The mean, or null when no numeric rows were seen.
     */
    private function avg(array $rows, string $field): float|null
    {
        $sum   = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $value = $row[$field] ?? null;
            if (is_numeric($value) === false) {
                continue;
            }

            $sum += (float) $value;
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        return ($sum / $count);
    }//end avg()

    /**
     * Apply operator-style filters (gte/lte/gt/lt/in/ne) in PHP.
     *
     * @param array<int, array<string, mixed>> $rows   Rows to filter.
     * @param array<string, mixed>             $filter Filter map (scalar = eq, array = operator map).
     *
     * @return array<int, array<string, mixed>> Filtered rows.
     */
    private function applyFilter(array $rows, array $filter): array
    {
        $result = [];
        foreach ($rows as $row) {
            $keep = true;
            foreach ($filter as $field => $criterion) {
                $value = $row[$field] ?? null;
                if (is_array($criterion) === false) {
                    // BUG-SVC-9: magic-table column values come back as strings,
                    // while the criterion may be int/bool/float. A strict !==
                    // then drops rows the Postgres path would keep (e.g. "1" vs
                    // 1). Compare as strings when both sides are scalar so the
                    // PHP fallback matches the native equality semantics; keep
                    // strict comparison for non-scalars (null/array/object).
                    if (is_scalar($value) === true && is_scalar($criterion) === true) {
                        if ((string) $value !== (string) $criterion) {
                            $keep = false;
                            break;
                        }
                    } else if ($value !== $criterion) {
                        $keep = false;
                        break;
                    }

                    continue;
                }

                foreach ($criterion as $op => $opValue) {
                    if ($this->checkOp(value: $value, op: (string) $op, opValue: $opValue) === false) {
                        $keep = false;
                        break 2;
                    }
                }
            }//end foreach

            if ($keep === true) {
                $result[] = $row;
            }
        }//end foreach

        return $result;
    }//end applyFilter()

    /**
     * Apply a single operator check.
     *
     * @param mixed  $value   The value extracted from the row.
     * @param string $op      Operator name ('eq','ne','gt','gte','lt','lte','in','notIn').
     * @param mixed  $opValue The operand value to compare against.
     *
     * @return bool True when the value satisfies the operator.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function checkOp(mixed $value, string $op, mixed $opValue): bool
    {
        $cmp = $this->normaliseForCompare(v: $value);
        $rhs = $this->normaliseForCompare(v: $opValue);
        return match ($op) {
            'eq'    => $cmp === $rhs,
            'ne'    => $cmp !== $rhs,
            'gt'    => $cmp !== null && $rhs !== null && $cmp > $rhs,
            'gte'   => $cmp !== null && $rhs !== null && $cmp >= $rhs,
            'lt'    => $cmp !== null && $rhs !== null && $cmp < $rhs,
            'lte'   => $cmp !== null && $rhs !== null && $cmp <= $rhs,
            'in'    => is_array($opValue) === true && in_array($value, $opValue, true),
            'notIn' => is_array($opValue) === false || in_array($value, $opValue, true) === false,
            default => true,
        };
    }//end checkOp()

    /**
     * Coerce date-like scalars to integer timestamps for ordered comparisons.
     *
     * @param mixed $v The value to normalise.
     *
     * @return mixed Integer timestamp for date-like values, original otherwise.
     */
    private function normaliseForCompare(mixed $v): mixed
    {
        if ($v instanceof DateTimeInterface) {
            return $v->getTimestamp();
        }

        if (is_string($v) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1) {
            try {
                return (new DateTimeImmutable($v))->getTimestamp();
            } catch (\Throwable) {
                return $v;
            }
        }

        return $v;
    }//end normaliseForCompare()

    /**
     * Try to compute the aggregation directly in SQL on the magic table.
     *
     * Supports: count/sum/avg/min/max + simple equality filters
     * + optional groupBy on a single field. Postgres only.
     *
     * Returns the result fragment ('value' or 'groups') on success, null
     * to signal the caller should fall back to PHP-side aggregation.
     *
     * @param Register                  $register   Register the schema belongs to.
     * @param Schema                    $schema     Schema being aggregated.
     * @param string                    $metric     Metric name (count/sum/avg/min/max).
     * @param string|null               $field      Field to aggregate over (ignored for count).
     * @param array<string, mixed>      $filter     Already placeholder-resolved filter map.
     * @param array<string, mixed>|null $groupBy    Optional group spec ({field: ...}).
     * @param array<string, mixed>|null $dateBucket Optional time-bucket spec ({field, start, end, gap}).
     *                                              When supplied, the query becomes a `date_trunc`-bucketed
     *                                              series with explicit `WHERE field >= start AND field < end`
     *                                              bounds. Mutually exclusive with $groupBy (validated by
     *                                              AggregationQuery::create() upstream).
     *
     * @return array{value: int|float|null}|array{groups: array<int, array{key: mixed, value: int|float|null}>}|null
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.ElseExpression)
     *   The platform-branch is genuinely binary (postgres vs non-postgres
     *   for both the soft-delete predicate and the aggregate-cast block,
     *   mysql vs sqlite for the bucket expression). Else clauses make
     *   the mutual-exclusion read at the call site.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    private function tryNativeAggregation(
        Register $register,
        Schema $schema,
        string $metric,
        ?string $field,
        array $filter,
        ?array $groupBy,
        ?array $dateBucket=null
    ): ?array {
        $platformName = $this->detectDatabasePlatform();

        // Postgres handles every query shape natively. MySQL and SQLite
        // currently support only the time-bucket path; the categorical
        // groupBy + ungrouped paths still depend on Postgres-specific
        // operators (`::jsonb`, `::numeric`) and fall through to the PHP
        // fallback on non-Postgres engines.
        if ($platformName === 'unknown') {
            return null;
        }

        if ($platformName !== 'postgres' && $dateBucket === null) {
            return null;
        }

        if (in_array($metric, ['count', 'sum', 'avg', 'min', 'max'], true) === false) {
            return null;
        }

        // BUG-SVC-7: value metrics (sum/avg/min/max) operate on a column, so a
        // null/empty $field would build malformed SQL (e.g. SUM(NULLIF(::text,
        // ''))). Bail to the PHP fallback when no field is supplied for a
        // value metric; only `count` is valid without a field.
        if (in_array($metric, ['sum', 'avg', 'min', 'max'], true) === true
            && ($field === null || $field === '')
        ) {
            return null;
        }

        // Validate filter shapes are translatable. Supported:
        // {field: scalar}              → field = ?
        // {field: {in: [...]}}         → field IN (?, ?, ?)
        // {field: {notIn: [...]}}      → field NOT IN (?, ?, ?)
        // {field: {gt|gte|lt|lte: x}}  → field > / >= / < / <= ?
        // {field: {ne: x}}             → field <> ?
        // Reject anything else.
        foreach ($filter as $value) {
            if (is_array($value) === true) {
                foreach (array_keys($value) as $op) {
                    if (in_array((string) $op, ['in', 'notIn', 'gt', 'gte', 'lt', 'lte', 'ne'], true) === false) {
                        return null;
                    }
                }
            }
        }

        $tableName = $this->magicMapper->getTableNameForRegisterSchema(
            register: $register,
            schema: $schema
        );
        if ($tableName === null || $tableName === '') {
            return null;
        }

        $quote      = $this->identifierQuote(platform: $platformName);
        $fullTable  = $quote.'oc_'.$tableName.$quote;
        $whereParts = [];
        $bindings   = [];

        // Soft-delete predicate. Postgres uses jsonb; MySQL/SQLite store
        // the metadata cols as JSON-string text where the empty/null marker
        // is the literal string 'null' or actual NULL.
        if ($platformName === 'postgres') {
            $whereParts[] = "(_deleted IS NULL OR _deleted = 'null'::jsonb)";
        } else {
            $whereParts[] = "(_deleted IS NULL OR _deleted = 'null' OR _deleted = '')";
        }

        // SECURITY: mirror MagicRbacHandler's multi-tenancy predicate. The
        // native fast path bypasses MagicMapper entirely, so without this
        // filter any authed caller could compute aggregates over rows in
        // other tenants. Active org of `null` ⇒ no rows (fail-closed).
        // Column is `_organisation` — magic tables prefix metadata cols
        // with `_` (see MagicMapper::METADATA_PREFIX).
        $activeOrg    = $this->organisationService->getActiveOrganisation();
        $whereParts[] = $quote.'_organisation'.$quote.' = ?';
        $bindings[]   = $activeOrg?->getUuid() ?? '__no_active_org__';

        foreach ($filter as $f => $v) {
            $col = $this->sanitizeColumnName(name: (string) $f);
            if (is_array($v) === false) {
                $whereParts[] = $quote.$col.$quote.' = ?';
                $bindings[]   = $this->bindValue(value: $v);
                continue;
            }

            foreach ($v as $op => $opValue) {
                if ($op === 'in') {
                    $list = [];
                    if (is_array($opValue) === true) {
                        $list = $opValue;
                    }

                    if (count($list) === 0) {
                        // `in` with empty list never matches; emit a no-op
                        // condition that returns no rows.
                        $whereParts[] = '1 = 0';
                        continue;
                    }

                    $placeholders = implode(', ', array_fill(0, count($list), '?'));
                    $whereParts[] = $quote.$col.$quote.' IN ('.$placeholders.')';
                    foreach ($list as $item) {
                        $bindings[] = $this->bindValue(value: $item);
                    }

                    continue;
                }//end if

                if ($op === 'notIn') {
                    $list = [];
                    if (is_array($opValue) === true) {
                        $list = $opValue;
                    }

                    if (count($list) === 0) {
                        // `notIn` with an empty exclusion list excludes
                        // nothing — emit an always-true condition so every
                        // row is retained (mirrors SQL `NOT IN ()` intent).
                        $whereParts[] = '1 = 1';
                        continue;
                    }

                    $placeholders = implode(', ', array_fill(0, count($list), '?'));
                    $whereParts[] = $quote.$col.$quote.' NOT IN ('.$placeholders.')';
                    foreach ($list as $item) {
                        $bindings[] = $this->bindValue(value: $item);
                    }

                    continue;
                }//end if

                $sqlOp = match ((string) $op) {
                    'gt'  => '>',
                    'gte' => '>=',
                    'lt'  => '<',
                    'lte' => '<=',
                    'ne'  => '<>',
                    default => null,
                };

                if ($sqlOp === null) {
                    continue;
                }

                $whereParts[] = $quote.$col.$quote.' '.$sqlOp.' ?';
                $bindings[]   = $this->bindValue(value: $opValue);
            }//end foreach
        }//end foreach

        $whereSql = implode(' AND ', $whereParts);

        // Aggregate clause. Postgres needs explicit `::numeric` casts because
        // magic-table data columns are jsonb-shaped. MySQL/SQLite handle the
        // implicit conversion in the engine. NULLIF guards against empty
        // strings produced by jsonb-to-text casts.
        $aggCol = null;
        if ($field !== null) {
            $aggCol = $quote.$this->sanitizeColumnName(name: $field).$quote;
        }

        if ($platformName === 'postgres') {
            $aggSql = match ($metric) {
                'count' => 'COUNT(*)',
                'sum'   => 'SUM(NULLIF('.$aggCol.'::text, \'\')::numeric)',
                'avg'   => 'AVG(NULLIF('.$aggCol.'::text, \'\')::numeric)',
                'min'   => 'MIN(NULLIF('.$aggCol.'::text, \'\')::numeric)',
                'max'   => 'MAX(NULLIF('.$aggCol.'::text, \'\')::numeric)',
            };
        } else {
            $aggSql = match ($metric) {
                'count' => 'COUNT(*)',
                'sum'   => 'SUM(NULLIF('.$aggCol.', \'\') + 0)',
                'avg'   => 'AVG(NULLIF('.$aggCol.', \'\') + 0)',
                'min'   => 'MIN(NULLIF('.$aggCol.', \'\') + 0)',
                'max'   => 'MAX(NULLIF('.$aggCol.', \'\') + 0)',
            };
        }

        try {
            // Time-bucket path: emit a platform-specific bucketing
            // expression (date_trunc / DATE_FORMAT / strftime), add
            // `WHERE "$field" >= ? AND "$field" < ?` bounds from the
            // dateBucket spec, and group on the bucket. Mutual exclusion
            // with $groupBy is guaranteed by AggregationQuery::create()
            // upstream.
            if ($dateBucket !== null) {
                $bucketField = (string) $dateBucket['field'];
                $bucketCol   = $quote.$this->sanitizeColumnName(name: $bucketField).$quote;
                $gap         = (string) $dateBucket['gap'];

                $boundedWhere = $whereSql.' AND '.$bucketCol.' >= ? AND '.$bucketCol.' < ?';

                if ($platformName === 'postgres') {
                    // Prepend the bucket bounds to the binding list so
                    // they appear in placeholder order: first the gap
                    // (for the Postgres date_trunc call), then the
                    // existing WHERE clause bindings, then the dateBucket
                    // bounds.
                    $bindings[] = $this->bindValue(value: $dateBucket['start']);
                    $bindings[] = $this->bindValue(value: $dateBucket['end']);
                    array_unshift($bindings, $gap);
                    $bucketExpr = 'date_trunc(?, '.$bucketCol.')::text';
                } else {
                    // MySQL / SQLite emit literal format strings — the
                    // gap vocabulary is closed (seven entries) and
                    // validated upstream by AggregationQuery::create().
                    $bindings[] = $this->bindValue(value: $dateBucket['start']);
                    $bindings[] = $this->bindValue(value: $dateBucket['end']);
                    if ($platformName === 'mysql') {
                        $bucketExpr = $this->mysqlBucketExpression(column: $bucketCol, gap: $gap);
                    } else {
                        $bucketExpr = $this->sqliteBucketExpression(column: $bucketCol, gap: $gap);
                    }
                }//end if

                $sql  = "SELECT {$bucketExpr} AS bucket, {$aggSql} AS agg
                         FROM {$fullTable}
                         WHERE {$boundedWhere}
                         GROUP BY bucket
                         ORDER BY bucket";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($bindings);
                $groups = [];
                while (($row = $stmt->fetch()) !== false) {
                    $value = $row['agg'];
                    if ($metric !== 'count' && is_string($value) === true) {
                        $value = (float) $value;
                    } else if ($value !== null) {
                        $value = (int) $value;
                    }

                    $groups[] = [
                        'key'   => $this->coerceBucketKey(raw: $row['bucket']),
                        'value' => $value,
                    ];
                }

                return ['groups' => $groups];
            }//end if

            if ($groupBy !== null && isset($groupBy['field']) === true) {
                $groupCol = '"'.$this->sanitizeColumnName(name: (string) $groupBy['field']).'"';
                $sql      = "SELECT {$groupCol} AS bucket, {$aggSql} AS agg
                             FROM {$fullTable}
                             WHERE {$whereSql}
                             GROUP BY {$groupCol}";
                $stmt     = $this->db->prepare($sql);
                $stmt->execute($bindings);
                $groups = [];
                while (($row = $stmt->fetch()) !== false) {
                    $value = $row['agg'];
                    if ($metric !== 'count' && is_string($value) === true) {
                        $value = (float) $value;
                    } else if ($value !== null) {
                        $value = (int) $value;
                    }

                    $groups[] = ['key' => $row['bucket'], 'value' => $value];
                }

                return ['groups' => $groups];
            }//end if

            $sql  = "SELECT {$aggSql} AS agg FROM {$fullTable} WHERE {$whereSql}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);
            $row   = $stmt->fetch();
            $value = null;
            if ($row !== false) {
                $value = $row['agg'];
            }

            if ($metric === 'count') {
                return ['value' => (int) ($value ?? 0)];
            }

            if ($value === null) {
                return ['value' => null];
            }

            $floatValue = $value;
            if (is_string($value) === true) {
                $floatValue = (float) $value;
            }

            return ['value' => $floatValue];
        } catch (\Throwable $e) {
            // Native path failed (table not found, column not found, etc) —
            // tell the caller to fall back to PHP.
            return null;
        }//end try
    }//end tryNativeAggregation()

    /**
     * Coerce a Postgres date_trunc bucket label to a stable
     * ISO-8601-UTC string (`Y-m-d\TH:i:s\Z`).
     *
     * The Postgres `date_trunc()::text` cast returns values like
     * `2026-05-21 00:00:00+00`,
     * `2026-05-21 00:00:00` (depending on the column timezone), or
     * `2026-05-21 13:00:00`. We need a stable wire format that the
     * client can parse identically across Postgres versions / timezone
     * settings AND across the PHP-fallback path on non-Postgres
     * databases.
     *
     * @param mixed $raw Raw bucket value from the DB row (typically a string).
     *
     * @return string ISO-8601-UTC `Y-m-d\TH:i:s\Z` bucket label, or the
     *                original string when it can't be parsed (defensive).
     */
    private function coerceBucketKey(mixed $raw): string
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d\TH:i:s\Z');
        }

        if (is_string($raw) === false) {
            return (string) $raw;
        }

        // BUG-SVC-4: Postgres emits date/timestamp text WITHOUT a timezone
        // designator (e.g. "2026-06-01 00:00:00"). strtotime() parses such
        // offset-less text in the SERVER timezone, then gmdate() re-expresses
        // it as UTC, shifting every bucket label by the server's UTC offset
        // (e.g. CET buckets land an hour early). Parse offset-less shapes as
        // UTC explicitly; only fall back to strtotime for offset-bearing text.
        $hasTimezone = (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($raw)) === 1);
        if ($hasTimezone === false) {
            $formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d'];
            foreach ($formats as $format) {
                $parsed = DateTimeImmutable::createFromFormat($format, trim($raw), new DateTimeZone('UTC'));
                if ($parsed !== false) {
                    return $parsed->format('Y-m-d\TH:i:s\Z');
                }
            }
        }

        // Offset-bearing (or otherwise unhandled) shapes: strtotime understands
        // the embedded offset, so converting to UTC via gmdate is correct here.
        $stamp = strtotime($raw);
        if ($stamp === false) {
            return $raw;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $stamp);

    }//end coerceBucketKey()

    /**
     * Detect the active database platform short name.
     *
     * Returns one of `postgres`, `mysql`, `sqlite`, or `unknown`. Used to
     * pick the matching native-bucketing path in
     * {@see tryNativeAggregation()} and to annotate the response
     * `backend` field in {@see runAdhoc()}.
     *
     * @return string Lower-case platform short name.
     */
    private function detectDatabasePlatform(): string
    {
        $platformClass = $this->db->getDatabasePlatform()::class;
        if (stripos($platformClass, 'PostgreSQL') !== false) {
            return 'postgres';
        }

        if (stripos($platformClass, 'MySQL') !== false || stripos($platformClass, 'MariaDB') !== false) {
            return 'mysql';
        }

        if (stripos($platformClass, 'SQLite') !== false) {
            return 'sqlite';
        }

        return 'unknown';

    }//end detectDatabasePlatform()

    /**
     * Identifier-quoting character for the given platform.
     *
     * Postgres and SQLite use double-quotes; MySQL uses backticks.
     *
     * @param string $platform Platform short name from {@see detectDatabasePlatform()}.
     *
     * @return string The quoting character to wrap identifiers.
     */
    private function identifierQuote(string $platform): string
    {
        if ($platform === 'mysql') {
            return '`';
        }

        return '"';

    }//end identifierQuote()

    /**
     * Emit a MySQL `DATE_FORMAT`-based bucket expression for the given gap.
     *
     * The gap vocabulary is closed (seven entries) and validated upstream
     * by {@see AggregationQuery::create()}. The format strings produce
     * the canonical `Y-m-d\TH:i:s\Z` wire shape so the round-trip
     * through {@see coerceBucketKey()} is an identity transform on
     * MySQL output.
     *
     * @param string $column Identifier-quoted column reference.
     * @param string $gap    Validated gap unit.
     *
     * @return string The full bucket SQL expression (without the `AS bucket` alias).
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    private function mysqlBucketExpression(string $column, string $gap): string
    {
        if ($gap === 'week') {
            // ISO-Monday week-start: back-shift by ((DAYOFWEEK - 1 - 1) % 7)
            // days, but MySQL's DAYOFWEEK starts on Sunday=1 so the actual
            // expression is `((DAYOFWEEK(field) + 5) MOD 7)` which produces
            // 0 for Monday, 6 for Sunday — exactly the shift we need to
            // reach the previous (or current) Monday.
            return 'DATE_FORMAT('.$column.' - INTERVAL ((DAYOFWEEK('.$column.') + 5) MOD 7) DAY, \'%Y-%m-%dT00:00:00Z\')';
        }

        if ($gap === 'quarter') {
            return sprintf(
                'CONCAT(YEAR(%1$s), \'-\', LPAD(((QUARTER(%1$s) - 1) * 3 + 1), 2, \'0\'), \'-01T00:00:00Z\')',
                $column
            );
        }

        $format = match ($gap) {
            'minute' => '%Y-%m-%dT%H:%i:00Z',
            'hour'   => '%Y-%m-%dT%H:00:00Z',
            'day'    => '%Y-%m-%dT00:00:00Z',
            'month'  => '%Y-%m-01T00:00:00Z',
            'year'   => '%Y-01-01T00:00:00Z',
            default  => '%Y-%m-%dT00:00:00Z',
        };

        return 'DATE_FORMAT('.$column.', \''.$format.'\')';

    }//end mysqlBucketExpression()

    /**
     * Emit a SQLite `strftime`-based bucket expression for the given gap.
     *
     * Mirrors {@see mysqlBucketExpression()} for the SQLite strftime
     * vocabulary. The `weekday 0` modifier in SQLite jumps forward to
     * the next Sunday (UTC); subtracting six days yields the previous
     * Monday — matching ISO-week-start semantics.
     *
     * @param string $column Identifier-quoted column reference.
     * @param string $gap    Validated gap unit.
     *
     * @return string The full bucket SQL expression (without the `AS bucket` alias).
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    private function sqliteBucketExpression(string $column, string $gap): string
    {
        if ($gap === 'week') {
            return 'strftime(\'%Y-%m-%dT00:00:00Z\', '.$column.', \'weekday 0\', \'-6 days\')';
        }

        if ($gap === 'quarter') {
            $parts   = [];
            $parts[] = sprintf('CASE WHEN CAST(strftime(\'%%m\', %1$s) AS INTEGER) <= 3', $column);
            $parts[] = sprintf('THEN strftime(\'%%Y-01-01T00:00:00Z\', %1$s)', $column);
            $parts[] = sprintf('WHEN CAST(strftime(\'%%m\', %1$s) AS INTEGER) <= 6', $column);
            $parts[] = sprintf('THEN strftime(\'%%Y-04-01T00:00:00Z\', %1$s)', $column);
            $parts[] = sprintf('WHEN CAST(strftime(\'%%m\', %1$s) AS INTEGER) <= 9', $column);
            $parts[] = sprintf('THEN strftime(\'%%Y-07-01T00:00:00Z\', %1$s)', $column);
            $parts[] = sprintf('ELSE strftime(\'%%Y-10-01T00:00:00Z\', %1$s) END', $column);
            return implode(' ', $parts);
        }

        $format = match ($gap) {
            'minute' => '%Y-%m-%dT%H:%M:00Z',
            'hour'   => '%Y-%m-%dT%H:00:00Z',
            'day'    => '%Y-%m-%dT00:00:00Z',
            'month'  => '%Y-%m-01T00:00:00Z',
            'year'   => '%Y-01-01T00:00:00Z',
            default  => '%Y-%m-%dT00:00:00Z',
        };

        return 'strftime(\''.$format.'\', '.$column.')';

    }//end sqliteBucketExpression()

    /**
     * Convert a value to its SQL bind shape.
     *
     * DateTimeImmutable values come from the PlaceholderResolver
     * (e.g. `$startOfMonth`) — coerce them to ISO-8601 so they bind
     * cleanly against text/date columns. Other values pass through
     * as strings.
     *
     * @param mixed $value Raw value to bind.
     *
     * @return string SQL-ready string representation.
     */
    private function bindValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_bool($value) === true) {
            if ($value === true) {
                return 'true';
            }

            return 'false';
        }

        return (string) $value;
    }//end bindValue()

    /**
     * Convert a property name to its magic-table column name. Mirrors
     * MagicMapper::sanitizeColumnName so we don't expose a public API there.
     *
     * @param string $name Raw property name.
     *
     * @return string Sanitised column name.
     */
    private function sanitizeColumnName(string $name): string
    {
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $name = strtolower((string) $name);
        $name = preg_replace('/[^a-z0-9_]/', '_', (string) $name);
        if (preg_match('/^[a-z_]/', $name) === 0) {
            $name = 'col_'.$name;
        }

        $name = preg_replace('/_+/', '_', $name);
        return rtrim((string) $name, '_');
    }//end sanitizeColumnName()

    /**
     * Execute a cross-schema aggregation.
     *
     * Called by `run()` when the aggregation spec declares a `from` key.
     * Loads the *target* schema (the one named by `from`), finds its
     * register, applies RBAC, resolves `@self.<field>` references in the
     * `where` clause against the parent object row, then delegates to the
     * same three-path pipeline (external → Postgres-native → PHP fallback)
     * that the intra-schema path uses.
     *
     * Security notes
     * - The parent schema's RBAC gate already ran in `run()` before this
     *   method is called.
     * - An additional RBAC gate is applied here on the *target* schema so
     *   a caller cannot use a cross-schema aggregation to leak counts from
     *   a schema it is not allowed to list.
     * - The active-organisation predicate is carried into `tryNativeAggregation()`
     *   unchanged; the target table belongs to the same tenant.
     *
     * @param Register             $parentRegister The register that owns the *parent* schema.
     * @param Schema               $parentSchema   The schema whose annotation we read.
     * @param string               $name           Aggregation name.
     * @param array<string, mixed> $spec           The raw aggregation spec from the annotation.
     * @param array<string, mixed> $parentRow      Parent object fields for `@self.*` resolution.
     * @param bool                 $bypassRbac     Whether to skip the RBAC gate on the target schema.
     *
     * @return array<string, mixed> Aggregation result envelope.
     *
     * @throws RuntimeException When the target schema/register cannot be resolved or RBAC fails.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function runCrossSchema(
        Register $parentRegister,
        Schema $parentSchema,
        string $name,
        array $spec,
        array $parentRow,
        bool $bypassRbac
    ): array {
        $fromRef = (string) ($spec['from'] ?? '');
        if ($fromRef === '') {
            throw new RuntimeException('Cross-schema aggregation spec is missing a non-empty `from` key.');
        }

        // Resolve `select` / `metric` alias.
        $metric = (string) ($spec['metric'] ?? $spec['select'] ?? 'count');
        $field  = ($spec['field'] ?? null);
        // Resolve `where` / `filter` alias.
        $rawWhere = (array) ($spec['where'] ?? $spec['filter'] ?? []);
        $groupBy  = ($spec['groupBy'] ?? null);

        // Load the target schema.
        $targetSchema = $this->loadSchema(schemaRef: $fromRef);

        // SECURITY: gate on list permission for the *target* schema so a
        // cross-schema aggregation cannot leak counts from a schema the
        // caller is not allowed to list.
        //
        // `objectOwner: null` is intentional here: this is a list-level
        // check across the entire target schema (the aggregation hasn't
        // selected any specific row yet), so there is no single
        // owner-context to gate against. The owner-specific branch of
        // permissionHandler->hasPermission() only applies when both
        // $userId and $objectOwner are non-null. The corresponding
        // `object: null` reinforces this: PermissionHandler treats the
        // (null, null) pair as the list-level form.
        $userId = $this->userSession->getUser()?->getUID();
        if ($bypassRbac === false && $this->permissionHandler->hasPermission(
            schema: $targetSchema,
            action: 'list',
            userId: $userId,
            objectOwner: null,
            _rbac: true,
            object: null
        ) === false
        ) {
            throw new RuntimeException(
                sprintf(
                    'Forbidden: caller lacks list permission on cross-schema target "%s".',
                    $fromRef
                )
            );
        }

        // Find the register that owns the target schema.
        $targetRegister = $this->findRegisterForSchema(schema: $targetSchema);
        if ($targetRegister === null) {
            throw new RuntimeException(
                sprintf('No register found that contains schema "%s".', $fromRef)
            );
        }

        // Resolve `@self.<field>` references against the parent row, then
        // apply placeholder resolution ($now, $currentUser, etc.).
        $resolvedWhere = $this->resolveAtSelfReferences(
            where: $rawWhere,
            parentRow: $parentRow
        );
        $resolvedWhere = $this->placeholders->resolveArray($resolvedWhere);

        // Build the cache key, including the parent row values that were
        // substituted so two parent objects with different field values
        // cache independently.
        $activeOrg = $this->organisationService->getActiveOrganisation();
        $cacheKey  = [
            'cross'   => true,
            'from'    => $fromRef,
            'metric'  => $metric,
            'field'   => $field,
            'where'   => $resolvedWhere,
            'groupBy' => $groupBy,
            'userId'  => $userId,
            'org'     => $activeOrg?->getUuid(),
        ];

        $cached = $this->cache->get(
            registerSlug: (string) $parentRegister->getSlug(),
            schemaSlug: (string) $parentSchema->getSlug(),
            name: $name,
            filter: $cacheKey
        );
        if ($cached !== null) {
            $cached['cached'] = true;
            return $cached;
        }

        // Attempt Postgres-native aggregation on the target schema table.
        $crossFieldArg = null;
        if (is_string($field) === true) {
            $crossFieldArg = $field;
        }

        $crossGroupByArg = null;
        if (is_array($groupBy) === true) {
            $crossGroupByArg = $groupBy;
        }

        $native = $this->tryNativeAggregation(
            register: $targetRegister,
            schema: $targetSchema,
            metric: $metric,
            field: $crossFieldArg,
            filter: $resolvedWhere,
            groupBy: $crossGroupByArg
        );

        if ($native !== null) {
            $crossNativeField = null;
            if (is_string($field) === true) {
                $crossNativeField = $field;
            }

            $result = [
                'name'      => $name,
                'metric'    => $metric,
                'field'     => $crossNativeField,
                'from'      => $fromRef,
                'backend'   => 'postgres',
                'truncated' => false,
            ] + $native;

            $this->cache->set(
                registerSlug: (string) $parentRegister->getSlug(),
                schemaSlug: (string) $parentSchema->getSlug(),
                name: $name,
                filter: $cacheKey,
                result: $result
            );
            return $result;
        }//end if

        // PHP fallback path for the target schema.
        $objects   = $this->magicMapper->findAllInRegisterSchemaTable(
            register: $targetRegister,
            schema: $targetSchema,
            limit: self::PHP_FALLBACK_ROW_CAP
        );
        $truncated = count($objects) >= self::PHP_FALLBACK_ROW_CAP;

        $rows = [];
        foreach ($objects as $entity) {
            // The findAllInRegisterSchemaTable mapper returns ObjectEntity[];
            // getObject() resolves each row to the inner data array.
            $rows[] = $entity->getObject();
        }

        $rows = $this->applyFilter(rows: $rows, filter: $resolvedWhere);

        $crossPhpField = null;
        if (is_string($field) === true) {
            $crossPhpField = $field;
        }

        $result = [
            'name'      => $name,
            'metric'    => $metric,
            'field'     => $crossPhpField,
            'from'      => $fromRef,
            'backend'   => 'php-fallback',
            'truncated' => $truncated,
        ];

        if (is_array($groupBy) === true && isset($groupBy['field']) === true) {
            $result['groups'] = $this->computeGrouped(
                rows: $rows,
                metric: $metric,
                field: $field,
                groupField: (string) $groupBy['field']
            );
        }

        if (isset($result['groups']) === false) {
            $result['value'] = $this->computeMetric(rows: $rows, metric: $metric, field: $field);
        }

        $this->cache->set(
            registerSlug: (string) $parentRegister->getSlug(),
            schemaSlug: (string) $parentSchema->getSlug(),
            name: $name,
            filter: $cacheKey,
            result: $result
        );
        return $result;
    }//end runCrossSchema()

    /**
     * Resolve `@self.<field>` references in a `where` clause against the parent row.
     *
     * Given a where map such as:
     *   `{ "regulationSlug": "@self.slug", "mandatory": true }`
     * and a parent row `{ "slug": "abc" }`, the method returns:
     *   `{ "regulationSlug": "abc", "mandatory": true }`
     *
     * References are resolved recursively so they work inside operator
     * maps (`{ "ne": "@self.slug" }`).  Unknown `@self.<field>` references
     * (i.e. the field is absent in `$parentRow`) are replaced with `null`,
     * which causes the filter to match nothing — fail-closed behaviour.
     *
     * @param array<string, mixed> $where     Raw where/filter clause from the aggregation spec.
     * @param array<string, mixed> $parentRow Parent object's field values.
     *
     * @return array<string, mixed> Where clause with `@self.*` references resolved.
     */
    private function resolveAtSelfReferences(array $where, array $parentRow): array
    {
        $resolved = [];
        foreach ($where as $key => $value) {
            if (is_array($value) === true) {
                $resolved[$key] = $this->resolveAtSelfReferences(
                    where: $value,
                    parentRow: $parentRow
                );
                continue;
            }

            if (is_string($value) === true && str_starts_with($value, '@self.') === true) {
                // Strip the leading '@self.' prefix (6 characters) to get the field name.
                $fieldName      = substr($value, 6);
                $resolved[$key] = ($parentRow[$fieldName] ?? null);
                continue;
            }

            $resolved[$key] = $value;
        }//end foreach

        return $resolved;
    }//end resolveAtSelfReferences()

    /**
     * Find the first register that contains the given schema.
     *
     * Iterates over all registers visible to the current organisation
     * (multitenancy respected) and returns the first one whose `schemas`
     * list includes the schema's integer ID.  Returns null when no match
     * is found (schema is not attached to any register).
     *
     * Performance: the result is not cached here; callers that need to
     * invoke this repeatedly should consider their own request-scoped
     * caching.  In practice this method is called at most once per
     * cross-schema aggregation request.
     *
     * @param Schema $schema The target schema to find a register for.
     *
     * @return Register|null The first matching register, or null.
     */
    private function findRegisterForSchema(Schema $schema): ?Register
    {
        $schemaId = $schema->getId();
        if ($schemaId === null) {
            return null;
        }

        // Metadata-read bypass per auth-system "Schema and register
        // METADATA-READ lookups MUST bypass multi-tenancy". findRegisterForSchema
        // resolves which register contains a given schema definition — a
        // metadata read, not an object-data scan. Catalog-wide visibility
        // is consistent with loadSchema/loadRegister.
        $registers = $this->registerMapper->findAll(_multitenancy: false);
        foreach ($registers as $register) {
            $schemaIds = $register->getSchemas();
            if (is_array($schemaIds) === false) {
                continue;
            }

            // Normalise both sides to int before strict comparison: the
            // schema id is canonically an int and `register->getSchemas()`
            // may return string representations (legacy rows / DB drivers).
            // Strict in_array with mixed-type members would silently miss
            // a match and steer aggregation at the wrong register.
            $normalised = array_map(static fn($id) => (int) $id, $schemaIds);
            if (in_array((int) $schemaId, $normalised, true) === true) {
                return $register;
            }
        }

        return null;
    }//end findRegisterForSchema()

    /**
     * Load a schema by ref, throwing a RuntimeException when missing.
     *
     * @param string $schemaRef Schema slug/uuid/id.
     *
     * @return Schema The loaded schema.
     *
     * @throws RuntimeException When the schema can't be found.
     */
    private function loadSchema(string $schemaRef): Schema
    {
        try {
            // Metadata-read bypass per auth-system "Schema and register
            // METADATA-READ lookups MUST bypass multi-tenancy". Tenant
            // isolation lives at the object-row level via MultiTenancyTrait
            // on MagicMapper; schema definitions are a globally-visible
            // catalog (precedent: SchemasController::index/show, @PublicPage
            // + _multitenancy: false). Aggregations resolve the schema by
            // ref to find its annotations; the subsequent object-row scan
            // remains tenant-filtered by the existing object-row policy.
            return $this->schemaMapper->find($schemaRef, _multitenancy: false);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Schema "%s" not found.', $schemaRef), 0, $e);
        }
    }//end loadSchema()

    /**
     * Load a register by ref, throwing a RuntimeException when missing.
     *
     * @param string $registerRef Register slug/uuid/id.
     *
     * @return Register The loaded register.
     *
     * @throws RuntimeException When the register can't be found.
     */
    private function loadRegister(string $registerRef): \OCA\OpenRegister\Db\Register
    {
        try {
            // Metadata-read bypass per auth-system "Schema and register
            // METADATA-READ lookups MUST bypass multi-tenancy" — see the
            // same rationale on loadSchema(). Register definitions are part
            // of the same globally-visible catalog.
            return $this->registerMapper->find($registerRef, _multitenancy: false);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Register "%s" not found.', $registerRef), 0, $e);
        }
    }//end loadRegister()

    /**
     * Read the `x-openregister-aggregations` annotation off a schema.
     *
     * @param Schema $schema The schema to read.
     *
     * @return array<string, mixed>|null The annotation map, or null when absent.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
     */
    private function getAnnotation(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-aggregations'] ?? null);
        if (is_array($value) === true) {
            return $value;
        }

        return null;
    }//end getAnnotation()
}//end class
