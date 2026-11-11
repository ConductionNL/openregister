<?php

/**
 * Data-Subject Access Request (DSAR) service.
 *
 * Composes the existing GdprEntity index + entity_relations join +
 * MagicMapper object lookup into the four AVG / GDPR data-subject
 * rights flows:
 *
 *   - Art 15 (inzage)        — locate every object referencing a subject.
 *   - Art 17 (vergetelheid)  — soft-delete every object referencing a subject.
 *   - Art 20 (portabiliteit) — same locate, rendered for export.
 *   - Art 16 (rectificatie)  — single-object update wrapper.
 *
 * All write paths set the transient
 * `ObjectEntity::setProcessingActivityId()` before persisting so the
 * existing AuditTrailMapper hook (Phase 1) tags the audit row with
 * the operator-configured DSAR processing-activity uuid. The configured
 * activity is read from app-config key `dsar_processing_activity` and
 * resolved through `VerwerkingsactiviteitMapper::resolveReference`
 * (accepts both `code` and `uuid`). When unset, the audit row falls
 * back to the schema/register annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Data-Subject Access Request orchestrator.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DsarService implements DsarServiceInterface {

	/**
	 * App-config key for the DSAR processing-activity reference.
	 *
	 * Operators register one Verwerkingsactiviteit (typically with
	 * code `dsar`) describing the legal basis + purpose of DSAR
	 * handling itself, then point this app-config key at it. All audit
	 * rows produced by `eraseObjectsForSubject` /
	 * `rectifyObjectForSubject` will be attributed to that activity.
	 *
	 * @var string
	 */
	public const APP_CONFIG_DSAR_ACTIVITY = 'dsar_processing_activity';

	/**
	 * Default reference used when the app-config key is unset. Maps to
	 * the operator-registered activity that has `code='dsar'`.
	 *
	 * @var string
	 */
	public const DEFAULT_DSAR_ACTIVITY_CODE = 'dsar';

	/**
	 * The entity and relation columns a subject match returns.
	 *
	 * @var list<string>
	 */
	private const MATCHED_ENTITY_COLUMNS = [
		'e.id',
		'e.type',
		'e.value',
		'e.category',
		'e.detected_at',
		'r.object_id',
		'r.object_uuid',
		'r.register_id',
		'r.schema_id',
	];

	/**
	 * Database connection for the GdprEntity and entity_relations lookup.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Object-routing mapper used to load found objects.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectMapper;

	/**
	 * Resolves the configured DSAR activity reference.
	 *
	 * @var VerwerkingsactiviteitMapperInterface
	 */
	private readonly VerwerkingsactiviteitMapperInterface $vrwMapper;

	/**
	 * App-config reader.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Current user, for soft-delete metadata and the in-service privilege guard.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager used by the in-service admin guard.
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Refuses erasure of a legally retained record and puts the refusal into words.
	 *
	 * @var ArchivalRetentionGuard
	 */
	private readonly ArchivalRetentionGuard $archivalGuard;

	/**
	 * Constructor.
	 *
	 * @param DsarStores      $stores The relation tables, the object mapper, the activity mapper and app config.
	 * @param DsarGuards      $guards The current user, the group manager and the archival retention guard.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		DsarStores $stores,
		DsarGuards $guards,
		private readonly LoggerInterface $logger,
	) {
		$this->db = $stores->db;
		$this->objectMapper = $stores->objectMapper;
		$this->vrwMapper = $stores->vrwMapper;
		$this->appConfig = $stores->appConfig;
		$this->userSession = $guards->userSession;
		$this->groupManager = $guards->groupManager;
		$this->archivalGuard = $guards->archivalGuard;
	}//end __construct()

	/**
	 * Fail-closed privilege guard for all DSAR composition entry points.
	 *
	 * DSAR flows load + mutate objects across the entire register
	 * surface with `_rbac:false` and `_multitenancy:false`, deliberately
	 * bypassing per-object access control because a privacy officer must
	 * be able to reach every object referencing a data subject. That
	 * makes this service a cross-tenant amplifier: any caller able to
	 * invoke it can read or erase PII for arbitrary subjects regardless
	 * of tenant.
	 *
	 * `DsarController` already admin-gates every endpoint, but a service
	 * this powerful must not rely solely on its callers. This guard is
	 * defense-in-depth: it throws unless the active user is an admin, so
	 * a future caller that forgets to gate (or a mis-wired route) cannot
	 * silently expose the bypass. Authorized (admin) callers are
	 * unaffected — the response shape is unchanged for them.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the active user is not an admin.
	 */
	private function assertPrivileged(): void {
		$user = $this->userSession->getUser();
		if ($user !== null && $this->groupManager->isAdmin($user->getUID()) === true) {
			return;
		}

		$this->logger->warning(
			message: '[DSAR] Blocked unprivileged DSAR composition call',
			context: ['user' => $user?->getUID() ?? 'anonymous']
		);

		throw new RuntimeException('DSAR operations require administrator privileges');
	}//end assertPrivileged()

	/**
	 * Find every object that contains personal data for the given subject.
	 *
	 * Walks `oc_openregister_entities` for rows whose `value` matches
	 * the subject (case-insensitive exact match by default; pass
	 * `mode='ilike'` for substring), then joins
	 * `oc_openregister_entity_relations.object_id` to dedupe and load
	 * the owning ObjectEntity rows.
	 *
	 * Returns a list of envelopes:
	 *
	 *   [
	 *     [
	 *       'object'        => <ObjectEntity::jsonSerialize() output>,
	 *       'gdprEntities'  => [{type, value, category, detectedAt}, ...]
	 *     ],
	 *     ...
	 *   ]
	 *
	 * @param string $subject Subject identifier value (email, BSN, …).
	 * @param string|null $type Optional GdprEntity type filter (e.g. `email`).
	 * @param string $mode `exact` (default) or `ilike`.
	 *
	 * @return array<int, array{object: array, gdprEntities: array}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function findObjectsForSubject(string $subject, ?string $type = null, string $mode = 'exact'): array {
		return $this->findObjectsFor(query: new DsarSubjectQuery(subject: $subject, type: $type, mode: $mode));
	}//end findObjectsForSubject()

	/**
	 * Find every object that contains personal data for a data subject, as {@see findObjectsForSubject()} describes.
	 *
	 * @param DsarSubjectQuery $query The subject identifier, optional GdprEntity type filter and match mode.
	 *
	 * @return array<int, array{object: array, gdprEntities: array}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function findObjectsFor(DsarSubjectQuery $query): array {
		$this->assertPrivileged();

		$query = $query->trimmed();
		if ($query->subject === '') {
			return [];
		}

		$hits = $this->matchEntities(query: $query);
		if ($hits === []) {
			return [];
		}

		return $this->subjectEnvelopes(hits: $hits);
	}//end findObjectsFor()

	/**
	 * Each matched object that still loads, with the personal data found in it.
	 *
	 * @param array<int, array<string, mixed>> $hits The entity matches.
	 *
	 * @return array<int, array{object: array, gdprEntities: array}>
	 */
	private function subjectEnvelopes(array $hits): array {
		$envelopes = [];
		foreach ($this->groupHitsByObject(hits: $hits) as $entry) {
			$object = $this->loadObjectByEntry(entry: $entry);
			if ($object === null) {
				continue;
			}

			$envelopes[] = [
				'object' => $object->jsonSerialize(),
				'gdprEntities' => $entry['gdprEntities'],
			];
		}//end foreach

		return $envelopes;
	}//end subjectEnvelopes()

	/**
	 * Soft-delete every object matching a subject (Art 17 vergetelheid).
	 *
	 * The deletion itself is audit-logged for legal defence (the
	 * verwerkingsactiviteit configured under `dsar_processing_activity`
	 * provides the legal basis for keeping a deletion record); the
	 * object's personal data is removed from active records by setting
	 * the `deleted` metadata column.
	 *
	 * Returns a summary envelope:
	 *
	 *   [
	 *     'subject'      => '<echo>',
	 *     'type'         => '<echo|null>',
	 *     'dryRun'       => bool,
	 *     'matchedCount' => int,
	 *     'erased'       => [
	 *       ['uuid' => '<object-uuid>', 'register' => '<...>', 'schema' => '<...>'],
	 *       ...
	 *     ],
	 *     'withheld'     => [
	 *       ['uuid' => '...', 'schema' => '...', 'ground' => 'ARCHIVAL_RETENTION_OBLIGATION',
	 *        'message' => '...', 'basis' => '...', 'action' => '...'],
	 *       ...
	 *     ],
	 *     'withheldCount' => <int>,
	 *   ]
	 *
	 * RETENTION WINS OVER ERASURE. A record on a schema declaring
	 * `x-openregister-archival` is kept under a legal obligation, and art-17(3)(b)
	 * stands down for it. Those rows are refused, recorded and reported in
	 * `withheld` with the ground and the wording to pass back to the data subject.
	 * The refusal is decided by
	 * {@see \OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard}, which asks
	 * {@see \OCA\OpenRegister\Db\Schema::hasArchivalAnnotation()}, the same single
	 * definition the four HTTP delete doors already use.
	 *
	 * Withholding one record never blocks the rest: every non-archival match in
	 * the same request is still erased. A run that withheld anything reports
	 * `complete: false`, because the data is still there.
	 *
	 * @param string $subject Subject identifier.
	 * @param string|null $type Optional GdprEntity type filter.
	 * @param bool $dryRun When true, returns matches without erasing.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function eraseObjectsForSubject(string $subject, ?string $type = null, bool $dryRun = false): array {
		return $this->eraseObjectsFor(query: new DsarSubjectQuery(subject: $subject, type: $type, mode: 'exact'), dryRun: $dryRun);
	}//end eraseObjectsForSubject()

	/**
	 * Erase every object that matches a data subject, as {@see eraseObjectsForSubject()} describes.
	 *
	 * @param DsarSubjectQuery $query  The subject identifier and optional GdprEntity type filter (exact match).
	 * @param bool             $dryRun When true, returns matches without erasing.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function eraseObjectsFor(DsarSubjectQuery $query, bool $dryRun): array {
		$this->assertPrivileged();

		$entries = $this->erasureEntries(query: $query);

		$summary = [
			'subject' => $query->subject,
			'type' => $query->type,
			'dryRun' => $dryRun,
			'matchedCount' => count($entries),
		];

		$run = new DsarErasureRun();
		if ($dryRun === false && $entries !== []) {
			$run = $this->eraseEntries(entries: $entries, subject: $query->subject);
		}

		// BUG-SVC-2: objects whose erasure failed are reported, so a run with failures
		// is not read as a complete erasure. RETENTION WINS OVER ERASURE: records held
		// under an archival obligation are refused and named in `withheld`; a withheld
		// record makes the erasure incomplete exactly as a failure does, because the
		// row is still there. The counts stay separate: a failure is retried, a
		// withheld record is explained.
		return $summary + $run->outcome();
	}//end eraseObjectsFor()

	/**
	 * The objects a subject query matches, deduped by canonical key: uuid (preferred) or int id (legacy).
	 *
	 * @param DsarSubjectQuery $query The subject, type and mode.
	 *
	 * @return array<string, array{object_id: int, object_uuid: string}> The objects by canonical key.
	 */
	private function erasureEntries(DsarSubjectQuery $query): array {
		return array_map(
			static fn (array $group): array => ['object_id' => $group['object_id'], 'object_uuid' => $group['object_uuid']],
			$this->groupHitsByObject(hits: $this->matchEntities(query: $query))
		);
	}//end erasureEntries()

	/**
	 * Erase every matched object under one erasure run.
	 *
	 * @param array<string, array{object_id: int, object_uuid: string}> $entries The matched objects.
	 * @param string                                                 $subject The data subject.
	 *
	 * @return DsarErasureRun What was erased, failed and withheld.
	 */
	private function eraseEntries(array $entries, string $subject): DsarErasureRun {
		$run = $this->startErasure(subject: $subject);
		foreach ($entries as $entry) {
			$this->eraseOneEntry(entry: $entry, run: $run);
		}

		return $run;
	}//end eraseEntries()

	/**
	 * Erase one matched object, or record why it was not erased.
	 *
	 * EVERY ENTRY LANDS IN EXACTLY ONE BUCKET of `$summary`: `erased`, `failed`
	 * or `withheld`. An entry that reached none of them would be a match the
	 * caller is never told about.
	 *
	 * @param array<string, mixed> $entry The deduped match (object_id / object_uuid).
	 * @param DsarErasureRun       $run   The run: stamp, activity and buckets.
	 *
	 * @return void
	 */
	private function eraseOneEntry(array $entry, DsarErasureRun $run): void {
		$object = $this->loadObjectByEntry(entry: $entry);
		if ($object === null) {
			$this->failUnloadable(entry: $entry, run: $run);
			return;
		}

		if ($this->withholdArchival(object: $object, run: $run) === true) {
			return;
		}

		$error = $this->softDelete(object: $object, run: $run);
		if ($error !== null) {
			$run->fail(entry: $entry, error: $error);
			$this->logSoftDeleteFailure(entry: $entry, error: $error);
		}
	}//end eraseOneEntry()


	/**
	 * Record a matched object that could not be loaded as a failure.
	 *
	 * BUG-SVC-2: a matched object we cannot load was NOT erased — record it as
	 * a failure rather than silently skipping it.
	 *
	 * @param array<string, mixed> $entry The deduped match (object_id / object_uuid).
	 * @param DsarErasureRun       $run   The run: stamp, activity and buckets.
	 *
	 * @return void
	 */
	private function failUnloadable(array $entry, DsarErasureRun $run): void {
		$run->fail(entry: $entry, error: 'Object could not be loaded for erasure');
		$this->logger->warning(
			message: '[DSAR] Matched object could not be loaded during vergetelheid',
			context: ['object' => $entry]
		);
	}//end failUnloadable()

	/**
	 * Withhold an object held under an archival obligation.
	 *
	 * RETENTION WINS OVER ERASURE. Vergetelheid is art-17, and art-17(3)(b)
	 * stands down for processing the law requires: an archival record is
	 * kept, the refusal is recorded, and it travels back in `withheld` so
	 * the officer answering the data subject can name it. The refusal is
	 * per row, so every non-archival match in the same request is still
	 * erased.
	 *
	 * @param ObjectEntity   $object The loaded object.
	 * @param DsarErasureRun $run    The run: stamp, activity and buckets.
	 *
	 * @return bool True when the object is withheld and must not be erased.
	 */
	private function withholdArchival(ObjectEntity $object, DsarErasureRun $run): bool {
		$refusal = $this->archivalGuard->erasureRefusal(object: $object);
		if ($refusal === null) {
			return false;
		}

		$run->withheld[] = $refusal;
		return true;
	}//end withholdArchival()

	/**
	 * Stamp and soft-delete one object, recording it as erased.
	 *
	 * @param ObjectEntity   $object The loaded object.
	 * @param DsarErasureRun $run    The run: stamp, activity and buckets.
	 *
	 * @return string|null Why the update failed, or null when the object was erased.
	 */
	private function softDelete(ObjectEntity $object, DsarErasureRun $run): ?string {
		$run->stamp(object: $object);

		try {
			$this->objectMapper->update(entity: $object);
			$run->erased[] = [
				'uuid' => $object->getUuid(),
				'register' => $object->getRegister(),
				'schema' => $object->getSchema(),
			];
			return null;
		} catch (\Throwable $e) {
			return $e->getMessage();
		}
	}//end softDelete()

	/**
	 * Log a soft-delete that failed.
	 *
	 * @param array<string, mixed> $entry The deduped match (object_id / object_uuid).
	 * @param string               $error Why the update failed.
	 *
	 * @return void
	 */
	private function logSoftDeleteFailure(array $entry, string $error): void {
		$this->logger->warning(
			message: '[DSAR] Soft-delete failed during vergetelheid',
			context: ['object' => $entry, 'error' => $error]
		);
	}//end logSoftDeleteFailure()

	/**
	 * A run that erases on behalf of the current user (or `system`) for a subject,
	 * under the configured DSAR processing activity.
	 *
	 * @param string $subject Subject identifier.
	 *
	 * @return DsarErasureRun
	 */
	private function startErasure(string $subject): DsarErasureRun {
		$activityUuid = $this->getDsarProcessingActivityUuid();
		$run = new DsarErasureRun(
			deletionData: [
				'deletedBy' => ($this->userSession->getUser()?->getUID() ?? 'system'),
				'deletedAt' => (new DateTime())->format(DateTime::ATOM),
				'reason' => 'avg-vergetelheid',
				'subject' => $subject,
			]
		);
		$run->activityUuid = $activityUuid;
		return $run;
	}//end startErasure()

	/**
	 * Group entity hits by the canonical object key: `object_uuid` (globally unique
	 * across magic tables) when present, else the legacy int `object_id`; hits with
	 * neither are dropped. The first hit of an object sets its ids.
	 *
	 * @param array<int, array<string, mixed>> $hits Rows from matchEntities.
	 *
	 * @return array<string, array{object_id: int, object_uuid: string, gdprEntities: array}>
	 */
	private function groupHitsByObject(array $hits): array {
		$groups = [];
		foreach ($hits as $hit) {
			$key = $this->buildObjectKey(hit: $hit);
			if ($key === null) {
				continue;
			}

			$groups[$key] ??= self::emptyGroup(hit: $hit);
			$groups[$key]['gdprEntities'][] = self::gdprEntity(hit: $hit);
		}

		return $groups;
	}//end groupHitsByObject()

	/**
	 * The group of a matched object before its personal data is added.
	 *
	 * @param array<string, mixed> $hit The first match for the object.
	 *
	 * @return array{object_id: int, object_uuid: string, gdprEntities: array}
	 */
	private static function emptyGroup(array $hit): array {
		return [
			'object_id' => (int)($hit['object_id'] ?? 0),
			'object_uuid' => (string)($hit['object_uuid'] ?? ''),
			'gdprEntities' => [],
		];
	}//end emptyGroup()

	/**
	 * One piece of personal data a match found.
	 *
	 * @param array<string, mixed> $hit The match.
	 *
	 * @return array{type: string, value: string, category: string, detectedAt: string}
	 */
	private static function gdprEntity(array $hit): array {
		return [
			'type' => (string)($hit['type'] ?? ''),
			'value' => (string)($hit['value'] ?? ''),
			'category' => (string)($hit['category'] ?? ''),
			'detectedAt' => (string)($hit['detected_at'] ?? ''),
		];
	}//end gdprEntity()

	/**
	 * Build a dedup key for a relation hit row.
	 *
	 * Prefers `object_uuid` when present (post-disambiguation migration);
	 * falls back to `object_id` for legacy rows. Returns null when the
	 * row carries neither — the hit is dropped.
	 *
	 * @param array<string, mixed> $hit Single SQL row from matchEntities.
	 *
	 * @return string|null Dedup key, or null when the row is unusable.
	 */
	private function buildObjectKey(array $hit): ?string {
		$uuid = (string)($hit['object_uuid'] ?? '');
		if ($uuid !== '') {
			return 'uuid:' . $uuid;
		}

		$id = (int)($hit['object_id'] ?? 0);
		if ($id > 0) {
			return 'id:' . $id;
		}

		return null;
	}//end buildObjectKey()

	/**
	 * Load the owning ObjectEntity for a deduplicated hit entry.
	 *
	 * Uses uuid lookup when the relation row carries one (deterministic
	 * across magic-tables), otherwise falls back to the int id (best
	 * effort, may collide on the legacy schema).
	 *
	 * @param array{object_id: int, object_uuid: string} $entry Dedup entry.
	 *
	 * @return ObjectEntity|null
	 */
	private function loadObjectByEntry(array $entry): ?ObjectEntity {
		$identifier = self::entryIdentifier(entry: $entry);
		if ($identifier === 0 || $identifier === '') {
			return null;
		}

		return $this->findUnscoped(identifier: $identifier);
	}//end loadObjectByEntry()

	/**
	 * The identifier a matched object is loaded by: its uuid, else its id.
	 *
	 * @param array{object_id: int, object_uuid: string} $entry The matched object.
	 *
	 * @return int|string
	 */
	private static function entryIdentifier(array $entry): int|string {
		$identifier = $entry['object_id'];
		if ($entry['object_uuid'] !== '') {
			$identifier = $entry['object_uuid'];
		}

		return $identifier;
	}//end entryIdentifier()

	/**
	 * Load an object outside RBAC and tenancy; null when it is gone or fails to load.
	 *
	 * @param int|string $identifier The uuid or id.
	 *
	 * @return ObjectEntity|null
	 */
	private function findUnscoped(int|string $identifier): ?ObjectEntity {
		try {
			return $this->objectMapper->find(
				$identifier,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			$this->logLoadFailure(identifier: $identifier, error: $e);
			return null;
		}
	}//end findUnscoped()

	/**
	 * Log a matched object that failed to load.
	 *
	 * @param int|string $identifier The uuid or id.
	 * @param \Throwable $error      Why it failed.
	 *
	 * @return void
	 */
	private function logLoadFailure(int|string $identifier, \Throwable $error): void {
		$this->logger->debug(
			message: '[DSAR] Failed to load object',
			context: ['identifier' => $identifier, 'error' => $error->getMessage()]
		);
	}//end logLoadFailure()

	/**
	 * Update a single object with DSAR attribution (Art 16 rectificatie).
	 *
	 * Thin wrapper around the existing object update path that pins
	 * the audit row to the configured DSAR activity. Use this when a
	 * data subject requests correction of specific fields rather than
	 * a wholesale erasure.
	 *
	 * @param int $objectId Internal id of the object.
	 * @param array<string, mixed> $changes Property → new value map.
	 *
	 * @return array<string, mixed>|null Updated object envelope or null on miss.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-12
	 */
	public function rectifyObjectForSubject(int $objectId, array $changes): ?array {
		$this->assertPrivileged();

		$object = $this->findForRectification(objectId: $objectId);
		if ($object === null) {
			return null;
		}

		$this->applyRectification(object: $object, changes: $changes);
		if ($this->persistRectification(object: $object, objectId: $objectId) === false) {
			return null;
		}

		return $object->jsonSerialize();
	}//end rectifyObjectForSubject()


	/**
	 * Load the object to rectify, outside RBAC and tenancy.
	 *
	 * @param int $objectId Internal id of the object.
	 *
	 * @return ObjectEntity|null The object, or null when it cannot be loaded.
	 */
	private function findForRectification(int $objectId): ?ObjectEntity {
		try {
			return $this->objectMapper->find(
				$objectId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}
	}//end findForRectification()

	/**
	 * Merge the changes into the object and pin it to the DSAR activity.
	 *
	 * @param ObjectEntity         $object  The object to rectify.
	 * @param array<string, mixed> $changes Property → new value map.
	 *
	 * @return void
	 */
	private function applyRectification(ObjectEntity $object, array $changes): void {
		$current = $object->getObject() ?? [];
		$merged = array_merge($current, $changes);
		$object->setObject($merged);

		$dsarActivityUuid = $this->getDsarProcessingActivityUuid();
		if ($dsarActivityUuid !== null) {
			$object->setProcessingActivityId($dsarActivityUuid);
		}
	}//end applyRectification()

	/**
	 * Save a rectified object.
	 *
	 * @param ObjectEntity $object   The rectified object.
	 * @param int          $objectId Internal id of the object, for the log.
	 *
	 * @return bool False when the update failed (logged).
	 */
	private function persistRectification(ObjectEntity $object, int $objectId): bool {
		try {
			$this->objectMapper->update(entity: $object);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[DSAR] Rectificatie update failed',
				context: ['objectId' => $objectId, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end persistRectification()

	/**
	 * Get the configured DSAR Verwerkingsactiviteit uuid.
	 *
	 * Reads the app-config key, falling back to the
	 * `DEFAULT_DSAR_ACTIVITY_CODE` literal. Resolves either form
	 * through the existing reference resolver. Returns null when
	 * neither resolves — the caller falls through to schema/register
	 * defaults in that case.
	 *
	 * @return string|null Resolved activity uuid, or null.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-11
	 */
	public function getDsarProcessingActivityUuid(): ?string {
		try {
			$reference = (string)$this->appConfig->getValueString(
				app: 'openregister',
				key: self::APP_CONFIG_DSAR_ACTIVITY,
				default: self::DEFAULT_DSAR_ACTIVITY_CODE
			);
		} catch (\Throwable $e) {
			$reference = self::DEFAULT_DSAR_ACTIVITY_CODE;
		}

		if ($reference === '') {
			return null;
		}

		$activity = $this->vrwMapper->resolveReference(reference: $reference);
		return $activity?->getUuid();
	}//end getDsarProcessingActivityUuid()

	/**
	 * Run the SQL join used by inzage / vergetelheid / portabiliteit.
	 *
	 * Returns one row per (entity, object) pair so callers can render
	 * which PII attributes triggered the match alongside the owning
	 * object — useful for the inzage envelope.
	 *
	 * @param DsarSubjectQuery $query The subject value, optional GdprEntity type filter and
	 *                                `exact` (case-insensitive literal match) or `ilike`
	 *                                (case-insensitive substring) mode.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function matchEntities(DsarSubjectQuery $query): array {
		try {
			$qb = $this->entityRelationQuery();
			$this->filterBySubject(qb: $qb, query: $query);

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
			return $rows;
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[DSAR] GdprEntity lookup failed',
				context: ['subject' => $query->subject, 'type' => $query->type, 'error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end matchEntities()


	/**
	 * The join of PII entities to the objects they were found in.
	 *
	 * @return IQueryBuilder The query, one row per (entity, object) pair.
	 */
	private function entityRelationQuery(): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(self::MATCHED_ENTITY_COLUMNS)
			->from('openregister_entities', 'e')
			->innerJoin(
				'e',
				'openregister_entity_relations',
				'r',
				$qb->expr()->eq('r.entity_id', 'e.id')
			)
			->where(
				$qb->expr()->isNotNull('r.object_id')
			);

		return $qb;
	}//end entityRelationQuery()

	/**
	 * Restrict the query to the subject value and, when given, the entity type.
	 *
	 * @param IQueryBuilder    $qb    The entity relation query.
	 * @param DsarSubjectQuery $query The subject value, type filter and match mode.
	 *
	 * @return void
	 */
	private function filterBySubject(IQueryBuilder $qb, DsarSubjectQuery $query): void {
		// SECURITY: escape LIKE wildcards in user-supplied subject before
		// wrapping with `%`-anchors. Without this, an admin (the
		// `vergetelheid` endpoint is admin-gated) could pass
		// `subject=%@%` or `subject=_` to match every PII row in
		// `openregister_entities.value` — combined with the downstream
		// `eraseObjectsForSubject()` chain, that's a one-call wildcard
		// erase well beyond legitimate DSAR semantics.
		$pattern = $this->db->escapeLikeParameter($query->subject);
		if ($query->mode === 'ilike') {
			$pattern = '%' . $pattern . '%';
		}

		$qb->andWhere(
			$qb->expr()->iLike('e.value', $qb->createNamedParameter($pattern))
		);

		if ($query->type !== null && $query->type !== '') {
			$qb->andWhere(
				$qb->expr()->eq('e.type', $qb->createNamedParameter($query->type))
			);
		}
	}//end filterBySubject()
}//end class
