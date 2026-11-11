<?php

/**
 * OpenRegister SemanticTypeResolver
 *
 * Resolves a canonical semantic-type URI (e.g. `https://schema.org/Organization`)
 * to the installed schema that implements it, enumerating every register the
 * caller can see (org/RBAC-scoped via `SchemaMapper::findAll()`). A schema's
 * implemented types are `configuration.implements` when present, else
 * `[configuration.jsonld.type]` — computed by
 * {@see \OCA\OpenRegister\Service\JsonLd\JsonLdContextService::getImplementedTypes()}.
 *
 * The resolver is deliberately null-safe: when no installed schema implements
 * the URI it returns `null` and NEVER raises, so a property that references a
 * cross-app object kind degrades gracefully when the providing app is absent
 * (ADR-048). A schema whose owning register's app is installed but DISABLED is
 * treated as no provider — its lingering schemas do not resolve. Any schema
 * that adheres to the requested URI (from a top-level/`configuration`
 * `x-schema-org` marker, `configuration.jsonld.type`, or `implements[]`) is an
 * acceptable provider; when more than one matches the resolver keeps a
 * deterministic pick (first by slug, optionally biased to the consuming
 * register) and emits a WARN log naming the pick, so ambiguous vocabulary is
 * observable without breaking rendering.
 *
 * Mirrors the request-scoped-cache + typed-mapper style of
 * {@see \OCA\OpenRegister\Service\RegisterResolverService}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Resolve a canonical semantic-type URI to the installed schema implementing
 * it, null-safe across all registers, with a deterministic tie-break.
 *
 * Class complexity is inherent: every resolution path is deliberately
 * null-safe (each mapper/app-manager call is individually guarded so a missing
 * provider degrades rather than raises), which unavoidably raises the WMC.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 */
final class SemanticTypeResolver implements SemanticTypeResolverInterface {

	/**
	 * Request-scoped cache of resolved schemas, keyed by
	 * "{uri}:{consumingRegisterId|''}". A null cache entry records a proven
	 * "no provider" so repeated lookups in one request stay cheap. Mirrors
	 * {@see \OCA\OpenRegister\Service\JsonLd\JsonLdContextService::$contextCache}.
	 *
	 * @var array<string, Schema|null>
	 */
	private array $resolveCache = [];

	/**
	 * Wire the resolver against the canonical OR mappers + JSON-LD helper.
	 *
	 * Constructor-injection only; the service is autowired (no explicit DI
	 * registration needed, matching RegisterResolverService).
	 *
	 * @param SchemaCatalogInterface $catalog The register and schema mappers.
	 * @param JsonLdContextService $jsonLdContextService Computes a schema's implemented semantic types.
	 * @param LoggerInterface $logger Logger for ambiguity WARN.
	 * @param IAppManager|null $appManager App manager used to treat a provider whose owning
	 *                                     app is disabled as unavailable; null-safe (when
	 *                                     unavailable the app-enabled filter is skipped).
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly JsonLdContextService $jsonLdContextService,
		private readonly LoggerInterface $logger,
		private readonly ?IAppManager $appManager = null,
	) {
	}//end __construct()

	/**
	 * Resolve a semantic-type URI to the schema that implements it.
	 *
	 * Enumerates all schemas the caller can see, keeps those whose implemented
	 * types contain `$uri`, and returns the deterministic pick — or `null` when
	 * none implement it. Never raises for a "not found" outcome.
	 *
	 * Tie-break when >1 candidate matches: (a) a schema in the consuming
	 * register (when known), else (b) the first candidate by slug. A WARN log
	 * records the ambiguity and the chosen provider.
	 *
	 * @param string $uri The canonical semantic-type URI (absolute IRI).
	 * @param int|null $consumingRegisterId The consuming schema's register id, used only to bias the tie-break.
	 *
	 * @return Schema|null The implementing schema, or null when none is installed.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 *   (Requirement: Resolution is null-safe across installed schemas)
	 */
	public function resolveSchemaByImplements(string $uri, ?int $consumingRegisterId = null): ?Schema {
		if ($uri === '') {
			return null;
		}

		$cacheKey = $uri . ':' . ((string)($consumingRegisterId ?? ''));
		if (array_key_exists($cacheKey, $this->resolveCache) === false) {
			$this->resolveCache[$cacheKey] = $this->resolveUncached(
				query: new SemanticTypeQuery(uri: $uri, consumingRegisterId: $consumingRegisterId)
			);
		}

		return $this->resolveCache[$cacheKey];
	}//end resolveSchemaByImplements()

	/**
	 * Resolve a query without the request cache: no candidate is null, one is
	 * the answer, more go through the tie-break.
	 *
	 * @param SemanticTypeQuery $query The uri and consuming register.
	 *
	 * @return Schema|null
	 */
	private function resolveUncached(SemanticTypeQuery $query): ?Schema {
		$candidates = $this->candidatesFor(uri: $query->uri);
		if ($candidates === []) {
			// Standalone-safe: no installed schema provides this type.
			return null;
		}

		if (count($candidates) === 1) {
			return $candidates[0];
		}

		return $this->tieBreak(query: $query, candidates: $candidates);
	}//end resolveUncached()

	/**
	 * Every visible schema that implements `$uri` and whose owning app is enabled.
	 *
	 * @param string $uri The semantic-type URI.
	 *
	 * @return array<int, Schema>
	 */
	private function candidatesFor(string $uri): array {
		// A provider whose owning register's app is disabled is NOT an
		// available provider (ADR-048): its schemas may linger in OR after
		// `occ app:disable`, but the app that gives them meaning is gone, so
		// resolution must degrade exactly as if no provider were installed.
		$provides = fn (Schema $schema): bool => in_array($uri, $this->implementedTypesWithAncestors(schema: $schema), true) === true
			&& $this->isSchemaProvidedByEnabledApp(schema: $schema) === true;

		return array_values(array_filter($this->allSchemas(uri: $uri), $provides));
	}//end candidatesFor()

	/**
	 * Enumerate every schema the caller may read (org/RBAC-scoped, all
	 * registers); any failure counts as "no providers" so resolution stays null-safe.
	 *
	 * @param string $uri The semantic-type URI (for the log).
	 *
	 * @return array<int, Schema>
	 */
	private function allSchemas(string $uri): array {
		try {
			return $this->catalog->schemas()->findAll();
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[SemanticTypeResolver] schema enumeration failed — treating as no providers',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uri' => $uri, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end allSchemas()

	/**
	 * Compute a schema's implemented semantic types INCLUDING those inherited via
	 * `allOf`.
	 *
	 * A schema's implemented types are the union of its OWN markers
	 * ({@see \OCA\OpenRegister\Service\JsonLd\JsonLdContextService::getImplementedTypes()})
	 * and the implemented types of every schema it extends via `allOf`, resolved
	 * recursively with a visited-set circular guard (mirroring
	 * {@see \OCA\OpenRegister\Db\SchemaMapper::resolveAllOf()}). A child ADDS,
	 * never removes — so a schema that `allOf`-extends a `schema:Person` schema
	 * resolves for `https://schema.org/Person` even with no marker of its own. A
	 * schema with no `allOf` is unaffected (exactly its own markers).
	 *
	 * The ancestor walk lives here rather than in `JsonLdContextService` so that
	 * service stays dependency-light; this resolver already holds a `SchemaMapper`
	 * to load each `allOf` parent by id/uuid/slug.
	 *
	 * @param Schema $schema The schema whose implemented types to compute.
	 * @param array<int, string> $visited Visited schema identifiers (circular guard).
	 *
	 * @return array<int, string> The union of own + inherited implemented types.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-1.1
	 */
	private function implementedTypesWithAncestors(Schema $schema, array $visited = []): array {
		// Mark this schema visited so a cyclic `allOf` never loops.
		$currentId = (string)$schema->getId();
		if ($currentId !== '' && in_array($currentId, $visited, true) === true) {
			return [];
		}

		if ($currentId !== '') {
			$visited[] = $currentId;
		}

		// The schema's own markers, then each `allOf` ancestor's (recursive).
		$types = $this->jsonLdContextService->getImplementedTypes(schema: $schema);
		foreach ($this->allOfRefs(schema: $schema) as $parentRef) {
			$types = array_merge($types, $this->ancestorTypesForRef(parentRef: $parentRef, visited: $visited));
		}

		return array_values(array_unique($types));
	}//end implementedTypesWithAncestors()

	/**
	 * A schema's `allOf` references, empty when it has none.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<mixed>
	 */
	private function allOfRefs(Schema $schema): array {
		$allOf = $schema->getAllOf();
		if (is_array($allOf) === false) {
			return [];
		}

		return $allOf;
	}//end allOfRefs()

	/**
	 * Resolve the implemented types contributed by a single `allOf` parent
	 * reference, empty when the ref is unusable or its ancestor cannot be read.
	 *
	 * Extracted from {@see self::implementedTypesWithAncestors()} so the ancestor
	 * walk stays within complexity limits; behaviour is identical (an unusable or
	 * unresolved parent contributes nothing and never raises).
	 *
	 * @param mixed $parentRef A single `allOf` entry (schema id/uuid/slug).
	 * @param array<int, string> $visited Visited schema identifiers (circular guard).
	 *
	 * @return array<int, string> The parent's (recursive) implemented types.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-1.1
	 */
	private function ancestorTypesForRef(mixed $parentRef, array $visited): array {
		if ($this->isUsableRef(parentRef: $parentRef) === false) {
			return [];
		}

		$parent = $this->findAncestor(parentRef: $parentRef);
		if ($parent === null) {
			return [];
		}

		return $this->implementedTypesWithAncestors(schema: $parent, visited: $visited);
	}//end ancestorTypesForRef()

	/**
	 * Whether an `allOf` entry can name a schema: a non-empty id/uuid/slug.
	 *
	 * @param mixed $parentRef A single `allOf` entry.
	 *
	 * @return bool
	 */
	private function isUsableRef(mixed $parentRef): bool {
		return (is_string($parentRef) === true || is_int($parentRef) === true) && (string)$parentRef !== '';
	}//end isUsableRef()

	/**
	 * Load an `allOf` ancestor; a missing/unreadable ancestor is null, never an exception.
	 *
	 * @param int|string $parentRef The schema id/uuid/slug.
	 *
	 * @return Schema|null
	 */
	private function findAncestor(int|string $parentRef): ?Schema {
		try {
			return $this->catalog->schemas()->find(id: $parentRef);
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[SemanticTypeResolver] allOf ancestor unresolved — skipping',
				context: ['file' => __FILE__, 'line' => __LINE__, 'ref' => (string)$parentRef, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end findAncestor()

	/**
	 * Find the register a resolved schema belongs to.
	 *
	 * Registers hold their schema ids in `Register::getSchemas()`; a schema
	 * carries no back-reference, so we scan registers for membership. Returns
	 * the first register whose schema-id list contains the schema id. Null when
	 * the schema is orphaned (belongs to no register) or enumeration fails.
	 *
	 * @param Schema $schema The resolved schema.
	 *
	 * @return Register|null The owning register, or null when none is found.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 *   (Requirement: Resolution is null-safe across installed schemas)
	 */
	public function findRegisterForSchema(Schema $schema): ?Register {
		$schemaId = $schema->getId();
		foreach ($this->allRegisters(schemaId: $schemaId) as $register) {
			if (in_array((int)$schemaId, $this->memberIds(register: $register), true) === true) {
				return $register;
			}
		}

		return null;
	}//end findRegisterForSchema()

	/**
	 * Every register, empty when enumeration fails.
	 *
	 * @param int|null $schemaId The schema being located (for the log).
	 *
	 * @return array<int, Register>
	 */
	private function allRegisters(?int $schemaId): array {
		try {
			return $this->catalog->registers()->findAll();
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[SemanticTypeResolver] register enumeration failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end allRegisters()

	/**
	 * A register's member schema ids, as ints.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<int, int>
	 */
	private function memberIds(Register $register): array {
		return array_map('intval', $register->getSchemas());
	}//end memberIds()

	/**
	 * Whether the app that owns a candidate schema is installed AND enabled.
	 *
	 * A schema resolves ONLY when its owning app is enabled — a disabled
	 * provider app degrades to "no provider" even though its schemas may still
	 * linger in OR after `occ app:disable`. The owning app id is taken, in
	 * order, from the schema's own `application` field (the reliable per-schema
	 * signal — a register's `application` column is frequently null in practice)
	 * then the owning register's `application`. Fully null-safe: when the app
	 * manager is unavailable, no owning app id can be determined, or the id is
	 * core OR (`openregister`), the schema is treated as available so this check
	 * never *removes* a provider that no leaf app claimed. Only a concrete owning
	 * app that is NOT enabled filters the schema out.
	 *
	 * @param Schema $schema The candidate provider schema.
	 *
	 * @return bool True when the owning app is enabled (or the check does not apply); false only when a named owning app is disabled.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 *   (Requirement: A disabled provider app degrades to no provider)
	 */
	private function isSchemaProvidedByEnabledApp(Schema $schema): bool {
		if ($this->appManager === null) {
			return true;
		}

		$appId = $this->owningAppId(schema: $schema);
		if ($appId === null || $appId === 'openregister') {
			return true;
		}

		return $this->isAppEnabled(appManager: $this->appManager, appId: $appId);
	}//end isSchemaProvidedByEnabledApp()

	/**
	 * Whether an app is enabled for the current user; contexts that reject the
	 * check fall back to install state so resolution never raises.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param string $appId The owning app id.
	 *
	 * @return bool
	 */
	private function isAppEnabled(IAppManager $appManager, string $appId): bool {
		try {
			return $appManager->isEnabledForUser($appId);
		} catch (\Throwable $e) {
			// Some app entities reject anonymous / user-less contexts.
			return $this->isAppInstalled(appManager: $appManager, appId: $appId);
		}
	}//end isAppEnabled()

	/**
	 * Whether an app is installed; true when even that cannot be determined.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param string $appId The owning app id.
	 *
	 * @return bool
	 */
	private function isAppInstalled(IAppManager $appManager, string $appId): bool {
		try {
			return $appManager->isInstalled($appId);
		} catch (\Throwable $inner) {
			return true;
		}
	}//end isAppInstalled()

	/**
	 * Determine the id of the app that owns a schema, or null when none is
	 * declared.
	 *
	 * Prefers the schema's own `application` field (present and reliable on real
	 * fleet schemas — e.g. `shillinq`), then falls back to the owning register's
	 * `application`. Returns null when neither names an app, in which case the
	 * app-enabled gate does not apply.
	 *
	 * @param Schema $schema The candidate provider schema.
	 *
	 * @return string|null The owning app id, or null when undeclared.
	 */
	private function owningAppId(Schema $schema): ?string {
		$appId = $schema->getApplication();
		if ($this->isAppName(value: $appId) === true) {
			return $appId;
		}

		$registerApp = $this->findRegisterForSchema(schema: $schema)?->getApplication();
		if ($this->isAppName(value: $registerApp) === true) {
			return $registerApp;
		}

		return null;
	}//end owningAppId()

	/**
	 * Whether a declared application value names an app.
	 *
	 * @param mixed $value The `application` field value.
	 *
	 * @return bool
	 */
	private function isAppName(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isAppName()

	/**
	 * Clear the request-scoped resolve cache. Test hook.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 */
	public function clearCache(): void {
		$this->resolveCache = [];

	}//end clearCache()

	/**
	 * Deterministically pick one schema from >1 candidates for the same URI.
	 *
	 * Order: (1) a candidate in the consuming register (when known), else
	 * (2) the first candidate by slug. Emits a WARN naming the pick so
	 * ambiguous vocabulary is observable.
	 *
	 * @param SemanticTypeQuery $query The uri being resolved and the consuming register.
	 * @param array<Schema> $candidates The >1 matching schemas.
	 *
	 * @return Schema The chosen schema.
	 */
	private function tieBreak(SemanticTypeQuery $query, array $candidates): Schema {
		// Deterministic base order: by slug ascending.
		usort($candidates, fn (Schema $a, Schema $b) => strcmp($this->slugOf(schema: $a), $this->slugOf(schema: $b)));
		$pick = $this->preferConsuming(candidates: $candidates, consumingRegisterId: $query->consumingRegisterId);
		$this->warnAmbiguous(
			summary: [
				'uri' => $query->uri,
				'candidates' => array_map(fn (Schema $s) => $this->slugOf(schema: $s), $candidates),
				'picked' => $this->slugOf(schema: $pick),
				'pickedId' => $pick->getId(),
				'consumingReg' => $query->consumingRegisterId,
			]
		);
		return $pick;
	}//end tieBreak()

	/**
	 * The first candidate that lives in the consuming register, else the first candidate.
	 *
	 * @param array<int, Schema> $candidates The slug-ordered candidates.
	 * @param int|null $consumingRegisterId The consuming schema's register id, or null.
	 *
	 * @return Schema
	 */
	private function preferConsuming(array $candidates, ?int $consumingRegisterId): Schema {
		if ($consumingRegisterId === null) {
			return $candidates[0];
		}

		$consumingIds = $this->schemaIdsOfRegister(registerId: $consumingRegisterId);
		foreach ($candidates as $candidate) {
			if (in_array((int)$candidate->getId(), $consumingIds, true) === true) {
				return $candidate;
			}
		}

		return $candidates[0];
	}//end preferConsuming()

	/**
	 * Emit the WARN that names an ambiguous pick, so ambiguous vocabulary is observable.
	 *
	 * @param array<string, mixed> $summary uri, candidates (slugs), picked, pickedId, consumingReg.
	 *
	 * @return void
	 */
	private function warnAmbiguous(array $summary): void {
		$this->logger->warning(
			message: sprintf(
				"[SemanticTypeResolver] %d schemas implement '%s'; picked '%s' (id %s)",
				count($summary['candidates']),
				$summary['uri'],
				$summary['picked'],
				(string)$summary['pickedId']
			),
			context: array_merge(['file' => __FILE__, 'line' => __LINE__], $summary)
		);
	}//end warnAmbiguous()

	/**
	 * Read the schema-id list of a register by id, empty on any failure.
	 *
	 * @param int $registerId The register id.
	 *
	 * @return array<int, int> The register's member schema ids (as ints).
	 */
	private function schemaIdsOfRegister(int $registerId): array {
		try {
			return $this->memberIds(register: $this->catalog->registers()->find(id: (string)$registerId));
		} catch (\Throwable $e) {
			return [];
		}
	}//end schemaIdsOfRegister()

	/**
	 * Resolve a schema's slug for deterministic ordering, falling back to
	 * uuid then id so a sort key is always available.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string The slug-like sort key.
	 */
	private function slugOf(Schema $schema): string {
		$slug = $schema->getSlug();
		if (is_string($slug) === true && $slug !== '') {
			return $slug;
		}

		$uuid = $schema->getUuid();
		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		return (string)$schema->getId();
	}//end slugOf()
}//end class
