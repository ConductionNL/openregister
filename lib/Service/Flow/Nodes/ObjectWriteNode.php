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
 * BULK MODE (`bulk: true`) trades the per-item service round-trip for ONE
 * `ObjectService::saveObjects()` call covering the whole page of items. It is
 * for pipelines whose item count comes from an external source — a
 * synchronization page of hundreds of rows — where per-item `saveObject()` is
 * the dominant cost. The trade is explicit and validated at save time:
 * only `create` and `upsert` qualify (`update` and `delete` keep their
 * per-item guards), a bulk upsert must match on `uuid` alone and carry
 * `replace: true` because the bulk path replaces whole objects and cannot
 * patch, and `onConflict: fail` is refused because the bulk path cannot
 * arbitrate per-row claims. Per-object lifecycle events are not dispatched —
 * that is much of what makes it bulk. Attribution and RBAC do not move:
 * the one call still runs inside `runAs($owner)` and `saveObjects()` applies
 * its own per-row permission checks.
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
 * THE WRITES RUN AS THE OWNER
 * ---------------------------
 *
 * `$owner` was resolved, passed down every path as `currentUser:` and
 * documented as the subject the write is attributed to — and it is,
 * in the audit trail. But it is NOT the subject the ACCESS CHECK uses:
 * MagicRbacHandler and MagicOrganizationHandler read
 * `IUserSession::getUser()` directly, so the permission gate answers
 * for whoever the ambient session carries. Under a cron worker
 * (`FlowRunWorker`) that is nobody, and a scheduled write is refused
 * as `Anonymous` no matter who owns the run — measured on the hydra
 * sequencer's `lock-issue`, which failed with "User 'Anonymous' does
 * not have permission to 'create' objects in schema 'Agent flow'"
 * while `triggeredBy` was `admin` all along.
 *
 * openregister#2272 fixed exactly this for `ObjectReadNode`, which had
 * the mirror-image version of the bug (a sessionless read SKIPS the
 * RBAC predicate and reads too much, where a sessionless write is
 * DENIED and writes nothing). The write side was left behind. Same
 * seam, same reason: the query layer has no acting-user parameter, so
 * `runAs()` sets the subject for the duration and restores it in a
 * `finally`.
 *
 * The whole loop is wrapped rather than each call, because `findMatch()`
 * is a READ that decides what a write or delete then touches. Running
 * the match under one subject and the write under another is how a
 * delete finds a row it is not allowed to remove.
 *
 * This narrows; it never grants. A run whose owner cannot write is
 * still refused, and now says so for the right reason.
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
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder;
use OCA\OpenRegister\Service\Flow\FlowSubjectCandidates;
use OCA\OpenRegister\Service\Flow\FlowStepReport;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodePreview;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
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
class ObjectWriteNode extends ObjectNodeBase implements IFlowNode, ObjectWriteNodeInterface,
	IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy, IFlowNodePreview
{

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
	 * The config vocabulary of an object-write step, across all four operations.
	 *
	 * @var string[]
	 */
	private const CONFIG_KEYS = [
		'register',
		'schema',
		'operation',
		'fields',
		'payloadFrom',
		'match',
		'replace',
		'bulk',
		'skipWhen',
		'output',
		'confirmDelete',
		'permanent',
		'maxWrites',
		'onConflict',
		'onMissing',
		'onNoMatch',
		'subjectRole',
	];

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
	 * Performs every write, with all its ordinary semantics.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objects;

	/**
	 * Resolves the configured register (id, uuid or slug).
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registers;

	/**
	 * Resolves the configured schema (id, uuid or slug).
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemas;

	/**
	 * Resolves the run owner to an account.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * Holds the instance-wide write-cap default.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Translations.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Resolves the palette icon.
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urls;

	/**
	 * Constructor.
	 *
	 * @param ObjectWriteStores    $stores       Performs every write, and resolves the configured
	 *                                          register and schema (id, uuid or slug).
	 * @param ObjectWritePlatform  $platform     Resolves the run owner to an account, and holds
	 *                                          the instance-wide write-cap default.
	 * @param FlowNodePresentation $presentation Translations and the palette icon.
	 * @param FlowRunSubjectRecorder|null $subjects Records a written object under the declared role.
	 */
	public function __construct(
		ObjectWriteStores $stores,
		ObjectWritePlatform $platform,
		FlowNodePresentation $presentation,
		private readonly ?FlowRunSubjectRecorder $subjects = null,
	) {
		$this->objects     = $stores->objects;
		$this->registers   = $stores->registers;
		$this->schemas     = $stores->schemas;
		$this->userManager = $platform->userManager;
		$this->appConfig   = $platform->appConfig;
		$this->l10n        = $presentation->l10n;
		$this->urls        = $presentation->urls;

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
	 * What this write would do, without writing (flow dry-run).
	 *
	 * The operation, where it would write, and the fields it would set as the
	 * step configures them.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array `summary` and `changes` (`[{field, value}]`).
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do
	 */
	public function preview(array $items, array $config, array $context): array {
		return $this->previewOf(config: $config);
	}//end preview()

	/**
	 * The preview of a write: one summary sentence and the configured field changes.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return array{summary: string, changes: array<int, array{field: string, value: mixed}>} The preview.
	 */
	private function previewOf(array $config): array {
		$operation = trim((string)($config['operation'] ?? 'create'));
		$where = trim((string)($config['register'] ?? '') . ' / ' . (string)($config['schema'] ?? ''), ' /');

		$changes = [];
		foreach ((array)($config['fields'] ?? []) as $field => $value) {
			$changes[] = ['field' => (string)$field, 'value' => $value];
		}

		return [
			'summary' => sprintf('%s an object in %s', $operation, $where),
			'changes' => $changes,
		];
	}//end previewOf()

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
		return self::CONFIG_KEYS;
	}//end configKeys()

	/**
	 * The fields this node is edited through.
	 *
	 * `fields` and `match` are deliberately absent: they are structured values
	 * whose shape depends on the schema being written, and the editor's JSON
	 * pane represents them honestly where a flat text field would not.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-object-write-bulk/spec.md
	 */
	public function configForm(): array {
		return (new ObjectWriteConfigForm(l10n: $this->l10n))->fields();
	}//end configForm()

	/**
	 * Refuse a configuration the author cannot have meant.
	 *
	 * The rules are {@see ObjectWriteConfigValidator}'s; a flow that breaks one must
	 * not be persistable.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration is unusable.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function validateConfig(array $config): void {
		$this->validator()->validate(config: $config);
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
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->validateConfig(config: $config);

		if ($items === []) {
			return [];
		}

		return $this->writeAs(items: $items, plan: $this->writePlan(config: $config, context: $context));
	}//end execute()

	/**
	 * Write every item as the plan's owner, then record what was written as the run's subject.
	 *
	 * @param array          $items The input items, at least one.
	 * @param ObjectWritePlan $plan  The resolved write plan.
	 *
	 * @return array The written items.
	 */
	private function writeAs(array $items, ObjectWritePlan $plan): array {
		$written = $this->objects->runAs($plan->owner, fn (): array => $this->writeAll(items: $items, plan: $plan));
		$this->recordSubject(written: $written, plan: $plan);

		return $written;
	}//end writeAs()

	/**
	 * Write the items down the path the step configures.
	 *
	 * @param array           $items The input items.
	 * @param ObjectWritePlan $plan  The step's resolved plan.
	 *
	 * @return array One output item per input item.
	 */
	private function writeAll(array $items, ObjectWritePlan $plan): array {
		if (($plan->config['bulk'] ?? false) !== true) {
			return $this->writeItems(items: $items, plan: $plan);
		}

		return array_map(
			static fn (array $item): array => FlowItems::item(...$item),
			$this->bulk()->write(items: $items, plan: $plan)
		);
	}//end writeAll()

	/**
	 * Resolve what a write step needs before its first write, in the order the checks fail.
	 *
	 * @param array $config  The step configuration.
	 * @param array $context The run context.
	 *
	 * @return ObjectWritePlan The resolved plan.
	 *
	 * @throws RuntimeException When the owner, register, schema or match pairs cannot be resolved.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	private function writePlan(array $config, array $context): ObjectWritePlan {
		$operation = $this->validator()->operation(config: $config);
		$owner = $this->resolveOwner(context: $context);
		$register = $this->resolveRegister(config: $config);
		$schema = $this->resolveSchema(config: $config, register: $register);
		$plan = new ObjectWritePlan(config: $config, owner: $owner, register: $register, schema: $schema);
		$plan->context = $context;
		$plan->operation = $operation;
		$plan->pairs = $this->validator()->matchPairs(config: $config);
		$plan->cap = $this->writeCap(config: $config);
		$plan->readPolicies();
		return $plan;
	}//end writePlan()

	/**
	 * Record what this step wrote under the role its author named.
	 *
	 * 🔑 A STEP THAT NAMES NO ROLE RECORDS NOTHING. Opting in is the whole
	 * interface: a set that filled itself would be the audit-derived object
	 * list again, which already exists and answers a different question.
	 *
	 * 🔴 ONE OBJECT, OR NONE. A role is a singular name — `case` is one case —
	 * and a step that wrote forty objects has no single one to mean by it.
	 * Recording the fortieth would be arbitrary, and recording all forty in turn
	 * would fire thirty-nine replacement warnings for a set that ends up holding
	 * one entry anyway. So a multi-object write records nothing and says so
	 * ONCE, and the author finds out at the point of use: the later `attachTo`
	 * fails naming the roles the run does hold, which is the loud failure this
	 * whole feature is built around.
	 *
	 * A DELETE RECORDS NOTHING EITHER. The object is gone; a role pointing at it
	 * would attach a task to a record that no longer exists, which is precisely
	 * the "attached to nothing" state `attachTo` refuses to create.
	 *
	 * @param array<int, array<string, mixed>> $written The step's output items.
	 * @param ObjectWritePlan                  $plan    The step's resolved plan.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	private function recordSubject(array $written, ObjectWritePlan $plan): void {
		$role = trim((string)($plan->config['subjectRole'] ?? ''));
		if ($role === '' || $plan->operation === self::OP_DELETE) {
			// Recording is opt-in, and a deleted object is not a subject.
			return;
		}

		$this->subjects?->recordOne(
			context: $plan->context,
			candidates: new FlowSubjectCandidates(
				role: $role,
				uuids: $this->writtenUuids(written: $written, config: $plan->config),
				register: (string)$plan->register->getId(),
				schema: (string)$plan->schema->getId()
			)
		);

	}//end recordSubject()

	/**
	 * The distinct objects this step's output names.
	 *
	 * Read through the same `output` key the items were shaped with, so the
	 * uuid is found whether the written object replaced the record or was nested
	 * under a field.
	 *
	 * @param array<int, array<string, mixed>> $written The step's output items.
	 * @param array<string, mixed>             $config  The step configuration.
	 *
	 * @return array<int, string> The distinct uuids, in order.
	 */
	private function writtenUuids(array $written, array $config): array {
		$key = trim((string)($config['output'] ?? ''));
		$uuids = [];

		foreach ($written as $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			if ($key !== '') {
				$json = (array)($json[$key] ?? []);
			}

			$uuid = trim((string)($json['uuid'] ?? ''));
			if ($uuid !== '' && in_array($uuid, $uuids, true) === false) {
				$uuids[] = $uuid;
			}
		}

		return $uuids;

	}//end writtenUuids()

	/**
	 * The per-item write loop, executed as the run owner.
	 *
	 * Split out of {@see execute()} only so the whole loop can be handed to
	 * `ObjectService::runAs()` as one callable — the match and the write it
	 * feeds must share a subject.
	 *
	 * @param array           $items The input items.
	 * @param ObjectWritePlan $plan  The step's resolved plan.
	 *
	 * @return array One output item per input item.
	 *
	 * @throws RuntimeException When a match is ambiguous or absent, or the cap is exceeded.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	private function writeItems(array $items, ObjectWritePlan $plan): array {
		$out = [];
		foreach ($items as $index => $item) {
			$out[] = $this->writeItem(plan: $plan, item: new ObjectWriteItem(item: $item, index: (int)$index));
		}

		$plan->report(report: $plan->itemCounts());
		return $out;
	}//end writeItems()

	/**
	 * Write one item: skipped items pass through, a delete deletes, any other operation saves.
	 *
	 * A skipped item is still emitted, its json untouched: dropping it is what
	 * would make a later sweep delete it.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 * @param ObjectWriteItem $item The item.
	 *
	 * @return array The output item.
	 *
	 * @throws RuntimeException When a match is ambiguous or absent, or the cap is exceeded.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	private function writeItem(ObjectWritePlan $plan, ObjectWriteItem $item): array {
		if ($this->payloads()->template->isSkipped(path: $plan->skipWhen, json: $item->json) === true) {
			return FlowItems::item(json: $item->json, binary: $item->binary, fromItemIndex: $item->index);
		}

		if ($plan->operation === self::OP_DELETE) {
			$out = $this->executeDelete(item: $item, plan: $plan);
			$plan->counts['deleted'] += $this->confirmedDelete(item: $out, config: $plan->config);
			return $out;
		}

		return $this->saveItem(plan: $plan, item: $item);
	}//end writeItem()

	/**
	 * Create, update or upsert one item's object.
	 *
	 * An update that matches nothing fails: it never silently inserts one.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 * @param ObjectWriteItem $item The item.
	 *
	 * @return array The output item.
	 *
	 * @throws RuntimeException When an update matches nothing, a match is ambiguous, or the cap is exceeded.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	private function saveItem(ObjectWritePlan $plan, ObjectWriteItem $item): array {
		$item->payload = $this->payloads()->build(plan: $plan, json: $item->json);
		$item->matched = $this->saveTarget(plan: $plan, json: $item->json);

		$this->guardCap(writes: $plan->writes, cap: $plan->cap);
		$saved = $this->persist(plan: $plan, item: $item);
		$plan->countSave(matched: $item->matched !== null);

		return FlowItems::item(
			json: $plan->shaped(incoming: $item->json, written: $this->writtenJson(saved: $saved, plan: $plan)),
			binary: $item->binary,
			fromItemIndex: $item->index
		);
	}//end saveItem()

	/**
	 * The object an item's save lands on, or null to insert.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 * @param array           $json The item's record.
	 *
	 * @return ObjectEntity|null The match; always null for a create.
	 *
	 * @throws RuntimeException When an update matches nothing, or a match is ambiguous.
	 */
	private function saveTarget(ObjectWritePlan $plan, array $json): ?ObjectEntity {
		if ($plan->operation === self::OP_CREATE) {
			return null;
		}

		$matched = $this->matcher()->find(plan: $plan, json: $json);
		if ($plan->operation === self::OP_UPDATE && $matched === null) {
			throw new RuntimeException(
				$this->l10n->t('An update matched no object; an update never silently inserts one.')
			);
		}

		return $matched;
	}//end saveTarget()

	/**
	 * Whether an item {@see executeDelete()} returned records a confirmed
	 * delete: 1 when its written record says `deleted: true`, else 0.
	 *
	 * Only ever called on executeDelete()'s own output, where the `deleted`
	 * flag is always the node's (it overwrites the record, or the `output`
	 * key, on every branch), so an incoming `deleted` field from upstream can
	 * never be counted here.
	 *
	 * @param array $item The output item.
	 * @param array $config The step configuration; `output` is read from it.
	 *
	 * @return int 1 for a confirmed delete, 0 otherwise.
	 */
	private function confirmedDelete(array $item, array $config): int {
		$record = (array)($item[FlowItems::JSON] ?? []);
		$key = trim((string)($config['output'] ?? ''));
		if ($key !== '') {
			$record = (array)($record[$key] ?? []);
		}

		if (($record['deleted'] ?? null) === true) {
			return 1;
		}

		return 0;
	}//end confirmedDelete()

	/**
	 * Perform one guarded delete and build its output item.
	 *
	 * Every delete ATTEMPT counts as a write towards the cap: a delete the
	 * service reports as failed may already have cascaded into child deletes
	 * before failing. Only a delete the service confirms (`deleteObject()`
	 * returns true; it returns false for an exception it caught) is marked
	 * `deleted: true` on the output item and counted in the `deleted` tally;
	 * a failed one stays in the run marked `deleted: false`, like a skip.
	 *
	 * @param ObjectWriteItem $item The item.
	 * @param ObjectWritePlan $plan The step's resolved plan; its write count is incremented.
	 *
	 * @return array The output item.
	 *
	 * @throws RuntimeException When the match is absent under `error`, or ambiguous, or the cap is hit.
	 */
	private function executeDelete(ObjectWriteItem $item, ObjectWritePlan $plan): array {
		$matched = $this->matcher()->find(plan: $plan, json: $item->json);
		if ($matched === null) {
			return $this->unmatchedDelete(item: $item, plan: $plan);
		}

		$uuid = (string)$matched->getUuid();
		$this->guardCap(writes: $plan->writes, cap: $plan->cap);

		$deleted = $this->deleteMatched(plan: $plan, uuid: $uuid);
		$plan->writes++;

		// Through `shaped()`, exactly like every non-delete write, so a delete carries
		// its incoming record forward under `output` like any other write.
		return FlowItems::item(
			json: $plan->shaped(incoming: $item->json, written: $plan->deleteRecord(uuid: $uuid, deleted: $deleted)),
			binary: $item->binary,
			fromItemIndex: $item->index
		);
	}//end executeDelete()

	/**
	 * Guard 4: the ordinary delete path, with the run owner as the explicit acting
	 * user and the register / schema scope confining the lookup to one magic table.
	 * No `_retentionSweep`; see `permanenceOf()` for why the soft delete is the
	 * default and what opts out of it.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 * @param string          $uuid The matched object.
	 *
	 * @return bool Whether the service confirmed the delete.
	 */
	private function deleteMatched(ObjectWritePlan $plan, string $uuid): bool {
		return $this->objects->deleteObject(
			uuid: $uuid,
			register: $plan->register,
			schema: $plan->schema,
			currentUser: $plan->owner,
			permanent: $this->permanenceOf(config: $plan->config)
		);
	}//end deleteMatched()

	/**
	 * The output of a delete that matched nothing, or a refusal when `onNoMatch` says so.
	 *
	 * A skip carries the input record through with `deleted: false`, so it is never
	 * indistinguishable from a removal in the run log. It gets the `output` key too,
	 * when one is set: a step whose output key exists only on the branch that deleted
	 * something makes `{{removed.deleted}}` resolve on some items and not others.
	 *
	 * NOT via `shaped()`: on this branch the record IS the output, so an empty key
	 * must leave the record alone rather than replace it with the two-field record.
	 *
	 * @param ObjectWriteItem $item The item.
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 *
	 * @return array The output item.
	 *
	 * @throws RuntimeException When `onNoMatch` is not `skip`.
	 */
	private function unmatchedDelete(ObjectWriteItem $item, ObjectWritePlan $plan): array {
		if ((new ObjectWriteOptions())->option(config: $plan->config, key: 'onNoMatch') !== ObjectWriteOptions::ON_NO_MATCH_SKIP) {
			throw new RuntimeException(
				$this->l10n->t('A delete matched no object; set "onNoMatch" to "skip" if that is intended.')
			);
		}

		$json = $item->json;
		$json['deleted'] = false;
		$outputKey = trim((string)($plan->config['output'] ?? ''));
		if ($outputKey !== '') {
			$json[$outputKey] = ['deleted' => false];
		}

		return FlowItems::item(json: $json, binary: $item->binary, fromItemIndex: $item->index);
	}//end unmatchedDelete()

	/**
	 * Route one non-delete write to the service method its operation calls for.
	 *
	 * `create`, and the insert half of `upsert`, go through `saveObject()`. Every
	 * update-side write goes through `patchObject()` unless the author asked for
	 * `replace: true`, which is the only way to reach PUT semantics from a flow.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan: register, schema, owner, `replace` and `onConflict`.
	 * @param ObjectWriteItem $item The item, its payload built and its match, when there is one, found.
	 *
	 * @return ObjectEntity The written object.
	 */
	private function persist(ObjectWritePlan $plan, ObjectWriteItem $item): ObjectEntity {
		if ($item->matched === null) {
			return $this->insert(plan: $plan, item: $item);
		}

		if ($plan->replace === true) {
			return $this->replaceMatched(plan: $plan, item: $item);
		}

		return $this->objects->patchObject(
			objectId: (string)$item->matched->getUuid(),
			data: $item->payload,
			register: $plan->register,
			schema: $plan->schema,
			currentUser: $plan->owner
		);
	}//end persist()

	/**
	 * Replace the matched object with an item's payload.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 * @param ObjectWriteItem $item The item, its payload built and its match found.
	 *
	 * @return ObjectEntity The written object.
	 */
	private function replaceMatched(ObjectWritePlan $plan, ObjectWriteItem $item): ObjectEntity {
		return $this->objects->saveObject(
			object: $item->payload,
			register: $plan->register,
			schema: $plan->schema,
			uuid: (string)$item->matched->getUuid(),
			currentUser: $plan->owner
		);
	}//end replaceMatched()

	/**
	 * Insert an item's object.
	 *
	 * `matched === null` means the node's own lookup found nothing — but that lookup
	 * and this write are two separate calls, so another flow run can land in between.
	 * Passing failIfExists through is what closes that window: the database, not the
	 * node, arbitrates.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 * @param ObjectWriteItem $item The item, its payload built.
	 *
	 * @return ObjectEntity The written object.
	 */
	private function insert(ObjectWritePlan $plan, ObjectWriteItem $item): ObjectEntity {
		return $this->objects->saveObject(
			object: $item->payload,
			register: $plan->register,
			schema: $plan->schema,
			currentUser: $plan->owner,
			failIfExists: ($plan->onConflict === ObjectWriteOptions::ON_CONFLICT_FAIL)
		);
	}//end insert()

	/**
	 * Build the output record for a performed write.
	 *
	 * Carries the saved data plus the identifiers a downstream step needs to act
	 * on what was just written without re-fetching it.
	 *
	 * @param ObjectEntity    $saved The written object.
	 * @param ObjectWritePlan $plan  The step's resolved plan.
	 *
	 * @return array The output record.
	 */
	private function writtenJson(ObjectEntity $saved, ObjectWritePlan $plan): array {
		$json = (array)$saved->getObject();

		$json['uuid'] = $saved->getUuid();
		$json['register'] = $plan->registerLabel();
		$json['schema'] = $plan->schemaLabel();

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
		// `runAs`, not `triggeredBy`. The two answer different questions and
		// this one is the ACCESS decision: `triggeredBy` records who caused the
		// run and is provenance, while `runAs` names whose rights its steps use.
		// For a scheduled run they differ — the cause is a schedule, the acting
		// identity is the user the trigger declares — and reading provenance here
		// is what made a scheduled write execute as whoever authored the flow.
		$uid = $this->ownerUid(context: $context);
		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new RuntimeException(
				$this->l10n->t(
					'This flow run\'s acting identity "%s" (runAs) is not a user account; an object write must be attributable.',
					[$uid]
				)
			);
		}

		$this->refuseDisabled(user: $user, uid: $uid);

		return $user;
	}//end resolveOwner()

	/**
	 * Refuse an owner whose account is disabled.
	 *
	 * A DISABLED account is not an identity work may proceed under, and
	 * `IUserManager::get()` still returns one. Rights are re-resolved at the
	 * moment work runs precisely so that a departure takes effect: a run
	 * parked for three weeks must not resume with the rights of somebody who
	 * has since been offboarded. Checking only for existence would let the
	 * most common revocation there is — disabling the account — pass
	 * unnoticed for as long as the run lives.
	 *
	 * @param IUser  $user The acting account.
	 * @param string $uid  The identity the run names.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the account is disabled.
	 */
	private function refuseDisabled(IUser $user, string $uid): void {
		if ($user->isEnabled() === false) {
			throw new RuntimeException(
				$this->l10n->t(
					'This flow run\'s acting identity "%s" (runAs) is a disabled account; an object write is refused rather than performed on their behalf.',
					[$uid]
				)
			);
		}
	}//end refuseDisabled()

	/**
	 * The run's acting identity, trimmed.
	 *
	 * @param array $context The run context.
	 *
	 * @return string The uid.
	 *
	 * @throws RuntimeException When the context names none.
	 */
	private function ownerUid(array $context): string {
		$uid = ($context[FlowRunServiceInterface::RUN_AS_CONTEXT_KEY] ?? null);
		if (is_string($uid) === false || trim($uid) === '') {
			throw new RuntimeException(
				$this->l10n->t('This flow run has no acting identity (runAs); an object write must be attributable.')
			);
		}

		return trim($uid);
	}//end ownerUid()

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
		$identifier = $this->validator()->identifier(value: ($config['register'] ?? null));
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
		$identifier = $this->validator()->identifier(value: ($config['schema'] ?? null));
		if ($identifier === '') {
			throw new UnexpectedValueException($this->l10n->t('An object-write step needs a schema.'));
		}

		// The step always carries a register, so the register is the boundary: a
		// slug it does not carry is refused rather than resolved globally. Writing
		// is the dangerous direction — a global match here would create an object
		// under a schema belonging to a register its owner never chose, and a
		// looser `required` set on that foreign schema would accept the row
		// silently.
		if (is_numeric($identifier) === false) {
			return $this->schemaInRegister(slug: $identifier, register: $register);
		}

		return $this->schemaById(identifier: $identifier);
	}//end resolveSchema()

	/**
	 * Resolve a schema by id.
	 *
	 * @param string $identifier The configured id.
	 *
	 * @return Schema The resolved schema.
	 *
	 * @throws UnexpectedValueException When it does not resolve.
	 */
	private function schemaById(string $identifier): Schema {
		try {
			return $this->schemas->find(id: $identifier, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			throw new UnexpectedValueException(
				$this->l10n->t('The schema "%s" could not be resolved.', [$identifier]),
				0,
				$e
			);
		}
	}//end schemaById()

	/**
	 * Resolve a slug among the register's own schemas, or refuse.
	 *
	 * @param string   $slug     The configured slug.
	 * @param Register $register The already-resolved register.
	 *
	 * @return Schema The resolved schema.
	 *
	 * @throws SchemaNotInRegisterException When the register does not carry it.
	 */
	private function schemaInRegister(string $slug, Register $register): Schema {
		$registerSchemaIds = (array)($register->getSchemas() ?? []);
		$scoped            = $this->schemas->findBySlugInIds(slug: $slug, schemaIds: $registerSchemaIds);
		if ($scoped !== null) {
			return $scoped;
		}

		throw new SchemaNotInRegisterException(
			schemaSlug: $slug,
			registerId: $register->getId(),
			registerSlug: $register->getSlug(),
			candidatesElsewhere: $this->schemas->countBySlug(slug: $slug),
			registerSchemaCount: count($registerSchemaIds)
		);
	}//end schemaInRegister()

	/**
	 * Whether this delete destroys the row or tombstones it.
	 *
	 * SOFT unless the author said otherwise, and that default does not move: a
	 * tombstone is what makes a mistaken flow recoverable.
	 *
	 * `permanent: true` is for a row that is a CLAIM rather than a record — a
	 * lock, a slot, a lease. Such a row's identifier is deliberately a pure
	 * function of the thing being claimed, which is exactly what makes two
	 * concurrent claimants collide on it, so it cannot be varied per attempt
	 * without giving up the mutual exclusion it exists for. A soft delete leaves
	 * the tombstone holding that identifier — the `_uuid` unique index carries no
	 * `WHERE _deleted IS NULL` predicate — so a claim that cannot destroy its own
	 * row can be taken exactly once, ever, and every attempt after that is
	 * refused with `already exists` about an object every read reports as absent
	 * (openregister#2459).
	 *
	 * A strict `=== true`, matching `confirmDelete`: the string "false" is
	 * truthy, and `validateDeleteKeys()` has already refused any non-boolean, so
	 * this is belt and braces on the one option that cannot be undone.
	 *
	 * @param array<string, mixed> $config The step's configuration.
	 *
	 * @return bool True to destroy the row, false to tombstone it.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	private function permanenceOf(array $config): bool {
		return (($config['permanent'] ?? false) === true);

	}//end permanenceOf()

	/**
	 * The payload builder, bound to this node's translations.
	 *
	 * @return ObjectWritePayload The builder.
	 */
	private function payloads(): ObjectWritePayload {
		return new ObjectWritePayload(l10n: $this->l10n);
	}//end payloads()

	/**
	 * The match lookup, bound to this node's store.
	 *
	 * @return ObjectWriteMatcher The matcher.
	 */
	private function matcher(): ObjectWriteMatcher {
		return new ObjectWriteMatcher(objects: $this->objects, template: $this->payloads()->template, l10n: $this->l10n);
	}//end matcher()

	/**
	 * The bulk write path, bound to this node's store.
	 *
	 * @return ObjectWriteBulk The bulk path.
	 */
	private function bulk(): ObjectWriteBulk {
		return new ObjectWriteBulk(objects: $this->objects, payloads: $this->payloads(), l10n: $this->l10n);
	}//end bulk()

	/**
	 * The save-time rules, bound to this node's translations.
	 *
	 * @return ObjectWriteConfigValidator The validator.
	 */
	private function validator(): ObjectWriteConfigValidator {
		return new ObjectWriteConfigValidator(l10n: $this->l10n);
	}//end validator()
}//end class
