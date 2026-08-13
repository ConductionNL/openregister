<?php

/**
 * Create, update, upsert or delete an OpenRegister object, one per item.
 *
 * The step that makes a flow able to remember something. Before it, the engine
 * could compute anything and persist nothing: every other built-in reshapes,
 * routes, waits or branches, and object writes lived only in the schema-attached
 * `x-openregister-*` engine, which is bound to "this object changed" and cannot
 * be placed in a graph.
 *
 * It is engine infrastructure, not business logic (ADR-031). No business rule
 * lives in this class — the rule lives in the authored flow document, which is
 * declarative data like an `x-openregister-lifecycle` block. What lives here is
 * the mechanics of turning a configured mapping into an ATTRIBUTED write.
 *
 * THE THREE THINGS THIS NODE REFUSES TO DO
 * ----------------------------------------
 *  1. **Write without an owner.** Every write runs as `context.triggeredBy`,
 *     passed explicitly. No system user, no `runAsSystem()`, no owner named in
 *     the step's own configuration. A flow is authored data; if a flow could
 *     write past RBAC then authoring a flow would be a privilege escalation.
 *     A run with no resolvable owner writes nothing and says why — which is a
 *     live state today, since MCP-triggered runs carry a null `triggeredBy`
 *     (ConductionNL/openregister#2158).
 *  2. **Null a field the author did not mention.** `saveObject()` is
 *     PUT-semantic, and a field mapping is by nature partial. `update` and the
 *     update half of `upsert` therefore go through `ObjectService::patchObject()`.
 *     Full replacement is available, but only behind an explicit `replace: true`.
 *  3. **Swallow a failure into a hollow success.** Every failure throws, so the
 *     engine reads the step's `onError` policy and the run log records the
 *     cause. The anti-pattern is concrete and already in the fleet —
 *     `hermiq/lib/Flow/HermiqAgentNode.php` catches `Throwable` and carries on
 *     with an empty answer, producing a run that reports success having written
 *     nothing. That is the most expensive failure mode available: the flow looks
 *     green and the data is not there.
 *
 * DELETION is offered, behind five independent guards, so that no single mistake
 * reaches a removal: an explicit `match` is mandatory (there is no shape that
 * means "delete everything in scope"), the match must resolve to exactly one
 * object, `confirmDelete: true` must be present and boolean, `permanent` — if
 * named at all — must be boolean and may only qualify a delete, and the removal
 * goes through the ordinary `deleteObject()` path so RBAC, the audit trail,
 * append-only and archival immutability all still apply. The first, third and
 * fourth are enforced when the flow is SAVED, because a flow that could delete
 * unboundedly must not be persistable at all.
 *
 * The delete is SOFT unless `permanent: true` says otherwise, and that default
 * does not move: a tombstone is what makes a mistaken flow recoverable. The
 * opt-out exists for a row that is a CLAIM rather than a record — a lock, a slot,
 * a lease — whose identifier is deliberately a pure function of the thing being
 * claimed, because that is what makes two concurrent claimants collide on it.
 * Such an identifier cannot be varied per attempt without giving up the mutual
 * exclusion it exists for, and the `_uuid` unique index carries no
 * `WHERE _deleted IS NULL` predicate, so a soft-deleted claim goes on holding its
 * identifier forever: the claim can be taken exactly once, and every attempt
 * after that is refused with `already exists` about an object every read reports
 * as absent (openregister#2459).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Writes one OpenRegister object per flow item, as the run's owner.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   A write step needs the object service, both configuration mappers and the user manager.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Most of the length is reasoning and per-rule docblocks; the executable body is small and flat.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One branch per named configuration key and one per operation; collapsing it hides the guards.
 * @SuppressWarnings(PHPMD.TooManyMethods)           One small, named method per guard and per operation; merging them would bury the delete guards.
 */
class ObjectWriteNode implements IFlowNode, IFlowNodeConfigKeys {

	/**
	 * Insert a new object; never look for an existing one.
	 *
	 * @var string
	 */
	public const OP_CREATE = 'create';

	/**
	 * Patch an existing object resolved through the configured match.
	 *
	 * @var string
	 */
	public const OP_UPDATE = 'update';

	/**
	 * Patch the matched object, or insert when nothing matches.
	 *
	 * @var string
	 */
	public const OP_UPSERT = 'upsert';

	/**
	 * Remove the matched object, behind the four delete guards.
	 *
	 * @var string
	 */
	public const OP_DELETE = 'delete';

	/**
	 * The complete operation vocabulary. Exactly four values, deliberately.
	 *
	 * @var string[]
	 */
	private const OPERATIONS = [
		self::OP_CREATE,
		self::OP_UPDATE,
		self::OP_UPSERT,
		self::OP_DELETE,
	];

	/**
	 * Drop a mapped key whose template resolves to nothing (the default).
	 *
	 * @var string
	 */
	private const ON_MISSING_OMIT = 'omit';

	/**
	 * Fail the item when a mapped key's template resolves to nothing.
	 *
	 * @var string
	 */
	private const ON_MISSING_FAIL = 'fail';

	/**
	 * Accepted `onMissing` values.
	 *
	 * @var string[]
	 */
	private const ON_MISSING = [self::ON_MISSING_OMIT, self::ON_MISSING_FAIL];

	/**
	 * A delete that matched nothing fails the item (the default).
	 *
	 * @var string
	 */
	private const ON_NO_MATCH_ERROR = 'error';

	/**
	 * A delete that matched nothing is a no-op emitting `deleted: false`.
	 *
	 * @var string
	 */
	private const ON_NO_MATCH_SKIP = 'skip';

	/**
	 * Accepted `onNoMatch` values.
	 *
	 * @var string[]
	 */
	private const ON_NO_MATCH = [self::ON_NO_MATCH_ERROR, self::ON_NO_MATCH_SKIP];

	/**
	 * A create whose identifier is already taken overwrites it (the default).
	 *
	 * This is `saveObject()`'s long-standing upsert behaviour and stays the
	 * default: it is silent, so there is no way to enumerate which existing
	 * flows depend on it.
	 *
	 * @var string
	 */
	private const ON_CONFLICT_OVERWRITE = 'overwrite';

	/**
	 * A create whose identifier is already taken FAILS the item.
	 *
	 * Opt in with `onConflict: fail` when the write is a CLAIM — a lock, a
	 * slot, a lease, a queue position. There, "it already existed" is the whole
	 * answer, and overwriting it means two flow runs both believe they won
	 * while the loser is never told. See openregister#2210.
	 *
	 * ✅ SAFE FOR MUTUAL EXCLUSION since openregister#2215. This warning used to
	 * read "NOT YET SAFE — the underlying guard is a check followed by a write,
	 * so concurrent flow runs can all pass the check and several succeed". That
	 * was true of #2212 and is no longer true: the database arbitrates through
	 * the `_uuid` unique constraint, and the losing writer is told.
	 *
	 * Re-measured through THIS node on 2026-07-31, not inherited from the fix's
	 * own PR: twelve simultaneous flow runs claiming one identifier produced
	 * `1 completed / 11 stopped`, each loser carrying `An object with identifier
	 * "…" already exists`, and exactly ONE row in the table.
	 *
	 * The stale warning mattered more than a stale comment usually does: it sat
	 * on the one primitive hydra's per-issue lock needs, and it said the thing
	 * that would stop somebody building it.
	 *
	 * @var string
	 */
	private const ON_CONFLICT_FAIL = 'fail';

	/**
	 * Accepted `onConflict` values.
	 *
	 * @var string[]
	 */
	private const ON_CONFLICT = [self::ON_CONFLICT_OVERWRITE, self::ON_CONFLICT_FAIL];

	/**
	 * Writes one step execution may perform when it declares no `maxWrites`.
	 *
	 * Matches `FlowEngine::MAX_TRANSITIONS`'s order of magnitude so the two
	 * ceilings read as a pair. It is load-bearing rather than decorative:
	 * `openregister.synchronization-run` emits one item per synchronised object,
	 * so `sync -> object-write` is a pipeline whose item count comes from an
	 * external source. Without a cap that pair is a write amplifier bounded only
	 * by the size of someone else's API.
	 *
	 * @var integer
	 */
	private const DEFAULT_MAX_WRITES = 1000;

	/**
	 * App id the instance-wide cap default is read from.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * App-config key holding the instance-wide cap default.
	 *
	 * @var string
	 */
	private const MAX_WRITES_KEY = 'flowObjectWriteMaxWrites';

	/**
	 * How many candidates a match lookup reads before it stops counting.
	 *
	 * A match is required to resolve to exactly one object, so anything past a
	 * handful is already a failure; this only bounds how much a badly-authored
	 * match can pull into memory before the node says so.
	 *
	 * @var integer
	 */
	private const MATCH_SCAN_LIMIT = 100;

	/**
	 * Prefix that addresses a match pair to object metadata rather than a property.
	 *
	 * @var string
	 */
	private const SELF_PREFIX = '@self.';

	/**
	 * Metadata fields a match pair may name.
	 *
	 * Deliberately narrower than the full metadata column set: these are the
	 * fields that identify or scope a row and are stable enough to match on.
	 * `uuid` is the one that matters most — it is what `ObjectReadNode` puts on
	 * every item it emits so a follow-up write can name the row.
	 *
	 * @var string[]
	 */
	private const MATCHABLE_METADATA_FIELDS = [
		'uuid',
		'slug',
		'uri',
		'version',
		'owner',
		'organisation',
		'application',
		'name',
		'created',
		'updated',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects Performs every write, with all its ordinary semantics.
	 * @param RegisterMapper $registers Resolves the configured register (id, uuid or slug).
	 * @param SchemaMapper $schemas Resolves the configured schema (id, uuid or slug).
	 * @param IUserManager $userManager Resolves the run owner to an account.
	 * @param IAppConfig $appConfig Holds the instance-wide write-cap default.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly IUserManager $userManager,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function getId(): string {
		return 'openregister.object-write';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Write an object');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Create, update, upsert or delete an OpenRegister object for every item — as the person who started the run, with their permissions.'
		);

	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/edit.svg');
	}//end getIcon()

	/**
	 * Offered in both scopes.
	 *
	 * The node grants no privilege of its own — every write it performs is
	 * bounded by the run owner's own permissions — so restricting it by scope
	 * would restrict AUTHORING, not access.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of an object-write step, across all four operations.
	 *
	 * The union, not a per-operation set. Which keys are FORBIDDEN for which
	 * operation is `validateOperationKeys()`'s question and it answers it far
	 * more usefully than a bare "unknown key" ever could — `"fields" has no
	 * meaning for a delete step` names the mistake, not just the symptom.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return [
			'register',
			'schema',
			'operation',
			'fields',
			'match',
			'replace',
			'output',
			'confirmDelete',
			'permanent',
			'maxWrites',
			'onConflict',
			'onMissing',
			'onNoMatch',
		];

	}//end configKeys()

	/**
	 * Refuse a configuration the author cannot have meant.
	 *
	 * Two of the four delete guards live here rather than in `execute()`: a flow
	 * that could delete without a match, or without an explicit acknowledgement,
	 * must not be PERSISTABLE. Catching that when the schedule fires at 3am is
	 * too late to be called a guard.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration is unusable.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per named key, by design.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function validateConfig(array $config): void {
		if ($this->identifierFrom(value: ($config['register'] ?? null)) === '') {
			throw new UnexpectedValueException($this->l10n->t('An object-write step needs a register.'));
		}

		if ($this->identifierFrom(value: ($config['schema'] ?? null)) === '') {
			throw new UnexpectedValueException($this->l10n->t('An object-write step needs a schema.'));
		}

		$operation = $this->operationFrom(config: $config);
		if ($operation === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('An object-write step needs an operation: create, update, upsert or delete.')
			);
		}

		if (in_array($operation, self::OPERATIONS, true) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('Unknown operation "%s"; choose create, update, upsert or delete.', [$operation])
			);
		}

		// Guard 1 of the delete guards, and the same rule for update / upsert:
		// without a match there is nothing to act on but everything in scope.
		$pairs = $this->matchPairs(config: $config);
		if (in_array($operation, [self::OP_UPDATE, self::OP_UPSERT, self::OP_DELETE], true) === true
			&& $pairs === []
		) {
			throw new UnexpectedValueException(
				$this->l10n->t('An object-write step with operation "%s" needs at least one match property and value.', [$operation])
			);
		}

		$this->validateOperationKeys(config: $config, operation: $operation);
		$this->validateOptionKeys(config: $config);

	}//end validateConfig()

	/**
	 * Write one object per item, as the run's owner.
	 *
	 * Nothing here catches a write failure. That is the point: the engine reads
	 * the step's `onError` policy (`stop`, `continue`, `dead_letter`) from the
	 * exception, and a run that reports success having written nothing is a
	 * worse outcome than a run that fails loudly.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata; carries `triggeredBy`.
	 *
	 * @return array One output item per input item.
	 *
	 * @throws UnexpectedValueException When the configuration or the register / schema is unusable.
	 * @throws RuntimeException When the run has no owner, a match is ambiguous or absent,
	 *                          or the write cap is exceeded.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per operation.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function execute(array $items, array $config, array $context): array {
		// Re-run the save-time validation. A flow document can be written into
		// the store by something other than the editor, and the delete guards
		// must not depend on which door the document came through.
		$this->validateConfig(config: $config);

		if ($items === []) {
			return [];
		}

		$operation = $this->operationFrom(config: $config);
		$owner = $this->resolveOwner(context: $context);
		$register = $this->resolveRegister(config: $config);
		$schema = $this->resolveSchema(config: $config, register: $register);

		// `onNoMatch` is deliberately absent here: it is read inside
		// executeDelete(), the only path that acts on it, rather than resolved
		// here and passed down as an eleventh parameter.
		$pairs = $this->matchPairs(config: $config);
		$fields = (array)($config['fields'] ?? []);
		$onMissing = $this->optionFrom(config: $config, key: 'onMissing', allowed: self::ON_MISSING, default: self::ON_MISSING_OMIT);
		$onConflict = $this->optionFrom(config: $config, key: 'onConflict', allowed: self::ON_CONFLICT, default: self::ON_CONFLICT_OVERWRITE);
		$replace = (($config['replace'] ?? false) === true);
		$cap = $this->writeCap(config: $config);

		// THE WRITES RUN AS THE OWNER, rather than merely naming one.
		//
		// `$owner` was resolved, passed down every path as `currentUser:` and
		// documented as the subject the write is attributed to — and it is,
		// in the audit trail. But it is NOT the subject the ACCESS CHECK uses:
		// MagicRbacHandler and MagicOrganizationHandler read
		// `IUserSession::getUser()` directly, so the permission gate answers
		// for whoever the ambient session carries. Under a cron worker
		// (`FlowRunWorker`) that is nobody, and a scheduled write is refused
		// as `Anonymous` no matter who owns the run — measured on the hydra
		// sequencer's `lock-issue`, which failed with "User 'Anonymous' does
		// not have permission to 'create' objects in schema 'Agent flow'"
		// while `triggeredBy` was `admin` all along.
		//
		// openregister#2272 fixed exactly this for `ObjectReadNode`, which had
		// the mirror-image version of the bug (a sessionless read SKIPS the
		// RBAC predicate and reads too much, where a sessionless write is
		// DENIED and writes nothing). The write side was left behind. Same
		// seam, same reason: the query layer has no acting-user parameter, so
		// `runAs()` sets the subject for the duration and restores it in a
		// `finally`.
		//
		// The whole loop is wrapped rather than each call, because `findMatch()`
		// is a READ that decides what a write or delete then touches. Running
		// the match under one subject and the write under another is how a
		// delete finds a row it is not allowed to remove.
		//
		// This narrows; it never grants. A run whose owner cannot write is
		// still refused, and now says so for the right reason.
		return $this->objects->runAs(
			$owner,
			fn (): array => $this->writeItems(
				items: $items,
				config: $config,
				operation: $operation,
				owner: $owner,
				register: $register,
				schema: $schema,
				pairs: $pairs,
				fields: $fields,
				onMissing: $onMissing,
				onConflict: $onConflict,
				replace: $replace,
				cap: $cap
			)
		);

	}//end execute()

	/**
	 * The per-item write loop, executed as the run owner.
	 *
	 * Split out of {@see execute()} only so the whole loop can be handed to
	 * `ObjectService::runAs()` as one callable — the match and the write it
	 * feeds must share a subject.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param string $operation The resolved operation.
	 * @param IUser $owner The run owner, as the acting user.
	 * @param Register $register The resolved register.
	 * @param Schema $schema The resolved schema.
	 * @param array $pairs The match pairs.
	 * @param array $fields The configured fields.
	 * @param string $onMissing The missing-field policy.
	 * @param string $onConflict The conflict policy.
	 * @param bool $replace Whether the write replaces.
	 * @param int|null $cap The write cap.
	 *
	 * @return array One output item per input item.
	 *
	 * @throws RuntimeException When a match is ambiguous or absent, or the cap is exceeded.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)   One branch per operation.
	 * @SuppressWarnings(PHPMD.NPathComplexity)        Same.
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The parameters execute() already resolved.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	private function writeItems(
		array $items,
		array $config,
		string $operation,
		IUser $owner,
		Register $register,
		Schema $schema,
		array $pairs,
		array $fields,
		string $onMissing,
		string $onConflict,
		bool $replace,
		?int $cap,
	): array {
		$writes = 0;
		$out = [];

		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			$binary = (array)($item[FlowItems::BINARY] ?? []);

			if ($operation === self::OP_DELETE) {
				$out[] = $this->executeDelete(
					item: [FlowItems::JSON => $json, FlowItems::BINARY => $binary],
					index: (int)$index,
					pairs: $pairs,
					register: $register,
					schema: $schema,
					owner: $owner,
					cap: $cap,
					config: $config,
					writes: $writes
				);
				continue;
			}

			$payload = $this->buildPayload(fields: $fields, json: $json, onMissing: $onMissing);
			$matched = null;
			if ($operation !== self::OP_CREATE) {
				$matched = $this->findMatch(pairs: $pairs, json: $json, register: $register, schema: $schema, owner: $owner);
			}

			if ($operation === self::OP_UPDATE && $matched === null) {
				throw new RuntimeException(
					$this->l10n->t('An update matched no object; an update never silently inserts one.')
				);
			}

			$this->guardCap(writes: $writes, cap: $cap);
			$saved = $this->persist(
				payload: $payload,
				matched: $matched,
				register: $register,
				schema: $schema,
				owner: $owner,
				replace: $replace,
				onConflict: $onConflict
			);
			$writes++;

			$out[] = FlowItems::item(
				json: $this->outputJson(
					incoming: (array)($item[FlowItems::JSON] ?? []),
					written: $this->writtenJson(saved: $saved, register: $register, schema: $schema),
					config: $config
				),
				binary: $binary,
				fromItemIndex: (int)$index
			);
		}//end foreach

		return $out;
	}//end writeItems()

	/**
	 * The json a written item carries onward.
	 *
	 * With `output` set, the incoming record is PRESERVED and the written object
	 * is added under that key — the convention every other node already follows
	 * (`hermiq.agent-step`, `openconnector.source-call`,
	 * `openregister.flow-state` all do this). Without it, the written object
	 * REPLACES the record, which is the historical behaviour and stays the
	 * default because changing it silently would rewrite what existing flows
	 * see.
	 *
	 * Replacing is fine for a write that ends a branch and wrong for one in the
	 * middle of a chain, because it discards everything the run was carrying. A
	 * per-issue lock is exactly the second shape: hydra's sequencer claims a
	 * lock and then still needs the repo, the issue and its slot number to do
	 * the work the lock protects. Measured while building it — after the lock
	 * write, `{{repo}}` rendered empty and the next call went to `/repos//issues`.
	 *
	 * @param array $incoming The item's json as it arrived.
	 * @param array $written The written object's json.
	 * @param array $config The step configuration.
	 *
	 * @return array The outgoing json.
	 */
	private function outputJson(array $incoming, array $written, array $config): array {
		$key = trim((string)($config['output'] ?? ''));
		if ($key === '') {
			return $written;
		}

		$incoming[$key] = $written;

		return $incoming;
	}//end outputJson()

	/**
	 * Perform one guarded delete and build its output item.
	 *
	 * @param array $item The input item (`json` and `binary`).
	 * @param int $index The input item's index, for provenance.
	 * @param array $pairs The composite match pairs.
	 * @param Register $register The resolved register.
	 * @param Schema $schema The resolved schema.
	 * @param IUser $owner The run owner, as the acting user.
	 * @param int $cap The step's write cap.
	 * @param array $config The step configuration; `onNoMatch` and `output` are read from it.
	 * @param int $writes Writes performed so far; incremented by reference.
	 *
	 * @return array The output item.
	 *
	 * @throws RuntimeException When the match is absent under `error`, or ambiguous, or the cap is hit.
	 */
	private function executeDelete(
		array $item,
		int $index,
		array $pairs,
		Register $register,
		Schema $schema,
		IUser $owner,
		int $cap,
		array $config,
		int &$writes,
	): array {
		// Read from the config rather than taken as an eleventh parameter. The
		// caller resolves it exactly this way; passing both it and the config
		// would be two spellings of one fact, and the parameter list is already
		// at its limit.
		$onNoMatch = $this->optionFrom(
			config: $config,
			key: 'onNoMatch',
			allowed: self::ON_NO_MATCH,
			default: self::ON_NO_MATCH_ERROR
		);

		$json = (array)($item[FlowItems::JSON] ?? []);
		$binary = (array)($item[FlowItems::BINARY] ?? []);

		$matched = $this->findMatch(pairs: $pairs, json: $json, register: $register, schema: $schema, owner: $owner);

		if ($matched === null) {
			if ($onNoMatch !== self::ON_NO_MATCH_SKIP) {
				throw new RuntimeException(
					$this->l10n->t('A delete matched no object; set "onNoMatch" to "skip" if that is intended.')
				);
			}

			// A skip carries the input record through with `deleted: false`, so
			// it is never indistinguishable from a removal in the run log.
			$json['deleted'] = false;

			// ...and it gets the `output` key too, when one is set. A step
			// whose output key exists only on the branch that deleted
			// something makes `{{removed.deleted}}` resolve on some items and
			// not others, which is a downstream null nobody can explain.
			//
			// NOT via outputJson() here: on this branch the record IS the
			// output, so an empty key must leave `$json` alone rather than
			// replace it with the two-field written record.
			$outputKey = trim((string)($config['output'] ?? ''));
			if ($outputKey !== '') {
				$json[$outputKey] = ['deleted' => false];
			}

			return FlowItems::item(json: $json, binary: $binary, fromItemIndex: $index);
		}//end if

		$uuid = (string)$matched->getUuid();

		$this->guardCap(writes: $writes, cap: $cap);

		// Guard 4: the ordinary delete path, with the run owner as the explicit
		// acting user and the register / schema scope confining the lookup to
		// one magic table. No `_retentionSweep`, and a SOFT delete unless the
		// author asked for otherwise — a soft delete is what makes a mistaken
		// flow recoverable, so it stays the default.
		//
		// `permanent: true` is for a row that is a CLAIM rather than a record —
		// a lock, a slot, a lease. Such a row's identifier is deliberately a
		// pure function of the thing being claimed, which is what makes two
		// concurrent claimants collide on it; it therefore cannot be varied per
		// attempt. A soft delete leaves the tombstone holding that identifier
		// (the `_uuid` unique index has no `WHERE _deleted IS NULL` predicate),
		// so a claim that cannot destroy its own row can be taken exactly once,
		// ever — and the second attempt is refused with `already exists` about
		// an object every read reports as absent. See openregister#2459.
		$this->objects->deleteObject(
			uuid: $uuid,
			register: $register,
			schema: $schema,
			currentUser: $owner,
			permanent: (($config['permanent'] ?? false) === true)
		);
		$writes++;

		// Through `outputJson()`, exactly like every non-delete write. Without
		// it a delete was the one operation that could not carry its incoming
		// record forward: `config.output` never reached this method, so the
		// key the author set did nothing and everything the item was carrying
		// — the issue number, the repo, the run id — was dropped at the delete.
		//
		// With no `output` set the returned record is unchanged, so this is not
		// a behaviour change for any flow that does not ask for one.
		return FlowItems::item(
			json: $this->outputJson(
				incoming: $json,
				written: [
					'uuid' => $uuid,
					'register' => $this->identifierOf(register: $register),
					'schema' => $this->labelOf(schema: $schema),
					'deleted' => true,
				],
				config: $config
			),
			binary: $binary,
			fromItemIndex: $index
		);

	}//end executeDelete()

	/**
	 * Route one non-delete write to the service method its operation calls for.
	 *
	 * `create`, and the insert half of `upsert`, go through `saveObject()`. Every
	 * update-side write goes through `patchObject()` unless the author asked for
	 * `replace: true`, which is the only way to reach PUT semantics from a flow.
	 *
	 * @param array $payload The templated field payload.
	 * @param ObjectEntity|null $matched The matched object, when there is one.
	 * @param Register $register The resolved register.
	 * @param Schema $schema The resolved schema.
	 * @param IUser $owner The run owner, as the acting user.
	 * @param bool $replace Whether to replace rather than patch.
	 * @param string $onConflict What to do when a create's identifier is already taken.
	 *
	 * @return ObjectEntity The written object.
	 */
	private function persist(
		array $payload,
		?ObjectEntity $matched,
		Register $register,
		Schema $schema,
		IUser $owner,
		bool $replace,
		string $onConflict = self::ON_CONFLICT_OVERWRITE,
	): ObjectEntity {
		if ($matched === null) {
			// `matched === null` means the node's own lookup found nothing —
			// but that lookup and this write are two separate calls, so another
			// flow run can land in between. Passing failIfExists through is what
			// closes that window: the database, not the node, arbitrates.
			return $this->objects->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				currentUser: $owner,
				failIfExists: ($onConflict === self::ON_CONFLICT_FAIL)
			);
		}

		$uuid = (string)$matched->getUuid();

		if ($replace === true) {
			return $this->objects->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				uuid: $uuid,
				currentUser: $owner
			);
		}

		return $this->objects->patchObject(
			objectId: $uuid,
			data: $payload,
			register: $register,
			schema: $schema,
			currentUser: $owner
		);

	}//end persist()

	/**
	 * Build the output record for a performed write.
	 *
	 * Carries the saved data plus the identifiers a downstream step needs to act
	 * on what was just written without re-fetching it.
	 *
	 * @param ObjectEntity $saved The written object.
	 * @param Register $register The resolved register.
	 * @param Schema $schema The resolved schema.
	 *
	 * @return array The output record.
	 */
	private function writtenJson(ObjectEntity $saved, Register $register, Schema $schema): array {
		$json = (array)$saved->getObject();

		$json['uuid'] = $saved->getUuid();
		$json['register'] = $this->identifierOf(register: $register);
		$json['schema'] = $this->labelOf(schema: $schema);

		return $json;
	}//end writtenJson()

	/**
	 * Refuse to perform one more write than the cap allows.
	 *
	 * Truncating instead — writing the first N and dropping the rest while
	 * reporting success — would leave the register holding a partial dataset
	 * that looks complete. The message names how many writes were performed
	 * because there is no transaction spanning a step, and an operator who
	 * believes the step was atomic will make the wrong recovery decision.
	 *
	 * @param int $writes Writes performed so far.
	 * @param int $cap The step's cap.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the next write would exceed the cap.
	 */
	private function guardCap(int $writes, int $cap): void {
		if ($writes < $cap) {
			return;
		}

		throw new RuntimeException(
			$this->l10n->t(
				'Step exceeded its write cap of %1$s writes; %2$s writes were performed and were not rolled back.',
				[(string)$cap, (string)$writes]
			)
		);

	}//end guardCap()

	/**
	 * The cap this execution must stay within.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return int The cap.
	 */
	private function writeCap(array $config): int {
		$configured = ($config['maxWrites'] ?? null);
		if (is_int($configured) === true && $configured > 0) {
			return $configured;
		}

		$default = $this->appConfig->getValueInt(self::APP_ID, self::MAX_WRITES_KEY, self::DEFAULT_MAX_WRITES);
		if ($default < 1) {
			return self::DEFAULT_MAX_WRITES;
		}

		return $default;
	}//end writeCap()

	/**
	 * Resolve the run's owner, or refuse to write at all.
	 *
	 * There is deliberately no fallback. Writing as a system user would make
	 * every flow a privilege-escalation primitive and produce audit entries that
	 * name nobody; letting the step configuration name an owner is the same
	 * escalation one indirection further away. An unattributable row in a
	 * register is worse than a failed run, and an unattributable DELETE is worse
	 * again, because there is no row left to notice.
	 *
	 * @param array $context The run context.
	 *
	 * @return IUser The run owner.
	 *
	 * @throws RuntimeException When the run carries no resolvable owner.
	 */
	private function resolveOwner(array $context): IUser {
		$uid = ($context['triggeredBy'] ?? null);
		if (is_string($uid) === false || trim($uid) === '') {
			throw new RuntimeException(
				$this->l10n->t('This flow run has no owner (triggeredBy); an object write must be attributable.')
			);
		}

		$user = $this->userManager->get(trim($uid));
		if ($user === null) {
			throw new RuntimeException(
				$this->l10n->t(
					'This flow run\'s owner "%s" (triggeredBy) is not a user account; an object write must be attributable.',
					[trim($uid)]
				)
			);
		}

		return $user;
	}//end resolveOwner()

	/**
	 * Resolve the configured register from a slug, uuid or id.
	 *
	 * Registers and schemas are configuration, not data: they are resolved the
	 * way `ObjectService::setRegister()` resolves them, and the owner's RBAC and
	 * multitenancy are enforced on the OBJECT reads and writes that follow.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return Register The resolved register.
	 *
	 * @throws UnexpectedValueException When it does not resolve.
	 */
	private function resolveRegister(array $config): Register {
		$identifier = $this->identifierFrom(value: ($config['register'] ?? null));
		if ($identifier === '') {
			throw new UnexpectedValueException($this->l10n->t('An object-write step needs a register.'));
		}

		try {
			return $this->registers->find(id: $identifier, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			throw new UnexpectedValueException(
				$this->l10n->t('The register "%s" could not be resolved.', [$identifier]),
				0,
				$e
			);
		}

	}//end resolveRegister()

	/**
	 * Resolve the configured schema from a slug, uuid or id.
	 *
	 * A slug is resolved against the register's OWN schemas first. A bare slug
	 * is only unique within a register: resolving it globally lets a generic
	 * slug (`order`, `task`, `conversation`) land on another app's schema that
	 * happens to share it.
	 *
	 * @param array $config The step configuration.
	 * @param Register $register The already-resolved register.
	 *
	 * @return Schema The resolved schema.
	 *
	 * @throws UnexpectedValueException When it does not resolve.
	 */
	private function resolveSchema(array $config, Register $register): Schema {
		$identifier = $this->identifierFrom(value: ($config['schema'] ?? null));
		if ($identifier === '') {
			throw new UnexpectedValueException($this->l10n->t('An object-write step needs a schema.'));
		}

		if (is_numeric($identifier) === false) {
			$scoped = $this->schemas->findBySlugInIds(
				slug: $identifier,
				schemaIds: (array)($register->getSchemas() ?? [])
			);
			if ($scoped !== null) {
				return $scoped;
			}
		}

		try {
			return $this->schemas->find(id: $identifier, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			throw new UnexpectedValueException(
				$this->l10n->t('The schema "%s" could not be resolved.', [$identifier]),
				0,
				$e
			);
		}

	}//end resolveSchema()

	/**
	 * Resolve the composite match to exactly one object, or to none.
	 *
	 * Every pair must hold — the pairs are ANDed, never ORed. An OR would widen
	 * the resolved set, and every consumer of a match treats a wider set as an
	 * error, so an OR would only ever produce configurations that cannot succeed.
	 *
	 * More than one match fails, naming the count. Picking one would be
	 * non-deterministic across instances, which is the defect class that only
	 * ever appears on someone else's system.
	 *
	 * The lookup runs AS the owner, so the object a match resolves to is one the
	 * owner can actually see. Without that the node checked one subject and
	 * chose another: the writes were attributed (`currentUser: $owner`) while
	 * the scan that decided WHICH row to write ran under the ambient session.
	 * Under CLI cron that session is empty, and both the RBAC and organisation
	 * filters skip themselves for a sessionless CLI context — so the match
	 * could resolve a row outside the owner's visibility, and `upsert` could
	 * find one the owner could not see and therefore insert a duplicate.
	 *
	 * @param array $pairs The match pairs.
	 * @param array $json The current item's record.
	 * @param Register $register The resolved register.
	 * @param Schema $schema The resolved schema.
	 * @param IUser $owner The run owner, whose RBAC applies to the lookup.
	 *
	 * @return ObjectEntity|null The single match, or null when nothing matched.
	 *
	 * @throws RuntimeException When a match value cannot be templated, or the match is ambiguous.
	 */
	private function findMatch(
		array $pairs,
		array $json,
		Register $register,
		Schema $schema,
		IUser $owner,
	): ?ObjectEntity {
		$filters = [
			'register' => $register->getId(),
			'schema' => $schema->getId(),
		];

		$properties = ($schema->getProperties() ?? []);

		foreach ($pairs as $pair) {
			$property = (string)$pair['property'];
			$resolved = $this->resolveTemplate(value: $pair['value'], json: $json);

			// `onMissing` never applies here. Omitting a field narrows what is
			// written; omitting a match pair WIDENS what is matched, and a guard
			// that gets weaker when its input is missing is not a guard.
			if ($resolved['resolved'] === false) {
				throw new RuntimeException(
					$this->l10n->t('The match value for "%s" could not be resolved from the item; a match key is never omitted.', [$property])
				);
			}

			$this->assignMatchFilter(
				filters: $filters,
				property: $property,
				value: $resolved['value'],
				properties: $properties
			);
		}

		$rows = $this->objects->runAs(
			$owner,
			fn (): array => $this->objects->findAll(
				config: [
					'filters' => $filters,
					'limit' => self::MATCH_SCAN_LIMIT,
				]
			)
		);

		$entities = [];
		foreach ($rows as $row) {
			if (($row instanceof ObjectEntity) === true) {
				$entities[] = $row;
			}
		}

		if ($entities === []) {
			return null;
		}

		$count = count($entities);
		if ($count > 1) {
			$label = (string)$count;
			if ($count >= self::MATCH_SCAN_LIMIT) {
				$label = $this->l10n->t('%s or more', [(string)$count]);
			}

			throw new RuntimeException(
				$this->l10n->t('The match resolved to %s objects; it must resolve to exactly one.', [$label])
			);
		}

		return $entities[0];
	}//end findMatch()

	/**
	 * Route one resolved match pair into the property bag or the `@self` bag.
	 *
	 * A schema property and an object's metadata live in different places: the
	 * property in the row's JSON document, the metadata in the magic table's
	 * underscore-prefixed columns. A filter therefore has to be addressed to the
	 * right one. Every match pair used to go into the property bag
	 * unconditionally, so `uuid` — which is metadata and never a document field —
	 * was looked up as a property that does not exist and hit the
	 * `WHERE 1 = 0` branch. Zero rows, no error: an update or delete matched
	 * nothing while the run reported success, and an upsert inserted a duplicate.
	 *
	 * That `uuid` in particular is what `ObjectReadNode` puts on every item it
	 * emits, expressly so a follow-up write or delete can name the row — so the
	 * most natural way to chain a read into a write was the one way that could
	 * not work.
	 *
	 * Routing rules, in order:
	 *   1. An explicit `@self.<field>` prefix always addresses metadata.
	 *   2. A name the schema declares as a property addresses the property. This
	 *      keeps precedence with the schema, so a schema that genuinely has a
	 *      `name` or `owner` property behaves exactly as before.
	 *   3. A name that is a known metadata field addresses metadata.
	 *   4. Anything else is refused, by name. It could only ever have produced
	 *      `1 = 0`, and a guard that silently matches nothing is not a guard.
	 *
	 * @param array $filters The filter bag being built (by reference).
	 * @param string $property The configured match property name.
	 * @param mixed $value The resolved match value.
	 * @param array<string,mixed> $properties The schema's declared properties.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the property is neither a schema property nor metadata.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	private function assignMatchFilter(array &$filters, string $property, mixed $value, array $properties): void {
		// 1. Explicit metadata addressing.
		if (str_starts_with($property, self::SELF_PREFIX) === true) {
			$field = substr($property, strlen(self::SELF_PREFIX));
			if (in_array($field, self::MATCHABLE_METADATA_FIELDS, true) === false) {
				throw new RuntimeException(
					$this->l10n->t(
						'The match names metadata field "%1$s", which does not exist. Matchable metadata fields are: %2$s.',
						[$field, implode(', ', self::MATCHABLE_METADATA_FIELDS)]
					)
				);
			}

			$filters['@self'][$field] = $value;
			return;
		}

		// 2. The schema wins for any name it declares.
		if (array_key_exists($property, $properties) === true) {
			$filters[$property] = $value;
			return;
		}

		// 3. A metadata field the schema does not shadow.
		if (in_array($property, self::MATCHABLE_METADATA_FIELDS, true) === true) {
			$filters['@self'][$property] = $value;
			return;
		}

		// 4. Neither a declared property nor metadata.
		//
		// Refuse only when the schema actually told us what it has. An empty
		// property list cannot distinguish "this schema declares nothing" from
		// "the declaration was not available here", and a guard that fires on
		// missing information would break working flows rather than protect
		// them. With no declaration to check against, keep the historical
		// behaviour and let the query layer answer.
		if ($properties !== []) {
			throw new RuntimeException(
				$this->l10n->t(
					'The match names "%1$s", which is neither a property of this schema nor a metadata field, '
					. 'so it could never match anything. Matchable metadata fields are: %2$s.',
					[$property, implode(', ', self::MATCHABLE_METADATA_FIELDS)]
				)
			);
		}

		$filters[$property] = $value;
	}//end assignMatchFilter()

	/**
	 * Template the configured field mapping against one item.
	 *
	 * @param array $fields The configured mapping.
	 * @param array $json The item's record.
	 * @param string $onMissing What an unresolvable value means.
	 *
	 * @return array The payload to write.
	 *
	 * @throws RuntimeException When a value is unresolvable and `onMissing` is `fail`.
	 */
	private function buildPayload(array $fields, array $json, string $onMissing): array {
		$payload = [];

		foreach ($fields as $key => $value) {
			$key = (string)$key;
			$resolved = $this->resolveTemplate(value: $value, json: $json);

			if ($resolved['resolved'] === false) {
				if ($onMissing === self::ON_MISSING_FAIL) {
					throw new RuntimeException(
						$this->l10n->t('The value for field "%s" could not be resolved from the item.', [$key])
					);
				}

				// Omission — not `""`, not `null`, not `{}`. OpenRegister's
				// validator rejects both `{}` and `null` for an object property
				// nested inside an array item, and an empty string is worse
				// still: it passes validation and writes a wrong value.
				continue;
			}

			$payload[$key] = $resolved['value'];
		}//end foreach

		return $payload;
	}//end buildPayload()

	/**
	 * Resolve one configured value against the item.
	 *
	 * A non-string is passed through unchanged, so literals and nested
	 * structures — including a deliberate `null`, which is how an author clears
	 * a property — can be authored directly. A value that is exactly one
	 * placeholder keeps the resolved value's TYPE: an array stays an array, a
	 * number stays a number. Anything else is substituted inline and stringified.
	 *
	 * A placeholder resolves to nothing when its path is absent, or holds null,
	 * or holds an empty array. That is reported rather than substituted.
	 *
	 * @param mixed $value The configured value.
	 * @param array $json The item's record.
	 *
	 * @return array{resolved: bool, value: mixed} The verdict and the value.
	 */
	private function resolveTemplate(mixed $value, array $json): array {
		if (is_string($value) === false) {
			return ['resolved' => true, 'value' => $value];
		}

		$whole = [];
		if (preg_match('/^\{\{\s*([^{}]+?)\s*\}\}$/', $value, $whole) === 1) {
			$found = $this->lookupPath(path: $whole[1], json: $json);
			if ($found['found'] === false) {
				return ['resolved' => false, 'value' => null];
			}

			return ['resolved' => true, 'value' => $found['value']];
		}

		$missing = false;
		$out = preg_replace_callback(
			'/\{\{\s*([^{}]+?)\s*\}\}/',
			function (array $matches) use ($json, &$missing): string {
				$found = $this->lookupPath(path: $matches[1], json: $json);
				if ($found['found'] === false) {
					$missing = true;
					return '';
				}

				return $this->stringify(value: $found['value']);
			},
			$value
		);

		if ($missing === true) {
			return ['resolved' => false, 'value' => null];
		}

		return ['resolved' => true, 'value' => $out];
	}//end resolveTemplate()

	/**
	 * Walk a dotted path through the item's record.
	 *
	 * A path that lands on null or an empty array counts as not found: those are
	 * the two shapes OpenRegister's validator rejects for a nested object
	 * property, so the node reports them rather than writing them.
	 *
	 * @param string $path The dotted path.
	 * @param array $json The item's record.
	 *
	 * @return array{found: bool, value: mixed} The verdict and the value.
	 */
	private function lookupPath(string $path, array $json): array {
		$path = trim($path);
		if ($path === '') {
			return ['found' => false, 'value' => null];
		}

		$cursor = $json;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return ['found' => false, 'value' => null];
			}

			$cursor = $cursor[$segment];
		}

		if ($cursor === null || $cursor === []) {
			return ['found' => false, 'value' => null];
		}

		return ['found' => true, 'value' => $cursor];
	}//end lookupPath()

	/**
	 * Render a resolved value into an inline template.
	 *
	 * @param mixed $value The resolved value.
	 *
	 * @return string Its string form.
	 */
	private function stringify(mixed $value): string {
		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return (string)json_encode($value);
	}//end stringify()

	/**
	 * Read and normalise the configured match pairs.
	 *
	 * Equality on named properties only. No operators, no ranges, no negation,
	 * no raw store filters: the mandatory-match delete guard depends on a human
	 * being able to look at a delete step and see what it can reach, and an
	 * expression language would make that unauditable.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return array<int, array{property: string, value: mixed}> The pairs.
	 *
	 * @throws UnexpectedValueException When a pair is malformed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The branches are one per
	 * REJECTED shape, each with its own message naming what the author wrote and
	 * what to write instead. Collapsing them would trade a specific diagnostic
	 * for a generic one on a config that is authored by hand.
	 */
	private function matchPairs(array $config): array {
		$raw = ($config['match'] ?? []);
		if (is_array($raw) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('"match" must be a list of property and value pairs.')
			);
		}

		// Accept the map shorthand — `{"status": "flagged"}` — alongside the
		// canonical pair list. It is the shape every author reaches for first
		// (it is how `fields` and `filters` are written on neighbouring nodes),
		// and rejecting it bought nothing: the two are unambiguous, because a
		// pair list is a LIST and a map is not.
		//
		// Detected by key shape rather than by inspecting the values: a list has
		// sequential integer keys, so anything else is a map of property =>
		// value. `[]` stays a pair list and means "no match", which is what an
		// empty config should mean.
		if ($raw !== [] && array_is_list($raw) === false) {
			$shorthand = [];
			foreach ($raw as $property => $value) {
				$shorthand[] = ['property' => (string)$property, 'value' => $value];
			}

			$raw = $shorthand;
		}

		$pairs = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t(
						'Every match entry must name a property and a value. '
						. 'Write [{"property": "status", "value": "flagged"}] or {"status": "flagged"}.'
					)
				);
			}

			$property = ($entry['property'] ?? null);
			if (is_string($property) === false || trim($property) === '') {
				throw new UnexpectedValueException(
					$this->l10n->t('Every match entry must name a property.')
				);
			}

			if (array_key_exists('value', $entry) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t('The match on "%s" has no value.', [trim($property)])
				);
			}

			$pairs[] = [
				'property' => trim($property),
				'value' => $entry['value'],
			];
		}//end foreach

		return $pairs;
	}//end matchPairs()

	/**
	 * Reject keys that have no meaning for the configured operation.
	 *
	 * @param array $config The step configuration.
	 * @param string $operation The configured operation.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a key is meaningless or an acknowledgement is missing.
	 */
	private function validateOperationKeys(array $config, string $operation): void {
		if ($operation === self::OP_DELETE) {
			if (array_key_exists('fields', $config) === true) {
				throw new UnexpectedValueException(
					$this->l10n->t('"fields" has no meaning for a delete step.')
				);
			}

			if (array_key_exists('replace', $config) === true) {
				throw new UnexpectedValueException(
					$this->l10n->t('"replace" has no meaning for a delete step.')
				);
			}

			// Guard 3: a second, deliberate key. Changing one enum value from
			// "update" to "delete" is not enough to reach a deletion, and the
			// flow will not save without it. The string "true" does not count.
			if (($config['confirmDelete'] ?? null) !== true) {
				throw new UnexpectedValueException(
					$this->l10n->t('A delete step must carry "confirmDelete": true, as a boolean.')
				);
			}

			// Guard 5, and the only one that is about IRREVERSIBILITY rather
			// than about aim. The four above stop a delete from touching the
			// wrong rows; this one stops a delete from being unrecoverable
			// without the author having said so in as many words. Same rule as
			// `confirmDelete`: a boolean, because the string "false" is truthy
			// and would turn a paste into a destruction.
			if (array_key_exists('permanent', $config) === true && is_bool($config['permanent']) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t('"permanent" must be true or false, as a boolean.')
				);
			}

			return;
		}//end if

		if (array_key_exists('permanent', $config) === true) {
			throw new UnexpectedValueException(
				$this->l10n->t('"permanent" has no meaning for a "%s" step; it only qualifies a delete.', [$operation])
			);
		}

		if ($operation === self::OP_CREATE && array_key_exists('replace', $config) === true) {
			throw new UnexpectedValueException(
				$this->l10n->t('"replace" has no meaning for a create step.')
			);
		}

		if ((array)($config['fields'] ?? []) === []) {
			throw new UnexpectedValueException(
				$this->l10n->t('An object-write step with operation "%s" needs at least one field to write.', [$operation])
			);
		}

	}//end validateOperationKeys()

	/**
	 * Reject an out-of-range option value.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When an option is out of range.
	 */
	private function validateOptionKeys(array $config): void {
		if (array_key_exists('maxWrites', $config) === true) {
			$max = $config['maxWrites'];
			if (is_int($max) === false || $max < 1) {
				throw new UnexpectedValueException(
					$this->l10n->t('"maxWrites" must be a whole number of one or more.')
				);
			}
		}

		foreach (['onMissing' => self::ON_MISSING, 'onNoMatch' => self::ON_NO_MATCH] as $key => $allowed) {
			if (array_key_exists($key, $config) === false) {
				continue;
			}

			if (is_string($config[$key]) === false || in_array($config[$key], $allowed, true) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t('"%1$s" must be one of: %2$s.', [$key, implode(', ', $allowed)])
				);
			}
		}

	}//end validateOptionKeys()

	/**
	 * Read an option, falling back to its default.
	 *
	 * @param array $config The step configuration.
	 * @param string $key The option key.
	 * @param array $allowed The accepted values.
	 * @param string $default The default.
	 *
	 * @return string The option value.
	 */
	private function optionFrom(array $config, string $key, array $allowed, string $default): string {
		$value = ($config[$key] ?? null);
		if (is_string($value) === true && in_array($value, $allowed, true) === true) {
			return $value;
		}

		return $default;
	}//end optionFrom()

	/**
	 * Read the configured operation, normalised.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string The operation, or an empty string when none is set.
	 */
	private function operationFrom(array $config): string {
		$operation = ($config['operation'] ?? null);
		if (is_string($operation) === false) {
			return '';
		}

		return strtolower(trim($operation));
	}//end operationFrom()

	/**
	 * Read a register / schema identifier from configuration.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return string The identifier, or an empty string when unusable.
	 */
	private function identifierFrom(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_int($value) === true) {
			return (string)$value;
		}

		return '';
	}//end identifierFrom()

	/**
	 * The register's most legible identifier, for an output item.
	 *
	 * @param Register $register The resolved register.
	 *
	 * @return string The slug, or the id when it has none.
	 */
	private function identifierOf(Register $register): string {
		$slug = (string)($register->getSlug() ?? '');
		if ($slug !== '') {
			return $slug;
		}

		return (string)$register->getId();
	}//end identifierOf()

	/**
	 * The schema's most legible identifier, for an output item.
	 *
	 * @param Schema $schema The resolved schema.
	 *
	 * @return string The slug, or the id when it has none.
	 */
	private function labelOf(Schema $schema): string {
		$slug = (string)($schema->getSlug() ?? '');
		if ($slug !== '') {
			return $slug;
		}

		return (string)$schema->getId();
	}//end labelOf()
}//end class
