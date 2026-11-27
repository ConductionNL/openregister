<?php

/**
 * OpenRegister ChangeGateListener
 *
 * Holds a write that touches a property an approval chain gates. The change is
 * stored as a pending change, a draft keyed `pending-<n>` beside the record,
 * and the write is stopped, so the stored record stays as it was until a second
 * person approves. A gated create is held the same way, over a reserved uuid
 * with no live object, so reads, lists and search do not see it.
 *
 * The gate is the `gate.change` form of an `x-openregister-approval-chains`
 * entry ({@see ChangeGateDeclaration}). A gate that is declared but cannot be
 * read fails closed, as a transition gate does.
 *
 * The same listener decides the held change: when the chain's sequence
 * completes it applies the change (`onApprove: applyChange`), and when it is
 * rejected it discards it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskSequenceRejectedEvent;
use OCA\OpenRegister\Kernel\Contracts\Core\HeldChangeCodes;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Listener\Support\ApprovalChainSequences;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\Task\ChangeGateDeclaration;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Stores a gated write as a pending change and stops it.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ChangeGateListener implements IEventListener {

	/**
	 * The refusal code of a held write; the controller answers it with 202.
	 *
	 * @var string
	 */
	public const CODE_PENDING = HeldChangeCodes::PENDING;

	/**
	 * The refusal code of a gated write while a pending change is open.
	 *
	 * @var string
	 */
	public const CODE_OPEN = HeldChangeCodes::PENDING_OPEN;

	/**
	 * The refusal code of a change gate that is declared but cannot be read.
	 *
	 * @var string
	 */
	public const CODE_MISCONFIGURED = 'approval-chain-misconfigured';

	/**
	 * The prefix of a pending change's draft key.
	 *
	 * @var string
	 */
	public const KEY_PREFIX = 'pending-';

	/**
	 * The records whose approved change is being applied right now, by uuid.
	 *
	 * Static, so the write the promotion makes passes this gate whichever
	 * instance of the listener the dispatcher hands the pre-write event to.
	 *
	 * @var array<string, true>
	 */
	private static array $releasing = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemas       Resolves the written object's schema.
	 * @param DraftService          $drafts        Stores the pending change.
	 * @param DraftVersionMapper    $draftVersions Finds the pending changes already open.
	 * @param IUserSession          $userSession   The submitter.
	 * @param IGroupManager         $groups        The submitter's groups, for the exemption.
	 * @param LoggerInterface       $logger        Logs a gate that fails closed.
	 * @param ApprovalChainSequences|null $sequences The chain's task sequence, started for each held change.
	 * @param ObjectOperationsInterface|null $objects The object write path an approved change is applied through.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly DraftService $drafts,
		private readonly DraftVersionMapper $draftVersions,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly LoggerInterface $logger,
		private readonly ?ApprovalChainSequences $sequences = null,
		private readonly ?ObjectOperationsInterface $objects = null,
	) {

	}//end __construct()

	/**
	 * Hold a gated create or update.
	 *
	 * @param Event $event The pre-write event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
	 */
	public function handle(Event $event): void {
		if ($event instanceof TaskSequenceCompletedEvent) {
			$this->decide(sequence: $event->getSequence(), approved: true, decider: $event->getDecider());
			return;
		}

		if ($event instanceof TaskSequenceRejectedEvent) {
			$this->decide(sequence: $event->getSequence(), approved: false, decider: null);
			return;
		}

		if ($event instanceof ObjectCreatingEvent && $event->isPropagationStopped() === false) {
			$this->gateWrite(event: $event, operation: ChangeGateDeclaration::ON_CREATE, written: $event->getObject(), stored: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent && $event->isPropagationStopped() === false && $event->getOldObject() !== null) {
			$this->gateWrite(
				event: $event,
				operation: ChangeGateDeclaration::ON_UPDATE,
				written: $event->getNewObject(),
				stored: $event->getOldObject()
			);
		}

	}//end handle()

	/**
	 * Judge the write against each change gate of its schema, in declared order; the first that holds it wins.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event     The pre-write event.
	 * @param string                                  $operation `create` or `update`.
	 * @param ObjectEntity                            $written   The object as it would be stored.
	 * @param ObjectEntity|null                       $stored    The stored object, null for a create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
	 */
	private function gateWrite(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		string $operation,
		ObjectEntity $written,
		?ObjectEntity $stored
	): void {
		// The approved change being applied is not held again by its own gate.
		if (isset(self::$releasing[(string)($stored ?? $written)->getUuid()]) === true) {
			return;
		}

		$changes = self::changedFields(written: $written, stored: $stored);
		if ($changes === []) {
			return;
		}

		foreach ($this->changeGatesOf(object: $written) as $chainKey => $spec) {
			$gate = ChangeGateDeclaration::fromChain(chain: $spec);
			if ($gate === null || (string)($spec['transition'] ?? '') !== '') {
				$this->refuseMisconfigured(event: $event, chainKey: $chainKey);
				return;
			}

			if ($this->holds(gate: $gate, operation: $operation, changes: $changes) === true) {
				$this->hold(event: $event, chainKey: $chainKey, written: $written, stored: $stored, changes: $changes);
				return;
			}
		}

	}//end gateWrite()

	/**
	 * Whether a gate holds this write: the operation is gated, a gated property changes, and the caller is not exempt.
	 *
	 * @param ChangeGateDeclaration $gate      The gate.
	 * @param string                $operation `create` or `update`.
	 * @param array<string, mixed>  $changes   The changed fields.
	 *
	 * @return bool True when the write is held.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
	 */
	private function holds(ChangeGateDeclaration $gate, string $operation, array $changes): bool {
		if ($gate->gates(operation: $operation) === false) {
			return false;
		}

		$covered = array_filter(
			array_keys($changes),
			static fn (string|int $property): bool => $gate->coversProperty(property: (string)$property)
		);
		if ($covered === []) {
			return false;
		}

		return $gate->isExempt(groups: $this->callerGroups()) === false;

	}//end holds()

	/**
	 * Store the write as a pending change and stop it, or refuse it while one is open.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event    The pre-write event.
	 * @param string                                  $chainKey The chain whose gate holds the write.
	 * @param ObjectEntity                            $written  The object as it would be stored.
	 * @param ObjectEntity|null                       $stored   The stored object, null for a create.
	 * @param array<string, mixed>                    $changes  The changed fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-approval-applies-the-change-and-rejection-discards-it
	 */
	private function hold(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		string $chainKey,
		ObjectEntity $written,
		?ObjectEntity $stored,
		array $changes
	): void {
		$base = ($stored ?? self::reservedBase(written: $written));
		$open = $this->openPendingChange(objectUuid: (string)$base->getUuid(), chainKey: $chainKey);
		if ($open !== null) {
			$event->setErrors(
				[
					'code' => self::CODE_OPEN,
					'status' => 409,
					'message' => sprintf('A change to this record is already waiting for approval (%s).', (string)$open->getDraftKey()),
					'pendingChange' => (string)$open->getDraftKey(),
					'chain' => $chainKey,
				]
			);
			$event->stopPropagation();
			return;
		}

		$template = $this->chainTemplate(object: $written, chainKey: $chainKey);
		if ($template === false) {
			$this->refuseMisconfigured(event: $event, chainKey: $chainKey);
			return;
		}

		$submitter = (string)$this->userSession->getUser()?->getUID();
		$draft = $this->drafts->create(
			object: $base,
			key: $this->nextKey(objectUuid: (string)$base->getUuid()),
			name: $chainKey,
			changes: $changes,
			actor: $submitter
		);

		if ($template !== null) {
			$this->startDecision(template: $template, base: $base, submitter: $submitter);
		}

		$event->setErrors(
			[
				'code' => self::CODE_PENDING,
				'status' => 202,
				'message' => sprintf('This change waits for approval through chain "%s".', $chainKey),
				'pendingChange' => (string)$draft->getDraftKey(),
				'object' => (string)$base->getUuid(),
				'chain' => $chainKey,
			]
		);
		$event->stopPropagation();

	}//end hold()

	/**
	 * The compiled task template of the chain: null when no sequence store is wired, false when the chain cannot be compiled.
	 *
	 * A chain that holds a change but cannot start its decision would leave the
	 * change waiting for nobody, so it fails closed, as a transition gate does.
	 *
	 * @param ObjectEntity $object   The written object.
	 * @param string       $chainKey The chain.
	 *
	 * @return array<string, mixed>|false|null The template, false when uncompilable, null without a sequence store.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/tasks.md#3.1
	 */
	private function chainTemplate(ObjectEntity $object, string $chainKey): array|false|null {
		if ($this->sequences === null) {
			return null;
		}

		$schema = $this->schemaOf(object: $object);
		if ($schema === null) {
			return false;
		}

		return ($this->sequences->installer->compile(schema: $schema, chainKey: $chainKey) ?? false);
	}//end chainTemplate()

	/**
	 * Start the chain's task sequence for a held change, with the submitter as its requester.
	 *
	 * The requester is what separation of duties binds the decision to: the
	 * sequence's decision guard refuses the submitter's own approve (403, with
	 * the separation-of-duties reason). The sequence is anchored on the record
	 * (the reserved uuid of a held create), where the pending change waits.
	 *
	 * @param array<string, mixed> $template  The compiled chain.
	 * @param ObjectEntity         $base      The record the change waits beside.
	 * @param string               $submitter The user whose change is held.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-change-held-for-approval/tasks.md#3.1
	 */
	private function startDecision(array $template, ObjectEntity $base, string $submitter): void {
		if ($this->sequences === null) {
			return;
		}

		$register   = (string)$base->getRegister();
		$registerId = null;
		if (is_numeric($register) === true) {
			$registerId = (int)$register;
		}

		$requester = null;
		if ($submitter !== '') {
			$requester = $submitter;
		}

		$service = $this->sequences->sequenceService;
		$service->provision(
			chain: $service->chain(template: $template),
			anchor: $service->anchorOf(objectUuid: (string)$base->getUuid(), requesterId: $requester, registerId: $registerId)
		);
	}//end startDecision()

	/**
	 * Fail closed on a change gate that is declared but cannot be read.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event    The pre-write event.
	 * @param string                                  $chainKey The chain.
	 *
	 * @return void
	 */
	private function refuseMisconfigured(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $chainKey): void {
		$this->logger->error(
			message: '[ChangeGateListener] chain "{chain}" declares a change gate that cannot be read; the write is refused.',
			context: ['chain' => $chainKey]
		);
		$event->setErrors(
			[
				'code' => self::CODE_MISCONFIGURED,
				'message' => sprintf('Approval chain "%s" declares a change gate that is not usable.', $chainKey),
			]
		);
		$event->stopPropagation();

	}//end refuseMisconfigured()

	/**
	 * The chain entries of the object's schema that declare a change gate, keyed by chain.
	 *
	 * @param ObjectEntity $object The written object.
	 *
	 * @return array<string, array<string, mixed>> The entries.
	 */
	private function changeGatesOf(ObjectEntity $object): array {
		$schema = $this->schemaOf(object: $object);
		if ($schema === null) {
			return [];
		}

		$chains = (($schema->getConfiguration() ?? [])['x-openregister-approval-chains'] ?? null);
		if (is_array($chains) === false) {
			return [];
		}

		$gated = [];
		foreach ($chains as $chainKey => $spec) {
			if (is_string($chainKey) === true && is_array($spec) === true && ChangeGateDeclaration::declaredIn(chain: $spec) === true) {
				$gated[$chainKey] = $spec;
			}
		}

		return $gated;
	}//end changeGatesOf()

	/**
	 * Apply or discard the pending change a decided approval sequence held.
	 *
	 * Only a sequence of a chain that declares a change gate, anchored on a
	 * record with an open pending change of that chain, is acted on. A rejection
	 * discards the pending change; the rejecting decision and its comment stay on
	 * the task's own audit trail. An approval applies it when the chain declares
	 * `onApprove: applyChange`.
	 *
	 * @param TaskSequence $sequence The closed sequence.
	 * @param bool                  $approved Whether it completed approvingly.
	 * @param string|null           $decider  Who took the final decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-approval-applies-the-change-and-rejection-discards-it
	 */
	private function decide(TaskSequence $sequence, bool $approved, ?string $decider): void {
		$held = $this->heldChangeOf(sequence: $sequence);
		if ($held === null) {
			return;
		}

		if ($approved === false) {
			$this->draftVersions->delete($held['draft']);
			$this->logger->info(
				message: '[ChangeGateListener] the pending change {key} of {object} was rejected and discarded.',
				context: ['key' => (string)$held['draft']->getDraftKey(), 'object' => $held['anchor']]
			);
			return;
		}

		if (($held['spec']['onApprove'] ?? null) !== 'applyChange') {
			return;
		}

		$this->applyChange(held: $held, decider: $decider);
	}//end decide()

	/**
	 * The schema chain, the record and the open pending change a sequence decides; null when it decides none.
	 *
	 * @param TaskSequence $sequence The closed sequence.
	 *
	 * @return array{schema: Schema, spec: array<string, mixed>, anchor: string, draft: DraftVersion}|null
	 */
	private function heldChangeOf(TaskSequence $sequence): ?array {
		$anchor   = (string)$sequence->getAnchorObjectUuid();
		$chainKey = (string)$sequence->getChainKey();
		if ($anchor === '' || $chainKey === '' || $sequence->getSchemaId() === null) {
			return null;
		}

		try {
			$schema = $this->schemas->find(id: $sequence->getSchemaId(), _rbac: false, _multitenancy: false);
		} catch (\Throwable) {
			return null;
		}

		$spec = ((($schema->getConfiguration() ?? [])['x-openregister-approval-chains'] ?? [])[$chainKey] ?? null);
		if (is_array($spec) === false || ChangeGateDeclaration::declaredIn(chain: $spec) === false) {
			return null;
		}

		$draft = $this->openPendingChange(objectUuid: $anchor, chainKey: $chainKey);
		if ($draft === null) {
			return null;
		}

		return ['schema' => $schema, 'spec' => $spec, 'anchor' => $anchor, 'draft' => $draft];
	}//end heldChangeOf()

	/**
	 * Apply an approved pending change through the normal save path, then remove it.
	 *
	 * The save runs the full validation, so a change that no longer fits the
	 * record is refused: it is logged naming the approver and discarded, so the
	 * record is not left blocked by a change nobody can apply. The approval is
	 * the authorization of this write, so the save does not ask the approver's
	 * own rights again; this gate lets it through by the record's uuid.
	 *
	 * @param array{schema: Schema, spec: array<string, mixed>, anchor: string, draft: DraftVersion} $held    The decided change.
	 * @param string|null                                                                         $decider The approver.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-approval-applies-the-change-and-rejection-discards-it
	 */
	private function applyChange(array $held, ?string $decider): void {
		if ($this->objects === null) {
			$this->logger->warning(message: '[ChangeGateListener] an approved change cannot be applied: no object write path is wired.');
			return;
		}

		$draft  = $held['draft'];
		$anchor = $held['anchor'];
		$live   = $this->liveRecord(uuid: $anchor);
		$data   = ($draft->getDelta() ?? []);
		if ($live !== null) {
			$data = $this->drafts->merged(object: $live, draft: $draft);
		}

		$register = (string)($draft->getObjectRegister() ?? $live?->getRegister() ?? '');
		self::$releasing[$anchor] = true;
		try {
			$this->objects->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $register, schema: (string)$held['schema']->getId()),
					uuid: $anchor
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $refused) {
			$this->logger->warning(
				message: '[ChangeGateListener] the approved change {key} of {object} was refused on apply and discarded; approver {approver}: {reason}',
				context: ['key' => (string)$draft->getDraftKey(), 'object' => $anchor, 'approver' => (string)$decider, 'reason' => $refused->getMessage()]
			);
		} finally {
			unset(self::$releasing[$anchor]);
		}

		$this->draftVersions->delete($draft);
	}//end applyChange()

	/**
	 * The stored record a pending change waits beside, or null for a held create.
	 *
	 * @param string $uuid The record's uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function liveRecord(string $uuid): ?ObjectEntity {
		try {
			return $this->objects?->find(id: $uuid, _rbac: false, _multitenancy: false);
		} catch (\Throwable) {
			return null;
		}
	}//end liveRecord()

	/**
	 * The written object's schema, or null when it names none that exists.
	 *
	 * @param ObjectEntity $object The written object.
	 *
	 * @return Schema|null
	 */
	private function schemaOf(ObjectEntity $object): ?Schema {
		$reference = $object->getSchema();
		if ($reference === null || (string)$reference === '') {
			return null;
		}

		try {
			return $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end schemaOf()

	/**
	 * The pending change of this chain still open on the object, if any.
	 *
	 * @param string $objectUuid The object.
	 * @param string $chainKey   The chain.
	 *
	 * @return DraftVersion|null The open pending change.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-approval-applies-the-change-and-rejection-discards-it
	 */
	private function openPendingChange(string $objectUuid, string $chainKey): ?DraftVersion {
		foreach ($this->draftVersions->findForObject(objectUuid: $objectUuid) as $draft) {
			if (str_starts_with((string)$draft->getDraftKey(), self::KEY_PREFIX) === true && $draft->getName() === $chainKey) {
				return $draft;
			}
		}

		return null;
	}//end openPendingChange()

	/**
	 * The next free pending-change key of the object: one past the highest in use.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return string The key, such as `pending-3`.
	 */
	private function nextKey(string $objectUuid): string {
		$highest = 0;
		foreach ($this->draftVersions->findForObject(objectUuid: $objectUuid) as $draft) {
			$key = (string)$draft->getDraftKey();
			if (preg_match('/^' . self::KEY_PREFIX . '(\d+)$/', $key, $match) === 1) {
				$highest = max($highest, (int)$match[1]);
			}
		}

		return self::KEY_PREFIX . ($highest + 1);
	}//end nextKey()

	/**
	 * The calling user's group ids; none without a user.
	 *
	 * @return array<int, string> The group ids.
	 */
	private function callerGroups(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		return array_values($this->groups->getUserGroupIds($user));
	}//end callerGroups()

	/**
	 * The fields a write changes against the stored object; a removed field changes to null.
	 *
	 * @param ObjectEntity      $written The object as it would be stored.
	 * @param ObjectEntity|null $stored  The stored object, null for a create.
	 *
	 * @return array<string, mixed> The changed fields and their new values.
	 */
	private static function changedFields(ObjectEntity $written, ?ObjectEntity $stored): array {
		$new = self::dataOf(object: $written);
		$old = [];
		if ($stored !== null) {
			$old = self::dataOf(object: $stored);
		}

		$changes = [];
		foreach ($new as $field => $value) {
			if (array_key_exists($field, $old) === false || $old[$field] !== $value) {
				$changes[$field] = $value;
			}
		}

		foreach (array_keys(array_diff_key($old, $new)) as $field) {
			$changes[$field] = null;
		}

		return $changes;
	}//end changedFields()

	/**
	 * An object's own data, without metadata and identifiers.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The data.
	 */
	private static function dataOf(ObjectEntity $object): array {
		$data = $object->getObject();
		if (is_array($data) === false) {
			return [];
		}

		$own = [];
		foreach ($data as $field => $value) {
			$field = (string)$field;
			if ($field === '' || $field[0] === '@' || in_array($field, ['id', 'uuid'], true) === true) {
				continue;
			}

			$own[$field] = $value;
		}

		return $own;
	}//end dataOf()

	/**
	 * The base of a held create: no data and no version, under the uuid the create asked for or a fresh one.
	 *
	 * @param ObjectEntity $written The object the create would store.
	 *
	 * @return ObjectEntity The base the pending change is drafted against.
	 *
	 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-a-gated-new-record-does-not-exist-until-it-is-approved
	 */
	private static function reservedBase(ObjectEntity $written): ObjectEntity {
		$uuid = (string)$written->getUuid();
		if ($uuid === '') {
			$uuid = Uuid::v4()->toRfc4122();
		}

		$base = new ObjectEntity();
		$base->setUuid($uuid);
		$base->setRegister($written->getRegister());
		$base->setSchema($written->getSchema());
		$base->setObject([]);

		return $base;
	}//end reservedBase()
}//end class
