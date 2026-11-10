<?php

/**
 * Reads register objects into a flow.
 *
 * The counterpart to {@see ObjectWriteNode}, and until now the missing half: a
 * flow could create, patch and delete objects and had no way to ask a QUESTION
 * about them. The live catalogue carried fourteen node types and exactly one
 * object-shaped step, the write (ConductionNL/openregister#2235).
 *
 * The motivating case is a reaper. hydra's sequencer takes a per-issue lock as
 * an object write with `onConflict: fail`, which is a real mutual-exclusion
 * primitive since #2215 — but a crashed run leaves its lock behind, and the
 * scheduled flow that would clear stale ones has to begin by LISTING them.
 * Without a read step that flow cannot be written at all, and the workarounds
 * are worse than the gap: calling OpenRegister's own REST API back through
 * `openconnector.source-call` leaves the process, re-authenticates and crosses
 * an HTTP boundary to read a table it is already sitting on top of.
 *
 * SAME GUARDS AS THE WRITE, FOR THE SAME REASON
 * ---------------------------------------------
 * A flow is authored data. If a flow could READ past RBAC then authoring one
 * would be a disclosure escalation, exactly as writing past it would be a
 * privilege escalation. So the read runs as `context.triggeredBy` — no system
 * user, no owner named in the step's own configuration — and a run with no
 * resolvable owner reads nothing and says why.
 *
 * A failure throws rather than yielding an empty result. "No objects matched"
 * and "the read did not happen" are different answers, and a reaper that reads
 * the second as the first quietly stops reaping.
 *
 * The same distinction applies BEFORE the read: a filter whose placeholder
 * renders empty asked a different question from the one it was authored to
 * ask, so it throws too. See {@see renderFilters()} — that is the shape hydra's
 * lock reaper was dormant in for weeks while every run reported `completed`.
 *
 * TWO OUTPUT SHAPES, AND THE DEFAULT IS THE NARROWER ONE
 * ------------------------------------------------------
 * By default the matches land as a LIST under `output`, which is right for
 * "how many are there" and "is there any" — questions that are one decision.
 * With `fanOut: true` the step emits ONE ITEM PER OBJECT instead, which is what
 * lets the rest of the engine act on them: every other node works per item, and
 * nothing expands a list inside an item's json back into items
 * (`openregister.loop` batches the items on the walk, not the entries of a
 * field). A reaper needs the second shape, and with the first its delete step
 * fails with "the match value for uuid could not be resolved from the item".
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
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IUser;
use OCP\IUserManager;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Reads objects from a register into the run.
 */
class ObjectReadNode extends ObjectNodeBase implements IFlowNode, ObjectReadNodeInterface, IFlowNodeConfigKeys, IFlowNodeTaxonomy {

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openregister.object-read';

	/**
	 * Where the results land when the step names no `output`.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTPUT_KEY = 'objects';

	/**
	 * How many objects one read returns when the step names no `limit`.
	 *
	 * A default rather than "everything": a reaper over a runaway lock table
	 * should process a bounded batch and come back on the next tick, not build
	 * a million-item walk in memory and time the run out.
	 *
	 * @var int
	 */
	private const DEFAULT_LIMIT = 100;

	/**
	 * The ceiling on `limit`, whatever the step asks for.
	 *
	 * @var int
	 */
	private const MAX_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectNodeSources    $sources      Object reads (RBAC-enforcing) and the register and schema mappers.
	 * @param IUserManager         $userManager  Resolves the run owner.
	 * @param FlowNodePresentation $presentation Translations and the palette icon.
	 */
	public function __construct(
		private readonly ObjectNodeSources $sources,
		private readonly IUserManager $userManager,
		private readonly FlowNodePresentation $presentation,
	) {
	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->presentation->l10n->t('Read objects');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->presentation->l10n->t('Find objects in a register and put them on the item.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->presentation->urls->imagePath('core', 'actions/search.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of an object-read step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['register', 'schema', 'filters', 'fanOut', 'limit', 'output'];
	}//end configKeys()

	/**
	 * Refuse a step that cannot name what it reads.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When register or schema is missing, or filters are malformed.
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['register'] ?? '')) === '') {
			throw new UnexpectedValueException($this->presentation->l10n->t('An object-read step needs a register.'));
		}

		if (trim((string)($config['schema'] ?? '')) === '') {
			throw new UnexpectedValueException($this->presentation->l10n->t('An object-read step needs a schema.'));
		}

		if (array_key_exists('filters', $config) === true && is_array($config['filters']) === false) {
			throw new UnexpectedValueException($this->presentation->l10n->t('An object-read step\'s filters must be an object.'));
		}

	}//end validateConfig()

	/**
	 * Read once per item, putting the results on each.
	 *
	 * One read per item, not one for the whole batch, because the filters are
	 * templated from the item — two items asking different questions must get
	 * their own answers. It is the same contract every other item-driven node
	 * follows.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, each with the read's results added.
	 *
	 * @throws RuntimeException When the run has no resolvable owner.
	 * @throws UnexpectedValueException When the register or schema does not resolve.
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		$context = $call->context;
		return $this->readEach(step: new NodeInvocation(items: $items, config: $config, context: $context));
	}//end execute()

	/**
	 * Read once per item; see {@see self::execute()}.
	 *
	 * @param NodeInvocation $step The items, the step configuration and the run metadata.
	 *
	 * @return array The items, each with the read's results added.
	 *
	 * @throws RuntimeException When the run has no resolvable owner.
	 * @throws UnexpectedValueException When the register or schema does not resolve.
	 */
	private function readEach(NodeInvocation $step): array {
		// An empty branch asks nothing. It short-circuits before the owner is
		// resolved, because there is nothing to attribute — the same contract
		// `openconnector.source-call` follows.
		if ($step->items === []) {
			return [];
		}

		$this->validateConfig(config: $step->config);

		$owner    = $this->resolveOwner(context: $step->context);
		$register = $this->resolveRegister(config: $step->config);
		$run      = new ObjectReadRun(
			step: $step,
			owner: $owner,
			register: $register,
			schema: $this->resolveSchema(config: $step->config, register: $register)
		);

		$out = [];
		foreach (array_keys($step->items) as $index) {
			array_push($out, ...$this->itemsFor(run: $run, index: $index));
		}

		return $out;
	}//end readEach()

	/**
	 * One item's read: its results on the item, or one item per object on a fan-out.
	 *
	 * @param ObjectReadRun $run   The step's run.
	 * @param int|string    $index The item's key in the input items.
	 *
	 * @return array<int, array> The output items for this input item.
	 */
	private function itemsFor(ObjectReadRun $run, int|string $index): array {
		$item    = $run->step->items[$index];
		$json    = (array)($item[FlowItems::JSON] ?? []);
		$binary  = (array)($item[FlowItems::BINARY] ?? []);
		$query   = new ObjectReadQuery(
			register: $run->register,
			schema: $run->schema,
			filters: $this->renderFilters(config: $run->step->config, json: $json),
			limit: $this->limitFrom(config: $run->step->config)
		);
		$records = $this->read(query: $query, owner: $run->owner);

		// FAN-OUT: one item per object, rather than one item holding a list.
		//
		// This exists because the list form does not COMPOSE. Every other
		// node in the engine acts per item, and nothing expands a list
		// inside an item's json back into items — `openregister.loop`
		// batches the items on the walk, not the entries of a field. So a
		// read that returns `{objects: [...]}` can be counted and branched
		// on, and cannot be acted on one row at a time.
		//
		// Which is the motivating case. A reaper reads stale locks and then
		// DELETES each one; with the list form its delete step sees one item
		// whose `uuid` is not a field, and fails with "the match value for
		// uuid could not be resolved from the item". Measured while building
		// it.
		//
		// The list stays the DEFAULT because it is the right shape for the
		// other half of the uses — "how many are there", "is there any" —
		// where fanning out would turn one decision into N.
		if (($run->step->config['fanOut'] ?? false) === true) {
			// No matches means no items, which ends the branch — the same
			// contract `openregister.loop` has for an empty input. It is
			// not an error: "nothing to reap" is the ordinary case.
			return array_values(
				array_map(
					static fn (array $record): array => FlowItems::item(json: array_merge($json, $record), binary: $binary, fromItemIndex: (int)$index),
					$records
				)
			);
		}

		$json[$this->outputKey(config: $run->step->config)] = $records;

		return [FlowItems::item(json: $json, binary: $binary, fromItemIndex: (int)$index)];
	}//end itemsFor()

	/**
	 * The item key a non-fan-out read lands under: `output`, or the default.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string The key.
	 */
	private function outputKey(array $config): string {
		$key = trim((string)($config['output'] ?? ''));
		if ($key === '') {
			return self::DEFAULT_OUTPUT_KEY;
		}

		return $key;
	}//end outputKey()

	/**
	 * Render the step's filters, refusing any placeholder that comes out empty.
	 *
	 * A FILTER IS A QUESTION, AND AN EMPTY ONE IS STILL A QUESTION.
	 * ------------------------------------------------------------
	 * `FlowValueTemplate::render()` cannot fail: a path the item does not carry
	 * resolves to null and substitutes as the empty string. For a written FIELD
	 * that is a deliberate narrowing, governed by `onMissing`. For a filter it
	 * is a silent change of meaning — `"ZZ issue-lock {{repo}}"` becomes
	 * `"ZZ issue-lock "`, which matches nothing, and the step then returns zero
	 * rows and reports success.
	 *
	 * That is not hypothetical. hydra's lock reaper is a scheduled flow whose
	 * filter interpolated `{{repo}}`, and a schedule tick's payload is only
	 * `{flowId, scheduledAt}` — so under its own trigger the filter always
	 * rendered empty and the reaper swept nothing, every run `completed`. It
	 * reaped correctly whenever a human passed `repo` in a test payload, which
	 * is exactly what kept it hidden.
	 *
	 * So an unfilled placeholder throws here, the same way
	 * `ObjectWriteNode::findMatch()` refuses an unfilled MATCH key: a guard
	 * that gets weaker when its input is missing is not a guard. `null` and the
	 * empty string count as unfilled alongside an absent path, because all
	 * three render identically — a computed field whose expression returned
	 * null is present in the record and just as empty.
	 *
	 * A step that genuinely wants "everything" says so by authoring no filter,
	 * which is a different sentence from one whose filter did not render.
	 *
	 * @param array $config The step configuration.
	 * @param array $json The current item's record.
	 *
	 * @return array The rendered filters.
	 *
	 * @throws RuntimeException When a filter placeholder renders empty.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function renderFilters(array $config, array $json): array {
		$rendered = FlowValueTemplate::renderTracked(
			value: (array)($config['filters'] ?? []),
			json: $json
		);

		if ($rendered['unresolved'] !== []) {
			throw new RuntimeException(
				$this->presentation->l10n->t(
					'The object-read step\'s filter could not be rendered: %s resolved to nothing on this item. '
					. 'A filter that renders empty matches nothing, so the step would report success having read no objects.',
					[implode(', ', $rendered['unresolved'])]
				)
			);
		}

		return (array)$rendered['value'];
	}//end renderFilters()

	/**
	 * Perform one read as the run owner.
	 *
	 * A failure THROWS rather than returning an empty list. "No objects
	 * matched" and "the read did not happen" are different answers, and a
	 * caller that cannot tell them apart — a reaper, a guard, a count — will
	 * read the second as the first and quietly do nothing.
	 *
	 * @param ObjectReadQuery $query The resolved register and schema, the rendered filters and the cap.
	 * @param IUser           $owner The run owner, whose RBAC applies.
	 *
	 * @return array<int, array> The matched objects, as plain records.
	 *
	 * @throws RuntimeException When the read itself fails.
	 */
	private function read(ObjectReadQuery $query, IUser $owner): array {
		try {
			// The read runs AS the owner. `$owner` was resolved, declared and
			// documented as "the subject whose RBAC applies" — and then never
			// used, so the read actually ran under whatever subject the ambient
			// session happened to carry. Under CLI cron that is no subject at
			// all, and both the RBAC and the organisation filter treat a
			// sessionless CLI context as trusted and skip themselves entirely.
			// So a scheduled flow read past the very access control this
			// parameter names.
			//
			// ObjectService::findAll() has no acting-user parameter anywhere on
			// its chain, so the subject is set for the duration of the call and
			// restored afterwards. See ObjectService::runAs() for why that is
			// the seam rather than threading a user through the query layer.
			$found = $this->sources->objects->runAs(
				$owner,
				fn (): array => $this->sources->objects
					->setRegister($query->register)
					->setSchema($query->schema)
					->findAll(config: ['filters' => $query->filters, 'limit' => $query->limit])
			);
		} catch (Throwable $e) {
			throw new RuntimeException(
				$this->presentation->l10n->t('The object-read step could not read from the register: %s', [$e->getMessage()]),
				0,
				$e
			);
		}//end try

		$records = [];
		foreach ($found as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$record = (array)$object->getObject();
			// The uuid is what a follow-up write or delete needs to name this
			// row, and it does not live in the record's own fields.
			$record['uuid'] = $object->getUuid();

			$records[] = $record;
		}

		return $records;
	}//end read()

	/**
	 * The bounded result cap for one read.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return int The limit.
	 */
	private function limitFrom(array $config): int {
		$limit = (int)($config['limit'] ?? self::DEFAULT_LIMIT);
		if ($limit < 1) {
			return self::DEFAULT_LIMIT;
		}

		return min($limit, self::MAX_LIMIT);
	}//end limitFrom()

	/**
	 * The run's owner, whose RBAC bounds the read.
	 *
	 * @param array $context Run-level metadata.
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
		// is what made a scheduled read execute as whoever authored the flow.
		$uid = ($context[FlowRunServiceInterface::RUN_AS_CONTEXT_KEY] ?? null);
		if (is_string($uid) === false || trim($uid) === '') {
			throw new RuntimeException(
				$this->presentation->l10n->t('This flow run has no acting identity (runAs); an object read must be attributable.')
			);
		}

		$user = $this->userManager->get(trim($uid));
		if ($user === null) {
			throw new RuntimeException(
				$this->presentation->l10n->t(
					'This flow run\'s acting identity "%s" (runAs) is not a user account; an object read must be attributable.',
					[trim($uid)]
				)
			);
		}

		// A DISABLED account is not an identity work may proceed under, and
		// `IUserManager::get()` still returns one. Rights are re-resolved at the
		// moment work runs precisely so that a departure takes effect: a run
		// parked for three weeks must not resume with the rights of somebody who
		// has since been offboarded. Checking only for existence would let the
		// most common revocation there is — disabling the account — pass
		// unnoticed for as long as the run lives.
		if ($user->isEnabled() === false) {
			throw new RuntimeException(
				$this->presentation->l10n->t(
					'This flow run\'s acting identity "%s" (runAs) is a disabled account; an object read is refused rather than performed on their behalf.',
					[trim($uid)]
				)
			);
		}

		return $user;
	}//end resolveOwner()

	/**
	 * Resolve the configured register from a slug, uuid or id.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return Register The resolved register.
	 *
	 * @throws UnexpectedValueException When it does not resolve.
	 */
	private function resolveRegister(array $config): Register {
		$identifier = trim((string)($config['register'] ?? ''));

		try {
			return $this->sources->registers->find(id: $identifier, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			throw new UnexpectedValueException(
				$this->presentation->l10n->t('The register "%s" could not be resolved.', [$identifier]),
				0,
				$e
			);
		}

	}//end resolveRegister()

	/**
	 * Resolve the configured schema, preferring the register's own schemas.
	 *
	 * A bare slug is only unique WITHIN a register. Resolving it globally lets
	 * a generic slug (`order`, `task`, `lock`) land on another app's schema
	 * that happens to share it — the same reasoning as `ObjectWriteNode`.
	 *
	 * @param array $config The step configuration.
	 * @param Register $register The already-resolved register.
	 *
	 * @return Schema The resolved schema.
	 *
	 * @throws UnexpectedValueException When it does not resolve.
	 */
	private function resolveSchema(array $config, Register $register): Schema {
		$identifier = trim((string)($config['schema'] ?? ''));

		// Refusing beats resolving globally on the READ side too, and for a reason
		// that is easy to miss: a global match here does not usually return foreign
		// data, it returns NOTHING — the wrongly-resolved schema has no table under
		// this register. An empty result set is indistinguishable from "this
		// register holds no objects", so the flow would report success over a
		// silently unreachable dataset.
		if (is_numeric($identifier) === false) {
			$registerSchemaIds = (array)($register->getSchemas() ?? []);
			$scoped            = $this->sources->schemas->findBySlugInIds(
				slug: $identifier,
				schemaIds: $registerSchemaIds
			);
			if ($scoped !== null) {
				return $scoped;
			}

			throw new SchemaNotInRegisterException(
				schemaSlug: $identifier,
				registerId: $register->getId(),
				registerSlug: $register->getSlug(),
				candidatesElsewhere: $this->sources->schemas->countBySlug(slug: $identifier),
				registerSchemaCount: count($registerSchemaIds)
			);
		}

		try {
			return $this->sources->schemas->find(id: $identifier, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			throw new UnexpectedValueException(
				$this->presentation->l10n->t('The schema "%s" could not be resolved.', [$identifier]),
				0,
				$e
			);
		}

	}//end resolveSchema()
}//end class
