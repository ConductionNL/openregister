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
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
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
	public const CODE_PENDING = 'change-pending';

	/**
	 * The refusal code of a gated write while a pending change is open.
	 *
	 * @var string
	 */
	public const CODE_OPEN = 'change-pending-open';

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
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemas       Resolves the written object's schema.
	 * @param DraftService          $drafts        Stores the pending change.
	 * @param DraftVersionMapper    $draftVersions Finds the pending changes already open.
	 * @param IUserSession          $userSession   The submitter.
	 * @param IGroupManager         $groups        The submitter's groups, for the exemption.
	 * @param LoggerInterface       $logger        Logs a gate that fails closed.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly DraftService $drafts,
		private readonly DraftVersionMapper $draftVersions,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly LoggerInterface $logger,
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

		$draft = $this->drafts->create(
			object: $base,
			key: $this->nextKey(objectUuid: (string)$base->getUuid()),
			name: $chainKey,
			changes: $changes,
			actor: (string)$this->userSession->getUser()?->getUID()
		);

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
		$reference = $object->getSchema();
		if ($reference === null || (string)$reference === '') {
			return [];
		}

		try {
			$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException) {
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
