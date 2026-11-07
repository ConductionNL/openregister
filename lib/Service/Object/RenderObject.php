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
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationPayload;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCA\OpenRegister\Service\Hinge\LensResolver;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
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
use WeakMap;

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
	 * The inverse relation batch loader, created on first use.
	 *
	 * @var InverseRelationPreload|null
	 */
	private ?InverseRelationPreload $inversePreload = null;

	/**
	 * The inverse property filler, created on first use.
	 *
	 * @var InverseRelationRender|null
	 */
	private ?InverseRelationRender $inverseRender = null;

	/**
	 * The lookups inverse relation rendering shares, created on first use.
	 *
	 * @var InverseRenderSupport|null
	 */
	private ?InverseRenderSupport $inverseSupport = null;

	/**
	 * The read rule of the current render, built on first use.
	 *
	 * @var ExtendTargetReads|null
	 */
	private ?ExtendTargetReads $extendTargetReads = null;

	/**
	 * The entities that went through a render, so `@self.objects` can tell them from loaded ones.
	 *
	 * @var WeakMap<ObjectEntity, true>|null
	 */
	private ?WeakMap $renderedEntities = null;

	/**
	 * The explicit-reveal masker, resolved on first use.
	 *
	 * @var ExplicitRevealMasker|null
	 */
	private ?ExplicitRevealMasker $revealMasker = null;

	/**
	 * Constructor for RenderObject handler.
	 *
	 * @param FileMapperInterface $fileMapper File mapper for database operations.
	 * @param MagicMapperInterface $objectEntityMapper Object entity mapper for database operations.
	 * @param RegisterMapperInterface $registerMapper Register mapper for database operations.
	 * @param SchemaMapperInterface $schemaMapper Schema mapper for database operations.
	 * @param ISystemTagManager $systemTagManager System tag manager for file tags.
	 * @param ISystemTagObjectMapper $systemTagMapper System tag object mapper for file tags.
	 * @param CacheHandlerInterface $objectCacheService Object cache service for optimized loading.
	 * @param PropertyRbacHandler $propertyRbacHandler Property-level RBAC handler.
	 * @param LoggerInterface $logger Logger for performance monitoring.
	 * @param FileService $fileService File service for file operations.
	 * @param ComputedFieldHandler $computedFieldHandler Handler for computed field evaluation.
	 * @param TranslationHandler $translationHandler Handler for translatable property resolution.
	 * @param LinkedEntityEnricher $linkedEntityEnricher Enricher for linked entity metadata.
	 * @param CalculationEvaluatorInterface $calculationEvaluator Evaluator for derived/computed properties.
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
		private readonly FileMapperInterface $fileMapper,
		private readonly MagicMapperInterface $objectEntityMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly ISystemTagManager $systemTagManager,
		private readonly ISystemTagObjectMapper $systemTagMapper,
		private readonly CacheHandlerInterface $objectCacheService,
		private readonly PropertyRbacHandler $propertyRbacHandler,
		private readonly LoggerInterface $logger,
		private readonly FileService $fileService,
		private readonly ComputedFieldHandler $computedFieldHandler,
		private readonly TranslationHandler $translationHandler,
		private readonly LinkedEntityEnricher $linkedEntityEnricher,
		private readonly CalculationEvaluatorInterface $calculationEvaluator,
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
		return UuidFormat::isCanonical(value: $value);
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
		// **PERFORMANCE OPTIMIZATION**: Use CacheHandler for optimized caching.
		// First check local cache for backward compatibility.
		if (($this->objectsCache[$id] ?? null) !== null) {
			return $this->objectsCache[$id];
		}

		if ($this->extendReads()->refused(id: $id) === true) {
			return null;
		}

		// Use cache service for optimized loading (only if not in ultra cache).
		// Its own cache answers without a read check, so the answer is checked here.
		$object = $this->objectCacheService->getObject($id);
		if ($object !== null && $this->readableExtendTargets(objects: [$object]) === []) {
			$this->extendReads()->refuse(id: $id);
			return null;
		}

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
			if (is_string($key) === true && UuidFormat::isCanonical(value: $key) === true) {
				if ($object instanceof ObjectEntity) {
					$result[$key] = $this->renderedCopy(object: $object);
				} elseif (is_array($object) === true) {
					$result[$key] = $object;
				}
			}
		}

		return $result;
	}//end getObjectsCache()

	/**
	 * A cached object as a reader may get it: an object that was only loaded is rendered first.
	 *
	 * @param ObjectEntity $object The cached object.
	 *
	 * @return array The serialized object.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function renderedCopy(ObjectEntity $object): array {
		if (isset($this->renderedEntities[$object]) === true) {
			return $object->jsonSerialize();
		}

		return $this->renderRelated(object: $object);
	}//end renderedCopy()

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
		$uuidByIndex = array_map(static fn ($row) => self::extractRowUuid(row: $row), $rows);
		$idsByUuid = $this->readableFileIdsOf(uuids: $uuidByIndex);

		foreach ($rows as $index => &$row) {
			self::writeFilesToRow(row: $row, ids: ($idsByUuid[$uuidByIndex[$index] ?? ''] ?? []));
		}

		unset($row);
	}//end attachLightweightFilesToRows()

	/**
	 * The readable file IDs of objects, per UUID.
	 *
	 * @param array<int|string, string|null> $uuids The UUIDs; null entries are skipped.
	 *
	 * @return array<string, array<int, int>>
	 */
	private function readableFileIdsOf(array $uuids): array {
		$uniqueUuids = array_values(array_unique(array_filter($uuids, static fn ($uid) => $uid !== null)));
		if (empty($uniqueUuids) === true) {
			return [];
		}

		return $this->readableFileIds(idsByUuid: $this->fileMapper->getFileIdsForObjects($uniqueUuids));
	}//end readableFileIdsOf()

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
			$row = $this->translatedRow(row: $row);
		}

		unset($row);
	}//end resolveTranslationsForRows()

	/**
	 * One list row with its translations resolved; a row of another shape stays as it is.
	 *
	 * @param mixed $row The row.
	 *
	 * @return mixed The row.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function translatedRow(mixed $row): mixed {
		if ($row instanceof ObjectEntity === true) {
			$this->translateEntityRow(row: $row);
			return $row;
		}

		if (is_array($row) === true) {
			return $this->translateArrayRow(row: $row);
		}

		return $row;
	}//end translatedRow()

	/**
	 * Resolve the translations of one entity row; without a known schema it stays as it is.
	 *
	 * @param ObjectEntity $row The row, changed in place.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function translateEntityRow(ObjectEntity $row): void {
		$schema = null;
		if ($row->getSchema() !== null) {
			$schema = $this->getSchema(id: $row->getSchema());
		}

		if ($schema === null) {
			return;
		}

		$register = null;
		if ($row->getRegister() !== null) {
			$register = $this->getRegister(id: $row->getRegister());
		}

		$row->setObject(
			$this->translationHandler->resolveTranslationsForRender(objectData: $row->getObject(), schema: $schema, register: $register)
		);
	}//end translateEntityRow()

	/**
	 * Resolve the translations of one serialized row; without a known schema it stays as it is.
	 *
	 * @param array $row The row.
	 *
	 * @return array The row with its translations resolved.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function translateArrayRow(array $row): array {
		$schema = $this->arrayRowSchema(row: $row);
		if ($schema === null) {
			return $row;
		}

		return $this->translationHandler->resolveTranslationsForRender(
			objectData: $row,
			schema: $schema,
			register: $this->arrayRowRegister(row: $row)
		);
	}//end translateArrayRow()

	/**
	 * The schema a serialized row names in @self.
	 *
	 * @param array $row The row.
	 *
	 * @return Schema|null The schema, or null when the row names none that is known.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function arrayRowSchema(array $row): ?Schema {
		$schemaId = ($row['@self']['schema'] ?? null);
		if (is_int($schemaId) === true || is_string($schemaId) === true) {
			return $this->getSchema(id: $schemaId);
		}

		return null;
	}//end arrayRowSchema()

	/**
	 * The register a serialized row names in @self.
	 *
	 * @param array $row The row.
	 *
	 * @return Register|null The register, or null when the row names none that is known.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function arrayRowRegister(array $row): ?Register {
		$registerId = ($row['@self']['register'] ?? null);
		if (is_int($registerId) === true || is_string($registerId) === true) {
			return $this->getRegister(id: $registerId);
		}

		return null;
	}//end arrayRowRegister()

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
			if ($row instanceof ObjectEntity === true) {
				$this->applyLensToRow(row: $row, rbac: $_rbac);
			}
		}
	}//end applyLensesToRows()

	/**
	 * Resolve one entity row's lenses; a row whose schema declares none stays as it is.
	 *
	 * @param ObjectEntity $row  The row, changed in place.
	 * @param bool         $rbac Whether the reader's access to the referenced object is checked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lenses/spec.md
	 */
	private function applyLensToRow(ObjectEntity $row, bool $rbac): void {
		if ($row->getSchema() === null) {
			return;
		}

		$schema = $this->getSchema(id: $row->getSchema());
		if ($schema === null || $schema->getLenses() === []) {
			return;
		}

		$row->setObject($this->applyLens(schema: $schema, objectData: ($row->getObject() ?? []), rbac: $rbac));
	}//end applyLensToRow()

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
		// decryption ONLY. The writeOnly strip deliberately does not consult it.
		$pass = new RowRedaction();
		$pass->applyGatedStrips = ($_rbac !== false) && (SystemOperationContext::isActive() === false);

		foreach ($rows as &$row) {
			$row = $this->redactedRow(pass: $pass, row: $row);
		}

		unset($row);
	}//end redactWriteOnlyFromRows()

	/**
	 * One row after the redaction pass; a row of another shape stays as it is.
	 *
	 * @param RowRedaction $pass The pass.
	 * @param mixed        $row  The row.
	 *
	 * @return mixed The row.
	 */
	private function redactedRow(RowRedaction $pass, mixed $row): mixed {
		if ($row instanceof ObjectEntity === true) {
			$this->redactEntityRow(pass: $pass, row: $row);
			return $row;
		}

		if (is_array($row) === true) {
			return $this->redactArrayRow(pass: $pass, row: $row);
		}

		return $row;
	}//end redactedRow()

	/**
	 * Record on the pass the schema of a row and which strips apply to it.
	 *
	 * @param RowRedaction $pass     The redaction pass.
	 * @param int|string   $schemaId The row's schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function planRowRedaction(RowRedaction $pass, int|string $schemaId): void {
		$pass->schema = $this->getSchema(id: $schemaId);
		$pass->doWriteOnly = $this->schemaHasWriteOnlyRule(schema: $pass->schema);
		$pass->doGated = ($pass->applyGatedStrips === true && $this->schemaNeedsReadStrip(schema: $pass->schema) === true);
	}//end planRowRedaction()

	/**
	 * Decrypt a row's fields when the gated strips apply and the schema encrypts properties.
	 *
	 * Runs AFTER redaction: a field the strip removed is no longer a key, so
	 * decryptProperties() (which only acts on keys still present) skips it,
	 * and an unauthorized caller never reaches decryption for that field.
	 *
	 * @param RowRedaction $pass The redaction pass, on one row.
	 * @param array        $data The redacted data.
	 *
	 * @return array The data, decrypted where allowed.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function decryptRedactedRow(RowRedaction $pass, array $data): array {
		if ($pass->doGated === true
			&& $this->fieldEncryptionHandler !== null
			&& $pass->schema->hasEncryptedProperties() === true
		) {
			return $this->fieldEncryptionHandler->decryptProperties(data: $data, schema: $pass->schema);
		}

		return $data;
	}//end decryptRedactedRow()

	/**
	 * Redact one ObjectEntity row in place: body, masked reveals and the relations index.
	 *
	 * The gated path uses filterAuthorizedProperties, which strips writeOnly
	 * first and then applies the group-authorization filter. The writeOnly-only
	 * path (admin, `_rbac: false`, system context, or no property authorization)
	 * strips writeOnly unconditionally. Explicit-reveal values leave masked on
	 * every read. The `relations` search index is a separate scalar mirror
	 * surfaced as `@self.relations`, so a writeOnly secret leaks there unless
	 * it is stripped too (#429); encrypted values do not look like references,
	 * so only the writeOnly strip applies to it.
	 *
	 * @param RowRedaction $pass The redaction pass.
	 * @param ObjectEntity $row  The row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function redactEntityRow(RowRedaction $pass, ObjectEntity $row): void {
		$this->planRowRedaction(pass: $pass, schemaId: $row->getSchema());
		if ($pass->doWriteOnly === true || $pass->doGated === true) {
			$this->redactEntityObject(pass: $pass, row: $row);
		}

		$this->maskEntityReveals(entity: $row, schema: $pass->schema);

		$relations = $row->getRelations();
		if ($pass->doWriteOnly === true && is_array($relations) === true) {
			$row->setRelations($this->propertyRbacHandler->stripWriteOnlyProperties(schema: $pass->schema, object: $relations));
		}
	}//end redactEntityRow()

	/**
	 * Filter an entity's object data for the pass, and decrypt what stays.
	 *
	 * @param RowRedaction $pass The pass, planned for this row.
	 * @param ObjectEntity $row  The entity.
	 *
	 * @return void
	 */
	private function redactEntityObject(RowRedaction $pass, ObjectEntity $row): void {
		$object = $row->getObject();
		if (is_array($object) === false) {
			return;
		}

		if ($pass->doGated === true) {
			$read = new EntityRead(entity: $row, schema: $pass->schema);
			$object = $this->filterAuthorizedProperties(read: $read, objectData: $object);
		} else {
			$object = $this->propertyRbacHandler->stripWriteOnlyProperties(schema: $pass->schema, object: $object);
		}

		$row->setObject($this->decryptRedactedRow(pass: $pass, data: $object));
	}//end redactEntityObject()

	/**
	 * Redact one array row: body, `@self` copies of removed properties, masked reveals and `@self.relations`.
	 *
	 * A row without a usable `@self.schema` is returned unchanged.
	 *
	 * @param RowRedaction $pass The redaction pass.
	 * @param array        $row  The row.
	 *
	 * @return array The redacted row.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function redactArrayRow(RowRedaction $pass, array $row): array {
		$schemaId = $row['@self']['schema'] ?? null;
		if (is_int($schemaId) === false && is_string($schemaId) === false) {
			return $row;
		}

		$this->planRowRedaction(pass: $pass, schemaId: $schemaId);
		if ($pass->doWriteOnly === false && $pass->doGated === false) {
			return $this->maskRowReveals(row: $row, schema: $pass->schema);
		}

		$row = $this->decryptRedactedRow(pass: $pass, data: $this->filteredArrayRow(pass: $pass, row: $row));
		return $this->withoutWriteOnlyRelations(pass: $pass, row: $this->maskRowReveals(row: $row, schema: $pass->schema));
	}//end redactArrayRow()

	/**
	 * An array row filtered for the pass, its copies withheld when the pass is gated.
	 *
	 * @param RowRedaction         $pass The pass, planned for this row.
	 * @param array<string, mixed> $row  The row.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function filteredArrayRow(RowRedaction $pass, array $row): array {
		if ($pass->doGated === false) {
			return $this->propertyRbacHandler->stripWriteOnlyProperties(schema: $pass->schema, object: $row);
		}

		$filtered = $this->propertyRbacHandler->filterReadableProperties(schema: $pass->schema, object: $row);
		$removed = $this->removedProperties(before: $row, after: $filtered);
		return $this->withholdRowCopies(row: $filtered, withheld: new WithheldProperties(schema: $pass->schema, removed: $removed));
	}//end filteredArrayRow()

	/**
	 * An array row whose `@self.relations` carry no write-only property.
	 *
	 * @param RowRedaction         $pass The pass, planned for this row.
	 * @param array<string, mixed> $row  The row.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function withoutWriteOnlyRelations(RowRedaction $pass, array $row): array {
		if ($pass->doWriteOnly === true && is_array($row['@self']['relations'] ?? null) === true) {
			$row['@self']['relations'] = $this->propertyRbacHandler->stripWriteOnlyProperties(
				schema: $pass->schema,
				object: $row['@self']['relations']
			);
		}

		return $row;
	}//end withoutWriteOnlyRelations()

	/**
	 * The largest file embedded as base64, in bytes.
	 *
	 * @var int
	 */
	private const MAX_BASE64_BYTES = (10 * 1024 * 1024);

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
	 * @param EntityRead           $read       The entity rendered, with its schema.
	 * @param array<string, mixed> $objectData The rendered body.
	 *
	 * @return array<string, mixed> The body, masked.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	private function maskRenderedReveals(EntityRead $read, array $objectData): array {
		if ($this->masksReveals(schema: $read->schema) === false) {
			return $objectData;
		}

		[$objectData, $masked] = $this->revealMasker()->maskObject(
			schema: $read->schema,
			object: $objectData,
			objectIds: [(string)$read->entity->getUuid()]
		);
		$this->withholdEntityCopies(
			entity: $read->entity,
			withheld: new WithheldProperties(schema: $read->schema, removed: $masked)
		);

		return $objectData;
	}//end maskRenderedReveals()

	/**
	 * Whether a schema has properties that need an explicit reveal, and a masker can hide them.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return bool
	 */
	private function masksReveals(?Schema $schema): bool {
		return $schema !== null
			&& ExplicitReveal::explicitProperties(schema: $schema) !== []
			&& $this->revealMasker() !== null;
	}//end masksReveals()

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
			$read = new EntityRead(entity: $entity, schema: $schema);
			$entity->setObject($this->maskRenderedReveals(read: $read, objectData: $object));
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
		if ($this->masksReveals(schema: $schema) === false) {
			return $row;
		}

		[$row, $masked] = $this->revealMasker()->maskObject(schema: $schema, object: $row);

		return $this->withholdRowCopies(row: $row, withheld: new WithheldProperties(schema: $schema, removed: $masked));
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
	 * @param ObjectEntity       $entity   The rendered entity (mutated in memory only).
	 * @param WithheldProperties $withheld What is withheld.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function withholdEntityCopies(ObjectEntity $entity, WithheldProperties $withheld): void {
		if ($withheld->removed === []) {
			return;
		}

		$relations = $entity->getRelations();
		if (is_array($relations) === true && $relations !== []) {
			$entity->setRelations($this->withoutRelationKeys(relations: $relations, removed: $withheld->removed));
		}

		foreach ($this->withheldMetadataFields(schema: $withheld->schema, removed: $withheld->removed) as $field) {
			$setter = 'set' . ucfirst($field);
			$entity->$setter(null);
		}
	}//end withholdEntityCopies()

	/**
	 * Remove the copies of withheld properties from an array row's `@self`.
	 *
	 * The array-row twin of {@see self::withholdEntityCopies()}.
	 *
	 * @param array<string, mixed> $row      The filtered row.
	 * @param WithheldProperties   $withheld What is withheld.
	 *
	 * @return array<string, mixed> The row with its `@self` copies withheld.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
	 */
	private function withholdRowCopies(array $row, WithheldProperties $withheld): array {
		if ($withheld->removed === [] || is_array($row['@self'] ?? null) === false) {
			return $row;
		}

		$row['@self'] = $this->withheldSelf(meta: $row['@self'], withheld: $withheld);
		return $row;
	}//end withholdRowCopies()

	/**
	 * A row's `@self` without the relations and metadata that copy withheld properties.
	 *
	 * @param array<string, mixed> $meta     The `@self` of the row.
	 * @param WithheldProperties   $withheld What is withheld.
	 *
	 * @return array<string, mixed> The `@self`.
	 */
	private function withheldSelf(array $meta, WithheldProperties $withheld): array {
		if (is_array($meta['relations'] ?? null) === true) {
			$meta['relations'] = $this->withoutRelationKeys(relations: $meta['relations'], removed: $withheld->removed);
		}

		$fields = $this->withheldMetadataFields(schema: $withheld->schema, removed: $withheld->removed);
		foreach (array_intersect($fields, array_keys($meta)) as $field) {
			$meta[$field] = null;
		}

		return $meta;
	}//end withheldSelf()

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
		$candidates = [($row['@self']['id'] ?? null), ($row['@self']['uuid'] ?? null), ($row['id'] ?? null)];
		$uuids = array_filter($candidates, 'is_string');

		return array_shift($uuids);
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
		if (is_string($extend) === true) {
			$extend = array_filter(array_map('trim', explode(',', $extend)));
		}

		$requested = ($extend ?? []);
		return in_array('@self.files', $requested, true) === true || in_array('_files', $requested, true) === true;
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

		$idsByUuid = $this->readableFileIds(idsByUuid: $this->fileMapper->getFileIdsForObjects([$uuid]));
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

		$labelsPerFile = $this->tagLabels(fileIds: array_map(fn ($f) => (string)$f['fileid'], $fileRecords));
		$formattedFiles = [];
		foreach ($fileRecords as $fileRecord) {
			$formattedFiles[] = $this->fileShape(fileRecord: $fileRecord, labels: $labelsPerFile[(string)$fileRecord['fileid']]);
		}

		$object->setFiles($formattedFiles);

		return $object;
	}//end renderFiles()

	/**
	 * The visible tag names of each file, by file id, loaded in two queries for all files.
	 *
	 * @param list<string> $fileIds The file ids
	 *
	 * @return array<string, list<string>>
	 */
	private function tagLabels(array $fileIds): array {
		// OCP declares this `array<string, list<string>>`, but file ids are
		// canonical numeric strings, so PHP coerces every key to INT on the way
		// in; PHP applies the same coercion on lookup, so `$tagIdsPerFile["123"]`
		// finds the row stored under 123.
		// phpcs:ignore Squiz.Commenting.InlineComment.DocBlock -- PHPStan only reads @var from a /** */ block.
		/** @var array<int|string, list<string>> $tagIdsPerFile */
		$tagIdsPerFile = $this->systemTagMapper->getTagIdsForObjects(objIds: $fileIds, objectType: 'files');
		$tagNames = $this->visibleTagNames(tagIdsPerFile: $tagIdsPerFile);

		$labels = [];
		foreach ($fileIds as $fileId) {
			$known = array_filter($tagIdsPerFile[$fileId] ?? [], fn ($tagId) => isset($tagNames[$tagId]));
			$labels[$fileId] = array_values(array_map(fn ($tagId) => $tagNames[$tagId], $known));
		}

		return $labels;
	}//end tagLabels()

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

		$tagIds = $this->systemTagMapper->getTagIdsForObjects(objIds: [$fileId], objectType: 'files');

		// PERF-1: back-fill the request cache.
		$this->fileTagsCache[$fileId] = $this->fileTagNames(tagIds: ($tagIds[$fileId] ?? []));
		return $this->fileTagsCache[$fileId];
	}//end getFileTags()

	/**
	 * The names of tags, without the internal `object:` tags.
	 *
	 * @param array<int, string> $tagIds The tag IDs.
	 *
	 * @return list<string> The names.
	 */
	private function fileTagNames(array $tagIds): array {
		if ($tagIds === []) {
			return [];
		}

		$tags = $this->systemTagManager->getTagsByIds(tagIds: $tagIds);
		$names = array_map(static fn ($tag) => $tag->getName(), $tags);

		return array_values(array_filter($names, static fn ($name) => !str_starts_with($name, 'object:')));
	}//end fileTagNames()

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
			$this->hydrateFilesOf(entity: $entity);
		} catch (Exception $e) {
			// Log error but don't break rendering - just return original entity.
		}//end try

		return $entity;
	}//end renderFileProperties()

	/**
	 * Hydrate the file properties of an entity; without a schema the entity stays as it is.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return void
	 */
	private function hydrateFilesOf(ObjectEntity $entity): void {
		$schema = $this->getSchema(id: $entity->getSchema());
		if ($schema === null) {
			return;
		}

		$fileProperties = array_filter(
			$schema->getProperties() ?? [],
			fn (array $config): bool => $this->isFilePropertyConfig(propertyConfig: $config)
		);
		$objectData = self::withEmptyFileArrays(objectData: $entity->getObject(), fileProperties: $fileProperties);
		$entity->setObject($this->hydrateFileValues(objectData: $objectData, fileProperties: $fileProperties));
	}//end hydrateFilesOf()

	/**
	 * Object data in which every unset file array property is an empty array.
	 *
	 * @param array|null                          $objectData     The object data.
	 * @param array<string, array<string, mixed>> $fileProperties The file properties of the schema.
	 *
	 * @return array|null The object data.
	 */
	private static function withEmptyFileArrays(?array $objectData, array $fileProperties): ?array {
		foreach ($fileProperties as $propertyName => $propertyConfig) {
			if (($propertyConfig['type'] ?? '') === 'array') {
				$objectData[$propertyName] ??= [];
			}
		}

		return $objectData;
	}//end withEmptyFileArrays()

	/**
	 * Object data with its file properties hydrated; `@` properties and `id` stay as they are.
	 *
	 * @param array|null                          $objectData     The object data.
	 * @param array<string, array<string, mixed>> $fileProperties The file properties of the schema.
	 *
	 * @return array|null The object data.
	 */
	private function hydrateFileValues(?array $objectData, array $fileProperties): ?array {
		foreach ($objectData ?? [] as $propertyName => $propertyValue) {
			if (self::isFileKey(key: $propertyName, fileProperties: $fileProperties) === true) {
				$objectData[$propertyName] = $this->hydrateFileProperty(
					propertyValue: $propertyValue,
					propertyConfig: $fileProperties[$propertyName]
				);
			}
		}

		return $objectData;
	}//end hydrateFileValues()

	/**
	 * Whether an object data key names a file property, and is not metadata.
	 *
	 * @param string                              $key            The key.
	 * @param array<string, array<string, mixed>> $fileProperties The file properties of the schema.
	 *
	 * @return bool
	 */
	private static function isFileKey(string $key, array $fileProperties): bool {
		return str_starts_with($key, '@') === false && $key !== 'id' && isset($fileProperties[$key]) === true;
	}//end isFileKey()

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
		$type = ($propertyConfig['type'] ?? '');
		return $type === 'file' || ($type === 'array' && ($propertyConfig['items']['type'] ?? '') === 'file');
	}//end isFilePropertyConfig()

	/**
	 * Hydrates a file property by replacing file IDs with file objects or base64 content.
	 *
	 * If the property config has `format: base64`, returns the file content as a base64
	 * data URI string. Otherwise, returns the file metadata object with URLs.
	 *
	 * @param mixed $propertyValue The property value (file ID or array of file IDs).
	 * @param array $propertyConfig The property configuration from schema.
	 *
	 * @psalm-param   mixed $propertyValue
	 * @psalm-param   array<string, mixed> $propertyConfig
	 * @phpstan-param mixed $propertyValue
	 * @phpstan-param array<string, mixed> $propertyConfig
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
	private function hydrateFileProperty($propertyValue, array $propertyConfig) {
		if (($propertyConfig['type'] ?? '') === 'array') {
			return $this->hydrateFileArray(
				propertyValue: $propertyValue,
				base64: $this->embedsBase64(fileConfig: ($propertyConfig['items'] ?? []))
			);
		}

		if (is_numeric($propertyValue) === false) {
			// Not a file ID.
			return $propertyValue;
		}

		return $this->hydrateFileId(fileId: $propertyValue, base64: $this->embedsBase64(fileConfig: $propertyConfig));
	}//end hydrateFileProperty()

	/**
	 * Whether a file property renders as base64 content.
	 *
	 * PERF-11: never embed base64 file content on a list/collection (page) render:
	 * reading whole files into memory per file per row blows up peak memory. On a
	 * page render the lightweight file reference is used instead; the full base64
	 * content is still produced on single-object GETs (size-capped).
	 *
	 * @param array<string, mixed> $fileConfig The configuration of the file itself.
	 *
	 * @return bool
	 */
	private function embedsBase64(array $fileConfig): bool {
		return ($fileConfig['format'] ?? '') === 'base64' && $this->pageRenderActive === false;
	}//end embedsBase64()

	/**
	 * The files of an array property; a value that is not an array stays as it is.
	 *
	 * @param mixed $propertyValue The file IDs.
	 * @param bool  $base64        Whether to render base64 content.
	 *
	 * @return mixed The readable files.
	 */
	private function hydrateFileArray($propertyValue, bool $base64) {
		if (is_array($propertyValue) === false) {
			return $propertyValue;
		}

		$hydrated = array_map(
			fn ($fileId) => $this->hydrateFileId(fileId: $fileId, base64: $base64),
			array_values($propertyValue)
		);

		return array_values(array_filter($hydrated, static fn ($file): bool => $file !== null));
	}//end hydrateFileArray()

	/**
	 * One file as base64 content or as a file object.
	 *
	 * @param mixed $fileId The file ID.
	 * @param bool  $base64 Whether to render base64 content.
	 *
	 * @return array|string|null The file, or null when it cannot be read.
	 */
	private function hydrateFileId($fileId, bool $base64): array|string|null {
		if ($base64 === true) {
			return $this->getFileAsBase64(fileId: $fileId);
		}

		return $this->getFileObject(fileId: $fileId);
	}//end hydrateFileId()

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
			$file = $this->readableFile(fileId: (int)$fileId);
			if ($file === null || $this->exceedsBase64Cap(file: $file, fileId: (int)$fileId) === true) {
				return null;
			}

			return self::dataUriOf(file: $file);
		} catch (Exception $e) {
			return null;
		}//end try
	}//end getFileAsBase64()

	/**
	 * A file's content as a data URI.
	 *
	 * @param mixed $file The file.
	 *
	 * @return string|null The data URI, or null for a file without content.
	 */
	private static function dataUriOf($file): ?string {
		$fileContent = $file->getContent();
		if ($fileContent === null || $fileContent === '') {
			return null;
		}

		return 'data:' . ($file->getMimeType() ?? 'application/octet-stream') . ';base64,' . base64_encode($fileContent);
	}//end dataUriOf()

	/**
	 * The file behind an ID, when the reader may read it.
	 *
	 * @param int $fileId The file ID.
	 *
	 * @return mixed The file, or null.
	 */
	private function readableFile(int $fileId) {
		if ($fileId <= 0 || $this->withoutRestrictedFiles(fileRecords: [['fileid' => $fileId]]) === []) {
			return null;
		}

		return $this->fileService->getFileById($fileId);
	}//end readableFile()

	/**
	 * Whether a file is over the size embedded as base64.
	 *
	 * PERF-11: a single oversized attachment must not exhaust memory, also on the
	 * single-object path. The caller still has the file reference through the
	 * non-base64 path and @self.files. A size that cannot be read counts as under
	 * the cap, and getContent() decides.
	 *
	 * @param mixed $file   The file.
	 * @param int   $fileId The file ID.
	 *
	 * @return bool
	 */
	private function exceedsBase64Cap($file, int $fileId): bool {
		$fileSize = $this->fileSizeOf(file: $file);
		if ($fileSize <= self::MAX_BASE64_BYTES) {
			return false;
		}

		$this->logger->warning(
			message: '[RenderObject] Refusing to base64-embed oversized file',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'size' => $fileSize, 'cap' => self::MAX_BASE64_BYTES]
		);
		return true;
	}//end exceedsBase64Cap()

	/**
	 * The size of a file.
	 *
	 * @param mixed $file The file.
	 *
	 * @return int The size in bytes, 0 when it cannot be read.
	 */
	private function fileSizeOf($file): int {
		try {
			return (int)$file->getSize();
		} catch (\Throwable $sizeError) {
			$this->logger->debug(
				'[RenderObject] Could not determine file size for base64 cap: ' . $sizeError->getMessage()
			);
			return 0;
		}
	}//end fileSizeOf()

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
			$schema = $this->getSchema(id: $entity->getSchema());
			$imageField = ($schema?->getConfiguration()['objectImageField'] ?? null);
			if (empty($imageField) === false) {
				$value = $this->getValueFromPath(data: $entity->getObject(), path: $imageField);
				$entity->setImage(self::fileUrlOf(value: $value));
			}
		} catch (\Exception $e) {
			// Log error but don't break rendering - just return original entity.
		}//end try

		return $entity;
	}//end hydrateMetadataFromFileProperties()

	/**
	 * The URL of a file object, preferring its download URL.
	 *
	 * @param mixed $value The value of the image field.
	 *
	 * @return mixed The URL, or null for a value that is not a file object.
	 */
	private static function fileUrlOf($value): mixed {
		if (is_array($value) === false) {
			return null;
		}

		return ($value['downloadUrl'] ?? $value['accessUrl'] ?? null);
	}//end fileUrlOf()

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
			if ($this->mayServeFile(fileId: $fileId) === false) {
				return null;
			}

			return $this->cachedFileObject(fileId: (string)$fileId);
		} catch (Exception $e) {
			return null;
		}//end try
	}//end getFileObject()

	/**
	 * Whether a value is a file ID the reader may read.
	 *
	 * The batch cache is the same for every caller; the restriction is not.
	 *
	 * @param mixed $fileId The value.
	 *
	 * @return bool
	 */
	private function mayServeFile(mixed $fileId): bool {
		if (is_numeric($fileId) === false && is_string($fileId) === false) {
			return false;
		}

		return $this->withoutRestrictedFiles(fileRecords: [['fileid' => (string)$fileId]]) !== [];
	}//end mayServeFile()

	/**
	 * A file object from the request-scoped batch cache, back-filling it on a miss (PERF-1).
	 *
	 * @param string $fileId The file ID.
	 *
	 * @return array|null The file object, or null when there is no such file.
	 */
	private function cachedFileObject(string $fileId): ?array {
		if (array_key_exists($fileId, $this->fileObjectCache) === false) {
			$this->fileObjectCache[$fileId] = $this->loadFileObject(fileId: $fileId);
		}

		return $this->fileObjectCache[$fileId];
	}//end cachedFileObject()

	/**
	 * A file's record, formatted.
	 *
	 * @param string $fileId The file ID.
	 *
	 * @return array|null The file object, or null when there is no such file.
	 */
	private function loadFileObject(string $fileId): ?array {
		$fileRecord = $this->fileMapper->getFile((int)$fileId);
		if (empty($fileRecord) === true) {
			return null;
		}

		return $this->formatFileRecord(fileRecord: $fileRecord);
	}//end loadFileObject()

	/**
	 * The file records without the restricted files the caller is not admitted to.
	 *
	 * Without a container (legacy fixtures) nothing is filtered.
	 *
	 * @param array<int, array<string, mixed>> $fileRecords FileMapperInterface records carrying `fileid`.
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
	 * The file ids per object without the restricted files the caller is not admitted to.
	 *
	 * All objects are judged in one lookup, so a page costs one guard call.
	 *
	 * @param array<string, array<int, int>> $idsByUuid The file ids, by object UUID.
	 *
	 * @return array<string, array<int, int>> The readable file ids, by object UUID.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
	 */
	private function readableFileIds(array $idsByUuid): array {
		$records = array_map(
			static fn (int $fileId): array => ['fileid' => $fileId],
			array_merge(...array_values($idsByUuid))
		);
		$readable = array_flip(array_column($this->withoutRestrictedFiles(fileRecords: $records), 'fileid'));

		return array_map(
			static fn (array $fileIds): array => array_values(
				array_filter($fileIds, static fn (int $fileId): bool => isset($readable[$fileId]))
			),
			$idsByUuid
		);
	}//end readableFileIds()

	/**
	 * Format a raw FileMapper file record into the rendered file object shape (PERF-1).
	 *
	 * Shared by getFileObject() and the page-level batch prefetch so both paths emit
	 * an identical structure.
	 *
	 * @param array $fileRecord The raw file record from FileMapperInterface
	 *
	 * @return array The formatted file object (same structure as renderFiles()).
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function formatFileRecord(array $fileRecord): array {
		return $this->fileShape(fileRecord: $fileRecord, labels: $this->getFileTags(fileId: (string)$fileRecord['fileid']));
	}//end formatFileRecord()

	/**
	 * The rendered shape of one file record with its labels.
	 *
	 * @param array<string, mixed> $fileRecord The raw file record from FileMapperInterface
	 * @param list<string>         $labels     The file's tag names
	 *
	 * @return array<string, mixed>
	 */
	private function fileShape(array $fileRecord, array $labels): array {
		return [
			'id' => (string)$fileRecord['fileid'],
			'path' => $fileRecord['path'],
			'title' => $fileRecord['name'],
			'accessUrl' => $fileRecord['accessUrl'] ?? null,
			'downloadUrl' => $fileRecord['downloadUrl'] ?? null,
			...self::fileProperties(fileRecord: $fileRecord),
			'labels' => $labels,
		];
	}//end fileShape()

	/**
	 * The content properties of a file record.
	 *
	 * @param array<string, mixed> $fileRecord The file record.
	 *
	 * @return array<string, mixed>
	 */
	private static function fileProperties(array $fileRecord): array {
		return [
			'type' => $fileRecord['mimetype'] ?? 'application/octet-stream',
			'extension' => pathinfo($fileRecord['name'], PATHINFO_EXTENSION),
			'size' => (int)$fileRecord['size'],
			'hash' => $fileRecord['etag'] ?? '',
			'published' => $fileRecord['published'] ?? null,
			'modified' => $fileRecord['mtime'] ?? null,
		];
	}//end fileProperties()

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

		if ($depth === 0) {
			$this->applyReadRule(rbac: $_rbac, multitenancy: $_multitenancy);
		}

		$context = new RenderContext(
			$_extend, $depth, $filter, self::withIdentityFields(fields: $fields), $unset,
			$registers, $schemas, $objects, self::withVisitedId(entity: $entity, visitedIds: $visitedIds), $_rbac
		);
		$this->primeRenderCaches(context: $context);

		$rendered = $this->renderWithContext(entity: $entity, context: $context);
		$this->renderedEntities ??= new WeakMap();
		$this->renderedEntities[$rendered] = true;

		return $rendered;
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
		$objectData = $this->maskRenderedReveals(read: new EntityRead(entity: $entity, schema: $schema), objectData: $objectData);

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
	 * @param RenderContext $context The render options, with the preloaded registers, schemas and objects
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function primeRenderCaches(RenderContext $context): void {
		self::primeCache(cache: $this->registersCache, entries: $context->registers);
		self::primeCache(cache: $this->schemasCache, entries: $context->schemas);
		self::primeCache(cache: $this->objectsCache, entries: $context->objects);
	}//end primeRenderCaches()

	/**
	 * Add preloaded entries to one render cache.
	 *
	 * @param array      $cache   The cache, changed in place
	 * @param array|null $entries The preloaded entries keyed by id
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function primeCache(array &$cache, ?array $entries): void {
		foreach ($entries ?? [] as $id => $entry) {
			$cache[$id] = $entry;
		}
	}//end primeCache()

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

		return $this->inverseRelations()->handleInversedProperties(entity: $entity, objectData: $objectData);
	}//end applyInversedProperties()

	/**
	 * Set the read rule of a top-level render; what it extends stays under that rule.
	 *
	 * The object caches hold what one rule admitted, so a change empties them.
	 *
	 * @param bool $rbac         False for a trusted read.
	 * @param bool $multitenancy False for a read across organisations.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function applyReadRule(bool $rbac, bool $multitenancy): void {
		if ($this->extendReads()->applyRule(rbac: $rbac, multitenancy: $multitenancy) === true) {
			$this->inverseSupport()->objects->cache->clear();
			$this->objectsCache = [];
		}
	}//end applyReadRule()

	/**
	 * The read rule of the current render.
	 *
	 * @return ExtendTargetReads
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function extendReads(): ExtendTargetReads {
		$this->extendTargetReads ??= new ExtendTargetReads(
			mapper: $this->objectEntityMapper,
			mayRead: fn (ObjectEntity $object): bool => $this->mayReadInverse(object: $object)
		);

		return $this->extendTargetReads;
	}//end extendReads()

	/**
	 * The extend targets the caller may read.
	 *
	 * The preload behind an extend applies no access rule. Filtering here, before the
	 * objects are cached, keeps them out of the extended property and out of
	 * `@self.objects`. Without a container (legacy fixtures) nothing is filtered.
	 *
	 * @param ObjectEntity[] $objects The objects found.
	 *
	 * @return ObjectEntity[] The readable objects.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function readableExtendTargets(array $objects): array {
		if ($this->container === null || $this->extendReads()->checked() === false || SystemOperationContext::isActive() === true) {
			return $objects;
		}

		return $this->extendReads()->readable(objects: $objects);
	}//end readableExtendTargets()

	/**
	 * Keep the readable preloaded objects in the objects cache and note the others as refused.
	 *
	 * @param ObjectEntity[] $objects The objects the preload found.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function cacheExtendTargets(array $objects): void {
		$readable = $this->readableExtendTargets(objects: $objects);
		foreach ($readable as $object) {
			$this->objectsCache[$object->getUuid()] = $object;
			$this->objectsCache[$object->getId()] = $object;
		}

		foreach ($objects as $object) {
			if (in_array($object, $readable, true) === false) {
				$this->extendReads()->refuse(id: (string)$object->getUuid());
			}
		}
	}//end cacheExtendTargets()

	/**
	 * The inverse results the caller may read.
	 *
	 * The lookups filter on organisation only. Filtering here, where the objects are
	 * loaded, keeps them out of the inverse property and out of `@self.objects`.
	 * Without a container (legacy fixtures) nothing is filtered.
	 *
	 * @param ObjectEntity[] $objects The objects that point back.
	 *
	 * @return ObjectEntity[] The readable objects.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function readableInverseObjects(array $objects): array {
		if ($this->container === null || $this->extendReads()->checked() === false) {
			return $objects;
		}

		return array_values(array_filter($objects, fn (ObjectEntity $object): bool => $this->mayReadInverse(object: $object)));
	}//end readableInverseObjects()

	/**
	 * Whether the caller may read one inverse result; a failed check answers no.
	 *
	 * @param ObjectEntity $object The object that points back.
	 *
	 * @return bool True when the caller may read it.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function mayReadInverse(ObjectEntity $object): bool {
		try {
			$schema = $this->getSchema(id: $object->getSchema());
			if ($schema === null) {
				return false;
			}

			$owner = $object->getOwner();
			if (is_string($owner) === false) {
				$owner = null;
			}

			return $this->container->get(PermissionHandler::class)->hasPermission(
				schema: $schema,
				action: 'read',
				objectOwner: $owner,
				object: $object
			);
		} catch (\Throwable $e) {
			$this->logRenderFailure(failure: 'inverse read check failed for', entity: $object, error: $e);
			return false;
		}
	}//end mayReadInverse()

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

		$inversePropertyNames = array_keys($this->inversePreload()->getInversedProperties(schema: $schema));

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
		$extend = self::expandedExtend(extend: $extend, objectData: $objectData);
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
	 * An extend parameter as a list: a string split on commas, `all` in a list expanded.
	 *
	 * @param array|string|null $extend     The extend parameter
	 * @param array             $objectData The object data
	 *
	 * @return array|null
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function expandedExtend(array|string|null $extend, array $objectData): array|null {
		if (is_string($extend) === true) {
			return explode(',', $extend);
		}

		if (is_array($extend) === true && in_array('all', $extend, true) === true) {
			return self::expandExtendAll(extend: $extend, objectData: $objectData);
		}

		return $extend;
	}//end expandedExtend()

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

		return $this->extendObject(entity: $entity, objectData: $objectData, context: $context);
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

		$objectData = (new RecurrenceOccurrences(request: $this->request))->enrich(objectData: $objectData, schema: $schema);
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
			$objectData = $this->filterAuthorizedProperties(read: new EntityRead(entity: $entity, schema: $schema), objectData: $objectData);
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
	 * so the rules read a temporary `@self` built from the record's own metadata,
	 * never one the data carries. The data's own `@self`, if any, is put back
	 * afterwards (jsonSerialize re-adds the record's).
	 *
	 * @param EntityRead $read       The entity being rendered, with its schema
	 * @param array      $objectData The object data
	 *
	 * @return array
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function filterAuthorizedProperties(EntityRead $read, array $objectData): array {
		$schema = $read->schema;
		$filtered = $read->entity->filterAsRecord(
			data: $objectData,
			filter: fn (array $probe): array => $this->propertyRbacHandler->filterReadableProperties(schema: $schema, object: $probe)
		);
		$removed = $this->removedProperties(before: $objectData, after: $filtered);
		$this->withholdEntityCopies(entity: $read->entity, withheld: new WithheldProperties(schema: $schema, removed: $removed));

		return $filtered;
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
			$subject = new LanguageMetaSubject(
				entity: $entity,
				schema: $schema,
				registerDefault: ($register?->getDefaultLanguage() ?? 'nl'),
				preResolveData: $preResolveData
			);
			return $this->attachLanguageMeta(objectData: $objectData, subject: $subject);
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

		$calculations = new VirtualCalculations(evaluator: $this->calculationEvaluator, logger: $this->logger);

		return array_replace(
			$objectData,
			$calculations->values(
				calculations: VirtualCalculations::declaredBy(schema: $schema),
				payload: (new CalculationPayload())->withSelf(data: $objectData, entity: $entity)
			)
		);
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
		$annotations = new RecordAnnotations(logger: $this->logger);
		$annotations->attachRetention(entity: $entity, schema: $schema);
		$annotations->attachDecision(entity: $entity);
		$this->applyRetentionClocks(entity: $entity);
		$annotations->attachExternalLinks(entity: $entity, schema: $schema);
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
			$this->setTranslationCompleteness(entity: $entity, schema: $schema);
		} catch (\Throwable $e) {
			$this->logRenderFailure(failure: 'translation completeness lookup failed for', entity: $entity, error: $e);
		}
	}//end applyTranslationCompleteness()

	/**
	 * Set the completeness the translation status answers, when it has any.
	 *
	 * @param ObjectEntity $entity The rendered entity
	 * @param Schema       $schema The object's schema
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function setTranslationCompleteness(ObjectEntity $entity, Schema $schema): void {
		$completeness = $this->translationStatusService->completenessForObject((string) $entity->getUuid(), $schema);
		if (count($completeness) > 0) {
			$entity->setTranslationCompleteness($completeness);
		}
	}//end setTranslationCompleteness()

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
		if ($schema === null || $entity->getUuid() === null) {
			return;
		}

		try {
			$this->setRegistryState(entity: $entity, schema: $schema);
		} catch (\Throwable $e) {
			$this->logRenderFailure(failure: 'registry subscription state lookup failed for', entity: $entity, error: $e);
		}
	}//end applyRegistryState()

	/**
	 * Set the subscription state of an object whose schema declares a registry.
	 *
	 * @param ObjectEntity $entity The rendered entity
	 * @param Schema       $schema The object's schema
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function setRegistryState(ObjectEntity $entity, Schema $schema): void {
		if ($this->container === null) {
			return;
		}

		$registrySubscriptions = $this->container->get(RegistrySubscriptionService::class);
		if ($registrySubscriptions->annotationFor(schema: $schema) === null) {
			return;
		}

		$registryState = $registrySubscriptions->stateFor((string) $entity->getUuid());
		if ($registryState !== null) {
			$entity->setRegistryState($registryState);
		}
	}//end setRegistryState()

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
	 * Attach `@self.can`: whether the reader may update this object, and run each declared action on it.
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

			$can = $this->container->get(ObjectActionList::class)->canRun(object: $entity, schema: $schema);
			$can['update'] = $permissions->hasPermission(
				schema: $schema,
				action: 'update',
				objectOwner: $owner,
				object: $entity
			);
			$entity->setCan($can);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[RenderObject] update right marker skipped for %s: %s', (string)$entity->getUuid(), $e->getMessage())
			);
		}//end try
	}//end applyUpdateRightMarker()

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
	 * Attach the `_meta.languageMeta` envelope, keeping any existing `_meta` keys.
	 *
	 * Each translatable property gets its served language, source language,
	 * whether the served language is the source, and the workflow status of
	 * its translation in the served language.
	 *
	 * @param array               $objectData The rendered object data
	 * @param LanguageMetaSubject $subject    The entity, schema, register default and raw data
	 *
	 * @return array The object data with the envelope, or unchanged without translatable properties
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	private function attachLanguageMeta(array $objectData, LanguageMetaSubject $subject): array {
		$languageMeta = $this->languageMetaFor(subject: $subject);
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
	 * The language meta of every translatable property of the subject.
	 *
	 * The source language comes from the object body override, then the schema
	 * property, then the register default ('nl' without a register).
	 *
	 * @param LanguageMetaSubject $subject The entity, schema, register default and raw data
	 *
	 * @return array<string, array{served: string, sourceLanguage: string, isSource: bool, status: string}>
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	private function languageMetaFor(LanguageMetaSubject $subject): array {
		$translatableProps = $this->translationHandler->getTranslatableProperties($subject->schema);
		if (count($translatableProps) === 0) {
			return [];
		}

		$served = $this->languageService->getPreferredLanguage();
		$languageMeta = [];
		foreach ($translatableProps as $property) {
			$sourceLanguage = $this->resolveSourceLanguageForProperty(
				schema: $subject->schema,
				preResolveData: $subject->preResolveData,
				property: $property,
				registerDefault: $subject->registerDefault
			);
			$languageMeta[$property] = $this->languageEntry(served: $served, sourceLanguage: $sourceLanguage);
		}

		return $this->withSidecarStatus(languageMeta: $languageMeta, uuid: (string) ($subject->entity->getUuid() ?? ''));
	}//end languageMetaFor()

	/**
	 * The language meta of one property before its sidecar status is known.
	 *
	 * @param string $served         The served language
	 * @param string $sourceLanguage The property's source language
	 *
	 * @return array{served: string, sourceLanguage: string, isSource: bool, status: string}
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	private function languageEntry(string $served, string $sourceLanguage): array {
		return ['served' => $served, 'sourceLanguage' => $sourceLanguage, 'isSource' => ($served === $sourceLanguage), 'status' => 'source'];
	}//end languageEntry()

	/**
	 * Set the status of every property served in another language than its
	 * source from the translation sidecar: the row's status (draft when unset),
	 * or 'missing' when there is no row or it cannot be read.
	 *
	 * Without a uuid every status stays 'source'.
	 *
	 * @param array  $languageMeta The language meta per property
	 * @param string $uuid         The object uuid
	 *
	 * @return array The language meta with sidecar statuses
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	private function withSidecarStatus(array $languageMeta, string $uuid): array {
		foreach ($languageMeta as $property => $entry) {
			if ($uuid === '' || $entry['isSource'] === true) {
				continue;
			}

			try {
				$row = $this->translationMapper->findOne($uuid, $property, $entry['served']);
			} catch (\Throwable $e) {
				$row = null;
			}

			$languageMeta[$property]['status'] = $this->translationRowStatus(row: $row);
		}

		return $languageMeta;
	}//end withSidecarStatus()

	/**
	 * The workflow status of a sidecar translation row: its status (draft when
	 * unset), or 'missing' without a row.
	 *
	 * @param Translation|null $row The sidecar row
	 *
	 * @return string The status
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	private function translationRowStatus(?Translation $row): string {
		if ($row === null) {
			return 'missing';
		}

		return (string) ($row->getStatus() ?? Translation::STATUS_DRAFT);
	}//end translationRowStatus()

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
		$rule = new PropertySourceLanguage(schema: $schema, registerDefault: $registerDefault);
		return $rule->forProperty(body: $preResolveData, property: $property);
	}//end resolveSourceLanguageForProperty()

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
		$run = new ExtendDotRun();
		$run->data = new Dot($objectData);
		$run->depth = $depth;
		if ($depth >= 10) {
			return $run->data->all();
		}

		foreach (self::takeWildcardExtends(extend: $_extend) as $root => $extends) {
			$run->key = (string)$root;
			$run->keyExtends = $extends;
			$this->extendWildcardRoot(run: $run);
		}

		return $run->data->all();
	}//end handleWildcardExtends()

	/**
	 * Take the wildcard entries off an extend list, grouped by the list property they apply to.
	 *
	 * @param array $extend The extend list; its wildcard entries leave it.
	 *
	 * @return array<string, array> The extends below each root, keeping a named override's path.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function takeWildcardExtends(array &$extend): array {
		$wildcardExtends = array_filter($extend, fn (string $key) => str_contains($key, '.$.'));

		$extendedRoots = [];
		foreach ($wildcardExtends as $key => $wildcardExtend) {
			unset($extend[$key]);

			[$root, $extends] = explode(separator: '.$.', string: $wildcardExtend, limit: 2);

			if (is_numeric($key) === true) {
				$extendedRoots[$root][] = $extends;
				continue;
			}

			[$root, $path] = explode(separator: '.$.', string: $key, limit: 2);
			$extendedRoots[$root][$path] = $extends;
		}

		return $extendedRoots;
	}//end takeWildcardExtends()

	/**
	 * Extend every entry of the list at the run's key with the key's nested extends.
	 *
	 * @param ExtendDotRun $run The extend pass, on one wildcard root.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendWildcardRoot(ExtendDotRun $run): void {
		$data = $run->data->get(key: $run->key);
		if (is_iterable($data) === false) {
			return;
		}

		foreach ($data as $key => $date) {
			$tmpExtends = $run->keyExtends;
			$data[$key] = $this->handleExtendDot(data: $date, _extend: $tmpExtends, depth: $run->depth);
		}

		$run->data->set($run->key, $data);
	}//end extendWildcardRoot()

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

		$run = new ExtendDotRun();
		$run->data = new Dot($data);
		$run->depth = $depth;
		$run->allFlag = $allFlag;
		$run->visitedIds = $visitedIds;

		foreach ($_extend as $override => $key) {
			// Skip keys that are absent, and special '@' fields.
			if ($run->data->has(keys: $key) === false || str_starts_with($key, '@') === true) {
				continue;
			}

			$run->override = $override;
			$run->key = $key;
			$run->descriptor = ($exposures[$key] ?? null);
			$run->keyExtends = array_map(
				fn (string $extendedKey) => substr(string: $extendedKey, offset: strlen($key) + 1),
				array_filter(
					$_extend,
					fn (string $singleKey) => str_starts_with(haystack: $singleKey, needle: $key . '.')
				)
			);
			$this->extendDotKey(run: $run);
		}

		return $run->data->jsonSerialize();
	}//end handleExtendDot()

	/**
	 * Extend the value at the run's key: a list of references or a single one.
	 *
	 * @param ExtendDotRun $run The extend pass, on one key.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendDotKey(ExtendDotRun $run): void {
		$value = $run->data->get(key: $run->key);

		// Make sure arrays are arrays.
		if ($value instanceof Dot) {
			$value = $value->jsonSerialize();
		}

		if ($value === null) {
			return;
		}

		if (is_array($value) === true) {
			$this->extendDotList(run: $run, value: $value);
			return;
		}

		$this->extendDotSingle(run: $run, value: $value);
	}//end extendDotKey()

	/**
	 * Put an extended value where the run's extend entry sends it: its non-numeric list key, else the dot path.
	 *
	 * @param ExtendDotRun $run   The extend pass, on one key.
	 * @param mixed        $value The extended value.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function storeExtended(ExtendDotRun $run, mixed $value): void {
		if (is_numeric($run->override) === false) {
			$run->data->set(keys: $run->override, value: $value);
			return;
		}

		$run->data->set(keys: $run->key, value: $value);
	}//end storeExtended()

	/**
	 * Extend a list of references, skipping nulls and '@' values, and reset its keys.
	 *
	 * @param ExtendDotRun $run   The extend pass, on one key.
	 * @param array        $value The list at the key.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendDotList(ExtendDotRun $run, array $value): void {
		$value = array_filter(
			$value,
			fn ($v) => $v !== null
				&& (is_string($v) === false || str_starts_with(haystack: $v, needle: '@') === false)
		);
		$renderedValue = array_map(
			fn ($identifier) => $this->extendDotListItem(run: $run, identifier: $identifier),
			$value
		);

		self::storeExtended(run: $run, value: array_values(array_filter($renderedValue, fn ($v) => $v !== null)));
	}//end extendDotList()

	/**
	 * Extend one entry of a list of references.
	 *
	 * An entry that is already an extended object (it has 'id' or '@self')
	 * still gets the link exposure: an extend that arrives pre-rendered is the
	 * same far record reached through the same link, and skipping the
	 * projection would be a control any caller can step around by asking for
	 * the wildcard form. Projecting twice is harmless. A reference whose object
	 * is not found keeps its original identifier, so imported external
	 * references stay intact.
	 *
	 * @param ExtendDotRun $run        The extend pass, on one key.
	 * @param mixed        $identifier The list entry.
	 *
	 * @return mixed The rendered entry, the kept identifier, or null to drop it.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendDotListItem(ExtendDotRun $run, mixed $identifier): mixed {
		if (is_array($identifier) === true) {
			return $this->exposedListItem(run: $run, item: $identifier);
		}

		$object = $this->getObject(id: $identifier);
		if ($object === null) {
			$this->logExtendMiss(
				message: '[RenderObject] Object not found in preloaded cache - preserving original UUID',
				context: ['identifier' => $identifier, 'context' => 'extend_array_processing']
			);
			return $identifier;
		}

		if (in_array($object->getUuid(), $run->visitedIds, true) === true) {
			return ['@circular' => true, 'id' => $object->getUuid()];
		}

		return $this->renderExtendedObject(run: $run, object: $object);
	}//end extendDotListItem()

	/**
	 * A list entry that is already an array: an extended object gets the link exposure, anything else is dropped.
	 *
	 * @param ExtendDotRun $run  The extend pass, on one key.
	 * @param array        $item The list entry.
	 *
	 * @return array|null The projected entry, or null to drop it.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function exposedListItem(ExtendDotRun $run, array $item): ?array {
		if (isset($item['id']) === true || isset($item['@self']) === true) {
			return $this->applyLinkExposure(rendered: $item, descriptor: $run->descriptor);
		}

		return null;
	}//end exposedListItem()

	/**
	 * Log a reference the extend could not find.
	 *
	 * @param string $message The log message.
	 * @param array  $context The identifier and the extend path it was on.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function logExtendMiss(string $message, array $context): void {
		$this->logger->debug(message: $message, context: ['file' => __FILE__, 'line' => __LINE__] + $context);
	}//end logExtendMiss()

	/**
	 * Render an extended object one level deeper, with the key's nested extends and link exposure.
	 *
	 * @param ExtendDotRun $run    The extend pass, on one key.
	 * @param ObjectEntity $object The object to render.
	 *
	 * @return array The rendered object.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function renderExtendedObject(ExtendDotRun $run, ObjectEntity $object): array {
		$subExtend = $run->keyExtends;
		if ($run->allFlag === true) {
			$subExtend = array_merge(['all'], $run->keyExtends);
		}

		$rendered = $this->renderEntity(
			entity: $object,
			_extend: $subExtend,
			depth: $run->depth + 1,
			filter: [],
			fields: [],
			unset: [],
			visitedIds: $run->visitedIds
		)->jsonSerialize();

		return $this->applyLinkExposure(rendered: $rendered, descriptor: $run->descriptor);
	}//end renderExtendedObject()

	/**
	 * Extend a single reference, by UUID or by URL ending in it.
	 *
	 * Values starting with '@' or '_' are left alone. An object that was not
	 * preloaded is skipped rather than queried, to prevent N+1 queries.
	 *
	 * @param ExtendDotRun $run   The extend pass, on one key.
	 * @param mixed        $value The value at the key.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendDotSingle(ExtendDotRun $run, mixed $value): void {
		if (self::isReservedValue(value: $value) === true) {
			return;
		}

		$value = self::identifierOf(value: $value);

		$object = $this->getObject(id: $value);
		if ($object === null) {
			$this->logExtendMiss(
				message: '[RenderObject] Single object not found in preloaded cache - skipping to prevent N+1 query',
				context: ['identifier' => $value, 'context' => 'extend_single_processing']
			);
			return;
		}

		// A single reference is rendered even when it closes a cycle; the cycle marker then replaces it.
		$rendered = $this->renderExtendedObject(run: $run, object: $object);
		if (in_array($object->getUuid(), $run->visitedIds, true) === true) {
			$rendered = ['@circular' => true, 'id' => $object->getUuid()];
		}

		self::storeExtended(run: $run, value: $rendered);
	}//end extendDotSingle()

	/**
	 * Whether a value is a string starting with '@' or '_', which an extend leaves alone.
	 *
	 * @param mixed $value The value at the key.
	 *
	 * @return bool
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function isReservedValue(mixed $value): bool {
		return is_string($value) === true
			&& (str_starts_with(haystack: $value, needle: '@') === true || str_starts_with(haystack: $value, needle: '_') === true);
	}//end isReservedValue()

	/**
	 * The identifier a value names: the last path segment of a URL, else the value itself.
	 *
	 * @param mixed $value The value at the key.
	 *
	 * @return mixed The identifier.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function identifierOf(mixed $value): mixed {
		if (filter_var($value, FILTER_VALIDATE_URL) === false) {
			return $value;
		}

		$pathExploded = explode('/', parse_url($value, PHP_URL_PATH));

		return end($pathExploded);
	}//end identifierOf()


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
	 * (D-4). The record reached this point through the read check of the extend,
	 * and the readable set is whatever survived `renderEntity()`, which has
	 * already run the far schema's own property rules through
	 * `PropertyRbacHandler`. This takes that answer as its argument and
	 * intersects the declared set with it, so a link never shows more than the
	 * reader may read on the far record.
	 *
	 * `id` is the render envelope and is never withheld. `@self` keeps what
	 * {@see LinkExposure::envelope()} leaves of it.
	 *
	 * @param array<string, mixed>      $rendered   The far record as renderEntity answered it.
	 * @param array<string, mixed>|null $descriptor The relation descriptor, or null when the link declares none.
	 *
	 * @return array<string, mixed> The projection.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/row-field-level-security/spec.md
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
			if ($property === 'id' || str_starts_with($property, '@') === true) {
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

		if (is_array($envelope['@self'] ?? null) === true) {
			$envelope['@self'] = $this->linkExposure->envelope(self: $envelope['@self'], shown: $projected);
		}

		return array_merge($projected, $envelope);
	}//end applyLinkExposure()

	/**
	 * Extends an object with additional data based on the extension configuration
	 *
	 * @param ObjectEntity  $entity     The entity to extend
	 * @param array         $objectData Current object data
	 * @param RenderContext $context    The render options: the extend list, the depth and the visited ids
	 *
	 * @return array The extended object data
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendObject(ObjectEntity $entity, array $objectData, RenderContext $context): array {
		$_extend = $context->extend;

		// Add register and schema context to @self if requested.
		if (in_array('@self.register', $_extend) === true || in_array('@self.schema', $_extend) === true) {
			$objectData['@self'] = array_replace(
				($objectData['@self'] ?? []),
				$this->extendedSelf(entity: $entity, extend: $_extend)
			);
		}

		// Object-source relations first: a property whose `$ref` targets a
		// schema served by an object-source provider must resolve through THAT
		// provider — its raw key (often a plain integer) would otherwise be
		// looked up against native object storage and can match an unrelated
		// object entirely. Resolved keys are removed from $_extend so the
		// generic path below never sees them.
		$run = self::sourceRefRun(entity: $entity, context: $context);
		$run->objectData = $objectData;
		$this->extendObjectSourceRefs(run: $run);

		$this->preloadOwnExtendTargets(run: $run);

		return $this->handleExtendDot(
			data: $run->objectData,
			_extend: $run->extend,
			depth: $context->depth,
			allFlag: in_array('all', $run->extend, true),
			visitedIds: $context->visitedIds,
			exposures: $this->exposuresFor(schema: $this->getSchema(id: $entity->getSchema()))
		);
	}//end extendObject()

	/**
	 * The object-source pass for an entity's extend list.
	 *
	 * @param ObjectEntity  $entity  The entity being extended.
	 * @param RenderContext $context The render options.
	 *
	 * @return SourceRefRun The pass, without the object data.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private static function sourceRefRun(ObjectEntity $entity, RenderContext $context): SourceRefRun {
		$run = new SourceRefRun();
		$run->entity = $entity;
		$run->extend = $context->extend;
		$run->depth = $context->depth;
		$run->visitedIds = ($context->visitedIds ?? []);

		return $run;
	}//end sourceRefRun()

	/**
	 * Batch preload all UUIDs the extend will resolve, in a SINGLE database query.
	 *
	 * During a full page render renderEntities() has already batch-preloaded every
	 * extend UUID for the whole page into $objectsCache, so depth 0 skips it while a
	 * page render is active; nested (depth > 0) extends still preload their own children.
	 *
	 * @param SourceRefRun $run The finished object-source pass.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function preloadOwnExtendTargets(SourceRefRun $run): void {
		if ($this->pageRenderActive === true && $run->depth === 0) {
			return;
		}

		$this->preloadExtendTargets(
			entity: $run->entity,
			uuids: $this->collectUuidsForExtend(objectData: $run->objectData, extend: $run->extend)
		);
	}//end preloadOwnExtendTargets()

	/**
	 * The register and schema of an entity that its extend list asks to put on @self.
	 *
	 * @param ObjectEntity $entity The entity being extended.
	 * @param array        $extend The extend list.
	 *
	 * @return array<string, array> The serialized register and schema, each only when asked for and found.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extendedSelf(ObjectEntity $entity, array $extend): array {
		$self = [];
		if (in_array('@self.register', $extend) === true) {
			$register = $this->getRegister(id: $entity->getRegister());
			if ($register !== null) {
				$self['register'] = $register->jsonSerialize();
			}
		}

		if (in_array('@self.schema', $extend) === true) {
			$schema = $this->getSchema(id: $entity->getSchema());
			if ($schema !== null) {
				$self['schema'] = $schema->jsonSerialize();
			}
		}

		return $self;
	}//end extendedSelf()

	/**
	 * Load the objects an extend will resolve in one query and keep them in the render's object cache.
	 *
	 * @param ObjectEntity $entity The entity being extended; its register scopes the load.
	 * @param array        $uuids  The identifiers to load.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function preloadExtendTargets(ObjectEntity $entity, array $uuids): void {
		if (empty($uuids) === true) {
			return;
		}

		$preloadedObjects = $this->objectCacheService->preloadObjects(
			identifiers: $uuids,
			registerId: $this->registerIdOf(entity: $entity)
		);
		$this->cacheExtendTargets(objects: $preloadedObjects);

		$this->logger->debug(
			message: '[RenderObject] Batch preloaded objects for extend',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'requestedUuids' => count($uuids),
				'loadedObjects' => count($preloadedObjects),
			]
		);
	}//end preloadExtendTargets()

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
		$registerIds = [];
		foreach ($entities as $entity) {
			if ($entity instanceof ObjectEntity === true) {
				$registerIds[] = $this->registerIdOf(entity: $entity);
			}
		}

		$distinct = array_values(array_unique($registerIds));
		if (count($distinct) !== 1) {
			return null;
		}

		return $distinct[0];
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
	 * @param SourceRefRun $run The pass over an entity; resolved references land in its data, their keys leave its extends.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function extendObjectSourceRefs(SourceRefRun $run): void {
		if ($this->objectSourceRegistry === null || $run->extend === []) {
			return;
		}

		$schema = $this->getSchema(id: $run->entity->getSchema());
		foreach (($schema?->getProperties() ?? []) as $name => $definition) {
			if ($this->isSourceRefProperty(run: $run, property: [$name => $definition]) === true) {
				$this->resolveSourceRefProperty(run: $run, name: $name);
			}
		}
	}//end extendObjectSourceRefs()

	/**
	 * Whether an extended property references an enabled object source.
	 *
	 * @param SourceRefRun $run      The pass; receives the target, provider, config and register.
	 * @param array        $property The property definition, keyed by the property name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function isSourceRefProperty(SourceRefRun $run, array $property): bool {
		$definition = reset($property);

		return is_array($definition) === true
			&& in_array(key($property), $run->extend, true) === true
			&& $this->sourceRefTarget(run: $run, definition: $definition) === true;
	}//end isSourceRefProperty()

	/**
	 * Find the enabled object-source provider a property's `$ref` points at, and the entity's register.
	 *
	 * An INT is as valid a `$ref` as a slug: ImportHandler rewrites a slug
	 * `$ref` to the resolved schema id, and getSchema() accepts both.
	 *
	 * @param SourceRefRun $run        The pass; receives the target, provider, config and register.
	 * @param array        $definition The property definition.
	 *
	 * @return bool True when the property references an enabled object source in a known register.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function sourceRefTarget(SourceRefRun $run, array $definition): bool {
		$ref = ($definition['$ref'] ?? ($definition['items']['$ref'] ?? null));
		if (self::isSchemaRef(ref: $ref) === false) {
			return false;
		}

		$run->target = $this->getSchema(id: $ref);
		$objectSource = $run->target?->getObjectSource();
		if ($objectSource === null) {
			return false;
		}

		$run->provider = $this->objectSourceRegistry->get((string)$objectSource['provider']);
		if ($run->provider?->isEnabled() !== true) {
			return false;
		}

		$run->register ??= $this->getRegister(id: $run->entity->getRegister());
		$run->config = ($objectSource['config'] ?? []);
		return $run->register !== null;
	}//end sourceRefTarget()

	/**
	 * Whether a `$ref` value can name a schema: an integer or a non-empty string.
	 *
	 * @param mixed $ref The `$ref` value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private static function isSchemaRef(mixed $ref): bool {
		return is_int($ref) === true || (is_string($ref) === true && $ref !== '');
	}//end isSchemaRef()

	/**
	 * Resolve one property's raw external keys through its provider, and take the property off the extend list.
	 *
	 * An associative array is an object already embedded by an earlier extend
	 * pass, so its values are never treated as ids. The generic extend path
	 * must never see the key: a raw external key resolved against native
	 * storage can match a foreign object.
	 *
	 * @param SourceRefRun $run  The pass, on one property's target.
	 * @param mixed        $name The property name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function resolveSourceRefProperty(SourceRefRun $run, mixed $name): void {
		$value = ($run->objectData[$name] ?? null);
		if (is_array($value) === true) {
			if (array_is_list($value) === true) {
				$run->objectData[$name] = array_map(fn ($identifier) => $this->resolveSourceRef(run: $run, identifier: $identifier), $value);
			}
		} elseif ($value !== null) {
			$run->objectData[$name] = $this->resolveSourceRef(run: $run, identifier: $value);
		}

		$run->extend = array_values(
			array_filter(
				$run->extend,
				fn ($key) => ((string)$key) !== $name
					&& str_starts_with((string)$key, $name . '.') === false
			)
		);
	}//end resolveSourceRefProperty()

	/**
	 * Render the external row a raw key names, or keep the raw key when it is not scalar or the row is absent.
	 *
	 * @param SourceRefRun $run        The pass, on one property's target.
	 * @param mixed        $identifier The raw key.
	 *
	 * @return mixed The rendered row, or the raw key.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	private function resolveSourceRef(SourceRefRun $run, mixed $identifier): mixed {
		if (is_scalar($identifier) === false) {
			return $identifier;
		}

		$related = $run->provider->find(
			register: $run->register,
			schema: $run->target,
			id: (string)$identifier,
			config: $run->config
		);
		if ($related === null) {
			return $identifier;
		}

		return $this->renderEntity(
			entity: $related,
			_extend: [],
			depth: ($run->depth + 1),
			filter: [],
			fields: [],
			unset: [],
			visitedIds: $run->visitedIds
		)->jsonSerialize();
	}//end resolveSourceRef()

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
			// Get the base property name (before any dots for nested extends).
			$baseProp = explode('.', $key)[0];

			// Skip special keys.
			if (str_starts_with($key, '@') === false && $dataDot->has($baseProp) === true) {
				$uuids = array_merge($uuids, $this->uuidsIn(value: $dataDot->get($baseProp)));
			}
		}

		return array_unique($uuids);
	}//end collectUuidsForExtend()

	/**
	 * The UUIDs in a property value: a single one, or the ones in a list.
	 *
	 * Validates with a regex to support non-RFC 4122 compliant UUIDs (e.g.,
	 * GEMMA ArchiMate UUIDs which have non-standard variant bits).
	 *
	 * @param mixed $value The property value.
	 *
	 * @return array The UUIDs.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function uuidsIn(mixed $value): array {
		$candidates = [$value];
		if (is_array($value) === true) {
			$candidates = $value;
		}

		return array_values(
			array_filter($candidates, fn ($item) => is_string($item) === true && $this->isUuidLike(value: $item) === true)
		);
	}//end uuidsIn()

	/**
	 * The inverse relation batch loader.
	 *
	 * @return InverseRelationPreload
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function inversePreload(): InverseRelationPreload {
		$this->inversePreload ??= new InverseRelationPreload(support: $this->inverseSupport());

		return $this->inversePreload;
	}//end inversePreload()

	/**
	 * The inverse property filler.
	 *
	 * @return InverseRelationRender
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function inverseRelations(): InverseRelationRender {
		$this->inverseRender ??= new InverseRelationRender(
			support: $this->inverseSupport(),
			preload: $this->inversePreload()
		);

		return $this->inverseRender;
	}//end inverseRelations()

	/**
	 * The lookups inverse relation rendering takes from this renderer.
	 *
	 * @return InverseRenderSupport
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function inverseSupport(): InverseRenderSupport {
		if ($this->inverseSupport !== null) {
			return $this->inverseSupport;
		}

		$this->inverseSupport = new InverseRenderSupport(
			mapper: $this->objectEntityMapper,
			logger: $this->logger,
			schemas: new InverseSchemaLookups(
				find: fn (int|string $id): ?Schema => $this->getSchema(id: $id),
				resolveId: fn (string $ref): string => $this->resolveSchemaReference(schemaRef: $ref)
			),
			objects: new InverseObjectHooks(
				cache: new InverseRelationCache(
					remember: function (ObjectEntity $object): void {
						$this->objectsCache[$object->getUuid()] = $object;
					}
				),
				render: fn (ObjectEntity $object): array => $this->renderRelated(object: $object),
				rememberBehind: fn (array $objects) => $this->cacheReferencingObjects(objects: $objects),
				readable: fn (array $objects): array => $this->readableInverseObjects(objects: $objects)
			),
		);

		return $this->inverseSupport;
	}//end inverseSupport()

	/**
	 * Render a related object to its full data, as a regular _extend does.
	 *
	 * @param ObjectEntity $object The related object.
	 *
	 * @return array The rendered data.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function renderRelated(ObjectEntity $object): array {
		return $this->renderEntity(entity: $object, _extend: [], depth: 1, filter: [], fields: [], unset: [])->jsonSerialize();
	}//end renderRelated()

	/**
	 * Put referencing objects with a UUID into the objects cache, ahead of what it already holds.
	 *
	 * @param ObjectEntity[] $objects The referencing objects.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cacheReferencingObjects(array $objects): void {
		$objectsToCache = [];
		foreach ($objects as $object) {
			if ($object->getUuid() !== null) {
				$objectsToCache[$object->getUuid()] = $object;
			}
		}

		$this->objectsCache = array_merge($objectsToCache, $this->objectsCache);
	}//end cacheReferencingObjects()

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

		// If all else fails, try to use the reference as-is.
		return ($this->schemaIdByUuid(reference: $cleanSchemaRef)
			?? $this->schemaIdByPath(reference: $cleanSchemaRef)
			?? $this->schemaIdBySlug(slug: $cleanSchemaRef)
			?? $cleanSchemaRef);
	}//end resolveSchemaReference()

	/**
	 * The id of the schema a UUID names.
	 *
	 * @param string $reference The schema reference.
	 *
	 * @return string|null The id, or null when the reference is no UUID or no schema answers to it.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function schemaIdByUuid(string $reference): ?string {
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) !== 1) {
			return null;
		}

		try {
			return (string)$this->schemaMapper->find($reference)->getId();
		} catch (\Exception $e) {
			return null;
		}
	}//end schemaIdByUuid()

	/**
	 * The id of the schema a JSON Schema path names by its last segment (e.g. "#/components/schemas/organisatie").
	 *
	 * Bypasses RBAC and multi-tenancy: this is an internal schema resolution.
	 *
	 * @param string $reference The schema reference.
	 *
	 * @return string|null The id, or null when the reference is no path or no slug matches, ignoring case.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function schemaIdByPath(string $reference): ?string {
		if (str_contains($reference, '/') === false) {
			return null;
		}

		// Remove any file extension or fragment.
		$lastSegment = preg_replace('/\.[^.]*$/', '', basename($reference));
		$lastSegment = strtolower(preg_replace('/#.*$/', '', $lastSegment));

		try {
			$schemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			return null;
		}

		$matches = array_filter($schemas, static fn ($schema) => strtolower($schema->getSlug() ?? '') === $lastSegment);
		if ($matches === []) {
			return null;
		}

		return (string)reset($matches)->getId();
	}//end schemaIdByPath()

	/**
	 * The id of the one schema with a slug.
	 *
	 * Bypasses RBAC and multi-tenancy: this is an internal schema resolution.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string|null The id, or null unless exactly one schema has the slug.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function schemaIdBySlug(string $slug): ?string {
		$schemas = $this->schemaMapper->findAll(filters: ['slug' => $slug], _rbac: false, _multitenancy: false);
		if (count($schemas) === 1) {
			return (string)array_shift($schemas)->getId();
		}

		return null;
	}//end schemaIdBySlug()

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
		if (is_string($_extend) === true) {
			$_extend = explode(',', $_extend);
		}

		$page = new PageRender();
		$page->extend = ($_extend ?? []);
		$page->fields = self::trimmedList(value: $_fields);
		$page->filter = self::trimmedList(value: $_filter);
		$page->unset = self::trimmedList(value: $_unset);
		$page->rbac = $_rbac;
		$page->multitenancy = $_multitenancy;

		// Debug level: this fires on EVERY extended list render (hot path).
		$this->logger->debug(
			message: '[RenderObject] [BATCH_PRELOAD] Starting batch preload check',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'extendParam' => $page->extend,
				'entityCount' => count($entities),
			]
		);

		$this->applyReadRule(rbac: $page->rbac, multitenancy: $page->multitenancy);

		// Batch preload ALL related objects BEFORE rendering, to prevent N+1 queries.
		if (empty($page->extend) === false && empty($entities) === false) {
			$this->preloadExtendedRelations(entities: $entities, extend: $page->extend);
		}

		$this->prefetchPageFileIds(entities: $entities, extend: $page->extend);

		// PERF-1: batch-prefetch full file objects + tags for every file-typed property
		// value across the whole page, so renderFileProperties()/getFileObject() do not
		// issue 1+ queries per file per row. Caches are request-scoped and cleared in
		// the finally of renderPage().
		$this->batchPreloadFileObjects(entities: $entities);

		return $this->renderPage(page: $page, entities: $entities);
	}//end renderEntities()

	/**
	 * A comma-separated option as a list of trimmed, non-empty names; any other value as it is.
	 *
	 * @param array|string|null $value The option.
	 *
	 * @return array|null The list.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function trimmedList(array|string|null $value): ?array {
		if (is_string($value) === true) {
			return array_filter(array_map('trim', explode(',', $value)));
		}

		return $value;
	}//end trimmedList()

	/**
	 * Preload, in one query, every object the page's rows reference through an extended property, and the inverse relations.
	 *
	 * @param array $entities The page of entities.
	 * @param array $extend   The relations to extend.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function preloadExtendedRelations(array $entities, array $extend): void {
		$allUuidsToPreload = [];
		foreach ($entities as $entity) {
			if ($entity instanceof ObjectEntity === true && is_array($entity->getObject()) === true) {
				$allUuidsToPreload = array_merge(
					$allUuidsToPreload,
					$this->collectUuidsForExtend(objectData: $entity->getObject(), extend: $extend)
				);
			}
		}

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
			$this->cachePreloadedObjects(entities: $entities, uuids: $allUuidsToPreload);
		}

		// Batch preload objects that REFERENCE our entities, so extending inverse
		// properties like 'contactpersonen' does not query per row.
		$this->inversePreload()->preloadInverseRelationships(entities: $entities, extend: $extend);
	}//end preloadExtendedRelations()

	/**
	 * Load the given objects in one query and keep them in the local cache by UUID and id.
	 *
	 * @param array $entities The page of entities, for the register they share.
	 * @param array $uuids    The UUIDs to load.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cachePreloadedObjects(array $entities, array $uuids): void {
		$preloadedObjects = $this->objectCacheService->preloadObjects(
			identifiers: $uuids,
			registerId: $this->sharedRegisterIdOf(entities: $entities)
		);
		$this->cacheExtendTargets(objects: $preloadedObjects);

		$this->logger->debug(
			message: '[RenderObject] Batch preloaded objects for renderEntities',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'entityCount' => count($entities),
				'requestedUuids' => count($uuids),
				'loadedObjects' => count($preloadedObjects),
			]
		);
	}//end cachePreloadedObjects()

	/**
	 * Batch-prefetch the lightweight @self.files ids of the whole page.
	 *
	 * Without it renderEntity issues one FileMapper query per row when extend
	 * does NOT cover @self.files. When extend opts in to full file metadata,
	 * the per-entity renderFiles() runs instead and this cache is unused.
	 *
	 * @param array $entities The page of entities.
	 * @param array $extend   The relations to extend.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function prefetchPageFileIds(array $entities, array $extend): void {
		if (self::shouldExtendFiles(extend: $extend) === true || empty($entities) === true) {
			return;
		}

		$uuids = self::pageUuids(entities: $entities);
		if (empty($uuids) === false) {
			$this->batchFileIdsCache = $this->readableFileIds(idsByUuid: $this->fileMapper->getFileIdsForObjects($uuids));
		}
	}//end prefetchPageFileIds()

	/**
	 * The UUIDs of the object entities of a page.
	 *
	 * @param array<int|string, mixed> $entities The page.
	 *
	 * @return list<string>
	 */
	private static function pageUuids(array $entities): array {
		$uuids = [];
		foreach ($entities as $entity) {
			if ($entity instanceof ObjectEntity === true && $entity->getUuid() !== null) {
				$uuids[] = $entity->getUuid();
			}
		}

		return $uuids;
	}//end pageUuids()

	/**
	 * Render every row of a page with warm caches, then drop the page's request-scoped caches.
	 *
	 * The source is removed from `@self` in list responses; only a single
	 * object response carries it.
	 *
	 * @param PageRender $page     The page's render options.
	 * @param array      $entities The page of entities.
	 *
	 * @return ObjectEntity[] The rendered rows.
	 *
	 * @psalm-return list<ObjectEntity>
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function renderPage(PageRender $page, array $entities): array {
		$renderedEntities = [];

		// PERF-8: mark a page render so extendObject() skips per-row re-preloading.
		$this->pageRenderActive = true;

		try {
			foreach ($entities as $entity) {
				$renderedEntity = $this->renderEntity(
					entity: $entity,
					_extend: $page->extend,
					depth: 0,
					filter: $page->filter,
					fields: $page->fields,
					unset: $page->unset,
					_rbac: $page->rbac,
					_multitenancy: $page->multitenancy
				);
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
	}//end renderPage()

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
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function batchPreloadFileObjects(array $entities): void {
		$fileIds = $this->pageFileIds(entities: $entities);
		if (empty($fileIds) === true) {
			return;
		}

		try {
			$this->preloadFiles(fileIds: $fileIds);
		} catch (Exception $e) {
			// A prefetch failure must never break rendering: per-row paths will simply
			// fall back to individual lookups (the pre-PERF-1 behaviour).
			$this->logger->warning(
				message: '[RenderObject] Batch file prefetch failed; falling back to per-row lookups',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}//end try
	}//end batchPreloadFileObjects()

	/**
	 * The file IDs referenced by file properties across a page.
	 *
	 * @param array<int|string, mixed> $entities The page.
	 *
	 * @return list<int>
	 */
	private function pageFileIds(array $entities): array {
		$fileIds = [];
		foreach ($entities as $entity) {
			if ($entity instanceof ObjectEntity === true) {
				$fileIds += $this->referencedFileIds(entity: $entity);
			}
		}

		return array_values($fileIds);
	}//end pageFileIds()

	/**
	 * Load files and their tags in one query each, into the request-scoped caches.
	 *
	 * @param array<int, int> $fileIds The file IDs.
	 *
	 * @return void
	 */
	private function preloadFiles(array $fileIds): void {
		$filesById = $this->fileMapper->getFilesByIds(fileIds: $fileIds);
		$this->cacheFileTags(fileIds: $fileIds);

		// Null for a missing file.
		foreach ($fileIds as $fileId) {
			$this->cacheFileObject(fileIdStr: (string)$fileId, record: ($filesById[(string)$fileId] ?? null));
		}
	}//end preloadFiles()

	/**
	 * The file ids an entity references through its file-typed properties, keyed by id.
	 *
	 * @param ObjectEntity $entity The entity about to be rendered.
	 *
	 * @return array<int, int> The file ids, keyed by themselves.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function referencedFileIds(ObjectEntity $entity): array {
		$schema = $this->getSchema(id: $entity->getSchema());
		$objectData = $entity->getObject();
		if ($schema === null || is_array($objectData) === false) {
			return [];
		}

		$fileIds = [];
		foreach (($schema->getProperties() ?? []) as $propertyName => $propertyConfig) {
			if ($this->isFileConfigValue(config: $propertyConfig) === true) {
				$fileIds += self::fileIdsOfValue(propertyConfig: $propertyConfig, value: ($objectData[$propertyName] ?? null));
			}
		}

		return $fileIds;
	}//end referencedFileIds()

	/**
	 * Whether a schema property value is the configuration of a file property.
	 *
	 * @param mixed $config The value.
	 *
	 * @return bool
	 */
	private function isFileConfigValue(mixed $config): bool {
		return is_array($config) === true && $this->isFilePropertyConfig(propertyConfig: $config) === true;
	}//end isFileConfigValue()

	/**
	 * The numeric file ids in one file property's value, keyed by id.
	 *
	 * Base64-format properties read content, not the formatted file
	 * object/tags, so they are not part of the prefetch.
	 *
	 * @param array $propertyConfig The file property's configuration.
	 * @param mixed $value          The property's value.
	 *
	 * @return array<int, int> The file ids, keyed by themselves.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function fileIdsOfValue(array $propertyConfig, mixed $value): array {
		$fileConfig = $propertyConfig;
		if (($propertyConfig['type'] ?? '') === 'array') {
			$fileConfig = ($propertyConfig['items'] ?? []);
		}

		if ($value === null || ($fileConfig['format'] ?? '') === 'base64') {
			return [];
		}

		if (is_array($value) === false) {
			$value = [$value];
		}

		$numeric = array_filter($value, static fn ($candidate): bool => is_numeric($candidate) === true);
		$fileIds = array_map('intval', $numeric);
		return array_combine($fileIds, $fileIds);
	}//end fileIdsOfValue()

	/**
	 * Load the tags of the given files in one batch and put their visible names in the request-scoped tag cache.
	 *
	 * @param int[] $fileIds The requested file ids.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cacheFileTags(array $fileIds): void {
		foreach ($this->tagLabels(fileIds: array_map(static fn ($id) => (string)$id, $fileIds)) as $fileId => $labels) {
			$this->fileTagsCache[$fileId] = $labels;
		}
	}//end cacheFileTags()

	/**
	 * The names of the tags used by the given files, by tag id, without the internal `object:` tags.
	 *
	 * All unique tag ids are resolved to names in one call.
	 *
	 * @param array $tagIdsPerFile The tag ids per file id.
	 *
	 * @return array<string, string> The tag names, by tag id.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function visibleTagNames(array $tagIdsPerFile): array {
		$uniqueTagIds = array_keys(array_flip(array_merge(...array_values($tagIdsPerFile))));
		if (empty($uniqueTagIds) === true) {
			return [];
		}

		$tagNameMap = [];
		foreach ($this->systemTagManager->getTagsByIds(tagIds: $uniqueTagIds) as $tag) {
			$name = $tag->getName();
			if (str_starts_with($name, 'object:') === false) {
				$tagNameMap[$tag->getId()] = $name;
			}
		}

		return $tagNameMap;
	}//end visibleTagNames()

	/**
	 * Put one file's formatted object in the request-scoped file-object cache, or null for a missing file.
	 *
	 * @param string     $fileIdStr The file id.
	 * @param array|null $record    The file record, or null when the file is missing.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cacheFileObject(string $fileIdStr, ?array $record): void {
		if ($record === null) {
			$this->fileObjectCache[$fileIdStr] = null;
			return;
		}

		$this->fileObjectCache[$fileIdStr] = $this->formatFileRecord(fileRecord: $record);
	}//end cacheFileObject()
}//end class
