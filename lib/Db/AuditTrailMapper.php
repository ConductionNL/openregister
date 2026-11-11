<?php

/**
 * OpenRegister Audit Trail Mapper
 *
 * This file contains the class for handling audit trail related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\AuditTrail\AuditActionStatistics;
use OCA\OpenRegister\Db\AuditTrail\ActionWindow;
use OCA\OpenRegister\Db\AuditTrail\ActorTrailQuery;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;
use OCA\OpenRegister\Db\AuditTrail\ExportOutcome;
use OCA\OpenRegister\Db\AuditTrail\HardeningChange;
use OCA\OpenRegister\Db\AuditTrail\NamedActor;
use OCA\OpenRegister\Db\AuditTrail\RegisterAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions;
use OCA\OpenRegister\Db\AuditTrail\SharedMasterDataRefusal;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailStatistics;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Audit\AuditAggregationServiceInterface;
use OCA\OpenRegister\Db\AuditTrail\PartyQueryRefusal;
use OCA\OpenRegister\Db\AuditTrail\PurposeRefusal;
use OCA\OpenRegister\Db\AuditTrail\SlugBackfill;
use OCA\OpenRegister\Db\AuditTrail\ToolInvocation;
use OCA\OpenRegister\Service\Audit\AuditSinkShipperInterface;
use OCA\OpenRegister\Service\Audit\AuditTrailCollaborators;
use OCA\OpenRegister\Service\Audit\PurposeGuardInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrail\ActionScope;
use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrail\RevertOptions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use Throwable;

/**
 * The AuditTrailMapper class handles audit trail operations and object reversions
 *
 * @package OCA\OpenRegister\Db
 *
 * @method AuditTrail insert(Entity $entity)
 * @method AuditTrail update(Entity $entity)
 * @method AuditTrail insertOrUpdate(Entity $entity)
 * @method AuditTrail delete(Entity $entity)
 * @method AuditTrail findEntity(IQueryBuilder $query)
 * @method list<AuditTrail> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<AuditTrail>
 *
 * @psalm-suppress PossiblyUnusedMethod
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.TooManyMethods) The class already carried TooManyPublicMethods
 *   for the same reason: this is the one place an audit row is written, and every entry
 *   point that writes one has to go through it or the hash chain has a second author.
 * @SuppressWarnings(PHPMD.ExcessivePublicCount) Same reason: the slug backfill's run entry
 *   (recordSlugBackfill) is one more entry point that must seal into the hash chain here.
 */
class AuditTrailMapper extends QBMapper implements AuditTrailMapperInterface {

	/**
	 * The most changes one slug backfill entry lists; the rest is in the log.
	 */
	public const SLUG_BACKFILL_MAX_CHANGES = 1000;

	/**
	 * The only slug backfill mode: fill slugs that are missing (a set slug is never rewritten).
	 */
	public const SLUG_BACKFILL_MODE = 'fill';

	/**
	 * Request-scoped import-job tag.
	 *
	 * Set by `ImportService` at the start of a bulk import via
	 * `setRequestImportJobId()` and cleared in the import's `finally`
	 * block. Read by `createAuditTrail()` as a fallback when the
	 * `ObjectEntity` does not carry its own per-entity override. The
	 * mapper is request-bound under the standard NC DI container, so
	 * the value is request-local; a leaked value across requests would
	 * still be a benign mistag because the next import either sets a
	 * new UUID (overrides) or no UUID (single-object writes never
	 * resolve through this fallback because they go through
	 * `saveObject` not `saveObjects`).
	 *
	 * @var string|null
	 */
	private ?string $requestImportJobId = null;

	/**
	 * DI container for lazy collaborator resolution.
	 *
	 * @var \Psr\Container\ContainerInterface
	 */
	private readonly \Psr\Container\ContainerInterface $container;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * User session service.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Current request.
	 *
	 * @var IRequest
	 */
	private readonly IRequest $request;

	/**
	 * Dependency-free payload conversion/reversion helpers.
	 *
	 * @var AuditTrailPayloadHelper
	 */
	private readonly AuditTrailPayloadHelper $payloadHelper;

	/**
	 * Constructor for the AuditTrailMapper
	 *
	 * @param IDBConnection $db The database connection
	 * @param AuditTrailMapperServices $services DI container for lazy collaborator resolution, the logger, the user session and the request
	 */
	public function __construct(
		IDBConnection $db,
		AuditTrailMapperServices $services,
	) {
		parent::__construct(db: $db, tableName: 'openregister_audit_trails', entityClass: AuditTrail::class);
		$this->container = $services->container;
		$this->logger = $services->logger;
		$this->userSession = $services->userSession;
		$this->request = $services->request;
		$this->payloadHelper = new AuditTrailPayloadHelper();
	}//end __construct()




	/**
	 * Insert an audit-trail entry sealed into the SHA-256 hash chain.
	 *
	 * Captures the current chain head as `previousHash`, inserts to obtain the
	 * row id, then computes `hash` over the id-populated entity — matching
	 * exactly how {@see AuditHashService::verifyChain()} re-derives it — and
	 * persists it. Fail-soft: the audit row is always inserted; a hashing/DB
	 * hiccup logs and leaves that row unhashed rather than losing the audit
	 * record. Without this the chain was never populated, so `verifyChain()`
	 * reported `valid:true` with `entriesVerified:0` — tamper-evidence in name
	 * only (fix-audit-hash-chain-write-path / ADR-003).
	 *
	 * @param AuditTrail $auditTrail The entry to persist.
	 *
	 * @return AuditTrail The persisted (and, on success, hash-chained) entry.
	 */
	private function insertHashChained(AuditTrail $auditTrail): AuditTrail {
		// Sealing is DELIBERATELY not done here. It is left to AuditSealJob.
		//
		// Inline sealing was not merely slow (~15 ms of lock acquisition and
		// hashing on every audited write); it was the direct cause of chain
		// corruption. Sealing takes an exclusive lock, so under concurrency some
		// rows sealed and some fell through the fail-soft path unsealed. A row
		// sealed AFTER a gap chained onto the newest SEALED row, skipping the
		// gap — so when the sweep later filled that gap, the gap and the row
		// after it shared one predecessor. That is a fan-out, indistinguishable
		// from tampering to verifyChain(). Observed live on this very path: rows
		// 455956 and 455957 both chained onto 455955.
		//
		// With no inline sealing there is exactly one sealer, and unsealed rows
		// are therefore always a contiguous TAIL rather than holes punched
		// through the middle. The sweep walks that tail in id order, chaining
		// each row onto the row genuinely before it, so the fan-out is not
		// handled — it is made unreachable.
		//
		// Cost of the delay: a row is unvouched-for until the next sweep (five
		// minutes). verifyChain() skips unsealed rows and carries the last
		// sealed hash forward, so a tail of them is a smaller claim, never a
		// false alarm.
		//
		// Attribution is applied here as well as in buildEntry(), which
		// covers the entry points that do NOT build their row there —
		// createAuditTrailEntry() (archival/retention) and
		// recordToolInvocation() (MCP). Both can be reached from inside a
		// flow. Re-applying to a row the builder already stamped is idempotent:
		// it reads the same ambient frame and writes the same three values.
		//
		// It must happen before the row is INSERTED, not merely before it is
		// sealed: the sweep seals whatever is in the row, so a field added
		// after insert would be outside the hash it is later given.
		$this->applyAttributions(auditTrail: $auditTrail);

		$inserted = $this->insert(entity: self::withChangeList(auditTrail: $auditTrail));

		$this->shipToSink(entries: [$inserted]);

		return $inserted;
	}//end insertHashChained()

	/**
	 * Stamp the elevation of the action run in progress onto a row it covers.
	 *
	 * Before the INSERT, for the reason the attributions above give: `changed`
	 * is inside the canonical JSON the sweep seals. Idempotent, so the builder
	 * and the insert may both apply it.
	 *
	 * @param AuditTrail $auditTrail The row.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionElevationScope is the request-scoped frame the run opened.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	private function applyElevation(AuditTrail $auditTrail): void {
		$elevation = AuditTrailCollaborators::elevationAttribution(
			schemaId: $auditTrail->getSchema(),
			verb: (string)$auditTrail->getAction()
		);
		if ($elevation === null) {
			return;
		}

		$auditTrail->setChanged(array_merge($auditTrail->getChanged(), ['elevation' => $elevation]));
	}//end applyElevation()

	/**
	 * Stamp what caused a row onto it, before it is inserted.
	 *
	 * - Flow attribution: which run, node and step caused this write.
	 * - Purpose (doelbinding): the purpose the caller declared for the read
	 *   that produced this row.
	 * - Token: which token, whose, and for which consumer.
	 * - Organisation context: the organisation code acted for.
	 * - Elevation: the declared action that elevated the write, who ran it,
	 *   and that it ran as the system (ADR-099).
	 *
	 * The purpose, token and organisation context halves live in
	 * `resultSummary`, which is inside the canonical JSON, so this MUST run
	 * before the row is inserted. Re-applying to a row already stamped is
	 * idempotent: it reads the same ambient frames and writes the same values.
	 *
	 * @param AuditTrail $auditTrail The row being built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
	 */
	private function applyAttributions(AuditTrail $auditTrail): void {
		(new AuditFlowAttribution($this->db, $this->container))->apply(auditTrail: $auditTrail);
		AuditTrailCollaborators::purposeAttribution(container: $this->container)->apply(auditTrail: $auditTrail);
		AuditTrailCollaborators::tokenAttribution(container: $this->container)->apply(auditTrail: $auditTrail);
		AuditTrailCollaborators::organisationContextAttribution()->apply(auditTrail: $auditTrail);
		$this->applyElevation(auditTrail: $auditTrail);
	}//end applyAttributions()

	/**
	 * Set the request-scoped import-job UUID stamped on all
	 * `createAuditTrail()` rows for the duration of an import. Pass
	 * `null` to clear (typically in the import's `finally` block).
	 *
	 * @param string|null $importJobId UUID v4 of the active import job, or null to clear.
	 *
	 * @return void
	 */
	public function setRequestImportJobId(?string $importJobId): void {
		$this->requestImportJobId = $importJobId;
	}//end setRequestImportJobId()

	/**
	 * Get the currently-active request-scoped import-job UUID. Returns
	 * null when no import is in flight.
	 *
	 * @return string|null
	 */
	public function getRequestImportJobId(): ?string {
		return $this->requestImportJobId;
	}//end getRequestImportJobId()

	/**
	 * Find audit-trail entries tagged with the given import-job UUID.
	 *
	 * Used by `ImportService::softDeleteByImportJobId()` to identify
	 * the objects created during a specific import for rollback.
	 *
	 * @param string $importJobId UUID of the import job to look up.
	 * @param string|null $action Optional action filter (e.g. `'create'`); null returns all actions.
	 *
	 * @return AuditTrail[] Matching audit trail rows.
	 */
	public function findByImportJobId(string $importJobId, ?string $action = 'create'): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_audit_trails')
			->where(
				$qb->expr()->eq(
					'import_job_id',
					$qb->createNamedParameter($importJobId, IQueryBuilder::PARAM_STR)
				)
			);

		if ($action !== null) {
			$qb->andWhere(
				$qb->expr()->eq('action', $qb->createNamedParameter($action, IQueryBuilder::PARAM_STR))
			);
		}

		$qb->orderBy('created', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByImportJobId()

	/**
	 * Count the audit rows tagged with an import-job UUID.
	 *
	 * The cheap question behind "did this import create anything that can be
	 * removed by job": it reads the same rows softDeleteByImportJobId() reads,
	 * without loading their payloads.
	 *
	 * @param string      $importJobId UUID of the import job.
	 * @param string|null $action      Action filter (e.g. `'create'`); null counts every action.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-job-id-of-an-app-import-that-created-objects-must-be-recorded-per-app
	 */
	public function countByImportJobId(string $importJobId, ?string $action = 'create'): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'row_count'))
			->from('openregister_audit_trails')
			->where($qb->expr()->eq('import_job_id', $qb->createNamedParameter($importJobId, IQueryBuilder::PARAM_STR)));

		if ($action !== null) {
			$qb->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action, IQueryBuilder::PARAM_STR)));
		}

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countByImportJobId()

	/**
	 * The UUIDs of the objects an import job created, oldest first.
	 *
	 * @param string $importJobId UUID of the import job.
	 *
	 * @return array<int, string> Distinct object UUIDs from the job's `create` rows.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-the-cli-purge-must-accept-an-import-job-instead-of-a-list-of-uuids
	 */
	public function objectUuidsByImportJobId(string $importJobId): array {
		$uuids = [];
		foreach ($this->findByImportJobId(importJobId: $importJobId, action: 'create') as $row) {
			$uuid = $row->getObjectUuid();
			if ($uuid !== null && $uuid !== '') {
				$uuids[$uuid] = true;
			}
		}

		return array_keys($uuids);
	}//end objectUuidsByImportJobId()

	/**
	 * The change history of one object, oldest first, for deriving a projection.
	 *
	 * Purged rows are excluded, not skipped afterwards: a tombstoned row's
	 * `changed` is an empty object, so including it would read as "every field
	 * became nothing at that moment" and write an interval that never happened.
	 *
	 * @param string $objectUuid The object.
	 * @param int    $limit      Most rows to read.
	 *
	 * @return array<int, array{created: string, changed: array}> The changes.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function findChangesForObject(string $objectUuid, int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('created', 'changed')
			->from('openregister_audit_trails')
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->isNull('purged_at'))
			->orderBy('created', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$changes = [];
		while (($row = $result->fetch()) !== false) {
			$changed = json_decode((string)($row['changed'] ?? '{}'), true);
			if (is_array($changed) === false) {
				$changed = [];
			}

			$changes[] = [
				'created' => (string)($row['created'] ?? ''),
				'changed' => $changed,
			];
		}

		$result->closeCursor();

		return $changes;
	}//end findChangesForObject()

	/**
	 * Object uuids carrying audit rows, in uuid order, after a cursor.
	 *
	 * The cursor is what makes a rebuild resumable: a run takes the next batch
	 * and stops, and the next run starts where it left off rather than at the
	 * beginning of a table with millions of rows in it.
	 *
	 * @param string $afterUuid The cursor; '' starts at the beginning.
	 * @param int    $limit     Most uuids to return.
	 *
	 * @return string[] The uuids.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function findObjectUuidsAfter(string $afterUuid, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('object_uuid')
			->from('openregister_audit_trails')
			->where($qb->expr()->isNotNull('object_uuid'))
			->andWhere($qb->expr()->neq('object_uuid', $qb->createNamedParameter('', IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->isNull('purged_at'))
			->orderBy('object_uuid', 'ASC')
			->setMaxResults($limit);

		if ($afterUuid !== '') {
			$qb->andWhere($qb->expr()->gt('object_uuid', $qb->createNamedParameter($afterUuid, IQueryBuilder::PARAM_STR)));
		}

		$result = $qb->executeQuery();
		$uuids = [];
		while (($row = $result->fetch()) !== false) {
			$uuids[] = (string)$row['object_uuid'];
		}

		$result->closeCursor();

		return $uuids;
	}//end findObjectUuidsAfter()

	/**
	 * The newest purged moment per object, for pruning what derives from it.
	 *
	 * A projection is derived data. When the payload it was derived from is
	 * destroyed, the derivation has to go too, or a filter answers about a
	 * record nothing else can show.
	 *
	 * @param int $limit Most objects to report on.
	 *
	 * @return array<string, string> object uuid => newest purged row's `created`.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function findPurgedHorizons(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid')
			->selectAlias($qb->func()->max('created'), 'horizon')
			->from('openregister_audit_trails')
			->where($qb->expr()->isNotNull('purged_at'))
			->andWhere($qb->expr()->isNotNull('object_uuid'))
			->groupBy('object_uuid')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$horizons = [];
		while (($row = $result->fetch()) !== false) {
			$uuid = (string)($row['object_uuid'] ?? '');
			if ($uuid !== '') {
				$horizons[$uuid] = (string)($row['horizon'] ?? '');
			}
		}

		$result->closeCursor();

		return $horizons;
	}//end findPurgedHorizons()

	/**
	 * Finds an audit trail by id
	 *
	 * @param int $id The id of the audit trail
	 *
	 * @return AuditTrail The audit trail
	 */
	public function find(int $id): AuditTrail {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_audit_trails')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * The columns findTrail() filters and sorts on. A key outside this list is dropped.
	 *
	 * @var string[]
	 */
	public const QUERY_COLUMNS = [
		'id',
		'uuid',
		'schema',
		'register',
		'object',
		'object_uuid',
		'action',
		'changed',
		'user',
		'user_name',
		'session',
		'request',
		'ip_address',
		'version',
		'created',
		// Flow attribution. Absent from this allowlist a filter is
		// not rejected — it is silently DROPPED by the `continue`
		// in findTrail(), so `?flow_run=<uuid>` would return the whole
		// unfiltered audit trail with a 200 and read as a run that
		// had touched everything on the instance.
		'flow_run',
		'flow_node',
		'flow_step',
		// The cause and its run. Absent from this allowlist a
		// filter is not rejected, it is silently DROPPED by the
		// `continue` in findTrail() — so `?cause=import` would answer the
		// WHOLE unfiltered trail with a 200 and read as a load
		// that had touched everything on the instance.
		'cause',
		'cause_run',
	];

	/**
	 * Audit trails matching a listing's filters, search and sort, from its offset.
	 *
	 * An unbounded listing ({@see ListQuery::unbounded()}) returns every match.
	 *
	 * @param ListQuery $query The limit, offset, filters, sort and search.
	 *
	 * @return AuditTrail[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\AuditTrail>
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function findTrail(ListQuery $query): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_audit_trails');

		$this->applyFindFilters(qb: $qb, filters: $query->filters);
		$this->applyChangedSearch(qb: $qb, search: $query->search);
		$this->applyFindSort(qb: $qb, sort: $query->sort);
		(new QueryPage(limit: $query->limitOrNull(), offset: $query->offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findTrail()

	/**
	 * Add the search on the changed field, when a search term is provided.
	 *
	 * @param IQueryBuilder $qb     The query being built.
	 * @param string|null   $search The search term, if any.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function applyChangedSearch(IQueryBuilder $qb, string|null $search): void {
		if ($search !== null && $search !== '') {
			$qb->andWhere(
				$qb->expr()->like('changed', $qb->createNamedParameter('%' . $search . '%'))
			);
		}
	}//end applyChangedSearch()

	/**
	 * Apply the filters of findTrail(): system variables (starting with _) and
	 * columns outside QUERY_COLUMNS are ignored.
	 *
	 * @param IQueryBuilder $qb      The query being built.
	 * @param array         $filters The filters, keyed by column.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function applyFindFilters(IQueryBuilder $qb, array $filters): void {
		$filters = array_filter(
			$filters,
			function ($key) {
				return !str_starts_with($key, '_');
			},
			ARRAY_FILTER_USE_KEY
		);

		foreach ($filters as $field => $value) {
			if (in_array(
				$field,
				self::QUERY_COLUMNS
			) === false
			) {
				continue;
			}

			if (is_array($value) === true) {
				$this->applyListFilter(qb: $qb, term: new AuditTrailFilterTerm(field: $field, value: $value));
				continue;
			}

			$qb->andWhere($this->scalarFilterExpression(qb: $qb, term: new AuditTrailFilterTerm(field: $field, value: $value)));
		}
	}//end applyFindFilters()

	/**
	 * The predicate for one scalar filter: a NULL check, an `action` prefix
	 * (`action=portaliq.*` lists every action an app writes under its own
	 * name), a comma-separated list (`action=create,update`), or equality.
	 *
	 * @param IQueryBuilder        $qb   The query being built.
	 * @param AuditTrailFilterTerm $term The column and its value.
	 *
	 * @return string The predicate.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function scalarFilterExpression(IQueryBuilder $qb, AuditTrailFilterTerm $term): string {
		$field = $term->field;
		if ($term->value === 'IS NOT NULL') {
			return $qb->expr()->isNotNull($field);
		}

		if ($term->value === 'IS NULL') {
			return $qb->expr()->isNull($field);
		}

		// Cast to string to handle integer filter values.
		$valueStr = (string)$term->value;
		if ($field === 'action' && str_ends_with($valueStr, '.*') === true) {
			$prefix = substr($valueStr, 0, -1);
			return $qb->expr()->like('action', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix).'%'));
		}

		return self::listOrEqualExpression(qb: $qb, term: $term);
	}//end scalarFilterExpression()

	/**
	 * The predicate for a comma-separated list (`action=create,update`), or equality.
	 *
	 * @param IQueryBuilder        $qb   The query being built.
	 * @param AuditTrailFilterTerm $term The column and its value.
	 *
	 * @return string The predicate.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private static function listOrEqualExpression(IQueryBuilder $qb, AuditTrailFilterTerm $term): string {
		$valueStr = (string)$term->value;
		if (strpos($valueStr, ',') !== false) {
			$values = array_map('trim', explode(',', $valueStr));
			return $qb->expr()->in($term->field, $qb->createNamedParameter($values, IQueryBuilder::PARAM_STR_ARRAY));
		}

		return $qb->expr()->eq($term->field, $qb->createNamedParameter($term->value));
	}//end listOrEqualExpression()

	/**
	 * Apply the sort of findTrail(): columns outside QUERY_COLUMNS are ignored,
	 * and a direction other than DESC (any case) sorts ascending.
	 *
	 * @param IQueryBuilder $qb   The query being built.
	 * @param array         $sort Direction keyed by column.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function applyFindSort(IQueryBuilder $qb, array $sort): void {
		foreach ($sort as $field => $direction) {
			if (in_array(
				$field,
				self::QUERY_COLUMNS
			) === false
			) {
				continue;
			}

			// The default is assigned to a SEPARATE name. Writing it back over
			// `$direction` first, then testing `$direction`, compares the
			// default with itself, so the branch can never be taken and every
			// sort this mapper is given comes back ASCENDING. The listing sort
			// `['created' => 'DESC']` returned oldest-first
			// regardless, which is how a case history read bottom-up on the
			// page while both the caller and this signature said newest-first.
			$order = 'ASC';
			if (strtoupper((string)$direction) === 'DESC') {
				$order = 'DESC';
			}

			$qb->addOrderBy($field, $order);
		}
	}//end applyFindSort()

	/**
	 * Apply a filter whose value is a list: `field[notIn]=a,b` leaves out the
	 * rows whose column is one of the values (case-insensitive), and a plain
	 * list (`field[]=a&field[]=b`) keeps only the rows whose column is one of
	 * them. The exclusion runs in the query, so a page holds what is left.
	 *
	 * @param IQueryBuilder        $qb   The query being built.
	 * @param AuditTrailFilterTerm $term The allowlisted column, and its operator map or plain list.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the list names an operator other than `notIn`.
	 *
	 * @spec openspec/changes/audit-trail-not-in-filter/specs/audit-trail-immutable/spec.md#requirement-a-history-read-can-leave-out-actions-and-actors-in-the-query
	 */
	private function applyListFilter(IQueryBuilder $qb, AuditTrailFilterTerm $term): void {
		$field = $term->field;
		$value = $term->value;
		if (array_is_list($value) === true) {
			$qb->andWhere($qb->expr()->in($field, $qb->createNamedParameter($this->listValues(value: $value), IQueryBuilder::PARAM_STR_ARRAY)));
			return;
		}

		$unknown = array_diff(array_keys($value), ['notIn']);
		if ($unknown !== []) {
			throw new InvalidArgumentException('Unsupported audit trail filter operator for ' . $field . ': ' . implode(', ', $unknown));
		}

		$excluded = array_map('strtolower', $this->listValues(value: $value['notIn']));
		if ($excluded === []) {
			return;
		}

		$qb->andWhere(
			$qb->expr()->notIn($qb->func()->lower($field), $qb->createNamedParameter($excluded, IQueryBuilder::PARAM_STR_ARRAY))
		);
	}//end applyListFilter()

	/**
	 * The values a list filter names: an array of values, or one comma-separated string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return list<string> The trimmed, non-empty values.
	 */
	private function listValues(mixed $value): array {
		$values = $value;
		if (is_array($value) === false) {
			$values = explode(',', (string)$value);
		}

		$values = array_map(static fn ($one): string => trim((string)$one), $values);

		return array_values(array_filter($values, static fn (string $one): bool => $one !== ''));
	}//end listValues()

	/**
	 * Creates an audit trail for object changes
	 *
	 * @param ObjectEntity|null $old The old state of the object
	 * @param ObjectEntity|null $new The new state of the object
	 * @param string|null $action The action to record. NULL (the default) infers it from which
	 *                            entities are present: no `new` is a delete, no `old` is a create,
	 *                            otherwise an update. A NAMED action is used verbatim — the
	 *                            inference used to overwrite an explicit 'update' because it was
	 *                            indistinguishable from the old default (#2217).
	 * @param array|null $cascadeContext Optional referential-integrity cascade context. When
	 *                                   non-null, the context is folded into the `changed`
	 *                                   column of the initial INSERT (previously this required
	 *                                   a second UPDATE per row, which also invalidated the
	 *                                   hash-chain seal computed at insert time).
	 *
	 * @return AuditTrail The created audit trail
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          Uuid::v4 is standard Symfony UID pattern
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Audit trail creation requires handling many optional fields
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	public function createAuditTrail(
		?ObjectEntity $old = null,
		?ObjectEntity $new = null,
		?string $action = null,
		?array $cascadeContext = null,
	): AuditTrail {
		return $this->recordChange(change: new AuditedChange(old: $old, new: $new, action: $action, cascadeContext: $cascadeContext));
	}//end createAuditTrail()

	/**
	 * Record one change to an object, merged into an aggregation window when one is set.
	 *
	 * The typed form of {@see createAuditTrail()}, which keeps its signature for other apps.
	 *
	 * @param AuditedChange $change The old and new state, the action and the cascade context.
	 *
	 * @return AuditTrail The created (or merged) audit trail
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function recordChange(AuditedChange $change): AuditTrail {
		$auditTrail = $this->buildEntry(change: $change);

		// Nothing merges unless an administrator set a window, so this is a
		// no-op on every instance that did not ask for it. When nothing merged,
		// insert the new AuditTrail, sealed into the hash chain, and return it.
		return $this->mergeIntoAggregationWindow(candidate: $auditTrail)
			?? $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordChange()

	/**
	 * Fold an edit into the entry already written, when a window says to.
	 *
	 * Returns the amended entry, or null when the edit is its own entry. Null
	 * is the answer in every case that is not unambiguously a merge: no window,
	 * not an update, no previous entry, a different actor, a different object,
	 * too long ago, or an entry that is already sealed.
	 *
	 * ⚠️ THE SEALED CHECK IS NOT A BELT-AND-BRACES GUARD, IT IS THE RULE.
	 * Amending an unsealed row is ordinary writing, because `insertHashChained`
	 * deliberately leaves sealing to `AuditSealJob` and an unsealed row is not
	 * yet in the chain. Amending a SEALED row would change a row the chain
	 * covers, which `verifyChain()` reports as tampering and which it would be.
	 * So a sealed row ends the window early and the edit gets its own entry,
	 * which is the conservative direction: more entries, never fewer.
	 *
	 * Fail-soft throughout. Aggregation is a convenience; recording the edit is
	 * not. Anything that goes wrong here answers null and the ordinary insert
	 * happens, so the worst case is an entry that was not merged.
	 *
	 * @param AuditTrail $candidate The entry that would otherwise be inserted.
	 *
	 * @return AuditTrail|null The amended entry, or null to insert normally.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function mergeIntoAggregationWindow(AuditTrail $candidate): AuditTrail|null {
		if ($candidate->getAction() !== 'update' || $candidate->getObjectUuid() === null) {
			return null;
		}

		try {
			return $this->foldIntoWindow(candidate: $candidate);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AuditTrailMapper] Could not fold an edit into the aggregation window',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'objectUuid' => $candidate->getObjectUuid(),
					'error' => $e->getMessage(),
				]
			);

			return null;
		}//end try
	}//end mergeIntoAggregationWindow()

	/**
	 * Fold an update into the unsealed entry before it, when the window allows.
	 *
	 * The steps of {@see mergeIntoAggregationWindow()} inside its fail-soft
	 * try: read the window, find the entry to amend, check the span, fold.
	 * Every step that does not end in an unambiguous merge answers null.
	 *
	 * @param AuditTrail $candidate The entry that would otherwise be inserted.
	 *
	 * @return AuditTrail|null The amended entry, or null to insert normally.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function foldIntoWindow(AuditTrail $candidate): AuditTrail|null {
		$aggregation = $this->windowAggregation();
		if ($aggregation === null) {
			return null;
		}

		$window = $aggregation->windowSeconds();
		if ($window === 0) {
			return null;
		}

		$previous = $this->findMergeCandidate(candidate: $candidate);
		if ($previous === null) {
			return null;
		}

		$within = $aggregation->isWithinWindow(
			previous: $previous->getCreated(),
			now: ($candidate->getCreated() ?? new DateTime())
		);
		if ($within === false) {
			return null;
		}

		$previous->setChanged(
			$aggregation->fold(previous: $previous->getChanged(), incoming: $candidate->getChanged())
		);

		return $this->update(entity: $previous);
	}//end foldIntoWindow()

	/**
	 * The aggregation service over the app config, or null when no app config resolves.
	 *
	 * Resolved here rather than injected: the constructor is mocked by a long
	 * tail of tests that build this mapper positionally, and the window is read
	 * once per audited write on instances that set one.
	 *
	 * @return AuditAggregationServiceInterface|null The service, or null.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function windowAggregation(): AuditAggregationServiceInterface|null {
		$appConfig = $this->container->get(IAppConfig::class);
		if (($appConfig instanceof IAppConfig) === false) {
			return null;
		}

		return AuditTrailCollaborators::aggregation(appConfig: $appConfig);
	}//end windowAggregation()

	/**
	 * The entry an edit would fold into, if one exists.
	 *
	 * Same object, same actor, still an update, and not yet sealed. Ordered by
	 * id rather than by `created` because the chain is walked in id order and
	 * two rows can share a second.
	 *
	 * @param AuditTrail $candidate The entry that would otherwise be inserted.
	 *
	 * @return AuditTrail|null The entry to amend, or null.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function findMergeCandidate(AuditTrail $candidate): ?AuditTrail {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($candidate->getObjectUuid())))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter('update')))
			->andWhere($qb->expr()->isNull('hash'))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		// Same actor, and an entry with no actor only ever merges with another
		// one that has none. Folding a system write into a person's entry would
		// put their name on a change they did not make.
		$actor = $candidate->getUser();
		$sameActor = $qb->expr()->isNull('user');
		if ($actor !== null) {
			$sameActor = $qb->expr()->eq('user', $qb->createNamedParameter($actor));
		}

		$qb->andWhere($sameActor);

		$found = $this->findEntities(query: $qb);
		if ($found === []) {
			return null;
		}

		return $found[0];
	}//end findMergeCandidate()

	/**
	 * Build (but do not persist) the audit row of one change.
	 *
	 * @param AuditedChange $change The old and new state, the action and the cascade context.
	 *
	 * @return AuditTrail The populated (unpersisted) audit trail entity
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function buildEntry(AuditedChange $change): AuditTrail {
		$pair = ['old' => $change->old, 'new' => $change->new];
		[$action, $objectEntity] = $this->resolveAuditAction(pair: $pair, action: $change->action);

		$changed = $this->diffForAudit(pair: $pair, action: $action);
		$changed = $this->foldCascadeContext(changed: $changed, cascadeContext: $change->cascadeContext);
		// The markers are added before the row is built and sealed: the hash
		// chain covers whatever is in the row.
		$changed = $this->markTransitionOrigin(changed: $changed, objectEntity: $objectEntity);
		$changed = $this->markClientSuppliedCause(changed: $changed, objectEntity: $objectEntity);

		$auditTrail = new AuditTrail();
		$auditTrail->setUuid((string)Uuid::v4());
		$auditTrail->setObject($objectEntity->getId());
		$auditTrail->setObjectUuid($objectEntity->getUuid());
		$auditTrail->setAction($action);
		$auditTrail->setChanged($this->payloadHelper->capChangedPayload(changed: $changed));

		$this->stampActorAndObject(auditTrail: $auditTrail, objectEntity: $objectEntity);
		$this->stampAttributions(auditTrail: $auditTrail, objectEntity: $objectEntity);

		// Set the size to the byte size of the serialized object, with a minimum default of 14 bytes.
		$serializedSize = strlen(serialize($objectEntity->jsonSerialize()));
		$auditTrail->setSize(max($serializedSize, 14));

		// Expiry follows the RETENTION OF THE OBJECT the row describes, not a
		// flat window (or#2265). `null` means retain indefinitely, which is the
		// resolver's outcome for an object with no retention policy, under legal
		// hold, or nominated `bewaren` / `nog_niet_bepaald`. Set BEFORE the row
		// is sealed: `expires` is part of the canonical JSON the hash covers.
		// See AuditRetentionResolver.
		$this->applyRetentionExpiry(auditTrail: $auditTrail, objectEntity: $objectEntity);

		return $auditTrail;
	}//end buildEntry()

	/**
	 * The action to record and the entity the row describes.
	 *
	 * A NULL action is inferred from which entities are present: no `new` is a
	 * delete, no `old` is a create, otherwise an update. A named action is used
	 * verbatim, so an explicit 'update' with no old state stays an update (#2217).
	 * Deletes and referential-integrity actions describe the old entity.
	 *
	 * @param array{old: ObjectEntity|null, new: ObjectEntity|null} $pair   The old and new states.
	 * @param string|null                                           $action The named action, if any.
	 *
	 * @return array{0: string, 1: ObjectEntity|null} The action and the entity it describes.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function resolveAuditAction(array $pair, string|null $action): array {
		$objectEntity = $pair['new'];
		$action ??= self::inferredAction(pair: $pair);

		if ($action === 'delete' || str_starts_with($action, 'referential_integrity.') === true) {
			$objectEntity = $pair['old'];
		}

		return [$action, $objectEntity];
	}//end resolveAuditAction()

	/**
	 * The action an unnamed write records: no `new` is a delete, no `old` a create, otherwise an update.
	 *
	 * @param array{old: ObjectEntity|null, new: ObjectEntity|null} $pair The old and new states.
	 *
	 * @return string The inferred action.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function inferredAction(array $pair): string {
		if ($pair['new'] === null) {
			return 'delete';
		}

		if ($pair['old'] === null) {
			return 'create';
		}

		return 'update';
	}//end inferredAction()

	/**
	 * The per-field changes between the old and new state.
	 *
	 * Delete, read and referential-integrity actions record no diff: their new
	 * state is legitimately absent. Only an update records removed fields.
	 *
	 * @param array{old: ObjectEntity|null, new: ObjectEntity|null} $pair   The old and new states.
	 * @param string                                                $action The resolved action.
	 *
	 * @return array<string, array{old: mixed, new: mixed}> The changed fields.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function diffForAudit(array $pair, string $action): array {
		if (self::recordsNoDiff(action: $action) === true) {
			return [];
		}

		$oldArray = ($pair['old']?->jsonSerialize() ?? []);
		$newArray = ($pair['new']?->jsonSerialize() ?? []);

		$changed = self::setFields(oldArray: $oldArray, newArray: $newArray);

		if ($action === 'update') {
			$changed = array_merge($changed, $this->removedFields(oldArray: $oldArray, newArray: $newArray));
		}

		return $changed;
	}//end diffForAudit()

	/**
	 * Whether an action records no diff: delete, read and referential-integrity actions.
	 *
	 * @param string $action The resolved action.
	 *
	 * @return bool True when the action records an empty change set.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function recordsNoDiff(string $action): bool {
		return $action === 'delete' || $action === 'read' || str_starts_with($action, 'referential_integrity.') === true;
	}//end recordsNoDiff()

	/**
	 * The fields the new state sets to a value the old state did not hold.
	 *
	 * @param array $oldArray The serialised old state.
	 * @param array $newArray The serialised new state.
	 *
	 * @return array<string, array{old: mixed, new: mixed}> The added or changed fields.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function setFields(array $oldArray, array $newArray): array {
		$changed = [];
		foreach ($newArray as $key => $value) {
			if ((isset($oldArray[$key]) === false) || ($oldArray[$key] !== $value)) {
				$changed[$key] = ['old' => ($oldArray[$key] ?? null), 'new' => $value];
			}
		}

		return $changed;
	}//end setFields()

	/**
	 * The fields the old state set and the new state no longer does, as changes to null.
	 *
	 * @param array $oldArray The serialised old state.
	 * @param array $newArray The serialised new state.
	 *
	 * @return array<string, array{old: mixed, new: null}> The removed fields.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function removedFields(array $oldArray, array $newArray): array {
		$removed = [];
		foreach ($oldArray as $key => $value) {
			if (isset($newArray[$key]) === false) {
				$removed[$key] = ['old' => $value, 'new' => null];
			}
		}

		return $removed;
	}//end removedFields()

	/**
	 * Fold a referential-integrity cascade context into the change set.
	 *
	 * It is part of the initial INSERT, in the key shape the post-insert UPDATE
	 * DeleteObject used to issue per cascade-tagged delete.
	 *
	 * @param array      $changed        The changed fields.
	 * @param array|null $cascadeContext The cascade context, if any.
	 *
	 * @return array The changed fields with the cascade context.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function foldCascadeContext(array $changed, ?array $cascadeContext): array {
		if ($cascadeContext === null) {
			return $changed;
		}

		$changed['triggeredBy'] = 'referential_integrity';
		$changed['cascadeContext'] = [
			'triggerObject' => $cascadeContext['triggerObject'] ?? null,
			'triggerSchema' => $cascadeContext['triggerSchema'] ?? null,
			'action_type' => $cascadeContext['action_type'] ?? 'referential_integrity.cascade_delete',
			'property' => $cascadeContext['property'] ?? null,
		];
		return $changed;
	}//end foldCascadeContext()

	/**
	 * Mark a row an automatic lifecycle transition, or a transition run as the system, produced.
	 *
	 * An auditor can then tell a move a user asked for from one a rule made on
	 * that user's save, and see which app approved skipping OpenRegister's own
	 * read and update checks. The row's user stays the acting user. Both are read
	 * through the container: this mapper is built in contexts where the
	 * lifecycle services are not wired, and a resolution failure means "nothing
	 * in flight".
	 *
	 * @param array        $changed      The changed fields.
	 * @param ObjectEntity $objectEntity The entity the row describes.
	 *
	 * @return array The changed fields with any markers.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function markTransitionOrigin(array $changed, ObjectEntity $objectEntity): array {
		$automaticAction = null;
		try {
			$automaticAction = $this->container
				->get(\OCA\OpenRegister\Service\Lifecycle\AutoTransitionPassInterface::class)
				->applyingAction();
		} catch (\Throwable $passUnavailable) {
			$automaticAction = null;
		}

		if ($automaticAction !== null) {
			$changed['automaticTransition'] = $automaticAction;
		}

		$systemApp = $this->transitionSystemApp(uuid: (string)$objectEntity->getUuid());
		if ($systemApp !== null) {
			$changed['transitionAsSystem'] = ['app' => $systemApp];
		}

		return $changed;
	}//end markTransitionOrigin()

	/**
	 * Stamp who made the change, from where, and on which object version.
	 *
	 * The version is the one this change produced, which makes "revert to
	 * version X" answerable from the trail rather than by counting rows.
	 *
	 * @param AuditTrail   $auditTrail   The row being built.
	 * @param ObjectEntity $objectEntity The entity the row describes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function stampActorAndObject(AuditTrail $auditTrail, ObjectEntity $objectEntity): void {
		$user = $this->userSession->getUser();
		$auditTrail->setUser('System');
		$auditTrail->setUserName('System');
		if ($user !== null) {
			$auditTrail->setUser($user->getUID());
			$auditTrail->setUserName($user->getDisplayName());
		}

		$auditTrail->setSession(session_id());
		$auditTrail->setRequest($this->request->getId());
		$auditTrail->setIpAddress($this->request->getRemoteAddress());
		$auditTrail->setCreated(new DateTime());
		$auditTrail->setRegister($objectEntity->getRegister());
		$auditTrail->setSchema($objectEntity->getSchema());
		$auditTrail->setVersion($objectEntity->getVersion());
	}//end stampActorAndObject()

	/**
	 * Mark the row when the request tried to supply its own audit cause.
	 *
	 * The supplied cause is ignored and the derived one is stamped; this marker
	 * records that the attempt was made, on the sealed row itself. Only rows of
	 * the object whose write carried the attempt are marked.
	 *
	 * @param array<string, mixed> $changed      The changed payload.
	 * @param ObjectEntity         $objectEntity The object the row describes.
	 *
	 * @return array<string, mixed> The payload, marked when a client attempted it.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause holds the ambient acting context
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	private function markClientSuppliedCause(array $changed, ObjectEntity $objectEntity): array {
		if (WriteCause::clientAttemptedFor(objectUuid: (string)$objectEntity->getUuid()) === true) {
			$changed['clientSuppliedCause'] = true;
		}

		return $changed;
	}//end markClientSuppliedCause()

	/**
	 * Stamp why the write happened and what it ran under.
	 *
	 * Applied in the shared builder, not in the two insert methods:
	 * `insertAuditTrails()` builds its rows here too, and stamping only the
	 * single inserts would leave every bulk write unattributed. Covers the AVG
	 * processing activity (action override > schema > register), the import job
	 * (entity override > request scope), the write cause from the ambient acting
	 * context (never from the request), and the flow, purpose and token
	 * attributions.
	 *
	 * @param AuditTrail   $auditTrail   The row being built.
	 * @param ObjectEntity $objectEntity The entity the row describes.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause holds the ambient acting context
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function stampAttributions(AuditTrail $auditTrail, ObjectEntity $objectEntity): void {
		$processingActivityId = $this->resolveProcessingActivityId(objectEntity: $objectEntity);
		if ($processingActivityId !== null) {
			$auditTrail->setProcessingActivityId($processingActivityId);
		}

		$importJobId = ($objectEntity->getImportJobId() ?? $this->requestImportJobId);
		if ($importJobId !== null) {
			$auditTrail->setImportJobId($importJobId);
		}

		$frame = \OCA\OpenRegister\Service\WriteCause::current();
		$auditTrail->setCause($frame['cause']);
		$auditTrail->setCauseRun($frame['run']);

		$this->applyAttributions(auditTrail: $auditTrail);
	}//end stampAttributions()

	/**
	 * The app a transition on this object currently runs as the system for, if any.
	 *
	 * Read off the request-scoped LifecycleActionContext through the container,
	 * like the automatic-transition pass above: this mapper is built in
	 * contexts where the lifecycle services are not wired, and an audit row
	 * must never fail to be built because of that. Unavailable means "no
	 * system transition in flight".
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return string|null The app id, or null for an ordinary write.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function transitionSystemApp(string $uuid): ?string {
		if ($uuid === '') {
			return null;
		}

		try {
			return AuditTrailCollaborators::lifecycleActionContextFrom(container: $this->container)
				->systemAppFor($uuid);
		} catch (\Throwable $contextUnavailable) {
			return null;
		}
	}//end transitionSystemApp()

	/**
	 * Stamp an audit row's expiry and retention source from the retention of
	 * the object it describes.
	 *
	 * The one place every audit writer takes its expiry from. Rows built here
	 * got it in or#2265; the referential-integrity rows and the file audit
	 * rows kept a flat `+30 days` until or#4101, so the record of why a
	 * reference was cleared, or which file of a record under legal hold was
	 * renamed, was purged a month later. A writer that builds its own row
	 * calls this before inserting it, so the expiry is part of the sealed
	 * canonical JSON.
	 *
	 * Without an object (it could not be found) the row is retained
	 * indefinitely: the failure being guarded against is evidence
	 * disappearing, not disk filling.
	 *
	 * @param AuditTrail $auditTrail The row to stamp.
	 * @param ObjectEntity|null $objectEntity The object the row describes, or null when it could not be found.
	 *
	 * @return AuditTrail The same row, stamped.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function applyRetentionExpiry(AuditTrail $auditTrail, ?ObjectEntity $objectEntity): AuditTrail {
		if ($objectEntity === null) {
			$auditTrail->setExpires(null);
			$auditTrail->setRetentionPeriod('object-unavailable:indefinite');
			return $auditTrail;
		}

		$resolvedRetention = $this->resolveAuditExpiry(
			objectEntity: $objectEntity,
			createdAt: ($auditTrail->getCreated() ?? new DateTime())
		);
		$auditTrail->setExpires($resolvedRetention['expires']);
		$auditTrail->setRetentionPeriod($resolvedRetention['source']);

		return $auditTrail;
	}//end applyRetentionExpiry()

	/**
	 * Resolve the audit row's expiry from the object's retention policy.
	 *
	 * Delegates to {@see AuditRetentionResolver}, resolved lazily through the
	 * container for the same reason the sibling AVG and hash-chain lookups are:
	 * this runs inside the audit write path and must degrade rather than break
	 * it. Fail-safe direction matters here — when the resolver is unavailable
	 * the row is retained INDEFINITELY rather than given a short life, because
	 * the failure mode being fixed is evidence disappearing, not disk filling.
	 *
	 * @param ObjectEntity $objectEntity The object the audit row describes.
	 * @param \DateTime $createdAt The audit row's creation instant.
	 *
	 * @return array{expires: \DateTime|null, source: string|null}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTime::createFromInterface is the standard immutable-to-mutable bridge
	 */
	private function resolveAuditExpiry(ObjectEntity $objectEntity, \DateTime $createdAt): array {
		try {
			$resolver = AuditTrailCollaborators::retentionResolverFrom(container: $this->container);

			$resolved = $resolver->resolve(object: $objectEntity, createdAt: $createdAt);

			// The resolver works in DateTimeImmutable; the entity's `expires`
			// setter is typed to the mutable DateTime that QBMapper binds.
			$expires = $resolved['expires'];
			if ($expires !== null) {
				$expires = DateTime::createFromInterface($expires);
			}

			return [
				'expires' => $expires,
				'source' => $resolved['source'],
			];
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[AuditTrailMapper] Retention resolution failed; retaining the audit row '
					. 'indefinitely: ' . $e->getMessage(),
				context: [
					'app' => 'openregister',
					'exception' => $e,
				]
			);

			return [
				'expires' => null,
				'source' => 'resolver-unavailable:indefinite',
			];
		}//end try

	}//end resolveAuditExpiry()

	/**
	 * Create audit trail rows for many object changes with batched inserts.
	 *
	 * Each entry is an array with keys `old` (?ObjectEntity), `new` (?ObjectEntity)
	 * and `action` (string). Rows are built through the exact same
	 * {@see buildEntry()} logic as {@see createAuditTrail()} so their content
	 * is identical, but they are persisted with one multi-row INSERT per chunk of
	 * 100 instead of one INSERT (plus 3 hash-chain queries) per row. After each
	 * chunk the rows are sealed into the SHA-256 hash chain in a single batched
	 * pass ({@see AuditHashService::sealRows()}). Sealing stays fail-soft exactly
	 * like the single-row path: a sealing hiccup logs and leaves rows unhashed
	 * rather than losing the audit records.
	 *
	 * @param array $entries Mixed list: ['old' => ?ObjectEntity, 'new' => ?ObjectEntity, 'action' => string,
	 *                       'cascadeContext' => ?array] entries built through {@see buildEntry()}, and/or
	 *                       pre-built AuditTrail entities (each must carry a uuid) persisted as-is
	 * @param int $chunkSize Number of rows per multi-row INSERT statement
	 *
	 * @return AuditTrail[] The persisted audit trail entities (ids populated)
	 *
	 * @throws \OCP\DB\Exception If a database error occurs during insert
	 *
	 * @psalm-return list<AuditTrail>
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function insertAuditTrails(array $entries, int $chunkSize = 100): array {
		if ($entries === []) {
			return [];
		}

		$auditTrails = [];
		foreach ($entries as $entry) {
			$auditTrails[] = $this->auditTrailFromEntry(entry: $entry);
		}

		foreach (array_chunk($auditTrails, max(1, $chunkSize)) as $chunk) {
			$this->insertAuditTrailChunk(chunk: $chunk);
		}

		return $auditTrails;
	}//end insertAuditTrails()

	/**
	 * The row one insertAuditTrails() entry stands for.
	 *
	 * Pre-built rows (e.g. cascade deletes assembled via buildEntry()
	 * with cascade context) are persisted as-is; array entries are built
	 * through the shared row logic so both shapes yield identical rows.
	 *
	 * @param mixed $entry A pre-built AuditTrail, or an old/new/action entry array.
	 *
	 * @return AuditTrail The row to insert.
	 *
	 * @throws InvalidArgumentException When a pre-built row has no uuid, or the entry is neither shape.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function auditTrailFromEntry(mixed $entry): AuditTrail {
		if ($entry instanceof AuditTrail) {
			if ($entry->getUuid() === null || $entry->getUuid() === '') {
				throw new InvalidArgumentException('insertAuditTrails() requires every pre-built row to carry a uuid.');
			}

			return $entry;
		}

		if (is_array($entry) === false) {
			throw new InvalidArgumentException('insertAuditTrails() expects AuditTrail entities or old/new/action entry arrays.');
		}

		return $this->buildEntry(
			change: new AuditedChange(
				old: ($entry['old'] ?? null),
				new: ($entry['new'] ?? null),
				action: ($entry['action'] ?? 'update'),
				cascadeContext: ($entry['cascadeContext'] ?? null)
			)
		);
	}//end auditTrailFromEntry()

	/**
	 * Persist one chunk of audit trail entities with a single multi-row INSERT.
	 *
	 * Values are converted exactly as QBMapper::insert() would convert them
	 * (json fields json_encode'd, datetime fields formatted as `Y-m-d H:i:s`),
	 * so the resulting rows are byte-identical to single-row inserts. After the
	 * insert the generated ids are read back (by row uuid) and the chunk is
	 * sealed into the hash chain in one batched pass.
	 *
	 * @param AuditTrail[] $chunk The audit trail entities to persist
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs during insert
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function insertAuditTrailChunk(array $chunk): void {
		if ($chunk === []) {
			return;
		}

		$properties = self::chunkProperties(chunk: $chunk);

		$qb = $this->db->getQueryBuilder();
		$platform = $this->db->getDatabasePlatform();

		$columns = [];
		foreach ($properties as $property) {
			// `user` is a reserved word in PostgreSQL (it doubles as the
			// CURRENT_USER function) — an unquoted `user` column in an
			// INSERT column list raises SQLSTATE 42601 ("syntax error at
			// or near \"user\""). The QueryBuilder-based single-row insert
			// path quotes identifiers automatically; this hand-built
			// multi-row INSERT must do the same for every column.
			$columns[] = $platform->quoteIdentifier($chunk[0]->propertyToColumn($property));
		}

		$tableName = $qb->getTableName($this->getTableName());

		$bound = $this->bindChunkValues(chunk: $chunk, properties: $properties);

		$sql = 'INSERT INTO ' . $tableName
			. ' (' . implode(', ', $columns) . ') VALUES '
			. implode(', ', $bound['values']);

		$this->db->executeStatement($sql, $bound['parameters']);

		$this->readBackChunkIds(chunk: $chunk, tableName: $tableName);

		// Sealing is left to AuditSealJob, exactly as on the single-row path.
		// See insertHashChained() for why: one sealer means unsealed rows form a
		// contiguous tail, which is the property that makes a fan-out
		// impossible rather than merely unlikely.

		// Shipped AFTER the ids are read back, so a line in the file can be
		// pointed at the row it came from. A bulk write that shipped before the
		// readback would put `"id":null` on every line of an import.
		$this->shipToSink(entries: $chunk);
	}//end insertAuditTrailChunk()

	/**
	 * The union of updated properties across a chunk, in first-seen order, without `id`.
	 *
	 * Rows built by buildEntry() set the same fields, but optional fields
	 * like processingActivityId/importJobId can differ per row.
	 *
	 * @param AuditTrail[] $chunk The chunk.
	 *
	 * @return list<string> The properties to insert.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private static function chunkProperties(array $chunk): array {
		$properties = [];
		foreach ($chunk as $auditTrail) {
			foreach (array_keys($auditTrail->getUpdatedFields()) as $property) {
				if ($property !== 'id' && in_array($property, $properties, true) === false) {
					$properties[] = $property;
				}
			}
		}

		return $properties;
	}//end chunkProperties()

	/**
	 * One placeholder group per row and the converted values they bind, in row order.
	 *
	 * @param AuditTrail[] $chunk      The chunk.
	 * @param list<string> $properties The properties to insert.
	 *
	 * @return array{values: list<string>, parameters: list<mixed>} The VALUES groups and parameters.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function bindChunkValues(array $chunk, array $properties): array {
		$fieldTypes = $chunk[0]->getFieldTypes();
		$valuesClauses = [];
		$parameters = [];
		foreach ($chunk as $auditTrail) {
			$placeholders = [];
			foreach ($properties as $property) {
				$getter = 'get' . ucfirst($property);
				$parameters[] = $this->payloadHelper->toDatabaseValue(
					value: $auditTrail->$getter(),
					type: ($fieldTypes[$property] ?? 'string')
				);
				$placeholders[] = '?';
			}

			$valuesClauses[] = '(' . implode(',', $placeholders) . ')';
		}

		return ['values' => $valuesClauses, 'parameters' => $parameters];
	}//end bindChunkValues()

	/**
	 * Read back the generated ids of a chunk by the uuid generated per row, and set them.
	 *
	 * @param AuditTrail[] $chunk     The inserted chunk.
	 * @param string       $tableName The prefixed table name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function readBackChunkIds(array $chunk, string $tableName): void {
		$uuidToEntity = [];
		foreach ($chunk as $auditTrail) {
			$uuidToEntity[$auditTrail->getUuid()] = $auditTrail;
		}

		$idPlaceholders = implode(',', array_fill(0, count($uuidToEntity), '?'));
		$idSql = 'SELECT id, uuid FROM ' . $tableName . ' WHERE uuid IN (' . $idPlaceholders . ')';

		$result = $this->db->executeQuery($idSql, array_keys($uuidToEntity));
		while (($row = $result->fetch()) !== false) {
			if (isset($uuidToEntity[$row['uuid']]) === true) {
				$uuidToEntity[$row['uuid']]->setId((int)$row['id']);
			}
		}

		$result->closeCursor();
	}//end readBackChunkIds()

	/**
	 * Resolve the AVG / GDPR Art 30 processing-activity uuid for this
	 * write.
	 *
	 * Order of precedence (action > schema > register):
	 *
	 *   1. Transient override on the `ObjectEntity` itself
	 *      (`getProcessingActivityId()`).
	 *   2. Schema configuration key
	 *      `x-openregister-processing-activity`.
	 *   3. Register configuration key (same key).
	 *
	 * Each candidate is run through
	 * `VerwerkingsactiviteitMapper::resolveReference` so operators can
	 * use either the short readable `code` or the canonical `uuid`. A
	 * miss is logged at debug level (typo guard) and falls through to
	 * the next candidate; a fully-unresolved chain returns `null` and
	 * the audit row's `processing_activity_id` stays unset — preserves
	 * the existing behaviour for callers that haven't opted in.
	 *
	 * @param ObjectEntity $objectEntity The entity being audited.
	 *
	 * @return string|null Resolved verwerkingsactiviteit uuid, or null.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function resolveProcessingActivityId(ObjectEntity $objectEntity): string|null {
		$vrwMapper = $this->processingActivityMapper();
		if ($vrwMapper === null) {
			return null;
		}

		// Action > schema > register: a later candidate is read only when the
		// earlier one did not resolve.
		$activity = $this->overrideActivity(vrwMapper: $vrwMapper, objectEntity: $objectEntity)
			?? $this->schemaActivity(vrwMapper: $vrwMapper, objectEntity: $objectEntity)
			?? $this->registerActivity(vrwMapper: $vrwMapper, objectEntity: $objectEntity);

		return $activity?->getUuid();
	}//end resolveProcessingActivityId()

	/**
	 * The verwerkingsactiviteit mapper, or null while it is not registered.
	 *
	 * Mapper not registered yet (e.g. during migration before service
	 * registration completes). Fail open — audit-trail tagging is additive,
	 * not required.
	 *
	 * Untyped on purpose: whatever the container answers is used as it was
	 * before this lookup moved into its own method.
	 *
	 * @return VerwerkingsactiviteitMapperInterface|null The mapper, or null.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function processingActivityMapper(): mixed {
		try {
			/*
			 * @var VerwerkingsactiviteitMapper $vrwMapper
			 */

			return $this->container->get(VerwerkingsactiviteitMapper::class);
		} catch (\Throwable $e) {
			return null;
		}
	}//end processingActivityMapper()

	/**
	 * 1. The per-action override on the ObjectEntity itself, when it resolves.
	 *
	 * A miss is logged at debug level (typo guard).
	 *
	 * @param VerwerkingsactiviteitMapperInterface $vrwMapper    The mapper references resolve through.
	 * @param ObjectEntity                $objectEntity The entity being audited.
	 *
	 * @return Verwerkingsactiviteit|null The activity, or null.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function overrideActivity($vrwMapper, ObjectEntity $objectEntity): Verwerkingsactiviteit|null {
		$override = $objectEntity->getProcessingActivityId();
		if ($override === null || $override === '') {
			return null;
		}

		$resolved = $vrwMapper->resolveReference(reference: $override);
		if ($resolved === null) {
			$this->logger->debug(
				message: '[AVG] Per-action processing-activity override did not resolve',
				context: ['reference' => $override]
			);
		}

		return $resolved;
	}//end overrideActivity()

	/**
	 * 2. The schema-level annotation, when it resolves; a miss is logged as a warning.
	 *
	 * @param VerwerkingsactiviteitMapperInterface $vrwMapper    The mapper references resolve through.
	 * @param ObjectEntity                $objectEntity The entity being audited.
	 *
	 * @return Verwerkingsactiviteit|null The activity, or null.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function schemaActivity($vrwMapper, ObjectEntity $objectEntity): Verwerkingsactiviteit|null {
		$schemaRef = $this->readProcessingActivityFromSchema(schemaId: $objectEntity->getSchema());
		if ($schemaRef === null || $schemaRef === '') {
			return null;
		}

		$resolved = $vrwMapper->resolveReference(reference: $schemaRef);
		if ($resolved === null) {
			$this->logger->warning(
				message: '[AVG] Schema annotation references unknown verwerkingsactiviteit',
				context: ['reference' => $schemaRef, 'schemaId' => $objectEntity->getSchema()]
			);
		}

		return $resolved;
	}//end schemaActivity()

	/**
	 * 3. The register-level fallback, when it resolves; a miss is logged as a warning.
	 *
	 * @param VerwerkingsactiviteitMapperInterface $vrwMapper    The mapper references resolve through.
	 * @param ObjectEntity                $objectEntity The entity being audited.
	 *
	 * @return Verwerkingsactiviteit|null The activity, or null.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function registerActivity($vrwMapper, ObjectEntity $objectEntity): Verwerkingsactiviteit|null {
		$registerRef = $this->readProcessingActivityFromRegister(registerId: $objectEntity->getRegister());
		if ($registerRef === null || $registerRef === '') {
			return null;
		}

		$resolved = $vrwMapper->resolveReference(reference: $registerRef);
		if ($resolved === null) {
			$this->logger->warning(
				message: '[AVG] Register annotation references unknown verwerkingsactiviteit',
				context: ['reference' => $registerRef, 'registerId' => $objectEntity->getRegister()]
			);
		}

		return $resolved;
	}//end registerActivity()

	/**
	 * Read the `x-openregister-processing-activity` annotation from a
	 * schema's configuration column. Null when not set or the schema
	 * cannot be loaded.
	 *
	 * @param string|int|null $schemaId Schema identifier (numeric id or uuid).
	 *
	 * @return string|null Annotation value (code or uuid), or null.
	 */
	private function readProcessingActivityFromSchema($schemaId): string|null {
		if (self::isUnsetIdentifier(identifier: $schemaId) === true) {
			return null;
		}

		try {
			/*
			 * @var SchemaMapperInterface $schemaMapper
			 */

			$schemaMapper = $this->container->get(SchemaMapperInterface::class);
			$schema = $schemaMapper->find(
				$schemaId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		return self::processingActivityAnnotation(config: $schema->getConfiguration());
	}//end readProcessingActivityFromSchema()

	/**
	 * Read the `x-openregister-processing-activity` annotation from a
	 * register's configuration column. Null when not set or the
	 * register cannot be loaded.
	 *
	 * @param string|int|null $registerId Register identifier (numeric id or uuid).
	 *
	 * @return string|null Annotation value (code or uuid), or null.
	 */
	private function readProcessingActivityFromRegister($registerId): string|null {
		if (self::isUnsetIdentifier(identifier: $registerId) === true) {
			return null;
		}

		try {
			/*
			 * @var RegisterMapperInterface $registerMapper
			 */

			$registerMapper = $this->container->get(RegisterMapperInterface::class);
			$register = $registerMapper->find(
				$registerId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		return self::processingActivityAnnotation(config: $register->getConfiguration());
	}//end readProcessingActivityFromRegister()

	/**
	 * Whether a schema or register identifier is unset: null, empty, or the integer zero.
	 *
	 * @param mixed $identifier The identifier.
	 *
	 * @return bool True when there is nothing to look up.
	 */
	private static function isUnsetIdentifier(mixed $identifier): bool {
		return $identifier === null || $identifier === '' || $identifier === 0;
	}//end isUnsetIdentifier()

	/**
	 * The non-empty string `x-openregister-processing-activity` value of a configuration, or null.
	 *
	 * @param mixed $config The configuration column.
	 *
	 * @return string|null The annotation value, or null.
	 */
	private static function processingActivityAnnotation(mixed $config): string|null {
		if (is_array($config) === false) {
			return null;
		}

		$ref = $config['x-openregister-processing-activity'] ?? null;
		if (is_string($ref) === true && $ref !== '') {
			return $ref;
		}

		return null;
	}//end processingActivityAnnotation()

	/**
	 * Get the audit trail entries made after a point in an object's history
	 *
	 * These are the entries a revert to that point undoes, newest first. The
	 * object is matched on `object_uuid`: the table has no `object_id` column,
	 * and a filter on one made every revert fail (#4161).
	 *
	 * - A DateTime returns the entries created at or after it.
	 * - An audit trail id (int or numeric string) returns this object's entries
	 *   after that entry, so a revert to it restores the state it recorded.
	 * - A semantic version returns the entries after the last one that recorded
	 *   that version.
	 * - Null returns every entry of the object.
	 *
	 * @param string                   $objectUuid The object UUID
	 * @param DateTime|int|string|null $until      DateTime, AuditTrail ID, or semantic version
	 *
	 * @return AuditTrail[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\AuditTrail>
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function findByObjectUntil(string $objectUuid, DateTime|int|string|null $until = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_audit_trails')
			->where(
				$qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid, IQueryBuilder::PARAM_STR))
			)
			->orderBy('created', 'DESC')
			->addOrderBy('id', 'DESC');

		if ($until instanceof DateTime === true) {
			$qb->andWhere(
				$qb->expr()->gte(
					'created',
					$qb->createNamedParameter($until->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)
				)
			);
		}

		if (is_int($until) === true || is_string($until) === true) {
			$afterId = $this->auditIdOfRevertPoint(objectUuid: $objectUuid, until: (string) $until);
			if ($afterId === null) {
				return [];
			}

			$qb->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)));
		}

		return $this->findEntities(query: $qb);
	}//end findByObjectUntil()

	/**
	 * Resolve a revert point given as an audit trail id or a version to the id of its entry
	 *
	 * @param string $objectUuid The object UUID
	 * @param string $until      An audit trail id or a semantic version
	 *
	 * @return int|null The entry id, or null when the object has no such entry
	 */
	private function auditIdOfRevertPoint(string $objectUuid, string $until): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('openregister_audit_trails')
			->where(
				$qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid, IQueryBuilder::PARAM_STR))
			)
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		// A semantic version matches the version column, anything else is an audit trail id.
		$column = 'id';
		$value  = (int) $until;
		$type   = IQueryBuilder::PARAM_INT;
		if ($this->payloadHelper->isSemanticVersion(version: $until) === true) {
			$column = 'version';
			$value  = $until;
			$type   = IQueryBuilder::PARAM_STR;
		}

		$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, $type)));

		$result = $qb->executeQuery();
		try {
			$row = $result->fetch();
		} finally {
			$result->closeCursor();
		}

		if (is_array($row) === false) {
			return null;
		}

		return (int) $row['id'];
	}//end auditIdOfRevertPoint()

	/**
	 * Revert an object to a previous state
	 *
	 * @param ObjectEntity|string|int $identifier The object itself, or its ID, UUID or URI to look it up by
	 * @param DateTime|string|null $until DateTime or AuditTrail ID to revert to
	 * @param bool $overwriteVersion Whether to overwrite the version or increment it
	 *
	 * @throws DoesNotExistException If object not found
	 * @throws \Exception If revert fails
	 *
	 * @return ObjectEntity The reverted object (unsaved)
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Controls version handling strategy on revert
	 */
	public function revertObject($identifier, $until = null, bool $overwriteVersion = false): ObjectEntity {
		return $this->revertObjectWith(identifier: $identifier, options: new RevertOptions(until: $until, overwriteVersion: $overwriteVersion));
	}//end revertObject()

	/**
	 * Revert an object to a point in its trail.
	 *
	 * The typed form of {@see revertObject()}, which keeps its signature for other apps.
	 *
	 * @param mixed         $identifier The object itself, or its ID, UUID or URI to look it up by.
	 * @param RevertOptions $options    The reversion point and the version handling.
	 *
	 * @throws DoesNotExistException If object not found
	 * @throws \Exception If revert fails
	 *
	 * @return ObjectEntity The reverted object (unsaved)
	 */
	public function revertObjectWith(mixed $identifier, RevertOptions $options): ObjectEntity {
		$object = $this->objectToRevert(identifier: $identifier);

		// Get audit trail entries until the specified point.
		$auditTrails = $this->findByObjectUntil(
			objectUuid: $object->getUuid(),
			until: $options->until
		);

		if (empty($auditTrails) === true && $options->until !== null) {
			throw new Exception('No audit trail entries found for the specified reversion point.');
		}

		// Create a clone of the current object to apply reversions.
		$revertedObject = clone $object;

		// Apply changes in reverse.
		foreach ($auditTrails as $audit) {
			$this->payloadHelper->revertChanges(object: $revertedObject, audit: $audit);
		}

		// Handle versioning.
		if ($options->overwriteVersion === false) {
			self::bumpPatchVersion(object: $revertedObject);
		}

		return $revertedObject;
	}//end revertObjectWith()

	/**
	 * The object to revert: the one handed in, or the one the identifier names.
	 *
	 * A caller that already checked the object passes it, so it is not looked
	 * up again. Otherwise it is lazy-resolved to avoid circular DI.
	 *
	 * @param mixed $identifier The object, or its id/uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function objectToRevert(mixed $identifier): ObjectEntity {
		if ($identifier instanceof ObjectEntity) {
			return $identifier;
		}

		return $this->container->get(MagicMapperInterface::class)->find($identifier);
	}//end objectToRevert()

	/**
	 * Raise the patch part of an object's version by one (`1.0.0` when it has none).
	 *
	 * @param ObjectEntity $object The reverted object.
	 *
	 * @return void
	 */
	private static function bumpPatchVersion(ObjectEntity $object): void {
		$version = explode('.', $object->getVersion() ?? '1.0.0');
		$version[2] = ((int)$version[2] + 1);
		$object->setVersion(implode('.', $version));
	}//end bumpPatchVersion()

	/**
	 * The number and total size of the audit trails in a scope.
	 *
	 * @param AuditStatisticsScope $scope The register and schema (null for all) and the combinations to leave out.
	 *
	 * @return int[] Array containing total count and size of audit trails:
	 *               - total: Total number of audit trails
	 *               - size: Total size of all audit trails in bytes
	 *
	 * @psalm-return array{total: int, size: int}
	 */
	public function statisticsTotals(AuditStatisticsScope $scope): array {
		return $this->statistics()->totals(scope: $scope);
	}//end statisticsTotals()

	/**
	 * Updates an entity in the database
	 *
	 * @param Entity $entity The entity to update
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
	 *
	 * @return AuditTrail The updated entity
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function update(Entity $entity): AuditTrail {
		// Recalculate size before update, with a minimum default of 14 bytes.
		$serializedSize = strlen(serialize($entity->jsonSerialize()));
		$entity->setSize(max($serializedSize, 14));

		return parent::update(entity: $entity);
	}//end update()

	/**
	 * Chart data for audit trail actions over time
	 *
	 * @param AuditStatisticsScope $scope The register and schema (null for all) and the `from`/`till` dates.
	 *
	 * @return ((int[]|string)[]|(int|string))[][]
	 *
	 * @psalm-return array{labels: list<array-key>,
	 *     series: list<array{data: list<int>, name: string}>}
	 */
	public function actionChartData(AuditStatisticsScope $scope): array {
		return $this->actionStatistics()->actionChart(scope: $scope);
	}//end actionChartData()

	/**
	 * Get detailed statistics for audit trails including action counts within timeframe
	 *
	 * @param AuditStatisticsScope $scope The register and schema to filter by (each optional) and the hours to look
	 *                                    back for recent activity (default: 24).
	 *
	 * @return int[]
	 *
	 * @psalm-return array{total: int, creates: int, updates: int,
	 *     deletes: int, reads: int}
	 */
	public function detailedStatistics(AuditStatisticsScope $scope): array {
		$total = $this->statisticsTotals(scope: new AuditStatisticsScope(registerId: $scope->registerId, schemaId: $scope->schemaId))['total'];

		return $this->actionStatistics()->windowedActions(scope: $scope, total: $total);
	}//end detailedStatistics()

	/**
	 * Lifetime row counts per action, for the actions that start with a prefix
	 *
	 * An app that writes its own audit actions, such as portaliq's
	 * `portaliq.login`, counts them here in one grouped query instead of loading
	 * every row. The prefix is matched literally: `_` and `%` are not wildcards.
	 *
	 * @param string $prefix The action prefix, for example `portaliq.`.
	 *
	 * @return array<string, int> Keyed by the full action, e.g. ['portaliq.login' => 1204].
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md
	 */
	public function countByActionPrefix(string $prefix): array {
		return $this->actionStatistics()->countByActionPrefix(prefix: $prefix);
	}//end countByActionPrefix()

	/**
	 * Get lifetime audit trail counts grouped by action
	 *
	 * Unlike detailedStatistics(), which windows the per-action counts to
	 * the last N hours, this returns lifetime counts so the audit-trail page
	 * statistics line up with the (unwindowed) audit-trail list beside them.
	 * Keys are singular to match the audit-trail REST surface; the windowed
	 * dashboard surface uses plural keys.
	 *
	 * @param int|null $registerId Optional register ID to filter by
	 * @param int|null $schemaId Optional schema ID to filter by
	 *
	 * @return int[] Total plus a lifetime count per action. The total counts
	 *               every row, including rows whose action is outside the four
	 *               known ones.
	 *
	 * @psalm-return array{total: int, create: int, update: int,
	 *     delete: int, read: int}
	 */
	public function getActionCounts(?int $registerId = null, ?int $schemaId = null): array {
		return $this->actionStatistics()->lifetimeActions(scope: new AuditStatisticsScope(registerId: $registerId, schemaId: $schemaId));
	}//end getActionCounts()

	/**
	 * Get action distribution data with percentages
	 *
	 * @param AuditStatisticsScope $scope The register and schema to filter by (each optional) and the hours to look
	 *                                    back (default: 24).
	 *
	 * @return (int|mixed)[][][]
	 *
	 * @psalm-return array{actions: list<array{count: int, name: mixed}>}
	 */
	public function actionDistribution(AuditStatisticsScope $scope): array {
		return $this->actionStatistics()->actionDistribution(scope: $scope);
	}//end actionDistribution()

	/**
	 * Get most active objects based on audit trail activity
	 *
	 * @param AuditStatisticsScope $scope The register and schema to filter by (each optional), the number of results
	 *                                    (default: 10) and the hours to look back (default: 24).
	 *
	 * @return (int|mixed|string)[][][]
	 *
	 * @psalm-return array{objects: list<array{count: int, id: mixed, name: string}>}
	 */
	public function mostActiveObjects(AuditStatisticsScope $scope): array {
		return $this->statistics()->mostActiveObjects(scope: $scope);
	}//end mostActiveObjects()

	/**
	 * Purge expired audit rows, leaving a verifiable chain tombstone.
	 *
	 * Rows whose `expires` has passed have their PAYLOAD destroyed — the
	 * `changed` diff and the actor/session/network identifiers — and are
	 * stamped with `purged_at`. The row itself survives.
	 *
	 * It is an UPDATE, not a DELETE, and that is the whole point. The table
	 * carries a SHA-256 `hash`/`previous_hash` chain that
	 * {@see \OCA\OpenRegister\Service\AuditHashService::verifyChain()} walks in
	 * id order, each row's hash covering the previous row's hash. Physically
	 * removing a row mid-chain therefore breaks verification at the row AFTER
	 * it — which is exactly what tampering looks like. Under the old hard
	 * delete a lawful retention purge and an attack were indistinguishable
	 * (or#2265). Keeping id, `created`, `hash` and `previous_hash` preserves
	 * the link, and the `purged_at` stamp lets verification report a DECLARED
	 * tombstone instead of a break.
	 *
	 * `expires IS NULL` still means "never purge" and is now the resolver's
	 * outcome for objects with no retention policy, under legal hold, or
	 * nominated `bewaren` — see {@see \OCA\OpenRegister\Service\AuditRetentionResolver}.
	 *
	 * Already-tombstoned rows are excluded so repeated sweeps are idempotent
	 * and do not keep rewriting the same rows every hour.
	 *
	 * @return bool True if any rows were tombstoned, false otherwise
	 *
	 * @throws \Exception Database operation exceptions
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 */
	/**
	 * Tombstone every audit-trail entry.
	 *
	 * ⚠️ Tombstones rather than deletes: the payload is destroyed but the row and
	 * its chain links survive, because the hash chain is what makes the trail
	 * evidence. Deleting rows would break the chain and leave nothing to show
	 * that anything was ever there.
	 *
	 * @return bool True when the tombstone write succeeded.
	 */
	public function clearLogs(): bool {
		try {
			// Get the query builder for database operations.
			$qb = $this->db->getQueryBuilder();

			// Tombstone rather than delete: destroy the payload, keep the row
			// and its chain links. `changed` holds the actual before/after
			// data; user/session/request/ip are the personal identifiers a
			// retention purge is meant to remove. Everything retained here is
			// structural: which register/schema/object was acted on, when, and
			// the hash pair that proves the row was not substituted.
			//
			// ⚠️ `changed` and `user` are NOT NULL; the other four are nullable.
			// Blanking all six to NULL made this statement throw on EVERY run,
			// and the catch below swallowed it — so the retention purge had
			// never once succeeded: 3,254,448 rows on the dev instance, 0 of
			// them purged, 13,472 already past their `expires`. An empty JSON
			// object and an empty string destroy the payload and the identifier
			// just as completely while satisfying the constraints, which keeps
			// this a data change rather than a migration.
			$qb->update('openregister_audit_trails')
				->set('purged_at', $qb->createFunction('NOW()'))
				->set('changed', $qb->createNamedParameter('{}'))
				->set('user', $qb->createNamedParameter(''))
				->set('user_name', $qb->createNamedParameter(null))
				->set('session', $qb->createNamedParameter(null))
				->set('request', $qb->createNamedParameter(null))
				->set('ip_address', $qb->createNamedParameter(null))
				->where($qb->expr()->isNotNull('expires'))
				->andWhere($qb->expr()->lt('expires', $qb->createFunction('NOW()')))
				->andWhere($qb->expr()->isNull('purged_at'));

			// Execute the query and get the number of affected rows.
			$result = $qb->executeStatement();

			// Return true if any rows were affected (i.e., any logs were tombstoned).
			return $result > 0;
		} catch (\Exception $e) {
			// Log the error for debugging purposes.
			$this->logger->error(
				message: '[AuditTrailMapper] Failed to clear expired audit trail logs: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'exception' => $e,
				]
			);

			// Re-throw the exception so the caller knows something went wrong.
			throw $e;
		}//end try
	}//end clearLogs()

	/**
	 * Count the live rows of one object's trail within an action scope.
	 *
	 * @param string      $objectUuid The object's UUID.
	 * @param ActionScope $scope      The actions included and the actions never touched.
	 *
	 * @return int The number of rows that would be tombstoned.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function countObjectTrail(string $objectUuid, ActionScope $scope): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'row_count'))
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->isNull('purged_at'));

		self::andActionScope(qb: $qb, scope: $scope);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countObjectTrail()

	/**
	 * Read the audit rows of one object, by action, newest first.
	 *
	 * Keyed on `object_uuid` rather than the numeric `object` id, because the
	 * caller this exists for reads a destruction record AFTER the object row
	 * is gone and the numeric id resolves to nothing.
	 *
	 * @param string $objectUuid The object's UUID.
	 * @param array<int, string> $actions Actions to return; every action when empty.
	 * @param int $limit Maximum rows.
	 *
	 * @return array<int, AuditTrail> The matching rows, newest first.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function findForObjectByAction(string $objectUuid, array $actions = [], int $limit = 50): array {
		return $this->findObjectActions(objectUuid: $objectUuid, options: new ActionLookupOptions(actions: $actions, limit: $limit));
	}//end findForObjectByAction()

	/**
	 * One object's entries, optionally only some actions, newest first.
	 *
	 * The typed form of {@see findForObjectByAction()}, which keeps its signature for other apps.
	 *
	 * @param string              $objectUuid The object's UUID.
	 * @param ActionLookupOptions $options    The actions to return (every action when empty) and the row cap.
	 *
	 * @return array<int, AuditTrail> The matching rows, newest first.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function findObjectActions(string $objectUuid, ActionLookupOptions $options): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('created', 'DESC')
			->setMaxResults($options->limit);

		if ($options->actions !== []) {
			$qb->andWhere(
				$qb->expr()->in('action', $qb->createNamedParameter($options->actions, IQueryBuilder::PARAM_STR_ARRAY))
			);
		}

		return $this->findEntities(query: $qb);
	}//end findObjectActions()

	/**
	 * Tombstone the rows of one object's trail within an action scope.
	 *
	 * @param string      $objectUuid The object's UUID.
	 * @param ActionScope $scope      The actions included and the actions never touched.
	 *
	 * @return int The number of rows tombstoned.
	 *
	 * @throws \Exception When the statement fails.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function tombstoneObjectTrail(string $objectUuid, ActionScope $scope): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('openregister_audit_trails')
			->set('purged_at', $qb->createFunction('NOW()'))
			->set('changed', $qb->createNamedParameter('{}'))
			->set('user', $qb->createNamedParameter(''))
			->set('user_name', $qb->createNamedParameter(null))
			->set('session', $qb->createNamedParameter(null))
			->set('request', $qb->createNamedParameter(null))
			->set('ip_address', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->isNull('purged_at'));

		self::andActionScope(qb: $qb, scope: $scope);

		return (int)$qb->executeStatement();
	}//end tombstoneObjectTrail()

	/**
	 * Restrict a query on one object's trail to an action scope.
	 *
	 * @param IQueryBuilder $qb    The query.
	 * @param ActionScope   $scope The actions included and the actions never touched.
	 *
	 * @return void
	 */
	private static function andActionScope(IQueryBuilder $qb, ActionScope $scope): void {
		self::andActionIn(qb: $qb, onlyActions: $scope->onlyActions);
		self::andActionNotIn(qb: $qb, excludeActions: $scope->excludeActions);
	}//end andActionScope()

	/**
	 * Restrict a per-object query to these actions; null or empty restricts nothing.
	 *
	 * @param IQueryBuilder           $qb          The query being built.
	 * @param array<int, string>|null $onlyActions The actions, or null for every action.
	 *
	 * @return void
	 */
	private static function andActionIn(IQueryBuilder $qb, ?array $onlyActions): void {
		if ($onlyActions !== null && $onlyActions !== []) {
			$qb->andWhere($qb->expr()->in('action', $qb->createNamedParameter($onlyActions, IQueryBuilder::PARAM_STR_ARRAY)));
		}
	}//end andActionIn()

	/**
	 * Leave these actions out of a per-object query, such as the evidence of a destruction.
	 *
	 * @param IQueryBuilder      $qb             The query being built.
	 * @param array<int, string> $excludeActions The actions never touched.
	 *
	 * @return void
	 */
	private static function andActionNotIn(IQueryBuilder $qb, array $excludeActions): void {
		if ($excludeActions !== []) {
			$qb->andWhere($qb->expr()->notIn('action', $qb->createNamedParameter($excludeActions, IQueryBuilder::PARAM_STR_ARRAY)));
		}
	}//end andActionNotIn()

	/**
	 * Clear all audit trail logs (not just expired ones)
	 *
	 * This method deletes all audit trail logs from the database
	 *
	 * @return bool True if any logs were deleted, false otherwise
	 *
	 * @throws \Exception If the deletion fails
	 */
	public function clearAllLogs(): bool {
		try {
			// Get the query builder for database operations.
			$qb = $this->db->getQueryBuilder();

			// Build the delete query to remove ALL audit trail logs.
			$qb->delete('openregister_audit_trails');

			// Execute the query and get the number of affected rows.
			$result = $qb->executeStatement();

			// Return true if any rows were affected (i.e., any logs were deleted).
			return $result > 0;
		} catch (\Exception $e) {
			// Log the error for debugging purposes.
			$this->logger->error(
				message: '[AuditTrailMapper] Failed to clear all audit trail logs: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'exception' => $e,
				]
			);

			// Re-throw the exception so the caller knows something went wrong.
			throw $e;
		}//end try
	}//end clearAllLogs()

	/**
	 * Set expiry dates for audit trails based on retention period in milliseconds
	 *
	 * Updates the expires column for audit trails based on their creation date plus the retention period.
	 * Only affects audit trails that don't already have an expiry date set.
	 *
	 * @param int $retentionMs Retention period in milliseconds
	 *
	 * @return int Number of audit trails updated
	 *
	 * @throws \Exception Database operation exceptions
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-support-minimum-10-year-retention
	 */
	public function setExpiryDate(int $retentionMs): int {
		try {
			// Convert milliseconds to seconds for DateTime calculation.
			$retentionSeconds = intval($retentionMs / 1000);

			// Get the query builder.
			$qb = $this->db->getQueryBuilder();

			// DATE_ADD is MySQL and MariaDB only, so the interval is spelled per
			// platform. SearchTrailMapper::setExpiryDate() already learned this
			// the hard way when the hourly LogCleanUpTask started calling it; the
			// audit-trail twin kept the MySQL-only expression, which would throw
			// on every PostgreSQL install the moment anything calls it.
			$expiresExpression = sprintf('DATE_ADD(created, INTERVAL %d SECOND)', $retentionSeconds);
			if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
				$expiresExpression = sprintf("created + INTERVAL '%d seconds'", $retentionSeconds);
			}

			// Update audit trails that don't have an expiry date set.
			$qb->update($this->getTableName())
				->set('expires', $qb->createFunction($expiresExpression))
				->where($qb->expr()->isNull('expires'));

			// Execute the update and return number of affected rows.
			return $qb->executeStatement();
		} catch (\Exception $e) {
			// Log the error for debugging purposes.
			$this->logger->error(
				message: '[AuditTrailMapper] Failed to set expiry dates for audit trails: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'exception' => $e,
				]
			);

			// Re-throw the exception so the caller knows something went wrong.
			throw $e;
		}//end try
	}//end setExpiryDate()

	/**
	 * Get audit trail statistics grouped by schema for multiple schemas in a single query
	 *
	 * Returns per-schema statistics using GROUP BY instead of one query per schema.
	 * This replaces N individual statisticsTotals() calls with 1 query.
	 *
	 * @param int[] $schemaIds Array of schema IDs to get statistics for
	 *
	 * @return array<int, array{total: int, size: int}> Map of schemaId => statistics array
	 */
	public function getStatisticsGroupedBySchema(array $schemaIds): array {
		return $this->statistics()->totalsPerSchema(schemaIds: $schemaIds);
	}//end getStatisticsGroupedBySchema()

	/**
	 * Create a custom audit trail entry for archival operations, or for any
	 * other caller that needs a non-CRUD action recorded with an explicit
	 * actor.
	 *
	 * `$actorId`/`$actorName` exist for callers acting on behalf of a
	 * non-human principal — e.g. `registry-subscriptions`' inbound update
	 * endpoint, which authenticates as a connector's app-password account
	 * but must audit the REGISTRY (`registry:brp`) as the actor, not
	 * whichever Nextcloud account the app password happens to belong to.
	 * Omit both to keep the previous session-derived behavior unchanged.
	 *
	 * @param ObjectEntity $object The object the entry relates to
	 * @param string $action The archival action (e.g., archival.destroyed)
	 * @param array $context Additional context data
	 * @param string|null $actorId Explicit actor id, bypassing the session user. Null uses the session.
	 * @param string|null $actorName Explicit actor display name, paired with $actorId.
	 * @param string|null $ipAddress The calling address, for callers that have one and must record it.
	 *
	 * `$ipAddress` exists for a principal that is not a session at all: an
	 * access link is opened by somebody with no account, and reconstructing a
	 * wrong publication needs where it was opened from as much as when. Omit it
	 * and the column is left exactly as before, so no existing caller changes.
	 *
	 * @return AuditTrail The created audit trail entry
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 *
	 */
	public function createAuditTrailEntry(
		ObjectEntity $object,
		string $action,
		array $context = [],
		?string $actorId = null,
		?string $actorName = null,
		?string $ipAddress = null,
	): AuditTrail {
		$options = new ObjectAuditEventOptions(actor: new NamedActor(id: $actorId, name: $actorName), ipAddress: $ipAddress);

		return $this->recordObjectEvent(event: new ObjectAuditEvent(object: $object, action: $action, context: $context, options: $options));
	}//end createAuditTrailEntry()

	/**
	 * Record a non-CRUD action on one object, with an explicit actor when one is named.
	 *
	 * The typed form of {@see createAuditTrailEntry()}, which keeps its signature for other apps.
	 *
	 * @param ObjectAuditEvent $event The object, action, context, and the explicit actor and address.
	 *
	 * @return AuditTrail The created audit trail entry
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function recordObjectEvent(ObjectAuditEvent $event): AuditTrail {
		$actor = $this->entryActor(actorId: $event->options->actor->id, actorName: $event->options->actor->name);
		$auditTrail = self::actorEntry(action: $event->action, actor: $actor);
		$auditTrail->setObject($event->object->getId());
		$auditTrail->setObjectUuid($event->object->getUuid());
		$auditTrail->setRegister($event->object->getRegister());
		$auditTrail->setSchema($event->object->getSchema());
		$auditTrail->setChanged($event->context);
		self::stampIpAddress(auditTrail: $auditTrail, ipAddress: $event->options->ipAddress);

		return $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordObjectEvent()

	/**
	 * The actor a createAuditTrailEntry() row names.
	 *
	 * An explicit actor id is used with its name, or with itself when no name
	 * is given. Without one the session user is the actor, and the given name
	 * is ignored.
	 *
	 * SECURITY / AVG: keep `user_name` populated even though the migration
	 * (Version1Date20260423100000) relaxed NOT NULL on the column to support
	 * referential-integrity rows that have no displayable actor. Without this
	 * default, every audit row produced through this entry point would persist
	 * with a NULL `user_name` — undermining GDPR Art 30 §4 supervisor review.
	 *
	 * @param string|null $actorId   Explicit actor id, or null for the session.
	 * @param string|null $actorName Explicit actor display name.
	 *
	 * @return array{userId: string, userName: string} The actor.
	 */
	private function entryActor(string|null $actorId, string|null $actorName): array {
		$actor = ['userId' => $actorId, 'userName' => $actorName];
		if ($actorId === null) {
			$actor = $this->sessionActor();
		}

		return ['userId' => $actor['userId'], 'userName' => ($actor['userName'] ?? $actor['userId'])];
	}//end entryActor()

	/**
	 * The session user as an audit actor, or `system` / `System` without one.
	 *
	 * @return array{userId: string, userName: string} The actor.
	 */
	private function sessionActor(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['userId' => 'system', 'userName' => 'System'];
		}

		return ['userId' => $user->getUID(), 'userName' => $user->getDisplayName()];
	}//end sessionActor()

	/**
	 * A new row that names its actor: a fresh uuid, the action, the actor and the current time.
	 *
	 * Every direct entry writer starts from this row and adds what its entry is about.
	 *
	 * @param string                                  $action The action.
	 * @param array{userId: string, userName: string} $actor  Who the row names.
	 *
	 * @return AuditTrail The row, not yet inserted.
	 */
	private static function actorEntry(string $action, array $actor): AuditTrail {
		$auditTrail = new AuditTrail();
		$auditTrail->setUuid((string)new UuidV4());
		$auditTrail->setAction($action);
		$auditTrail->setUser($actor['userId']);
		$auditTrail->setUserName($actor['userName']);
		$auditTrail->setCreated(new DateTime());

		return $auditTrail;
	}//end actorEntry()

	/**
	 * Record the calling address on a row, trimmed, when one was given.
	 *
	 * @param AuditTrail  $auditTrail The row.
	 * @param string|null $ipAddress  The calling address, if any.
	 *
	 * @return void
	 */
	private static function stampIpAddress(AuditTrail $auditTrail, string|null $ipAddress): void {
		if ($ipAddress !== null && trim($ipAddress) !== '') {
			$auditTrail->setIpAddress(trim($ipAddress));
		}
	}//end stampIpAddress()

	/**
	 * Record one MCP tool invocation.
	 *
	 * @param ToolInvocation $invocation The tool id, argument digest, outcome summary and what it touched.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function recordToolInvocation(ToolInvocation $invocation): AuditTrail {
		$auditTrail = self::actorEntry(action: 'mcp.' . self::toolVerb(toolId: $invocation->toolId), actor: $this->sessionActor());
		// `changed` is NOT NULL and QBMapper writes only the fields a setter
		// touched: a tool call changes no object, so it records an empty set (#4279).
		$auditTrail->setChanged([]);
		$auditTrail->setToolId($invocation->toolId);
		$auditTrail->setParamsDigest($invocation->paramsDigest);
		$auditTrail->setResultSummary($invocation->resultSummary);
		$invocation->about->stampOnto(auditTrail: $auditTrail);

		return $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordToolInvocation()

	/**
	 * The verb of a namespaced tool id: the part after the last dot, or the whole id without one.
	 *
	 * @param string $toolId The tool id, e.g. `pipelinq.lead.search`.
	 *
	 * @return string The verb, e.g. `search`.
	 */
	private static function toolVerb(string $toolId): string {
		if (str_contains($toolId, '.') === false) {
			return $toolId;
		}

		return substr($toolId, (strrpos($toolId, '.') + 1));
	}//end toolVerb()

	/**
	 * Get processing activities from audit trail entries.
	 *
	 * Groups audit trail entries by processing activity ID and returns
	 * summary statistics for each activity.
	 *
	 * @param string|null $organisationId Optional organisation filter
	 *
	 * @return array List of processing activity summaries
	 */
	public function getProcessingActivities(?string $organisationId = null): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created', 'DESC');

		if ($organisationId !== null) {
			$qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisationId)));
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		// Group by action type as processing activities.
		$activities = [];
		foreach ($rows as $row) {
			$activities = self::countActivityRow(activities: $activities, row: $row);
		}

		return array_values($activities);
	}//end getProcessingActivities()

	/**
	 * Count one audit row into its action's activity summary.
	 *
	 * @param array<string, array<string, mixed>> $activities The summaries so far, keyed by action.
	 * @param array<string, mixed>                $row        The audit row.
	 *
	 * @return array<string, array<string, mixed>> The summaries with the row counted.
	 */
	private static function countActivityRow(array $activities, array $row): array {
		$action = $row['action'] ?? 'unknown';
		if (isset($activities[$action]) === false) {
			$activities[$action] = [
				'processingActivityId' => $action,
				'entryCount' => 0,
				'firstSeen' => $row['created'] ?? null,
				'lastSeen' => $row['created'] ?? null,
			];
		}

		$activities[$action]['entryCount']++;
		if (($row['created'] ?? null) !== null) {
			$activities[$action] = self::widenSeenSpan(activity: $activities[$action], created: $row['created']);
		}

		return $activities;
	}//end countActivityRow()

	/**
	 * Move an activity's first and last seen moments out to include one more row.
	 *
	 * @param array<string, mixed> $activity The activity summary.
	 * @param mixed                $created  The row's `created` value (not null).
	 *
	 * @return array<string, mixed> The summary with its span widened.
	 */
	private static function widenSeenSpan(array $activity, mixed $created): array {
		if ($activity['firstSeen'] === null || $created < $activity['firstSeen']) {
			$activity['firstSeen'] = $created;
		}

		if ($activity['lastSeen'] === null || $created > $activity['lastSeen']) {
			$activity['lastSeen'] = $created;
		}

		return $activity;
	}//end widenSeenSpan()

	/**
	 * Find audit trail entries by identifier.
	 *
	 * Searches the changed JSON field for entries matching the given identifier,
	 * grouped by schema.
	 *
	 * @param string $identifier The identifier to search for
	 *
	 * @return array Matching audit trail entries grouped by schema
	 */
	public function findByIdentifier(string $identifier): array {
		$qb = $this->db->getQueryBuilder();

		$likeParam = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($identifier) . '%');
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->like('changed', $likeParam))
			->orderBy('created', 'DESC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		// Group results by schema.
		$grouped = [];
		foreach ($rows as $row) {
			$schemaId = $row['schema'] ?? 'unknown';
			if (isset($grouped[$schemaId]) === false) {
				$grouped[$schemaId] = [];
			}

			$grouped[$schemaId][] = $row;
		}

		return $grouped;
	}//end findByIdentifier()

	/**
	 * One page of the entries one actor produced, with the unbounded total.
	 *
	 * @param ActorTrailQuery $query The actor, the page and the optional action and date range.
	 *
	 * @return array{results: array<int, AuditTrail>, total: int}
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md
	 */
	public function findActorTrail(ActorTrailQuery $query): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($query->userId)))
			->orderBy('created', 'DESC')
			->setMaxResults($query->limit)
			->setFirstResult($query->offset);

		self::andActionWindow(qb: $qb, window: $query->window);

		return [
			'results' => $this->findEntities(query: $qb),
			'total' => $this->countActorTrail(query: $query),
		];
	}//end findActorTrail()

	/**
	 * How many entries one actor produced within the window, unbounded by the page.
	 *
	 * @param ActorTrailQuery $query The actor and the optional action and date range.
	 *
	 * @return int The total.
	 */
	private function countActorTrail(ActorTrailQuery $query): int {
		$count = $this->db->getQueryBuilder();
		$count->select($count->func()->count('*', 'total_count'))
			->from($this->getTableName())
			->where($count->expr()->eq('user', $count->createNamedParameter($query->userId)));

		self::andActionWindow(qb: $count, window: $query->window);

		$result = $count->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['total_count'] ?? 0);
	}//end countActorTrail()

	/**
	 * Narrow a query to an action and a `created` range, each only when given.
	 *
	 * @param IQueryBuilder $qb     The query.
	 * @param ActionWindow  $window The optional action and date range.
	 *
	 * @return void
	 */
	private static function andActionWindow(IQueryBuilder $qb, ActionWindow $window): void {
		self::andActionIs(qb: $qb, action: $window->action);
		self::andCreatedFrom(qb: $qb, from: $window->from);
		self::andCreatedUntil(qb: $qb, until: $window->to);
	}//end andActionWindow()

	/**
	 * The optional action filter of findActorTrail(), only when non-empty.
	 *
	 * @param IQueryBuilder $qb     The query being built.
	 * @param string|null   $action Action filter.
	 *
	 * @return void
	 */
	private static function andActionIs(IQueryBuilder $qb, string|null $action): void {
		if ($action !== null && $action !== '') {
			$qb->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));
		}
	}//end andActionIs()

	/**
	 * Record a write refused because the resource is shared master data another organisation holds.
	 *
	 * The actor is pseudonymous and the context is redacted before it arrives;
	 * {@see AuditTrailMapperInterface::recordSharedMasterDataRefusal()} explains why.
	 *
	 * @param SharedMasterDataRefusal $refusal The organisations, the resource type, and the context, target and actor.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	public function recordSharedMasterDataRefusal(SharedMasterDataRefusal $refusal): AuditTrail {
		$options = $refusal->options;
		$actor = self::refusalActor(actorId: $options->actor->id, actorReference: $options->actor->name);
		$auditTrail = self::actorEntry(action: 'shared_master_data_write_denied', actor: $actor);
		$options->target->stampOnto(auditTrail: $auditTrail);
		$auditTrail->setOrganisationId($refusal->sourceOrganisation);
		$summary = [
			'holderOrganisation' => $refusal->holderOrganisation,
			'sourceOrganisation' => $refusal->sourceOrganisation,
			'resourceType' => $refusal->resourceType,
		];
		$auditTrail->setResultSummary(array_merge($options->context, $summary, ['outcome' => 'refused']));

		return $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordSharedMasterDataRefusal()

	/**
	 * The actor a shared-master-data refusal names: `system` without an id, the id without a reference.
	 *
	 * @param string|null $actorId        Acting user id, or null/empty for a system context.
	 * @param string|null $actorReference Pseudonymous actor reference for the `user_name` column.
	 *
	 * @return array{userId: string, userName: string} The actor.
	 */
	private static function refusalActor(string|null $actorId, string|null $actorReference): array {
		$userId = $actorId;
		if ($userId === null || $userId === '') {
			$userId = 'system';
		}

		$userName = $actorReference;
		if ($userName === null || $userName === '') {
			$userName = $userId;
		}

		return ['userId' => $userId, 'userName' => $userName];
	}//end refusalActor()

	/**
	 * Record a person query refused over the administered cap.
	 *
	 * @param PartyQueryRefusal $refusal The query, the cap, the count it would have matched and the schema.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-person-query-over-the-administered-cap-is-refused-req-prm-004
	 */
	public function recordPartyQueryRefusal(PartyQueryRefusal $refusal): AuditTrail {
		$auditTrail = self::actorEntry(action: 'party.query-refused', actor: $this->sessionActor());
		$auditTrail->setSchema($refusal->schema);
		$summary = ['query' => $refusal->query, 'cap' => $refusal->cap, 'wouldHaveMatched' => $refusal->would, 'returned' => 0];
		$auditTrail->setResultSummary($summary);

		return $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordPartyQueryRefusal()

	/**
	 * Record a read refused for want of a usable purpose.
	 *
	 * @param PurposeRefusal $refusal The rule, the purpose named, and the register and schema read.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function recordPurposeRefusal(PurposeRefusal $refusal): AuditTrail {
		$auditTrail = self::actorEntry(action: PurposeGuardInterface::ACTION_REFUSED, actor: $this->sessionActor());
		$refusal->target->stampOnto(auditTrail: $auditTrail);
		$auditTrail->setResultSummary(['rule' => $refusal->rule, 'declaredPurpose' => $refusal->purpose, 'refused' => true]);

		return $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordPurposeRefusal()

	/**
	 * Count audit rows per purpose over a period.
	 *
	 * This is the report the doelbinding requirement exists for: "a month of
	 * queries under three purposes, each purpose carrying its own count". It
	 * groups on the `purpose` COLUMN rather than on the sealed copy in
	 * `result_summary`, because a `GROUP BY` inside a JSON document is not
	 * portable across the databases this app supports, which is exactly why
	 * the column exists.
	 *
	 * Rows with no purpose are counted too, under the key `null`. An
	 * unattributed read is a finding, not a row to hide from the report.
	 *
	 * @param DateTime|null $from Start of the period, inclusive.
	 * @param DateTime|null $to   End of the period, inclusive.
	 *
	 * @return array<string, int> Purpose code to count, unattributed rows under `null`.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function countByPurpose(?DateTime $from = null, ?DateTime $to = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('purpose')
			->selectAlias($qb->createFunction('COUNT(*)'), 'entry_count')
			->from($this->getTableName())
			->groupBy('purpose');

		self::andCreatedFrom(qb: $qb, from: $from);
		self::andCreatedUntil(qb: $qb, until: $to);

		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$counts[self::purposeKey(purpose: $row['purpose'])] = (int)$row['entry_count'];
		}

		$result->closeCursor();

		return $counts;
	}//end countByPurpose()

	/**
	 * Bound `created` from below, only when a bound is given.
	 *
	 * A DateTime is bound as a date; a string (findActorTrail()'s ISO-8601 filter)
	 * is bound as it was given, and an empty one is no bound.
	 *
	 * @param IQueryBuilder        $qb   The query being built.
	 * @param DateTime|string|null $from Inclusive lower bound.
	 *
	 * @return void
	 */
	private static function andCreatedFrom(IQueryBuilder $qb, DateTime|string|null $from): void {
		if ($from instanceof DateTime) {
			$qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}

		if (is_string($from) === true && $from !== '') {
			$qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from)));
		}
	}//end andCreatedFrom()

	/**
	 * Bound `created` from above, only when a bound is given; bound the way andCreatedFrom() binds.
	 *
	 * @param IQueryBuilder        $qb    The query being built.
	 * @param DateTime|string|null $until Inclusive upper bound.
	 *
	 * @return void
	 */
	private static function andCreatedUntil(IQueryBuilder $qb, DateTime|string|null $until): void {
		if ($until instanceof DateTime) {
			$qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($until, IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}

		if (is_string($until) === true && $until !== '') {
			$qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($until)));
		}
	}//end andCreatedUntil()

	/**
	 * The count key of a purpose: the purpose as a string, or `null` for none.
	 *
	 * @param mixed $purpose The `purpose` column.
	 *
	 * @return string The key.
	 */
	private static function purposeKey(mixed $purpose): string {
		if ($purpose !== null && $purpose !== '') {
			return (string)$purpose;
		}

		return 'null';
	}//end purposeKey()

	/**
	 * Hand freshly written rows to the configured file sink.
	 *
	 * Fail-soft, deliberately and completely. The database row IS the trail;
	 * the file is a copy for something else to read (D-1). An audited write
	 * that threw because a log directory was full would mean a filesystem
	 * problem could stop people saving objects, which is a worse failure than
	 * the one this exists to prevent. The gap is recorded instead, and the
	 * operations console reports it.
	 *
	 * @param AuditTrail[] $entries The rows just written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function shipToSink(array $entries): void {
		try {
			/*
			 * @var AuditSinkShipperInterface $sink
			 */

			$sink = $this->container->get(AuditSinkShipperInterface::class);
		} catch (Throwable $e) {
			// Not registered yet, e.g. during a migration before service
			// registration completes.
			return;
		}

		if (($sink instanceof AuditSinkShipperInterface) === false || $sink->isConfigured() === false) {
			return;
		}

		try {
			$sink->setFailureRecorder(
				function (AuditTrail $unshipped, string $error): void {
					$this->recordSinkGap(unshipped: $unshipped, error: $error);
				}
			);
			$sink->shipMany(entries: $entries);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[AuditSink] Shipping failed outright; the database entries are unaffected.',
				context: ['error' => $e->getMessage()]
			);
		}
	}//end shipToSink()

	/**
	 * Record on the trail that an entry did not reach the sink.
	 *
	 * Written straight through `insert()` rather than through
	 * `insertHashChained()` on purpose: the chained path ships what it writes,
	 * and shipping the record of a broken sink into that same broken sink is
	 * how a failure becomes a loop.
	 *
	 * @param AuditTrail $unshipped The entry that did not ship.
	 * @param string     $error     What went wrong.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function recordSinkGap(AuditTrail $unshipped, string $error): void {
		$gap = new AuditTrail();
		$gap->setUuid((string)Uuid::v4());
		$gap->setAction(AuditSinkShipperInterface::ACTION_SINK_FAILED);
		$gap->setResultSummary(
			[
				'error' => $error,
				'firstUnshippedUuid' => $unshipped->getUuid(),
				'firstUnshippedId' => $unshipped->getId(),
			]
		);
		$gap->setUser('system');
		$gap->setUserName('System');
		$gap->setCreated(new DateTime());

		$this->insert(entity: self::withChangeList(auditTrail: $gap));
	}//end recordSinkGap()

	/**
	 * The entry with an empty change list when it records no change.
	 *
	 * `changed` is NOT NULL: a refusal, an export or a gap record that names no
	 * change is written with `[]`, or the insert fails and the record is lost.
	 *
	 * @param AuditTrail $auditTrail The entry to insert.
	 *
	 * @return AuditTrail The same entry.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private static function withChangeList(AuditTrail $auditTrail): AuditTrail {
		$auditTrail->setChanged($auditTrail->getChanged());

		return $auditTrail;
	}//end withChangeList()

	/**
	 * Record one change to a security control, or one refused attempt at a change.
	 *
	 * @param HardeningChange $change   The control, the values before and asked for, and the refusal.
	 * @param bool            $accepted Whether the change was made.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function recordHardeningChange(HardeningChange $change, bool $accepted): AuditTrail {
		$summary = ['control' => $change->control, 'before' => $change->before, 'after' => $change->after, 'accepted' => $accepted];
		if ($change->refusal !== '') {
			$summary['refusal'] = $change->refusal;
		}

		$auditTrail = self::actorEntry(action: self::hardeningAction(accepted: $accepted), actor: $this->sessionActor());
		$auditTrail->setResultSummary($summary);

		return $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordHardeningChange()

	/**
	 * The action of a hardening row: a change that was made, or one that was refused.
	 *
	 * @param bool $accepted Whether the change was made.
	 *
	 * @return string The action.
	 */
	private static function hardeningAction(bool $accepted): string {
		if ($accepted === true) {
			return 'hardening.changed';
		}

		return 'hardening.refused';
	}//end hardeningAction()
	/**
	 * Record one export, completed or refused.
	 *
	 * @param ExportOutcome $export The outcome, the summary, the register and schema, and the actor.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function recordExport(ExportOutcome $export): AuditTrail {
		$auditTrail = self::actorEntry(action: 'export.' . $export->outcome, actor: $this->exportActor(actorId: $export->actorId));
		$export->target->stampOnto(auditTrail: $auditTrail);
		$auditTrail->setResultSummary($export->summary);

		return $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordExport()

	/**
	 * The actor an export row names: the principal it ran as, or the session user.
	 *
	 * @param string|null $actorId The principal the export ran as, or null for the session user.
	 *
	 * @return array{userId: string, userName: string} The actor.
	 */
	private function exportActor(string|null $actorId): array {
		if ($actorId === null) {
			return $this->sessionActor();
		}

		return ['userId' => $actorId, 'userName' => $actorId];
	}//end exportActor()

	/**
	 * Record one register-level event: something that happened to a register as a whole.
	 *
	 * The row names the register and no object or schema, and carries the details as
	 * its result summary. It goes onto the same hash chain as every other row.
	 *
	 * @param RegisterAuditEvent $event The register, the namespaced action, the details and the actor.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function recordRegisterEvent(RegisterAuditEvent $event): AuditTrail {
		$auditTrail = self::actorEntry(action: $event->action, actor: $this->entryActor(actorId: $event->actor->id, actorName: $event->actor->name));
		$auditTrail->setRegister($event->register);
		// `changed` is NOT NULL and QBMapper writes only the fields a setter touched.
		$auditTrail->setChanged([]);
		$auditTrail->setResultSummary($event->details);

		return $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordRegisterEvent()

	/**
	 * Record one slug backfill run.
	 *
	 * @param SlugBackfill $backfill The slugs the run wrote, its counters, and its register and schema.
	 *
	 * @return AuditTrail The persisted, hash-chained entry.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function recordSlugBackfill(SlugBackfill $backfill): AuditTrail {
		$auditTrail = self::actorEntry(action: 'slug.backfill.' . self::SLUG_BACKFILL_MODE, actor: $this->sessionActor());
		// `changed` is NOT NULL and QBMapper writes only the fields a setter touched.
		$auditTrail->setChanged([]);
		$backfill->target->stampOnto(auditTrail: $auditTrail);
		$auditTrail->setResultSummary($this->slugBackfillSummary(backfill: $backfill, mode: self::SLUG_BACKFILL_MODE));

		return $this->insertHashChained(auditTrail: $auditTrail);
	}//end recordSlugBackfill()

	/**
	 * The result summary of a slug backfill entry, with the change list bounded.
	 *
	 * @param SlugBackfill $backfill The slugs the run wrote and its counters.
	 * @param string       $mode     The run mode, `fill`.
	 *
	 * @return array<string, mixed> The result summary of the entry.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
	 */
	public function slugBackfillSummary(SlugBackfill $backfill, string $mode): array {
		$changes = $backfill->changes;
		$summary = $backfill->summary;
		$total = count($changes);
		$listed = [];
		foreach (array_slice($changes, 0, self::SLUG_BACKFILL_MAX_CHANGES) as $change) {
			$listed[] = ['uuid' => ($change['uuid'] ?? null), 'old' => ($change['old'] ?? null), 'new' => ($change['new'] ?? null)];
		}

		$truncated = ($total > self::SLUG_BACKFILL_MAX_CHANGES);
		$result = ['mode' => $mode, 'summary' => $summary, 'changes' => $listed, 'changesTotal' => $total, 'truncated' => $truncated];
		if ($truncated === true) {
			$result['note'] = sprintf(
				'Only the first %d of %d changes are listed here; the full list is in the Nextcloud log (lines starting "[ObjectSlug] Slug backfill changes").',
				self::SLUG_BACKFILL_MAX_CHANGES,
				$total
			);
		}

		return $result;
	}//end slugBackfillSummary()

	/**
	 * The objects one user read most recently, newest first.
	 *
	 * One row per object: the latest `read` entry this user has on the audit
	 * trail, capped at `$limit`. The (user, action, object_uuid, created)
	 * index narrows the rows; the cause is read from each candidate row.
	 *
	 * Only a read the person made counts: cause `person`, or empty for a row
	 * written before causes were recorded. A `lookup` (a guard, a relation
	 * lookup, an agent tool) and a read inside an import, rule, migration,
	 * scheduled job or cascade stay on the audit trail and stay out of the
	 * history (`recently-opened-means-opened`).
	 *
	 * A tombstoned row (retention purge) carries an empty `user`, so it never
	 * matches a uid and drops out of every history by itself.
	 *
	 * @param string $userId The reader's uid.
	 * @param int    $limit  How many objects to answer at most.
	 *
	 * @return array<string, string> Object uuid => ISO 8601 moment of the latest read, newest first.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-recently-opened-is-read-from-the-audit-trail
	 * @spec openspec/specs/object-interactions/spec.md#requirement-only-a-person-opening-an-object-counts-as-recently-opened
	 */
	public function findLatestReadsByUser(string $userId, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid', $qb->createFunction('MAX(created) AS last_read'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter('read')))
			->andWhere($qb->expr()->isNotNull('object_uuid'))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('cause'),
					$qb->expr()->eq('cause', $qb->createNamedParameter(WriteCause::PERSON))
				)
			)
			->groupBy('object_uuid')
			->orderBy('last_read', 'DESC')
			->setMaxResults(max(1, $limit));

		$result = $qb->executeQuery();
		$reads  = [];
		while (($row = $result->fetch()) !== false) {
			$uuid = (string)($row['object_uuid'] ?? '');
			$moment = $this->toIsoMoment(value: ($row['last_read'] ?? null));
			if ($uuid !== '' && $moment !== null) {
				$reads[$uuid] = $moment;
			}
		}

		$result->closeCursor();

		return $reads;
	}//end findLatestReadsByUser()

	/**
	 * The aggregate reads over this table.
	 *
	 * @return AuditTrailStatistics
	 */
	private function statistics(): AuditTrailStatistics {
		return new AuditTrailStatistics(db: $this->db, tableName: $this->getTableName());
	}//end statistics()

	/**
	 * The reads that count audit actions.
	 *
	 * @return AuditActionStatistics
	 */
	private function actionStatistics(): AuditActionStatistics {
		return new AuditActionStatistics(db: $this->db, tableName: $this->getTableName(), logger: $this->logger);
	}//end actionStatistics()

	/**
	 * Render a stored `created` value as ISO 8601.
	 *
	 * Stored values are UTC wall-clock strings (`Y-m-d H:i:s`), so they are
	 * read in UTC and rendered with their offset.
	 *
	 * @param mixed $value The raw column value.
	 *
	 * @return string|null The ISO 8601 moment, or null when unreadable.
	 */
	private function toIsoMoment(mixed $value): string|null {
		if ($value instanceof DateTimeInterface) {
			return $value->format('c');
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return (new DateTime($value, new DateTimeZone('UTC')))->format('c');
		} catch (Exception $e) {
			return null;
		}
	}//end toIsoMoment()

}//end class
