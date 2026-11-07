<?php

/**
 * OpenRegister ReferentialIntegrityService
 *
 * Service responsible for enforcing referential integrity when objects are deleted.
 * Builds a relation index from schema definitions, walks the deletion graph to detect
 * blockers (RESTRICT) and cascade targets, and applies mutations (CASCADE, SET_NULL,
 * SET_DEFAULT) when deletion proceeds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * Referential integrity requires coordination with schema, object, and mapper services.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\DeletionGraph\DeclaredRelation;
use OCA\OpenRegister\Service\Object\DeletionGraph\DeletionGraphNode;
use OCA\OpenRegister\Service\Object\DeletionGraph\DeletionGraphStep;
use OCA\OpenRegister\Service\Object\DeletionGraph\DeletionGraphWalk;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Exception\HookStoppedException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Service for referential integrity enforcement on object deletion.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Core referential integrity algorithm handles 5 action types
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.TooManyMethods) One integrity algorithm: relation index, graph walk, uuid and key lookups, actions.
 */
class ReferentialIntegrityService {
	/**
	 * Maximum depth for graph walking to prevent infinite recursion in pathological configs.
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 10;

	/**
	 * Distributed-cache namespace for the relation index.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'openregister_refintegrity';

	/**
	 * Cache key holding the versioned relation index.
	 *
	 * @var string
	 */
	private const CACHE_KEY_INDEX = 'relation_index';

	/**
	 * Lifetime of a cached relation index, in seconds.
	 *
	 * A backstop only: correctness comes from the version token, not expiry.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 3600;

	/**
	 * How long a schema edit must have aged before the version token is trusted.
	 *
	 * `openregister_schemas.updated` is `timestamp(0)`, so two edits within the
	 * same clock second collapse onto one value and a token computed between
	 * them would wrongly compare equal. Two seconds clears the truncation
	 * boundary with a full second of slack for jitter between the PHP clock
	 * that writes the stamp and the one that reads it back.
	 *
	 * @var int
	 */
	private const VERSION_SETTLE_SECONDS = 2;

	/**
	 * Valid onDelete action values.
	 *
	 * @var string[]
	 */
	public const VALID_ON_DELETE_ACTIONS = [
		'CASCADE',
		'RESTRICT',
		'SET_NULL',
		'SET_DEFAULT',
		'NO_ACTION',
	];

	/**
	 * Cached relation index: target schema ID => array of dependent relations.
	 * Built once per request and reused for batch operations.
	 *
	 * @var array|null
	 */
	private ?array $relationIndex = null;

	/**
	 * Cached schema map: schema ID => Schema entity.
	 *
	 * @var array|null
	 */
	private ?array $schemaCache = null;

	/**
	 * Cached schema-to-register mapping: schema ID => Register entity.
	 *
	 * @var array|null
	 */
	private ?array $schemaRegisterMap = null;

	/**
	 * Constructor for ReferentialIntegrityService.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema data mapper.
	 * @param RegisterMapperInterface $registerMapper Register data mapper.
	 * @param MagicMapperInterface $objectEntityMapper Object entity data mapper.
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper for integrity action logging.
	 * @param LoggerInterface $logger Logger for debugging.
	 * @param IDBConnection $db Database connection for raw SQL queries.
	 * @param ICacheFactory $cacheFactory Distributed cache for the relation index.
	 * @param ArchivalRetentionGuard $archivalGuard Decides whether a cascade target is legally retained.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly MagicMapperInterface $objectEntityMapper,
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly LoggerInterface $logger,
		private readonly IDBConnection $db,
		private readonly ICacheFactory $cacheFactory,
		private readonly ArchivalRetentionGuard $archivalGuard,
	) {
	}//end __construct()

	/**
	 * Analyze whether an object can be deleted without violating referential integrity.
	 *
	 * Walks the full deletion graph without performing any mutations.
	 *
	 * @param ObjectEntity $object The object to analyze for deletion.
	 *
	 * @return DeletionAnalysis The analysis result with targets and blockers.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function canDelete(ObjectEntity $object): DeletionAnalysis {
		$this->ensureRelationIndex();

		$schemaId = $object->getSchema();
		if ($schemaId === null) {
			return DeletionAnalysis::empty();
		}

		// Quick check: if no schemas reference this object's schema with onDelete config, skip.
		if (isset($this->relationIndex[$schemaId]) === false) {
			return DeletionAnalysis::empty();
		}

		$walk = new DeletionGraphWalk();
		$this->walkDeletionGraph(node: new DeletionGraphNode(object: $object, walk: $walk));
		return $walk->toAnalysis();
	}//end canDelete()

	/**
	 * Apply the deletion actions from a pre-computed analysis.
	 *
	 * Execution order: SET_NULL → SET_DEFAULT → CASCADE (deepest first).
	 *
	 * @param DeletionAnalysis $analysis The pre-computed deletion analysis.
	 * @param string $userId The user performing the deletion.
	 * @param string $cascadeSource The UUID of the root object being deleted.
	 * @param string|null $organisationId The active organisation ID.
	 * @param string|null $triggerSchemaSlug Slug of the schema of the deleted object (for audit trail).
	 *
	 * @return array{retained: array<int, array<string, mixed>>} The cascade targets the
	 *               archival obligation kept live, each naming the record and the ground.
	 *               Empty when nothing was retained.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple action types require distinct handling paths
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function applyDeletionActions(
		DeletionAnalysis $analysis,
		string $userId,
		string $cascadeSource,
		?string $organisationId = null,
		?string $triggerSchemaSlug = null,
	): array {
		// 1. Apply SET_NULL targets first (objects survive with cleared reference).
		$origin = new CascadeDeleteOrigin(
			userId: $userId,
			cascadeSource: $cascadeSource,
			triggerSchemaSlug: $triggerSchemaSlug,
			organisationId: $organisationId
		);
		$this->applySetNullTargets(analysis: $analysis, origin: $origin);
		$this->applySetDefaultTargets(analysis: $analysis, origin: $origin);

		// A retained (archival) target stays live even when its parent goes: it is kept out of the
		// soft-delete batch and named in the return value, and the parent delete still proceeds.
		[$cascadeTargets, $retained] = $this->partitionRetainedTargets(
			cascadeTargets: array_reverse($analysis->cascadeTargets)
		);
		if (empty($cascadeTargets) === false) {
			$this->applyBatchCascadeDelete(cascadeTargets: $cascadeTargets, origin: $origin);
		}

		return ['retained' => $retained];
	}//end applyDeletionActions()

	/**
	 * Clear the reference in every SET_NULL target and audit each one.
	 *
	 * @param DeletionAnalysis    $analysis The deletion analysis.
	 * @param CascadeDeleteOrigin $origin   The deleting user and the root object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applySetNullTargets(DeletionAnalysis $analysis, CascadeDeleteOrigin $origin): void {
		foreach ($analysis->nullifyTargets as $target) {
			$updated = $this->applySetNull(target: $target);
			$this->logIntegrityAction(
				entry: (new IntegrityAuditEntry(
					action: 'referential_integrity.set_null',
					objectUuid: $target['objectUuid'],
					changed: [
						'property' => $target['property'],
						'previousValue' => $target['sourceUuid'] ?? null,
						'newValue' => null,
						'triggerObject' => $origin->cascadeSource,
						'triggerSchema' => $origin->triggerSchemaSlug,
					],
					userId: $origin->userId
				))->within(schemaId: $target['schema'] ?? null, registerId: null)->about(object: $updated)
			);
		}
	}//end applySetNullTargets()

	/**
	 * Set every SET_DEFAULT target's reference to its default and audit each one.
	 *
	 * @param DeletionAnalysis    $analysis The deletion analysis.
	 * @param CascadeDeleteOrigin $origin   The deleting user and the root object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applySetDefaultTargets(DeletionAnalysis $analysis, CascadeDeleteOrigin $origin): void {
		foreach ($analysis->defaultTargets as $target) {
			$updated = $this->applySetDefault(target: $target);
			$this->logIntegrityAction(
				entry: (new IntegrityAuditEntry(
					action: 'referential_integrity.set_default',
					objectUuid: $target['objectUuid'],
					changed: [
						'property' => $target['property'],
						'previousValue' => $origin->cascadeSource,
						'defaultValue' => $target['defaultValue'] ?? null,
						'triggerObject' => $origin->cascadeSource,
						'triggerSchema' => $origin->triggerSchemaSlug,
					],
					userId: $origin->userId
				))->within(schemaId: $target['schema'] ?? null, registerId: null)->about(object: $updated)
			);
		}
	}//end applyDeletionActions()

	/**
	 * Split cascade targets into the ones that may be deleted and the ones the law keeps.
	 *
	 * Asks {@see ArchivalRetentionGuard}, which asks
	 * {@see \OCA\OpenRegister\Db\Schema::hasArchivalAnnotation()}. That is the
	 * single definition of "is archival" and the four HTTP delete doors read the
	 * same one, so the cascade cannot quietly disagree with them.
	 *
	 * A target referenced through two properties appears twice in the analysis
	 * and is reported once, keyed by uuid, so the caller's count matches the
	 * number of records it can go and look at.
	 *
	 * @param array<int, array<string, mixed>> $cascadeTargets Targets, already reversed.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
	 *               [0] targets the cascade may delete, [1] one report per retained record.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private function partitionRetainedTargets(array $cascadeTargets): array {
		$proceed = [];
		$reported = [];

		foreach ($cascadeTargets as $target) {
			$uuid = (string)($target['objectUuid'] ?? '');
			if ($uuid === '') {
				$proceed[] = $target;
				continue;
			}

			if (isset($reported[$uuid]) === true) {
				continue;
			}

			$refusal = $this->archivalGuard->cascadeRefusal(
				uuid: $uuid,
				schemaIdentifier: ($target['schema'] ?? null)
			);
			if ($refusal === null) {
				$proceed[] = $target;
				continue;
			}

			$reported[$uuid] = $refusal;
			$this->logger->info(
				message: '[ReferentialIntegrity] Kept a retained record out of a cascade',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'schema' => ($target['schema'] ?? null),
					'ground' => $refusal['ground'],
				]
			);
		}

		return [$proceed, array_values($reported)];
	}//end partitionRetainedTargets()

	/**
	 * Log a RESTRICT block event to the audit trail.
	 *
	 * Called when a deletion is prevented by RESTRICT constraints. Records the
	 * blocked object and the blockers that prevented deletion.
	 *
	 * @param string $objectUuid The UUID of the object that could not be deleted.
	 * @param string|null $schemaId Schema ID of the blocked object.
	 * @param DeletionAnalysis $analysis The analysis containing blocker information.
	 * @param string $userId The user who attempted the deletion.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function logRestrictBlock(
		string $objectUuid,
		?string $schemaId,
		DeletionAnalysis $analysis,
		string $userId,
	): void {
		$blockerSchemas = [];
		$blockerProps = [];
		foreach ($analysis->blockers as $blocker) {
			$blockerSchemas[] = $blocker['schema'] ?? 'unknown';
			$blockerProps[] = $blocker['property'] ?? 'unknown';
		}

		$uniqueSchemas = array_values(array_unique($blockerSchemas));
		$uniqueProps = array_values(array_unique($blockerProps));

		$this->logIntegrityAction(
			entry: (new IntegrityAuditEntry(
				action: 'referential_integrity.restrict_blocked',
				objectUuid: $objectUuid,
				changed: [
					'blockerCount' => count($analysis->blockers),
					'blockerSchema' => $uniqueSchemas[0] ?? 'unknown',
					'blockerProperty' => $uniqueProps[0] ?? 'unknown',
					'reason' => 'RESTRICT constraint prevents deletion',
				],
				userId: $userId
			))->within(schemaId: $schemaId, registerId: null)
		);
	}//end logRestrictBlock()

	/**
	 * Check if a schema has any incoming onDelete references from other schemas.
	 *
	 * Used as a fast check to skip referential integrity analysis entirely
	 * for schemas that no other schema cares about.
	 *
	 * @param string $schemaId The schema ID to check.
	 *
	 * @return bool True if any schema has onDelete config referencing this schema.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function hasIncomingOnDeleteReferences(string $schemaId): bool {
		$this->ensureRelationIndex();
		return isset($this->relationIndex[$schemaId]);
	}//end hasIncomingOnDeleteReferences()

	/**
	 * Build the relation index from all schemas if not already cached.
	 *
	 * The index maps: target schema ID → [{sourceSchemaId, property, onDelete, isArray, sourceSchemaSlug}]
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * Reduced from 19 to ~12 by extracting buildSchemaRegisterMap + indexRelationsForSchema.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function ensureRelationIndex(): void {
		if ($this->relationIndex !== null) {
			return;
		}

		// Fast path: the index is a small pure-array derivative of the schema
		// definitions, which change rarely, so it survives across requests in
		// the distributed cache. Every delete used to rebuild it, and that
		// rebuild hydrates EVERY schema on the instance purely to discover
		// which ones declare an onDelete relation — measured at ~270 ms of an
		// ~800 ms delete against 1,935 schemas, of which only 121 can
		// contribute.
		//
		// Correctness rests entirely on the version token, and the token is
		// trusted only when it can be shown to have settled — see
		// {@see schemaCatalogueVersion()}, which returns null whenever that
		// cannot be established. A null token bypasses the cache in BOTH
		// directions: nothing is read, and nothing is written back. This gate
		// is deliberately asymmetric. A missed cache hit costs milliseconds; a
		// missed RESTRICT destroys data the user asked the system to protect.
		$version = $this->schemaCatalogueVersion();
		$cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
		$this->relationIndex = self::cachedRelationIndex(cached: $this->cachedIndexEntry(cache: $cache, version: $version), version: $version);
		if ($this->relationIndex !== null) {
			return;
		}

		if ($this->buildRelationIndex() === true && $version !== null) {
			$cache->set(self::CACHE_KEY_INDEX, ['version' => $version, 'index' => $this->relationIndex], self::CACHE_TTL);
		}
	}//end ensureRelationIndex()

	/**
	 * Build the relation index from every schema.
	 *
	 * @return bool False when the schemas could not be loaded; the index stays empty.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function buildRelationIndex(): bool {
		$this->relationIndex = [];
		$allSchemas = $this->allSchemasOrNull(purpose: 'relation index');
		if ($allSchemas === null) {
			return false;
		}

		foreach ($allSchemas as $schema) {
			$this->indexRelationsForSchema(schema: $schema, allSchemas: $allSchemas);
		}

		return true;
	}//end buildRelationIndex()

	/**
	 * The stored index entry, when a catalogue version can be trusted.
	 *
	 * @param ICache      $cache   The distributed cache.
	 * @param string|null $version The catalogue version.
	 *
	 * @return array The entry, empty when there is none.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function cachedIndexEntry(ICache $cache, ?string $version): array {
		$entry = null;
		if ($version !== null) {
			$entry = $cache->get(self::CACHE_KEY_INDEX);
		}

		if (is_array($entry) === false) {
			return [];
		}

		return $entry;
	}//end cachedIndexEntry()

	/**
	 * The index in a cache entry, when the entry belongs to this catalogue version.
	 *
	 * @param array       $cached  The cache entry.
	 * @param string|null $version The catalogue version.
	 *
	 * @return array|null The index.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private static function cachedRelationIndex(array $cached, ?string $version): ?array {
		if (($cached['version'] ?? false) !== $version || is_array($cached['index'] ?? null) === false) {
			return null;
		}

		return $cached['index'];
	}//end cachedRelationIndex()

	/**
	 * Every schema, or null when they cannot be loaded.
	 *
	 * @param string $purpose What they are for, for the warning.
	 *
	 * @return array|null The schemas.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function allSchemasOrNull(string $purpose): ?array {
		try {
			return $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] Failed to load schemas for ' . $purpose,
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end allSchemasOrNull()

	/**
	 * Compute a token that changes whenever any schema definition changes.
	 *
	 * A narrow projection — `id`, `version`, `updated` — over the schema table
	 * stands in for hydrating the whole catalogue. It reads three small columns
	 * instead of decoding 1,935 JSON property blobs, and it is folded into one
	 * digest in PHP rather than in SQL so no database-specific aggregate
	 * (`string_agg` / `group_concat`) is required.
	 *
	 * Three independent signals make it move:
	 *
	 *  1. The row set itself — an inserted or deleted schema changes the digest
	 *     regardless of any timestamp.
	 *  2. `version`, which {@see \OCA\OpenRegister\Db\SchemaMapper::updateFromArray()}
	 *     bumps on every edit that does not name a version explicitly.
	 *  3. `updated`, which SchemaMapper::update() now stamps on every edit. It
	 *     previously never moved at all, which is precisely how an edit that
	 *     added an incoming RESTRICT relation could leave this index stale and
	 *     let a protected parent be deleted.
	 *
	 * **The whole-second window.** `updated` is `timestamp(0)`, so two edits in
	 * the same clock second are indistinguishable, and an index built between
	 * them would carry a token that still matches. Signal 2 usually covers
	 * this, but a caller that supplies its own `version` defeats it, so the
	 * window is closed structurally instead: a token whose newest `updated` has
	 * not yet aged past the truncation boundary is refused outright. For the
	 * couple of seconds after any schema edit, deletes take the slow path and
	 * are correct by construction; after that the token is provably settled.
	 *
	 * Returning null on any failure — unreadable table, unparseable timestamp,
	 * a stamp in the future — disables caching for this call rather than
	 * risking a stale referential-integrity index.
	 *
	 * @return string|null The version token, or null when it cannot be trusted.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function schemaCatalogueVersion(): ?string {
		try {
			$rows = $this->catalogueRows();
			if (self::catalogueSettled(newest: self::newestStamp(rows: $rows)) === false) {
				return null;
			}

			return self::catalogueHash(rows: $rows);
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[ReferentialIntegrity] Could not compute schema catalogue version',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end schemaCatalogueVersion()

	/**
	 * The id, version and update stamp of every schema.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function catalogueRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'version', 'updated')->from('openregister_schemas');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end catalogueRows()

	/**
	 * The newest update stamp in the rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The catalogue rows.
	 *
	 * @return int|false|null The epoch, null without stamps, false on a stamp that cannot be read.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private static function newestStamp(array $rows): int|false|null {
		$stamps = array_map(static fn (array $row): string => (string)($row['updated'] ?? ''), $rows);
		$epochs = array_map('strtotime', array_filter($stamps, static fn (string $stamp): bool => $stamp !== ''));
		if (in_array(false, $epochs, true) === true) {
			return false;
		}

		if ($epochs === []) {
			return null;
		}

		return max($epochs);
	}//end newestStamp()

	/**
	 * Whether the catalogue has been quiet long enough to trust a version.
	 *
	 * @param int|false|null $newest The newest stamp, as newestStamp() gives it.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private static function catalogueSettled(int|false|null $newest): bool {
		return $newest !== false && ($newest === null || (time() - $newest) >= self::VERSION_SETTLE_SECONDS);
	}//end catalogueSettled()

	/**
	 * The version string of the catalogue rows, independent of their order.
	 *
	 * @param array<int, array<string, mixed>> $rows The catalogue rows.
	 *
	 * @return string The version.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private static function catalogueHash(array $rows): string {
		$parts = array_map(
			static fn (array $row): string => ((string)($row['id'] ?? '')) . ':' . ((string)($row['version'] ?? '')) . ':' . ((string)($row['updated'] ?? '')),
			$rows
		);
		sort($parts);

		return count($parts) . '|' . md5(implode("\n", $parts));
	}//end catalogueHash()

	/**
	 * Hydrate the schema entities and schema-to-register map on demand.
	 *
	 * Split out of {@see ensureRelationIndex()} because these two structures are
	 * only read while APPLYING an integrity action (cascade / set-null /
	 * required-property checks). The overwhelmingly common delete has no
	 * incoming onDelete references at all and answers from the relation index
	 * alone, so it must not pay for hydrating the schema catalogue.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function ensureSchemaDetail(): void {
		if ($this->schemaCache !== null) {
			return;
		}

		$this->schemaCache = [];
		$this->schemaRegisterMap = [];

		$allSchemas = $this->allSchemasOrNull(purpose: 'detail cache');
		if ($allSchemas === null) {
			return;
		}

		$this->buildSchemaRegisterMap();

		foreach ($allSchemas as $schema) {
			$this->schemaCache[(string)$schema->getId()] = $schema;
		}

	}//end ensureSchemaDetail()

	/**
	 * Build a map from schema ID to register by scanning magic table names.
	 *
	 * Tables follow convention: openregister_table_{registerId}_{schemaId}.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function buildSchemaRegisterMap(): void {
		try {
			$registers = $this->registersById();
			foreach ($this->magicTableNames() as $tableName) {
				$this->mapTableToRegister(tableName: $tableName, registers: $registers);
			}
		} catch (\Exception $e) {
			$this->logger->debug(
				message: '[ReferentialIntegrity] Failed to build schema-register map',
				context: ['error' => $e->getMessage()]
			);
		}//end try

	}//end buildSchemaRegisterMap()

	/**
	 * The magic table names in this database, without their table prefix.
	 *
	 * @return array<int, string> The table names.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function magicTableNames(): array {
		$stmt = $this->db->prepare(
			"SELECT table_name FROM information_schema.tables WHERE table_name LIKE '*PREFIX*openregister_table_%' "
			. "AND table_schema = {$this->currentSchemaFunction()}"
		);
		$stmt->execute();
		$tables = [];
		while (($row = $stmt->fetch()) !== false && is_array($row) === true) {
			if (is_string($row['table_name'] ?? null) === true) {
				$tables[] = (string)strstr($row['table_name'], 'openregister_table_');
			}
		}

		return $tables;
	}//end magicTableNames()

	/**
	 * The SQL function that names the current schema on this database.
	 *
	 * @return string The function call.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function currentSchemaFunction(): string {
		if (stripos(get_debug_type($this->db->getDatabasePlatform()), 'PostgreSQL') !== false) {
			return 'current_schema()';
		}

		return 'DATABASE()';
	}//end currentSchemaFunction()

	/**
	 * Every register, by id.
	 *
	 * @return array<int|string, Register> The registers.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function registersById(): array {
		$registers = [];
		foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$registers[(string)$register->getId()] = $register;
		}

		return $registers;
	}//end registersById()

	/**
	 * Remember the register of the schema a magic table belongs to; the first table found wins.
	 *
	 * @param string     $tableName The table name.
	 * @param Register[] $registers The registers by id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function mapTableToRegister(string $tableName, array $registers): void {
		if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $tableName, $matches) === 1
			&& isset($registers[$matches[1]]) === true
			&& isset($this->schemaRegisterMap[$matches[2]]) === false
		) {
			$this->schemaRegisterMap[$matches[2]] = $registers[$matches[1]];
		}
	}//end mapTableToRegister()

	/**
	 * Index referential integrity relations for a single schema.
	 *
	 * @param \OCA\OpenRegister\Db\Schema $schema The schema to index
	 * @param array $allSchemas All schemas for ref resolution
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function indexRelationsForSchema(\OCA\OpenRegister\Db\Schema $schema, array $allSchemas): void {
		foreach (($schema->getProperties() ?? []) as $propertyName => $property) {
			$this->indexRelation(
				relation: new DeclaredRelation(
					sourceSchemaId: (string)$schema->getId(),
					property: $propertyName,
					definition: $property
				),
				allSchemas: $allSchemas
			);
		}
	}//end indexRelationsForSchema()

	/**
	 * Add one declared relation to the index, when it has an action and a target that resolves.
	 *
	 * @param DeclaredRelation $relation   The property read as a relation.
	 * @param array            $allSchemas All schemas for ref resolution.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function indexRelation(DeclaredRelation $relation, array $allSchemas): void {
		$onDelete = $this->extractOnDelete(property: $relation->definition);
		if ($onDelete === null || $onDelete === 'NO_ACTION') {
			return;
		}

		$targetSchemaId = $this->relationTargetId(relation: $relation, allSchemas: $allSchemas);
		if ($targetSchemaId === null || $this->isIgnoredKeyRelation(relation: $relation, onDelete: $onDelete) === true) {
			return;
		}

		$referenceKey = $this->extractReferenceKey(property: $relation->definition);
		$this->relationIndex[$targetSchemaId][] = $relation->indexEntry(onDelete: $onDelete, referenceKey: $referenceKey);
	}//end indexRelation()

	/**
	 * The schema ID a declared relation points at.
	 *
	 * @param DeclaredRelation $relation   The property read as a relation.
	 * @param array            $allSchemas All schemas for ref resolution.
	 *
	 * @return string|null The schema ID, or null without a target or when it does not resolve.
	 */
	private function relationTargetId(DeclaredRelation $relation, array $allSchemas): ?string {
		$targetRef = $this->extractTargetRef(property: $relation->definition);
		if ($targetRef === null) {
			return null;
		}

		return $this->resolveSchemaRef(ref: $targetRef, allSchemas: $allSchemas);
	}//end relationTargetId()

	/**
	 * Whether a reference by key asks for more than a refusal, and is left out of the index.
	 *
	 * A reference by key (a code, not a uuid) only refuses. CASCADE, SET_NULL and
	 * SET_DEFAULT rewrite or delete by uuid, so on a key they would act on the wrong
	 * value; the relation is left out and the schema author told why, rather than
	 * half-applied.
	 *
	 * @param DeclaredRelation $relation The property read as a relation.
	 * @param string           $onDelete The uppercase onDelete action.
	 *
	 * @return bool True when the relation is ignored.
	 */
	private function isIgnoredKeyRelation(DeclaredRelation $relation, string $onDelete): bool {
		if ($this->extractReferenceKey(property: $relation->definition) === null || $onDelete === 'RESTRICT') {
			return false;
		}

		$this->logger->warning(
			message: '[ReferentialIntegrity] referenceKey is only honoured with onDelete RESTRICT; relation ignored',
			context: ['schemaId' => $relation->sourceSchemaId, 'property' => $relation->property, 'onDelete' => $onDelete]
		);
		return true;
	}//end isIgnoredKeyRelation()

	/**
	 * Extract the onDelete action from a property definition.
	 *
	 * @param array $property The property configuration array.
	 *
	 * @return string|null The uppercase onDelete action, or null if not set.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function extractOnDelete(array $property): ?string {
		if (isset($property['onDelete']) === false) {
			return null;
		}

		return strtoupper((string)$property['onDelete']);
	}//end extractOnDelete()

	/**
	 * Extract the target schema reference from a property definition.
	 *
	 * Handles both direct $ref and array items.$ref.
	 *
	 * @param array $property The property configuration array.
	 *
	 * @return string|null The $ref value, or null if not a relation property.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function extractTargetRef(array $property): ?string {
		// A reference that is not an object relation (a code citing a row by
		// key) names its target with `referenceSchema`, so the `$ref` relation
		// machinery (rendering, _extend, uuid validation) is not engaged.
		$ref = ($property['$ref'] ?? $property['items']['$ref'] ?? $property['referenceSchema'] ?? $property['items']['referenceSchema'] ?? null);
		if ($ref === null) {
			return null;
		}

		return (string)$ref;
	}//end extractTargetRef()

	/**
	 * The target property a reference cites by, when it is not the uuid.
	 *
	 * @param array $property The property configuration array.
	 *
	 * @return string|null The target's key property, or null for a uuid reference.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function extractReferenceKey(array $property): ?string {
		return (new ReferenceKey())->keyOf(property: $property);
	}//end extractReferenceKey()

	/**
	 * Resolve a schema $ref string to a schema ID.
	 *
	 * References can be slugs, UUIDs, URLs, or numeric IDs.
	 *
	 * @param string $ref The $ref value to resolve.
	 * @param array $allSchemas All loaded schemas for matching.
	 *
	 * @return string|null The resolved schema ID, or null if not found.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveSchemaRef(string $ref, array $allSchemas): ?string {
		// Strip leading path components (e.g., "/schemas/my-slug" → "my-slug").
		$refClean = basename($ref);

		foreach ($allSchemas as $schema) {
			$names = [(string)$schema->getId(), $schema->getSlug(), $schema->getUuid()];
			if (in_array($ref, $names, true) === true || in_array($refClean, $names, true) === true) {
				return $names[0];
			}
		}

		return null;
	}//end resolveSchemaRef()

	/**
	 * Walk the deletion graph from one object, adding what its deletion implies to the node's walk.
	 *
	 * @param DeletionGraphNode $node The object being analyzed for deletion.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function walkDeletionGraph(DeletionGraphNode $node): void {
		if ($this->entersNode(node: $node) === false) {
			return;
		}

		foreach ($this->relationsCiting(object: $node->object) as $dep) {
			$this->walkDependent(node: $node, dep: $dep);
		}
	}//end walkDeletionGraph()

	/**
	 * Mark a node visited, unless it was visited before or lies past the depth limit.
	 *
	 * @param DeletionGraphNode $node The object being analyzed for deletion.
	 *
	 * @return bool True when the walk goes on from this node.
	 */
	private function entersNode(DeletionGraphNode $node): bool {
		$uuid = $node->object->getUuid();
		if ($node->walk->hasVisited(uuid: $uuid) === true) {
			return false;
		}

		if ($node->depth >= self::MAX_DEPTH) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] Max depth reached during graph walk',
				context: ['uuid' => $uuid, 'depth' => $node->depth]
			);
			return false;
		}

		$node->walk->markVisited(uuid: $uuid);
		return true;
	}//end entersNode()

	/**
	 * The relation index entries that point at an object's schema.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, array<string, mixed>> The entries, none for an object without a schema.
	 */
	private function relationsCiting(ObjectEntity $object): array {
		$schemaId = $object->getSchema();
		if ($schemaId === null) {
			return [];
		}

		return ($this->relationIndex[$schemaId] ?? []);
	}//end relationsCiting()

	/**
	 * Walk the objects that cite a node's object through one relation.
	 *
	 * @param DeletionGraphNode    $node The object being analyzed for deletion.
	 * @param array<string, mixed> $dep  The relation index entry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function walkDependent(DeletionGraphNode $node, array $dep): void {
		if (($dep['referenceKey'] ?? null) !== null) {
			$node->walk->addBlockers(blockers: $this->keyReferenceBlockers(node: $node, dep: $dep));
			return;
		}

		// Find actual objects of the dependent schema that reference this object's UUID.
		$referencingObjects = $this->findReferencingObjects(dep: $dep, targetUuid: $node->object->getUuid());
		if ($referencingObjects === null) {
			// Not known whether anything cites it: refuse rather than delete past a RESTRICT.
			$chain = array_merge($node->chain, ["{$node->object->getUuid()} → {$dep['property']} (not verifiable)"]);
			$node->walk->addBlocker(blocker: self::unverifiableBlocker(dep: $dep, chain: $chain));
			return;
		}

		foreach (self::liveObjects(objects: $referencingObjects) as $refObj) {
			$this->applyOnDelete(step: new DeletionGraphStep(node: $node, dep: $dep, referencing: $refObj));
		}
	}//end walkDependent()

	/**
	 * A RESTRICT blocker for a relation whose citing objects could not be read.
	 *
	 * @param array<string, mixed> $dep   The relation index entry.
	 * @param array<int, string>   $chain The chain to report.
	 *
	 * @return array<string, mixed>
	 */
	private static function unverifiableBlocker(array $dep, array $chain): array {
		return [
			'objectUuid' => '',
			'schema' => $dep['sourceSchemaId'],
			'property' => $dep['property'],
			'action' => 'RESTRICT',
			'reason' => 'unverifiable',
			'chain' => $chain,
		];
	}//end unverifiableBlocker()

	/**
	 * The objects that are not soft-deleted.
	 *
	 * @param ObjectEntity[] $objects The objects.
	 *
	 * @return list<ObjectEntity>
	 */
	private static function liveObjects(array $objects): array {
		return array_values(array_filter($objects, fn (ObjectEntity $object): bool => empty($object->getDeleted()) === true));
	}//end liveObjects()

	/**
	 * Add what the relation's onDelete action does to one referencing object.
	 *
	 * NO_ACTION, and any other value, adds nothing.
	 *
	 * @param DeletionGraphStep $step The referencing object and its relation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applyOnDelete(DeletionGraphStep $step): void {
		switch ($step->dep['onDelete']) {
			case 'RESTRICT':
				$step->node->walk->addBlocker(blocker: $this->restrictBlocker(step: $step, chain: $step->chain));
				return;
			case 'CASCADE':
				$this->cascadeStep(step: $step);
				return;
			case 'SET_NULL':
				$this->clearOrBlock(step: $step, blockNote: '(SET_NULL on required → RESTRICT)');
				return;
			case 'SET_DEFAULT':
				$this->setDefaultStep(step: $step);
		}
	}//end applyOnDelete()

	/**
	 * CASCADE: the referencing object is deleted too, so walk on from it.
	 *
	 * @param DeletionGraphStep $step The referencing object and its relation.
	 *
	 * @return void
	 */
	private function cascadeStep(DeletionGraphStep $step): void {
		$step->node->walk->addCascadeTarget(target: self::cascadeTarget(step: $step));

		$this->walkDeletionGraph(
			node: new DeletionGraphNode(
				object: $step->referencing,
				walk: $step->node->walk,
				chain: $step->chain,
				depth: $step->node->depth + 1
			)
		);
	}//end cascadeStep()

	/**
	 * A cascade target for a referencing object.
	 *
	 * @param DeletionGraphStep $step The referencing object and its relation.
	 *
	 * @return array<string, mixed>
	 */
	private static function cascadeTarget(DeletionGraphStep $step): array {
		return [
			'objectUuid' => $step->referencing->getUuid(),
			'register' => $step->referencing->getRegister(),
			'schema' => $step->dep['sourceSchemaId'],
			'property' => $step->dep['property'],
			'chain' => $step->chain,
		];
	}//end cascadeTarget()

	/**
	 * Clear the reference, or block when the property is required.
	 *
	 * @param DeletionGraphStep $step      The referencing object and its relation.
	 * @param string            $blockNote What the chain says when the delete is blocked.
	 *
	 * @return void
	 */
	private function clearOrBlock(DeletionGraphStep $step, string $blockNote): void {
		if ($this->isStepPropertyRequired(step: $step) === true) {
			$step->node->walk->addBlocker(
				blocker: $this->restrictBlocker(step: $step, chain: array_merge($step->chain, [$blockNote]))
			);
			return;
		}

		$step->node->walk->addNullifyTarget(target: $this->nullifyTarget(step: $step));
	}//end clearOrBlock()

	/**
	 * SET_DEFAULT: reset the reference to its default; without one, behave as SET_NULL.
	 *
	 * @param DeletionGraphStep $step The referencing object and its relation.
	 *
	 * @return void
	 */
	private function setDefaultStep(DeletionGraphStep $step): void {
		$defaultValue = $this->getDefaultValue(
			schemaId: $step->dep['sourceSchemaId'],
			propertyName: $step->dep['property']
		);
		if ($defaultValue === null) {
			$this->clearOrBlock(step: $step, blockNote: '(SET_DEFAULT no default + required → RESTRICT)');
			return;
		}

		$step->node->walk->addDefaultTarget(target: self::defaultTarget(step: $step, defaultValue: $defaultValue));
	}//end setDefaultStep()

	/**
	 * A default target for a referencing object.
	 *
	 * @param DeletionGraphStep $step         The referencing object and its relation.
	 * @param mixed             $defaultValue The value the reference is reset to.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaultTarget(DeletionGraphStep $step, mixed $defaultValue): array {
		return [
			'objectUuid' => $step->referencing->getUuid(),
			'schema' => $step->dep['sourceSchemaId'],
			'property' => $step->dep['property'],
			'defaultValue' => $defaultValue,
		];
	}//end defaultTarget()

	/**
	 * Whether the referencing property is required on its schema.
	 *
	 * @param DeletionGraphStep $step The referencing object and its relation.
	 *
	 * @return bool
	 */
	private function isStepPropertyRequired(DeletionGraphStep $step): bool {
		return $this->isRequiredProperty(
			schemaId: $step->dep['sourceSchemaId'],
			propertyName: $step->dep['property']
		);
	}//end isStepPropertyRequired()

	/**
	 * A RESTRICT blocker for a referencing object.
	 *
	 * @param DeletionGraphStep  $step  The referencing object and its relation.
	 * @param array<int, string> $chain The chain to report.
	 *
	 * @return array<string, mixed>
	 */
	private function restrictBlocker(DeletionGraphStep $step, array $chain): array {
		return [
			'objectUuid' => $step->referencing->getUuid(),
			'schema' => $step->dep['sourceSchemaId'],
			'property' => $step->dep['property'],
			'action' => 'RESTRICT',
			'chain' => $chain,
		];
	}//end restrictBlocker()

	/**
	 * A nullify target for a referencing object.
	 *
	 * @param DeletionGraphStep $step The referencing object and its relation.
	 *
	 * @return array<string, mixed>
	 */
	private function nullifyTarget(DeletionGraphStep $step): array {
		return [
			'objectUuid' => $step->referencing->getUuid(),
			'schema' => $step->dep['sourceSchemaId'],
			'property' => $step->dep['property'],
			'isArray' => $step->dep['isArray'],
			'sourceUuid' => $step->node->object->getUuid(),
		];
	}//end nullifyTarget()

	/**
	 * The RESTRICT blockers for a reference that cites this object by a key property.
	 *
	 * FAIL CLOSED. A citation that cannot be checked blocks the delete with
	 * reason `unverifiable`: deleting a row that may still be cited is the one
	 * outcome a RESTRICT rules out. Only a citing table that does not exist
	 * yet answers "nothing cites it", because then nothing can.
	 *
	 * @param DeletionGraphNode    $node The object being deleted.
	 * @param array<string, mixed> $dep  The relation index entry (with `referenceKey`).
	 *
	 * @return array<int, array<string, mixed>> The blockers, possibly none.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function keyReferenceBlockers(DeletionGraphNode $node, array $dep): array {
		$value = self::citedKeyValue(object: $node->object, key: $dep['referenceKey']);
		if ($value === null) {
			return [];
		}

		$uuid = $node->object->getUuid();
		$chain = array_merge($node->chain, ["{$uuid} → {$dep['property']}={$value} (RESTRICT by {$dep['referenceKey']})"]);
		return $this->blockersCiting(
			node: new DeletionGraphNode(object: $node->object, walk: $node->walk, chain: $chain, depth: $node->depth),
			reference: $dep + ['value' => trim((string)$value)]
		);
	}//end keyReferenceBlockers()

	/**
	 * The blockers for the live rows that cite a key value; one unverifiable blocker when they cannot be read.
	 *
	 * @param DeletionGraphNode    $node      The object being deleted, its chain the one to report.
	 * @param array<string, mixed> $reference The relation index entry with the cited `value`.
	 *
	 * @return array<int, array<string, mixed>> The blockers, possibly none.
	 */
	private function blockersCiting(DeletionGraphNode $node, array $reference): array {
		$referencing = $this->findReferencingByKey(dep: $reference, value: $reference['value']);
		if ($referencing === null) {
			return [self::unverifiableBlocker(dep: $reference, chain: $node->chain)];
		}

		return array_map(
			fn (ObjectEntity $refObj): array => $this->restrictBlocker(
				step: new DeletionGraphStep(node: $node, dep: $reference, referencing: $refObj),
				chain: $node->chain
			),
			self::liveObjects(objects: $referencing)
		);
	}//end blockersCiting()

	/**
	 * The value an object can be cited by.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $key    The key property.
	 *
	 * @return bool|float|int|string|null The value as stored, or null when it has nothing to be cited by.
	 */
	private static function citedKeyValue(ObjectEntity $object, string $key): bool|float|int|string|null {
		$value = (($object->getObject() ?? [])[$key] ?? null);
		if (is_scalar($value) === false || trim((string)$value) === '') {
			return null;
		}

		return $value;
	}//end citedKeyValue()

	/**
	 * Rows of a schema whose property holds a key value, or null when that cannot be read.
	 *
	 * @param array<string, mixed> $dep   The relation index entry: the citing schema, property and whether it is an array.
	 * @param string               $value The cited key value.
	 *
	 * @return ObjectEntity[]|null The citing rows, or null when unverifiable.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function findReferencingByKey(array $dep, string $value): ?array {
		$this->ensureSchemaDetail();

		if ($this->hasMagicTable(dep: $dep) === false) {
			// The broad uuid fallback cannot find a key, so this cannot be checked.
			$this->logger->warning(
				message: '[ReferentialIntegrity] key reference cannot be checked: citing schema has no register',
				context: ['schemaId' => $dep['sourceSchemaId'], 'property' => $dep['property']]
			);
			return null;
		}

		return $this->magicTableReferences(dep: $dep, value: $value);
	}//end findReferencingByKey()

	/**
	 * Find objects of a specific schema that reference a given UUID in a specific property.
	 *
	 * A lookup that fails on a table that exists answers null, never an empty
	 * list: "nothing cites it" would let a RESTRICT delete through and skip a
	 * CASCADE, as the key lookup already refuses (findReferencingByKey()).
	 *
	 * @param array<string, mixed> $dep        The relation index entry: the citing schema, property and whether it is an array.
	 * @param string               $targetUuid The UUID being referenced.
	 *
	 * @return ObjectEntity[]|null Matching objects, or null when the lookup could not be done.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function findReferencingObjects(array $dep, string $targetUuid): ?array {
		$this->ensureSchemaDetail();

		// Optimized path: search directly in the specific magic table using the property column.
		if ($this->hasMagicTable(dep: $dep) === true) {
			return $this->magicTableReferences(dep: $dep, value: $targetUuid);
		}

		// Fallback: broad search across all sources (only for schemas without register mapping).
		$candidates = $this->relationCandidates(uuid: $targetUuid);
		if ($candidates === null) {
			return null;
		}

		$reference = $dep + ['value' => $targetUuid];
		return array_values(
			array_filter($candidates, fn (ObjectEntity $candidate): bool => $this->cites(candidate: $candidate, reference: $reference))
		);
	}//end findReferencingObjects()

	/**
	 * The objects the broad relation search finds for a UUID.
	 *
	 * @param string $uuid The UUID being referenced.
	 *
	 * @return ObjectEntity[]|null The candidates, or null when the search failed.
	 */
	private function relationCandidates(string $uuid): ?array {
		try {
			return $this->objectEntityMapper->findByRelation(uuid: $uuid);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] findByRelation failed, so the delete is refused',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end relationCandidates()

	/**
	 * Whether the citing schema of a relation has a register, so its magic table can be searched.
	 *
	 * @param array<string, mixed> $dep The relation index entry.
	 *
	 * @return bool True when both its register and its schema are known.
	 */
	private function hasMagicTable(array $dep): bool {
		return ($this->schemaRegisterMap[$dep['sourceSchemaId']] ?? null) !== null
			&& ($this->schemaCache[$dep['sourceSchemaId']] ?? null) !== null;
	}//end hasMagicTable()

	/**
	 * The rows of the citing schema's magic table that hold a value, or null when that table cannot be read.
	 *
	 * No table yet answers an empty list: nothing can cite the object from there.
	 *
	 * @param array<string, mixed> $dep   The relation index entry, with a register known for its schema.
	 * @param string               $value The cited UUID or key value.
	 *
	 * @return ObjectEntity[]|null The citing rows, or null when unverifiable.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function magicTableReferences(array $dep, string $value): ?array {
		try {
			return $this->findReferencingInMagicTable(dep: $dep, targetUuid: $value);
		} catch (\Exception $e) {
			return $this->afterFailedLookup(dep: $dep, error: $e);
		}
	}//end magicTableReferences()

	/**
	 * What a failed magic table lookup answers: nothing for a table that does not exist yet, else unverifiable.
	 *
	 * @param array<string, mixed> $dep   The relation index entry, with a register known for its schema.
	 * @param \Exception           $error The failure.
	 *
	 * @return array{}|null An empty list, or null when unverifiable.
	 */
	private function afterFailedLookup(array $dep, \Exception $error): ?array {
		if ($this->hasBuiltTable(dep: $dep) === false) {
			return [];
		}

		$message = '[ReferentialIntegrity] reference could not be checked, so the delete is refused';
		if (($dep['referenceKey'] ?? null) !== null) {
			$message = '[ReferentialIntegrity] key reference could not be checked, so the delete is refused';
		}

		$this->logger->warning(
			message: $message,
			context: ['schemaId' => $dep['sourceSchemaId'], 'property' => $dep['property'], 'error' => $error->getMessage()]
		);
		return null;
	}//end afterFailedLookup()

	/**
	 * Whether the citing schema's magic table exists yet.
	 *
	 * @param array<string, mixed> $dep The relation index entry, with a register known for its schema.
	 *
	 * @return bool
	 */
	private function hasBuiltTable(array $dep): bool {
		$register = $this->schemaRegisterMap[$dep['sourceSchemaId']];
		$schema = $this->schemaCache[$dep['sourceSchemaId']];
		return $this->db->tableExists('openregister_table_' . $register->getId() . '_' . $schema->getId());
	}//end hasBuiltTable()

	/**
	 * Whether a candidate found by the broad relation search cites the value in the relation's property.
	 *
	 * @param ObjectEntity         $candidate The candidate.
	 * @param array<string, mixed> $reference The relation index entry with the cited `value`.
	 *
	 * @return bool True when the candidate is of the citing schema and its property holds the value.
	 */
	private function cites(ObjectEntity $candidate, array $reference): bool {
		if ($candidate->getSchema() !== $reference['sourceSchemaId']) {
			return false;
		}

		$propertyValue = ($candidate->getObject() ?? [])[$reference['property']] ?? null;
		if ($propertyValue === null) {
			return false;
		}

		if ($reference['isArray'] === true) {
			return is_array($propertyValue) === true && in_array($reference['value'], $propertyValue, true) === true;
		}

		return $propertyValue === $reference['value'];
	}//end cites()

	/**
	 * Search a specific magic table for objects whose property column contains the target UUID.
	 *
	 * Queries the property column directly (not _relations JSONB), avoiding full-table scans.
	 * Constructs minimal ObjectEntity objects from the results for use in cascade analysis.
	 *
	 * @param array<string, mixed> $dep        The relation index entry, with a register known for its schema.
	 * @param string               $targetUuid The UUID being referenced.
	 *
	 * @return ObjectEntity[] Matching objects.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function findReferencingInMagicTable(array $dep, string $targetUuid): array {
		// Validate inputs before database access (also ensures Psalm sees param usage before \OC:: call).
		if (empty($targetUuid) === true) {
			return [];
		}

		// Raw SQL: QueryBuilder cannot express the platform-specific JSON-array
		// containment operators required here — `JSON_CONTAINS(... JSON_QUOTE(?))`
		// for MariaDB and `column::jsonb @> to_jsonb(?::text)` for PostgreSQL.
		$stmt = $this->db->prepare($this->referenceSql(dep: $dep));
		$stmt->execute([$targetUuid]);
		$rows = $stmt->fetchAll();

		$results = [];
		foreach ($rows as $row) {
			$results[] = $this->referencingEntity(row: $row, reference: $dep + ['value' => $targetUuid]);
		}

		return $results;
	}//end findReferencingInMagicTable()

	/**
	 * The SQL that finds the live rows of the citing schema's table whose property column holds a value.
	 *
	 * @param array<string, mixed> $dep The relation index entry, with a register known for its schema.
	 *
	 * @return string The SQL, with one placeholder for the value.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function referenceSql(array $dep): string {
		$register = $this->schemaRegisterMap[$dep['sourceSchemaId']];
		$schema = $this->schemaCache[$dep['sourceSchemaId']];
		$platform = $this->db->getDatabasePlatform();

		// The table with the instance's prefix, and the column the way the table
		// was built (the schema's column map, collision suffixes included).
		// Nextcloud's QueryBuilder::getTableName() returns the prefixed table
		// ALREADY quoted for the platform, so it is used as is: quoting it again
		// doubled the quote characters, named a table that does not exist, and
		// the lookup's catch turned that into "nothing references this row"
		// (every CASCADE, RESTRICT and SET_NULL silently stopped). The column is
		// quoted by the connection's own platform: a double-quoted identifier is a
		// string literal to MySQL and MariaDB (no ANSI_QUOTES), so `"code" = ?`
		// compared the text 'code' with the value, matched nothing and let the
		// delete through. Null-safe via get_debug_type() (a mocked IDBConnection
		// returns a bare platform double in unit tests).
		$bareTable = $this->objectEntityMapper->getTableNameForRegisterSchema(register: $register, schema: $schema);
		$quotedTable = $this->db->getQueryBuilder()->getTableName($bareTable);
		$quotedCol = $platform->quoteIdentifier($this->columnOf(schema: $schema, propertyName: $dep['property']));

		$isPostgres = stripos(get_debug_type($platform), 'PostgreSQL') !== false;

		// For array properties we need JSON_CONTAINS / jsonb @> operators; for scalars a simple = suffices.
		$holdsValue = "{$quotedCol} = ?";
		if ($dep['isArray'] === true) {
			$holdsValue = self::jsonArrayHolds(column: $quotedCol, isPostgres: $isPostgres);
		}

		$liveRow = self::liveRowCondition(isPostgres: $isPostgres);
		return "SELECT _uuid, _register, _schema, _deleted, {$quotedCol} AS _prop FROM {$quotedTable} WHERE {$liveRow} AND {$holdsValue} LIMIT 100";
	}//end referenceSql()

	/**
	 * The condition for a row that is not soft-deleted.
	 *
	 * @param bool $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return string The SQL condition.
	 */
	private static function liveRowCondition(bool $isPostgres): string {
		if ($isPostgres === true) {
			return "(_deleted IS NULL OR _deleted = 'null'::jsonb)";
		}

		return '_deleted IS NULL';
	}//end liveRowCondition()

	/**
	 * The condition for a JSON array column that holds the value.
	 *
	 * @param string $column     The quoted column.
	 * @param bool   $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return string The SQL condition, with one placeholder for the value.
	 */
	private static function jsonArrayHolds(string $column, bool $isPostgres): string {
		if ($isPostgres === true) {
			return "{$column}::jsonb @> to_jsonb(?::text)";
		}

		return "JSON_CONTAINS({$column}, JSON_QUOTE(?))";
	}//end jsonArrayHolds()

	/**
	 * A minimal entity for one citing row: its uuid, register, schema, deletion and the matched property.
	 *
	 * @param array<string, mixed> $row       The row.
	 * @param array<string, mixed> $reference The relation index entry with the cited `value`.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function referencingEntity(array $row, array $reference): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($row['_uuid']);
		$entity->setRegister($row['_register'] ?? (string)$this->schemaRegisterMap[$reference['sourceSchemaId']]->getId());
		$entity->setSchema($row['_schema'] ?? (string)$this->schemaCache[$reference['sourceSchemaId']]->getId());

		$deletion = self::rowDeletion(row: $row);
		if ($deletion !== null) {
			$entity->setDeleted($deletion);
		}

		// Set object with at least the property that matched.
		$entity->setObject([$reference['property'] => $row['_prop'] ?? $reference['value']]);
		return $entity;
	}//end referencingEntity()

	/**
	 * The deletion a row carries.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>|null The deletion, empty when it cannot be read, or null for a live row.
	 */
	private static function rowDeletion(array $row): ?array {
		$deleted = $row['_deleted'] ?? null;
		if ($deleted === null || $deleted === 'null') {
			return null;
		}

		return self::decodedDeletion(deleted: $deleted);
	}//end rowDeletion()

	/**
	 * A stored deletion as an array.
	 *
	 * @param mixed $deleted The stored value, JSON text or already decoded.
	 *
	 * @return array<string, mixed> The deletion, empty when it cannot be read.
	 */
	private static function decodedDeletion(mixed $deleted): array {
		if (is_string($deleted) === true) {
			$deleted = json_decode($deleted, true);
		}

		if (is_array($deleted) === true) {
			return $deleted;
		}

		return [];
	}//end decodedDeletion()

	/**
	 * The physical column of a schema property, as the schema's table was built.
	 *
	 * Asked of the mapper's own column map rather than derived here, so a
	 * property whose column carries a collision suffix, or a name the local
	 * camel-to-snake rule would spell differently, is found where it lives.
	 *
	 * @param Schema $schema       The citing schema.
	 * @param string $propertyName The citing property.
	 *
	 * @return string The column name.
	 *
	 * @throws RuntimeException When the schema's table has no column for the property.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	private function columnOf(Schema $schema, string $propertyName): string {
		$column = ($this->objectEntityMapper->buildTableColumnsFromSchema(schema: $schema)[$propertyName]['name'] ?? null);
		if (is_string($column) === false || $column === '') {
			throw new RuntimeException('The table of schema ' . $schema->getId() . ' has no column for property ' . $propertyName);
		}

		return $column;
	}//end columnOf()

	/**
	 * Check whether a property is required by the given schema.
	 *
	 * @param string $schemaId The schema identifier.
	 * @param string $propertyName The property name to check.
	 *
	 * @return bool True if the property is required.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isRequiredProperty(string $schemaId, string $propertyName): bool {
		$this->ensureSchemaDetail();

		$schema = $this->schemaCache[$schemaId] ?? null;
		if ($schema === null) {
			return false;
		}

		return in_array($propertyName, $schema->getRequired(), true);
	}//end isRequiredProperty()

	/**
	 * Get the default value for a property on a schema.
	 *
	 * @param string $schemaId The schema ID.
	 * @param string $propertyName The property name.
	 *
	 * @return mixed The default value, or null if not set.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function getDefaultValue(string $schemaId, string $propertyName): mixed {
		$this->ensureSchemaDetail();

		$schema = $this->schemaCache[$schemaId] ?? null;
		if ($schema === null) {
			return null;
		}

		$properties = $schema->getProperties();
		if ($properties === null || isset($properties[$propertyName]) === false) {
			return null;
		}

		return $properties[$propertyName]['default'] ?? null;
	}//end getDefaultValue()

	/**
	 * Log a referential integrity action to the audit trail.
	 *
	 * Creates an AuditTrail entry recording what integrity action was taken,
	 * on which object, triggered by what, and by whom. Failures are caught
	 * and logged as warnings to avoid blocking the integrity action.
	 *
	 * @param IntegrityAuditEntry $entry What happened to which object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function logIntegrityAction(IntegrityAuditEntry $entry): void {
		try {
			$auditTrail = self::integrityAuditRow(entry: $entry);

			// Expiry follows the retention of the object the row describes,
			// not a flat 30 days (or#4101): a cleared reference or a cascade on
			// a record kept for years must stay explainable for as long.
			$this->auditTrailMapper->applyRetentionExpiry(
				auditTrail: $auditTrail,
				objectEntity: ($entry->object ?? $this->findIntegrityAuditObject(objectUuid: $entry->objectUuid))
			);

			$this->auditTrailMapper->insert($auditTrail);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] Failed to create audit trail entry for integrity action',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'action' => $entry->action,
					'objectUuid' => $entry->objectUuid,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end logIntegrityAction()

	/**
	 * The audit row of an integrity action.
	 *
	 * @param IntegrityAuditEntry $entry What happened to which object.
	 *
	 * @return AuditTrail The row, not yet stored.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function integrityAuditRow(IntegrityAuditEntry $entry): AuditTrail {
		$auditTrail = new AuditTrail();
		$auditTrail->setUuid((string)Uuid::v4());
		$auditTrail->setObjectUuid($entry->objectUuid);
		$auditTrail->setAction($entry->action);
		$auditTrail->setChanged($entry->changed);
		$auditTrail->setUser($entry->userId);
		$auditTrail->setCreated(new DateTime());

		if ($entry->schemaId !== null) {
			$auditTrail->setSchema((int)$entry->schemaId);
		}

		if ($entry->registerId !== null) {
			$auditTrail->setRegister((int)$entry->registerId);
		}

		return $auditTrail;
	}//end integrityAuditRow()

	/**
	 * The object an integrity audit row describes, for its retention.
	 *
	 * Looked up with deleted objects included (a cascade target is already
	 * soft-deleted when its row is written) and without RBAC or tenancy
	 * scoping, since this is the system recording its own action. Null when
	 * it cannot be found, which keeps the row indefinitely.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function findIntegrityAuditObject(string $objectUuid): ?ObjectEntity {
		try {
			$object = ($this->objectEntityMapper->findAcrossAllSources(
				identifier: $objectUuid,
				includeDeleted: true,
				_rbac: false,
				_multitenancy: false
			)['object'] ?? null);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object instanceof ObjectEntity) {
			return $object;
		}

		return null;
	}//end findIntegrityAuditObject()

	/**
	 * Apply SET_NULL action: clear the reference in the dependent object.
	 *
	 * For array properties, removes the UUID from the array.
	 * For single properties, sets the value to null.
	 *
	 * @param array $target The nullify target from the DeletionAnalysis.
	 *
	 * @return ObjectEntity|null The updated object, or null when it could not be updated.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applySetNull(array $target): ?ObjectEntity {
		try {
			return $this->rewriteDependent(
				target: $target,
				rewrite: static fn (array $data): array => self::withoutReference(data: $data, target: $target)
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] Failed to apply SET_NULL',
				context: self::rewriteFailureContext(target: $target, error: $e)
			);
		}

		return null;
	}//end applySetNull()

	/**
	 * The dependent's data with the reference cleared: the source uuid taken out of an array
	 * property, null for a single one.
	 *
	 * @param array $data   The dependent object's data.
	 * @param array $target The nullify target.
	 *
	 * @return array The rewritten data.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function withoutReference(array $data, array $target): array {
		$current = ($data[$target['property']] ?? null);
		$data[$target['property']] = null;
		if (($target['isArray'] ?? false) === true && is_array($current) === true) {
			$data[$target['property']] = array_values(
				array_filter($current, static fn ($val): bool => $val !== $target['sourceUuid'])
			);
		}

		return $data;
	}//end withoutReference()

	/**
	 * Load a dependent object system-wide, rewrite its data and store it.
	 *
	 * @param array    $target  The target naming the dependent by `objectUuid`.
	 * @param \Closure $rewrite Maps the object's data to its new data.
	 *
	 * @return ObjectEntity The updated object.
	 *
	 * @throws \Exception When the object cannot be found or stored.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function rewriteDependent(array $target, \Closure $rewrite): ObjectEntity {
		$context = $this->objectEntityMapper->findAcrossAllSources(
			identifier: $target['objectUuid'],
			includeDeleted: false,
			_rbac: false,
			_multitenancy: false
		);
		$object  = $context['object'];
		$object->setObject($rewrite($object->getObject()));
		$this->objectEntityMapper->update(entity: $object, register: $context['register'], schema: $context['schema']);

		return $object;
	}//end rewriteDependent()

	/**
	 * The log context of a dependent that could not be rewritten.
	 *
	 * @param array      $target The target.
	 * @param \Exception $error  What went wrong.
	 *
	 * @return array The log context.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function rewriteFailureContext(array $target, \Exception $error): array {
		return ['objectUuid' => $target['objectUuid'], 'property' => $target['property'], 'error' => $error->getMessage()];
	}//end applySetNull()

	/**
	 * Apply SET_DEFAULT action: set the reference to the configured default value.
	 *
	 * @param array $target The default target from the DeletionAnalysis.
	 *
	 * @return ObjectEntity|null The updated object, or null when it could not be updated.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applySetDefault(array $target): ?ObjectEntity {
		try {
			return $this->rewriteDependent(
				target: $target,
				rewrite: static fn (array $data): array => array_replace($data, [$target['property'] => $target['defaultValue']])
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] Failed to apply SET_DEFAULT',
				context: self::rewriteFailureContext(target: $target, error: $e)
			);
		}

		return null;
	}//end applySetDefault()

	/**
	 * Apply CASCADE deletes with batched resolution, batched soft-delete writes
	 * and one multi-row audit INSERT.
	 *
	 * PERF: the previous implementation fed every target through
	 * MagicMapper::deleteObjects(), which runs a full cross-magic-table scan
	 * (findAcrossAllSources) PER UUID, and wrote one single-row audit INSERT per
	 * target. For N cascade targets that is ~N cross-table scans + N INSERTs
	 * inside the delete transaction. This method mirrors the legacy-cascade
	 * batch path (DeleteObject::cascadeDeleteObjects): ONE batched cross-table
	 * lookup, one CASE-UPDATE per magic table via
	 * MagicMapper::softDeleteMultipleObjectEntities() (which dispatches the
	 * per-object updating/updated event pairs), and ONE multi-row audit INSERT.
	 *
	 * Targets the uuid-based batch lookup cannot resolve — and every target
	 * when the batched resolve or write fails — fall back to the unchanged
	 * per-object pipeline ({@see applyPerObjectCascadeDelete()}), fail-soft.
	 *
	 * @param array               $cascadeTargets The cascade targets from DeletionAnalysis (already reversed).
	 * @param CascadeDeleteOrigin $origin         The deleting user, the root object and the organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applyBatchCascadeDelete(array $cascadeTargets, CascadeDeleteOrigin $origin): void {
		$resolved = $this->resolveCascadeTargets(cascadeTargets: $cascadeTargets);
		foreach ($resolved as $target) {
			$this->objectEntityMapper->assertDeletable(entity: $target);
		}

		[$handled, $deleted] = $this->batchCascadeSoftDelete(targets: $resolved, origin: $origin);
		$this->writeBatchCascadeAuditTrails(cascadeTargets: $cascadeTargets, deleted: $deleted, origin: $origin);

		$fallbackTargets = array_values(
			array_filter($cascadeTargets, static fn (array $target): bool => isset($handled[$target['objectUuid']]) === false)
		);
		if (empty($fallbackTargets) === false) {
			$this->applyPerObjectCascadeDelete(cascadeTargets: $fallbackTargets, origin: $origin);
		}
	}//end applyBatchCascadeDelete()

	/**
	 * The live cascade targets, found in one batched cross-table lookup; none when it fails.
	 *
	 * Already soft-deleted targets are not wanted: they stay on the per-object fallback, which
	 * no-ops their write.
	 *
	 * @param array $cascadeTargets The cascade targets.
	 *
	 * @return ObjectEntity[] The resolved targets.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveCascadeTargets(array $cascadeTargets): array {
		$uuids = array_values(array_unique(array_map(static fn (array $target): string => (string) $target['objectUuid'], $cascadeTargets)));
		try {
			return $this->objectEntityMapper->findStoredAcrossAllMagicTables(uuids: $uuids, includeDeleted: false);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] Batched cascade lookup failed; falling back to per-object deletes',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return [];
	}//end applyBatchCascadeDelete()

	/**
	 * Soft-delete a batch of resolved cascade targets.
	 *
	 * Applies the same deletion attribution metadata shape as
	 * DeleteObject::delete() and the legacy-cascade batch write, then persists
	 * via one UPDATE per magic table (MagicMapper::softDeleteMultipleObjectEntities,
	 * which also dispatches the per-object updating/updated event pairs — a
	 * hook stopping propagation skips that target's write and the target is
	 * NOT force-retried per-object).
	 *
	 * @param ObjectEntity[]      $targets The batch-resolved cascade target entities.
	 * @param CascadeDeleteOrigin $origin  The deleting user and the organisation, for attribution.
	 *
	 * @return array{0: array<string, true>, 1: array<string, ObjectEntity>} Two maps keyed
	 *                                                                       by uuid: [0] every target the batch took care of
	 *                                                                       (deleted OR deliberately skipped by a hook — the
	 *                                                                       caller must not retry these), [1] the targets that
	 *                                                                       were actually soft-deleted (audit rows are written
	 *                                                                       for these). Both empty when the batched write
	 *                                                                       failed entirely — the caller then falls back to
	 *                                                                       the per-object pipeline for every target.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function batchCascadeSoftDelete(array $targets, CascadeDeleteOrigin $origin): array {
		if (empty($targets) === true) {
			return [[], []];
		}

		[$handled, $oldEntities] = (new SoftDeleteStamp(deletedBy: $origin->userId, organisation: $origin->organisationId))->apply(targets: $targets);

		$deletedEntities = $this->softDeleteBatch(targets: $targets, oldEntities: $oldEntities);
		if ($deletedEntities === null) {
			// Nothing was handled — the caller retries every target via the
			// per-object pipeline.
			return [[], []];
		}

		return [$handled, self::keyedByUuid(entities: $deletedEntities)];
	}//end batchCascadeSoftDelete()

	/**
	 * Soft-delete stamped targets in one statement.
	 *
	 * @param array $targets     The stamped entities.
	 * @param array $oldEntities The entities as they were.
	 *
	 * @return array|null The deleted entities, or null when the statement failed.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function softDeleteBatch(array $targets, array $oldEntities): array|null {
		try {
			return $this->objectEntityMapper->softDeleteMultipleObjectEntities(
				entities: $targets,
				oldEntities: $oldEntities
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ReferentialIntegrity] Batched cascade soft delete failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end softDeleteBatch()

	/**
	 * Entities by uuid; one without a uuid is left out.
	 *
	 * @param array $entities The entities.
	 *
	 * @return array<string, ObjectEntity> The entities.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function keyedByUuid(array $entities): array {
		$keyed = [];
		foreach ($entities as $entity) {
			$uuid = $entity->getUuid();
			if ($uuid !== null && $uuid !== '') {
				$keyed[$uuid] = $entity;
			}
		}

		return $keyed;
	}//end keyedByUuid()

	/**
	 * Write the audit rows for batch-deleted cascade targets in one bulk INSERT.
	 *
	 * One row per analysis target (a target referenced through two properties
	 * gets two rows, matching the per-object path), each pre-built through
	 * AuditTrailMapper::buildAuditTrail() with action
	 * `referential_integrity.cascade_delete` and the canonical cascade-context
	 * fold (triggerObject/triggerSchema/action_type/property). Audit failures
	 * are logged but never abort the cascade — parity with the per-object
	 * path's fail-soft logIntegrityAction().
	 *
	 * @param array $cascadeTargets All cascade targets (already reversed).
	 * @param array<string, ObjectEntity> $deleted Batch-deleted entities keyed by uuid.
	 * @param CascadeDeleteOrigin $origin The root object and its schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function writeBatchCascadeAuditTrails(array $cascadeTargets, array $deleted, CascadeDeleteOrigin $origin): void {
		$rows = [];
		foreach ($cascadeTargets as $target) {
			$entity = ($deleted[$target['objectUuid']] ?? null);
			if ($entity !== null) {
				$rows[] = $this->cascadeAuditRow(entity: $entity, cascadeContext: $origin->auditContext(target: $target));
			}
		}

		$this->insertCascadeAuditRows(rows: array_values(array_filter($rows)));
	}//end writeBatchCascadeAuditTrails()

	/**
	 * The audit row of one batch-deleted cascade target, or null when it cannot be built.
	 *
	 * @param ObjectEntity $entity         The deleted entity.
	 * @param array        $cascadeContext The cascade context folded into the row.
	 *
	 * @return mixed The built row, or null.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function cascadeAuditRow(ObjectEntity $entity, array $cascadeContext): mixed {
		try {
			return $this->auditTrailMapper->buildAuditTrail(
				old: $entity,
				new: null,
				action: 'referential_integrity.cascade_delete',
				cascadeContext: $cascadeContext
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] Could not build cascade audit row',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $entity->getUuid(), 'error' => $e->getMessage()]
			);
		}

		return null;
	}//end cascadeAuditRow()

	/**
	 * Insert the cascade audit rows in one statement; a failure is logged, never thrown.
	 *
	 * @param array $rows The built audit rows.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function insertCascadeAuditRows(array $rows): void {
		if (empty($rows) === true) {
			return;
		}

		try {
			$this->auditTrailMapper->insertAuditTrails(entries: $rows);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] Bulk cascade audit insert failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'count' => count($rows), 'error' => $e->getMessage()]
			);
		}
	}//end writeBatchCascadeAuditTrails()

	/**
	 * Apply CASCADE deletes per object, grouped by register+schema (legacy fallback).
	 *
	 * The fallback pipeline for targets the batched path cannot handle: each
	 * group goes to MagicMapper::deleteObjects() in bulk (which re-resolves
	 * each uuid individually), and each target gets its own single-row audit
	 * insert with the legacy changed-shape.
	 *
	 * @param array               $cascadeTargets The cascade targets needing per-object handling.
	 * @param CascadeDeleteOrigin $origin         The deleting user and the root object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applyPerObjectCascadeDelete(array $cascadeTargets, CascadeDeleteOrigin $origin): void {
		foreach ($this->groupCascadeTargets(cascadeTargets: $cascadeTargets) as $targets) {
			$this->deleteCascadeGroup(targets: $targets, origin: $origin);
			foreach ($targets as $target) {
				$this->logCascadeDelete(target: $target, origin: $origin);
			}
		}
	}//end applyPerObjectCascadeDelete()

	/**
	 * Group cascade targets by register+schema, in order of first appearance.
	 *
	 * A target without register or schema gets its own group keyed by its
	 * uuid, so a repeated one is deleted and audited once.
	 *
	 * @param array $cascadeTargets The cascade targets.
	 *
	 * @return array<string, list<array>> The targets per group.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function groupCascadeTargets(array $cascadeTargets): array {
		$groups = [];
		foreach ($cascadeTargets as $target) {
			if (($target['register'] ?? null) === null || ($target['schema'] ?? null) === null) {
				$groups['fallback_' . $target['objectUuid']] = [$target];
				continue;
			}

			$groups[$target['register'] . '::' . $target['schema']][] = $target;
		}

		return $groups;
	}//end groupCascadeTargets()

	/**
	 * Soft-delete one group of cascade targets; a failure is logged, not thrown.
	 *
	 * @param list<array>         $targets The targets of one group.
	 * @param CascadeDeleteOrigin $origin  The deleting user and the root object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function deleteCascadeGroup(array $targets, CascadeDeleteOrigin $origin): void {
		$uuids = array_map(static fn (array $t): string => $t['objectUuid'], $targets);
		try {
			$this->objectEntityMapper->deleteObjects(uuids: $uuids, hardDelete: false);
		} catch (HookStoppedException $refusal) {
			throw $refusal;
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ReferentialIntegrity] Batch CASCADE delete failed',
				context: ['uuids' => $uuids, 'cascadeSource' => $origin->cascadeSource, 'error' => $e->getMessage()]
			);
		}
	}//end deleteCascadeGroup()

	/**
	 * Write the legacy single-row audit entry of one cascade-deleted target.
	 *
	 * @param array               $target The cascade target.
	 * @param CascadeDeleteOrigin $origin The deleting user and the root object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function logCascadeDelete(array $target, CascadeDeleteOrigin $origin): void {
		$this->logIntegrityAction(
			entry: (new IntegrityAuditEntry(
				action: 'referential_integrity.cascade_delete',
				objectUuid: $target['objectUuid'],
				changed: [
					'deletedBecause' => 'cascade',
					'triggerObject' => $origin->cascadeSource,
					'triggerSchema' => $origin->triggerSchemaSlug,
					'property' => $target['property'],
				],
				userId: $origin->userId
			))->within(schemaId: $target['schema'] ?? null, registerId: $target['register'] ?? null)
		);
	}//end logCascadeDelete()
}//end class
