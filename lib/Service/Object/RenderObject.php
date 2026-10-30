<?php

/**
 * OpenRegister RenderObject Handler
 *
 * Handler class responsible for transforming objects into their presentational format.
 * This handler provides methods for:
 * - Converting objects to their JSON representation
 * - Handling property extensions and nested objects
 * - Managing depth control for nested rendering
 * - Applying field filtering and selection
 * - Formatting object properties for display
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

use Adbar\Dot;
use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Formats\ExtendedFieldTypeValidator;
use OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver;
use OCA\OpenRegister\Service\Archival\RetentionEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\ExternalLink\ExternalLinkResolver;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCA\OpenRegister\Service\Hinge\LensResolver;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionService;
use OCA\OpenRegister\Service\Relation\LinkExposure;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Service\UrnService;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealMasker;
use OCP\IRequest;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handler class for rendering objects in the OpenRegister application.
 *
 * This handler is responsible for transforming objects into their presentational format,
 * including handling of extensions, depth control, and field filtering.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Rendering requires comprehensive transformation methods
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex rendering logic with multiple output formats
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Rendering requires multiple mapper and service dependencies
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class RenderObject {

	/**
	 * Cache of registers indexed by ID
	 *
	 * @var array<int|string, Register>
	 */
	private array $registersCache = [];

	/**
	 * Cache of schemas indexed by ID
	 *
	 * @var array<int|string, Schema>
	 */
	private array $schemasCache = [];

	/**
	 * Cache of objects indexed by ID or UUID
	 *
	 * @var array<int|string, ObjectEntity>
	 */
	private array $objectsCache = [];

	/**
	 * Ultra-aggressive preload cache for sub-second performance
	 *
	 * Contains ALL relationship objects preloaded in a single query
	 * for instant access during rendering without any additional database calls.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $ultraPreloadCache = [];

	/**
	 * Cache of inverse relationships: maps entity UUID to array of referencing objects.
	 * Used to batch-load inverse relationships for performance optimization.
	 *
	 * @var array<string, ObjectEntity[]>
	 */
	private array $inverseRelationCache = [];

	/**
	 * Transient cache of lightweight file IDs keyed by entity UUID.
	 *
	 * @var array<string, int[]>|null
	 *
	 * Populated by renderEntities() before its per-entity loop so renderEntity()
	 * does not need to issue one FileMapper query per row when extend does NOT
	 * cover `\@self.files`. Reset to null after the loop. When null, renderEntity
	 * falls back to a single-UUID FileMapper call.
	 */
	private ?array $batchFileIdsCache = null;

	/**
	 * Request-scoped cache of formatted file objects keyed by (string) fileid (PERF-1).
	 *
	 * Populated by renderEntities() (batch) before the per-entity loop so that
	 * renderFileProperties()/getFileObject() do not issue one FileMapper query per
	 * file per row. getFileObject() also back-fills this cache on the single-object
	 * path. Reset after the page loop.
	 *
	 * @var array<string, array|null>
	 */
	private array $fileObjectCache = [];

	/**
	 * Request-scoped cache of file tag-name lists keyed by (string) fileid (PERF-1).
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $fileTagsCache = [];

	/**
	 * Whether a full page render is in progress (PERF-8).
	 *
	 * Set by renderEntities() around its per-entity loop. When true, extendObject()
	 * skips its per-entity preloadObjects() call and relies on the warm page-level
	 * batch preload already populated into $objectsCache — avoiding duplicate
	 * UUID collection + preload work for every row.
	 *
	 * @var boolean
	 */
	private bool $pageRenderActive = false;

	/**
	 * The relation vocabulary reader, created on first use.
	 *
	 * Held rather than constructed per call because it memoises a schema's
	 * descriptors, and a page render asks the same schema the same question
	 * once per row. Not injected: it is a pure resolver with no dependencies,
	 * and threading it through this constructor would touch every caller and
	 * every test that builds one.
	 *
	 * @var RelationTypeResolver|null
	 */
	private ?RelationTypeResolver $relationTypes = null;

	/**
	 * The link exposure rule, created on first use.
	 *
	 * @var LinkExposure|null
	 */
	private ?LinkExposure $linkExposure = null;

	/**
	 * The explicit-reveal masker, resolved on first use.
	 *
	 * @var ExplicitRevealMasker|null
	 */
	private ?ExplicitRevealMasker $revealMasker = null;

	/**
	 * Constructor for RenderObject handler.
	 *
	 * @param FileMapper $fileMapper File mapper for database operations.
	 * @param MagicMapper $objectEntityMapper Object entity mapper for database operations.
	 * @param RegisterMapper $registerMapper Register mapper for database operations.
	 * @param SchemaMapper $schemaMapper Schema mapper for database operations.
	 * @param ISystemTagManager $systemTagManager System tag manager for file tags.
	 * @param ISystemTagObjectMapper $systemTagMapper System tag object mapper for file tags.
	 * @param CacheHandler $cacheHandler Cache service for performance optimization.
	 * @param CacheHandler $objectCacheService Object cache service for optimized loading.
	 * @param PropertyRbacHandler $propertyRbacHandler Property-level RBAC handler.
	 * @param LoggerInterface $logger Logger for performance monitoring.
	 * @param FileService $fileService File service for file operations.
	 * @param ComputedFieldHandler $computedFieldHandler Handler for computed field evaluation.
	 * @param TranslationHandler $translationHandler Handler for translatable property resolution.
	 * @param LinkedEntityEnricher $linkedEntityEnricher Enricher for linked entity metadata.
	 * @param CalculationEvaluator $calculationEvaluator Evaluator for derived/computed properties.
	 * @param UrnService $urnService URN resolver for register/schema/object identifiers.
	 * @param TranslationStatusService $translationStatusService Service exposing per-object translation status metadata.
	 * @param TranslationMapper $translationMapper Translation mapper for database operations.
	 * @param LanguageService $languageService Language service for source-language resolution.
	 * @param IRequest|null $request Current request, used to read `?recurrenceOccurrences=N`.
	 * @param ObjectSourceRegistry|null $objectSourceRegistry Resolves object-source providers for `$ref` extends into virtual schemas.
	 * @param FieldEncryptionHandler|null $fieldEncryptionHandler Field-level encryption handler (x-openregister-encrypted).
	 * @param ContainerInterface|null $container Lazily resolves RegistrySubscriptionService
	 *        (registry-subscriptions) — NOT constructor-injected directly: that dependency
	 *        chains ObjectService -> RenderObject -> RegistrySubscriptionService -> ObjectService,
	 *        a cycle Nextcloud's container refuses to construct eagerly. Same lazy-resolution
	 *        pattern PermissionHandler already uses for the same reason.
	 * @param LensResolver|null $lensResolver Resolves a schema's declared lenses at read time
	 *        (objects-as-the-hinge-between-cases). Nullable-with-a-default because this class is
	 *        constructed by hand in several tests, where a new required argument is a fatal; a
	 *        null resolver leaves the data exactly as it was, which is what a schema declaring
	 *        no lens gets anyway.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) All parameters are DI-injected dependencies
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-authorized-reads-are-decrypted-unauthorized-reads-never-see-ciphertext
	 */
	public function __construct(
		private readonly FileMapper $fileMapper,
		private readonly MagicMapper $objectEntityMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ISystemTagManager $systemTagManager,
		private readonly ISystemTagObjectMapper $systemTagMapper,
		private readonly CacheHandler $cacheHandler,
		private readonly CacheHandler $objectCacheService,
		private readonly PropertyRbacHandler $propertyRbacHandler,
		private readonly LoggerInterface $logger,
		private readonly FileService $fileService,
		private readonly ComputedFieldHandler $computedFieldHandler,
		private readonly TranslationHandler $translationHandler,
		private readonly LinkedEntityEnricher $linkedEntityEnricher,
		private readonly CalculationEvaluator $calculationEvaluator,
		private readonly UrnService $urnService,
		private readonly TranslationStatusService $translationStatusService,
		private readonly TranslationMapper $translationMapper,
		private readonly LanguageService $languageService,
		private readonly ?IRequest $request = null,
		private readonly ?ObjectSourceRegistry $objectSourceRegistry = null,
		private readonly ?FieldEncryptionHandler $fieldEncryptionHandler = null,
		private readonly ?ContainerInterface $container = null,
		private readonly ?LensResolver $lensResolver = null,
	) {
	}//end __construct()

	/**
	 * Set the ultra-aggressive preload cache for maximum performance
	 *
	 * This method receives ALL relationship objects loaded in a single query
	 * and stores them for instant access during rendering, eliminating all
	 * individual database queries for extended properties.
	 *
	 * @param array $ultraPreloadCache Array of preloaded objects indexed by ID/UUID
	 *
	 * @psalm-param   array<string, ObjectEntity> $ultraPreloadCache
	 * @phpstan-param array<string, ObjectEntity> $ultraPreloadCache
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function setUltraPreloadCache(array $ultraPreloadCache): void {
		$this->ultraPreloadCache = $ultraPreloadCache;
		$this->logger->debug(
			message: '[RenderObject] Ultra preload cache set',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'cachedObjectCount' => count($ultraPreloadCache),
			]
		);
	}//end setUltraPreloadCache()

	/**
	 * Get the size of the ultra preload cache for monitoring
	 *
	 * @return int Number of objects in the ultra preload cache
	 *
	 * @psalm-return int<0, max>
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function getUltraCacheSize(): int {
		return count($this->ultraPreloadCache);
	}//end getUltraCacheSize()

	/**
	 * Get a register from cache or database
	 *
	 * @param int|string $id The register ID
	 *
	 * @return Register|null The register or null if not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getRegister(int|string $id): ?Register {
		// Return from cache if available.
		if (($this->registersCache[$id] ?? null) !== null) {
			return $this->registersCache[$id];
		}

		try {
			$register = $this->registerMapper->find($id);
			// Cache the result.
			$this->registersCache[$id] = $register;
			return $register;
		} catch (\Exception $e) {
			return null;
		}
	}//end getRegister()

	/**
	 * Get a schema from cache or database
	 *
	 * @param int|string $id The schema ID
	 *
	 * @return Schema|null The schema or null if not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getSchema(int|string $id): ?Schema {
		// Return from cache if available.
		if (($this->schemasCache[$id] ?? null) !== null) {
			return $this->schemasCache[$id];
		}

		try {
			// Bypass multi-tenancy for internal schema resolution — schemas must always
			// be accessible when resolving references, regardless of the current user's organization.
			$schema = $this->schemaMapper->find($id, _multitenancy: false);
			// Cache the result.
			$this->schemasCache[$id] = $schema;
			return $schema;
		} catch (\Exception $e) {
			return null;
		}
	}//end getSchema()

	/**
	 * The names of a schema's write-only properties.
	 *
	 * `writeOnly: true` is JSON Schema's marker for a value a client may send but the
	 * server must never return — a password, an API key, a TOTP seed. renderEntity()
	 * strips these at the API boundary (openregister#380, ocon#147); the engine's internal
	 * `getObject()` is unaffected, so the value is still available to code that legitimately
	 * needs it (a synchronisation, the credential engine).
	 *
	 * Resolved through the same cached `getSchema()` used elsewhere in the renderer, so it
	 * adds no query on the hot path once the schema is cached. Returns an empty array when
	 * the schema cannot be resolved or declares no write-only property — i.e. the default
	 * for every existing schema, which is why this changes no behaviour until a schema opts
	 * in.
	 *
	 * @param int|string|null $schemaId The object's schema id/uuid/slug.
	 *
	 * @return array<int, string> The write-only property names.
	 */
	private function getWriteOnlyProperties(int|string|null $schemaId): array {
		if ($schemaId === null || $schemaId === '') {
			return [];
		}

		$schema = $this->getSchema(id: $schemaId);
		if ($schema === null) {
			return [];
		}

		$writeOnly = [];
		foreach (($schema->getProperties() ?? []) as $propertyName => $propertyConfig) {
			if (is_array($propertyConfig) === true
				&& ($propertyConfig['writeOnly'] ?? false) === true
				&& is_string($propertyName) === true
			) {
				$writeOnly[] = $propertyName;
			}
		}

		return $writeOnly;
	}//end getWriteOnlyProperties()

	/**
	 * Enrich `recurrence`-typed properties with their upcoming occurrences.
	 *
	 * For every property declared as `type: recurrence` whose value is a non-empty
	 * RRULE string, this attaches a sibling virtual field `_occurrences` listing
	 * the next N occurrences (ISO 8601). N defaults to 5 and may be overridden per
	 * request via `?recurrenceOccurrences=N` (clamped to 1..100). The RRULE string
	 * itself is preserved unchanged for round-trip integrity. When the object
	 * declares exactly one recurrence property, `_occurrences` is attached at the
	 * object root; with multiple, each is keyed as `<prop>_occurrences` to avoid
	 * collisions.
	 *
	 * @param array $objectData The object data being rendered.
	 * @param Schema $schema The schema for the object.
	 *
	 * @return array The object data, possibly enriched with occurrence lists.
	 *
	 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
	 */
	private function enrichRecurrenceOccurrences(array $objectData, Schema $schema): array {
		$properties = $schema->getProperties() ?? [];

		// Collect recurrence properties present in the object with a string value.
		$recurrenceProps = [];
		foreach ($properties as $propertyName => $propertyConfig) {
			$type = null;
			if (is_array($propertyConfig) === true) {
				$type = ($propertyConfig['type'] ?? null);
			} elseif (is_object($propertyConfig) === true) {
				$type = ($propertyConfig->type ?? null);
			}

			if ($type !== 'recurrence') {
				continue;
			}

			$value = ($objectData[$propertyName] ?? null);
			if (is_string($value) === true && $value !== '') {
				$recurrenceProps[$propertyName] = $value;
			}
		}//end foreach

		if (empty($recurrenceProps) === true) {
			return $objectData;
		}

		$count = $this->resolveOccurrenceCount();
		$validator = new ExtendedFieldTypeValidator();
		$single = (count($recurrenceProps) === 1);

		foreach ($recurrenceProps as $propertyName => $rrule) {
			$occurrences = $validator->materialiseOccurrences(rrule: $rrule, count: $count);
			$key = $propertyName . '_occurrences';
			if ($single === true) {
				$key = '_occurrences';
			}

			$objectData[$key] = $occurrences;
		}

		return $objectData;
	}//end enrichRecurrenceOccurrences()

	/**
	 * Resolve the requested number of recurrence occurrences from the request.
	 *
	 * Reads the `recurrenceOccurrences` query parameter (default 5, clamped to
	 * 1..100 by the validator). Falls back to the default when no request is
	 * available (e.g. in unit-test construction without an injected IRequest).
	 *
	 * @return int The requested occurrence count.
	 *
	 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
	 */
	private function resolveOccurrenceCount(): int {
		if ($this->request === null) {
			return ExtendedFieldTypeValidator::DEFAULT_OCCURRENCES;
		}

		$raw = $this->request->getParam('recurrenceOccurrences', null);
		if ($raw === null || is_numeric($raw) === false) {
			return ExtendedFieldTypeValidator::DEFAULT_OCCURRENCES;
		}

		return (int)$raw;
	}//end resolveOccurrenceCount()

	/**
	 * Check if a string looks like a UUID (using regex, not strict RFC 4122 validation).
	 *
	 * This allows non-RFC 4122 compliant UUIDs like those from GEMMA ArchiMate exports
	 * which may have non-standard variant bits.
	 *
	 * @param string $value The string to check
	 *
	 * @return bool True if the string matches UUID format
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function isUuidLike(string $value): bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
	}//end isUuidLike()

	/**
	 * Get an object from cache or database
	 *
	 * @param int|string $id The object ID or UUID
	 *
	 * @return ObjectEntity|null The object or null if not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getObject(int|string $id): ?ObjectEntity {
		// **ULTRA PERFORMANCE**: Check ultra preload cache first (fastest possible).
		if (($this->ultraPreloadCache[(string)$id] ?? null) !== null) {
			return $this->ultraPreloadCache[(string)$id];
		}

		// **PERFORMANCE OPTIMIZATION**: Use CacheHandler for optimized caching.
		// First check local cache for backward compatibility.
		if (($this->objectsCache[$id] ?? null) !== null) {
			return $this->objectsCache[$id];
		}

		// Use cache service for optimized loading (only if not in ultra cache).
		$object = $this->objectCacheService->getObject($id);

		// Update local cache for backward compatibility.
		if ($object !== null) {
			$this->objectsCache[$id] = $object;
			if ($object->getUuid() !== null && $object->getUuid() !== '') {
				$this->objectsCache[$object->getUuid()] = $object;
			}
		}

		return $object;
	}//end getObject()

	/**
	 * Clear all caches
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function clearCache(): void {
		$this->registersCache = [];
		$this->schemasCache = [];
		$this->objectsCache = [];
	}//end clearCache()

	/**
	 * Get the objects cache containing all extended/related objects indexed by UUID.
	 *
	 * This method returns all objects that were loaded during rendering (via _extend).
	 * Objects are indexed by their UUID for easy lookup by the frontend.
	 *
	 * @return array<string, array> Objects indexed by UUID, serialized as arrays
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function getObjectsCache(): array {
		$result = [];
		foreach ($this->objectsCache as $key => $object) {
			// Only include entries keyed by UUID (skip numeric IDs).
			$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
			if (is_string($key) === true && preg_match($uuidPattern, $key) === 1) {
				if ($object instanceof ObjectEntity) {
					$result[$key] = $object->jsonSerialize();
				} elseif (is_array($object) === true) {
					$result[$key] = $object;
				}
			}
		}

		return $result;
	}//end getObjectsCache()

	/**
	 * Attach the lightweight `\@self.files` ID list to a batch of un-rendered rows.
	 *
	 * @param array $rows Result rows by reference. Each row is mutated in place.
	 *
	 * @return void
	 *
	 * Used by list pipelines that bypass the full renderEntity pipeline (the
	 * cheap-path when no extend/fields/filter/unset is requested). Walks rows, collects UUIDs, issues a single
	 * batched FileMapper lookup, and writes the lightweight ID list onto each row's
	 * `\@self.files` (or the equivalent property on an ObjectEntity).
	 *
	 * Shape-tolerant: rows may be ObjectEntity instances or arrays. Rows whose UUID
	 * cannot be resolved get `\@self.files` set to []. Guarantees the spec contract
	 * that the field is always present on rendered list rows.
	 *
	 * Caller must not call this when rows already have full file metadata from
	 * renderEntities() — this method overwrites with IDs.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function attachLightweightFilesToRows(array &$rows): void {
		if (empty($rows) === true) {
			return;
		}

		// Collect UUIDs from heterogeneous row shapes.
		$uuidByIndex = [];
		foreach ($rows as $index => $row) {
			$uuidByIndex[$index] = self::extractRowUuid(row: $row);
		}

		$uniqueUuids = array_values(array_unique(array_filter($uuidByIndex, static fn ($uid) => $uid !== null)));
		$idsByUuid = [];
		if (empty($uniqueUuids) === false) {
			$idsByUuid = $this->fileMapper->getFileIdsForObjects($uniqueUuids);
		}

		foreach ($rows as $index => &$row) {
			$uuid = $uuidByIndex[$index] ?? null;
			$ids = [];
			if ($uuid !== null) {
				$ids = ($idsByUuid[$uuid] ?? []);
			}

			self::writeFilesToRow(row: $row, ids: $ids);
		}

		unset($row);
	}//end attachLightweightFilesToRows()

	/**
	 * Resolve translatable properties on a batch of un-rendered list rows.
	 *
	 * List pipelines that bypass the full renderEntity pipeline (the cheap-path
	 * when no extend/fields/filter/unset is requested) never resolve translatable
	 * properties, so translatable fields come back as raw language-keyed maps
	 * (e.g. `{"nl":"Foo","en":"Bar"}`) instead of the single projected string the
	 * single-object read path returns. This method closes that gap by delegating
	 * each row to TranslationHandler::resolveTranslationsForRender(), mirroring
	 * the resolution done in renderEntity().
	 *
	 * Behaviour:
	 * - No-op when the request opts in to `?_translations=all` and when the row's
	 *   schema declares no translatable properties (both early-return inside the
	 *   translation handler), so there is no cost for non-translatable schemas.
	 * - Multi-schema results resolve each row against ITS OWN schema and register.
	 *
	 * Shape-tolerant: rows may be ObjectEntity instances or serialized arrays.
	 * Rows whose schema cannot be resolved are left untouched.
	 *
	 * @param array $rows Result rows by reference. Each row is mutated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function resolveTranslationsForRows(array &$rows): void {
		if (empty($rows) === true) {
			return;
		}

		// Fast global skip: when `?_translations=all` is requested, no projection
		// happens at all — resolveTranslationsForRender early-returns per row, but
		// we short-circuit here to avoid resolving schemas/registers needlessly.
		if ($this->languageService->shouldReturnAllTranslations() === true) {
			return;
		}

		foreach ($rows as &$row) {
			if ($row instanceof ObjectEntity === true) {
				$schemaId = $row->getSchema();
				if ($schemaId === null) {
					continue;
				}

				$schema = $this->getSchema(id: $schemaId);
				if ($schema === null) {
					continue;
				}

				$registerId = $row->getRegister();
				$register = null;
				if ($registerId !== null) {
					$register = $this->getRegister(id: $registerId);
				}

				$resolved = $this->translationHandler->resolveTranslationsForRender(
					objectData: $row->getObject(),
					schema: $schema,
					register: $register
				);
				$row->setObject($resolved);
				continue;
			}//end if

			if (is_array($row) === true) {
				$schemaId = $row['@self']['schema'] ?? null;
				if (is_int($schemaId) === false && is_string($schemaId) === false) {
					continue;
				}

				$schema = $this->getSchema(id: $schemaId);
				if ($schema === null) {
					continue;
				}

				$registerId = $row['@self']['register'] ?? null;
				$register = null;
				if (is_int($registerId) === true || is_string($registerId) === true) {
					$register = $this->getRegister(id: $registerId);
				}

				$row = $this->translationHandler->resolveTranslationsForRender(
					objectData: $row,
					schema: $schema,
					register: $register
				);
			}//end if
		}//end foreach

		unset($row);
	}//end resolveTranslationsForRows()

	/**
	 * Resolve the declared lenses on a batch of un-rendered list rows.
	 *
	 * The list fast paths bypass renderEntity, where a schema's lenses are
	 * resolved, so a lens property was absent from a list row while the same
	 * object read on its own carried it. Each ObjectEntity row is resolved
	 * against its own schema; a schema without lenses is skipped.
	 *
	 * @param array $rows  Result rows by reference; ObjectEntity rows are mutated in place.
	 * @param bool  $_rbac Whether the reader's access to the referenced object is checked.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The lens resolver takes the rbac flag
	 *
	 * @spec openspec/specs/object-lenses/spec.md
	 */
	public function applyLensesToRows(array &$rows, bool $_rbac=true): void {
		if ($this->lensResolver === null) {
			return;
		}

		foreach ($rows as $row) {
			if ($row instanceof ObjectEntity === false || $row->getSchema() === null) {
				continue;
			}

			$schema = $this->getSchema(id: $row->getSchema());
			if ($schema === null || $schema->getLenses() === []) {
				continue;
			}

			$row->setObject($this->applyLens(schema: $schema, objectData: ($row->getObject() ?? []), rbac: $_rbac));
		}
	}//end applyLensesToRows()

	/**
	 * Redact write-only properties from cheap-path list rows (openregister#380, ocon#147).
	 *
	 * The list cheap-path (no _extend/_fields/_filter/_unset) does NOT run rows through
	 * renderEntity() — for performance — so the write-only redaction enforced there never
	 * runs. That is exactly the path the OpenConnector leak used: `GET .../objects/{reg}/
	 * {schema}` is a LIST, so it took the cheap-path and returned every Source's apikey in
	 * cleartext even after the single-object read was redacted. This method closes that gap
	 * with the same rule, mirroring resolveTranslationsForRows()'s per-row schema
	 * resolution and object-vs-array handling.
	 *
	 * writeOnly stripping is a HARD render-boundary rule and is NOT rbac-gated, exactly as
	 * on the single-object path (renderEntity, #389): a `writeOnly: true` property — and a
	 * nested `x-openregister-writeonly-paths` dot-path (#459), which rides the same
	 * boundary — must never be returned on ANY read, including admin and `_rbac: false`
	 * renders. `ObjectsController` derives `_rbac` as `($isAdmin === false)`, so an ADMIN
	 * list/search read arrives here with `_rbac: false`; gating writeOnly on it returned
	 * every secret in cleartext (#460 — the list half of #389, which only ever fixed
	 * renderEntity). `_rbac: false` on an HTTP read means "this caller bypasses WHICH
	 * OBJECTS it may see", never "this caller may see secrets"; conflating the two was the
	 * bug. Internal code that needs a raw secret value reads ObjectEntity::getObject() from
	 * the mapper, or `find(..., _render: false)`, and never enters a render path.
	 *
	 * Property `authorization.read` (group-based) stripping and field decryption REMAIN
	 * `_rbac` / SystemOperationContext-gated: those are trusted-internal-read bypasses that
	 * mirror PermissionHandler::hasPermission(), and are unchanged by #460.
	 *
	 * Strips the object body AND the `@self.relations` search index copy (#429), which is a
	 * separate scalar mirror of reference-shaped values and therefore leaks a writeOnly
	 * secret independently of the body.
	 *
	 * @param array $rows Result rows by reference, mutated in place.
	 * @param bool $_rbac Whether this is a user-facing read (true) or system context (false).
	 *                    Gates ONLY the authorization.read strip and decryption — never writeOnly.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-writeonly-properties-must-never-be-returned-on-any-read
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-nested-writeonly-paths-must-never-be-returned-on-any-read
	 */
	public function redactWriteOnlyFromRows(array &$rows, bool $_rbac = true): void {
		if (empty($rows) === true) {
			return;
		}

		// The trusted-internal-read bypass applies to property `authorization.read` and to
		// decryption ONLY. The writeOnly strip below deliberately does not consult it.
		$applyGatedStrips = ($_rbac !== false) && (SystemOperationContext::isActive() === false);

		foreach ($rows as &$row) {
			if ($row instanceof ObjectEntity === true) {
				$schema = $this->getSchema(id: $row->getSchema());

				$doWriteOnly = $this->schemaHasWriteOnlyRule(schema: $schema);
				$doGated = ($applyGatedStrips === true && $this->schemaNeedsReadStrip(schema: $schema) === true);
				if ($doWriteOnly === false && $doGated === false) {
					$this->maskEntityReveals(entity: $row, schema: $schema);
					continue;
				}

				$object = $row->getObject();
				if (is_array($object) === true) {
					if ($doGated === true) {
						// Note filterReadableProperties strips writeOnly first, then applies
						// the group-authorization filter, so this covers both concerns.
						$unfiltered = $object;
						$object = $this->propertyRbacHandler->filterReadableProperties(schema: $schema, object: $object);
						$this->withholdEntityCopies(
							entity: $row,
							schema: $schema,
							removed: $this->removedProperties(before: $unfiltered, after: $object)
						);
					} else {
						// The writeOnly-only path (admin / `_rbac: false` / system context, or a
						// schema with no property authorization): strip writeOnly unconditionally.
						$object = $this->propertyRbacHandler->stripWriteOnlyProperties(schema: $schema, object: $object);
					}

					// Decrypt AFTER redaction: a field the RBAC/writeOnly strip above just
					// removed is no longer a key in $object, so decryptProperties() (which
					// only acts on keys still present) silently skips it — an unauthorized
					// caller never reaches decryption for that field.
					if ($doGated === true
						&& $this->fieldEncryptionHandler !== null
						&& $schema->hasEncryptedProperties() === true
					) {
						$object = $this->fieldEncryptionHandler->decryptProperties(data: $object, schema: $schema);
					}//end if

					$row->setObject($object);
				}//end if

				// Explicit-reveal values leave masked on every read, admins and `_rbac: false` included.
				$this->maskEntityReveals(entity: $row, schema: $schema);

				// The body strip above leaves the `relations` search index untouched; it is
				// a separate scalar mirror that jsonSerialize surfaces as @self.relations,
				// so a write-only secret leaks there unless stripped too (#429). It rides the
				// SAME unconditional boundary as the body strip. Encrypted values are
				// ciphertext by the time scanForRelations() runs at save time and do not look
				// like references (isReference() matches UUID/URI/URL shapes), so they are not
				// expected to appear in this mirror in the first place — only the writeOnly
				// strip applies here.
				if ($doWriteOnly === true) {
					$relations = $row->getRelations();
					if (is_array($relations) === true) {
						$row->setRelations($this->propertyRbacHandler->stripWriteOnlyProperties(schema: $schema, object: $relations));
					}
				}

				continue;
			}//end if

			if (is_array($row) === true) {
				$schemaId = $row['@self']['schema'] ?? null;
				if (is_int($schemaId) === false && is_string($schemaId) === false) {
					continue;
				}

				$schema = $this->getSchema(id: $schemaId);

				$doWriteOnly = $this->schemaHasWriteOnlyRule(schema: $schema);
				$doGated = ($applyGatedStrips === true && $this->schemaNeedsReadStrip(schema: $schema) === true);
				if ($doWriteOnly === false && $doGated === false) {
					$row = $this->maskRowReveals(row: $row, schema: $schema);
					continue;
				}

				if ($doGated === true) {
					$unfiltered = $row;
					$row = $this->propertyRbacHandler->filterReadableProperties(schema: $schema, object: $row);
					$row = $this->withholdRowCopies(
						row: $row,
						schema: $schema,
						removed: $this->removedProperties(before: $unfiltered, after: $row)
					);
				} else {
					$row = $this->propertyRbacHandler->stripWriteOnlyProperties(schema: $schema, object: $row);
				}

				// Decrypt AFTER redaction, same ordering rationale as the ObjectEntity branch above.
				if ($doGated === true
					&& $this->fieldEncryptionHandler !== null
					&& $schema->hasEncryptedProperties() === true
				) {
					$row = $this->fieldEncryptionHandler->decryptProperties(data: $row, schema: $schema);
				}

				$row = $this->maskRowReveals(row: $row, schema: $schema);

				if ($doWriteOnly === true
					&& isset($row['@self']['relations']) === true
					&& is_array($row['@self']['relations']) === true
				) {
					$row['@self']['relations'] = $this->propertyRbacHandler->stripWriteOnlyProperties(
						schema: $schema,
						object: $row['@self']['relations']
					);
				}
			}//end if
		}//end foreach

		unset($row);
	}//end redactWriteOnlyFromRows()

	/**
	 * The schema configuration keys that copy a property into `@self` metadata.
	 *
	 * @var array<string, string> configuration key => `@self` field
	 */
	public const METADATA_COPIES = [
		'objectNameField'        => 'name',
		'objectDescriptionField' => 'description',
		'objectSummaryField'     => 'summary',
		'objectImageField'       => 'image',
	];

	/**
	 * The properties a read filter removed from an object body.
	 *
	 * @param array<string, mixed> $before The body before filtering.
	 * @param array<string, mixed> $after  The body after filtering.
	 *
	 * @return array<int, string> The removed property names (`@`-keys excluded).
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function removedProperties(array $before, array $after): array {
		$removed = [];
		foreach (array_keys($before) as $key) {
			$key = (string)$key;
			if (str_starts_with($key, '@') === false && array_key_exists($key, $after) === false) {
				$removed[] = $key;
			}
		}

		return $removed;
	}//end removedProperties()

	/**
	 * The explicit-reveal masker, or null where this class was built without a container.
	 *
	 * @return ExplicitRevealMasker|null
	 */
	private function revealMasker(): ?ExplicitRevealMasker {
		if ($this->revealMasker === null && $this->container !== null) {
			$this->revealMasker = $this->container->get(ExplicitRevealMasker::class);
		}

		return $this->revealMasker;
	}//end revealMasker()

	/**
	 * Mask a rendered object's explicit-reveal values and withhold their `@self` copies.
	 *
	 * @param ObjectEntity         $entity     The entity rendered.
	 * @param Schema|null          $schema     Its schema.
	 * @param array<string, mixed> $objectData The rendered body.
	 *
	 * @return array<string, mixed> The body, masked.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	private function maskRenderedReveals(ObjectEntity $entity, ?Schema $schema, array $objectData): array {
		if ($schema === null || ExplicitReveal::explicitProperties(schema: $schema) === [] || $this->revealMasker() === null) {
			return $objectData;
		}

		[$objectData, $masked] = $this->revealMasker()->maskObject(
			schema: $schema,
			object: $objectData,
			objectIds: [(string)$entity->getUuid()]
		);
		$this->withholdEntityCopies(entity: $entity, schema: $schema, removed: $masked);

		return $objectData;
	}//end maskRenderedReveals()

	/**
	 * Mask a list entity's explicit-reveal values in place.
	 *
	 * @param ObjectEntity $entity The entity.
	 * @param Schema|null  $schema Its schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	private function maskEntityReveals(ObjectEntity $entity, ?Schema $schema): void {
		$object = $entity->getObject();
		if (is_array($object) === true) {
			$entity->setObject($this->maskRenderedReveals(entity: $entity, schema: $schema, objectData: $object));
		}
	}//end maskEntityReveals()

	/**
	 * Mask an array row's explicit-reveal values and withhold their `@self` copies.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param Schema|null          $schema Its schema.
	 *
	 * @return array<string, mixed> The row, masked.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	private function maskRowReveals(array $row, ?Schema $schema): array {
		if ($schema === null || ExplicitReveal::explicitProperties(schema: $schema) === [] || $this->revealMasker() === null) {
			return $row;
		}

		[$row, $masked] = $this->revealMasker()->maskObject(schema: $schema, object: $row);

		return $this->withholdRowCopies(row: $row, schema: $schema, removed: $masked);
	}//end maskRowReveals()

	/**
	 * Remove the copies of withheld properties from an entity's `@self`.
	 *
	 * 🔴 THE BODY IS NOT THE ONLY COPY OF A PROPERTY. The `relations` mirror
	 * holds every reference-shaped scalar, and `objectNameField` /
	 * `objectDescriptionField` / `objectSummaryField` / `objectImageField` copy
	 * a property into `@self.name|description|summary|image` at save time. The
	 * read filter removed the property from the body and left both copies, so
	 * an anonymous caller read a value the schema withholds from it through
	 * `@self` (found live on the Rotterdam stack: a contact person's uuid and
	 * two document references in `@self.relations`, a long description in
	 * `@self.description`). Whatever the filter removed from the body is
	 * removed from its copies, by the same decision.
	 *
	 * @param ObjectEntity       $entity  The rendered entity (mutated in memory only).
	 * @param Schema             $schema  Its schema.
	 * @param array<int, string> $removed Properties the read filter removed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function withholdEntityCopies(ObjectEntity $entity, Schema $schema, array $removed): void {
		if ($removed === []) {
			return;
		}

		$relations = $entity->getRelations();
		if (is_array($relations) === true && $relations !== []) {
			$entity->setRelations($this->withoutRelationKeys(relations: $relations, removed: $removed));
		}

		foreach ($this->withheldMetadataFields(schema: $schema, removed: $removed) as $field) {
			$setter = 'set' . ucfirst($field);
			$entity->$setter(null);
		}
	}//end withholdEntityCopies()

	/**
	 * Remove the copies of withheld properties from an array row's `@self`.
	 *
	 * The array-row twin of {@see self::withholdEntityCopies()}.
	 *
	 * @param array<string, mixed> $row     The filtered row.
	 * @param Schema               $schema  Its schema.
	 * @param array<int, string>   $removed Properties the read filter removed.
	 *
	 * @return array<string, mixed> The row with its `@self` copies withheld.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function withholdRowCopies(array $row, Schema $schema, array $removed): array {
		if ($removed === [] || isset($row['@self']) === false || is_array($row['@self']) === false) {
			return $row;
		}

		if (isset($row['@self']['relations']) === true && is_array($row['@self']['relations']) === true) {
			$row['@self']['relations'] = $this->withoutRelationKeys(relations: $row['@self']['relations'], removed: $removed);
		}

		foreach ($this->withheldMetadataFields(schema: $schema, removed: $removed) as $field) {
			if (array_key_exists($field, $row['@self']) === true) {
				$row['@self'][$field] = null;
			}
		}

		return $row;
	}//end withholdRowCopies()

	/**
	 * Drop relation keys that belong to a removed property.
	 *
	 * A key is the property itself or a dotted path under it (`members.0`).
	 *
	 * @param array<string, mixed> $relations The relations mirror.
	 * @param array<int, string>   $removed   Properties the read filter removed.
	 *
	 * @return array<string, mixed> The mirror without them.
	 */
	private function withoutRelationKeys(array $relations, array $removed): array {
		foreach (array_keys($relations) as $key) {
			$key = (string)$key;
			foreach ($removed as $property) {
				if ($key === $property || str_starts_with($key, $property . '.') === true) {
					unset($relations[$key]);
					break;
				}
			}
		}

		return $relations;
	}//end withoutRelationKeys()

	/**
	 * The `@self` metadata fields whose source property was removed.
	 *
	 * @param Schema             $schema  The schema whose configuration names the sources.
	 * @param array<int, string> $removed Properties the read filter removed.
	 *
	 * @return array<int, string> `@self` fields to blank.
	 */
	private function withheldMetadataFields(Schema $schema, array $removed): array {
		$configuration = ($schema->getConfiguration() ?? []);
		$fields = [];
		foreach (self::METADATA_COPIES as $configKey => $field) {
			$source = trim((string)($configuration[$configKey] ?? ''));
			if ($source === '') {
				continue;
			}

			$topLevel = explode('.', $source)[0];
			if (in_array($topLevel, $removed, true) === true) {
				$fields[] = $field;
			}
		}

		return $fields;
	}//end withheldMetadataFields()

	/**
	 * Whether a schema has any read-strip rule (property-level read authorization or a
	 * write-only property). Mirrors the guard in renderEntity's read-strip block, so the
	 * cheap-path and the single-object path agree on when to strip.
	 *
	 * @param Schema|null $schema The row's schema.
	 *
	 * @return bool
	 */
	private function schemaNeedsReadStrip(?Schema $schema): bool {
		return $schema !== null
			&& ($schema->hasPropertyAuthorization() === true
				|| $this->schemaHasWriteOnlyRule(schema: $schema) === true
				|| $schema->hasEncryptedProperties() === true);
	}//end schemaNeedsReadStrip()

	/**
	 * Whether a schema carries ANY write-only rule — a declared property with
	 * `writeOnly: true`, or a nested dot-path declared via
	 * `x-openregister-writeonly-paths`.
	 *
	 * Both spellings answer the same question ("must this render strip secrets?") and
	 * ride the same unconditional render boundary, so every gate asks it through here
	 * rather than checking one and forgetting the other — which is precisely how the
	 * nested paths would have ended up as a phantom annotation.
	 *
	 * @param Schema|null $schema The schema to inspect.
	 *
	 * @return bool True when the render path must apply write-only stripping.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-nested-writeonly-paths-must-never-be-returned-on-any-read
	 */
	private function schemaHasWriteOnlyRule(?Schema $schema): bool {
		return $schema !== null
			&& ($schema->hasWriteOnlyProperties() === true || $schema->hasWriteOnlyPaths() === true);
	}//end schemaHasWriteOnlyRule()

	/**
	 * Extract the UUID from a list-row of unknown shape (ObjectEntity or array).
	 *
	 * @param mixed $row The row to inspect.
	 *
	 * @return string|null Resolved UUID or null when unrecognized.
	 */
	private static function extractRowUuid($row): ?string {
		if ($row instanceof ObjectEntity === true) {
			return $row->getUuid();
		}

		if (is_array($row) === false) {
			return null;
		}

		// Magic-mapper rows expose the UUID at @self.id.
		if (isset($row['@self']['id']) === true && is_string($row['@self']['id']) === true) {
			return $row['@self']['id'];
		}

		if (isset($row['@self']['uuid']) === true && is_string($row['@self']['uuid']) === true) {
			return $row['@self']['uuid'];
		}

		if (isset($row['id']) === true && is_string($row['id']) === true) {
			return $row['id'];
		}

		return null;
	}//end extractRowUuid()

	/**
	 * Write the lightweight file-IDs list to a row's @self.files of unknown shape.
	 *
	 * @param mixed $row Row by reference.
	 * @param int[] $ids File IDs to attach.
	 *
	 * @return void
	 */
	private static function writeFilesToRow(&$row, array $ids): void {
		if ($row instanceof ObjectEntity === true) {
			$row->setFiles($ids);
			return;
		}

		if (is_array($row) === true) {
			if (isset($row['@self']) === false || is_array($row['@self']) === false) {
				$row['@self'] = [];
			}

			$row['@self']['files'] = $ids;
		}
	}//end writeFilesToRow()

	/**
	 * Decide whether the request opts in to full @self.files metadata.
	 *
	 * Recognizes the canonical form `@self.files` and the equivalent shorthand
	 * `_files`. Accepts both array and comma-separated string forms.
	 *
	 * The blanket `all` token is intentionally NOT recognized here. The
	 * proposal pins `@self.files` as a strict, explicit opt-in — and `all`
	 * is propagated to sub-entity renders via `array_merge(['all'], $keyExtends)`,
	 * which would silently pay full file-metadata cost on every linked
	 * sub-object whenever a top-level request used `_extend[]=all`. Consumers
	 * that want full file metadata must spell it explicitly.
	 *
	 * @param array|string|null $extend Extend parameter from the request.
	 *
	 * @return bool True when full file metadata should be rendered.
	 */
	private static function shouldExtendFiles(array|string|null $extend): bool {
		if ($extend === null || $extend === '' || $extend === []) {
			return false;
		}

		if (is_string($extend) === true) {
			$extend = array_filter(array_map('trim', explode(',', $extend)));
		}

		// Type-system invariant: by here, $extend is `array` (the null/empty cases
		// already returned, and string was converted above).
		return in_array('@self.files', $extend, true) === true
			|| in_array('_files', $extend, true) === true;
	}//end shouldExtendFiles()

	/**
	 * Attach the lightweight default for @self.files: a list of integer file IDs.
	 *
	 * Uses the renderEntities batch cache when available (populated before the
	 * per-entity loop for list endpoints) to avoid per-row FileMapper queries.
	 * Falls back to a single-UUID FileMapper call for one-off renders (show paths).
	 *
	 * @param ObjectEntity $entity The entity to attach the lightweight file IDs to.
	 *
	 * @return ObjectEntity The same entity with $entity->setFiles($ids) applied.
	 */
	private function setLightweightFileIds(ObjectEntity $entity): ObjectEntity {
		$uuid = $entity->getUuid();
		if ($uuid === null) {
			$entity->setFiles([]);
			return $entity;
		}

		// Distinguish "UUID was in the batch and had zero files" from "UUID was not
		// in the batch at all". The batch cache is populated by renderEntities()
		// with only the top-level page entity UUIDs; sub-entities reached via
		// relation extends are NOT in it. For a sub-entity we must fall through
		// to a single-UUID lookup, otherwise its `@self.files` would silently
		// emit `[]` even when the entity has attached files.
		if ($this->batchFileIdsCache !== null && array_key_exists(key: $uuid, array: $this->batchFileIdsCache) === true) {
			$entity->setFiles($this->batchFileIdsCache[$uuid]);
			return $entity;
		}

		$idsByUuid = $this->fileMapper->getFileIdsForObjects([$uuid]);
		$entity->setFiles($idsByUuid[$uuid] ?? []);
		return $entity;
	}//end setLightweightFileIds()

	/**
	 * Add formatted files to the files array in the entity using FileMapper.
	 *
	 * This method retrieves files for an object using the FileMapper's getFilesForObject method,
	 * which handles both folder property lookup and UUID-based fallback search.
	 * The retrieved files are then formatted to match the FileService->formatFile() structure.
	 * Share information is now included directly from the FileMapper database query.
	 *
	 * @param ObjectEntity $object The entity to add the files to
	 *
	 * @return ObjectEntity The updated object with files information
	 *
	 * @throws \RuntimeException If multiple nodes are found for the object's uuid
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function renderFiles(ObjectEntity $object): ObjectEntity {
		// Use FileMapper to get files for the object (handles folder property and UUID fallback).
		$fileRecords = $this->withoutRestrictedFiles(fileRecords: $this->fileMapper->getFilesForObject($object));

		// If no files found, set empty array and return.
		if (empty($fileRecords) === true) {
			$object->setFiles([]);
			return $object;
		}

		// Batch-load all file tags in 2 queries instead of 2*N queries.
		$allFileIds = array_map(fn ($f) => (string)$f['fileid'], $fileRecords);

		// OCP declares this `array<string, list<string>>`, but file ids are
		// canonical numeric strings, so PHP coerces every key to INT on the way
		// in. Without the annotation below PHPStan concludes a numeric-string
		// offset can never match an `array<string, ...>` and reports the
		// per-file tag loop as iterating an always-empty array. It is not: PHP
		// applies the same coercion on lookup, so `$allTagIdsPerFile["123"]`
		// finds the row stored under 123.
		// phpcs:ignore Squiz.Commenting.InlineComment.DocBlock -- PHPStan only reads @var from a /** */ block.
		/** @var array<int|string, list<string>> $allTagIdsPerFile */
		$allTagIdsPerFile = $this->systemTagMapper->getTagIdsForObjects(
			objIds: $allFileIds,
			objectType: 'files'
		);

		// Collect all unique tag IDs across all files and load them in one call.
		$uniqueTagIds = [];
		foreach ($allTagIdsPerFile as $fileTagIds) {
			foreach ($fileTagIds as $tagId) {
				$uniqueTagIds[$tagId] = true;
			}
		}

		$tagNameMap = [];
		if (empty($uniqueTagIds) === false) {
			$tags = $this->systemTagManager->getTagsByIds(tagIds: array_keys($uniqueTagIds));
			foreach ($tags as $tag) {
				$name = $tag->getName();
				if (str_starts_with($name, 'object:') === false) {
					$tagNameMap[$tag->getId()] = $name;
				}
			}
		}

		// Format the files using the pre-loaded tag data.
		$formattedFiles = [];
		foreach ($fileRecords as $fileRecord) {
			$fileId = (string)$fileRecord['fileid'];

			// Build labels from pre-loaded tags.
			$labels = [];
			foreach ($allTagIdsPerFile[$fileId] ?? [] as $tagId) {
				if (isset($tagNameMap[$tagId]) === true) {
					$labels[] = $tagNameMap[$tagId];
				}
			}

			// Create formatted file metadata matching FileService->formatFile() structure.
			// Share information is now included directly from FileMapper.
			$formattedFile = [
				'id' => $fileId,
				'path' => $fileRecord['path'],
				'title' => $fileRecord['name'],
				'accessUrl' => $fileRecord['accessUrl'] ?? null,
				'downloadUrl' => $fileRecord['downloadUrl'] ?? null,
				'type' => $fileRecord['mimetype'] ?? 'application/octet-stream',
				'extension' => pathinfo($fileRecord['name'], PATHINFO_EXTENSION),
				'size' => (int)$fileRecord['size'],
				'hash' => $fileRecord['etag'] ?? '',
				'published' => $fileRecord['published'] ?? null,
				'modified' => $fileRecord['mtime'] ?? null,
				'labels' => $labels,
			];

			$formattedFiles[] = $formattedFile;
		}//end foreach

		// Set the formatted files on the object.
		$object->setFiles($formattedFiles);

		return $object;
	}//end renderFiles()

	/**
	 * Get the tags associated with a file.
	 *
	 * This method implements the same logic as FileService->getFileTags() to retrieve
	 * tags associated with a file by its ID. It filters out internal 'object:' tags.
	 *
	 * @param string $fileId The ID of the file
	 *
	 * @psalm-return   list<string>
	 * @phpstan-return array<int, string>
	 *
	 * @return array List of file tags
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getFileTags(string $fileId): array {
		// PERF-1: serve from the request-scoped batch cache when present (page render).
		if (array_key_exists($fileId, $this->fileTagsCache) === true) {
			return $this->fileTagsCache[$fileId];
		}

		// File tag type constant (same as in FileService).
		$fileTagType = 'files';

		// Get tag IDs for the file.
		$tagIds = $this->systemTagMapper->getTagIdsForObjects(
			objIds: [$fileId],
			objectType: $fileTagType
		);

		// Check if file has any tags.
		if (isset($tagIds[$fileId]) === false || empty($tagIds[$fileId]) === true) {
			$this->fileTagsCache[$fileId] = [];
			return [];
		}

		// Get the actual tag objects by their IDs.
		$tags = $this->systemTagManager->getTagsByIds(tagIds: $tagIds[$fileId]);

		// Extract tag names from tag objects and filter out 'object:' tags.
		$tagNames = array_filter(
			array_map(
				static function ($tag) {
					return $tag->getName();
				},
				$tags
			),
			static function ($tagName) {
				// Filter out internal object tags.
				return !str_starts_with($tagName, 'object:');
			}
		);

		// Return array of filtered tag names (PERF-1: back-fill request cache).
		$result = array_values($tagNames);
		$this->fileTagsCache[$fileId] = $result;
		return $result;
	}//end getFileTags()

	/**
	 * Hydrates file properties by replacing file IDs with actual file objects.
	 *
	 * This method processes object properties that are configured as file types in the schema,
	 * replacing stored file IDs with complete file objects for presentation. It handles both
	 * single file properties and arrays of files.
	 *
	 * @param ObjectEntity $entity The entity to process.
	 *
	 * @psalm-param   ObjectEntity $entity
	 * @phpstan-param ObjectEntity $entity
	 *
	 * @return ObjectEntity The entity with hydrated file properties.
	 *
	 * @psalm-return   ObjectEntity
	 * @phpstan-return ObjectEntity
	 *
	 * @throws Exception If schema or file operations fail.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) File property handling requires multiple type checks
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function renderFileProperties(ObjectEntity $entity): ObjectEntity {
		try {
			// Get the schema for this object to understand property configurations.
			$schema = $this->getSchema(id: $entity->getSchema());
			if ($schema === null) {
				// If no schema found, return entity unchanged.
				return $entity;
			}

			$schemaProperties = $schema->getProperties() ?? [];
			$objectData = $entity->getObject();

			// First, ensure all file array properties exist in objectData (even if empty).
			// This is important for properties that have been set to empty arrays.
			foreach ($schemaProperties as $propertyName => $propertyConfig) {
				if ($this->isFilePropertyConfig(propertyConfig: $propertyConfig) === true) {
					$isArrayProperty = ($propertyConfig['type'] ?? '') === 'array';

					// If it's an array property and not set, initialize it as empty array.
					if (($isArrayProperty === true) && (($objectData[$propertyName] ?? null) === null) === true) {
						$objectData[$propertyName] = [];
					}
				}
			}

			// Process each property in the object data.
			foreach ($objectData ?? [] as $propertyName => $propertyValue) {
				// Skip metadata properties.
				if (str_starts_with($propertyName, '@') === true || $propertyName === 'id') {
					continue;
				}

				// Check if this property is configured in the schema.
				if (isset($schemaProperties[$propertyName]) === false) {
					continue;
				}

				$propertyConfig = $schemaProperties[$propertyName];

				// Check if this is a file property (direct or array[file]).
				if ($this->isFilePropertyConfig(propertyConfig: $propertyConfig) === true) {
					$objectData[$propertyName] = $this->hydrateFileProperty(
						propertyValue: $propertyValue,
						propertyConfig: $propertyConfig,
						_propertyName: $propertyName
					);
				}
			}//end foreach

			// Update the entity with hydrated data.
			$entity->setObject($objectData);
		} catch (Exception $e) {
			// Log error but don't break rendering - just return original entity.
		}//end try

		return $entity;
	}//end renderFileProperties()

	/**
	 * Checks if a property configuration indicates a file property.
	 *
	 * @param array $propertyConfig The property configuration from schema.
	 *
	 * @psalm-param   array<string, mixed> $propertyConfig
	 * @phpstan-param array<string, mixed> $propertyConfig
	 *
	 * @return bool True if this is a file property configuration.
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function isFilePropertyConfig(array $propertyConfig): bool {
		// Direct file property.
		if (($propertyConfig['type'] ?? '') === 'file') {
			return true;
		}

		// Array of files.
		if (($propertyConfig['type'] ?? '') === 'array'
			&& (($propertyConfig['items'] ?? null) !== null)
			&& ($propertyConfig['items']['type'] ?? '') === 'file'
		) {
			return true;
		}

		return false;
	}//end isFilePropertyConfig()

	/**
	 * Hydrates a file property by replacing file IDs with file objects or base64 content.
	 *
	 * If the property config has `format: base64`, returns the file content as a base64
	 * data URI string. Otherwise, returns the file metadata object with URLs.
	 *
	 * @param mixed $propertyValue The property value (file ID or array of file IDs).
	 * @param array $propertyConfig The property configuration from schema.
	 * @param string $_propertyName The property name (for error reporting).
	 *
	 * @psalm-param   mixed $propertyValue
	 * @psalm-param   array<string, mixed> $propertyConfig
	 * @psalm-param   string $_propertyName
	 * @phpstan-param mixed $propertyValue
	 * @phpstan-param array<string, mixed> $propertyConfig
	 * @phpstan-param string $_propertyName
	 *
	 * @return mixed The hydrated property value (file object, base64 string, or array).
	 *
	 * @psalm-return   mixed
	 * @phpstan-return mixed
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function hydrateFileProperty($propertyValue, array $propertyConfig, string $_propertyName) {
		$isArrayProperty = ($propertyConfig['type'] ?? '') === 'array';

		// Determine if base64 format is requested.
		// Check both the property config and items config (for arrays).
		$fileConfig = $propertyConfig;
		if ($isArrayProperty === true) {
			$fileConfig = ($propertyConfig['items'] ?? []);
		}

		$returnBase64 = ($fileConfig['format'] ?? '') === 'base64';

		// PERF-11: never embed base64 file content on a list/collection (page) render —
		// reading whole files into memory per file per row blows up peak memory. On a
		// page render, fall back to the lightweight file reference instead; the full
		// base64 content is still produced on single-object GETs (size-capped below).
		if ($returnBase64 === true && $this->pageRenderActive === true) {
			$returnBase64 = false;
		}

		if ($isArrayProperty === true) {
			// Handle array of files.
			if (is_array($propertyValue) === false) {
				return $propertyValue;
				// Return unchanged if not an array.
			}

			$hydratedFiles = [];
			foreach ($propertyValue as $fileId) {
				if ($returnBase64 === true) {
					$base64Content = $this->getFileAsBase64(fileId: $fileId);
					if ($base64Content !== null) {
						$hydratedFiles[] = $base64Content;
					}

					continue;
				}

				$fileObject = $this->getFileObject(fileId: $fileId);
				if ($fileObject !== null) {
					$hydratedFiles[] = $fileObject;
				}
			}

			return $hydratedFiles;
		}//end if

		// Handle single file.
		$isDigitString = is_string($propertyValue) === true && ctype_digit($propertyValue) === true;
		if (is_numeric($propertyValue) === true || $isDigitString === true) {
			if ($returnBase64 === true) {
				return $this->getFileAsBase64(fileId: $propertyValue);
			}

			return $this->getFileObject(fileId: $propertyValue);
		}

		return $propertyValue;
		// Return unchanged if not a file ID.
	}//end hydrateFileProperty()

	/**
	 * Gets a file's content as a base64 data URI string.
	 *
	 * @param mixed $fileId The file ID to retrieve.
	 *
	 * @return string|null The base64 data URI or null if file not found.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getFileAsBase64($fileId): ?string {
		try {
			// Convert to int.
			$fileIdInt = (int)$fileId;
			if ($fileIdInt <= 0) {
				return null;
			}

			// Get the file using FileService.
			$file = $this->fileService->getFileById($fileIdInt);
			if ($file === null) {
				return null;
			}

			// PERF-11: cap the size of files we are willing to base64-embed even on the
			// single-object path, so a single oversized attachment cannot exhaust memory.
			// Oversized files return null (the caller still has the file reference via the
			// non-base64 path / @self.files).
			$maxBase64Bytes = (10 * 1024 * 1024);
			try {
				$fileSize = (int)$file->getSize();
				if ($fileSize > $maxBase64Bytes) {
					$this->logger->warning(
						message: '[RenderObject] Refusing to base64-embed oversized file',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'fileId' => $fileIdInt,
							'size' => $fileSize,
							'cap' => $maxBase64Bytes,
						]
					);
					return null;
				}
			} catch (\Throwable $sizeError) {
				// If size can't be determined, fall through and let getContent() decide.
				$this->logger->debug(
					'[RenderObject] Could not determine file size for base64 cap: ' . $sizeError->getMessage()
				);
			}//end try

			// Get file content.
			$fileContent = $file->getContent();
			if ($fileContent === null || $fileContent === '') {
				return null;
			}

			// Get MIME type.
			$mimeType = $file->getMimeType() ?? 'application/octet-stream';

			// Return as data URI.
			return 'data:' . $mimeType . ';base64,' . base64_encode($fileContent);
		} catch (Exception $e) {
			return null;
		}//end try
	}//end getFileAsBase64()

	/**
	 * Hydrates metadata (@self) from file properties after they've been converted to file objects.
	 *
	 * This method extracts metadata like image URLs from file properties that have been
	 * hydrated with accessUrl, downloadUrl, etc.
	 *
	 * @param ObjectEntity $entity The entity to hydrate metadata for.
	 *
	 * @return ObjectEntity The entity with hydrated metadata.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Metadata extraction requires multiple conditional checks
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function hydrateMetadataFromFileProperties(ObjectEntity $entity): ObjectEntity {
		try {
			// Get the schema for this object to understand property configurations.
			$schema = $this->getSchema(id: $entity->getSchema());
			if ($schema === null) {
				return $entity;
			}

			$config = $schema->getConfiguration();
			$objectData = $entity->getObject();

			// Check if objectImageField is configured.
			if (empty($config['objectImageField']) === false) {
				$imageField = $config['objectImageField'];

				// Get the value from the configured field.
				$value = $this->getValueFromPath(data: $objectData, path: $imageField);

				// Check if the value is a file object (has downloadUrl or accessUrl).
				$hasNoDownloadUrl = ($value['downloadUrl'] ?? null) === null;
				$hasNoAccessUrl = ($value['accessUrl'] ?? null) === null;
				if (is_array($value) === false || ($hasNoDownloadUrl === true && $hasNoAccessUrl === true)) {
					// If the file property is null/empty, set image to null.
					$entity->setImage(null);
				}

				if (is_array($value) === true && ($hasNoDownloadUrl === false || $hasNoAccessUrl === false)) {
					// Set the image URL on the entity itself (not in object data).
					// This will be serialized to @self.image in jsonSerialize().
					// Prefer downloadUrl, fallback to accessUrl.
					$entity->setImage($value['downloadUrl'] ?? $value['accessUrl']);
				}
			}//end if
		} catch (\Exception $e) {
			// Log error but don't break rendering - just return original entity.
		}//end try

		return $entity;
	}//end hydrateMetadataFromFileProperties()

	/**
	 * Helper method to get a value from a nested path in an array.
	 *
	 * @param array $data The data array.
	 * @param string $path The path (e.g., 'logo' or 'nested.field').
	 *
	 * @return mixed|null The value at the path or null if not found.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getValueFromPath(array $data, string $path) {
		$keys = explode('.', $path);
		$value = $data;

		foreach ($keys as $key) {
			if (is_array($value) === false || (($value[$key] ?? null) === null)) {
				return null;
			}

			$value = $value[$key];
		}

		return $value;
	}//end getValueFromPath()

	/**
	 * Gets a file object by its ID using the FileService.
	 *
	 * @param mixed $fileId The file ID to retrieve.
	 *
	 * @psalm-param mixed $fileId
	 *
	 * @phpstan-param mixed $fileId
	 *
	 * @return (int|null|string[])[]|null
	 *
	 * @psalm-return   array{id: numeric-string, path: string, title: string,
	 *     accessUrl: null|string, downloadUrl: null|string, type: string,
	 *     extension: string, size: int, hash: string, published: null|string,
	 *     modified: int|null, labels: list<string>}|null
	 * @phpstan-return array<string, mixed>|null
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getFileObject($fileId): ?array {
		try {
			// Convert to string/int as needed.
			$fileIdStr = $fileId;
			if (is_numeric($fileId) === true) {
				$fileIdStr = (string)$fileId;
			}

			if (is_string($fileIdStr) === false && is_int($fileIdStr) === false) {
				return null;
			}

			$cacheKey = (string)$fileIdStr;

			// The batch cache is the same for every caller; the restriction is not.
			if ($this->withoutRestrictedFiles(fileRecords: [['fileid' => $cacheKey]]) === []) {
				return null;
			}

			// PERF-1: serve from the request-scoped batch cache when present.
			if (array_key_exists($cacheKey, $this->fileObjectCache) === true) {
				return $this->fileObjectCache[$cacheKey];
			}

			// Use FileMapper to get file information directly.
			$fileRecord = $this->fileMapper->getFile((int)$fileIdStr);

			if (empty($fileRecord) === true) {
				$this->fileObjectCache[$cacheKey] = null;
				return null;
			}

			$formatted = $this->formatFileRecord(fileRecord: $fileRecord);

			// PERF-1: back-fill the request cache so repeated refs reuse it.
			$this->fileObjectCache[$cacheKey] = $formatted;

			return $formatted;
		} catch (Exception $e) {
			return null;
		}//end try
	}//end getFileObject()

	/**
	 * The file records without the restricted files the caller is not admitted to.
	 *
	 * Without a container (legacy fixtures) nothing is filtered.
	 *
	 * @param array<int, array<string, mixed>> $fileRecords FileMapper records carrying `fileid`.
	 *
	 * @return array<int, array<string, mixed>> The readable records.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
	 */
	private function withoutRestrictedFiles(array $fileRecords): array {
		if ($this->container === null || $fileRecords === []) {
			return $fileRecords;
		}

		$verdicts = $this->container->get(FileRestrictionGuard::class)->readable(
			fileIds: array_map(static fn (array $record): int => (int)($record['fileid'] ?? 0), $fileRecords)
		);

		return array_values(
			array_filter(
				$fileRecords,
				static fn (array $record): bool => ($verdicts[(int)($record['fileid'] ?? 0)] ?? false) === true
			)
		);
	}//end withoutRestrictedFiles()

	/**
	 * Format a raw FileMapper file record into the rendered file object shape (PERF-1).
	 *
	 * Shared by getFileObject() and the page-level batch prefetch so both paths emit
	 * an identical structure.
	 *
	 * @param array $fileRecord The raw file record from FileMapper
	 *
	 * @return array The formatted file object (same structure as renderFiles()).
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function formatFileRecord(array $fileRecord): array {
		// Get file tags (request-cached).
		$labels = $this->getFileTags(fileId: (string)$fileRecord['fileid']);

		// Format the file object (same structure as renderFiles method).
		return [
			'id' => (string)$fileRecord['fileid'],
			'path' => $fileRecord['path'],
			'title' => $fileRecord['name'],
			'accessUrl' => $fileRecord['accessUrl'] ?? null,
			'downloadUrl' => $fileRecord['downloadUrl'] ?? null,
			'type' => $fileRecord['mimetype'] ?? 'application/octet-stream',
			'extension' => pathinfo($fileRecord['name'], PATHINFO_EXTENSION),
			'size' => (int)$fileRecord['size'],
			'hash' => $fileRecord['etag'] ?? '',
			'published' => $fileRecord['published'] ?? null,
			'modified' => $fileRecord['mtime'] ?? null,
			'labels' => $labels,
		];
	}//end formatFileRecord()

	/**
	 * Renders an entity with optional extensions and filters.
	 *
	 * This method takes an ObjectEntity and applies extensions and filters to it.
	 * It maintains the object's structure while allowing for property extension
	 * and filtering based on the provided parameters. Additionally, it accepts
	 * preloaded registers, schemas, and objects to enhance rendering performance.
	 *
	 * @param ObjectEntity $entity The entity to render
	 * @param array|string|null $_extend Properties to extend the entity with
	 * @param int $depth The depth level for nested rendering
	 * @param array|null $filter Filters to apply to the rendered entity
	 * @param array|null $fields Specific fields to include in the output
	 * @param array|null $unset Properties to remove from the rendered entity
	 * @param array|null $registers Preloaded registers to use
	 * @param array|null $schemas Preloaded schemas to use
	 * @param array|null $objects Preloaded objects to use
	 * @param array|null $visitedIds All ids we already handled
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 * @return ObjectEntity The rendered entity with applied extensions and filters
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible rendering options
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    RBAC and multitenancy flags control security behavior
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function renderEntity(
		ObjectEntity $entity,
		array|string|null $_extend = [],
		int $depth = 0,
		?array $filter = [],
		?array $fields = [],
		?array $unset = [],
		?array $registers = [],
		?array $schemas = [],
		?array $objects = [],
		?array $visitedIds = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ObjectEntity {
		if (self::isVisited(entity: $entity, visitedIds: $visitedIds) === true) {
			// @psalm-suppress NullableReturnStatement - setObject() returns $this (ObjectEntity) despite void annotation
			$entity->setObject(['@circular' => true, 'id' => $entity->getUuid()]);
			return $entity;
		}

		$this->primeRenderCaches(registers: $registers, schemas: $schemas, objects: $objects);

		$context = new RenderContext(
			$_extend, $depth, $filter, self::withIdentityFields(fields: $fields), $unset,
			$registers, $schemas, $objects, self::withVisitedId(entity: $entity, visitedIds: $visitedIds), $_rbac
		);

		return $this->renderWithContext(entity: $entity, context: $context);
	}//end renderEntity()

	/**
	 * Render an entity once its options are gathered in a RenderContext.
	 *
	 * Caller-controlled projection (`fields`, `filter`, `unset`) runs before the
	 * write-only redaction, so none of it can widen what is redacted.
	 *
	 * @param ObjectEntity  $entity  The entity to render
	 * @param RenderContext $context The render options
	 *
	 * @return ObjectEntity The rendered entity
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function renderWithContext(ObjectEntity $entity, RenderContext $context): ObjectEntity {
		$entity = $this->hydrateEntityFiles(entity: $entity, extend: $context->extend);

		// The object before any caller-supplied projection. The lifecycle state's
		// field rules are resolved against this, so `?fields=onderwerp` cannot change
		// which rules apply by hiding the lifecycle field from the resolver.
		$unprojectedData = $entity->getObject();

		$objectData = $this->applyFieldSelection(entity: $entity, objectData: $unprojectedData, fields: $context->fields);
		if (self::matchesFilter(objectData: $objectData, filter: $context->filter) === false) {
			$entity->setObject([]);
			return $entity;
		}

		$objectData = $this->applyUnset(entity: $entity, objectData: $objectData, unset: $context->unset);
		$objectData = $this->redactWriteOnlyForRbac(entity: $entity, objectData: $objectData, rbac: $context->rbac);
		$objectData = $this->applyInversedProperties(entity: $entity, objectData: $objectData, context: $context);

		$context    = $context->withExtend(self::normalizeExtend(extend: $context->extend, objectData: $objectData));
		$objectData = $this->applyExtensions(entity: $entity, objectData: $objectData, context: $context);
		$this->enrichLinkedEntities(entity: $entity, extend: $context->extend);

		return $this->finishRender(entity: $entity, objectData: $objectData, unprojectedData: $unprojectedData, context: $context);
	}//end renderWithContext()

	/**
	 * Apply the read boundary, the presentation steps and the render markers.
	 *
	 * @param ObjectEntity  $entity          The entity being rendered
	 * @param array         $objectData      The object data after projection and extension
	 * @param array         $unprojectedData The object data before caller projection
	 * @param RenderContext $context         The render options
	 *
	 * @return ObjectEntity The rendered entity
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function finishRender(
		ObjectEntity $entity,
		array $objectData,
		array $unprojectedData,
		RenderContext $context
	): ObjectEntity {
		$readSchema = $this->getSchema(id: $entity->getSchema());
		$objectData = $this->applyReadTimeValues(entity: $entity, schema: $readSchema, objectData: $objectData);

		$schema     = $readSchema ?? $this->getSchema(id: $entity->getSchema());
		$objectData = $this->stripUnreadableProperties(entity: $entity, schema: $schema, objectData: $objectData, rbac: $context->rbac);

		// Publish the lifecycle state's field rules beside the object. It runs after
		// the strip because the two must agree: what was just removed is what
		// `hidden` names. StateFieldRuleListener still enforces them on save.
		if ($schema !== null) {
			$this->attachFieldRules(entity: $entity, schema: $schema, stored: $unprojectedData);
		}

		$objectData = $this->decryptRenderedProperties(schema: $schema, objectData: $objectData);

		// Explicit-reveal values leave masked on every read, admins and `_rbac: false` included,
		// after decryption so the mask is of the value and not of its ciphertext.
		$objectData = $this->maskRenderedReveals(entity: $entity, schema: $schema, objectData: $objectData);

		$renderSchema = $this->getSchema(id: $entity->getSchema());
		$objectData   = $this->resolveRenderTranslations(entity: $entity, schema: $renderSchema, objectData: $objectData);
		$objectData   = $this->applyRequestedCalculations(
			entity: $entity,
			schema: $renderSchema,
			objectData: $objectData,
			extend: $context->extend
		);
		$objectData   = $this->applyLens(schema: $renderSchema, objectData: $objectData, rbac: $context->rbac);

		$entity->setObject($objectData);
		$this->applyRenderMarkers(entity: $entity, schema: $renderSchema, extend: $context->extend);

		return $entity;
	}//end finishRender()

	/**
	 * Add preloaded registers, schemas and objects to the render caches.
	 *
	 * @param array|null $registers Preloaded registers keyed by id
	 * @param array|null $schemas   Preloaded schemas keyed by id
	 * @param array|null $objects   Preloaded objects keyed by id
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function primeRenderCaches(?array $registers, ?array $schemas, ?array $objects): void {
		foreach ($registers ?? [] as $id => $register) {
			$this->registersCache[$id] = $register;
		}

		foreach ($schemas ?? [] as $id => $schema) {
			$this->schemasCache[$id] = $schema;
		}

		foreach ($objects ?? [] as $id => $object) {
			$this->objectsCache[$id] = $object;
		}
	}//end primeRenderCaches()

	/**
	 * Whether this entity was already rendered on the current path.
	 *
	 * @param ObjectEntity $entity     The entity being rendered
	 * @param array|null   $visitedIds The uuids already rendered on this path
	 *
	 * @return bool
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function isVisited(ObjectEntity $entity, ?array $visitedIds): bool {
		return $entity->getUuid() !== null && in_array($entity->getUuid(), $visitedIds ?? [], true) === true;
	}//end isVisited()

	/**
	 * The visited ids with this entity's uuid added, when it has one.
	 *
	 * @param ObjectEntity $entity     The entity being rendered
	 * @param array|null   $visitedIds The uuids already rendered on this path
	 *
	 * @return array|null
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function withVisitedId(ObjectEntity $entity, ?array $visitedIds): ?array {
		if ($entity->getUuid() !== null) {
			$visitedIds[] = $entity->getUuid();
		}

		return $visitedIds;
	}//end withVisitedId()

	/**
	 * The requested fields plus `@self` and `id`, which a selection always keeps.
	 *
	 * @param array|null $fields The requested fields
	 *
	 * @return array|null The fields unchanged when none were requested
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function withIdentityFields(?array $fields): ?array {
		if (empty($fields) === true) {
			return $fields;
		}

		$fields[] = '@self';
		$fields[] = 'id';

		return $fields;
	}//end withIdentityFields()

	/**
	 * Attach file data to the entity.
	 *
	 * `@self.files` is opt-in via _extend (capability files-render-extension): by
	 * default the entity carries a lightweight list of file ids, and
	 * `_extend[]=@self.files`, `_extend[]=_files` or `_extend[]=all` give full
	 * file metadata. File properties and the metadata derived from them are
	 * hydrated either way.
	 *
	 * @param ObjectEntity      $entity The entity to hydrate
	 * @param array|string|null $extend The extend parameter
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function hydrateEntityFiles(ObjectEntity $entity, array|string|null $extend): ObjectEntity {
		if (self::shouldExtendFiles(extend: $extend) === true) {
			$entity = $this->renderFiles(object: $entity);
		}

		if (self::shouldExtendFiles(extend: $extend) === false) {
			$entity = $this->setLightweightFileIds(entity: $entity);
		}

		$entity = $this->renderFileProperties(entity: $entity);

		return $this->hydrateMetadataFromFileProperties(entity: $entity);
	}//end hydrateEntityFiles()

	/**
	 * Keep only the requested fields that hold a non-null value.
	 *
	 * @param ObjectEntity $entity     The entity being rendered
	 * @param array        $objectData The object data
	 * @param array|null   $fields     The requested fields, `@self` and `id` included
	 *
	 * @return array The selected data, or the data unchanged when no fields were requested
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyFieldSelection(ObjectEntity $entity, array $objectData, ?array $fields): array {
		if (empty($fields) === true) {
			return $objectData;
		}

		$filteredData = [];
		foreach ($fields as $field) {
			if (($objectData[$field] ?? null) !== null) {
				$filteredData[$field] = $objectData[$field];
			}
		}

		$entity->setObject($filteredData);

		return $filteredData;
	}//end applyFieldSelection()

	/**
	 * Whether the object matches every filter it holds a non-null value for.
	 *
	 * @param array      $objectData The object data
	 * @param array|null $filter     Property values the object must match
	 *
	 * @return bool
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function matchesFilter(array $objectData, ?array $filter): bool {
		foreach ($filter ?? [] as $key => $value) {
			if (($objectData[$key] ?? null) !== null && $objectData[$key] !== $value) {
				return false;
			}
		}

		return true;
	}//end matchesFilter()

	/**
	 * Remove the named properties from the response.
	 *
	 * @param ObjectEntity $entity     The entity being rendered
	 * @param array        $objectData The object data
	 * @param array|null   $unset      The properties to remove
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyUnset(ObjectEntity $entity, array $objectData, ?array $unset): array {
		if (empty($unset) === true) {
			return $objectData;
		}

		foreach ($unset as $property) {
			if (($objectData[$property] ?? null) !== null) {
				unset($objectData[$property]);
			}
		}

		$entity->setObject($objectData);

		return $objectData;
	}//end applyUnset()

	/**
	 * Redact write-only properties on an access-controlled read (openregister#380, ocon#147).
	 *
	 * Runs after every caller-controlled projection (`fields`, `filter`, `unset`)
	 * so none of them can widen it. `writeOnly` is JSON Schema's word for "may be
	 * sent by the client, never returned by the server"; a property is affected only
	 * once its schema marks it so.
	 *
	 * Gated on `$rbac`: a `_rbac: false` caller is trusted internal code (the
	 * synchronisation engine, the credential engine, configuration import/export)
	 * that needs the full object. The `relations` search-index copy, surfaced as
	 * `@self.relations`, is redacted too, because it mirrors every scalar property.
	 *
	 * @param ObjectEntity $entity     The entity being rendered
	 * @param array        $objectData The object data
	 * @param bool         $rbac       Whether access control applies
	 *
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The rbac flag is the trust boundary this method enforces
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function redactWriteOnlyForRbac(ObjectEntity $entity, array $objectData, bool $rbac): array {
		if ($rbac !== true || empty($objectData) === true) {
			return $objectData;
		}

		$writeOnly = $this->getWriteOnlyProperties(schemaId: $entity->getSchema());
		if (empty($writeOnly) === true) {
			return $objectData;
		}

		$redacted = self::withoutKeys(data: $objectData, keys: $writeOnly);
		if ($redacted !== $objectData) {
			$entity->setObject($redacted);
		}

		$relations = $entity->getRelations();
		if (is_array($relations) === true && empty($relations) === false) {
			$redactedRelations = self::withoutKeys(data: $relations, keys: $writeOnly);
			if ($redactedRelations !== $relations) {
				$entity->setRelations($redactedRelations);
			}
		}

		return $redacted;
	}//end redactWriteOnlyForRbac()

	/**
	 * The data without the given keys.
	 *
	 * @param array $data The data
	 * @param array $keys The keys to remove
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function withoutKeys(array $data, array $keys): array {
		foreach ($keys as $key) {
			if (array_key_exists($key, $data) === true) {
				unset($data[$key]);
			}
		}

		return $data;
	}//end withoutKeys()

	/**
	 * Populate inversed properties, only when an inverse property is extended.
	 *
	 * Inverse lookups search all magic tables, so they run only on request.
	 *
	 * @param ObjectEntity  $entity     The entity being rendered
	 * @param array         $objectData The object data
	 * @param RenderContext $context    The render options
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyInversedProperties(ObjectEntity $entity, array $objectData, RenderContext $context): array {
		if ($context->depth >= 10 || empty($context->extend) === true) {
			return $objectData;
		}

		if ($this->extendsInverseProperty(entity: $entity, extend: $context->extend) === false) {
			return $objectData;
		}

		return $this->handleInversedProperties(
			entity: $entity,
			objectData: $objectData,
			_depth: $context->depth,
			_filter: $context->filter,
			_fields: $context->fields,
			_unset: $context->unset,
			_registers: $context->registers,
			_schemas: $context->schemas,
			_objects: $context->objects
		);
	}//end applyInversedProperties()

	/**
	 * Whether the extend list names one of the schema's inversed properties, or `all`.
	 *
	 * @param ObjectEntity $entity The entity being rendered
	 * @param array|string $extend The extend list, as an array or a comma-separated string
	 *
	 * @return bool False when the schema cannot be found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendsInverseProperty(ObjectEntity $entity, array|string $extend): bool {
		$schema = $this->getSchema(id: $entity->getSchema());
		if ($schema === null) {
			return false;
		}

		$inversePropertyNames = array_keys($this->getInversedProperties(schema: $schema));

		$extendArray = $extend;
		if (is_array($extend) === false) {
			$extendArray = explode(',', (string) $extend);
		}

		return in_array('all', $extendArray, true) === true
			|| array_intersect($inversePropertyNames, $extendArray) !== [];
	}//end extendsInverseProperty()

	/**
	 * Normalise the extend parameter to the list extendObject() expects.
	 *
	 * A comma-separated string becomes an array, `all` (in an array) expands to
	 * every property whose value is not the object's own id or origin id, and the
	 * shorthands `_schema`, `_register` and `_files` become their `@self` paths.
	 *
	 * @param array|string|null $extend     The extend parameter
	 * @param array             $objectData The object data
	 *
	 * @return array|null
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function normalizeExtend(array|string|null $extend, array $objectData): ?array {
		if (is_array($extend) === true && in_array('all', $extend, true) === true) {
			$extend = self::expandExtendAll(extend: $extend, objectData: $objectData);
		} elseif (is_string($extend) === true) {
			$extend = explode(',', $extend);
		}

		if (is_array($extend) === false) {
			return $extend;
		}

		$normalizeMap = [
			'_schema'   => '@self.schema',
			'_register' => '@self.register',
			'_files'    => '@self.files',
		];
		foreach ($normalizeMap as $shorthand => $full) {
			$key = array_search($shorthand, $extend, true);
			if ($key !== false) {
				$extend[$key] = $full;
			}
		}

		return $extend;
	}//end normalizeExtend()

	/**
	 * Add every property whose value is not the object's id or origin id to the extend list.
	 *
	 * @param array $extend     The extend list containing `all`
	 * @param array $objectData The object data
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function expandExtendAll(array $extend, array $objectData): array {
		$id       = $objectData['id'] ?? null;
		$originId = $objectData['originId'] ?? null;

		foreach ($objectData as $key => $value) {
			if (in_array($key, ['id', 'originId'], true) === true) {
				continue;
			}

			if ($value !== $id && $value !== $originId) {
				$extend[] = $key;
			}
		}

		return $extend;
	}//end expandExtendAll()

	/**
	 * Extend the object's references while the depth limit is not reached.
	 *
	 * @param ObjectEntity  $entity     The entity being rendered
	 * @param array         $objectData The object data
	 * @param RenderContext $context    The render options, with a normalised extend list
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyExtensions(ObjectEntity $entity, array $objectData, RenderContext $context): array {
		if (empty($context->extend) === true || $context->depth >= 10) {
			return $objectData;
		}

		return $this->extendObject(
			entity: $entity,
			_extend: $context->extend,
			objectData: $objectData,
			depth: $context->depth,
			_filter: $context->filter,
			_fields: $context->fields,
			_unset: $context->unset,
			visitedIds: $context->visitedIds
		);
	}//end applyExtensions()

	/**
	 * Enrich linked entity metadata columns requested via _extend (e.g. `_extend[]=_mail`).
	 *
	 * @param ObjectEntity      $entity The entity being rendered
	 * @param array|string|null $extend The normalised extend list
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function enrichLinkedEntities(ObjectEntity $entity, array|string|null $extend): void {
		if (is_array($extend) === false) {
			return;
		}

		$linkedExtend = array_intersect_key(
			array_flip($extend),
			array_flip(['_mail', '_contacts', '_notes', '_todos', '_calendar', '_talk', '_deck'])
		);
		if (empty($linkedExtend) === true) {
			return;
		}

		$enriched = $this->linkedEntityEnricher->enrich($entity->jsonSerialize(), $linkedExtend);
		foreach (array_keys($linkedExtend) as $key) {
			if (isset($enriched[$key]) === true) {
				$setter = 'set'.ucfirst(ltrim($key, '_'));
				$entity->$setter($enriched[$key]);
			}
		}
	}//end enrichLinkedEntities()

	/**
	 * Evaluate read-time computed fields and enrich recurrence properties.
	 *
	 * Computed fields with `evaluateOn: 'read'` are calculated here and never
	 * stored. Recurrence properties get their upcoming occurrences under
	 * `_occurrences`; the RRULE string itself is left unchanged.
	 *
	 * @param ObjectEntity $entity     The entity being rendered
	 * @param Schema|null  $schema     The object's schema
	 * @param array        $objectData The object data
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyReadTimeValues(ObjectEntity $entity, ?Schema $schema, array $objectData): array {
		if ($schema === null) {
			return $objectData;
		}

		if ($this->computedFieldHandler->hasComputedProperties($schema) === true) {
			$objectData = $this->computedFieldHandler->evaluateComputedFields(
				data: $objectData,
				schema: $schema,
				evaluateOn: 'read'
			);
			$entity->setObject($objectData);
		}

		$objectData = $this->enrichRecurrenceOccurrences(objectData: $objectData, schema: $schema);
		$entity->setObject($objectData);

		return $objectData;
	}//end applyReadTimeValues()

	/**
	 * Strip the properties the reader must not see: writeOnly and property `authorization.read`.
	 *
	 * Runs after the caller-supplied `fields`/`unset` selection, so a caller can
	 * never re-surface a stripped property by naming it (e.g. `?fields=apiToken`).
	 *
	 * writeOnly stripping, nested write-only paths included, is a hard
	 * render-boundary rule and is not rbac-gated: an admin HTTP read renders with
	 * `_rbac: false`, so gating it leaked plaintext secrets (#389). Code that
	 * needs a raw secret reads ObjectEntity::getObject() from the mapper.
	 *
	 * Property `authorization.read` stripping is rbac-gated: it is bypassed for
	 * `_rbac: false` reads and during a SystemOperationContext, mirroring
	 * PermissionHandler::hasPermission().
	 *
	 * @param ObjectEntity $entity     The entity being rendered
	 * @param Schema|null  $schema     The object's schema
	 * @param array        $objectData The object data
	 * @param bool         $rbac       Whether access control applies
	 *
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The rbac flag gates the authorization strip
	 * @SuppressWarnings(PHPMD.StaticAccess)        SystemOperationContext is a static execution-context holder by design
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function stripUnreadableProperties(ObjectEntity $entity, ?Schema $schema, array $objectData, bool $rbac): array {
		$stripAuthz  = ($rbac !== false) && (SystemOperationContext::isActive() === false);
		$doWriteOnly = $this->schemaHasWriteOnlyRule(schema: $schema);
		$doAuthz     = ($stripAuthz === true && $schema !== null && $schema->hasPropertyAuthorization() === true);
		if ($doWriteOnly === false && $doAuthz === false) {
			return $objectData;
		}

		if ($doAuthz === true) {
			$objectData = $this->filterAuthorizedProperties(entity: $entity, schema: $schema, objectData: $objectData);
		} else {
			$objectData = $this->propertyRbacHandler->stripWriteOnlyProperties(schema: $schema, object: $objectData);
		}

		if ($doWriteOnly === true) {
			$this->stripWriteOnlyRelationsMirror(entity: $entity, schema: $schema);
		}

		return $objectData;
	}//end stripUnreadableProperties()

	/**
	 * Strip the properties the reader's groups may not read, writeOnly included.
	 *
	 * Property authorization can reference `@self.organisation` or `_organisation`,
	 * so a temporary `@self` is supplied when the data has none and removed again
	 * afterwards (jsonSerialize re-adds it).
	 *
	 * @param ObjectEntity $entity     The entity being rendered
	 * @param Schema       $schema     The object's schema
	 * @param array        $objectData The object data
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function filterAuthorizedProperties(ObjectEntity $entity, Schema $schema, array $objectData): array {
		$objectDataWithSelf = $objectData;
		if (isset($objectDataWithSelf['@self']) === false) {
			$objectDataWithSelf['@self'] = [
				'organisation' => $entity->getOrganisation(),
				'owner'        => $entity->getOwner(),
			];
		}

		$objectData = $this->propertyRbacHandler->filterReadableProperties(schema: $schema, object: $objectDataWithSelf);
		$this->withholdEntityCopies(
			entity: $entity,
			schema: $schema,
			removed: $this->removedProperties(before: $objectDataWithSelf, after: $objectData)
		);

		if (isset($objectData['@self']) === true
			&& count($objectData['@self']) <= 2
			&& isset($objectData['@self']['organisation']) === true
		) {
			unset($objectData['@self']);
		}

		return $objectData;
	}//end filterAuthorizedProperties()

	/**
	 * Strip writeOnly values from the `relations` search-index copy.
	 *
	 * Reference-shaped scalar values are mirrored into `relations`, surfaced as
	 * `@self.relations`, so the body strip alone would still leak them. This runs
	 * on the same unconditional boundary as the body strip (#420, the mirror half of #389).
	 *
	 * @param ObjectEntity $entity The entity being rendered
	 * @param Schema|null  $schema The object's schema
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function stripWriteOnlyRelationsMirror(ObjectEntity $entity, ?Schema $schema): void {
		$relations = $entity->getRelations();
		if (is_array($relations) === false || empty($relations) === true) {
			return;
		}

		$entity->setRelations(
			$this->propertyRbacHandler->stripWriteOnlyProperties(schema: $schema, object: $relations)
		);
	}//end stripWriteOnlyRelationsMirror()

	/**
	 * Decrypt properties flagged `x-openregister-encrypted: true`.
	 *
	 * Runs after the read strip and nowhere earlier: a stripped property is no
	 * longer a key, so decryptProperties() skips it and an unauthorized reader
	 * never reaches decryption. A value that is not an envelope passes through;
	 * one that fails to decrypt becomes an error marker (see FieldEncryptionHandler).
	 *
	 * @param Schema|null $schema     The object's schema
	 * @param array       $objectData The object data
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function decryptRenderedProperties(?Schema $schema, array $objectData): array {
		if ($this->fieldEncryptionHandler === null || $schema === null || $schema->hasEncryptedProperties() === false) {
			return $objectData;
		}

		return $this->fieldEncryptionHandler->decryptProperties(data: $objectData, schema: $schema);
	}//end decryptRenderedProperties()

	/**
	 * Resolve translatable properties to the requested language.
	 *
	 * With `?_translationMeta=true` a `_meta.languageMeta` envelope describes each
	 * translatable property's served and source language and workflow status;
	 * it is opt-in to keep the payload flat by default.
	 *
	 * @param ObjectEntity $entity     The entity being rendered
	 * @param Schema|null  $schema     The object's schema
	 * @param array        $objectData The object data
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function resolveRenderTranslations(ObjectEntity $entity, ?Schema $schema, array $objectData): array {
		$register       = $this->getRegister(id: $entity->getRegister());
		$preResolveData = $objectData;
		if ($schema === null) {
			return $objectData;
		}

		$objectData = $this->translationHandler->resolveTranslationsForRender(
			objectData: $objectData,
			schema: $schema,
			register: $register
		);
		if ($this->shouldAttachLanguageMeta() === false) {
			return $objectData;
		}

		try {
			return $this->attachLanguageMeta(
				objectData: $objectData,
				preResolveData: $preResolveData,
				entity: $entity,
				schema: $schema,
				register: $register
			);
		} catch (\Throwable $e) {
			$this->logRenderFailure(failure: 'languageMeta attach failed for', entity: $entity, error: $e);
		}

		return $objectData;
	}//end resolveRenderTranslations()

	/**
	 * Evaluate virtual calculations (`materialise: false`) when `calculations` is extended.
	 *
	 * Materialised calculations are already in the data (CalculationOnSaveListener).
	 *
	 * @param ObjectEntity      $entity     The entity being rendered
	 * @param Schema|null       $schema     The object's schema
	 * @param array             $objectData The object data
	 * @param array|string|null $extend     The normalised extend list
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyRequestedCalculations(
		ObjectEntity $entity,
		?Schema $schema,
		array $objectData,
		array|string|null $extend
	): array {
		$extendArr = [$extend];
		if (is_array($extend) === true) {
			$extendArr = $extend;
		} elseif ($extend === null) {
			$extendArr = [];
		}

		if (in_array('calculations', $extendArr, true) === false) {
			return $objectData;
		}

		return $this->applyVirtualCalculations(entity: $entity, schema: $schema, data: $objectData);
	}//end applyRequestedCalculations()

	/**
	 * Apply the schema's lens, if a lens resolver is available.
	 *
	 * A lens is not behind `_extend`: a field that shows only when the caller
	 * asked for it is a field two readers disagree about. A schema declaring no
	 * lens gets its data back untouched.
	 *
	 * @param Schema|null $schema     The object's schema
	 * @param array       $objectData The object data
	 * @param bool        $rbac       Whether access control applies
	 *
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The lens resolver takes the rbac flag
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyLens(?Schema $schema, array $objectData, bool $rbac): array {
		if ($this->lensResolver === null) {
			return $objectData;
		}

		return $this->lensResolver->apply(schema: $schema, data: $objectData, _rbac: $rbac);
	}//end applyLens()

	/**
	 * Set the render-time `@self` metadata: URN, translation and registry state,
	 * the reader's markers, retention and external links.
	 *
	 * The archival steps run in order because each reads the previous one's output,
	 * and the external links run last so a template sees the values the reader sees.
	 *
	 * @param ObjectEntity      $entity The rendered entity
	 * @param Schema|null       $schema The object's schema
	 * @param array|string|null $extend The normalised extend list
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyRenderMarkers(ObjectEntity $entity, ?Schema $schema, array|string|null $extend): void {
		$this->applyUrn(entity: $entity);
		$this->applyTranslationCompleteness(entity: $entity, schema: $schema);
		$this->applyRegistryState(entity: $entity, schema: $schema);
		$this->applyWatcherMarkers(entity: $entity);
		$this->applyReadStateMarkers(entity: $entity);
		$this->applyUpdateRightMarker(entity: $entity, schema: $schema, extend: $extend);
		$this->applyArchivalRetentionBlock(entity: $entity, schema: $schema);
		$this->applyArchivalDecision(entity: $entity);
		$this->applyRetentionClocks(entity: $entity);
		$this->applyExternalLinks(entity: $entity, schema: $schema);
	}//end applyRenderMarkers()

	/**
	 * Set the object's RFC 8141 URN; a derivation failure never breaks rendering.
	 *
	 * @param ObjectEntity $entity The rendered entity
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyUrn(ObjectEntity $entity): void {
		try {
			$entity->setUrn($this->urnService->buildForObject($entity));
		} catch (\Throwable $e) {
			$this->logRenderFailure(failure: 'URN derivation failed for object', entity: $entity, error: $e);
		}
	}//end applyUrn()

	/**
	 * Set the per-language translation completeness, when the schema has translatable properties.
	 *
	 * @param ObjectEntity $entity The rendered entity
	 * @param Schema|null  $schema The object's schema
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyTranslationCompleteness(ObjectEntity $entity, ?Schema $schema): void {
		if ($schema === null || $entity->getUuid() === null) {
			return;
		}

		try {
			$completeness = $this->translationStatusService->completenessForObject((string) $entity->getUuid(), $schema);
			if (count($completeness) > 0) {
				$entity->setTranslationCompleteness($completeness);
			}
		} catch (\Throwable $e) {
			$this->logRenderFailure(failure: 'translation completeness lookup failed for', entity: $entity, error: $e);
		}
	}//end applyTranslationCompleteness()

	/**
	 * Set the registry subscription state, only for a schema declaring `x-openregister-registry`.
	 *
	 * @param ObjectEntity $entity The rendered entity
	 * @param Schema|null  $schema The object's schema
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyRegistryState(ObjectEntity $entity, ?Schema $schema): void {
		if ($this->container === null || $schema === null || $entity->getUuid() === null) {
			return;
		}

		try {
			$registrySubscriptions = $this->container->get(RegistrySubscriptionService::class);
			if ($registrySubscriptions->annotationFor(schema: $schema) === null) {
				return;
			}

			$registryState = $registrySubscriptions->stateFor((string) $entity->getUuid());
			if ($registryState !== null) {
				$entity->setRegistryState($registryState);
			}
		} catch (\Throwable $e) {
			$this->logRenderFailure(failure: 'registry subscription state lookup failed for', entity: $entity, error: $e);
		}
	}//end applyRegistryState()

	/**
	 * Log a render step that failed without failing the render.
	 *
	 * @param string       $failure What failed, phrased to precede the object uuid
	 * @param ObjectEntity $entity  The entity being rendered
	 * @param \Throwable   $error   The failure
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function logRenderFailure(string $failure, ObjectEntity $entity, \Throwable $error): void {
		$this->logger->debug(
			sprintf('[RenderObject] %s %s: %s', $failure, (string) $entity->getUuid(), $error->getMessage())
		);
	}//end logRenderFailure()

	/**
	 * Attach `@self.fieldRules` to a rendered object.
	 *
	 * The rules are resolved against the object as stored rather than against
	 * what survived the strip above: a `hidden` field is gone from the payload
	 * by this point, and resolving from the payload would make a rule whose
	 * condition reads a hidden value silently stop applying.
	 *
	 * An object whose schema declares no state rules gets no key at all, so a
	 * register that does not use them pays nothing and its responses do not
	 * change shape.
	 *
	 * The rules ride the transient `@self` mechanism rather than being written
	 * into the payload's own `@self` key, because `ObjectEntity::getObjectArray()`
	 * rebuilds that envelope from the entity and keeps only a whitelist from
	 * the payload: a key set on the array here would be dropped on serialisation,
	 * silently, which is the same shape of no-op this change exists to refuse.
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 * @param Schema $schema The entity's schema.
	 * @param array<string, mixed> $stored The object before any projection or strip.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function attachFieldRules(ObjectEntity $entity, Schema $schema, array $stored): void {
		try {
			$rules = $this->propertyRbacHandler->stateFieldRulesFor(schema: $schema, object: $stored);
		} catch (\Throwable $e) {
			// The hint is a courtesy on a read. Losing it must never cost the
			// object, and the save path refuses the same write either way.
			$this->logger->debug(
				sprintf(
					'[RenderObject] field rules could not be resolved for %s: %s',
					(string)$entity->getUuid(),
					$e->getMessage()
				)
			);
			return;
		}

		if ($rules->isEmpty() === true) {
			return;
		}

		$entity->setFieldRules($rules->jsonSerialize());
	}//end attachFieldRules()

	/**
	 * Attach `@self._clocks`: the AVG date and the Archiefwet date, each with
	 * its rule.
	 *
	 * Resolved through the container rather than the constructor for the same
	 * reason the watcher markers are: this class already takes twenty-two
	 * collaborators and a records-management read has no business widening
	 * that list further.
	 *
	 * Skipped entirely for an object that carries neither a processing
	 * activity nor an archiefactiedatum, so listing a register of ordinary
	 * objects costs nothing. Failures are logged and swallowed: a retention
	 * edge case must never take out object rendering.
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function applyRetentionClocks(ObjectEntity $entity): void {
		if ($this->container === null) {
			return;
		}

		$hasActivity = (($entity->getProcessingActivityId() ?? '') !== '');
		$hasActionDate = (($entity->getRetention() ?? [])['archiefactiedatum'] ?? null) !== null;
		if ($hasActivity === false && $hasActionDate === false) {
			return;
		}

		try {
			$clocks = $this->container->get(RetentionClockService::class);
			$entity->setRetentionClocks($clocks->clocksFor(object: $entity));
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf(
					'[RenderObject] retention clocks skipped for %s: %s',
					(string)$entity->getUuid(),
					$e->getMessage()
				)
			);
		}
	}//end applyRetentionClocks()

	/**
	 * Attach `@self.watching`, `@self.watchNotify` and, for an editor, `@self.watcherCount`.
	 *
	 * `@self.favourite` is no longer looked up: it is `watching` under its old
	 * name for one release (`merge-follow-and-favourites`), so the same one
	 * query answers both.
	 *
	 * Resolved through the container rather than the constructor so the render
	 * layer does not acquire a hard dependency on the subscription primitive:
	 * the same lazy posture the registry-subscription lookup above uses, and for
	 * the same reason, since WatcherService resolves permissions and would
	 * otherwise close a construction cycle.
	 *
	 * Failures are logged and swallowed: whether somebody follows an object is
	 * never worth failing the read of that object.
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	private function applyWatcherMarkers(ObjectEntity $entity): void {
		if ($this->container === null) {
			return;
		}

		$uuid = (string)$entity->getUuid();
		if ($uuid === '') {
			return;
		}

		try {
			$watchers = $this->container->get(WatcherService::class);

			// Anonymous reads get no marker at all: there is no "you" to answer
			// for, and a hard false would read as "you do not follow this",
			// which is a different claim.
			if ($watchers->callerUid() === null) {
				return;
			}

			$entity->setWatching(
				$watchers->isWatchedByCaller(objectUuid: $uuid),
				$watchers->notifyForCaller(objectUuid: $uuid)
			);

			// The count is a fact about the object's audience, so it is only
			// told to a reader who may edit the object.
			if ($watchers->maySeeWatchers(object: $entity) === true) {
				$entity->setWatcherCount($watchers->watcherCount(objectUuid: $uuid));
			}
		} catch (\Throwable $e) {
			// A subscription lookup must never take out object rendering.
			$this->logger->debug(
				sprintf('[RenderObject] watcher markers skipped for %s: %s', $uuid, $e->getMessage())
			);
		}//end try
	}//end applyWatcherMarkers()

	/**
	 * Attach `@self.unread` and the per-sub-resource badge counts.
	 *
	 * Resolved through the container rather than the constructor, for the same
	 * reason as `applyWatcherMarkers()` above: the render layer does not acquire
	 * a hard dependency on the read-state primitive, which resolves a session
	 * and would otherwise close a construction cycle.
	 *
	 * ONLY the marker is attached here, never the sub-resource badge counts.
	 * Counting a sub-resource means looking at the object's files and its dated
	 * arrays, so doing it on this path would pay that cost for every row of
	 * every list, which is exactly what the memoised marker exists to avoid. A
	 * list carries the marker; the single-object read in ObjectsController::show()
	 * adds the badges, because that is the only caller that can be sure it is
	 * rendering one object.
	 *
	 * Failures are logged and swallowed: whether you have seen an object is
	 * never worth failing the read of that object.
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	private function applyReadStateMarkers(ObjectEntity $entity): void {
		if ($this->container === null) {
			return;
		}

		$uuid = (string)$entity->getUuid();
		if ($uuid === '') {
			return;
		}

		try {
			$readState = $this->container->get(ReadStateService::class);

			// Anonymous reads get no marker at all: there is no "you" to answer
			// for, and a hard false would read as "you have seen this", which is
			// a different claim.
			if ($readState->callerUid() === null) {
				return;
			}

			$entity->setUnread($readState->isUnreadForCaller(objectUuid: $uuid));
		} catch (\Throwable $e) {
			// A read-state lookup must never take out object rendering.
			$this->logger->debug(
				sprintf('[RenderObject] read state markers skipped for %s: %s', $uuid, $e->getMessage())
			);
		}//end try
	}//end applyReadStateMarkers()

	/**
	 * Attach `@self.can.update`: whether the reader may update this object.
	 *
	 * Opt-in through `_extend[]=@self.can`, because it costs one permission
	 * evaluation per row and only the records list's in-place editor asks for
	 * it. The verdict is PermissionHandler's own (schema, owner and conditional
	 * rules), so the list never offers an edit the save would refuse on rights.
	 * Resolved through the container like the markers above, to keep the
	 * render layer free of a construction cycle. A failure leaves the marker
	 * out, which the list reads as "no editor": the safe side.
	 *
	 * @param ObjectEntity      $entity The entity being rendered.
	 * @param Schema|null       $schema The object's schema.
	 * @param array|string|null $extend The _extend parameter.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-req-rfce-002-a-cell-in-the-records-list-can-be-edited-in-place
	 */
	private function applyUpdateRightMarker(ObjectEntity $entity, ?Schema $schema, array|string|null $extend): void {
		if ($this->container === null || $schema === null || $extend === null || $extend === '' || $extend === []) {
			return;
		}

		if (is_string($extend) === true) {
			$extend = array_map('trim', explode(',', $extend));
		}

		if (in_array('@self.can', $extend, true) === false) {
			return;
		}

		try {
			$permissions = $this->container->get(PermissionHandler::class);
			$owner       = $entity->getOwner();
			if (is_string($owner) === false) {
				$owner = null;
			}

			$entity->setCan(
				[
					'update' => $permissions->hasPermission(
						schema: $schema,
						action: 'update',
						objectOwner: $owner,
						object: $entity
					),
				]
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[RenderObject] update right marker skipped for %s: %s', (string)$entity->getUuid(), $e->getMessage())
			);
		}//end try
	}//end applyUpdateRightMarker()

	/**
	 * Attach the resolved `@self._retention` decision.
	 *
	 * The slot has been declared on the entity since add-archival-annotation-support
	 * and, until this method existed, was filled by nothing outside a unit test —
	 * so every client asking an object for its archival constraints got silence
	 * while the facts sat unmerged in three sub-blocks of `retention`.
	 *
	 * Failures are logged and swallowed for the same reason the annotation block
	 * above swallows them: a records-management edge case must never take out
	 * object rendering.
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	private function applyArchivalDecision(ObjectEntity $entity): void {
		try {
			$resolver = new ArchivalDecisionResolver();
			$decision = $resolver->resolve(entity: $entity);
			if ($decision === null) {
				return;
			}

			$entity->setArchivalRetention($decision);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf(
					'[RenderObject] archival decision resolve failed for %s: %s',
					(string)$entity->getUuid(),
					$e->getMessage()
				)
			);
		}//end try
	}//end applyArchivalDecision()

	/**
	 * Attach the links out of this record, under `@self.externalLinks`.
	 *
	 * A link whose placeholders cannot all be filled is not offered, and a
	 * schema declaring no links attaches nothing at all rather than an empty
	 * list: a consumer that has to distinguish "no links declared" from "links
	 * declared, none applicable" can, and one that does not is not handed a key
	 * it must remember to ignore.
	 *
	 * Failure here is swallowed to a debug line for the same reason every other
	 * tail-of-render block swallows: a link is an ornament on a record, and a
	 * record the caseworker cannot read at all is a far worse outcome than a
	 * record missing its shortcut to the BAG viewer.
	 *
	 * @param ObjectEntity $entity The rendered object.
	 * @param Schema|null $schema Its schema, when one is resolvable.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-a-schema-declares-links-out-of-its-objects-req-avs-001
	 */
	private function applyExternalLinks(ObjectEntity $entity, ?Schema $schema): void {
		if ($schema === null) {
			return;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$declarations = ($configuration[ExternalLinkResolver::ANNOTATION] ?? null);
		if (is_array($declarations) === false || $declarations === []) {
			return;
		}

		try {
			$objectData = $entity->getObject();
			if (is_array($objectData) === false) {
				$objectData = [];
			}

			$links = (new ExternalLinkResolver())->resolve(
				declarations: $declarations,
				object: $objectData
			);

			if ($links === []) {
				return;
			}

			$objectData['@self'] = ($objectData['@self'] ?? []);
			if (is_array($objectData['@self']) === false) {
				$objectData['@self'] = [];
			}

			$objectData['@self']['externalLinks'] = $links;
			$entity->setObject($objectData);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf(
					'[RenderObject] external link resolution failed for %s: %s',
					(string)$entity->getUuid(),
					$e->getMessage()
				)
			);
		}//end try
	}//end applyExternalLinks()

	/**
	 * Compute + attach the annotation-driven `_retention.annotation` block.
	 *
	 * Stateless w.r.t. the row's persisted columns; pulls the annotation off
	 * the schema's `configuration` and asks `RetentionEvaluator` to produce
	 * the `{effectiveRetention, matchedRule, expiresAt}` triple for the
	 * current row + `_created` timestamp.
	 *
	 * Failures are logged + swallowed: a malformed annotation must NEVER
	 * break object rendering.
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 * @param Schema|null $schema The resolved schema (may be null).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private function applyArchivalRetentionBlock(ObjectEntity $entity, ?Schema $schema): void {
		if ($schema === null) {
			return;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-archival'] ?? null);
		if (is_array($annotation) === false) {
			return;
		}

		try {
			$createdAt = $entity->getCreated();
			if ($createdAt === null) {
				return;
			}

			$objectData = $entity->getObject();
			if (is_array($objectData) === false) {
				$objectData = [];
			}

			$row = $objectData;
			$evaluator = new RetentionEvaluator(logger: $this->logger);
			$annotationBlock = $evaluator->evaluate(
				annotation: $annotation,
				row: $row,
				createdAt: $createdAt
			);

			$existing = ($entity->getRetention() ?? []);
			$existing['annotation'] = $annotationBlock;
			$entity->setRetention($existing);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf(
					'[RenderObject] archival retention compute failed for %s: %s',
					(string)$entity->getUuid(),
					$e->getMessage()
				)
			);
		}//end try
	}//end applyArchivalRetentionBlock()

	/**
	 * Check whether the current request opts in to the `_translationMeta`
	 * envelope.
	 *
	 * @return bool True when `?_translationMeta=true`/`1` was passed.
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	private function shouldAttachLanguageMeta(): bool {
		if ($this->request === null) {
			return false;
		}

		$raw = $this->request->getParam('_translationMeta', null);
		if ($raw === null) {
			return false;
		}

		if (is_string($raw) === false) {
			return ($raw === true || $raw === 1);
		}

		$normalised = strtolower(trim($raw));
		return in_array($normalised, ['1', 'true', 'yes', 'on'], true);
	}//end shouldAttachLanguageMeta()

	/**
	 * Attach the `_meta.languageMeta` envelope describing per-property
	 * language metadata for translatable properties.
	 *
	 * The envelope is OFF by default; this method is only called when
	 * `?_translationMeta=true` is on the request.
	 *
	 * @param array<string, mixed> $objectData Rendered data (post resolveTranslationsForRender).
	 * @param array<string, mixed> $preResolveData Raw data (pre resolveTranslationsForRender).
	 * @param ObjectEntity $entity The entity being rendered.
	 * @param Schema $schema The schema for the entity.
	 * @param Register|null $register The owning register (for default fallback).
	 *
	 * @return array<string, mixed> Object data with `_meta.languageMeta` attached.
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	private function attachLanguageMeta(
		array $objectData,
		array $preResolveData,
		ObjectEntity $entity,
		Schema $schema,
		?Register $register,
	): array {
		$translatableProps = $this->translationHandler->getTranslatableProperties($schema);
		if (count($translatableProps) === 0) {
			return $objectData;
		}

		$uuid = (string)($entity->getUuid() ?? '');
		if ($register !== null) {
			$registerDefault = $register->getDefaultLanguage();
		} else {
			$registerDefault = 'nl';
		}

		$served = $this->languageService->getPreferredLanguage();

		$languageMeta = [];
		foreach ($translatableProps as $property) {
			// Determine source language from object body override → schema
			// property modifier → register default.
			$sourceLanguage = $this->resolveSourceLanguageForProperty(
				schema: $schema,
				preResolveData: $preResolveData,
				property: $property,
				registerDefault: $registerDefault
			);

			// Look up status from the sidecar for the served language.
			$status = 'source';
			if ($uuid !== '' && $served !== $sourceLanguage) {
				$row = null;
				try {
					$row = $this->translationMapper->findOne($uuid, $property, $served);
				} catch (\Throwable $e) {
					$row = null;
				}

				if ($row !== null) {
					$status = (string)($row->getStatus() ?? Translation::STATUS_DRAFT);
				} else {
					$status = 'missing';
				}
			}

			$languageMeta[$property] = [
				'served' => $served,
				'sourceLanguage' => $sourceLanguage,
				'isSource' => ($served === $sourceLanguage),
				'status' => $status,
			];
		}//end foreach

		if (count($languageMeta) === 0) {
			return $objectData;
		}

		$existingMeta = $objectData['_meta'] ?? [];
		if (is_array($existingMeta) === false) {
			$existingMeta = [];
		}

		$existingMeta['languageMeta'] = $languageMeta;
		$objectData['_meta'] = $existingMeta;
		return $objectData;
	}//end attachLanguageMeta()

	/**
	 * Resolve the source language for a single property from object override
	 * → schema modifier → register default.
	 *
	 * @param Schema $schema The schema for the entity.
	 * @param array<string, mixed> $preResolveData Raw object data with `_translationMeta` block intact.
	 * @param string $property The translatable property name.
	 * @param string $registerDefault Register-level default.
	 *
	 * @return string Resolved BCP-47 source language.
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	private function resolveSourceLanguageForProperty(
		Schema $schema,
		array $preResolveData,
		string $property,
		string $registerDefault,
	): string {
		$meta = $preResolveData['_translationMeta'] ?? null;
		if (is_array($meta) === true
			&& isset($meta[$property]) === true
			&& is_array($meta[$property]) === true
			&& isset($meta[$property]['sourceLanguage']) === true
			&& is_string($meta[$property]['sourceLanguage']) === true
			&& $meta[$property]['sourceLanguage'] !== ''
		) {
			return (string)$meta[$property]['sourceLanguage'];
		}

		$properties = $schema->getProperties() ?? [];
		$definition = $properties[$property] ?? null;
		if (is_array($definition) === true
			&& isset($definition['sourceLanguage']) === true
			&& is_string($definition['sourceLanguage']) === true
			&& $definition['sourceLanguage'] !== ''
		) {
			return (string)$definition['sourceLanguage'];
		}

		if ($registerDefault !== '') {
			return $registerDefault;
		}

		return 'nl';
	}//end resolveSourceLanguageForProperty()

	/**
	 * Apply virtual (materialise:false) calculations declared on the
	 * schema to the given data array. Materialised calculations are
	 * persisted on save and skipped here.
	 *
	 * @param ObjectEntity $entity The object being rendered (used for @self.* refs).
	 * @param Schema|null $schema The schema definition (may be null).
	 * @param array<string, mixed> $data The current rendered data array.
	 *
	 * @return array<string, mixed>
	 */
	private function applyVirtualCalculations(ObjectEntity $entity, $schema, array $data): array {
		if ($schema === null) {
			return $data;
		}

		$config = ($schema->getConfiguration() ?? []);
		$calcs = ($config['x-openregister-calculations'] ?? null);
		if (is_array($calcs) === false || count($calcs) === 0) {
			return $data;
		}

		$created = $entity->getCreated();
		$updated = $entity->getUpdated();

		$createdFormatted = null;
		if ($created !== null) {
			$createdFormatted = $created->format(\DateTimeInterface::ATOM);
		}

		$updatedFormatted = null;
		if ($updated !== null) {
			$updatedFormatted = $updated->format(\DateTimeInterface::ATOM);
		}

		$payload = $data;
		$payload['@self'] = [
			'id' => $entity->getUuid(),
			'uuid' => $entity->getUuid(),
			'register' => $entity->getRegister(),
			'schema' => $entity->getSchema(),
			'owner' => $entity->getOwner(),
			'created' => $createdFormatted,
			'updated' => $updatedFormatted,
		];

		foreach ($calcs as $name => $spec) {
			if (is_array($spec) === false) {
				continue;
			}

			// Skip materialised — already in $data from save-time listener.
			if (($spec['materialise'] ?? false) === true) {
				continue;
			}

			try {
				$value = $this->calculationEvaluator->evaluate($payload, $spec['expression'] ?? null);
				if ($value instanceof \DateTimeInterface) {
					$value = $value->format(\DateTimeInterface::ATOM);
				}

				$data[(string)$name] = $value;
			} catch (\Throwable $e) {
				$this->logger->debug(
					sprintf('[RenderObject] Virtual calculation "%s" failed: %s', (string)$name, $e->getMessage())
				);
			}
		}//end foreach

		return $data;
	}//end applyVirtualCalculations()

	/**
	 * Handle extends containing a wildcard ($)
	 *
	 * @param array $objectData The data to extend
	 * @param array $_extend The fields that should be extended
	 * @param int $depth The current depth.
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function handleWildcardExtends(array $objectData, array &$_extend, int $depth): array {
		$objectData = new Dot($objectData);
		if ($depth >= 10) {
			return $objectData->all();
		}

		$wildcardExtends = array_filter(
			$_extend,
			function (string $key) {
				return str_contains($key, '.$.');
			}
		);

		$extendedRoots = [];

		foreach ($wildcardExtends as $key => $wildcardExtend) {
			unset($_extend[$key]);

			[$root, $extends] = explode(separator: '.$.', string: $wildcardExtend, limit: 2);

			if (is_numeric($key) === true) {
				$extendedRoots[$root][] = $extends;
				continue;
			}

			[$root, $path] = explode(separator: '.$.', string: $key, limit: 2);
			$extendedRoots[$root][$path] = $extends;
		}

		foreach ($extendedRoots as $root => $extends) {
			$data = $objectData->get(key: $root);
			if (is_iterable($data) === false) {
				continue;
			}

			foreach ($data as $key => $date) {
				$tmpExtends = $extends;
				$data[$key] = $this->handleExtendDot(data: $date, _extend: $tmpExtends, depth: $depth);
			}

			$objectData->set($root, $data);
		}

		return $objectData->all();
	}//end handleWildcardExtends()

	/**
	 * Handle extends on a dot array
	 *
	 * @param array $data The data to extend.
	 * @param array $_extend The fields to extend.
	 * @param int $depth The current depth.
	 * @param bool $allFlag If we extend all or not.
	 * @param array $visitedIds All ids we already handled.
	 * @param array $exposures The relation descriptors that declare a field set, keyed by
	 *                         the property the link hangs on. Empty for every schema that
	 *                         declares none, which is every schema written so far.
	 *
	 * @return array
	 *
	 * @throws \OCP\DB\Exception
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex extension handling with multiple data types
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Many extension scenarios create multiple code paths
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive dot notation handling requires extensive logic
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   All flag controls extension behavior
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function handleExtendDot(
		array $data,
		array &$_extend,
		int $depth,
		bool $allFlag = false,
		array $visitedIds = [],
		array $exposures = [],
	): array {
		$data = $this->handleWildcardExtends(objectData: $data, _extend: $_extend, depth: $depth + 1);

		$dataDot = new Dot($data);

		foreach ($_extend as $override => $key) {
			// Skip if the key does not have to be extended.
			if ($dataDot->has(keys: $key) === false) {
				continue;
			}

			// Skip if the key starts with '@' (special fields).
			if (str_starts_with($key, '@') === true) {
				continue;
			}

			// Get sub-keys for nested extension.
			$keyExtends = array_map(
				fn (string $extendedKey) => substr(string: $extendedKey, offset: strlen($key) + 1),
				array_filter(
					$_extend,
					fn (string $singleKey) => str_starts_with(haystack: $singleKey, needle: $key . '.')
				)
			);

			$value = $dataDot->get(key: $key);

			// Make sure arrays are arrays.
			if ($value instanceof Dot) {
				$value = $value->jsonSerialize();
			}

			// Skip if the value is null.
			if ($value === null) {
				continue;
			}

			// Extend the subobject(s).
			if (is_array($value) === true) {
				// Filter out null values and values starting with '@' before mapping.
				$value = array_filter(
					$value,
					fn ($v) => $v !== null
						&& (is_string($v) === false || str_starts_with(haystack: $v, needle: '@') === false)
				);
				$descriptor = ($exposures[$key] ?? null);
				$renderedValue = array_map(
					function ($identifier) use ($depth, $keyExtends, $allFlag, $visitedIds, $descriptor) {
						// If already an extended object (has 'id' and '@self' keys), return as-is.
						// This prevents double-processing when extend is called multiple times.
						if (is_array($identifier) === true) {
							if (isset($identifier['id']) === true || isset($identifier['@self']) === true) {
								// Already extended, by the wildcard pass above or by an
								// earlier call. The exposure still applies: an extend that
								// arrives here pre-rendered is the same far record reached
								// through the same link, and skipping the projection because
								// somebody else did the loading would be a control that any
								// caller can step around by asking for the wildcard form.
								// Projecting twice is harmless, a withheld field stays
								// withheld.
								return $this->applyLinkExposure(rendered: $identifier, descriptor: $descriptor);
							}

							return null;
						}

						// **PERFORMANCE OPTIMIZATION**: Use preloaded cache instead of individual queries.
						$object = $this->getObject(id: $identifier);
						if ($object === null) {
							// Object not found - preserve the original UUID instead of returning null.
							// This keeps the reference data intact even when the referenced object
							// doesn't exist (e.g., data imported from CSV with external references).
							$this->logger->debug(
								message: '[RenderObject] Object not found in preloaded cache - preserving original UUID',
								context: [
									'file' => __FILE__,
									'line' => __LINE__,
									'identifier' => $identifier,
									'context' => 'extend_array_processing',
								]
							);
							return $identifier;
						}

						if (in_array($object->getUuid(), $visitedIds, true) === true) {
							return ['@circular' => true, 'id' => $object->getUuid()];
						}

						$subExtend = $keyExtends;
						if ($allFlag === true) {
							$subExtend = array_merge(['all'], $keyExtends);
						}

						$rendered = $this->renderEntity(
							entity: $object,
							_extend: $subExtend,
							depth: $depth + 1,
							filter: [],
							fields: [],
							unset: [],
							visitedIds: $visitedIds
						)->jsonSerialize();

						return $this->applyLinkExposure(rendered: $rendered, descriptor: $descriptor);
					},
					$value
				);

				// Filter out any null values that might have been returned from the mapping.
				$renderedValue = array_filter($renderedValue, fn ($v) => $v !== null);

				if (is_numeric($override) === false) {
					// Reset array keys.
					$dataDot->set(keys: $override, value: array_values($renderedValue));
					continue;
				}

				// Reset array keys.
				$dataDot->set(keys: $key, value: array_values($renderedValue));
				continue;
			}//end if

			// Skip if the value starts with '@' or '_'.
			if (is_string($value) === true
				&& ((str_starts_with(haystack: $value, needle: '@') === true)
				|| (str_starts_with(haystack: $value, needle: '_') === true)) === true
			) {
				continue;
			}

			if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
				$path = parse_url($value, PHP_URL_PATH);
				$pathExploded = explode('/', $path);
				$value = end($pathExploded);
			}

			// **PERFORMANCE OPTIMIZATION**: Use preloaded cache instead of individual queries.
			$object = $this->getObject(id: $value);

			if ($object === null) {
				// If not in cache, this object wasn't preloaded - skip it to prevent N+1.
				$this->logger->debug(
					message: '[RenderObject] Single object not found in preloaded cache - skipping to prevent N+1 query',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'identifier' => $value,
						'context' => 'extend_single_processing',
					]
				);
				continue;
			}

			$subExtend = $keyExtends;
			if ($allFlag === true) {
				$subExtend = array_merge(['all'], $keyExtends);
			}

			$rendered = $this->applyLinkExposure(
				rendered: $this->renderEntity(
					entity: $object,
					_extend: $subExtend,
					depth: $depth + 1,
					filter: [],
					fields: [],
					unset: [],
					visitedIds: $visitedIds
				)->jsonSerialize(),
				descriptor: ($exposures[$key] ?? null)
			);

			if (in_array($object->getUuid(), $visitedIds, true) === true) {
				$rendered = ['@circular' => true, 'id' => $object->getUuid()];
			}

			if (is_numeric($override) === false) {
				$dataDot->set(keys: $override, value: $rendered);
				continue;
			}

			$dataDot->set(keys: $key, value: $rendered);
		}//end foreach

		return $dataDot->jsonSerialize();
	}//end handleExtendDot()


	/**
	 * The relation descriptors that declare a field set, keyed by property.
	 *
	 * Resolved through {@see RelationTypeResolver}, which is the one reader of
	 * `x-openregister-relation-types`. Reading the annotation here instead
	 * would be a second reader of one vocabulary, and the two would drift.
	 *
	 * Empty for a schema that declares no exposure, which is every schema
	 * written before this change: an undeclared `exposes` narrows nothing.
	 *
	 * @param Schema|null $schema The schema being rendered.
	 *
	 * @return array<string, array<string, mixed>> The descriptors, keyed by property name.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	private function exposuresFor(?Schema $schema): array {
		if ($schema === null) {
			return [];
		}

		if ($this->relationTypes === null) {
			$this->relationTypes = new RelationTypeResolver();
		}

		if ($this->linkExposure === null) {
			$this->linkExposure = new LinkExposure();
		}

		$exposures = [];
		foreach ($this->relationTypes->descriptors(schema: $schema) as $property => $descriptor) {
			if ($this->linkExposure->declaresExposure(relationType: $descriptor) === true) {
				$exposures[(string)$property] = $descriptor;
			}
		}

		return $exposures;
	}//end exposuresFor()

	/**
	 * Narrow one extended far record to what its link declares it exposes.
	 *
	 * 🔑 THERE IS NO SECOND PERMISSION EVALUATOR HERE, and that is the design
	 * (D-4). The readable set is whatever survived `renderEntity()`, which has
	 * already run the far schema's own property rules through
	 * `PropertyRbacHandler`. This takes that answer as its argument and
	 * intersects the declared set with it, so a link can carry a reader to a
	 * record they could not otherwise open and can never show them a field
	 * their own rules withhold.
	 *
	 * 🔴 `@self` AND `id` ARE NOT PROPERTIES AND ARE NEVER WITHHELD. They are
	 * the render envelope: marking `id` withheld would break every client that
	 * follows the link it was handed, and it would say "you may not see this
	 * record's identity" about a record the link exists to point at. The
	 * exposure decides which FIELDS travel, not whether the link is there.
	 *
	 * @param array<string, mixed>      $rendered   The far record as renderEntity answered it.
	 * @param array<string, mixed>|null $descriptor The relation descriptor, or null when the link declares none.
	 *
	 * @return array<string, mixed> The projection.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	private function applyLinkExposure(array $rendered, ?array $descriptor): array {
		if ($descriptor === null) {
			return $rendered;
		}

		if ($this->linkExposure === null) {
			$this->linkExposure = new LinkExposure();
		}

		$envelope = [];
		$body = [];
		foreach ($rendered as $property => $value) {
			$property = (string)$property;
			if ($property === '@self' || $property === 'id' || str_starts_with($property, '@') === true) {
				$envelope[$property] = $value;
				continue;
			}

			$body[$property] = $value;
		}

		$projected = $this->linkExposure->project(
			farObject: $body,
			relationType: $descriptor,
			readable: array_map('strval', array_keys($body))
		);

		return array_merge($projected, $envelope);
	}//end applyLinkExposure()

	/**
	 * Extends an object with additional data based on the extension configuration
	 *
	 * @param ObjectEntity $entity The entity to extend
	 * @param array $_extend Extension configuration
	 * @param array $objectData Current object data
	 * @param int $depth Current depth level
	 * @param array|null $_filter Filters to apply
	 * @param array|null $_fields Fields to include
	 * @param array|null $_unset Properties to remove from the rendered entity
	 * @param array|null $visitedIds ids of objects already handled
	 *
	 * @return array The extended object data
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendObject(
		ObjectEntity $entity,
		array $_extend,
		array $objectData,
		int $depth,
		?array $_filter = [],
		?array $_fields = [],
		?array $_unset = [],
		?array $visitedIds = [],
	): array {
		// Add register and schema context to @self if requested.
		if (in_array('@self.register', $_extend) === true || in_array('@self.schema', $_extend) === true) {
			$self = $objectData['@self'] ?? [];

			if (in_array('@self.register', $_extend) === true) {
				$register = $this->getRegister(id: $entity->getRegister());
				if ($register !== null) {
					$self['register'] = $register->jsonSerialize();
				}
			}

			if (in_array('@self.schema', $_extend) === true) {
				$schema = $this->getSchema(id: $entity->getSchema());
				if ($schema !== null) {
					$self['schema'] = $schema->jsonSerialize();
				}
			}

			$objectData['@self'] = $self;
		}

		// Object-source relations first: a property whose `$ref` targets a
		// schema served by an object-source provider must resolve through THAT
		// provider — its raw key (often a plain integer) would otherwise be
		// looked up against native object storage and can match an unrelated
		// object entirely. Resolved keys are removed from $_extend so the
		// generic path below never sees them.
		[$objectData, $_extend] = $this->extendObjectSourceRefs(
			entity: $entity,
			_extend: $_extend,
			objectData: $objectData,
			depth: $depth,
			visitedIds: ($visitedIds ?? [])
		);

		// **PERFORMANCE OPTIMIZATION**: Batch preload all UUIDs that will be extended.
		// This collects all UUIDs from the properties that will be extended and loads
		// them in a SINGLE database query, instead of one query per UUID.
		// PERF-8: during a full page render renderEntities() has already batch-preloaded
		// every extend UUID for the whole page into $objectsCache, so re-collecting and
		// re-preloading per row is wasted CPU. Skip it at depth 0 while a page render is
		// active; nested (depth > 0) extends still preload their own children.
		$skipPreload = ($this->pageRenderActive === true && $depth === 0);
		$uuidsToPreload = [];
		if ($skipPreload === false) {
			$uuidsToPreload = $this->collectUuidsForExtend(objectData: $objectData, extend: $_extend);
		}

		if (empty($uuidsToPreload) === false) {
			$preloadedObjects = $this->objectCacheService->preloadObjects(
				identifiers: $uuidsToPreload,
				registerId: $this->registerIdOf(entity: $entity)
			);
			// Add preloaded objects to local cache for immediate access.
			foreach ($preloadedObjects as $object) {
				$this->objectsCache[$object->getUuid()] = $object;
				$this->objectsCache[$object->getId()] = $object;
			}

			$this->logger->debug(
				message: '[RenderObject] Batch preloaded objects for extend',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'requestedUuids' => count($uuidsToPreload),
					'loadedObjects' => count($preloadedObjects),
				]
			);
		}

		$objectDataDot = $this->handleExtendDot(
			data: $objectData,
			_extend: $_extend,
			depth: $depth,
			allFlag: in_array('all', $_extend, true),
			visitedIds: $visitedIds,
			exposures: $this->exposuresFor(schema: $this->getSchema(id: $entity->getSchema()))
		);

		return $objectDataDot;
	}//end extendObject()

	/**
	 * The register id an entity lives in, or null when it has none.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return int|null The register id.
	 */
	private function registerIdOf(ObjectEntity $entity): ?int {
		$register = $entity->getRegister();
		if ($register === null || is_numeric($register) === false) {
			return null;
		}

		return (int)$register;
	}//end registerIdOf()

	/**
	 * The register every entity on a page lives in, or null when they differ.
	 *
	 * @param array<int, mixed> $entities The page's entities.
	 *
	 * @return int|null The shared register id.
	 */
	private function sharedRegisterIdOf(array $entities): ?int {
		$shared = null;
		foreach ($entities as $entity) {
			if ($entity instanceof ObjectEntity === false) {
				continue;
			}

			$registerId = $this->registerIdOf(entity: $entity);
			if ($registerId === null || ($shared !== null && $shared !== $registerId)) {
				return null;
			}

			$shared = $registerId;
		}

		return $shared;
	}//end sharedRegisterIdOf()

	/**
	 * Resolve explicitly-extended `$ref` properties whose target schema is
	 * served by an object-source provider.
	 *
	 * The stored value of such a property is the EXTERNAL system's raw key
	 * (for a database-backed schema: the row's primary key, often a small
	 * integer). Resolving that key against native object storage — what the
	 * generic extend path does — can match an unrelated native object. Each
	 * matching key is resolved through the target schema's provider instead
	 * and removed from the extend list.
	 *
	 * Explicit extend keys only: `_extend=all` does not traverse object-source
	 * references (their raw keys are preserved un-expanded).
	 *
	 * @param ObjectEntity $entity The entity being rendered.
	 * @param array<int, string> $_extend The requested extends.
	 * @param array<string, mixed> $objectData The object data (mutated copy).
	 * @param int $depth The current render depth.
	 * @param array<int, string> $visitedIds Circular-reference guard.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>} [objectData, remaining extends].
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function extendObjectSourceRefs(
		ObjectEntity $entity,
		array $_extend,
		array $objectData,
		int $depth,
		array $visitedIds,
	): array {
		if ($this->objectSourceRegistry === null || $_extend === []) {
			return [$objectData, $_extend];
		}

		$schema = $this->getSchema(id: $entity->getSchema());
		if ($schema === null) {
			return [$objectData, $_extend];
		}

		$properties = ($schema->getProperties() ?? []);
		$register = null;

		foreach ($properties as $name => $definition) {
			if (is_array($definition) === false || in_array($name, $_extend, true) === false) {
				continue;
			}

			$ref = ($definition['$ref'] ?? ($definition['items']['$ref'] ?? null));

			// An INT is as valid a `$ref` as a slug, and is what a descriptor
			// ends up holding: `ImportHandler` rewrites `"$ref": "nc-organisation"`
			// to the resolved schema id. Requiring a string here meant a
			// reference resolved fine when hand-written and silently stopped
			// resolving the moment it had been through an import — the property
			// rendered as its raw key and nothing reported anything.
			// `getSchema()` has always accepted `int|string`.
			if (is_int($ref) === false && (is_string($ref) === false || $ref === '')) {
				continue;
			}

			$target = $this->getSchema(id: $ref);
			$objectSource = $target?->getObjectSource();
			if ($target === null || $objectSource === null) {
				continue;
			}

			$provider = $this->objectSourceRegistry->get((string)$objectSource['provider']);
			if ($provider === null || $provider->isEnabled() === false) {
				continue;
			}

			if ($register === null) {
				$register = $this->getRegister(id: $entity->getRegister());
			}

			if ($register === null) {
				continue;
			}

			$config = ($objectSource['config'] ?? []);
			$resolve = function ($identifier) use ($provider, $register, $target, $config, $depth, $visitedIds) {
				if (is_scalar($identifier) === false) {
					return $identifier;
				}

				$related = $provider->find(
					register: $register,
					schema: $target,
					id: (string)$identifier,
					config: $config
				);
				if ($related === null) {
					// Preserve the raw key when the external row is absent.
					return $identifier;
				}

				return $this->renderEntity(
					entity: $related,
					_extend: [],
					depth: ($depth + 1),
					filter: [],
					fields: [],
					unset: [],
					visitedIds: $visitedIds
				)->jsonSerialize();
			};

			$value = ($objectData[$name] ?? null);
			if (is_array($value) === true) {
				// An associative array (i.e. one carrying string keys such as
				// id/@self) is an object already embedded by an earlier extend
				// pass — never treat its VALUES as ids to resolve. A list can
				// never carry those string keys, so list-ness is the test.
				$alreadyEmbedded = (array_is_list($value) === false);
				if ($alreadyEmbedded === false) {
					$objectData[$name] = array_map($resolve, $value);
				}
			} elseif ($value !== null) {
				$objectData[$name] = $resolve($value);
			}

			// The generic extend path must never see this key — a raw external
			// key resolved against native storage can match a foreign object.
			$_extend = array_values(
				array_filter(
					$_extend,
					fn ($key) => ((string)$key) !== $name
						&& str_starts_with((string)$key, $name . '.') === false
				)
			);
		}//end foreach

		return [$objectData, $_extend];
	}//end extendObjectSourceRefs()

	/**
	 * Collect all UUIDs from object data for properties that will be extended.
	 *
	 * This method scans the object data for all UUIDs in properties that match
	 * the extend configuration, so they can be batch-loaded in a single query.
	 *
	 * @param array $objectData The object data to scan
	 * @param array $extend The properties to extend
	 *
	 * @return array Array of UUIDs to preload
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function collectUuidsForExtend(array $objectData, array $extend): array {
		$uuids = [];
		$dataDot = new Dot($objectData);

		foreach ($extend as $key) {
			// Skip special keys.
			if (str_starts_with($key, '@') === true) {
				continue;
			}

			// Get the base property name (before any dots for nested extends).
			$baseProp = explode('.', $key)[0];

			if ($dataDot->has($baseProp) === false) {
				continue;
			}

			$value = $dataDot->get($baseProp);

			// Handle array of UUIDs.
			if (is_array($value) === true) {
				foreach ($value as $item) {
					// Use regex-based UUID validation to support non-RFC 4122 compliant UUIDs
					// (e.g., GEMMA ArchiMate UUIDs which have non-standard variant bits).
					if (is_string($item) === true && $this->isUuidLike(value: $item) === true) {
						$uuids[] = $item;
					}
				}

				continue;
			}

			// Handle single UUID.
			// Use regex-based UUID validation to support non-RFC 4122 compliant UUIDs.
			if (is_string($value) === true && $this->isUuidLike(value: $value) === true) {
				$uuids[] = $value;
			}
		}//end foreach

		return array_unique($uuids);
	}//end collectUuidsForExtend()

	/**
	 * Batch preload inverse relationships for all entities.
	 *
	 * This is a CRITICAL performance optimization that prevents N+1 queries when extending
	 * inverse properties like 'contactpersonen'. Instead of searching all magic tables
	 * for each entity, we:
	 * 1. Identify which inverse properties are being extended
	 * 2. Determine the target schema for each inverse property
	 * 3. Do ONE batch query per target schema to find ALL referencing objects
	 * 4. Cache the results for use during individual entity rendering
	 *
	 * @param array $entities Array of ObjectEntity instances being rendered
	 * @param array $extend The _extend parameter specifying which properties to extend
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function preloadInverseRelationships(array $entities, array $extend): void {
		if (empty($entities) === true || empty($extend) === true) {
			return;
		}

		// Get the first entity to determine the schema (all entities should have the same schema).
		$firstEntity = reset($entities);
		if ($firstEntity instanceof \OCA\OpenRegister\Db\ObjectEntity === false) {
			return;
		}

		$schema = $this->getSchema(id: $firstEntity->getSchema());
		if ($schema === null) {
			return;
		}

		// Get properties that have inversedBy configurations.
		$inversedProperties = $this->getInversedProperties(schema: $schema);
		if (empty($inversedProperties) === true) {
			return;
		}

		// Filter to only inverse properties that are being extended.
		$inversePropsExtend = $this->filterExtendedInverseProperties(
			inversedProperties: $inversedProperties,
			extend: $extend
		);
		if (empty($inversePropsExtend) === true) {
			return;
		}

		// Collect all entity UUIDs.
		$entityUuids = $this->collectEntityUuids(entities: $entities);
		if (empty($entityUuids) === true) {
			return;
		}

		$this->logger->debug(
			message: '[RenderObject] [INVERSE_PRELOAD] Starting batch inverse preload',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'entityCount' => count($entityUuids),
				'inverseProperties' => array_keys($inversePropsExtend),
			]
		);

		// For each inverse property, determine target schema and batch-load referencing objects.
		foreach ($inversePropsExtend as $propName => $propConfig) {
			$this->preloadSingleInverseProperty(
				propName: $propName,
				propConfig: $propConfig,
				entityUuids: $entityUuids,
				firstEntity: $firstEntity
			);
		}//end foreach
	}//end preloadInverseRelationships()

	/**
	 * Filter inversed properties to only those being extended.
	 *
	 * @param array $inversedProperties All inversed properties from the schema
	 * @param array $extend The _extend parameter specifying which properties to extend
	 *
	 * @return array Filtered array of inverse properties that are being extended
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function filterExtendedInverseProperties(array $inversedProperties, array $extend): array {
		$inversePropsExtend = [];
		foreach ($inversedProperties as $propName => $propConfig) {
			if (in_array($propName, $extend, true) === true || in_array('all', $extend, true) === true) {
				$inversePropsExtend[$propName] = $propConfig;
			}
		}

		return $inversePropsExtend;
	}//end filterExtendedInverseProperties()

	/**
	 * Collect UUIDs from an array of entities.
	 *
	 * @param array $entities Array of ObjectEntity instances
	 *
	 * @return array Array of UUID strings
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function collectEntityUuids(array $entities): array {
		$entityUuids = [];
		foreach ($entities as $entity) {
			if ($entity instanceof \OCA\OpenRegister\Db\ObjectEntity === true && $entity->getUuid() !== null) {
				$entityUuids[] = $entity->getUuid();
			}
		}

		return $entityUuids;
	}//end collectEntityUuids()

	/**
	 * Extract inverse configuration (target schema ref and inversedBy fields) from a property config.
	 *
	 * Returns null if the configuration is incomplete (missing $ref or inversedBy).
	 *
	 * @param array $propConfig The property configuration array
	 *
	 * @return array|null Array with keys 'targetSchemaRef' and 'inversedByFields', or null if invalid
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extractInverseConfig(array $propConfig): ?array {
		// Extract target schema reference.
		$targetSchemaRef = $propConfig['items']['$ref'] ?? $propConfig['$ref'] ?? null;
		$inversedByField = $propConfig['items']['inversedBy'] ?? $propConfig['inversedBy'] ?? null;

		if ($targetSchemaRef === null || $inversedByField === null) {
			return null;
		}

		// Normalize inversedBy to an array to support multi-field inverse relations.
		// Example: "inversedBy": ["moduleA", "moduleB"] means the entity can appear in either field.
		$inversedByFields = [$inversedByField];
		if (is_array($inversedByField) === true) {
			$inversedByFields = $inversedByField;
		}

		return [
			'targetSchemaRef' => $targetSchemaRef,
			'inversedByFields' => $inversedByFields,
		];
	}//end extractInverseConfig()

	/**
	 * Preload inverse objects for a single inverse property.
	 *
	 * Resolves the target schema, batch-loads all referencing objects, and populates
	 * the inverse relation cache for all given entity UUIDs.
	 *
	 * @param string $propName The inverse property name
	 * @param array $propConfig The property configuration array
	 * @param array $entityUuids Array of entity UUIDs to preload for
	 * @param ObjectEntity $firstEntity The first entity (used to determine register)
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function preloadSingleInverseProperty(
		string $propName,
		array $propConfig,
		array $entityUuids,
		ObjectEntity $firstEntity,
	): void {
		// Extract and validate inverse configuration.
		$inverseConfig = $this->extractInverseConfig(propConfig: $propConfig);
		if ($inverseConfig === null) {
			return;
		}

		$inversedByFields = $inverseConfig['inversedByFields'];

		// Resolve schema reference to ID.
		$targetSchemaId = $this->resolveSchemaReference(schemaRef: $inverseConfig['targetSchemaRef']);
		if (empty($targetSchemaId) === true) {
			return;
		}

		// Get the target schema to find its register.
		$targetSchema = $this->getSchema(id: $targetSchemaId);
		if ($targetSchema === null) {
			return;
		}

		// Batch find all objects of the target schema that reference ANY of our entity UUIDs.
		// This uses the _relations column with GIN index for efficiency.
		try {
			$referencingObjects = $this->batchLoadReferencingObjects(
				entityUuids: $entityUuids,
				targetSchemaId: $targetSchemaId,
				registerId: (int)$firstEntity->getRegister(),
				inversedByFields: $inversedByFields
			);

			// Pre-initialize cache entries for ALL entities with empty arrays.
			// This prevents fallback to the slow per-entity findByRelation() path,
			// which does expensive LIKE scans iterating all magic tables.
			$this->initializeInverseCacheEntries(entityUuids: $entityUuids, propName: $propName);

			// Index the results by which entity UUID they reference.
			$this->indexReferencingObjects(
				referencingObjects: $referencingObjects,
				inversedByFields: $inversedByFields,
				entityUuids: $entityUuids,
				propName: $propName
			);

			$this->logger->debug(
				message: '[RenderObject] [INVERSE_PRELOAD] Batch loaded inverse relationships',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'property' => $propName,
					'targetSchema' => $targetSchemaId,
					'foundObjects' => count($referencingObjects),
				]
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[RenderObject] [INVERSE_PRELOAD] Batch preload failed, falling back to per-entity lookup',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'property' => $propName,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end preloadSingleInverseProperty()

	/**
	 * Batch load objects from the target schema that reference any of the given UUIDs.
	 *
	 * Uses the MagicMapper's findByRelationBatchInSchema with GIN index for efficiency.
	 *
	 * @param array $entityUuids Array of entity UUIDs to search for references to
	 * @param string $targetSchemaId The target schema ID to search within
	 * @param int $registerId The register ID to search within
	 * @param array $inversedByFields Array of field names that may hold the inverse reference
	 *
	 * @return array Array of ObjectEntity instances that reference the given UUIDs
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function batchLoadReferencingObjects(
		array $entityUuids,
		string $targetSchemaId,
		int $registerId,
		array $inversedByFields,
	): array {
		// Validate inputs before accessing \OC service container.
		if (empty($entityUuids) === true || empty($inversedByFields) === true) {
			return [];
		}

		$schemaIdInt = (int)$targetSchemaId;
		if ($schemaIdInt <= 0 || $registerId <= 0) {
			return [];
		}

		// Pass additional field names for multi-field inversedBy so the SQL also searches
		// columns that may store references in {"value": "uuid"} format not in _relations.
		$additionalFields = [];
		if (count($inversedByFields) > 1) {
			$additionalFields = array_slice($inversedByFields, 1);
		}

		return $this->objectEntityMapper->findByRelationBatchInSchema(
			uuids: $entityUuids,
			schemaId: $schemaIdInt,
			registerId: $registerId,
			fieldName: $inversedByFields[0],
			additionalFieldNames: $additionalFields
		);
	}//end batchLoadReferencingObjects()

	/**
	 * Pre-initialize inverse relation cache entries for all entities with empty arrays.
	 *
	 * This prevents fallback to the slow per-entity findByRelation() path,
	 * which does expensive LIKE scans iterating all magic tables.
	 *
	 * @param array $entityUuids Array of entity UUIDs
	 * @param string $propName The inverse property name
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function initializeInverseCacheEntries(array $entityUuids, string $propName): void {
		foreach ($entityUuids as $entityUuid) {
			$cacheKey = $entityUuid . '_' . $propName;
			if (isset($this->inverseRelationCache[$cacheKey]) === false) {
				$this->inverseRelationCache[$cacheKey] = [];
			}
		}
	}//end initializeInverseCacheEntries()

	/**
	 * Index referencing objects into the inverse relation cache by entity UUID.
	 *
	 * Checks all inversedBy fields (supports array of field names) and handles
	 * object references with {"value": "uuid"} format as well as arrays of UUIDs.
	 *
	 * @param array $referencingObjects Array of ObjectEntity instances to index
	 * @param array $inversedByFields Array of field names that may hold the inverse reference
	 * @param array $entityUuids Array of entity UUIDs to match against
	 * @param string $propName The inverse property name for cache key generation
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function indexReferencingObjects(
		array $referencingObjects,
		array $inversedByFields,
		array $entityUuids,
		string $propName,
	): void {
		foreach ($referencingObjects as $refObject) {
			$refData = $refObject->getObject();

			foreach ($inversedByFields as $field) {
				$referencedUuids = $this->resolveReferencedUuids(refData: $refData, field: $field);

				foreach ($referencedUuids as $uuid) {
					if ($uuid !== null && in_array($uuid, $entityUuids, true) === true) {
						$cacheKey = $uuid . '_' . $propName;
						// Avoid duplicate entries when the same object matches multiple fields.
						$existingUuids = array_map(
							fn (ObjectEntity $obj) => $obj->getUuid(),
							$this->inverseRelationCache[$cacheKey] ?? []
						);
						if (in_array($refObject->getUuid(), $existingUuids, true) === false) {
							$this->inverseRelationCache[$cacheKey][] = $refObject;
						}

						// Also add to objects cache for extended rendering.
						$this->objectsCache[$refObject->getUuid()] = $refObject;
					}
				}
			}//end foreach
		}//end foreach
	}//end indexReferencingObjects()

	/**
	 * Resolve referenced UUIDs from a referencing object's data field.
	 *
	 * Handles multiple reference formats:
	 * - Simple string UUID: "uuid-value"
	 * - Object reference: {"value": "uuid-value"}
	 * - Array of UUIDs: ["uuid1", "uuid2"]
	 *
	 * @param array $refData The referencing object's data array
	 * @param string $field The field name to extract referenced UUIDs from
	 *
	 * @return array Array of UUID strings (may contain nulls which should be filtered by caller)
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function resolveReferencedUuids(array $refData, string $field): array {
		$referencedUuid = $refData[$field] ?? null;

		// Handle object references with {"value": "uuid"} format.
		if (is_array($referencedUuid) === true && isset($referencedUuid['value']) === true) {
			$referencedUuid = $referencedUuid['value'];
		}

		// Handle both single UUID and array of UUIDs.
		if (is_array($referencedUuid) === true) {
			return $referencedUuid;
		}

		return [$referencedUuid];
	}//end resolveReferencedUuids()

	/**
	 * Gets the inversed properties from a schema
	 *
	 * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
	 *
	 * @param Schema $schema The schema to check for inversed properties
	 *
	 * @return array Array of property names that have inversedBy configurations
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getInversedProperties(Schema $schema): array {
		$properties = $schema->getProperties();

		// Use array_filter to get properties with inversedBy configurations.
		// TODO: Move writeBack, removeAfterWriteBack, and inversedBy
		// from items property to configuration property.
		$inversedProperties = array_filter(
			$properties,
			function (array $property): bool {
				return (isset($property['inversedBy'])
					&& empty($property['inversedBy']) === false)
					|| (isset($property['items']['inversedBy'])
					&& empty($property['items']['inversedBy']) === false);
			}
		);

		// Extract the property names and their inversedBy values.
		return $inversedProperties;
	}//end getInversedProperties()

	/**
	 * Handles inversed properties for an object
	 *
	 * @param ObjectEntity $entity The entity to process
	 * @param array $objectData The current object data
	 * @param int $_depth Current depth level
	 * @param array|null $_filter Filters to apply
	 * @param array|null $_fields Fields to include
	 * @param array|null $_unset Properties to remove from the rendered entity
	 * @param array|null $_registers Preloaded registers
	 * @param array|null $_schemas Preloaded schemas
	 * @param array|null $_objects Preloaded objects
	 *
	 * @return array The updated object data with inversed properties
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex inversed relationship resolution
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple relationship types create many paths
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive relationship handling requires extensive logic
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function handleInversedProperties(
		ObjectEntity $entity,
		array $objectData,
		int $_depth,
		?array $_filter = [],
		?array $_fields = [],
		?array $_unset = [],
		?array $_registers = [],
		?array $_schemas = [],
		?array $_objects = [],
	): array {
		// Get the schema for this object.
		$schema = $this->getSchema(id: $entity->getSchema());
		if ($schema === null) {
			return $objectData;
		}

		// Get properties that have inversedBy configurations.
		$inversedProperties = $this->getInversedProperties(schema: $schema);
		if (empty($inversedProperties) === true) {
			return $objectData;
		}

		// **PERFORMANCE OPTIMIZATION**: Check if we have preloaded cache for this entity.
		// If yes, we can skip the expensive findByRelation call entirely.
		$entityUuid = $entity->getUuid();
		$hasCache = false;
		$propertyNames = array_keys($inversedProperties);

		foreach ($propertyNames as $propName) {
			$cacheKey = $entityUuid . '_' . $propName;
			if (isset($this->inverseRelationCache[$cacheKey]) === true) {
				$hasCache = true;
				break;
			}
		}

		// If we have preloaded cache, use it directly instead of querying.
		if ($hasCache === true) {
			return $this->handleInversedPropertiesFromCache(
				entity: $entity,
				objectData: $objectData,
				inversedProperties: $inversedProperties
			);
		}

		// Single-entity render (e.g. show()): the batch preload only runs on the
		// list path (renderEntities), so the cache is cold here. Route this read
		// through the SAME schema-targeted batched machinery the list path uses
		// (preloadInverseRelationships → findByRelationBatchInSchema, GIN-indexed)
		// instead of falling straight into the generic cross-table
		// findByRelation() scan. ALL inverse properties are preloaded — not just
		// the extended ones — because a single read has always resolved every
		// inverse property once any one of them is extended; preloading a subset
		// would silently empty the others (consumer-visible response shape).
		$this->preloadInverseRelationships(
			entities: [$entity],
			extend: $propertyNames
		);

		foreach ($propertyNames as $propName) {
			if (isset($this->inverseRelationCache[$entityUuid . '_' . $propName]) === true) {
				$hasCache = true;
				break;
			}
		}

		if ($hasCache === true) {
			return $this->handleInversedPropertiesFromCache(
				entity: $entity,
				objectData: $objectData,
				inversedProperties: $inversedProperties
			);
		}

		// Fallback: Query for referencing objects (original slower cross-table path).
		// Only reached when the batched preload could not populate the cache
		// (e.g. unresolvable target schema reference or batch query failure).
		$referencingObjects = $this->objectEntityMapper->findByRelation($entityUuid);

		// For multi-field inversedBy, also search columns directly since _relations
		// may not contain UUIDs stored in object-format fields (e.g., {"value": "uuid"}).
		foreach ($inversedProperties as $propName => $propConfig) {
			$inversedByValue = $propConfig['items']['inversedBy'] ?? $propConfig['inversedBy'] ?? null;
			if (is_array($inversedByValue) === true && count($inversedByValue) > 1) {
				$targetSchemaRef = $propConfig['items']['$ref'] ?? $propConfig['$ref'] ?? null;
				if ($targetSchemaRef === null) {
					continue;
				}

				$targetSchemaId = $this->resolveSchemaReference(schemaRef: $targetSchemaRef);
				if (empty($targetSchemaId) === true) {
					continue;
				}

				$additionalFields = array_slice($inversedByValue, 1);
				$additionalResults = $this->objectEntityMapper->findByRelationBatchInSchema(
					uuids: [$entityUuid],
					schemaId: (int)$targetSchemaId,
					registerId: (int)$entity->getRegister(),
					fieldName: $inversedByValue[0],
					additionalFieldNames: $additionalFields
				);
				// Merge results, deduplicating by UUID.
				$existingUuids = array_map(fn (ObjectEntity $obj) => $obj->getUuid(), $referencingObjects);
				foreach ($additionalResults as $obj) {
					if (in_array($obj->getUuid(), $existingUuids, true) === false) {
						$referencingObjects[] = $obj;
						$existingUuids[] = $obj->getUuid();
					}
				}
			}//end if
		}//end foreach

		// Set all found objects to the objectsCache.
		$ids = array_map(
			function (ObjectEntity $object) {
				return $object->getUuid();
			},
			$referencingObjects
		);

		// Filter out null IDs before combining arrays.
		$validIds = [];
		$validObjects = [];
		foreach ($ids as $index => $id) {
			if ($id !== null) {
				$validIds[] = $id;
				$validObjects[] = $referencingObjects[$index];
			}
		}

		$objectsToCache = array_combine(keys: $validIds, values: $validObjects);
		$this->objectsCache = array_merge($objectsToCache, $this->objectsCache);

		// Process each inversed property.
		// For a property like 'deelnemers' with inversedBy='deelnames':
		// - Keep the original 'deelnemers' values (forward references to other orgs)
		// - Find objects that have our UUID in THEIR 'deelnemers' field.
		// - Put those objects' UUIDs in OUR 'deelnames' field (inverse references).
		foreach ($inversedProperties as $propertyName => $propertyConfig) {
			// Extract inversedBy configuration based on property structure.
			// Check if this is an array property with inversedBy in items.
			$inversedByProperty = null;
			$targetSchema = null;
			$isArray = false;

			if (($propertyConfig['type'] ?? null) !== null
				&& ($propertyConfig['type'] === 'array') === true
				&& (($propertyConfig['items']['inversedBy'] ?? null) !== null) === true
			) {
				$inversedByProperty = $propertyConfig['items']['inversedBy'];
				$targetSchema = $propertyConfig['items']['$ref'] ?? null;
				$isArray = true;
			} elseif (($propertyConfig['inversedBy'] ?? null) !== null) {
				// Check if this is a direct object property with inversedBy.
				$inversedByProperty = $propertyConfig['inversedBy'];
				$targetSchema = $propertyConfig['$ref'] ?? null;

				// Fallback for misconfigured arrays.
				if ($propertyConfig['type'] === 'array') {
					$isArray = true;
				}
			}

			// Skip if no inversedBy configuration found.
			if ($inversedByProperty === null) {
				continue;
			}

			// Normalize inversedBy to an array to support multi-field inverse relations.
			$inversedByProperties = [$inversedByProperty];
			if (is_array($inversedByProperty) === true) {
				$inversedByProperties = $inversedByProperty;
			}

			// Resolve schema reference to actual schema ID.
			$schemaId = $entity->getSchema();
			// Use current schema if no target specified.
			if ($targetSchema !== null) {
				$schemaId = $this->resolveSchemaReference(schemaRef: $targetSchema);
			}

			// Always use $propertyName as the target property to populate.
			// This is the property being extended (e.g., 'standaardVersies').
			// The $inversedByProperty fields (e.g., ['moduleA', 'moduleB']) are the fields
			// on related objects that point back to this entity.
			$targetProperty = $propertyName;

			// Initialize the target property if not already set to preserve any existing values.
			if (isset($objectData[$targetProperty]) === false) {
				$defaultValue = null;
				if ($isArray === true) {
					$defaultValue = [];
				}

				$objectData[$targetProperty] = $defaultValue;
			}

			// Find objects that have our UUID in ANY of their inversedBy fields.
			// Supports multiple field names for cases like koppelingen where the entity
			// can appear as either moduleA or moduleB.
			$inversedObjects = array_values(
				array_filter(
					$referencingObjects,
					function (ObjectEntity $object) use ($inversedByProperties, $schemaId, $entity) {
						$data = $object->getObject();

						// Check each inversedBy field — match if ANY field contains our UUID.
						foreach ($inversedByProperties as $fieldToCheck) {
							if (isset($data[$fieldToCheck]) === false) {
								continue;
							}

							$referenceValue = $data[$fieldToCheck];

							// Handle object references with {"value": "uuid"} format.
							if (is_array($referenceValue) === true && isset($referenceValue['value']) === true) {
								$referenceValue = $referenceValue['value'];
							}

							// Handle both array and single value references.
							if (is_array($referenceValue) === true) {
								if (in_array($entity->getUuid(), $referenceValue, true) === true
									&& $object->getSchema() === $schemaId
								) {
									return true;
								}

								continue;
							}

							// Check if the reference value matches the current entity's UUID.
							if (str_ends_with(haystack: $referenceValue, needle: $entity->getUuid()) === true
								&& $object->getSchema() === $schemaId
							) {
								return true;
							}
						}//end foreach

						return false;
					}
				)
			);

			// Render each inversed object to get full object data (not just UUIDs).
			// This makes inversedBy behave like regular _extend - returning full objects.
			$renderedObjects = array_map(
				function (ObjectEntity $object) {
					return $this->renderEntity(
						entity: $object,
						_extend: [],
						depth: 1,
						filter: [],
						fields: [],
						unset: []
					)->jsonSerialize();
				},
				$inversedObjects
			);

			// Set the target property value based on whether it's an array or single value.
			if ($isArray === true) {
				$objectData[$targetProperty] = $renderedObjects;
				continue;
			}

			$objectData[$targetProperty] = null;
			if (empty($renderedObjects) === false) {
				$objectData[$targetProperty] = end($renderedObjects);
			}
		}//end foreach

		return $objectData;
	}//end handleInversedProperties()

	/**
	 * Handle inversed properties using the preloaded cache.
	 *
	 * This is the FAST path that uses batch-preloaded inverse relationships
	 * instead of querying for each entity individually.
	 *
	 * @param ObjectEntity $entity The entity to process
	 * @param array $objectData The current object data
	 * @param array $inversedProperties The inversed property configurations
	 *
	 * @return array The updated object data with inversed properties populated
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function handleInversedPropertiesFromCache(
		ObjectEntity $entity,
		array $objectData,
		array $inversedProperties,
	): array {
		$entityUuid = $entity->getUuid();

		foreach ($inversedProperties as $propertyName => $propertyConfig) {
			// Extract configuration.
			$isArray = false;

			if (($propertyConfig['type'] ?? null) !== null
				&& ($propertyConfig['type'] === 'array') === true
				&& (($propertyConfig['items']['inversedBy'] ?? null) !== null) === true
			) {
				$isArray = true;
			} elseif (($propertyConfig['inversedBy'] ?? null) !== null) {
				if ($propertyConfig['type'] === 'array') {
					$isArray = true;
				}
			}

			// Skip properties without inversedBy configuration.
			$hasItemsInverse = ($propertyConfig['type'] ?? null) === 'array'
				&& ($propertyConfig['items']['inversedBy'] ?? null) !== null;
			$hasDirectInverse = ($propertyConfig['inversedBy'] ?? null) !== null;
			if ($hasItemsInverse === false && $hasDirectInverse === false) {
				continue;
			}

			// Always use $propertyName as the target property to populate.
			$targetProperty = $propertyName;

			// Get cached objects for this entity+property combination.
			$cacheKey = $entityUuid . '_' . $propertyName;
			$cachedObjects = $this->inverseRelationCache[$cacheKey] ?? [];

			// Render each cached object to get full object data (not just UUIDs).
			// This makes inversedBy behave like regular _extend - returning full objects.
			$renderedObjects = array_map(
				function (ObjectEntity $object) {
					return $this->renderEntity(
						entity: $object,
						_extend: [],
						depth: 1,
						filter: [],
						fields: [],
						unset: []
					)->jsonSerialize();
				},
				$cachedObjects
			);

			// Set the target property value with full rendered objects.
			if ($isArray === true) {
				$objectData[$targetProperty] = $renderedObjects;
			}

			if ($isArray === false) {
				$objectData[$targetProperty] = null;
				if (empty($renderedObjects) === false) {
					$objectData[$targetProperty] = end($renderedObjects);
				}
			}
		}//end foreach

		return $objectData;
	}//end handleInversedPropertiesFromCache()

	/**
	 * Resolve schema reference to actual schema ID
	 *
	 * @param string $schemaRef The schema reference (ID, UUID, path, or slug)
	 *
	 * @return string The resolved schema ID
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function resolveSchemaReference(string $schemaRef): string {
		// Remove query parameters if present (e.g., "schema?key=value" -> "schema").
		$cleanSchemaRef = $this->removeQueryParameters(reference: $schemaRef);

		// If it's already a numeric ID, return it.
		if (is_numeric($cleanSchemaRef) === true) {
			return $cleanSchemaRef;
		}

		// If it's a UUID, try to find the schema by UUID.
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cleanSchemaRef) === true) {
			try {
				$schema = $this->schemaMapper->find($cleanSchemaRef);
				return (string)$schema->getId();
			} catch (\Exception $e) {
				// If not found by UUID, continue with other methods.
			}
		}

		// Handle JSON Schema path references (e.g., "#/components/schemas/organisatie").
		if (str_contains($cleanSchemaRef, '/') === true) {
			$lastSegment = basename($cleanSchemaRef);
			// Remove any file extension or fragment.
			$lastSegment = preg_replace('/\.[^.]*$/', '', $lastSegment);
			$lastSegment = preg_replace('/#.*$/', '', $lastSegment);

			// Try to find schema by slug (case-insensitive).
			// Bypass RBAC and multi-tenancy since this is an internal schema resolution.
			try {
				$schemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
				foreach ($schemas as $schema) {
					if (strtolower($schema->getSlug() ?? '') === strtolower($lastSegment)) {
						return (string)$schema->getId();
					}
				}
			} catch (\Exception $e) {
				// If not found by slug, continue.
			}
		}

		// If it's a slug, try to find the schema by slug.
		// Bypass RBAC and multi-tenancy since this is an internal schema resolution.
		$schemas = $this->schemaMapper->findAll(filters: ['slug' => $cleanSchemaRef], _rbac: false, _multitenancy: false);

		if (count($schemas) === 1) {
			return (string)array_shift($schemas)->getId();
		}

		// If all else fails, try to use the reference as-is.
		return $cleanSchemaRef;
	}//end resolveSchemaReference()

	/**
	 * Removes query parameters from a reference string.
	 *
	 * @param string $reference The reference string that may contain query parameters
	 *
	 * @return string The reference string without query parameters
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function removeQueryParameters(string $reference): string {
		// Remove query parameters if present (e.g., "schema?key=value" -> "schema").
		if (str_contains($reference, '?') === true) {
			return substr($reference, 0, strpos($reference, '?'));
		}

		return $reference;
	}//end removeQueryParameters()

	/**
	 * Render multiple entities with extensions, filters, and field selections.
	 *
	 * This method renders an array of ObjectEntities by calling renderEntity() for each one.
	 * It's used for batch rendering of search results and collections.
	 *
	 * @param array $entities Array of ObjectEntity instances to render.
	 * @param array|string|null $_extend Properties to extend/embed in the response.
	 * @param array|null $_filter Filters to apply to the rendered entities.
	 * @param array|null $_fields Specific fields to include in the response.
	 * @param array|null $_unset Fields to exclude from the response.
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC and multitenancy flags control security behavior
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function renderEntities(
		array $entities,
		array|string|null $_extend = [],
		array|string|null $_filter = null,
		array|string|null $_fields = null,
		array|string|null $_unset = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		// Convert extend to array if it's a string.
		if (is_string($_extend) === true) {
			$_extend = explode(',', $_extend);
		}

		$_extend = $_extend ?? [];

		// Convert _fields to array if it's a string.
		if (is_string($_fields) === true) {
			$_fields = array_filter(array_map('trim', explode(',', $_fields)));
		}

		// Convert _filter to array if it's a string.
		if (is_string($_filter) === true) {
			$_filter = array_filter(array_map('trim', explode(',', $_filter)));
		}

		// Convert _unset to array if it's a string.
		if (is_string($_unset) === true) {
			$_unset = array_filter(array_map('trim', explode(',', $_unset)));
		}

		// **PERFORMANCE OPTIMIZATION**: Batch preload ALL related objects BEFORE rendering.
		// This prevents N+1 query problem when extending relations across multiple entities.
		// Debug level: this fires on EVERY extended list render (hot path).
		$this->logger->debug(
			message: '[RenderObject] [BATCH_PRELOAD] Starting batch preload check',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'extendParam' => $_extend,
				'entityCount' => count($entities),
			]
		);

		if (empty($_extend) === false && empty($entities) === false) {
			$allUuidsToPreload = [];

			// Collect ALL UUIDs from ALL entities that need to be extended.
			foreach ($entities as $entity) {
				if ($entity instanceof \OCA\OpenRegister\Db\ObjectEntity === false) {
					continue;
				}

				$objectData = $entity->getObject();
				if (is_array($objectData) === false) {
					continue;
				}

				// Use the existing collectUuidsForExtend method to extract UUIDs.
				$entityUuids = $this->collectUuidsForExtend(objectData: $objectData, extend: $_extend);
				$allUuidsToPreload = array_merge($allUuidsToPreload, $entityUuids);
			}

			// Remove duplicates and batch preload ALL related objects in ONE query.
			$allUuidsToPreload = array_unique($allUuidsToPreload);

			$this->logger->debug(
				message: '[RenderObject] [BATCH_PRELOAD] UUIDs collected',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuidCount' => count($allUuidsToPreload),
					'sampleUuids' => array_slice($allUuidsToPreload, 0, 3),
				]
			);

			if (empty($allUuidsToPreload) === false) {
				$preloadedObjects = $this->objectCacheService->preloadObjects(
					identifiers: $allUuidsToPreload,
					registerId: $this->sharedRegisterIdOf(entities: $entities)
				);

				// Add preloaded objects to local cache for immediate access during rendering.
				foreach ($preloadedObjects as $object) {
					$this->objectsCache[$object->getUuid()] = $object;
					$this->objectsCache[$object->getId()] = $object;
				}

				$this->logger->debug(
					message: '[RenderObject] Batch preloaded objects for renderEntities',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'entityCount' => count($entities),
						'requestedUuids' => count($allUuidsToPreload),
						'loadedObjects' => count($preloadedObjects),
					]
				);
			}

			// **INVERSE RELATIONSHIP OPTIMIZATION**: Batch preload objects that REFERENCE our entities.
			// This prevents N+1 queries when extending inverse properties like 'contactpersonen'.
			$this->preloadInverseRelationships(entities: $entities, extend: $_extend);
		}//end if

		// Batch-prefetch lightweight @self.files IDs once for the entire page so that
		// renderEntity does not issue one FileMapper query per row when extend does
		// NOT cover @self.files. When extend opts in to full file metadata, the
		// per-entity renderFiles() runs instead and this cache is unused.
		if (self::shouldExtendFiles(extend: $_extend) === false && empty($entities) === false) {
			$uuids = [];
			foreach ($entities as $entity) {
				if ($entity instanceof ObjectEntity === true) {
					$uuid = $entity->getUuid();
					if ($uuid !== null) {
						$uuids[] = $uuid;
					}
				}
			}

			if (empty($uuids) === false) {
				$this->batchFileIdsCache = $this->fileMapper->getFileIdsForObjects($uuids);
			}
		}

		// PERF-1: batch-prefetch full file objects + tags for every file-typed property
		// value across the whole page, so renderFileProperties()/getFileObject() do not
		// issue 1+ queries per file per row. This turns a 20-row x 3-file page from ~120
		// queries into ~3 (one IN(...) files query + one batched tag-id query + one tag
		// resolve). Caches are request-scoped and cleared in the finally below.
		$this->batchPreloadFileObjects(entities: $entities);

		$renderedEntities = [];

		// PERF-8: mark a page render so extendObject() skips per-row re-preloading.
		$this->pageRenderActive = true;

		try {
			// Render each entity (now using warm cache for forward relations).
			foreach ($entities as $entity) {
				$renderedEntity = $this->renderEntity(
					entity: $entity,
					_extend: $_extend,
					depth: 0,
					filter: $_filter,
					fields: $_fields,
					unset: $_unset,
					_rbac: $_rbac,
					_multitenancy: $_multitenancy
				);

				// Remove source from @self in list responses.
				// The source property is only included in individual object responses,
				// not in collection/list responses for cleaner output.
				$renderedEntity->setSource(null);

				$renderedEntities[] = $renderedEntity;
			}
		} finally {
			$this->batchFileIdsCache = null;
			// PERF-1: drop request-scoped file caches after the page render.
			$this->fileObjectCache = [];
			$this->fileTagsCache = [];
			// PERF-8: clear the page-render flag.
			$this->pageRenderActive = false;
		}//end try

		return $renderedEntities;
	}//end renderEntities()

	/**
	 * Batch-preload formatted file objects + tags for an entire page of entities (PERF-1).
	 *
	 * Collects every file id referenced by a file-typed property across all entities,
	 * loads them in a single FileMapper::getFilesByIds() call and resolves their tags in
	 * one batched ISystemTagManager call, then populates the request-scoped
	 * $fileObjectCache / $fileTagsCache so per-row getFileObject()/getFileTags() are
	 * cache hits.
	 *
	 * @param array $entities The page of ObjectEntity instances about to be rendered
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function batchPreloadFileObjects(array $entities): void {
		if (empty($entities) === true) {
			return;
		}

		// 1) Collect all file ids referenced by file-typed properties across the page.
		$allFileIds = [];
		foreach ($entities as $entity) {
			if ($entity instanceof ObjectEntity === false) {
				continue;
			}

			$schema = $this->getSchema(id: $entity->getSchema());
			if ($schema === null) {
				continue;
			}

			$schemaProperties = $schema->getProperties() ?? [];
			$objectData = $entity->getObject();
			if (is_array($objectData) === false) {
				continue;
			}

			foreach ($schemaProperties as $propertyName => $propertyConfig) {
				if (is_array($propertyConfig) === false
					|| $this->isFilePropertyConfig(propertyConfig: $propertyConfig) === false
				) {
					continue;
				}

				$value = ($objectData[$propertyName] ?? null);
				if ($value === null) {
					continue;
				}

				// Base64-format properties read content, not the formatted file
				// object/tags, so they are not part of this prefetch.
				$isArrayProperty = ($propertyConfig['type'] ?? '') === 'array';
				$fileConfig = $propertyConfig;
				if ($isArrayProperty === true) {
					$fileConfig = ($propertyConfig['items'] ?? []);
				}

				if (($fileConfig['format'] ?? '') === 'base64') {
					continue;
				}

				$candidates = $value;
				if (is_array($value) === false) {
					$candidates = [$value];
				}

				foreach ($candidates as $candidate) {
					if (is_numeric($candidate) === true
						|| (is_string($candidate) === true && ctype_digit($candidate) === true)
					) {
						$allFileIds[(int)$candidate] = (int)$candidate;
					}
				}
			}//end foreach
		}//end foreach

		if (empty($allFileIds) === true) {
			return;
		}

		$fileIds = array_values($allFileIds);

		try {
			// 2) Batch-load all files in one query.
			$filesById = $this->fileMapper->getFilesByIds(fileIds: $fileIds);

			// 3) Batch-load all tag ids for all files in one query.
			$stringFileIds = array_map(static fn ($id) => (string)$id, $fileIds);

			// Numeric-string keys are coerced to int by PHP — see the longer
			// note on the other getTagIdsForObjects() call in this class.
			// phpcs:ignore Squiz.Commenting.InlineComment.DocBlock -- PHPStan only reads @var from a /** */ block.
			/** @var array<int|string, list<string>> $allTagIdsPerFile */
			$allTagIdsPerFile = $this->systemTagMapper->getTagIdsForObjects(
				objIds: $stringFileIds,
				objectType: 'files'
			);

			// Resolve all unique tag ids to names in one call.
			$uniqueTagIds = [];
			foreach ($allTagIdsPerFile as $fileTagIds) {
				foreach ($fileTagIds as $tagId) {
					$uniqueTagIds[$tagId] = true;
				}
			}

			$tagNameMap = [];
			if (empty($uniqueTagIds) === false) {
				$tags = $this->systemTagManager->getTagsByIds(tagIds: array_keys($uniqueTagIds));
				foreach ($tags as $tag) {
					$name = $tag->getName();
					if (str_starts_with($name, 'object:') === false) {
						$tagNameMap[$tag->getId()] = $name;
					}
				}
			}

			// 4) Populate the request-scoped tag cache for every requested file id.
			foreach ($stringFileIds as $fileIdStr) {
				$labels = [];
				foreach (($allTagIdsPerFile[$fileIdStr] ?? []) as $tagId) {
					if (isset($tagNameMap[$tagId]) === true) {
						$labels[] = $tagNameMap[$tagId];
					}
				}

				$this->fileTagsCache[$fileIdStr] = $labels;
			}

			// 5) Populate the request-scoped file-object cache (null for missing files).
			foreach ($fileIds as $fileId) {
				$fileIdStr = (string)$fileId;
				$record = ($filesById[$fileIdStr] ?? null);
				if ($record === null) {
					$this->fileObjectCache[$fileIdStr] = null;
					continue;
				}

				$this->fileObjectCache[$fileIdStr] = $this->formatFileRecord(fileRecord: $record);
			}
		} catch (Exception $e) {
			// A prefetch failure must never break rendering — per-row paths will simply
			// fall back to individual lookups (the pre-PERF-1 behaviour).
			$this->logger->warning(
				message: '[RenderObject] Batch file prefetch failed; falling back to per-row lookups',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}//end try
	}//end batchPreloadFileObjects()
}//end class
