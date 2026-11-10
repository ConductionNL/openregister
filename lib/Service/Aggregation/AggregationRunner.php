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
 * @spec openspec/specs/aggregations-backend-native/spec.md
 * @spec openspec/specs/aggregations-backend-native/spec.md
 * @spec openspec/specs/aggregations-backend-native/spec.md
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait;
use OCA\OpenRegister\Db\MagicMapper\OrganisationlessAdmission;
use OCA\OpenRegister\Db\MagicMapper\OrganisationScopeResolverInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\AggregateVisibility;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\DraftListLens;
use OCA\OpenRegister\Service\Object\DraftListing;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolverInterface;
use OCA\OpenRegister\Support\NullFilter;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCacheInterface;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationSlot;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;

/**
 * Runs a named aggregation against a schema.
 *
 * `AggValues` exists only to keep the multi-metric `@return` inside the
 * 150-character limit. php-cs-fixer aligns a wrapped `@return` continuation to
 * the column after the type on the first line, so a long shape pushes its own
 * continuation past the limit; naming the repeated inner shape shortens both.
 * The union is deliberately left wrapped rather than joined onto one line —
 * joining it changes what PHPStan resolves and surfaces unrelated pre-existing
 * type debt, which is not this commit's business.
 *
 * @phpstan-type AggValues array<string, int|float|null>
 * @psalm-type AggValues = array<string, int|float|null>
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
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
class AggregationRunner implements AggregationRunnerInterface {
	use MagicColumnNameTrait;

	/**
	 * An organisation scope the native SQL cannot render: the boundary has a
	 * branch for the caller's conditional `crossOrganisation` rules.
	 *
	 * @var string
	 */
	private const SCOPE_CROSS_ORGANISATION_BRANCH = 'cross-organisation-branch';

	/**
	 * Hard cap on the number of rows the PHP fallback path will hydrate
	 * per request. The native Postgres path (tryNativeAggregation) does
	 * the work in SQL and is unaffected. Picked at 10 000 to keep peak
	 * memory bounded against an authenticated-caller request flood that
	 * varies the filter to bypass the AggregationCache.
	 */
	private const PHP_FALLBACK_ROW_CAP = 10000;

	/**
	 * Metrics a `join.select` entry may reduce the joined column with.
	 * Deliberately the same closed vocabulary the rest of the engine
	 * accepts — a join is an aggregation over a second schema, not a new
	 * metric surface. `count_distinct` has no native SQL translation and
	 * bails the whole join to the PHP fallback (see aggregateJoinedSchema()).
	 */
	private const JOIN_SELECT_METRICS = ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'];

	/**
	 * Magic-table mapper used for the PHP fallback path.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $magicMapper;

	/**
	 * Register loader.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema loader.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * The shared register-scoped schema resolver.
	 *
	 * Built here rather than injected: it is a stateless collaborator over the
	 * `RegisterMapper` + `SchemaMapper` this class already holds, so constructing
	 * it directly keeps every existing unit test — all of which mock those two
	 * mappers — exercising the REAL resolution path instead of a mock of the very
	 * thing under test.
	 *
	 * @var RegisterScopedSchemaResolver
	 */
	private readonly RegisterScopedSchemaResolver $scopedResolver;

	/**
	 * Evaluates derived (`metric: expression`) metrics over the aliases of the
	 * metrics computed alongside them.
	 *
	 * Constructed here rather than injected: it is a pure evaluator with no
	 * collaborators, and adding a required constructor argument would change
	 * the signature every existing caller and test already builds.
	 *
	 * @var MetricExpressionEvaluator
	 */
	private readonly MetricExpressionEvaluator $expressions;

	/**
	 * The operator checks of the PHP filter, built on first use.
	 *
	 * @var array<string, \Closure(mixed, mixed): bool>|null
	 */
	private ?array $operatorChecks = null;

	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface $catalog The register and schema loaders and the magic-table mapper (PHP fallback path).
	 * @param PlaceholderResolverInterface $placeholders Resolves dynamic placeholders inside filters.
	 * @param IDBConnection $db Database connection for the Postgres-native fast path.
	 * @param AggregationCacheInterface $cache 60s aggregation result cache.
	 * @param PermissionHandlerInterface $permissionHandler RBAC verdict on the schema's `list` action.
	 * @param IUserSession $userSession Active session, for the RBAC + cache-key user scope.
	 * @param OrganisationServiceInterface $organisationService Active-organisation lookup for the cache key.
	 * @param OrganisationScopeResolverInterface $organizationHandler Owns the organisation-boundary decision the native SQL renders.
	 * @param TranslationHandler $translationHandler Resolves translatable group keys to the negotiated language.
	 * @param LanguageService $languageService Request-scoped language negotiation (Accept-Language / _lang).
	 * @param LoggerInterface|null $logger Optional logger for diagnostics.
	 * @param DbalObjectSourceProvider|null $dbalSourceProvider Provider that computes aggregations live against an
	 *                                                          external DBAL virtual register; null disables the
	 *                                                          DBAL path.
	 * @param PropertyRbacHandler|null $propertyRbac Withholds an aggregation over a property the caller may
	 *                                               not read. Nullable and last so no construction site
	 *                                               shifts; absent, a governed property is withheld.
	 * @param ExplicitReveal|null $explicitReveal The request's trusted-code state for explicit-reveal properties;
	 *                                            absent, only a system operation may aggregate over one.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		private readonly PlaceholderResolverInterface $placeholders,
		private readonly IDBConnection $db,
		private readonly AggregationCacheInterface $cache,
		private readonly PermissionHandlerInterface $permissionHandler,
		private readonly IUserSession $userSession,
		private readonly OrganisationServiceInterface $organisationService,
		private readonly OrganisationScopeResolverInterface $organizationHandler,
		private readonly TranslationHandler $translationHandler,
		private readonly LanguageService $languageService,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?DbalObjectSourceProvider $dbalSourceProvider = null,
		// LAST AND NULLABLE so every existing construction keeps working. The
		// container always supplies it; null happens only in a hand-built test,
		// and then a GOVERNED property's aggregate is withheld, which is the
		// safe direction.
		private readonly ?PropertyRbacHandler $propertyRbac = null,
		private readonly ?ExplicitReveal $explicitReveal = null,
	) {
		$this->magicMapper    = $catalog->tables();
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper   = $catalog->schemas();
		$this->scopedResolver = new RegisterScopedSchemaResolver(
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper
		);
		$this->expressions = new MetricExpressionEvaluator();
	}//end __construct()


	/**
	 * Run the named aggregation on the given (register, schema).
	 *
	 * When the aggregation spec declares a `from` key the call is
	 * transparently delegated to {@see runCrossSchema()}: the named
	 * schema is the aggregation *source*, the current schema supplies
	 * the optional `@self.*` parent-reference values via `$parentRow`.
	 *
	 * @param NamedAggregationRef          $ref     Register, schema and aggregation name, as the request names them.
	 * @param NamedAggregationOptions|null $options The read-gate mode, parent row and narrowing filter; null runs
	 *                                              gated, without a parent row or extra filter. bypassRbac is
	 *                                              true ONLY from non-controller callers that already hold an
	 *                                              authoritative reason to compute the aggregation (report
	 *                                              rendering for a viewer with dashboard read, threshold
	 *                                              listeners reacting to a write event). The extra filter can
	 *                                              add a constraint but never relax a declared scoping one:
	 *                                              see mergeNarrowingFilter().
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function run(NamedAggregationRef $ref, ?NamedAggregationOptions $options=null): array {
		$options ??= new NamedAggregationOptions(bypassRbac: false);
		$run = $this->namedRun(ref: $ref->registerSchema(), name: $ref->name);
		$run->bypassRbac  = $options->bypassRbac;
		$run->extraFilter = $options->extraFilter;
		return $this->runNamed(run: $run, parentRow: $options->parentRow);
	}//end run()

	/**
	 * Start a named aggregation run on the schema the register carries.
	 *
	 * REGISTER-SCOPED RESOLUTION. The register is the boundary and is
	 * resolved FIRST; the schema ref is then matched only among the schemas
	 * that register carries. See loadSchemaInRegister().
	 *
	 * @param RegisterSchemaRef $ref  Register and schema slug/uuid/id.
	 * @param string            $name Aggregation name (key in the annotation).
	 *
	 * @return NamedAggregationRun
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function namedRun(RegisterSchemaRef $ref, string $name): NamedAggregationRun {
		$schemaRef = (string) $ref->schema;
		$pair      = $this->loadSchemaInRegister(schemaRef: $schemaRef, registerRef: (string) $ref->register);
		$run       = new NamedAggregationRun(register: $pair['register'], schema: $pair['schema'], name: $name);
		$run->schemaRef = $schemaRef;
		return $run;
	}//end namedRun()

	/**
	 * Gate the run, then compute it intra-schema or, with a `from` key, cross-schema.
	 *
	 * @param NamedAggregationRun  $run       The run, with its bypassRbac and extra filter set.
	 * @param array<string, mixed> $parentRow Parent object's field values for `@self.<field>` references.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function runNamed(NamedAggregationRun $run, array $parentRow): array {
		$run->spec = $this->authorisedSpec(run: $run);
		if ($run->getFromSchema() !== null) {
			return $this->runNamedCrossSchema(run: $run, parentRow: $parentRow);
		}

		return $this->runIntraSchema(run: $run);
	}//end runNamed()

	/**
	 * Gate the run behind READ on the schema, then return the declared spec.
	 *
	 * SECURITY: the gate runs before any native or fallback path (see
	 * mayAggregate(): read, or a declared `list`, or a conditional read rule
	 * the caller qualifies for). NotAuthorizedException maps to HTTP 403 in
	 * AggregationController; a RuntimeException would mask it as 404. The
	 * gate decides WHETHER the caller may aggregate; WHICH rows are counted is
	 * the read rule's row predicate. Internal callers pass `bypassRbac`.
	 *
	 * @param NamedAggregationRun $run The run; its userId is set here.
	 *
	 * @return array<string, mixed> The declared aggregation spec.
	 *
	 * @throws NotAuthorizedException When the caller may not aggregate the schema.
	 * @throws RuntimeException When the schema/aggregation is missing.
	 */
	private function authorisedSpec(NamedAggregationRun $run): array {
		$run->userId = $this->userSession->getUser()?->getUID();
		if ($run->bypassRbac === false && $this->mayAggregate(schema: $run->schema, userId: $run->userId) === false) {
			throw new NotAuthorizedException(
				message: sprintf('You do not have permission to aggregate schema "%s".', $run->schemaRef)
			);
		}

		return $this->declaredSpec(run: $run);
	}//end authorisedSpec()

	/**
	 * The spec the schema declares under the run's name.
	 *
	 * @param NamedAggregationRun $run The run.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the schema/aggregation is missing.
	 */
	private function declaredSpec(NamedAggregationRun $run): array {
		$annotation = $this->getAnnotation(schema: $run->schema);
		if ($annotation === null) {
			throw new RuntimeException(
				sprintf('Schema "%s" does not declare x-openregister-aggregations.', $run->schemaRef)
			);
		}

		if (isset($annotation[$run->name]) === false || is_array($annotation[$run->name]) === false) {
			throw new RuntimeException(sprintf('Aggregation "%s" is not declared on this schema.', $run->name));
		}

		return $annotation[$run->name];
	}//end declaredSpec()

	/**
	 * Delegate a spec that declares `from` to runCrossSchema(), after the gates of run().
	 *
	 * @param NamedAggregationRun  $run       The run.
	 * @param array<string, mixed> $parentRow Parent values for `@self.<field>` references.
	 *
	 * @return array<string, mixed>
	 */
	private function runNamedCrossSchema(NamedAggregationRun $run, array $parentRow): array {
		return $this->runCrossSchema(run: $run, parentRow: $parentRow);
	}//end runNamedCrossSchema()

	/**
	 * Run an intra-schema spec: guards, filter, cache, then native or PHP fallback.
	 *
	 * @param NamedAggregationRun $run The run.
	 *
	 * @return array<string, mixed>
	 */
	private function runIntraSchema(NamedAggregationRun $run): array {
		$this->guardTouchedFields(run: $run);
		$this->resolveRunFilter(run: $run);
		$run->cacheKey = $this->namedCacheKey(run: $run);
		$cached = $this->cachedNamedResult(run: $run);
		if ($cached !== null) {
			return $cached;
		}

		return ($this->nativeNamedResult(run: $run) ?? $this->fallbackNamedResult(run: $run));
	}//end runIntraSchema()

	/**
	 * Refuse a run that touches a property the caller may not see.
	 *
	 * The schema gate is row access; a SUM over a property nobody may read IS
	 * that property's total, and groupBy keys are its distinct values. The
	 * explicit-reveal guards hold with `bypassRbac` too: a bypassing caller
	 * stores or shows the figure for somebody else. A narrowing filter on an
	 * explicit-reveal property is an oracle.
	 *
	 * @param NamedAggregationRun $run The run.
	 *
	 * @return void
	 */
	private function guardTouchedFields(NamedAggregationRun $run): void {
		$touched = array_merge(
			$run->getFieldNames(),
			$this->groupByFields(groupBy: $run->getGroupBy()),
			$this->metricFields(metrics: $run->getMetrics())
		);
		$this->assertNoExplicitValues(schema: $run->schema, fields: $touched);
		$this->assertNoExplicitFilter(schema: $run->schema, filter: $run->extraFilter);
		if ($run->bypassRbac === false) {
			$this->assertFieldsAreReadable(schema: $run->schema, fields: $touched);
		}
	}//end guardTouchedFields()

	/**
	 * Resolve the declared filter and merge the caller's narrowing into it.
	 *
	 * A caller may ADD constraints and never relax one (see
	 * mergeNarrowingFilter()). The applied extra filter is taken from the
	 * merge result, not the request, so a dropped key is not forwarded to
	 * the join.
	 *
	 * @param NamedAggregationRun $run The run; filter and appliedExtraFilter are set here.
	 *
	 * @return void
	 */
	private function resolveRunFilter(NamedAggregationRun $run): void {
		$declared = $this->placeholders->resolveArray($run->getDeclaredFilter());
		$run->filter = $this->mergeNarrowingFilter(
			declared: $declared,
			narrowing: new RequestedNarrowing(constraints: $run->extraFilter, aggregation: $run->name)
		);
		$run->appliedExtraFilter = array_diff_key($run->filter, $declared);
	}//end resolveRunFilter()

	/**
	 * The cache key of a run.
	 *
	 * SECURITY: the key MUST include userId and the active organisation, or two
	 * callers with different RBAC verdicts read each other's results. `metrics`,
	 * `groupBy` and `join` change which figures and which rows the result holds.
	 *
	 * @param NamedAggregationRun $run The run.
	 *
	 * @return array<string, mixed>
	 */
	private function namedCacheKey(NamedAggregationRun $run): array {
		$activeOrg = $this->organisationService->getActiveOrganisation();
		return [
			'metric' => $run->getMetric(),
			'metrics' => $run->getMetrics(),
			'field' => $run->getField(),
			'filter' => $run->filter,
			'groupBy' => $run->getGroupBy(),
			'join' => $run->getJoin(),
			'userId' => $run->userId,
			'org' => $activeOrg?->getUuid(),
		];
	}//end namedCacheKey()

	/**
	 * The cached result of a run, projected, or null on a miss.
	 *
	 * @param NamedAggregationRun $run The run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cachedNamedResult(NamedAggregationRun $run): ?array {
		$cached = $this->cache->get(
			slot: new AggregationSlot(
				registerSlug: (string)$run->register->getSlug(),
				schemaSlug: (string)$run->schema->getSlug(),
				name: $run->name,
				filter: $run->cacheKey
			)
		);
		if ($cached === null) {
			return null;
		}

		$cached['cached'] = true;
		return $this->projectNamedGroupKeys(run: $run, envelope: $cached);
	}//end cachedNamedResult()

	/**
	 * Store a run's result and return it with translatable group keys projected.
	 *
	 * The cache holds the raw, language-agnostic group keys.
	 *
	 * @param NamedAggregationRun  $run    The run.
	 * @param array<string, mixed> $result The computed envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function storeNamedResult(NamedAggregationRun $run, array $result): array {
		$this->cache->set(
			slot: new AggregationSlot(
				registerSlug: (string)$run->register->getSlug(),
				schemaSlug: (string)$run->schema->getSlug(),
				name: $run->name,
				filter: $run->cacheKey
			),
			result: $result
		);
		return $this->projectNamedGroupKeys(run: $run, envelope: $result);
	}//end storeNamedResult()

	/**
	 * Project translatable group keys of a run's envelope.
	 *
	 * @param NamedAggregationRun  $run      The run.
	 * @param array<string, mixed> $envelope The envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function projectNamedGroupKeys(NamedAggregationRun $run, array $envelope): array {
		return $this->projectTranslatableGroupKeys(
			envelope: $envelope,
			schema: $run->schema,
			register: $run->register,
			groupBy: $run->getGroupByArg()
		);
	}//end projectNamedGroupKeys()

	/**
	 * The native result of a run, or null when the query shape has no native path.
	 *
	 * Native aggregates run over the full set, so `truncated` is always false.
	 *
	 * @param NamedAggregationRun $run The run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function nativeNamedResult(NamedAggregationRun $run): ?array {
		$native = $this->tryNamedNative(run: $run);
		if ($native === null) {
			return null;
		}

		$run->groupFields = $this->resolveGroupFields(groupBy: $run->getGroupBy());
		$result = $this->applyNamedJoin(run: $run, envelope: $run->envelope(backend: 'postgres') + $native);
		return $this->storeNamedResult(run: $run, result: $result);
	}//end nativeNamedResult()

	/**
	 * Try the native fast path for a run.
	 *
	 * @param NamedAggregationRun $run The run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function tryNamedNative(NamedAggregationRun $run): ?array {
		return $this->tryNativeAggregation(
			register: $run->register,
			schema: $run->schema,
			metric: $run->getMetric(),
			field: $run->getStringField(),
			filter: $run->filter,
			groupBy: $run->getGroupByArg(),
			metrics: $run->getMetrics(),
			rowRbac: $run->bypassRbac === false
		);
	}//end tryNamedNative()

	/**
	 * Attach the run's join to an envelope.
	 *
	 * @param NamedAggregationRun  $run      The run.
	 * @param array<string, mixed> $envelope The envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function applyNamedJoin(NamedAggregationRun $run, array $envelope): array {
		return $this->applyJoin(run: $run, envelope: $envelope);
	}//end applyNamedJoin()

	/**
	 * Compute a run in PHP over the capped table read.
	 *
	 * A groupBy on a joined-schema field is projected onto each row first (see
	 * projectJoinedGroupFields()); applyJoin() runs after grouping and cannot.
	 *
	 * @param NamedAggregationRun $run The run.
	 *
	 * @return array<string, mixed>
	 */
	private function fallbackNamedResult(NamedAggregationRun $run): array {
		$rows = $this->fallbackNamedRows(run: $run);
		$result = $run->envelope(backend: 'php-fallback');
		$rows = $this->projectJoinedGroupFields(run: $run, rows: $rows);
		$result += $this->namedFigures(run: $run, rows: $rows);
		return $this->storeNamedResult(run: $run, result: $this->finishNamedJoin(run: $run, result: $result));
	}//end fallbackNamedResult()

	/**
	 * Read the run's rows, capped at PHP_FALLBACK_ROW_CAP, and post-filter them.
	 *
	 * The cap bounds the per-request memory a caller can force by varying a
	 * filter; a capped read marks the run `truncated`.
	 *
	 * @param NamedAggregationRun $run The run; truncated is set here.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fallbackNamedRows(NamedAggregationRun $run): array {
		$objects = $this->magicMapper->findAllInRegisterSchemaTable(
			table: new RegisterSchema(register: $run->register, schema: $run->schema),
			listing: new ListQuery(limit: self::PHP_FALLBACK_ROW_CAP)
		);
		$run->truncated = count($objects) >= self::PHP_FALLBACK_ROW_CAP;
		$rows = [];
		foreach ($objects as $entity) {
			$rows[] = $entity->getObject();
		}

		return $this->applyFilter(rows: $rows, filter: $run->filter);
	}//end fallbackNamedRows()

	/**
	 * The figures of a run over its rows: `groups`, `values` or `value`.
	 *
	 * Ungrouped multi-metric yields `values` (a map), single-metric `value`.
	 *
	 * @param NamedAggregationRun              $run  The run.
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, mixed>
	 */
	private function namedFigures(NamedAggregationRun $run, array $rows): array {
		$metrics = $run->getMetrics();
		if (count($run->groupFields) > 0) {
			$groups = $this->computeGrouped(
				rows: $rows,
				aggregation: new RowAggregation(
					metric: $run->getMetric(),
					field: $run->getField(),
					groupFields: $run->groupFields,
					metrics: $metrics
				)
			);
			return ['groups' => $groups];
		}

		if ($metrics !== null && count($metrics) > 1) {
			return ['values' => $this->computeMetrics(rows: $rows, metrics: $metrics)];
		}

		return ['value' => $this->computeMetric(rows: $rows, aggregation: new RowAggregation(metric: $run->getMetric(), field: $run->getField()))];
	}//end namedFigures()

	/**
	 * Attach the join, or mark it spent when the grouping consumed it.
	 *
	 * After projection the group key IS the joined dimension, so
	 * mergeJoinedValues() would hang a map of nulls on every group.
	 *
	 * @param NamedAggregationRun  $run    The run.
	 * @param array<string, mixed> $result The envelope with its figures.
	 *
	 * @return array<string, mixed>
	 */
	private function finishNamedJoin(NamedAggregationRun $run, array $result): array {
		if ($run->joinConsumed === false) {
			return $this->applyNamedJoin(run: $run, envelope: $result);
		}

		$result['join'] = [
			'through' => (string)($run->getJoinArg()['through'] ?? ''),
			'consumedForGrouping' => true,
		];
		return $result;
	}//end finishNamedJoin()

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
	 * 1. RBAC gate via mayAggregate() (read, a declared list, or a
	 *    conditional read rule), then the read rule's row predicate.
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
	 * @param Register $register The register the schema belongs to.
	 * @param Schema $schema The schema being aggregated.
	 * @param AggregationQuery $query The fully-validated query value object.
	 * @param bool $bypassRbac Internal-system mode, as run() has it: skip the read gate.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Internal-mode toggle, as on run().
	 *
	 * @return array<string, mixed> Either `{value, backend, cached}` (ungrouped)
	 *                              or `{groups, backend, cached}` (grouped /
	 *                              time-bucketed). `cached` flips to `true`
	 *                              on the second identical request within
	 *                              the 60 s TTL window.
	 *
	 * @throws NotAuthorizedException When the caller may not read the schema.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *   `AggregationQuery::create()` is a fail-fast static factory.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function runAdhoc(
		Register $register,
		Schema $schema,
		AggregationQuery $query,
		bool $bypassRbac=false,
	): array {
		$this->gateAdhoc(schema: $schema, bypassRbac: $bypassRbac);
		$this->assertAdhocRevealsNothing(schema: $schema, query: $query);

		$run = new AdhocAggregationRun(
			register: $register,
			schema: $schema,
			query: $query,
			resolvedQuery: $this->resolveAdhocQuery(query: $query)
		);

		$envelope = $this->cachedAdhocEnvelope(run: $run);
		if ($envelope === null) {
			$envelope = $this->storeAdhocEnvelope(run: $run, envelope: $this->computeAdhocEnvelope(run: $run));
		}

		// The cache stores RAW group keys (the cache key is language-
		// agnostic), so translatable keys must be projected on every
		// read, including cache hits.
		return $this->projectTranslatableGroupKeys(
			envelope: $envelope,
			schema: $schema,
			register: $register,
			groupBy: $query->groupBy
		);
	}//end runAdhoc()

	/**
	 * RBAC gate of runAdhoc() — identical predicate to run().
	 *
	 * Including run()'s internal-system mode (a materialised
	 * aggregate-reference is the schema author's derived value, resolved
	 * whoever saves).
	 *
	 * @param Schema $schema     The schema being aggregated.
	 * @param bool   $bypassRbac Internal-system mode: skip the read gate.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the caller may not read the schema.
	 */
	private function gateAdhoc(Schema $schema, bool $bypassRbac): void {
		$userId = $this->userSession->getUser()?->getUID();
		if ($bypassRbac === false && $this->mayAggregate(schema: $schema, userId: $userId) === false) {
			throw new NotAuthorizedException(
				message: sprintf(
					'You do not have permission to aggregate schema "%s".',
					(string)$schema->getSlug()
				)
			);
		}
	}//end gateAdhoc()

	/**
	 * Refuse an ad-hoc query that would expose an explicit-reveal value.
	 *
	 * An explicit-reveal value leaves only as its mask, so the ad-hoc path
	 * may neither group on it (a bucket key is the value), take a min or max
	 * of it, nor filter on it. With `$bypassRbac` too: a materialised
	 * aggregate (AggregateReferenceResolver) stores the figure on another
	 * object, in clear.
	 *
	 * @param Schema           $schema The schema being aggregated.
	 * @param AggregationQuery $query  The caller's query.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the query reads an explicit-reveal property.
	 */
	private function assertAdhocRevealsNothing(Schema $schema, AggregationQuery $query): void {
		$this->assertNoExplicitValues(
			schema: $schema,
			fields: array_merge(
				[(string)$query->field],
				$this->groupByFields(groupBy: $query->groupBy),
				$this->metricFields(metrics: $query->metrics)
			)
		);
		$this->assertNoExplicitFilter(schema: $schema, filter: $query->filter);
	}//end assertAdhocRevealsNothing()

	/**
	 * The query with placeholders inside its filter resolved.
	 *
	 * Resolves e.g. $now, $startOfMonth so the native SQL path can bind
	 * concrete values. The resolved filter feeds both the cache key and the
	 * SQL bindings.
	 *
	 * @param AggregationQuery $query The caller's query.
	 *
	 * @return AggregationQuery
	 */
	private function resolveAdhocQuery(AggregationQuery $query): AggregationQuery {
		return AggregationQuery::create(
			metric: $query->metric,
			field: $query->field,
			filter: $this->placeholders->resolveArray($query->filter),
			groupBy: $query->groupBy,
			dateBucket: $query->dateBucket,
			metrics: $query->metrics,
			cumulative: $query->cumulative
		);
	}//end resolveAdhocQuery()

	/**
	 * The cached envelope for this query, flagged as cached, or null on a miss.
	 *
	 * Read-through cache. Identical query + filter + RBAC scope → same cache
	 * key → 60 s TTL hit. Invalidation is event-driven via
	 * AggregationCacheInvalidationListener which evicts the entire
	 * `openregister_aggregations` cache on every object-write event.
	 *
	 * @param AdhocAggregationRun $run The ad-hoc run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cachedAdhocEnvelope(AdhocAggregationRun $run): ?array {
		$cached = $this->cache->getAdhoc(
			registerSlug: (string)$run->register->getSlug(),
			schemaSlug: (string)$run->schema->getSlug(),
			query: $run->resolvedQuery
		);
		if ($cached !== null) {
			$cached['cached'] = true;
		}

		return $cached;
	}//end cachedAdhocEnvelope()

	/**
	 * Store the envelope under this query's cache key and return it.
	 *
	 * The RAW (un-projected) group keys are cached — the cache key does not
	 * encode the negotiated language, so language projection must happen
	 * after the cache boundary on every read.
	 *
	 * @param AdhocAggregationRun  $run      The ad-hoc run.
	 * @param array<string, mixed> $envelope The computed envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function storeAdhocEnvelope(AdhocAggregationRun $run, array $envelope): array {
		$this->cache->setAdhoc(
			registerSlug: (string)$run->register->getSlug(),
			schemaSlug: (string)$run->schema->getSlug(),
			query: $run->resolvedQuery,
			result: $envelope
		);
		return $envelope;
	}//end storeAdhocEnvelope()

	/**
	 * Compute the envelope: DBAL source, then native SQL, then the PHP bucketer.
	 *
	 * @param AdhocAggregationRun $run The ad-hoc run.
	 *
	 * @return array<string, mixed>
	 */
	private function computeAdhocEnvelope(AdhocAggregationRun $run): array {
		// External (DBAL virtual) register: the object rows live in an
		// external database, not the magic table, so the native + PHP paths
		// below would aggregate an empty magic table and return 0.
		$envelope = $this->dbalAdhocEnvelope(run: $run);
		if ($envelope === null) {
			$envelope = $this->nativeAdhocEnvelope(run: $run);
		}

		// PHP fallback path. Pull the RBAC-filtered row set + bucket in
		// PHP. Correctness path; the native paths are the production target.
		if ($envelope === null) {
			$envelope = $this->bucketInPhp(run: $run);
		}

		return $envelope;
	}//end computeAdhocEnvelope()

	/**
	 * The DBAL object-source envelope, or null when the schema is not DBAL-sourced.
	 *
	 * Delegates to the DBAL object-source provider, which computes the metric
	 * live in the external DB with the same table/column resolution the reads
	 * use. Null also when the query shape is unsupported.
	 *
	 * @param AdhocAggregationRun $run The ad-hoc run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function dbalAdhocEnvelope(AdhocAggregationRun $run): ?array {
		$dbal = $this->tryDbalAggregation(run: $run);
		if ($dbal === null) {
			return null;
		}

		return [
			'backend' => 'dbal-source',
			'cached' => false,
		] + $dbal;
	}//end dbalAdhocEnvelope()

	/**
	 * The native SQL envelope, or null to fall through to the PHP bucketer.
	 *
	 * The runner detects the database platform and emits the matching native
	 * bucketing expression (date_trunc / DATE_FORMAT / strftime). Null on
	 * unsupported query shapes or unrecognised engines.
	 *
	 * @param AdhocAggregationRun $run The ad-hoc run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function nativeAdhocEnvelope(AdhocAggregationRun $run): ?array {
		$query  = $run->query;
		$native = $this->tryNativeAggregation(
			register: $run->register,
			schema: $run->schema,
			metric: $query->metric,
			field: $query->field,
			filter: $run->resolvedQuery->filter,
			groupBy: $query->groupBy,
			dateBucket: $query->dateBucket,
			metrics: $query->getMetrics(),
			cumulative: $query->cumulative
		);
		if ($native === null) {
			return null;
		}

		return [
			'backend' => $this->detectDatabasePlatform(),
			'cached' => false,
		] + $native;
	}//end nativeAdhocEnvelope()

	/**
	 * Delegate an ad-hoc aggregation to the DBAL object-source provider when
	 * the schema is backed by an external database (`x-openregister-object-source`
	 * provider `dbal-source`). Returns the `{value}` / `{groups}` fragment, or
	 * null when the schema is not DBAL-sourced, the provider is unavailable, or
	 * the provider cannot serve the query shape (→ caller falls through to the
	 * magic-table native / PHP paths).
	 *
	 * A provider-side failure (unreachable DB / query error) is swallowed to
	 * null here rather than propagated: the aggregation endpoints should
	 * degrade to an empty widget, never 500 the dashboard.
	 *
	 * @param AdhocAggregationRun $run The ad-hoc run; its resolved query is sent.
	 *
	 * @return array<string, mixed>|null The result fragment, or null to fall through.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function tryDbalAggregation(AdhocAggregationRun $run): ?array {
		if ($this->dbalSourceProvider === null) {
			return null;
		}

		// Multi-metric requests aren't supported by the DBAL provider's
		// single-metric aggregate() signature. Fall through to the
		// magic-table native / PHP paths below, which do support it.
		$query = $run->resolvedQuery;
		if ($query->isMultiMetric() === true) {
			return null;
		}

		$config = $this->dbalSourceConfig(schema: $run->schema);
		if ($config === null) {
			return null;
		}

		try {
			return $this->dbalSourceProvider->aggregate(
				register: $run->register,
				schema: $run->schema,
				metric: $query->metric,
				field: $query->field,
				filter: $query->filter,
				groupBy: $query->groupBy,
				dateBucket: $query->dateBucket,
				config: $config
			);
		} catch (\Throwable $e) {
			$this->logger?->warning(
				'[AggregationRunner] DBAL aggregation failed for schema "' . ((string)$run->schema->getSlug()) . '": ' . $e->getMessage()
			);
			return null;
		}
	}//end tryDbalAggregation()

	/**
	 * The `dbal-source` object-source config of a schema, or null when it has none.
	 *
	 * @param Schema $schema The schema being aggregated.
	 *
	 * @return array<mixed>|null
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function dbalSourceConfig(Schema $schema): ?array {
		$configuration = $schema->getConfiguration();
		if (is_array($configuration) === false) {
			return null;
		}

		$objectSource = ($configuration['x-openregister-object-source'] ?? null);
		if (is_array($objectSource) === false
			|| (string)($objectSource['provider'] ?? '') !== 'dbal-source'
		) {
			return null;
		}

		$config = ($objectSource['config'] ?? []);
		if (is_array($config) === false) {
			return null;
		}

		return $config;
	}//end dbalSourceConfig()

	/**
	 * Project translatable group keys in a grouped-aggregation envelope to
	 * the negotiated display language.
	 *
	 * `GET /api/objects/aggregations/{register}/{schema}/grouped` groups on
	 * one or more fields via SQL `GROUP BY` (native path) or PHP bucketing
	 * (fallback). When a grouped field is `translatable: true`, the stored
	 * value is a language-keyed map (e.g. `{"nl":"Foo","en":"Bar"}`), so the
	 * group key comes back as the raw map (native: a JSON string; PHP: an
	 * associative array) instead of the single projected string a normal
	 * read returns.
	 *
	 * This projects each such key to the caller's negotiated language,
	 * reusing {@see TranslationHandler::resolveTranslationsForRender()} for
	 * the language chain / fallback logic rather than reimplementing it.
	 *
	 * Both group-row shapes are handled, and BOTH must be — a composite
	 * groupBy emits `keys: {fieldA: ..., fieldB: ...}` and never a scalar
	 * `key`, so a guard written for the single-field shape alone silently
	 * skips projection for every multi-field aggregation and hands the
	 * caller raw language maps:
	 * - single-field: the scalar `key` is projected;
	 * - multi-field: EVERY translatable member of the `keys` map is
	 *   projected, each against its own field name; non-translatable
	 *   members are left byte-for-byte unchanged.
	 *
	 * No-op when:
	 * - the envelope carries no `groups`;
	 * - the groupBy spec names no fields;
	 * - NO grouped field is translatable on the schema;
	 * - `?_translations=all` is requested (keys are returned verbatim).
	 *
	 * @param array<string, mixed> $envelope The aggregation result envelope.
	 * @param Schema $schema The schema being aggregated.
	 * @param Register $register The owning register (language config).
	 * @param array<string, mixed>|null $groupBy The groupBy spec — any accepted
	 *                                           spelling ({field}, {fields: [...]},
	 *                                           or a plain list).
	 *
	 * @return array<string, mixed> The envelope with translatable group keys projected.
	 *
	 * The method is a chain of cheap short-circuit guards (no groups /
	 * no groupBy field / nothing translatable / _translations=all) before
	 * the projection, which TranslatableGroupKeyProjection carries out.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function projectTranslatableGroupKeys(
		array $envelope,
		Schema $schema,
		Register $register,
		?array $groupBy,
	): array {
		if (isset($envelope['groups']) === false || is_array($envelope['groups']) === false) {
			return $envelope;
		}

		$translatableGroups = $this->translatableGroupFields(schema: $schema, groupBy: $groupBy);
		if (count($translatableGroups) === 0) {
			return $envelope;
		}

		// `?_translations=all` returns keys verbatim.
		if ($this->languageService->shouldReturnAllTranslations() === true) {
			return $envelope;
		}

		$projection = new TranslatableGroupKeyProjection(
			translations: $this->translationHandler,
			schema: $schema,
			register: $register,
			translatableFields: $translatableGroups
		);
		$envelope['groups'] = $projection->projectGroups(groups: $envelope['groups']);
		return $envelope;
	}//end projectTranslatableGroupKeys()

	/**
	 * The grouped fields that are translatable on the schema, in group order.
	 *
	 * Only project when at least one grouped field is actually translatable.
	 * getTranslatableProperties() is cheap, is not asked when nothing is
	 * grouped, and this keeps NON-translatable grouped fields byte-for-byte
	 * unchanged.
	 *
	 * @param Schema                    $schema  The schema being aggregated.
	 * @param array<string, mixed>|null $groupBy The group spec, in any accepted spelling.
	 *
	 * @return array<int, string>
	 */
	private function translatableGroupFields(Schema $schema, ?array $groupBy): array {
		$groupFields = $this->resolveGroupFields(groupBy: $groupBy);
		if (count($groupFields) === 0) {
			return [];
		}

		$translatableProps = $this->translationHandler->getTranslatableProperties(schema: $schema);
		return array_values(
			array_filter(
				$groupFields,
				static fn (string $groupField): bool => in_array($groupField, $translatableProps, true)
			)
		);
	}//end translatableGroupFields()

	/**
	 * Convenience: run an ad-hoc aggregation by register/schema ref.
	 *
	 * Mirrors {@see run()}'s ref-based call shape but for the ad-hoc
	 * path. Lets the REST controller call without re-implementing the
	 * mapper-lookup glue.
	 *
	 * @param string $registerRef Register slug/uuid/id.
	 * @param string $schemaRef Schema slug/uuid/id.
	 * @param AggregationQuery $query The fully-validated query.
	 * @param bool $bypassRbac Internal-system mode, as run() has it: skip the read gate.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Internal-mode toggle, as on run().
	 *
	 * @return array<string, mixed> Result envelope (see runAdhoc()).
	 *
	 * @throws RuntimeException When the register or schema cannot be resolved.
	 * @throws NotAuthorizedException When the caller may not read the schema.
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function runAdhocByRef(
		string $registerRef,
		string $schemaRef,
		AggregationQuery $query,
		bool $bypassRbac=false,
	): array {
		// REGISTER-SCOPED RESOLUTION — see run() and loadSchemaInRegister().
		// `value`, `grouped` and `timeseries` all land here, and all three are
		// the surfaces the dashboard `stat`/`chart` widgets call.
		$pair = $this->loadSchemaInRegister(schemaRef: $schemaRef, registerRef: $registerRef);

		return $this->runAdhoc(register: $pair['register'], schema: $pair['schema'], query: $query, bypassRbac: $bypassRbac);
	}//end runAdhocByRef()

	/**
	 * Convenience: load a schema by ref. Public surface to let the
	 * REST controller validate field allow-lists against
	 * `Schema::getProperties()` before constructing the AggregationQuery
	 * (we want a 400 from the validation layer, not a 404 from inside
	 * runAdhocByRef()).
	 *
	 * When `$registerRef` is supplied the lookup is REGISTER-SCOPED and must
	 * resolve to the same schema `runAdhocByRef()` will subsequently resolve —
	 * `timeseries()` calls both, and a validator that validated the field
	 * allow-list of one schema while the aggregate ran against another would be
	 * worse than no validation at all. Callers with no register boundary in hand
	 * omit it and keep global resolution.
	 *
	 * @param string      $schemaRef   Schema slug/uuid/id.
	 * @param string|null $registerRef Optional register slug/uuid/id — when given, the boundary.
	 *
	 * @return Schema The loaded schema.
	 *
	 * @throws RuntimeException When the schema can't be found, or is not carried by the register.
	 *
	 * @spec exclude Thin public convenience wrapper over the private schema lookups; exposed only so
	 *              the REST controller can validate field allow-lists before building an AggregationQuery.
	 *              No business logic of its own.
	 */
	public function findSchema(string $schemaRef, ?string $registerRef = null): Schema {
		if ($registerRef !== null && $registerRef !== '') {
			return $this->loadSchemaInRegister(schemaRef: $schemaRef, registerRef: $registerRef)['schema'];
		}

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
	 * @param AdhocAggregationRun $run The ad-hoc run.
	 *
	 * @return array<string, mixed> Either `{value, backend, cached}` or
	 *                              `{groups, backend, cached}` mirroring the
	 *                              Postgres native-path shape. Multi-metric
	 *                              requests carry `values` instead of
	 *                              `value`/per-group `value`.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function bucketInPhp(AdhocAggregationRun $run): array {
		$rows = $this->adhocPhpRows(run: $run);

		if ($run->query->dateBucket !== null) {
			return [
				'groups' => $this->dateBucketGroups(query: $run->query, rows: $rows),
				'backend' => 'php-fallback',
				'cached' => false,
			];
		}

		return $this->adhocPhpFigures(query: $run->query, rows: $rows) + [
			'backend' => 'php-fallback',
			'cached' => false,
		];
	}//end bucketInPhp()

	/**
	 * The schema's rows, capped, with the resolved filter applied in PHP.
	 *
	 * Same operator vocabulary (in / gt / gte / lt / lte / ne) as the SQL path.
	 *
	 * @param AdhocAggregationRun $run The ad-hoc run.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function adhocPhpRows(AdhocAggregationRun $run): array {
		$objects = $this->magicMapper->findAllInRegisterSchemaTable(
			table: new RegisterSchema(register: $run->register, schema: $run->schema),
			listing: new ListQuery(limit: self::PHP_FALLBACK_ROW_CAP)
		);

		$rows = [];
		foreach ($objects as $entity) {
			// The findAllInRegisterSchemaTable mapper returns ObjectEntity[];
			// getObject() resolves each row to the inner data array.
			$rows[] = $entity->getObject();
		}

		return $this->applyFilter(rows: $rows, filter: $run->resolvedQuery->filter);
	}//end adhocPhpRows()

	/**
	 * The metric per date bucket, chronologically ascending, with the running total when asked.
	 *
	 * Sorting the ISO-8601-UTC bucket keys with ksort() orders them chronologically
	 * ascending (string ordering matches time ordering for this key format),
	 * which is the ordering addCumulativeColumn() assumes.
	 *
	 * @param AggregationQuery                 $query The caller's query; it carries a dateBucket.
	 * @param array<int, array<string, mixed>> $rows  The filtered rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function dateBucketGroups(AggregationQuery $query, array $rows): array {
		$buckets = $this->bucketRowsByDate(rows: $rows, dateBucket: (array)$query->dateBucket);

		$groups = [];
		ksort($buckets);
		foreach ($buckets as $key => $bucketRows) {
			$groups[] = [
				'key' => $key,
				'value' => $this->computeMetric(rows: $bucketRows, aggregation: new RowAggregation(metric: $query->metric, field: $query->field)),
			];
		}

		if ($query->cumulative === true) {
			$groups = $this->addCumulativeColumn(groups: $groups);
		}

		return $groups;
	}//end dateBucketGroups()

	/**
	 * The rows whose bucket field falls in [start, end), keyed by truncated bucket.
	 *
	 * @param array<int, array<string, mixed>> $rows       The filtered rows.
	 * @param array<string, mixed>             $dateBucket The time-bucket spec: field, start, end, gap.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function bucketRowsByDate(array $rows, array $dateBucket): array {
		$bucketField = (string)$dateBucket['field'];
		$window = [
			'start' => strtotime((string)$dateBucket['start']),
			'end' => strtotime((string)$dateBucket['end']),
		];
		$gap = (string)$dateBucket['gap'];
		$buckets = [];

		foreach ($rows as $row) {
			$stamp = $this->rowBucketStamp(row: $row, field: $bucketField);
			if ($this->stampInWindow(stamp: $stamp, window: $window) === false) {
				continue;
			}

			$key = $this->truncateTimestamp(timestamp: (int)$stamp, gap: $gap);
			$buckets[$key] ??= [];
			$buckets[$key][] = $row;
		}

		return $buckets;
	}//end bucketRowsByDate()

	/**
	 * A row's bucket timestamp: null when the field is absent, false when unparseable.
	 *
	 * A numeric value is taken as a unix timestamp.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param string               $field The bucket field.
	 *
	 * @return int|false|null
	 */
	private function rowBucketStamp(array $row, string $field): int|false|null {
		$raw = ($row[$field] ?? null);
		if ($raw === null) {
			return null;
		}

		$stamp = strtotime((string)$raw);
		if (is_numeric($raw) === true) {
			$stamp = (int)$raw;
		}

		return $stamp;
	}//end rowBucketStamp()

	/**
	 * Whether a bucket timestamp falls in the window [start, end).
	 *
	 * @param int|false|null                          $stamp  The row's timestamp.
	 * @param array{start: int|false, end: int|false} $window The window bounds.
	 *
	 * @return bool
	 */
	private function stampInWindow(int|false|null $stamp, array $window): bool {
		return ($stamp === null || $stamp === false || $stamp < $window['start'] || $stamp >= $window['end']) === false;
	}//end stampInWindow()

	/**
	 * The figures of an un-bucketed fallback query: `groups`, `values` or `value`.
	 *
	 * @param AggregationQuery                 $query The caller's query.
	 * @param array<int, array<string, mixed>> $rows  The filtered rows.
	 *
	 * @return array<string, mixed>
	 */
	private function adhocPhpFigures(AggregationQuery $query, array $rows): array {
		$metrics     = $query->getMetrics();
		$groupFields = $this->resolveGroupFields(groupBy: $query->groupBy);
		if (count($groupFields) > 0) {
			return [
				'groups' => $this->computeGrouped(
					rows: $rows,
					aggregation: new RowAggregation(
						metric: $query->metric,
						field: $query->field,
						groupFields: $groupFields,
						metrics: $metrics
					)
				),
			];
		}

		if (count($metrics) > 1) {
			return ['values' => $this->computeMetrics(rows: $rows, metrics: $metrics)];
		}

		return ['value' => $this->computeMetric(rows: $rows, aggregation: new RowAggregation(metric: $query->metric, field: $query->field))];
	}//end adhocPhpFigures()

	/**
	 * Add a `cumulative` (running-total) key to each already-ordered
	 * time-bucket group (REQ-AGG-103 / design D3).
	 *
	 * Assumes `$groups` is already sorted ascending by bucket start — the
	 * native SQL paths `ORDER BY bucket` and the PHP fallback `ksort()`s
	 * the bucket map before building `$groups` — and simply accumulates
	 * `value` left-to-right. This is the PHP-post-pass half of the
	 * SQL-window / PHP-post-pass parity pair: MySQL, SQLite and the PHP
	 * fallback path call this helper; the Postgres native path instead
	 * computes the identical running total in SQL via
	 * `SUM(...) OVER (ORDER BY bucket)` (see {@see tryNativeAggregation()}).
	 * The two MUST agree bucket-for-bucket on the same input — pinned by
	 * `AggregationRunnerCumulativeTest::testSqlWindowAndPhpPostPassAgreeOnTheSameData()`.
	 *
	 * A `null` per-bucket value (an empty bucket) contributes `0` to the
	 * running total rather than propagating `null`, matching Postgres
	 * `SUM(...) OVER (...)` semantics (SQL `SUM` ignores `NULL` inputs).
	 *
	 * @param array<int, array{key: mixed, value: int|float|null}> $groups Ordered buckets, ascending by bucket start.
	 *
	 * @return array<int, array{key: mixed, value: int|float|null, cumulative: int|float}>
	 *                                                                                     The same groups with a running-total `cumulative` key added.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function addCumulativeColumn(array $groups): array {
		$running = 0;
		foreach ($groups as $index => $group) {
			$value = ($group['value'] ?? null);
			if ($value !== null) {
				$running += $value;
			}

			$groups[$index]['cumulative'] = $running;
		}

		return $groups;
	}//end addCumulativeColumn()

	/**
	 * Polyfill for Postgres `date_trunc($gap, ts)::text` over a Unix
	 * timestamp. Returns an ISO-8601-UTC `Y-m-d\TH:i:s\Z` bucket label
	 * keyed at the start of the gap.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @param string $gap One of AggregationQuery::DATE_BUCKET_GAPS.
	 *
	 * @return string ISO-8601-UTC bucket label at the gap-start.
	 */
	private function truncateTimestamp(int $timestamp, string $gap): string {
		// `week` and `quarter` need bespoke handling below — the rest
		// map directly to a gmdate format string.
		$format = match ($gap) {
			'minute' => 'Y-m-d\TH:i:00\Z',
			'hour' => 'Y-m-d\TH:00:00\Z',
			'day' => 'Y-m-d\T00:00:00\Z',
			'week' => null,
			'month' => 'Y-m-01\T00:00:00\Z',
			'quarter' => null,
			'year' => 'Y-01-01\T00:00:00\Z',
			default => 'Y-m-d\T00:00:00\Z',
		};

		if ($gap === 'week') {
			// ISO week starts Monday. Use gmdate('N') to find the day of
			// the week then back-shift.
			$dayOfWeek = (int)gmdate('N', $timestamp);
			$weekStartTs = ($timestamp - (($dayOfWeek - 1) * 86400));
			return gmdate('Y-m-d\T00:00:00\Z', $weekStartTs);
		}

		if ($gap === 'quarter') {
			// Truncate to the first month of the quarter.
			$month = (int)gmdate('n', $timestamp);
			$qStart = ((int)(($month - 1) / 3) * 3) + 1;
			$year = (int)gmdate('Y', $timestamp);
			return sprintf('%04d-%02d-01T00:00:00Z', $year, $qStart);
		}

		return gmdate($format, $timestamp);
	}//end truncateTimestamp()

	/**
	 * Compute a single scalar metric over the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows        Already-filtered rows.
	 * @param RowAggregation                   $aggregation The metric (count/sum/avg/min/max/count_distinct) and the
	 *                                                      field it aggregates over (ignored for count).
	 *
	 * @return int|float|null The metric result, or null when no rows match.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	private function computeMetric(array $rows, RowAggregation $aggregation): int|float|null {
		$field = (string) $aggregation->field;
		return match ($aggregation->metric) {
			'count' => count($rows),
			'sum' => array_reduce($this->numericValues(rows: $rows, field: $field), fn (float $a, float $b) => $a + $b, 0.0),
			'avg' => $this->avg(rows: $rows, field: $field),
			'min' => self::reduceFromFirst(values: $this->numericValues(rows: $rows, field: $field), reducer: fn (float $a, float $b) => min($a, $b)),
			'max' => self::reduceFromFirst(values: $this->numericValues(rows: $rows, field: $field), reducer: fn (float $a, float $b) => max($a, $b)),
			'count_distinct' => $this->countDistinct(rows: $rows, field: $field),
			default => null,
		};
	}//end computeMetric()

	/**
	 * The number of distinct non-null values of a column.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows to read.
	 * @param string                           $field Column to read.
	 *
	 * @return int
	 */
	private function countDistinct(array $rows, string $field): int {
		$values = array_filter(array_map(fn (array $r) => $r[$field] ?? null, $rows), fn ($v) => $v !== null);
		return count(array_unique($values, SORT_REGULAR));
	}//end countDistinct()

	/**
	 * Compute every metric in a multi-metric request over the same row set.
	 *
	 * Companion to {@see computeMetric()} for REQ-AGG-102 — each entry's
	 * result is keyed by {@see AggregationQuery::metricResponseKey()} (e.g.
	 * `count`, `sum_price`) into a single `values` map so a caller can read
	 * `count` and `sum_price` from one grouped row / one ungrouped envelope.
	 *
	 * @param array<int, array<string, mixed>> $rows Already-filtered rows.
	 * @param array<int, array{metric: string, field: ?string}> $metrics Requested metric entries.
	 *
	 * @return array<string, int|float|null> Metric results keyed by response key.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	/**
	 * Whether a metrics list uses anything the native SQL path cannot express.
	 *
	 * `condition` and `as` are both honoured only by {@see computeMetrics()}.
	 * The native path aggregates every entry over the same filtered row set and
	 * derives its response key from metric+field, so it would drop a condition
	 * silently and collide two aliases into one key. Both failures return a
	 * plausible number rather than an error, which is why this is a hard
	 * bail-out rather than a best-effort.
	 *
	 * @param array<int, array<string, mixed>> $metrics The metrics list.
	 *
	 * @return bool True when the PHP path must be used.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	/**
	 * Project joined-schema group fields onto the parent rows.
	 *
	 * A `groupBy` entry spelled `<JoinedSchema>.<field>` names a column the
	 * parent rows do not carry — it exists only on the other side of the join.
	 * Grouping on it directly puts every row in one null bucket, which reads as
	 * a working total rather than a mistake.
	 *
	 * This resolves it the only way the semantics allow: build a
	 * joinKey => value map from the joined schema, stamp each parent row with
	 * the value its join key maps to, and hand back a rewritten group-field
	 * list that points at the stamped column. Grouping then proceeds normally,
	 * and the result is a roll-up to the joined dimension — a cost-centre
	 * hierarchy summing its children, which is the shape this exists for.
	 *
	 * A parent row whose join key matches nothing keeps a NULL group value
	 * rather than being dropped. Dropping it would quietly shrink the totals;
	 * a null bucket is visible and is the honest answer for "belongs to no
	 * dimension".
	 *
	 * Leaves everything untouched when no group field is join-qualified, so the
	 * ordinary path pays only an array scan.
	 *
	 * Sets the run's group fields (the requested ones; the projected column
	 * is named after the qualified field) and whether the join was consumed
	 * by the grouping.
	 *
	 * @param NamedAggregationRun              $run  The run: its groupBy, join and internal-system mode.
	 * @param array<int, array<string, mixed>> $rows The parent rows.
	 *
	 * @return array<int, array<string, mixed>> The rows, carrying any projected columns.
	 *
	 * @throws RuntimeException When a join-qualified group field has no usable join spec.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function projectJoinedGroupFields(NamedAggregationRun $run, array $rows): array {
		$run->groupFields = $this->resolveGroupFields(groupBy: $run->getGroupBy());
		$run->joinConsumed = false;

		$joinSpec = ($run->getJoinArg() ?? []);
		$through = (string)($joinSpec['through'] ?? '');
		$qualified = $this->joinQualifiedGroupFields(groupFields: $run->groupFields, through: $through);
		if ($qualified === []) {
			return $rows;
		}

		$onMap = $this->joinedProjectionKeyMap(joinSpec: $joinSpec, qualified: $qualified[0]);

		$parentKey = (string)array_key_first($onMap);
		$joinedRows = $this->loadJoinedRowsForProjection(
			joinSpec: $joinSpec,
			bypassRbac: $run->bypassRbac
		);

		foreach ($qualified as $groupField) {
			$projection = new JoinedColumnProjection(
				parentKey: $parentKey,
				joinedKey: (string)$onMap[$parentKey],
				sourceField: substr($groupField, (strlen($through) + 1)),
				column: $groupField
			);
			$rows = $projection->stamp(rows: $rows, joinedRows: $joinedRows);
		}

		$run->joinConsumed = true;
		return $rows;
	}//end projectJoinedGroupFields()

	/**
	 * The group fields qualified by the join's `through` schema (`Through.field`).
	 *
	 * @param array<int, string> $groupFields The requested group fields.
	 * @param string             $through     The joined schema ref; empty when there is no join.
	 *
	 * @return list<string>
	 */
	private function joinQualifiedGroupFields(array $groupFields, string $through): array {
		$qualified = [];
		foreach ($groupFields as $groupField) {
			if ($through !== '' && str_starts_with($groupField, $through . '.') === true) {
				$qualified[] = $groupField;
			}
		}

		return $qualified;
	}//end joinQualifiedGroupFields()

	/**
	 * Resolve the parent=>joined key map for a group-field projection.
	 *
	 * THE `on` SHORTHAND CANNOT BE USED HERE, and saying so is the point.
	 * `"on": "Schema.column"` infers the parent-side field FROM THE GROUP
	 * FIELDS — the same-named one if present, otherwise the first. When the
	 * group field is the join-qualified one, that inference picks the JOINED
	 * field as the parent key, reads it off parent rows that do not have it,
	 * and lands every row in a single null bucket reported as a working total.
	 * Measured on the shorthand: a fixture summing 350 + 7 came back as one
	 * bucket of 357 keyed ''. The explicit map names both sides, so nothing is
	 * inferred.
	 *
	 * The map is read DIRECTLY rather than through resolveJoinKey(). That
	 * helper enforces "every parent-side field must be one of the groupBy
	 * fields", which is right for the post-grouping merge — it reads the key
	 * out of a group row — but false here by construction: before projection
	 * the group field is the joined one and the parent key is not grouped.
	 *
	 * @param array<string, mixed> $joinSpec  The join spec.
	 * @param string               $qualified The join-qualified group field, for messages.
	 *
	 * @return array<string, string> Single-entry parentField => joinedField map.
	 *
	 * @throws RuntimeException When `on` is the shorthand, absent, or composite.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function joinedProjectionKeyMap(array $joinSpec, string $qualified): array {
		if (is_string($joinSpec['on'] ?? null) === true) {
			throw new RuntimeException(
				'Grouping by "' . $qualified . '" requires the explicit join.on map '
				. '({parentField: joinedField}); the "Schema.column" shorthand infers the parent key '
				. 'from the group fields, which are the joined ones here.'
			);
		}

		$onMap = [];
		$onClause = ($joinSpec['on'] ?? null);
		if (is_array($onClause) === true) {
			foreach ($onClause as $parentField => $joinedField) {
				if (is_string($parentField) === true && is_string($joinedField) === true) {
					$onMap[$parentField] = $this->stripSchemaPrefix(ref: $joinedField);
				}
			}
		}

		if ($onMap === []) {
			throw new RuntimeException(
				'Grouping by "' . $qualified . '" needs the join\'s `on` mapping to locate the parent key.'
			);
		}

		// Single-key joins only. A composite key would need a tuple lookup per
		// row, and no declaration uses one — refusing beats silently matching
		// on the first key alone, which would over-merge groups and inflate
		// every total.
		if (count($onMap) > 1) {
			throw new RuntimeException(
				'Grouping by a joined field is supported for single-key joins only; "'
				. $qualified . '" joins on ' . count($onMap) . ' keys.'
			);
		}

		return $onMap;
	}//end joinedProjectionKeyMap()

	/**
	 * Hydrate and filter the joined schema's rows for a group-field projection.
	 *
	 * The declared `join.filter` applies here too: if the join only considers
	 * active dimensions, the projection must not map a parent row onto a
	 * retired one, or the roll-up would credit amounts to a dimension the join
	 * itself excludes.
	 *
	 * @param array<string, mixed> $joinSpec   The join spec; its `through` names the joined schema.
	 * @param bool                 $bypassRbac Internal-system mode.
	 *
	 * @return array<int, array<string, mixed>> The joined rows.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function loadJoinedRowsForProjection(array $joinSpec, bool $bypassRbac): array {
		[$joinedSchema, $joinedRegister] = $this->resolveJoinTarget(
			through: (string)($joinSpec['through'] ?? ''),
			bypassRbac: $bypassRbac
		);

		$joinedRows = [];
		foreach ($this->magicMapper->findAllInRegisterSchemaTable(
			table: new RegisterSchema(register: $joinedRegister, schema: $joinedSchema),
			listing: new ListQuery(limit: self::PHP_FALLBACK_ROW_CAP)
		) as $object) {
			$joinedRows[] = $object->getObject();
		}

		$joinedFilter = $this->placeholders->resolveArray(
			(array)($joinSpec['filter'] ?? $joinSpec['where'] ?? [])
		);
		if ($joinedFilter === []) {
			return $joinedRows;
		}

		return $this->applyFilter(rows: $joinedRows, filter: $joinedFilter);
	}//end loadJoinedRowsForProjection()

	/**
	 * Whether a metrics list uses anything the native SQL path cannot express.
	 *
	 * `condition` and `as` are honoured only by {@see computeMetrics()}. The
	 * native path aggregates every entry over the same filtered row set and
	 * derives its response key from metric+field, so it would drop a condition
	 * silently and collide two aliases into one key. Both failures return a
	 * plausible number rather than an error, which is why this is a hard
	 * bail-out rather than a best-effort.
	 *
	 * @param array<int, array<string, mixed>> $metrics The metrics list.
	 *
	 * @return bool True when the PHP path must be used.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function metricsNeedPhpPath(array $metrics): bool {
		foreach ($metrics as $entry) {
			if ($this->metricEntryNeedsPhpPath(entry: $entry) === true) {
				return true;
			}
		}

		return false;
	}//end metricsNeedPhpPath()

	/**
	 * Whether one metric entry is a derived metric, or carries a `condition` or an `as` alias.
	 *
	 * @param mixed $entry The metric entry; a non-array entry needs nothing.
	 *
	 * @return bool
	 */
	private function metricEntryNeedsPhpPath(mixed $entry): bool {
		if (is_array($entry) === false) {
			return false;
		}

		if (($entry['metric'] ?? null) === 'expression') {
			return true;
		}

		return $this->declaresConditionOrAlias(entry: $entry);
	}//end metricEntryNeedsPhpPath()

	/**
	 * Whether a metric entry declares a non-empty `condition` or a non-empty `as` alias.
	 *
	 * @param array<string, mixed> $entry The metric entry.
	 *
	 * @return bool
	 */
	private function declaresConditionOrAlias(array $entry): bool {
		$condition = ($entry['condition'] ?? null);
		$alias     = ($entry['as'] ?? null);
		return (is_array($condition) === true && $condition !== [])
			|| (is_string($alias) === true && $alias !== '');
	}//end declaresConditionOrAlias()

	/**
	 * Compute every requested metric over one row set.
	 *
	 * Each entry may carry an optional `condition` (a filter object scoping
	 * that figure to a subset of the rows) and an optional `as` (its response
	 * key). See the inline notes for why both exist.
	 *
	 * @param array<int, array<string, mixed>> $rows    The rows to reduce.
	 * @param array<int, array<string, mixed>> $metrics The metrics list.
	 *
	 * @return array<string, int|float|null> Value per response key.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function computeMetrics(array $rows, array $metrics): array {
		$values = [];
		foreach ($metrics as $entry) {
			if (($entry['metric'] ?? null) === 'expression') {
				$derivedAlias = $this->derivedMetricAlias(entry: $entry);
				$values[$derivedAlias] = $this->expressions->evaluate(
					expression: (string)($entry['expression'] ?? ''),
					scope: $values
				);
				continue;
			}

			$scoped = $this->metricEntryRows(rows: $rows, entry: $entry);
			$key    = $this->metricEntryKey(entry: $entry);
			$values[$key] = $this->computeMetric(
				rows: $scoped,
				aggregation: new RowAggregation(metric: $entry['metric'], field: ($entry['field'] ?? null))
			);
		}

		return $values;
	}//end computeMetrics()

	/**
	 * The `as` alias of a derived (expression) metric, which it must declare.
	 *
	 * @param array<string, mixed> $entry The metric entry.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the derived metric has no `as`.
	 */
	private function derivedMetricAlias(array $entry): string {
		$derivedAlias = ($entry['as'] ?? null);
		if (is_string($derivedAlias) === false || $derivedAlias === '') {
			throw new RuntimeException(
				'A derived metric must declare `as`: it has no field or metric name to '
				.'derive a response key from.'
			);
		}

		return $derivedAlias;
	}//end derivedMetricAlias()

	/**
	 * The rows a metric entry counts: all of them, or those its `condition` matches.
	 *
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @param array<string, mixed>             $entry The metric entry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function metricEntryRows(array $rows, array $entry): array {
		$condition = ($entry['condition'] ?? null);
		if (is_array($condition) === true && $condition !== []) {
			return $this->applyFilter(rows: $rows, filter: $condition);
		}

		return $rows;
	}//end metricEntryRows()

	/**
	 * The response key of a metric entry: its `as` alias, else the metric/field key.
	 *
	 * @param array<string, mixed> $entry The metric entry.
	 *
	 * @return string
	 */
	private function metricEntryKey(array $entry): string {
		$alias = ($entry['as'] ?? null);
		$key = AggregationQuery::metricResponseKey(
			metric: $entry['metric'],
			field: ($entry['field'] ?? null)
		);
		if (is_string($alias) === true && $alias !== '') {
			$key = $alias;
		}

		return $key;
	}//end metricEntryKey()

	/**
	 * Resolve a raw groupBy spec to an ordered list of non-empty group field
	 * names, honouring every accepted shape (single `{field}`, multi
	 * `{fields:[...]}`, plain list `[...]`).
	 *
	 * Invalid members (non-string / empty) are dropped defensively for the
	 * named-annotation path where the spec is not pre-validated by
	 * {@see AggregationQuery::create()}. Returns an empty list for an
	 * ungrouped request, which the callers treat as "compute a scalar".
	 *
	 * @param mixed $groupBy Raw groupBy spec from the annotation or query.
	 *
	 * @return array<int, string> Ordered, de-duplicated group field names.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function resolveGroupFields(mixed $groupBy): array {
		$fields = [];
		foreach (AggregationQuery::normaliseGroupByFields(groupBy: $groupBy) as $field) {
			if (is_string($field) === false || $field === '') {
				continue;
			}

			if (in_array($field, $fields, true) === true) {
				continue;
			}

			$fields[] = $field;
		}

		return $fields;
	}//end resolveGroupFields()

	/**
	 * Compute a grouped metric, bucketing rows by one or more group fields.
	 *
	 * Single-field groupBy yields the backward-compatible `{key, value}`
	 * row shape. Multi-field (cross-tab) groupBy yields a composite
	 * `{keys: {fieldA: ..., fieldB: ...}, value}` row per distinct tuple so
	 * a consumer can pivot the result into a cross-tab. The bucket order is
	 * the first-seen order of each distinct tuple.
	 *
	 * @param array<int, array<string, mixed>> $rows        Already-filtered rows.
	 * @param RowAggregation                   $aggregation The metric over its field, the group fields and the optional
	 *                                                      multi-metric list (with more than one entry each group row
	 *                                                      carries a `values` map instead of a single scalar `value`).
	 *
	 * @return array<int, array{key?: mixed, keys?: array<string, mixed>, value?: int|float|null, values?: array<string, int|float|null>}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 * @spec openspec/specs/aggregation-api/spec.md
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function computeGrouped(array $rows, RowAggregation $aggregation): array {
		$metrics       = $aggregation->metrics;
		$isMulti       = (count($aggregation->groupFields) > 1);
		$isMultiMetric = (is_array($metrics) === true && count($metrics) > 1);

		$out = [];
		foreach ($this->groupBuckets(rows: $rows, groupFields: $aggregation->groupFields) as $bucket) {
			if ($isMultiMetric === true) {
				$figure = ['values' => $this->computeMetrics(rows: $bucket['rows'], metrics: $metrics)];
			} else {
				$figure = ['value' => $this->computeMetric(rows: $bucket['rows'], aggregation: $aggregation)];
			}

			$out[] = $this->groupKeyOf(tuple: $bucket['tuple'], isMulti: $isMulti) + $figure;
		}

		return $out;
	}//end computeGrouped()

	/**
	 * The rows bucketed by their group-field tuple, in first-seen order.
	 *
	 * @param array<int, array<string, mixed>> $rows        The rows.
	 * @param array<int, string>               $groupFields The group fields.
	 *
	 * @return array<string, array{tuple: array<string, mixed>, rows: array<int, array<string, mixed>>}>
	 */
	private function groupBuckets(array $rows, array $groupFields): array {
		$buckets = [];
		foreach ($rows as $row) {
			$tuple = [];
			foreach ($groupFields as $groupField) {
				$tuple[$groupField] = ($row[$groupField] ?? null);
			}

			$key = json_encode($tuple);
			$buckets[$key] ??= ['tuple' => $tuple, 'rows' => []];
			$buckets[$key]['rows'][] = $row;
		}

		return $buckets;
	}//end groupBuckets()

	/**
	 * A group's key: the whole tuple as `keys` for several fields, else its one value as `key`.
	 *
	 * @param array<string, mixed> $tuple   The group-field tuple.
	 * @param bool                 $isMulti Whether there are several group fields.
	 *
	 * @return array<string, mixed>
	 */
	private function groupKeyOf(array $tuple, bool $isMulti): array {
		if ($isMulti === true) {
			return ['keys' => $tuple];
		}

		return ['key' => reset($tuple)];
	}//end groupKeyOf()

	/**
	 * The numeric values of a column, as floats, in row order; non-numeric values are skipped.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows to read.
	 * @param string                           $field Column to read.
	 *
	 * @return array<int, float>
	 */
	private function numericValues(array $rows, string $field): array {
		$values = [];
		foreach ($rows as $row) {
			$value = $row[$field] ?? null;
			if (is_numeric($value) === true) {
				$values[] = (float) $value;
			}
		}

		return $values;
	}//end numericValues()

	/**
	 * Reduce values with a binary reducer seeded by the first value; null when there are none.
	 *
	 * @param array<int, float>              $values  The values, in order.
	 * @param callable(float, float): float  $reducer Binary reducer applied to (acc, value).
	 *
	 * @return float|null
	 */
	private static function reduceFromFirst(array $values, callable $reducer): ?float {
		if (count($values) === 0) {
			return null;
		}

		$acc = array_shift($values);
		foreach ($values as $value) {
			$acc = $reducer($acc, $value);
		}

		return $acc;
	}//end reduceFromFirst()

	/**
	 * Compute the arithmetic mean of a numeric column.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to average.
	 * @param string $field Column to read.
	 *
	 * @return float|null The mean, or null when no numeric rows were seen.
	 */
	private function avg(array $rows, string $field): ?float {
		$sum = 0.0;
		$count = 0;
		foreach ($rows as $row) {
			$value = $row[$field] ?? null;
			if (is_numeric($value) === false) {
				continue;
			}

			$sum += (float)$value;
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
	 * REQ-AGG-106 / bug #2027: a bare array criterion (a plain list, not an
	 * operator map such as `{in: [...]}`) is treated as an implicit `in`
	 * (any-of) match rather than being silently ignored — previously
	 * `is_array($criterion) === true` routed a bare list into the operator
	 * loop below, where its numeric keys (`0`, `1`, …) matched no known
	 * operator and `checkOp()`'s `default => true` let every row through
	 * unfiltered. Scalar equality and every wrapped operator additionally
	 * honour a multi-value (array) ROW value (e.g. a `tags` property stored
	 * as a JSON array) via {@see valueMatchesAnyOf()}'s any-overlap test,
	 * instead of the whole-array identity compare that never matched.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to filter.
	 * @param array<string, mixed> $filter Filter map (scalar = eq, plain list = implicit `in`,
	 *                                     assoc array = operator map).
	 *
	 * @return array<int, array<string, mixed>> Filtered rows.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function applyFilter(array $rows, array $filter): array {
		$result = [];
		foreach ($rows as $row) {
			$keep = true;
			foreach ($filter as $field => $criterion) {
				if ($this->criterionMatches(value: ($row[$field] ?? null), criterion: $criterion) === false) {
					$keep = false;
					break;
				}
			}

			if ($keep === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end applyFilter()

	/**
	 * Whether one row value satisfies one filter criterion.
	 *
	 * @param mixed $value     The value extracted from the row.
	 * @param mixed $criterion Scalar = eq, plain list = implicit `in`, assoc array = operator map,
	 *                         or the list endpoint's `IS NULL` / `IS NOT NULL` / `{isnull: bool}`.
	 *
	 * @return bool True when the value matches.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function criterionMatches(mixed $value, mixed $criterion): bool {
		// `IS NULL` / `IS NOT NULL` / `{isnull: bool}`, the list endpoint's
		// spelling of "empty" and "filled". As a scalar it would otherwise be
		// compared as the literal text.
		$wantsNull = NullFilter::nullCheck(criterion: $criterion);
		if ($wantsNull !== null) {
			return ($value === null) === $wantsNull;
		}

		// Bare array criterion (a plain, non-associative list) is an
		// implicit `in` — any-of match against the candidate list.
		if (is_array($criterion) === true && array_is_list($criterion) === true) {
			return $this->valueMatchesAnyOf(rowValue: $value, candidates: $criterion);
		}

		if (is_array($criterion) === false) {
			// Scalar equality. valueMatchesAnyOf() folds in the
			// BUG-SVC-9 string-cast coercion (magic-table columns
			// come back as strings while the criterion may be
			// int/bool/float) AND the multi-value row-overlap test.
			return $this->valueMatchesAnyOf(rowValue: $value, candidates: [$criterion]);
		}

		foreach ($criterion as $op => $onValue) {
			if ($this->operatorCheck(op: (string)$op)($value, $onValue) === false) {
				return false;
			}
		}

		return true;
	}//end criterionMatches()

	/**
	 * The check of a single operator, applied as `$check($value, $onValue)`.
	 *
	 * `eq`/`ne`/`in`/`notIn` all route through {@see valueMatchesAnyOf()} —
	 * REQ-AGG-106 / bug #2027 — so a multi-value (array) row value (a
	 * property stored as a JSON array) is tested via any-overlap against
	 * the operand rather than a whole-array identity compare that never
	 * matched, AND a scalar row value gets the same BUG-SVC-9 string-cast
	 * coercion `eq` already had (magic-table columns come back as strings).
	 * `gt`/`gte`/`lt`/`lte` are unaffected — range comparisons over a
	 * multi-value row aren't part of this fix's scope.
	 *
	 * @param string $op Operator name ('eq','ne','gt','gte','lt','lte','in','notIn','isnull').
	 *
	 * @return \Closure(mixed, mixed): bool True when the row value satisfies the operator against the operand.
	 *
	 * @throws RuntimeException When the operator is not one of the nine implemented
	 *                          spellings. It previously matched every row instead,
	 *                          silently widening the filter.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function operatorCheck(string $op): \Closure {
		$check = ($this->operatorChecks()[$op] ?? null);
		if ($check !== null) {
			return $check;
		}

		// AN UNRECOGNISED OPERATOR IS REFUSED, NOT IGNORED.
		//
		// This arm used to be `default => true`, which let an unknown operator
		// match EVERY row. The filter then silently widened instead of
		// narrowing, and the caller got a bigger answer rather than an error.
		//
		// Measured in shillinq: `"lifecycleState": {"not-in": ["paid",
		// "written-off"]}` — the implemented spelling is `notIn`, so `not-in`
		// fell through here and the AR-ageing figures silently INCLUDED settled
		// invoices. A wrong total in a finance report is far worse than a 500,
		// and it is the same failure family as a string `groupBy` quietly
		// collapsing a breakdown into a grand total.
		//
		// Refusing also makes the unimplemented operators visible rather than
		// inert: `equals`, `not`, `between`, `gteOrNull`, `notStartsWith` and
		// `not_in` all appear in declarations today and none of them do
		// anything. They should fail loudly until they are either implemented
		// or rewritten.
		throw new RuntimeException(
			'Unsupported aggregation filter operator "' . $op . '". Supported: '
			. 'eq, ne, gt, gte, lt, lte, in, notIn, isnull. An unknown operator used to match every '
			. 'row, which silently widened the filter instead of narrowing it.'
		);
	}//end operatorCheck()

	/**
	 * The implemented operators, each as a check of (row value, operand).
	 *
	 * @return array<string, \Closure(mixed, mixed): bool>
	 */
	private function operatorChecks(): array {
		return $this->operatorChecks ??= [
			NullFilter::ISNULL_OPERATOR => static fn (mixed $value, mixed $onValue): bool =>
				($value === null) === NullFilter::isNullOperatorAsksForNull(value: $onValue),
			'eq' => fn (mixed $value, mixed $onValue): bool =>
				$this->valueMatchesAnyOf(rowValue: $value, candidates: [$onValue]),
			'ne' => fn (mixed $value, mixed $onValue): bool =>
				($this->valueMatchesAnyOf(rowValue: $value, candidates: [$onValue]) === false),
			'in' => fn (mixed $value, mixed $onValue): bool => $this->valueInList(value: $value, onValue: $onValue),
			'notIn' => fn (mixed $value, mixed $onValue): bool => $this->valueNotInList(value: $value, onValue: $onValue),
			'gt' => fn (mixed $value, mixed $onValue): bool =>
				($pair = $this->normalisedPair(value: $value, onValue: $onValue)) !== null && $pair[0] > $pair[1],
			'gte' => fn (mixed $value, mixed $onValue): bool =>
				($pair = $this->normalisedPair(value: $value, onValue: $onValue)) !== null && $pair[0] >= $pair[1],
			'lt' => fn (mixed $value, mixed $onValue): bool =>
				($pair = $this->normalisedPair(value: $value, onValue: $onValue)) !== null && $pair[0] < $pair[1],
			'lte' => fn (mixed $value, mixed $onValue): bool =>
				($pair = $this->normalisedPair(value: $value, onValue: $onValue)) !== null && $pair[0] <= $pair[1],
		];
	}//end operatorChecks()

	/**
	 * Whether the operand is a list and the row value matches any of it (`in`).
	 *
	 * @param mixed $value   The value extracted from the row.
	 * @param mixed $onValue The operand.
	 *
	 * @return bool
	 */
	private function valueInList(mixed $value, mixed $onValue): bool {
		return (is_array($onValue) === true
			&& $this->valueMatchesAnyOf(rowValue: $value, candidates: $onValue) === true);
	}//end valueInList()

	/**
	 * Whether the operand is not a list or the row value matches none of it (`notIn`).
	 *
	 * @param mixed $value   The value extracted from the row.
	 * @param mixed $onValue The operand.
	 *
	 * @return bool
	 */
	private function valueNotInList(mixed $value, mixed $onValue): bool {
		return (is_array($onValue) === false
			|| $this->valueMatchesAnyOf(rowValue: $value, candidates: $onValue) === false);
	}//end valueNotInList()

	/**
	 * Both sides normalised for a range check, or null when either side is null.
	 *
	 * Dates compare as timestamps (see normaliseForCompare()); a null on
	 * either side never satisfies a range check.
	 *
	 * @param mixed $value   The value extracted from the row.
	 * @param mixed $onValue The operand.
	 *
	 * @return array{0: mixed, 1: mixed}|null
	 */
	private function normalisedPair(mixed $value, mixed $onValue): ?array {
		$cmp = $this->normaliseForCompare(v: $value);
		$rhs = $this->normaliseForCompare(v: $onValue);
		if ($cmp === null || $rhs === null) {
			return null;
		}

		return [$cmp, $rhs];
	}//end normalisedPair()

	/**
	 * The SQL that asks for an empty or a filled column.
	 *
	 * @param bool $wantsNull True for an empty column.
	 *
	 * @return string ` IS NULL` or ` IS NOT NULL`.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nullPredicate(bool $wantsNull): string {
		if ($wantsNull === true) {
			return ' IS NULL';
		}

		return ' IS NOT NULL';
	}//end nullPredicate()

	/**
	 * Test whether a row value matches any of the given candidates.
	 *
	 * Handles both shapes uniformly (REQ-AGG-106 / bug #2027):
	 *  - a scalar row value matches when it loosely (string-cast) equals
	 *    ANY candidate — e.g. magic-table `"1"` against candidate `1`;
	 *  - a multi-value (array) row value — a property stored as a JSON
	 *    array — matches when ANY of its members loosely equals ANY
	 *    candidate (any-overlap), instead of comparing the whole array as
	 *    a single opaque value (which never matches a scalar candidate).
	 *
	 * @param mixed $rowValue The value extracted from the row (scalar, array, or null).
	 * @param array<int|string, mixed> $candidates One or more candidate values to match against.
	 *
	 * @return bool True when the row value overlaps any candidate.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function valueMatchesAnyOf(mixed $rowValue, array $candidates): bool {
		$haystack = $rowValue;
		if (is_array($haystack) === false) {
			$haystack = [$haystack];
		}

		foreach ($haystack as $item) {
			foreach ($candidates as $candidate) {
				if ($this->scalarLooseEquals(a: $item, b: $candidate) === true) {
					return true;
				}
			}
		}

		return false;
	}//end valueMatchesAnyOf()

	/**
	 * Loose scalar equality: string-cast comparison when both sides are
	 * scalar (BUG-SVC-9 — magic-table columns come back as strings while
	 * the criterion may be int/bool/float), strict identity otherwise
	 * (null / array / object members are never coerced).
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 *
	 * @return bool True when the two values are considered equal.
	 */
	private function scalarLooseEquals(mixed $a, mixed $b): bool {
		if (is_scalar($a) === true && is_scalar($b) === true) {
			return ((string)$a === (string)$b);
		}

		return ($a === $b);
	}//end scalarLooseEquals()

	/**
	 * Coerce date-like scalars to integer timestamps for ordered comparisons.
	 *
	 * @param mixed $v The value to normalise.
	 *
	 * @return mixed Integer timestamp for date-like values, original otherwise.
	 */
	private function normaliseForCompare(mixed $v): mixed {
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
	 * Supports: count/sum/avg/min/max + simple equality/operator filters
	 * + optional categorical groupBy on ONE OR MORE fields (`GROUP BY a, b`
	 * → one row per distinct tuple) + optional time-bucketing. The
	 * categorical groupBy and time-bucket paths run natively on Postgres,
	 * MySQL and SQLite; the ungrouped scalar path is Postgres-only (the
	 * others fall through to the PHP fallback).
	 *
	 * Multi-field groupBy returns each group row as
	 * `{keys: {fieldA: ..., fieldB: ...}, value}`; single-field groupBy
	 * keeps the backward-compatible `{key, value}` shape.
	 *
	 * Returns the result fragment ('value' or 'groups') on success, null
	 * to signal the caller should fall back to PHP-side aggregation.
	 *
	 * @param Register $register Register the schema belongs to.
	 * @param Schema $schema Schema being aggregated.
	 * @param string $metric Metric name (count/sum/avg/min/max).
	 * @param string|null $field Field to aggregate over (ignored for count).
	 * @param array<string, mixed> $filter Already placeholder-resolved filter map.
	 * @param array<string, mixed>|null $groupBy Optional group spec: single-field ({field: ...}),
	 *                                           multi-field ({fields: [...]}), or a plain list
	 *                                           ([...]).
	 * @param array<string, mixed>|null $dateBucket Optional time-bucket spec ({field, start, end, gap}).
	 *                                              When supplied, the query becomes a
	 *                                              `date_trunc`-bucketed series with explicit `WHERE
	 *                                              field >= start AND field < end` bounds. Mutually
	 *                                              exclusive with $groupBy (validated by
	 *                                              AggregationQuery::create() upstream).
	 * @param array<int, array{metric: string, field: ?string}>|null $metrics Optional multi-metric list
	 *                                                                        (REQ-AGG-102). When it
	 *                                                                        carries more than one
	 *                                                                        entry, this method
	 *                                                                        dispatches to {@see
	 *                                                                        tryNativeMultiMetric()}
	 *                                                                        instead of running the
	 *                                                                        single-metric path below;
	 *                                                                        `$metric`/`$field` are
	 *                                                                        then ignored.
	 * @param bool $cumulative Running-total flag
	 *                         (REQ-AGG-103),
	 *                         only consulted
	 *                         when `$dateBucket`
	 *                         is set. On
	 *                         Postgres this adds
	 *                         a `SUM(...) OVER
	 *                         (ORDER BY bucket)`
	 *                         window column to
	 *                         the SQL (native
	 *                         running total).
	 *                         MySQL and SQLite
	 *                         fall through to
	 *                         the {@see
	 *                         addCumulativeColumn()}
	 *                         PHP post-pass —
	 *                         the two MUST agree
	 *                         bucket-for- bucket
	 *                         (design D3).
	 * @param bool $rowRbac Apply the caller's read-rule row predicate (false only for bypassRbac callers).
	 *
	 * @return array{value: int|float|null}
	 *                                      |array{values: array<string, int|float|null>}
	 *                                      |array{groups: array<int, array{key: mixed, value: int|float|null, cumulative?: int|float}>}
	 *                                      |null
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)  rowRbac threads the bypassRbac decision down to the SQL.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)     cumulative and rowRbac are request flags
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 * @spec openspec/specs/aggregation-api/spec.md
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function tryNativeAggregation(
		Register $register,
		Schema $schema,
		string $metric,
		?string $field,
		array $filter,
		?array $groupBy,
		?array $dateBucket = null,
		?array $metrics = null,
		bool $cumulative = false,
		bool $rowRbac = true,
	): ?array {
		return $this->tryNativeWithSpec(
			request: new NativeAggregationRequest(
				register: $register,
				schema: $schema,
				spec: [
					'metric'     => $metric,
					'field'      => $field,
					'filter'     => $filter,
					'groupBy'    => $groupBy,
					'dateBucket' => $dateBucket,
					'metrics'    => $metrics,
					'cumulative' => $cumulative,
					'rowRbac'    => $rowRbac,
				]
			)
		);
	}//end tryNativeAggregation()

	/**
	 * Dispatch a native aggregation request to the multi-metric or single-metric path.
	 *
	 * @param NativeAggregationRequest $request The register, schema and aggregation asked.
	 *
	 * @return array|null Null sends the caller to the PHP path
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function tryNativeWithSpec(NativeAggregationRequest $request): ?array {
		$spec = $request->spec;
		if ($this->nativePathCannotExpress(metrics: $spec['metrics'], groupBy: $spec['groupBy']) === true) {
			return null;
		}

		if (is_array($spec['metrics']) === true && count($spec['metrics']) > 1) {
			return $this->tryNativeMultiMetric(request: $request);
		}

		return $this->tryNativeSingleMetric(request: $request);
	}//end tryNativeWithSpec()

	/**
	 * Run one metric natively, or answer null to send the caller to the PHP path.
	 *
	 * @param NativeAggregationRequest $request The register, schema and aggregation asked.
	 *
	 * @return array|null
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function tryNativeSingleMetric(NativeAggregationRequest $request): ?array {
		$spec         = $request->spec;
		$platformName = $this->detectDatabasePlatform();
		if ($this->nativeRequestSupported(request: $request, platform: $platformName) === false) {
			return null;
		}

		$query = $this->nativeQueryFor(request: $request, platform: $platformName);
		if ($query === null) {
			return null;
		}

		$statement = new NativeAggregationStatement(
			query: $query,
			aggSql: $this->nativeAggregateSql(query: $query, field: $spec['field']),
			cumulative: $spec['cumulative']
		);
		return $this->runNativeQuery(statement: $statement, spec: $spec);
	}//end tryNativeSingleMetric()

	/**
	 * The native query over the schema's table with its WHERE built, or null when it cannot run.
	 *
	 * Null when the schema has no table, or when the row predicate or
	 * organisation scope cannot be expressed natively.
	 *
	 * @param NativeAggregationRequest $request  The register, schema and aggregation asked.
	 * @param string                   $platform The detected database platform.
	 *
	 * @return NativeAggregationQuery|null
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nativeQueryFor(NativeAggregationRequest $request, string $platform): ?NativeAggregationQuery {
		$spec      = $request->spec;
		$tableName = $this->magicMapper->getTableNameForRegisterSchema(register: $request->register, schema: $request->schema);
		if ($tableName === null || $tableName === '') {
			return null;
		}

		$query = new NativeAggregationQuery(
			platform: $platform,
			quote: $this->identifierQuote(platform: $platform),
			tableName: $tableName,
			metric: $spec['metric']
		);
		if ($this->buildNativeWhere(query: $query, request: $request) === false) {
			return null;
		}

		$this->addNativeFilterPredicates(query: $query, filter: $spec['filter']);
		return $query;
	}//end nativeQueryFor()

	/**
	 * Whether the native SQL supports the request's shape, metric and filter on this platform.
	 *
	 * @param NativeAggregationRequest $request  The register, schema and aggregation asked.
	 * @param string                   $platform The detected database platform.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nativeRequestSupported(NativeAggregationRequest $request, string $platform): bool {
		$spec = $request->spec;
		return $this->nativeShapeSupported(platform: $platform, spec: $spec) === true
			&& self::nativeMetricSupported(metric: $spec['metric'], field: $spec['field']) === true
			&& self::nativeFilterSupported(filter: $spec['filter'], schema: $request->schema) === true;
	}//end nativeRequestSupported()

	/**
	 * Whether the request holds something the native SQL cannot express.
	 *
	 * A per-metric `condition` (or an `as` alias) cannot run natively:
	 * tryNativeMultiMetric() emits one aggregate per entry over the same row set,
	 * so a condition would be silently ignored. A join-qualified group field
	 * cannot either: the SQL runs against the parent table alone, so every row
	 * would land in one null bucket. The PHP path honours both.
	 *
	 * @param array|null $metrics The multi-metric list
	 * @param array|null $groupBy The group spec
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nativePathCannotExpress(?array $metrics, ?array $groupBy): bool {
		if (is_array($metrics) === true && $this->metricsNeedPhpPath(metrics: $metrics) === true) {
			return true;
		}

		foreach ($this->resolveGroupFields(groupBy: $groupBy) as $groupField) {
			if (str_contains($groupField, '.') === true) {
				return true;
			}
		}

		return false;
	}//end nativePathCannotExpress()

	/**
	 * Whether the platform runs this query shape natively.
	 *
	 * Postgres runs every shape. MySQL and SQLite run the time-bucket and the
	 * grouped shapes; the ungrouped scalar shape depends on the Postgres numeric
	 * cast and falls through to the PHP path.
	 *
	 * @param string               $platform The platform short name
	 * @param array<string, mixed> $spec     The native request's spec (its `dateBucket` and `groupBy`)
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nativeShapeSupported(string $platform, array $spec): bool {
		if ($platform === 'unknown') {
			return false;
		}

		return $platform === 'postgres' || $spec['dateBucket'] !== null || count($this->resolveGroupFields(groupBy: $spec['groupBy'])) > 0;
	}//end nativeShapeSupported()

	/**
	 * Whether the metric runs natively.
	 *
	 * A value metric (sum/avg/min/max) needs a field: without one the SQL
	 * would be malformed (BUG-SVC-7). Only `count` runs without a field.
	 *
	 * @param string      $metric The metric
	 * @param string|null $field  The field the metric aggregates
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function nativeMetricSupported(string $metric, ?string $field): bool {
		if (in_array($metric, ['count', 'sum', 'avg', 'min', 'max'], true) === false) {
			return false;
		}

		return $metric === 'count' || ($field !== null && $field !== '');
	}//end nativeMetricSupported()

	/**
	 * Whether every filter translates to native SQL.
	 *
	 * Supported: a scalar, a list (implicit `in`, REQ-AGG-106 / #2027), and an
	 * operator map of in, notIn, gt, gte, lt, lte, ne and isnull. A filter on an
	 * array-type property is refused: its column holds the whole JSON array, so
	 * `=` or `IN` would compare serialised text instead of testing membership;
	 * the PHP path implements the any-overlap semantics.
	 *
	 * @param array  $filter The placeholder-resolved filter map
	 * @param Schema $schema The schema
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function nativeFilterSupported(array $filter, Schema $schema): bool {
		$operators = ['in', 'notIn', 'gt', 'gte', 'lt', 'lte', 'ne', NullFilter::ISNULL_OPERATOR];
		foreach ($filter as $value) {
			if (is_array($value) === true && array_is_list($value) === false
				&& array_diff(array_map('strval', array_keys($value)), $operators) !== []
			) {
				return false;
			}
		}

		$properties = ($schema->getProperties() ?? []);
		foreach (array_keys($filter) as $filterField) {
			if (($properties[(string) $filterField]['type'] ?? null) === 'array') {
				return false;
			}
		}

		return true;
	}//end nativeFilterSupported()

	/**
	 * Add the predicates every native aggregation applies before the caller's filters.
	 *
	 * This fast path bypasses MagicMapper, so it reproduces the list path's own
	 * rules: the soft-delete and archive defaults, the draft lens, the
	 * organisation boundary and the row-level read rule.
	 *
	 * @param NativeAggregationQuery   $query   The query being built
	 * @param NativeAggregationRequest $request The register, the schema and whether the caller's read rule applies (`rowRbac`)
	 *
	 * @return bool False when the organisation scope cannot be expressed in SQL
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function buildNativeWhere(NativeAggregationQuery $query, NativeAggregationRequest $request): bool {
		$this->addLifecyclePredicates(query: $query, schema: $request->schema);

		$orgScope = $this->nativeOrganisationScope(request: $request);
		if (self::addOrganisationPredicate(query: $query, orgScope: $orgScope) === false) {
			return false;
		}

		if ($request->spec['rowRbac'] === true) {
			$rowPredicate = $this->magicMapper->rbacRowPredicateSql(schema: $request->schema, register: $request->register);
			if ($rowPredicate !== null) {
				$query->where(predicate: $rowPredicate);
			}
		}

		return true;
	}//end buildNativeWhere()

	/**
	 * Add the soft-delete, archive and draft predicates.
	 *
	 * Postgres stores the metadata columns as jsonb; MySQL and SQLite as JSON
	 * text, where the empty marker is the string 'null', '' or NULL.
	 *
	 * @param NativeAggregationQuery $query  The query being built
	 * @param Schema                 $schema The schema
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function addLifecyclePredicates(NativeAggregationQuery $query, Schema $schema): void {
		foreach (['_deleted', '_archived'] as $column) {
			if ($query->isPostgres() === true) {
				$query->where(predicate: "({$column} IS NULL OR {$column} = 'null'::jsonb)");
				continue;
			}

			$query->where(predicate: "({$column} IS NULL OR {$column} = 'null' OR {$column} = '')");
		}

		$this->addDraftPredicate(query: $query, schema: $schema);
	}//end addLifecyclePredicates()

	/**
	 * Add DraftListLens's clause for the mode a list with no query uses.
	 *
	 * Left out of a table that has no `_status` column (REQ-MTH-002).
	 *
	 * @param NativeAggregationQuery $query  The query being built
	 * @param Schema                 $schema The schema
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function addDraftPredicate(NativeAggregationQuery $query, Schema $schema): void {
		$draftLens     = new DraftListLens();
		$statusMissing = in_array('_status', $this->magicMapper->getMetadataColumnHealer()->missingFor(tableName: $query->tableName), true);
		if ($statusMissing === true) {
			return;
		}

		$draftOwner   = $this->userSession->getUser()?->getUID();
		$ownerBinding = null;
		if ($draftOwner !== null && $draftOwner !== '') {
			$ownerBinding = '?';
		}

		$draftPredicate = $draftLens->clauseFor(
			listing: new DraftListing(mode: $draftLens->modeFor(query: [], schema: $schema), owner: $ownerBinding),
			columnPrefix: ''
		);
		if ($draftPredicate === null) {
			return;
		}

		$bindings = [];
		if ($ownerBinding !== null && str_contains($draftPredicate, '?') === true) {
			$bindings[] = $draftOwner;
		}

		$query->where(predicate: $draftPredicate, bindings: $bindings);
	}//end addDraftPredicate()

	/**
	 * The organisation scope the list path would apply to this register and schema.
	 *
	 * MagicOrganizationHandler decides; both paths render that decision. The
	 * register+schema pair goes with the question because a shared master-data
	 * declaration (REQ-SLE-001) widens the readable set for exactly that pair.
	 * Like the list (MagicSearchHandler::multitenancyApplies()), the boundary is
	 * waived when the caller reaches the rows through an RBAC rule, and widened
	 * by the org-less rows for a grant from the register cascade; both only
	 * together with the row predicate.
	 *
	 * @param NativeAggregationRequest $request The register, the schema and whether the caller's read rule applies (`rowRbac`)
	 *
	 * @return array{mode: string, uuids: array<int, string>}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)        admitOrganisationless is a pure scope transformation
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nativeOrganisationScope(NativeAggregationRequest $request): array {
		$schema   = $request->schema;
		$orgScope = $this->organizationHandler->resolveOrganizationScope(
			registerId: $request->register->getId(),
			schemaId: $schema->getId()
		);
		if ($request->spec['rowRbac'] === false) {
			return $orgScope;
		}

		// The list widens the boundary by the rows of the caller's conditional
		// `crossOrganisation` rules; this SQL cannot, so a mode it does not know sends the
		// aggregation to the PHP path, which reads through the list.
		if ($this->magicMapper->organisationBoundaryHasCrossOrganisationBranch(schema: $schema) === true) {
			return ['mode' => self::SCOPE_CROSS_ORGANISATION_BRANCH, 'uuids' => []];
		}

		if ($this->magicMapper->organisationBoundaryWaivedByRbac(schema: $schema) === true) {
			return ['mode' => OrganisationScopeResolverInterface::SCOPE_ALL, 'uuids' => []];
		}

		if ($this->magicMapper->organisationlessRowsAdmittedByRbac(schema: $schema) === true) {
			return OrganisationlessAdmission::admit(scope: $orgScope);
		}

		return $orgScope;
	}//end nativeOrganisationScope()

	/**
	 * Add the organisation boundary on the `_organisation` column.
	 *
	 * SQL `=` never matches NULL, so the org-less modes are spelled out rather
	 * than flattened into an equality.
	 *
	 * @param NativeAggregationQuery                     $query    The query being built
	 * @param array{mode: string, uuids: array<int, string>} $orgScope The organisation scope
	 *
	 * @return bool False for a mode this SQL cannot express, which the caller refuses
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function addOrganisationPredicate(NativeAggregationQuery $query, array $orgScope): bool {
		$orgColumn = $query->quoted(column: '_organisation');
		switch ($orgScope['mode']) {
			case OrganisationScopeResolverInterface::SCOPE_ALL:
				return true;
			case OrganisationScopeResolverInterface::SCOPE_NONE:
				$query->where(predicate: '1 = 0');
				return true;
			case OrganisationScopeResolverInterface::SCOPE_NULL_ONLY:
				$query->where(predicate: $orgColumn.' IS NULL');
				return true;
			case OrganisationScopeResolverInterface::SCOPE_IN:
			case OrganisationScopeResolverInterface::SCOPE_IN_OR_NULL:
				$inClause = $orgColumn.' IN ('.self::placeholders(count: count($orgScope['uuids'])).')';
				if ($orgScope['mode'] === OrganisationScopeResolverInterface::SCOPE_IN_OR_NULL) {
					$inClause = '('.$inClause.' OR '.$orgColumn.' IS NULL)';
				}

				$query->where(predicate: $inClause, bindings: array_values($orgScope['uuids']));
				return true;
			default:
				return false;
		}//end switch
	}//end addOrganisationPredicate()

	/**
	 * A comma-separated list of `count` placeholders.
	 *
	 * @param int $count The number of placeholders
	 *
	 * @return string
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function placeholders(int $count): string {
		return implode(', ', array_fill(0, $count, '?'));
	}//end placeholders()

	/**
	 * Add one predicate per filter, in filter order.
	 *
	 * @param NativeAggregationQuery $query  The query being built
	 * @param array                  $filter The filter map, already checked by nativeFilterSupported()
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function addNativeFilterPredicates(NativeAggregationQuery $query, array $filter): void {
		foreach ($filter as $name => $value) {
			$column    = $query->quoted(column: $this->sanitizeColumnName(name: (string) $name));
			$wantsNull = NullFilter::nullCheck(criterion: $value);
			if ($wantsNull !== null) {
				$query->where(predicate: $column.$this->nullPredicate(wantsNull: $wantsNull));
				continue;
			}

			if (is_array($value) === false) {
				$query->where(predicate: $column.' = ?', bindings: [$this->bindValue(value: $value)]);
				continue;
			}

			$target = new NativeFilterColumn(query: $query, column: $column);
			if (array_is_list($value) === true) {
				$this->addInPredicate(target: $target, list: $value);
				continue;
			}

			$this->addOperatorPredicates(target: $target, operators: $value);
		}
	}//end addNativeFilterPredicates()

	/**
	 * Add an `IN` predicate over a list; an empty list matches nothing.
	 *
	 * @param NativeFilterColumn $target The query being built and the quoted column
	 * @param array              $list   The values
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function addInPredicate(NativeFilterColumn $target, array $list): void {
		if (count($list) === 0) {
			$target->query->where(predicate: '1 = 0');
			return;
		}

		$target->query->where(
			predicate: $target->column.' IN ('.self::placeholders(count: count($list)).')',
			bindings: $this->listBindings(list: $list)
		);
	}//end addInPredicate()

	/**
	 * Add a `NOT IN` predicate over a list; an empty list excludes nothing.
	 *
	 * @param NativeFilterColumn $target The query being built and the quoted column
	 * @param array              $list   The values
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function addNotInPredicate(NativeFilterColumn $target, array $list): void {
		if (count($list) === 0) {
			$target->query->where(predicate: '1 = 1');
			return;
		}

		$target->query->where(
			predicate: $target->column.' NOT IN ('.self::placeholders(count: count($list)).')',
			bindings: $this->listBindings(list: $list)
		);
	}//end addNotInPredicate()

	/**
	 * The bindings of a list predicate, in list order.
	 *
	 * @param array $list The values
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function listBindings(array $list): array {
		return array_map(fn (mixed $item): string => $this->bindValue(value: $item), array_values($list));
	}//end listBindings()

	/**
	 * Add the predicates of an operator map such as `{gte: 1, lt: 5}`, in map order.
	 *
	 * @param NativeFilterColumn $target    The query being built and the quoted column
	 * @param array              $operators The operator map
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function addOperatorPredicates(NativeFilterColumn $target, array $operators): void {
		$query       = $target->query;
		$column      = $target->column;
		$comparisons = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'ne' => '<>'];
		foreach ($operators as $op => $operand) {
			$op = (string) $op;
			if ($op === 'in' || $op === 'notIn') {
				$list = [];
				if (is_array($operand) === true) {
					$list = $operand;
				}

				if ($op === 'notIn') {
					$this->addNotInPredicate(target: $target, list: $list);
					continue;
				}

				$this->addInPredicate(target: $target, list: $list);
				continue;
			}

			if ($op === NullFilter::ISNULL_OPERATOR) {
				$query->where(
					predicate: $column.$this->nullPredicate(wantsNull: NullFilter::isNullOperatorAsksForNull(value: $operand))
				);
				continue;
			}

			if (isset($comparisons[$op]) === true) {
				$query->where(predicate: $column.' '.$comparisons[$op].' ?', bindings: [$this->bindValue(value: $operand)]);
			}
		}//end foreach
	}//end addOperatorPredicates()

	/**
	 * The aggregate expression of the query's metric over a field.
	 *
	 * Postgres needs explicit `::numeric` casts because magic-table data columns
	 * are jsonb-shaped; MySQL and SQLite convert implicitly. NULLIF guards
	 * against the empty strings a jsonb-to-text cast produces.
	 *
	 * @param NativeAggregationQuery $query The query being built
	 * @param string|null            $field The field to aggregate
	 *
	 * @return string
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nativeAggregateSql(NativeAggregationQuery $query, ?string $field): string {
		if ($query->metric === 'count') {
			return 'COUNT(*)';
		}

		$aggCol = null;
		if ($field !== null) {
			$aggCol = $query->quoted(column: $this->sanitizeColumnName(name: $field));
		}

		$function = strtoupper($query->metric);
		if ($query->isPostgres() === true) {
			return $function.'(NULLIF('.$aggCol.'::text, \'\')::numeric)';
		}

		return $function.'(NULLIF('.$aggCol.', \'\') + 0)';
	}//end nativeAggregateSql()

	/**
	 * Run the native statement for the query's shape.
	 *
	 * A failing statement (missing table or column) answers null, which sends
	 * the caller to the PHP path.
	 *
	 * @param NativeAggregationStatement $statement The built query, its aggregate expression and the running-total flag
	 * @param array<string, mixed>       $spec      The native request's spec (its `dateBucket` and `groupBy`)
	 *
	 * @return array|null
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function runNativeQuery(NativeAggregationStatement $statement, array $spec): ?array {
		try {
			if ($spec['dateBucket'] !== null) {
				return $this->runNativeBucketQuery(statement: $statement, dateBucket: $spec['dateBucket']);
			}

			$groupFields = $this->resolveGroupFields(groupBy: $spec['groupBy']);
			if (count($groupFields) > 0) {
				return $this->runNativeGroupedQuery(statement: $statement, groupFields: $groupFields);
			}

			return $this->runNativeScalarQuery(statement: $statement);
		} catch (\Throwable $e) {
			return null;
		}
	}//end runNativeQuery()

	/**
	 * Run a time-bucketed series.
	 *
	 * The bucket bounds are added as `field >= start AND field < end`. On
	 * Postgres the running total is a `SUM(...) OVER (ORDER BY bucket)` window
	 * column (REQ-AGG-103); MySQL and SQLite compute it in the
	 * addCumulativeColumn() PHP post-pass, and the two must agree bucket for
	 * bucket (design D3, pinned by AggregationRunnerCumulativeTest).
	 *
	 * @param NativeAggregationStatement $statement  The built query, its aggregate expression and the running-total flag
	 * @param array                      $dateBucket The time-bucket spec ({field, start, end, gap})
	 *
	 * @return array{groups: array<int, array{key: string, value: int|float|null, cumulative?: int|float|null}>}
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function runNativeBucketQuery(NativeAggregationStatement $statement, array $dateBucket): array {
		$query     = $statement->query;
		$aggSql    = $statement->aggSql;
		$bucketCol = $query->quoted(column: $this->sanitizeColumnName(name: (string) $dateBucket['field']));

		$cumulativeSelect = '';
		if ($statement->windowedTotal() === true) {
			$cumulativeSelect = ", SUM({$aggSql}) OVER (ORDER BY bucket) AS cumulative_agg";
		}

		$bucketExpr   = $this->nativeBucketExpression(
			column: new NativeFilterColumn(query: $query, column: $bucketCol),
			gap: (string) $dateBucket['gap']
		);
		$boundedWhere = $query->whereSql().' AND '.$bucketCol.' >= ? AND '.$bucketCol.' < ?';
		$stmt         = $this->db->prepare(
			"SELECT {$bucketExpr} AS bucket, {$aggSql} AS agg{$cumulativeSelect}
                         FROM {$query->table()}
                         WHERE {$boundedWhere}
                         GROUP BY bucket
                         ORDER BY bucket"
		);
		$stmt->execute($this->nativeBucketBindings(query: $query, dateBucket: $dateBucket));

		$groups = [];
		while (($row = $stmt->fetch()) !== false) {
			$groups[] = $this->nativeBucketRow(row: $row, statement: $statement);
		}

		if ($statement->cumulative === true && $query->isPostgres() === false) {
			$groups = $this->addCumulativeColumn(groups: $groups);
		}

		return ['groups' => $groups];
	}//end runNativeBucketQuery()

	/**
	 * The bindings of a time-bucketed series in placeholder order.
	 *
	 * The WHERE bindings come first and the bucket bounds last; on Postgres the
	 * gap goes in front, for the `date_trunc(?, ...)` in the SELECT list.
	 *
	 * @param NativeAggregationQuery $query      The built query
	 * @param array                  $dateBucket The time-bucket spec ({field, start, end, gap})
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nativeBucketBindings(NativeAggregationQuery $query, array $dateBucket): array {
		$bindings   = $query->bindings();
		$bindings[] = $this->bindValue(value: $dateBucket['start']);
		$bindings[] = $this->bindValue(value: $dateBucket['end']);
		if ($query->isPostgres() === true) {
			array_unshift($bindings, (string) $dateBucket['gap']);
		}

		return $bindings;
	}//end nativeBucketBindings()

	/**
	 * One bucket of a time-bucketed series.
	 *
	 * @param array                      $row       The fetched row
	 * @param NativeAggregationStatement $statement The statement; its metric, and whether the row carries a native running total
	 *
	 * @return array{key: string, value: int|float|null, cumulative?: int|float|null}
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nativeBucketRow(array $row, NativeAggregationStatement $statement): array {
		$metric = $statement->query->metric;
		$group  = [
			'key'   => $this->coerceBucketKey(raw: $row['bucket']),
			'value' => $this->coerceAggregateValue(raw: $row['agg'], metric: $metric),
		];
		if ($statement->windowedTotal() === true) {
			$group['cumulative'] = $this->coerceAggregateValue(raw: ($row['cumulative_agg'] ?? null), metric: $metric);
		}

		return $group;
	}//end nativeBucketRow()

	/**
	 * The platform's bucketing expression for a column and gap.
	 *
	 * Postgres binds the gap into `date_trunc`; MySQL and SQLite emit literal
	 * format strings from the closed gap vocabulary AggregationQuery::create() validates.
	 *
	 * @param NativeFilterColumn $column The built query and the quoted bucket column
	 * @param string             $gap    The bucket gap
	 *
	 * @return string
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function nativeBucketExpression(NativeFilterColumn $column, string $gap): string {
		if ($column->query->isPostgres() === true) {
			return 'date_trunc(?, '.$column->column.')::text';
		}

		if ($column->query->platform === 'mysql') {
			return $this->mysqlBucketExpression(column: $column->column, gap: $gap);
		}

		return $this->sqliteBucketExpression(column: $column->column, gap: $gap);
	}//end nativeBucketExpression()

	/**
	 * Run a categorical grouped aggregate, single-field or multi-field.
	 *
	 * @param NativeAggregationStatement $statement   The built query and its aggregate expression
	 * @param array                      $groupFields The ordered group fields
	 *
	 * @return array{groups: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function runNativeGroupedQuery(NativeAggregationStatement $statement, array $groupFields): array {
		$query       = $statement->query;
		$selectParts = [];
		$groupCols   = [];
		foreach ($groupFields as $index => $groupField) {
			$groupCol      = $query->quoted(column: $this->sanitizeColumnName(name: $groupField));
			$groupCols[]   = $groupCol;
			$selectParts[] = $groupCol.' AS g'.$index;
		}

		$selectSql = implode(', ', $selectParts);
		$groupSql  = implode(', ', $groupCols);
		$stmt      = $this->db->prepare(
			"SELECT {$selectSql}, {$statement->aggSql} AS agg
                             FROM {$query->table()}
                             WHERE {$query->whereSql()}
                             GROUP BY {$groupSql}"
		);
		$stmt->execute($query->bindings());

		$groups = [];
		while (($row = $stmt->fetch()) !== false) {
			$groups[] = self::nativeGroupKeys(row: $row, groupFields: $groupFields)
				+ ['value' => self::nativeGroupValue(raw: $row['agg'], metric: $query->metric)];
		}

		return ['groups' => $groups];
	}//end runNativeGroupedQuery()

	/**
	 * The key of one group of a grouped aggregate: `{key}`, or `{keys}` for several group fields.
	 *
	 * @param array $row         The fetched row
	 * @param array $groupFields The ordered group fields
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function nativeGroupKeys(array $row, array $groupFields): array {
		if (count($groupFields) === 1) {
			return ['key' => ($row['g0'] ?? null)];
		}

		$keys = [];
		foreach ($groupFields as $index => $groupField) {
			$keys[$groupField] = ($row['g'.$index] ?? null);
		}

		return ['keys' => $keys];
	}//end nativeGroupKeys()

	/**
	 * The value of one group of a grouped aggregate.
	 *
	 * @param mixed  $raw    The fetched aggregate
	 * @param string $metric The metric
	 *
	 * @return int|float|null
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function nativeGroupValue(mixed $raw, string $metric): int|float|null {
		if ($metric !== 'count' && is_string($raw) === true) {
			return (float) $raw;
		}

		if ($raw !== null) {
			return (int) $raw;
		}

		return null;
	}//end nativeGroupValue()

	/**
	 * Run an ungrouped aggregate.
	 *
	 * @param NativeAggregationStatement $statement The built query and its aggregate expression
	 *
	 * @return array{value: int|float|string|null}
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function runNativeScalarQuery(NativeAggregationStatement $statement): array {
		$query = $statement->query;
		$stmt  = $this->db->prepare("SELECT {$statement->aggSql} AS agg FROM {$query->table()} WHERE {$query->whereSql()}");
		$stmt->execute($query->bindings());
		$row   = $stmt->fetch();
		$value = null;
		if ($row !== false) {
			$value = $row['agg'];
		}

		if ($query->metric === 'count') {
			return ['value' => (int) ($value ?? 0)];
		}

		if (is_string($value) === true) {
			$value = (float) $value;
		}

		return ['value' => $value];
	}//end runNativeScalarQuery()

	/**
	 * Multi-metric native dispatch (REQ-AGG-102).
	 *
	 * Runs the single-metric native path in {@see tryNativeAggregation()}
	 * once per requested `{metric, field}` entry — reusing its dialect
	 * branching and filter translation verbatim, with zero new SQL surface
	 * — and merges the per-metric results into one `values` map (ungrouped)
	 * or one `values` map per group (categorical groupBy). Trades one SQL
	 * round-trip per metric for reusing the already-proven single-metric
	 * query builder; acceptable for ad-hoc dashboard widgets, which are not
	 * a hot loop.
	 *
	 * Any single metric bailing to null (e.g. an ungrouped ask on a
	 * non-Postgres engine) bails the WHOLE multi-metric attempt to the PHP
	 * fallback, so the response is never a partial native/partial-PHP mix.
	 * Not used for the time-bucket (`dateBucket`) path — mutually exclusive
	 * with multi-metric, rejected upstream by AggregationQuery::create().
	 *
	 * @param NativeAggregationRequest $request The register, schema and aggregation asked: its
	 *                                          placeholder-resolved filter, optional group spec,
	 *                                          metric entries (>1) and whether the caller's
	 *                                          read-rule row predicate applies.
	 *
	 * @return array{values: AggValues}
	 *                                  |array{groups: array<int, array{key?: mixed, keys?: array<string, mixed>, values: AggValues}>}
	 *                                  |null
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function tryNativeMultiMetric(NativeAggregationRequest $request): ?array {
		$perMetric = $this->nativePerMetric(request: $request);
		if ($perMetric === null) {
			return null;
		}

		$groupFields = $this->resolveGroupFields(groupBy: $request->spec['groupBy']);
		if (count($groupFields) === 0) {
			$ungroupedValues = [];
			foreach ($perMetric as [$responseKey, $single]) {
				$ungroupedValues[$responseKey] = ($single['value'] ?? null);
			}

			return ['values' => $ungroupedValues];
		}

		return ['groups' => $this->mergeNativeMetricGroups(perMetric: $perMetric, isMulti: count($groupFields) > 1)];
	}//end tryNativeMultiMetric()

	/**
	 * Run each metric entry natively, in order; null as soon as one cannot run.
	 *
	 * One metric that cannot run natively bails the whole multi-metric
	 * attempt so the caller falls through to a single, consistent
	 * PHP-fallback computation.
	 *
	 * @param NativeAggregationRequest $request The register, schema and aggregation asked.
	 *
	 * @return list<array{0: string, 1: array<string, mixed>}>|null Response key and native result per entry.
	 */
	private function nativePerMetric(NativeAggregationRequest $request): ?array {
		$spec = $request->spec;
		$perMetric = [];
		foreach ($spec['metrics'] as $entry) {
			$single = $this->tryNativeAggregation(
				register: $request->register,
				schema: $request->schema,
				metric: $entry['metric'],
				field: ($entry['field'] ?? null),
				filter: $spec['filter'],
				groupBy: $spec['groupBy'],
				dateBucket: null,
				rowRbac: $spec['rowRbac']
			);

			if ($single === null) {
				return null;
			}

			$perMetric[] = [
				AggregationQuery::metricResponseKey(metric: $entry['metric'], field: ($entry['field'] ?? null)),
				$single,
			];
		}

		return $perMetric;
	}//end nativePerMetric()

	/**
	 * Merge the per-metric groups into one `values` map per group, in first-seen group order.
	 *
	 * @param list<array{0: string, 1: array<string, mixed>}> $perMetric Response key and native result per entry.
	 * @param bool                                            $isMulti   Whether groups carry a `keys` tuple.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function mergeNativeMetricGroups(array $perMetric, bool $isMulti): array {
		$keyName = 'key';
		if ($isMulti === true) {
			$keyName = 'keys';
		}

		$mergedByKey = [];
		foreach ($perMetric as [$responseKey, $single]) {
			foreach (($single['groups'] ?? []) as $group) {
				$keyData = ($group['key'] ?? null);
				if ($isMulti === true) {
					$keyData = ($group['keys'] ?? []);
				}

				$tupleKey = (string)json_encode($keyData);
				$mergedByKey[$tupleKey] ??= [$keyName => $keyData, 'values' => []];
				$mergedByKey[$tupleKey]['values'][$responseKey] = ($group['value'] ?? null);
			}
		}

		return array_values($mergedByKey);
	}//end mergeNativeMetricGroups()

	/**
	 * Coerce a raw native-SQL aggregate column value (`agg` or, for
	 * REQ-AGG-103, `cumulative_agg`) to the same numeric type the
	 * time-bucket path has always returned: `float` for a non-count
	 * metric whose driver returned a numeric string (Postgres numeric
	 * casts round-trip as strings), `int` otherwise (including `count`),
	 * `null` when the row column itself is `null`.
	 *
	 * Extracted so the per-bucket `value` and the running-total
	 * `cumulative` column (same SQL row, same metric) are coerced
	 * identically — the pre-existing inline logic this replaces is
	 * unchanged, just shared between the two columns.
	 *
	 * @param mixed $raw The raw column value from the DB row.
	 * @param string $metric One of count/sum/avg/min/max.
	 *
	 * @return int|float|null The coerced value.
	 */
	private function coerceAggregateValue(mixed $raw, string $metric): int|float|null {
		if ($metric !== 'count' && is_string($raw) === true) {
			return (float)$raw;
		}

		if ($raw !== null) {
			return (int)$raw;
		}

		return null;
	}//end coerceAggregateValue()

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
	private function coerceBucketKey(mixed $raw): string {
		if ($raw instanceof DateTimeInterface) {
			return $raw->format('Y-m-d\TH:i:s\Z');
		}

		if (is_string($raw) === false) {
			return (string)$raw;
		}

		// BUG-SVC-4: Postgres emits date/timestamp text WITHOUT a timezone
		// designator (e.g. "2026-06-01 00:00:00"). strtotime() parses such
		// offset-less text in the SERVER timezone, then gmdate() re-expresses
		// it as UTC, shifting every bucket label by the server's UTC offset
		// (e.g. CET buckets land an hour early). Parse offset-less shapes as
		// UTC explicitly; only fall back to strtotime for offset-bearing text.
		$hasTimezone = (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($raw)) === 1);
		if ($hasTimezone === false) {
			// `!` on the date-only shape: without it createFromFormat() takes
			// the missing time of day from the wall clock, so a day bucket
			// would be labelled with the moment of the request.
			$formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', '!Y-m-d'];
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
	private function detectDatabasePlatform(): string {
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
	private function identifierQuote(string $platform): string {
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
	 * @param string $gap Validated gap unit.
	 *
	 * @return string The full bucket SQL expression (without the `AS bucket` alias).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	private function mysqlBucketExpression(string $column, string $gap): string {
		if ($gap === 'week') {
			// ISO-Monday week-start: back-shift by ((DAYOFWEEK - 1 - 1) % 7)
			// days, but MySQL's DAYOFWEEK starts on Sunday=1 so the actual
			// expression is `((DAYOFWEEK(field) + 5) MOD 7)` which produces
			// 0 for Monday, 6 for Sunday — exactly the shift we need to
			// reach the previous (or current) Monday.
			return 'DATE_FORMAT(' . $column . ' - INTERVAL ((DAYOFWEEK(' . $column . ') + 5) MOD 7) DAY, \'%Y-%m-%dT00:00:00Z\')';
		}

		if ($gap === 'quarter') {
			return sprintf(
				'CONCAT(YEAR(%1$s), \'-\', LPAD(((QUARTER(%1$s) - 1) * 3 + 1), 2, \'0\'), \'-01T00:00:00Z\')',
				$column
			);
		}

		$format = match ($gap) {
			'minute' => '%Y-%m-%dT%H:%i:00Z',
			'hour' => '%Y-%m-%dT%H:00:00Z',
			'day' => '%Y-%m-%dT00:00:00Z',
			'month' => '%Y-%m-01T00:00:00Z',
			'year' => '%Y-01-01T00:00:00Z',
			default => '%Y-%m-%dT00:00:00Z',
		};

		return 'DATE_FORMAT(' . $column . ', \'' . $format . '\')';
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
	 * @param string $gap Validated gap unit.
	 *
	 * @return string The full bucket SQL expression (without the `AS bucket` alias).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	private function sqliteBucketExpression(string $column, string $gap): string {
		if ($gap === 'week') {
			return 'strftime(\'%Y-%m-%dT00:00:00Z\', ' . $column . ', \'weekday 0\', \'-6 days\')';
		}

		if ($gap === 'quarter') {
			$parts = [];
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
			'hour' => '%Y-%m-%dT%H:00:00Z',
			'day' => '%Y-%m-%dT00:00:00Z',
			'month' => '%Y-%m-01T00:00:00Z',
			'year' => '%Y-01-01T00:00:00Z',
			default => '%Y-%m-%dT00:00:00Z',
		};

		return 'strftime(\'' . $format . '\', ' . $column . ')';
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
	private function bindValue(mixed $value): string {
		if ($value instanceof DateTimeInterface) {
			return $value->format(DateTimeInterface::ATOM);
		}

		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		return (string)$value;
	}//end bindValue()

	/**
	 * Attach aggregates from a SECOND schema to each group of an already
	 * computed grouped envelope (the `join` clause).
	 *
	 * Spec shape (see {@see \OCA\OpenRegister\Search\Service\Aggregation\AggregationJoinAnnotationValidator::validate()}):
	 *   "join": {
	 *     "through": "CommitmentBudget",
	 *     "on":      "CommitmentBudget.programmeCode",
	 *     "filter":  { },
	 *     "select":  ["CommitmentBudget.authorised_amount"]
	 *   }
	 *
	 * IMPLEMENTATION: TWO-QUERY MERGE, NOT A SQL JOIN
	 * ------------------------------------------------
	 * The parent aggregate has already run. This method aggregates the
	 * JOINED schema independently — grouped on the joined side of the join
	 * key — and merges the two result sets in PHP on that key. It does NOT
	 * emit a `FROM parent JOIN joined` statement, deliberately:
	 *
	 * - The two schemas live in SEPARATE per-schema magic shard tables, and
	 *   possibly in separate registers. A single statement would have to
	 *   re-derive BOTH tables' tenancy (`_organisation`) and soft-delete
	 *   predicates by hand. Every one of those is a place to silently widen
	 *   the row set; routing the joined schema through the SAME
	 *   {@see tryNativeAggregation()} the parent used means it inherits
	 *   those predicates instead of re-implementing them.
	 * - {@see tryNativeAggregation()} answers `null` (fall back to PHP) for
	 *   any shape it cannot translate. A hand-written JOIN inside it would
	 *   have to fall back too, and the PHP fallback has no join — the two
	 *   paths would disagree exactly when the native path bailed.
	 *
	 * PERFORMANCE
	 * -----------
	 * Native path: `1 + N` queries, N = `count(select)`. Each is a grouped
	 * aggregate over the joined table (same cost class as the parent's own
	 * grouped query). Crucially the count is independent of the NUMBER OF
	 * GROUPS — this is not an N+1-per-row lookup; 4 groups and 40,000
	 * groups issue the same `1 + N` queries.
	 * PHP fallback: the joined table is hydrated ONCE (capped at
	 * {@see PHP_FALLBACK_ROW_CAP}) and all N select entries are computed
	 * from those rows, so the fallback is `1` extra table scan, not N. The
	 * merge itself is O(parent groups + joined groups) via a hash lookup.
	 * When the joined hydrate hits the cap the envelope's `join.truncated`
	 * flag is set — the joined numbers are then partial and the caller MUST
	 * treat them as such.
	 *
	 * RBAC
	 * ----
	 * A join MUST NOT become a way to read a schema the caller cannot read,
	 * NOR a way around the scoping the caller asked for. Four controls — the
	 * first three are the ones the cross-schema `from` path relies on; the
	 * fourth was missing and cost a cross-tenant read (see $extraFilter below):
	 *  1. An explicit `list` permission gate on the JOINED schema via
	 *     {@see PermissionHandlerInterface::hasPermission()}, refusing with
	 *     RuntimeException('Forbidden: ...') before a single row is read.
	 *  2. Tenancy: the joined aggregate runs through the same
	 *     {@see tryNativeAggregation()} (which appends the
	 *     `_organisation = ?` predicate, fail-closed to
	 *     `__no_active_org__` when there is no active organisation) or the
	 *     same {@see MagicMapper::findAllInRegisterSchemaTable()} the
	 *     parent uses (MultiTenancyTrait). Note that register RESOLUTION
	 *     ({@see findRegisterForSchema()}) deliberately bypasses
	 *     multi-tenancy as a metadata read — it is NOT a tenancy control,
	 *     and is not counted as one here.
	 *  3. The cache key in {@see run()} carries the whole join spec plus
	 *     userId + active organisation, so a permitted caller's joined
	 *     numbers can never be served to a caller with a different verdict.
	 *
	 * The run supplies the raw join spec (null is a no-op), the parent's
	 * ordered group fields, the internal-system mode (skips the read gate;
	 * tenancy predicates still apply — they are not bypassable here) and the
	 * caller's narrowing constraints (control 4, see narrowJoinedFilter()).
	 *
	 * @param NamedAggregationRun  $run      The run whose join is attached.
	 * @param array<string, mixed> $envelope The parent aggregation envelope (must carry `groups`).
	 *
	 * @return array<string, mixed> The envelope with a `joined` map on each group and a `join` summary.
	 *
	 * @throws RuntimeException When the join spec is unusable, the joined schema cannot be
	 *                          resolved, or the caller lacks read permission on it.
	 *
	 * The method is one linear pipeline (validate → resolve+gate → aggregate
	 * → merge); each step is its own method, called in that fail-closed order.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function applyJoin(NamedAggregationRun $run, array $envelope): array {
		$join = $run->getJoinArg();
		if ($join === null) {
			return $envelope;
		}

		$this->assertJoinable(envelope: $envelope, groupFields: $run->groupFields);

		$joined = $this->resolveJoin(run: $run, join: $join);
		$this->narrowJoinedFilter(joined: $joined, extraFilter: $run->appliedExtraFilter);

		return $this->attachJoinedValues(envelope: $envelope, joined: $joined);
	}//end applyJoin()

	/**
	 * Refuse a join that has no groups to attach to.
	 *
	 * @param array<string, mixed> $envelope    The parent aggregation envelope.
	 * @param array<int, string>   $groupFields The parent's ordered group fields.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the parent is ungrouped.
	 */
	private function assertJoinable(array $envelope, array $groupFields): void {
		if (count($groupFields) === 0) {
			throw new RuntimeException(
				'Aggregation join requires a groupBy — joined values are attached per group.'
			);
		}

		if (isset($envelope['groups']) === false || is_array($envelope['groups']) === false) {
			throw new RuntimeException('Aggregation join expected a grouped result to attach to.');
		}
	}//end assertJoinable()

	/**
	 * Parse the join spec, then load the joined schema, gate on it and locate its register.
	 *
	 * The spec is parsed and the gate passed BEFORE a single joined row is read.
	 *
	 * @param NamedAggregationRun  $run  The run whose join is attached.
	 * @param array<string, mixed> $join The raw join spec.
	 *
	 * @return JoinedAggregation The joined side, with its declared filter resolved.
	 *
	 * @throws RuntimeException When the join spec is unusable, the joined schema cannot be
	 *                          resolved, or the caller lacks read permission on it.
	 */
	private function resolveJoin(NamedAggregationRun $run, array $join): JoinedAggregation {
		$through = (string)($join['through'] ?? '');
		if ($through === '') {
			throw new RuntimeException('Aggregation join is missing a non-empty `through` schema ref.');
		}

		$onMap = $this->resolveJoinKey(join: $join, groupFields: $run->groupFields);
		$selectEntries = $this->parseJoinSelect(join: $join);

		// Load the joined schema, gate on it, and locate its register —
		// all BEFORE a single joined row is read.
		[$joinedSchema, $joinedRegister] = $this->resolveJoinTarget(
			through: $through,
			bypassRbac: $run->bypassRbac
		);

		$joined = new JoinedAggregation(
			through: $through,
			schema: $joinedSchema,
			register: $joinedRegister,
			rowRbac: $run->bypassRbac === false
		);
		$joined->onMap = $onMap;
		$joined->selectEntries = $selectEntries;
		$joined->filter = $this->placeholders->resolveArray((array)($join['filter'] ?? $join['where'] ?? []));
		return $joined;
	}//end resolveJoin()

	/**
	 * Narrow the joined filter by the caller's constraints the joined schema declares.
	 *
	 * @param JoinedAggregation    $joined      The joined side; its filter is narrowed here.
	 * @param array<string, mixed> $extraFilter The caller's narrowing constraints.
	 *
	 * @return void
	 */
	private function narrowJoinedFilter(JoinedAggregation $joined, array $extraFilter): void {
		// SECURITY (control 4): the caller's NARROWING filter must reach the
		// joined side too.
		//
		// It did not, and the result was a cross-tenant read. The parent rows
		// were correctly narrowed to one administration while the joined
		// aggregate was computed over EVERY administration, so the joined
		// figures described a different population than the groups they were
		// attached to. Measured on a live instance: CommitmentLine narrowed to
		// `ADM-001` returned the right sums, and the joined
		// `CommitmentBudget.authorised_amount` came back 160,000,000 — the
		// ADM-001 budget (80,000,000) plus both of `adm-demo`'s (50,000,000 +
		// 30,000,000), because the join matches on `programmeCode` alone. The
		// page rendered another tenant's money as this tenant's authorised
		// budget, with no error and a perfectly plausible number.
		//
		// mergeNarrowingFilter()'s docblock promises the failure mode is never
		// "you saw more than you should". On the parent that held; the join
		// bypassed it entirely.
		//
		// Restricted to keys the joined schema DECLARES. A filter on a property
		// a schema does not have is not an error in this stack — it matches
		// nothing and returns an empty set — so forwarding an unknown key would
		// silently zero the join instead of narrowing it, trading a figure that
		// is too big for one that is always zero. Keys the joined schema does
		// not declare are dropped and logged; the join then stays as wide as it
		// was, which is the pre-existing behaviour rather than a new one.
		$joinedProperties = array_keys(($joined->schema->getProperties() ?? []));
		$applicable = array_intersect_key($extraFilter, array_flip($joinedProperties));
		$ignored = array_keys(array_diff_key($extraFilter, $applicable));
		if ($ignored !== []) {
			$this->logger?->debug(
				'Aggregation join: caller filter keys not declared by the joined schema were dropped.',
				[
					'through' => $joined->through,
					'ignored' => $ignored,
				]
			);
		}

		if ($applicable !== []) {
			$joined->filter = $this->mergeNarrowingFilter(
				declared: $joined->filter,
				narrowing: new RequestedNarrowing(constraints: $applicable, aggregation: $joined->through . ' (join)')
			);
		}
	}//end narrowJoinedFilter()

	/**
	 * Aggregate the joined side and attach its values to each parent group.
	 *
	 * @param array<string, mixed> $envelope The parent aggregation envelope (carries `groups`).
	 * @param JoinedAggregation    $joined   The joined side.
	 *
	 * @return array<string, mixed> The envelope with a `joined` map on each group and a `join` summary.
	 */
	private function attachJoinedValues(array $envelope, JoinedAggregation $joined): array {
		$lookup = $this->aggregateJoinedSchema(joined: $joined);

		$aliases = $joined->getAliases();

		$envelope['groups'] = $this->mergeJoinedValues(
			groups: $envelope['groups'],
			lookup: new JoinedValueLookup(onMap: $joined->onMap, aliases: $aliases, values: $lookup['values'])
		);

		$envelope['join'] = [
			'through' => $joined->through,
			'on' => $joined->onMap,
			'select' => $aliases,
			'backend' => $lookup['backend'],
			'truncated' => $lookup['truncated'],
		];

		return $envelope;
	}//end attachJoinedValues()

	/**
	 * Merge caller-supplied constraints into a declared filter, narrowing only.
	 *
	 * A declared aggregation's filter is part of its contract. A caller may ADD
	 * a constraint; it may never relax, replace or remove one. That asymmetry
	 * is the entire point — a declared `administrationId` is a scoping filter,
	 * and a request that could overwrite it would turn tenancy into a
	 * caller-chosen parameter.
	 *
	 * Refusals are SILENT drops rather than exceptions, deliberately: this runs
	 * on a read path reached from a URL, and a 500 on a stray query parameter
	 * would make the page fail rather than the parameter. A dropped key leaves
	 * the declared constraint in force, so the failure mode is "your narrowing
	 * did not apply", never "you saw more than you should".
	 *
	 * Three rules:
	 *   1. a key the declaration already constrains is dropped — the
	 *      declaration wins, always;
	 *   2. only scalars are accepted. An array is an operator filter, and
	 *      `!=` / `>` / `in` can widen a set rather than narrow it;
	 *   3. an empty-string value is dropped, because a caller omitting a
	 *      parameter should not silently become a filter on ''.
	 *
	 * @param array<string, mixed> $declared  The declared, placeholder-resolved filter.
	 * @param RequestedNarrowing   $narrowing Caller-supplied constraints and the aggregation name, for the debug log.
	 *
	 * @return array<string, mixed> The merged filter.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function mergeNarrowingFilter(array $declared, RequestedNarrowing $narrowing): array {
		$extra = $narrowing->constraints;
		if ($extra === []) {
			return $declared;
		}

		$merged = $declared;
		foreach ($extra as $key => $value) {
			if (is_string($key) === false || $key === '') {
				continue;
			}

			if (array_key_exists($key, $declared) === true) {
				// `?->` because the logger is an OPTIONAL constructor argument
				// here (`?LoggerInterface $logger = null`). A bare `->debug()`
				// is a fatal whenever it was not injected — and a fatal on a
				// read path, thrown from the branch that ENFORCES the scoping
				// rule, would be the worst possible place for one.
				$this->logger?->debug(
					'Aggregation: refusing a request filter that would relax a declared one.',
					['aggregation' => $narrowing->aggregation, 'key' => $key]
				);
				continue;
			}

			if (is_scalar($value) === false || $value === '') {
				continue;
			}

			$merged[$key] = $value;
		}

		return $merged;
	}//end mergeNarrowingFilter()

	/**
	 * Load the joined schema, gate the caller on it, and locate its register.
	 *
	 * The ORDER is the security-relevant part: the `list` permission gate
	 * runs before the register lookup and before any row read, so a caller
	 * without rights on the joined schema learns nothing beyond the refusal.
	 *
	 * @param string $through The joined schema ref.
	 * @param bool $bypassRbac Internal-system mode: skip the read-permission gate.
	 *
	 * @return array{0: Schema, 1: Register} The joined schema and its register.
	 *
	 * @throws RuntimeException When the caller lacks `list`, or no register carries the schema.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function resolveJoinTarget(string $through, bool $bypassRbac): array {
		$joinedSchema = $this->loadSchema(schemaRef: $through);

		// SECURITY (control 1 of 3, see applyJoin()'s docblock): list
		// permission on the JOINED schema. `objectOwner: null` /
		// `object: null` is the list-level form — the join has not selected
		// any specific row.
		$userId = $this->userSession->getUser()?->getUID();
		if ($bypassRbac === false && $this->mayAggregate(schema: $joinedSchema, userId: $userId) === false) {
			throw new RuntimeException(
				sprintf('Forbidden: caller lacks read permission on join target "%s".', $through)
			);
		}

		$joinedRegister = $this->findRegisterForSchema(schema: $joinedSchema);
		if ($joinedRegister === null) {
			throw new RuntimeException(
				sprintf('No register found that contains join target schema "%s".', $through)
			);
		}

		return [$joinedSchema, $joinedRegister];
	}//end resolveJoinTarget()

	/**
	 * Attach the joined lookup to each parent group under `joined`.
	 *
	 * Every alias is ALWAYS present, `null` on a miss. An absent key and a
	 * null value read the same to a careless consumer; making the miss
	 * explicit keeps "no matching row on the joined side" from looking like
	 * "this alias was never requested".
	 *
	 * @param array<int, mixed> $groups The parent grouped rows.
	 * @param JoinedValueLookup $lookup The join map, the select aliases and the joined values by merge key.
	 *
	 * @return array<int, mixed> The grouped rows, each carrying a `joined` map.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function mergeJoinedValues(array $groups, JoinedValueLookup $lookup): array {
		$parentFields = array_keys($lookup->onMap);

		foreach ($groups as $index => $group) {
			if (is_array($group) === false) {
				continue;
			}

			$tuple = [];
			foreach ($parentFields as $parentField) {
				if (isset($group['keys']) === true && is_array($group['keys']) === true) {
					$tuple[] = ($group['keys'][$parentField] ?? null);
					continue;
				}

				$tuple[] = ($group['key'] ?? null);
			}

			$tupleKey = $this->joinTupleKey(values: $tuple);

			$joined = [];
			foreach ($lookup->aliases as $alias) {
				$joined[$alias] = ($lookup->values[$tupleKey][$alias] ?? null);
			}

			$groups[$index]['joined'] = $joined;
		}//end foreach

		return $groups;
	}//end mergeJoinedValues()

	/**
	 * Resolve a join `on` clause to an ordered `parentField => joinedField` map.
	 *
	 * Two spellings:
	 * - `{parentField: joinedField, ...}` — explicit and unambiguous, and
	 *   the only spelling that can express a COMPOSITE join key.
	 * - `"Through.column"` or bare `"column"` — single-column shorthand.
	 *   The JOINED side is the column named. The PARENT side is inferred:
	 *   the group field of the SAME name when one exists, otherwise the
	 *   FIRST group field. The fallback is what makes shillinq's
	 *   `on: "CommitmentBudget.programmeCode"` resolve to
	 *   `programme => programmeCode` against
	 *   `groupBy: [programme, costCentre, ...]`. It IS an inference; the
	 *   map spelling exists for authors who would rather say it outright.
	 *
	 * @param array<string, mixed> $join The raw join spec.
	 * @param array<int, string> $groupFields The parent's ordered group fields.
	 *
	 * @return array<string, string> Ordered parentField => joinedField map.
	 *
	 * @throws RuntimeException When `on` is missing or unusable.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function resolveJoinKey(array $join, array $groupFields): array {
		$onClause = ($join['on'] ?? null);

		if (is_array($onClause) === true && count($onClause) > 0 && array_is_list($onClause) === false) {
			return $this->resolveJoinKeyMap(onClause: $onClause, groupFields: $groupFields);
		}

		$joinedField = $this->joinOnColumn(onClause: $onClause);

		// Same-named group field wins; otherwise the first group field.
		$parentField = $groupFields[0];
		if (in_array($joinedField, $groupFields, true) === true) {
			$parentField = $joinedField;
		}

		return [$parentField => $joinedField];
	}//end resolveJoinKey()

	/**
	 * The joined column a `"Schema.column"` join.on string names.
	 *
	 * @param mixed $onClause The join.on value, when it is not a map.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When join.on is not a non-empty string or names an empty column.
	 */
	private function joinOnColumn(mixed $onClause): string {
		if (is_string($onClause) === false || $onClause === '') {
			throw new RuntimeException(
				'Aggregation join.on must be a "Schema.column" string or a {parentField: joinedField} map.'
			);
		}

		$joinedField = $this->stripSchemaPrefix(ref: $onClause);
		if ($joinedField === '') {
			throw new RuntimeException('Aggregation join.on names an empty column.');
		}

		return $joinedField;
	}//end joinOnColumn()

	/**
	 * Resolve the explicit `{parentField: joinedField}` spelling of `on`.
	 *
	 * Each parent-side field MUST be one of the groupBy fields: the merge
	 * reads the join key out of the group row, so a parent field that is not
	 * grouped has no value there and would silently match nothing.
	 *
	 * @param array<string, mixed> $onClause The `on` map.
	 * @param array<int, string> $groupFields The parent's ordered group fields.
	 *
	 * @return array<string, string> Ordered parentField => joinedField map.
	 *
	 * @throws RuntimeException When a side is empty or the parent field is not grouped.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function resolveJoinKeyMap(array $onClause, array $groupFields): array {
		$map = [];
		foreach ($onClause as $parentField => $joinedField) {
			$parentField = (string)$parentField;
			$joinedField = $this->stripSchemaPrefix(ref: (string)$joinedField);
			if ($parentField === '' || $joinedField === '') {
				throw new RuntimeException('Aggregation join.on names an empty field.');
			}

			if (in_array($parentField, $groupFields, true) === false) {
				throw new RuntimeException(
					sprintf(
						'Aggregation join.on parent field "%s" is not one of the groupBy fields.',
						$parentField
					)
				);
			}

			$map[$parentField] = $joinedField;
		}

		return $map;
	}//end resolveJoinKeyMap()

	/**
	 * Strip a leading `Schema.` qualifier from a join field reference.
	 *
	 * `"CommitmentBudget.programmeCode"` → `"programmeCode"`. ANY qualifier
	 * is stripped, not only one matching the `through` ref: a schema ref may
	 * be written as a slug, uuid or id, so requiring an exact textual match
	 * would silently leave a dotted string as the column name and produce a
	 * column-not-found bail to the PHP fallback instead of a readable error.
	 *
	 * @param string $ref The raw field reference.
	 *
	 * @return string The bare column name.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function stripSchemaPrefix(string $ref): string {
		$position = strrpos($ref, '.');
		if ($position === false) {
			return $ref;
		}

		return substr($ref, ($position + 1));
	}//end stripSchemaPrefix()

	/**
	 * Parse a join `select` list to `{alias, field, metric}` entries.
	 *
	 * Accepted entries:
	 * - `"CommitmentBudget.authorised_amount"` — alias is the string AS
	 *   WRITTEN (so a consumer reads back the key it declared), field is
	 *   the bare column, metric defaults to `sum`.
	 * - `{field: "authorised_amount", metric: "max", alias: "cap"}` —
	 *   `metric` defaults to `sum`, `alias` defaults to `field`.
	 *
	 * `sum` is the default because the joined side is aggregated per join
	 * key, not looked up per row: a budget schema holding exactly one row
	 * per key sums to that row's value, and one holding several sums to
	 * their total — which is the arithmetic a budget-vs-realised report
	 * wants. An author who needs a different reduction says so with the
	 * object spelling.
	 *
	 * @param array<string, mixed> $join The raw join spec.
	 *
	 * @return array<int, array{alias: string, field: string, metric: string}> Parsed entries.
	 *
	 * @throws RuntimeException When `select` is missing, empty or malformed.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function parseJoinSelect(array $join): array {
		$select = ($join['select'] ?? null);
		if (is_array($select) === false || count($select) === 0) {
			throw new RuntimeException('Aggregation join requires a non-empty `select` list.');
		}

		$entries = [];
		foreach ($select as $raw) {
			$entries[] = $this->parseJoinSelectEntry(raw: $raw);
		}

		return $entries;
	}//end parseJoinSelect()

	/**
	 * Parse ONE join `select` entry to `{alias, field, metric}`.
	 *
	 * @param mixed $raw The raw entry — a "Field" string or a {field, metric?, alias?} object.
	 *
	 * @return array{alias: string, field: string, metric: string} The parsed entry.
	 *
	 * @throws RuntimeException When the entry is malformed or names an unsupported metric.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function parseJoinSelectEntry(mixed $raw): array {
		if (is_string($raw) === true && $raw !== '') {
			return [
				'alias' => $raw,
				'field' => $this->stripSchemaPrefix(ref: $raw),
				'metric' => 'sum',
			];
		}

		$field  = $this->joinSelectField(raw: $raw);
		$metric = $this->joinSelectMetric(raw: $raw);

		return [
			'alias' => (string)($raw['alias'] ?? $field),
			'field' => $this->stripSchemaPrefix(ref: $field),
			'metric' => $metric,
		];
	}//end parseJoinSelectEntry()

	/**
	 * The non-empty string `field` of a `{field, metric?}` select entry.
	 *
	 * @param mixed $raw The select entry.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the entry is not an object with a non-empty string field.
	 */
	private function joinSelectField(mixed $raw): string {
		if (is_array($raw) === false
			|| isset($raw['field']) === false
			|| is_string($raw['field']) === false
			|| $raw['field'] === ''
		) {
			throw new RuntimeException(
				'Aggregation join.select entries must be "Field" strings or {field, metric?} objects.'
			);
		}

		return $raw['field'];
	}//end joinSelectField()

	/**
	 * The metric of a `{field, metric?}` select entry, `sum` by default.
	 *
	 * @param array<string, mixed> $raw The select entry.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the metric is not one of JOIN_SELECT_METRICS.
	 */
	private function joinSelectMetric(array $raw): string {
		$metric = (string)($raw['metric'] ?? 'sum');
		if (in_array($metric, self::JOIN_SELECT_METRICS, true) === false) {
			throw new RuntimeException(
				sprintf('Aggregation join.select metric "%s" is not supported.', $metric)
			);
		}

		return $metric;
	}//end joinSelectMetric()

	/**
	 * Aggregate the joined schema, grouped on the joined side of the join key.
	 *
	 * Native first (one grouped query per select entry, reusing
	 * {@see tryNativeAggregation()} verbatim so the joined read carries the
	 * same tenancy and soft-delete predicates as the parent). If ANY entry
	 * bails to null the WHOLE native attempt is abandoned and every entry is
	 * recomputed in PHP — mirroring {@see tryNativeMultiMetric()}'s posture,
	 * so a single response is never a partial native / partial-PHP mix.
	 *
	 * The PHP fallback hydrates the joined table ONCE and reuses those rows
	 * for every select entry, and reduces them with the SAME
	 * {@see computeGrouped()} the parent's fallback uses — which is what
	 * makes the two paths agree rather than merely look similar.
	 *
	 * @param JoinedAggregation $joined The joined side: schema, register, filter, join key, select
	 *                                  entries and whether the caller's row predicate applies.
	 *
	 * @return array{values: array<string, array<string, int|float|null>>, backend: string, truncated: bool}
	 *         Lookup keyed by {@see joinTupleKey()} → alias → value.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function aggregateJoinedSchema(JoinedAggregation $joined): array {
		$native = $this->nativeJoinedGroups(joined: $joined);
		if ($native !== null) {
			return [
				'values' => $this->indexJoinedGroups(
					perAlias: $native,
					joinedFields: $joined->getJoinedFields()
				),
				'backend' => 'postgres',
				'truncated' => false,
			];
		}

		return $this->fallbackJoinedValues(joined: $joined);
	}//end aggregateJoinedSchema()

	/**
	 * The native groups per select alias, or null when any entry cannot run natively.
	 *
	 * One grouped query per select entry.
	 *
	 * @param JoinedAggregation $joined The joined side.
	 *
	 * @return array<string, array<int, array<string, mixed>>>|null
	 */
	private function nativeJoinedGroups(JoinedAggregation $joined): ?array {
		$groupBySpec = ['fields' => $joined->getJoinedFields()];
		$native = [];
		foreach ($joined->selectEntries as $entry) {
			$single = $this->tryNativeAggregation(
				register: $joined->register,
				schema: $joined->schema,
				metric: $entry['metric'],
				field: $entry['field'],
				filter: $joined->filter,
				groupBy: $groupBySpec,
				rowRbac: $joined->rowRbac
			);

			if ($single === null) {
				return null;
			}

			$native[$entry['alias']] = ($single['groups'] ?? []);
		}

		return $native;
	}//end nativeJoinedGroups()

	/**
	 * The joined lookup computed in PHP: hydrate ONCE, reduce per select entry.
	 *
	 * @param JoinedAggregation $joined The joined side.
	 *
	 * @return array{values: array<string, array<string, int|float|null>>, backend: string, truncated: bool}
	 */
	private function fallbackJoinedValues(JoinedAggregation $joined): array {
		$objects = $this->magicMapper->findAllInRegisterSchemaTable(
			table: new RegisterSchema(register: $joined->register, schema: $joined->schema),
			listing: new ListQuery(limit: self::PHP_FALLBACK_ROW_CAP)
		);
		$truncated = (count($objects) >= self::PHP_FALLBACK_ROW_CAP);

		$rows = [];
		foreach ($objects as $object) {
			$rows[] = $object->getObject();
		}

		$rows = $this->applyFilter(rows: $rows, filter: $joined->filter);

		$perAlias = [];
		foreach ($joined->selectEntries as $entry) {
			$perAlias[$entry['alias']] = $this->computeGrouped(
				rows: $rows,
				aggregation: new RowAggregation(
					metric: $entry['metric'],
					field: $entry['field'],
					groupFields: $joined->getJoinedFields()
				)
			);
		}

		return [
			'values' => $this->indexJoinedGroups(
				perAlias: $perAlias,
				joinedFields: $joined->getJoinedFields()
			),
			'backend' => 'php-fallback',
			'truncated' => $truncated,
		];
	}//end fallbackJoinedValues()

	/**
	 * Fold per-alias grouped rows into one `joinKey => alias => value` lookup.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $perAlias Grouped rows per select alias.
	 * @param array<int, string> $joinedFields Joined-side join key field(s), in key order; more than one
	 *                                         makes the key composite (rows carry `keys`, not `key`).
	 *
	 * @return array<string, array<string, int|float|null>> Lookup keyed by {@see joinTupleKey()}.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function indexJoinedGroups(array $perAlias, array $joinedFields): array {
		$isMulti = count($joinedFields) > 1;
		$lookup  = [];
		foreach ($perAlias as $alias => $groups) {
			foreach ($groups as $group) {
				if (is_array($group) === false) {
					continue;
				}

				$tuple = [($group['key'] ?? null)];
				if ($isMulti === true) {
					$tuple = $this->joinedKeysTuple(group: $group, joinedFields: $joinedFields);
				}

				$tupleKey = $this->joinTupleKey(values: $tuple);
				$lookup[$tupleKey][$alias] = ($group['value'] ?? null);
			}
		}//end foreach

		return $lookup;
	}//end indexJoinedGroups()

	/**
	 * A multi-key group's `keys` values, in joined-field order (null for a missing one).
	 *
	 * @param array<string, mixed> $group        The group row.
	 * @param array<int, string>   $joinedFields Joined-side join key fields.
	 *
	 * @return list<mixed>
	 */
	private function joinedKeysTuple(array $group, array $joinedFields): array {
		$keys = [];
		if (isset($group['keys']) === true && is_array($group['keys']) === true) {
			$keys = $group['keys'];
		}

		$tuple = [];
		foreach ($joinedFields as $joinedField) {
			$tuple[] = ($keys[$joinedField] ?? null);
		}

		return $tuple;
	}//end joinedKeysTuple()

	/**
	 * Build the merge key for one join-key tuple.
	 *
	 * Every member is normalised to its STRING form before hashing. That is
	 * load-bearing, not cosmetic: the native path reads join-key columns
	 * back through the DB driver (a numeric year arrives as `"2024"`) while
	 * the PHP fallback reads them out of decoded JSON (`2024`). Keying on
	 * the raw values would make the two paths match different groups on the
	 * same data — the native path silently attaching nothing.
	 *
	 * `null` gets a sentinel distinct from the empty string so a genuinely
	 * absent key does not collide with `""`.
	 *
	 * @param array<int, mixed> $values The tuple members, in key order.
	 *
	 * @return string The merge key.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function joinTupleKey(array $values): string {
		$parts = [];
		foreach ($values as $value) {
			$parts[] = match (true) {
				$value === null => "\x00null",
				$value === true => 'true',
				$value === false => 'false',
				is_scalar($value) === true => (string)$value,
				default => (string)json_encode($value),
			};
		}

		return implode("\x1f", $parts);
	}//end joinTupleKey()

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
	 * @param NamedAggregationRun  $run       The named run on the parent schema.
	 * @param array<string, mixed> $parentRow Parent object fields for `@self.*` resolution.
	 *
	 * @return array<string, mixed> Aggregation result envelope.
	 *
	 * @throws RuntimeException When the target schema/register cannot be resolved or RBAC fails.
	 */
	private function runCrossSchema(NamedAggregationRun $run, array $parentRow): array {
		$cross = $this->resolveCrossTarget(run: $run, parentRow: $parentRow);
		$this->resolveCrossWhere(cross: $cross);

		$cached = $this->cachedCrossResult(cross: $cross);
		if ($cached !== null) {
			return $cached;
		}

		$result = $this->nativeCrossResult(cross: $cross);
		if ($result === null) {
			$result = $this->fallbackCrossResult(cross: $cross);
		}

		return $this->storeCrossResult(cross: $cross, result: $result);
	}//end runCrossSchema()

	/**
	 * Load the `from` schema, gate on it, and find the register that carries it.
	 *
	 * @param NamedAggregationRun  $run       The named run on the parent schema.
	 * @param array<string, mixed> $parentRow Parent object fields for `@self.*` resolution.
	 *
	 * @return CrossSchemaAggregationRun
	 *
	 * @throws RuntimeException When `from` is empty, the target is missing or forbidden, or no register carries it.
	 */
	private function resolveCrossTarget(NamedAggregationRun $run, array $parentRow): CrossSchemaAggregationRun {
		$fromRef = (string)($run->spec['from'] ?? '');
		if ($fromRef === '') {
			throw new RuntimeException('Cross-schema aggregation spec is missing a non-empty `from` key.');
		}

		// Load the target schema.
		$targetSchema = $this->loadSchema(schemaRef: $fromRef);

		// SECURITY: gate on read permission for the *target* schema so a
		// cross-schema aggregation cannot leak counts from a schema the
		// caller is not allowed to list.
		//
		// `objectOwner: null` is intentional here: this is a list-level
		// check across the entire target schema (the aggregation hasn't
		// selected any specific row yet), so there is no single
		// owner-context to gate against. The owner-specific branch of
		// permissionHandler->hasPermission() only applies when both
		// $userId and $objectOwner are non-null. The corresponding
		// `object: null` reinforces this: PermissionHandlerInterface treats the
		// (null, null) pair as the list-level form.
		$userId = $this->userSession->getUser()?->getUID();
		if ($run->bypassRbac === false && $this->mayAggregate(schema: $targetSchema, userId: $userId) === false) {
			throw new RuntimeException(
				sprintf(
					'Forbidden: caller lacks read permission on cross-schema target "%s".',
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

		$cross = new CrossSchemaAggregationRun(
			parent: $run,
			parentRow: $parentRow,
			targetSchema: $targetSchema,
			targetRegister: $targetRegister
		);
		$cross->userId = $userId;
		return $cross;
	}//end resolveCrossTarget()

	/**
	 * Resolve the `where` clause and derive the cache key from it.
	 *
	 * @param CrossSchemaAggregationRun $cross The cross-schema run; where and cacheKey are set here.
	 *
	 * @return void
	 */
	private function resolveCrossWhere(CrossSchemaAggregationRun $cross): void {
		// Resolve `@self.<field>` references against the parent row, then
		// apply placeholder resolution ($now, $currentUser, etc.).
		$resolvedWhere = $this->resolveAtSelfReferences(
			where: $cross->getRawWhere(),
			parentRow: $cross->parentRow
		);
		$cross->where = $this->placeholders->resolveArray($resolvedWhere);

		// Build the cache key, including the parent row values that were
		// substituted so two parent objects with different field values
		// cache independently.
		$activeOrg = $this->organisationService->getActiveOrganisation();
		$cross->cacheKey = [
			'cross' => true,
			'from' => $cross->getFromRef(),
			'metric' => $cross->getMetric(),
			'metrics' => $cross->getMetrics(),
			'field' => $cross->getField(),
			'where' => $cross->where,
			'groupBy' => $cross->getGroupBy(),
			'userId' => $cross->userId,
			'org' => $activeOrg?->getUuid(),
		];
	}//end resolveCrossWhere()

	/**
	 * The cached result for this run, flagged as cached, or null on a miss.
	 *
	 * @param CrossSchemaAggregationRun $cross The cross-schema run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cachedCrossResult(CrossSchemaAggregationRun $cross): ?array {
		$cached = $this->cache->get(
			slot: new AggregationSlot(
				registerSlug: (string)$cross->parent->register->getSlug(),
				schemaSlug: (string)$cross->parent->schema->getSlug(),
				name: $cross->parent->name,
				filter: $cross->cacheKey
			)
		);
		if ($cached !== null) {
			$cached['cached'] = true;
		}

		return $cached;
	}//end cachedCrossResult()

	/**
	 * Store the result under this run's cache key and return it.
	 *
	 * @param CrossSchemaAggregationRun $cross  The cross-schema run.
	 * @param array<string, mixed>      $result The result envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function storeCrossResult(CrossSchemaAggregationRun $cross, array $result): array {
		$this->cache->set(
			slot: new AggregationSlot(
				registerSlug: (string)$cross->parent->register->getSlug(),
				schemaSlug: (string)$cross->parent->schema->getSlug(),
				name: $cross->parent->name,
				filter: $cross->cacheKey
			),
			result: $result
		);
		return $result;
	}//end storeCrossResult()

	/**
	 * The Postgres-native result on the target schema table, or null to fall back to PHP.
	 *
	 * @param CrossSchemaAggregationRun $cross The cross-schema run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function nativeCrossResult(CrossSchemaAggregationRun $cross): ?array {
		// A `metrics` spec is computed in PHP so that per-entry `condition`
		// and `as` are honoured — tryNativeAggregation() takes a single
		// metric/field pair and has nowhere to put them. Letting it run would
		// return one unconditioned figure for a spec that asked for several.
		// The row cap still applies and is reported through `truncated`.
		if ($cross->getMetrics() !== null) {
			return null;
		}

		$native = $this->tryNativeAggregation(
			register: $cross->targetRegister,
			schema: $cross->targetSchema,
			metric: $cross->getMetric(),
			field: $cross->getStringField(),
			filter: $cross->where,
			groupBy: $cross->getGroupByArg(),
			rowRbac: $cross->parent->bypassRbac === false
		);
		if ($native === null) {
			return null;
		}

		return $cross->envelope(backend: 'postgres') + $native;
	}//end nativeCrossResult()

	/**
	 * The PHP fallback result over the target schema's rows.
	 *
	 * @param CrossSchemaAggregationRun $cross The cross-schema run.
	 *
	 * @return array<string, mixed>
	 */
	private function fallbackCrossResult(CrossSchemaAggregationRun $cross): array {
		$rows = $this->fallbackCrossRows(cross: $cross);

		$result = $cross->envelope(backend: 'php-fallback');
		if ($cross->getMetrics() !== null) {
			$result['metrics'] = $cross->getMetrics();
		}

		return $result + $this->crossFigures(cross: $cross, rows: $rows);
	}//end fallbackCrossResult()

	/**
	 * The target schema's rows, capped and filtered by the resolved `where`.
	 *
	 * @param CrossSchemaAggregationRun $cross The cross-schema run; truncated is set here.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fallbackCrossRows(CrossSchemaAggregationRun $cross): array {
		$objects = $this->magicMapper->findAllInRegisterSchemaTable(
			table: new RegisterSchema(register: $cross->targetRegister, schema: $cross->targetSchema),
			listing: new ListQuery(limit: self::PHP_FALLBACK_ROW_CAP)
		);
		$cross->truncated = count($objects) >= self::PHP_FALLBACK_ROW_CAP;

		$rows = [];
		foreach ($objects as $entity) {
			// The findAllInRegisterSchemaTable mapper returns ObjectEntity[];
			// getObject() resolves each row to the inner data array.
			$rows[] = $entity->getObject();
		}

		return $this->applyFilter(rows: $rows, filter: $cross->where);
	}//end fallbackCrossRows()

	/**
	 * The figures over the rows: `groups`, `values` (a `metrics` spec) or `value`.
	 *
	 * @param CrossSchemaAggregationRun        $cross The cross-schema run.
	 * @param array<int, array<string, mixed>> $rows  The filtered rows.
	 *
	 * @return array<string, mixed>
	 */
	private function crossFigures(CrossSchemaAggregationRun $cross, array $rows): array {
		$crossGroupFields = $this->resolveGroupFields(groupBy: $cross->getGroupBy());
		if (count($crossGroupFields) > 0) {
			return [
				'groups' => $this->computeGrouped(
					rows: $rows,
					aggregation: new RowAggregation(
						metric: $cross->getMetric(),
						field: $cross->getField(),
						groupFields: $crossGroupFields,
						metrics: $cross->getMetrics()
					)
				),
			];
		}

		// Ungrouped: a `metrics` spec yields `values` (a map keyed by alias).
		// Falling through to computeMetric() here would hand back a single
		// figure derived from the metric — which is the DEFAULT 'count' when
		// the spec declared only `metrics` — for a request that named
		// several conditioned sums.
		if ($cross->getMetrics() !== null) {
			return ['values' => $this->computeMetrics(rows: $rows, metrics: $cross->getMetrics())];
		}

		return ['value' => $this->computeMetric(rows: $rows, aggregation: new RowAggregation(metric: $cross->getMetric(), field: $cross->getField()))];
	}//end crossFigures()

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
	 * @param array<string, mixed> $where Raw where/filter clause from the aggregation spec.
	 * @param array<string, mixed> $parentRow Parent object's field values.
	 *
	 * @return array<string, mixed> Where clause with `@self.*` references resolved.
	 */
	private function resolveAtSelfReferences(array $where, array $parentRow): array {
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
				$fieldName = substr($value, 6);

				// AN UNANSWERABLE CORRELATION IS AN ERROR, NOT AN EMPTY FILTER.
				//
				// This used to resolve to null and was described as failing
				// closed. It does not: the null is applied as a real filter
				// VALUE, so the aggregation returns the target rows whose own
				// field is null. For a segment P&L keyed on `@self.code` that
				// is the unassigned-cost-centre total — returned confidently,
				// under HTTP 200, identically for every parent record, with
				// nothing in the envelope naming what went wrong.
				//
				// No production caller supplies a parent row today (the REST
				// controller, ReportRenderService and ThresholdEvaluationService
				// all call run() without one), so silence here means every such
				// declaration answers wrongly rather than visibly.
				//
				// A key PRESENT but null is left alone: correlating on null is a
				// legitimate query. It is the ABSENT key — no parent row, or a
				// typo in the field name — that is refused.
				if (array_key_exists($fieldName, $parentRow) === false) {
					throw new RuntimeException(
						sprintf(
							'Cannot resolve "%s": the parent row supplies no "%s" field. '
							.'A cross-schema aggregation correlating on @self requires the '
							.'caller to pass the parent object as parentRow.',
							$value,
							$fieldName
						)
					);
				}

				$resolved[$key] = $parentRow[$fieldName];
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
	private function findRegisterForSchema(Schema $schema): ?Register {
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
			$normalised = array_map(static fn ($id) => (int)$id, $schemaIds);
			if (in_array((int)$schemaId, $normalised, true) === true) {
				return $register;
			}
		}

		return null;
	}//end findRegisterForSchema()

	/**
	 * Resolve the (register, schema) pair a request named, with the register as the boundary.
	 *
	 * WHY THE PAIR IS RESOLVED TOGETHER. Every aggregation endpoint carries a
	 * `{register}` path segment, and until this method existed not one of them used
	 * it to disambiguate: `run()` and `runAdhocByRef()` both opened with
	 * `loadSchema($schemaRef)` and only then loaded the register, so by the time the
	 * register was known the schema had already been matched against every register
	 * and every app on the instance. Measured on the shared dev instance 2026-08-21,
	 * a dashboard `stat` widget therefore aggregated ANOTHER APP's rows: `TimeEntry`
	 * resolved to planix's schema 161 instead of hrmq's 9466, and `Expense` to
	 * pipelinq's 507 instead of hrmq's 5026. The register was in hand the whole
	 * time; the ordering threw it away.
	 *
	 * The consequence is not only a wrong number. The `x-openregister-aggregations`
	 * annotation, the RBAC `list` gate and the object-row scan are ALL driven off
	 * the resolved schema, so a mis-resolution silently evaluates permissions
	 * against the wrong schema's authorization config.
	 *
	 * @param string $schemaRef   Schema slug/uuid/id.
	 * @param string $registerRef Register slug/uuid/id — the boundary.
	 *
	 * @return array{register: Register, schema: Schema} The resolved pair.
	 *
	 * @throws RuntimeException When the register does not resolve, or does not carry the schema.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	private function loadSchemaInRegister(string $schemaRef, string $registerRef): array {
		try {
			$pair = $this->scopedResolver->resolvePair(registerRef: $registerRef, schemaRef: $schemaRef);
		} catch (SchemaNotInRegisterException | RegisterNotFoundException $e) {
			// Re-thrown as RuntimeException so the existing controller contract
			// holds: AggregationController maps RuntimeException to HTTP 404 and
			// echoes the message. The message is carried through UNCHANGED — which
			// register, how many same-slug schemas exist elsewhere, and the
			// relink-schemas repair command are the whole point of the refusal.
			// Flattening it to `Schema "%s" not found.` would read as "your slug is
			// wrong", the one conclusion that is certainly false when duplicates
			// demonstrably exist.
			throw new RuntimeException($e->getMessage(), 0, $e);
		}

		return $pair;
	}//end loadSchemaInRegister()

	/**
	 * Load a schema by ref, throwing a RuntimeException when missing.
	 *
	 * GLOBAL resolution, deliberately retained for the two callers that hold no
	 * register boundary: {@see findSchema()} when invoked without a register ref,
	 * and the cross-schema `from:` target in {@see runCrossSchemaAggregation()},
	 * whose ref is written by the schema author and may legitimately point at
	 * another register (the runner then locates that schema's own register via
	 * {@see findRegisterForSchema()}). Every path that DOES carry a `{register}`
	 * uses {@see loadSchemaInRegister()} instead.
	 *
	 * @param string $schemaRef Schema slug/uuid/id.
	 *
	 * @return Schema The loaded schema.
	 *
	 * @throws RuntimeException When the schema can't be found.
	 */
	private function loadSchema(string $schemaRef): Schema {
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

	// NOTE. `loadRegister()` lived here and is gone: the register load is now part
	// of the (register, schema) pair resolution and belongs with it — see
	// loadSchemaInRegister(). Keeping a second, standalone register loader would
	// have left a path that resolves a register WITHOUT then bounding a schema by
	// it, which is the shape this change removes. The metadata-read bypass it
	// documented (`_multitenancy: false`, per the auth-system requirement "Schema
	// and register METADATA-READ lookups MUST bypass multi-tenancy") is unchanged
	// and now lives in RegisterScopedSchemaResolver::resolveRegister(); it is
	// locked by AggregationRunnerTest::testRegisterLoadPassesMultitenancyFalse.

	/**
	 * Whether the current caller may aggregate over a schema.
	 *
	 * An aggregate is a read of many rows at once, so the question is READ,
	 * the verb every schema declares. The gate used to ask for `list`, which
	 * is a canonical verb but one most leaf-app schemas never declare (their
	 * blocks name read/create/update/delete). Every non-admin was refused on
	 * those schemas, so a dashboard tile showed a dash for staff who could
	 * read every row the tile counts.
	 *
	 * Admitted, in order:
	 *  - schema-level `read`;
	 *  - schema-level `list`, so a schema that declares it keeps working;
	 *  - a conditional `read` rule the caller's group qualifies for. A
	 *    schema-level check has no row to evaluate the rule's `match`
	 *    against and refuses, but the aggregate narrows to the rows that
	 *    match through the row predicate in tryNativeAggregation() (and the
	 *    RBAC-filtered read in the PHP fallback), so the caller is counted
	 *    over its own rows and nothing else.
	 *
	 * Admitting the caller here never widens WHICH rows are counted: that is
	 * the row predicate's job, and it applies the read rule.
	 *
	 * @param Schema      $schema The schema being aggregated.
	 * @param string|null $userId The caller.
	 *
	 * @return bool True when the caller may aggregate.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function mayAggregate(Schema $schema, ?string $userId): bool {
		foreach (['read', 'list'] as $action) {
			if ($this->permissionHandler->hasPermission(
				schema: $schema,
				action: $action,
				userId: $userId,
				objectOwner: null,
				_rbac: true,
				object: null
			) === true
			) {
				return true;
			}
		}

		return $this->magicMapper->callerQualifiesForAction(schema: $schema, action: 'read');
	}//end mayAggregate()

	/**
	 * Read the `x-openregister-aggregations` annotation off a schema.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array<string, mixed>|null The annotation map, or null when absent.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
	 */
	private function getAnnotation(Schema $schema): ?array {
		$config = ($schema->getConfiguration() ?? []);
		$value = ($config['x-openregister-aggregations'] ?? null);
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end getAnnotation()
	/**
	 * Refuse an aggregate over a property this caller may not read.
	 *
	 * @param Schema             $schema The schema.
	 * @param array<int, string> $fields Every property the aggregate touches.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When any of them is not readable.
	 *
	 * @spec openspec/changes/aggregate-paths-ask-permission/specs/rbac-scopes/spec.md
	 */
	private function assertFieldsAreReadable(Schema $schema, array $fields): void {
		$named = [];
		foreach ($fields as $field) {
			$name = trim((string)$field);
			// A metadata field is governed by row access, not by a property
			// rule, and there is no schema property to look up for it.
			if ($name === '' || str_starts_with($name, '@self') === true) {
				continue;
			}

			$named[$name] = true;
		}

		$split = $this->aggregateVisibility()->partition(
			schema: $schema,
			fields: array_keys($named)
		);

		if ($split['withheld'] === []) {
			return;
		}

		// REFUSED, NOT SILENTLY ZEROED. An aggregation returns one number, and
		// there is nowhere in that number to say part of it was withheld, so
		// the only honest answers are the figure or a refusal.
		throw new NotAuthorizedException(
			message: sprintf(
				'This aggregation reads %s, which you may not read, so it cannot be computed for you.',
				implode(', ', $split['withheld'])
			)
		);
	}//end assertFieldsAreReadable()

	/**
	 * Refuse an aggregate that would show the values of an explicit-reveal property.
	 *
	 * @param Schema             $schema The schema.
	 * @param array<int, string> $fields Every property the aggregate touches.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When any of them is explicit-reveal.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	private function assertNoExplicitValues(Schema $schema, array $fields): void {
		$withheld = [];
		foreach ($fields as $field) {
			$name = trim((string)$field);
			if ($name !== '' && $this->aggregateVisibility()->isMaskedExplicit(schema: $schema, property: $name) === true) {
				$withheld[$name] = true;
			}
		}

		if ($withheld === []) {
			return;
		}

		throw new NotAuthorizedException(
			message: sprintf(
				'This aggregation reads %s, which is shown only on request, so it cannot be computed for you.',
				implode(', ', array_keys($withheld))
			)
		);
	}//end assertNoExplicitValues()

	/**
	 * Refuse an aggregation filter on an explicit-reveal property.
	 *
	 * @param Schema       $schema The schema.
	 * @param array<mixed> $filter The caller's filter.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the filter names such a property.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	private function assertNoExplicitFilter(Schema $schema, array $filter): void {
		$this->assertNoExplicitValues(
			schema: $schema,
			fields: array_map(callback: 'strval', array: array_keys($filter))
		);
	}//end assertNoExplicitFilter()

	/**
	 * The property names a groupBy spec refers to.
	 *
	 * @param mixed $groupBy The groupBy spec.
	 *
	 * @return array<int, string> The names.
	 */
	private function groupByFields(mixed $groupBy): array {
		if (is_string($groupBy) === true) {
			return [$groupBy];
		}

		if (is_array($groupBy) === false) {
			return [];
		}

		$fields = [];
		foreach ($groupBy as $key => $entry) {
			$field = $this->groupByEntryField(key: $key, entry: $entry);
			if ($field !== null) {
				$fields[] = $field;
			}
		}

		return $fields;
	}//end groupByFields()

	/**
	 * The field one groupBy entry names: a string entry, a `{field}` object, or a string key.
	 *
	 * @param int|string $key   The entry's key.
	 * @param mixed      $entry The entry.
	 *
	 * @return string|null Null when the entry names no field.
	 */
	private function groupByEntryField(int|string $key, mixed $entry): string|null {
		if (is_string($entry) === true) {
			return $entry;
		}

		if (is_array($entry) === true && is_string(($entry['field'] ?? null)) === true) {
			return $entry['field'];
		}

		if (is_string($key) === true) {
			return $key;
		}

		return null;
	}//end groupByEntryField()

	/**
	 * The property names a metrics spec refers to.
	 *
	 * @param array<mixed>|null $metrics The metrics spec.
	 *
	 * @return array<int, string> The names.
	 */
	private function metricFields(?array $metrics): array {
		$fields = [];
		foreach (($metrics ?? []) as $metric) {
			if (is_array($metric) === true && is_string(($metric['field'] ?? null)) === true) {
				$fields[] = $metric['field'];
			}
		}

		return $fields;
	}//end metricFields()

	/**
	 * The shared answer to "may a summary over this property be shown".
	 *
	 * Built here rather than injected so every existing construction of this
	 * runner keeps working; it holds no state.
	 *
	 * @return AggregateVisibility The answer.
	 */
	private function aggregateVisibility(): AggregateVisibility {
		return new AggregateVisibility(rbac: $this->propertyRbac, logger: $this->logger, reveal: $this->explicitReveal);
	}//end aggregateVisibility()

}//end class
