<?php

/**
 * OpenRegister ApprovalChainGateListener
 *
 * Subscribes to ObjectUpdatingEvent and blocks a lifecycle transition named by a
 * schema's `x-openregister-approval-chains` declaration until the object's
 * approval SEQUENCE for that chain is complete with an approving outcome
 * (flow-approval-consolidation). The refusal contract is unchanged from the
 * retired step-scanning implementation: the same two error codes
 * (`approval-chain-pending`, `approval-chain-misconfigured`), the same
 * fail-closed policy on a chain that cannot be provisioned, and the same
 * transition matching, re-derived independently of
 * `Listener\LifecycleValidationListener` exactly as before.
 *
 * One deliberate behavioural difference (design D-5): a rejected cycle is
 * CLOSED and kept, never deleted. The next attempt opens a NEW sequence and
 * the rejected one, its decisions and its comments stay readable.
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
 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Gates a declared lifecycle transition on approval-sequence completion.
 *
 * @template-implements IEventListener<ObjectUpdatingEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ApprovalChainGateListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param TaskSequenceMapper $sequenceMapper Reads the anchor's sequences.
	 * @param TaskSequenceService $sequenceService Provisions a sequence on the first attempt.
	 * @param ApprovalChainAnnotationInstaller $installer Compiles the declared chain into a task template.
	 * @param IUserSession $userSession Current user session (requester identity).
	 * @param LoggerInterface $logger Logger for gate diagnostics.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly TaskSequenceMapper $sequenceMapper,
		private readonly TaskSequenceService $sequenceService,
		private readonly ApprovalChainAnnotationInstaller $installer,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Gate the attempted lifecycle transition against any declared approval chain.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-007
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$oldObject = $event->getOldObject();
		if ($oldObject === null) {
			return;
		}

		$newObject = $event->getNewObject();
		$schema = $this->loadSchema(object: $newObject);
		if ($schema === null) {
			return;
		}

		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config['x-openregister-approval-chains'] ?? null);
		if (is_array($chains) === false || $chains === []) {
			return;
		}

		$lifecycle = ($config['x-openregister-lifecycle'] ?? null);
		if (is_array($lifecycle) === false) {
			return;
		}

		$field = (string)($lifecycle['field'] ?? ($lifecycle['property'] ?? ''));
		$transitions = ($lifecycle['transitions'] ?? []);
		$oldData = ($oldObject->getObject() ?? []);
		$newData = ($newObject->getObject() ?? []);
		$oldValue = ($oldData[$field] ?? null);
		$newValue = ($newData[$field] ?? null);

		if ($oldValue === $newValue || is_string($newValue) === false) {
			return;
		}

		$action = $this->matchTransition(
			transitions: $transitions,
			oldValue: (string)$oldValue,
			newValue: $newValue
		);
		if ($action === null) {
			// Not a recognised transition — LifecycleValidationListener rejects
			// this on its own; the approval gate has nothing to evaluate.
			return;
		}

		foreach ($chains as $chainKey => $spec) {
			if (is_string($chainKey) === false || is_array($spec) === false) {
				continue;
			}

			if (($spec['transition'] ?? null) !== $action) {
				continue;
			}

			$blocked = $this->evaluateGate(
				event: $event,
				schema: $schema,
				chainKey: $chainKey,
				object: $newObject,
				newData: $newData
			);
			if ($blocked === true) {
				return;
			}
		}//end foreach
	}//end handle()

	/**
	 * Evaluate one declared chain against the object attempting its gated
	 * transition. Returns true when the event was rejected (short-circuits the
	 * caller's loop).
	 *
	 * The store consulted is the SEQUENCE, not step rows: complete releases,
	 * running refuses without provisioning a duplicate, rejected or
	 * terminated (or none) provisions a NEW sequence and refuses. The tier
	 * is resolved from the attempted write ONCE, here, and frozen onto the
	 * sequence, so a mid-cycle amount edit cannot re-route a running
	 * approval.
	 *
	 * @param ObjectUpdatingEvent $event The event being evaluated.
	 * @param Schema $schema The object's schema.
	 * @param string $chainKey Declarative chain key.
	 * @param ObjectEntity $object The object attempting the transition.
	 * @param array<string, mixed> $newData The object's new (attempted) data.
	 *
	 * @return bool True when the transition was blocked.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-007
	 */
	private function evaluateGate(
		ObjectUpdatingEvent $event,
		Schema $schema,
		string $chainKey,
		ObjectEntity $object,
		array $newData,
	): bool {
		// Compiled on demand, so the gate never depends on a save-time
		// listener having run for the current schema revision.
		$template = $this->installer->compile(schema: $schema, chainKey: $chainKey);

		if ($template === null) {
			// Declared but not compilable (e.g. no valid approvers) — fail
			// closed, mirroring LifecycleGuardRegistry's missing-tag policy.
			$this->logger->error(
				sprintf('[ApprovalChainGateListener] chain "%s" could not be compiled for schema %s', $chainKey, (string)$schema->getId())
			);
			$this->reject(
				event: $event,
				code: 'approval-chain-misconfigured',
				message: sprintf('Approval chain "%s" is declared but not provisioned.', $chainKey)
			);
			return true;
		}

		$objectUuid = (string)$object->getUuid();
		$newest = $this->sequenceMapper->findNewestForAnchor(
			anchorObjectUuid: $objectUuid,
			templateId: (string)$template['templateId']
		);

		if ($newest !== null && $newest->getStatus() === TaskSequence::STATUS_COMPLETED) {
			// Release: the approval completed with an approving outcome.
			return false;
		}

		if ($newest !== null && $newest->getStatus() === TaskSequence::STATUS_RUNNING) {
			// Still in progress — refuse again, provision nothing.
			$this->reject(
				event: $event,
				code: 'approval-chain-pending',
				message: sprintf('Approval chain "%s" is still pending a decision.', $chainKey)
			);
			return true;
		}

		// No sequence yet, or the last one was rejected or terminated. The
		// rejected cycle is KEPT (design D-5): a fresh attempt opens a NEW
		// sequence beside it rather than deleting the record of who refused.
		$requesterId = $this->userSession->getUser()?->getUID();
		$register = (string)$object->getRegister();
		$registerId = null;
		if (is_numeric($register) === true) {
			$registerId = (int)$register;
		}

		$this->sequenceService->provision(
			template: $template,
			anchorObjectUuid: $objectUuid,
			requesterId: $requesterId,
			tierPositions: $this->resolveTierPositions(template: $template, newData: $newData),
			registerId: $registerId
		);

		$this->reject(
			event: $event,
			code: 'approval-chain-pending',
			message: sprintf('Transition requires approval via chain "%s".', $chainKey)
		);
		return true;
	}//end evaluateGate()

	/**
	 * Resolve the applicable tier for this object, frozen at provisioning.
	 *
	 * When the declaration carries `amountField`, selects the single
	 * position with the highest `minAmount` that is `<=` the object's value
	 * for that field, re-based at order 1. Otherwise returns `null` so
	 * provisioning uses every declared position in order, unchanged.
	 *
	 * @param array<string, mixed> $template The compiled template.
	 * @param array<string, mixed> $newData The object's new (attempted) data.
	 *
	 * @return array<int, array<string, mixed>>|null The tier, or null for no routing.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-008
	 */
	private function resolveTierPositions(array $template, array $newData): ?array {
		$amountField = (string)($template['amountField'] ?? '');
		if ($amountField === '') {
			return null;
		}

		$amount = (float)($newData[$amountField] ?? 0);

		$best = null;
		$bestMinAmount = -1.0;
		foreach ((array)($template['positions'] ?? []) as $position) {
			if (is_array($position) === false) {
				continue;
			}

			$minAmount = (float)($position['minAmount'] ?? 0);
			if ($minAmount > $amount) {
				continue;
			}

			if ($best === null || $minAmount > $bestMinAmount) {
				$best = $position;
				$bestMinAmount = $minAmount;
			}
		}

		if ($best === null) {
			return null;
		}

		$best['order'] = 1;

		return [$best];
	}//end resolveTierPositions()

	/**
	 * Find the transition (action name) whose `to` matches the new value AND
	 * whose `from` list contains the old value. Mirrors
	 * `LifecycleValidationListener::findTransitionByTarget()`.
	 *
	 * @param array<string, mixed> $transitions Transition map from the annotation.
	 * @param string $oldValue Current lifecycle field value.
	 * @param string $newValue Attempted lifecycle field value.
	 *
	 * @return string|null The matched action name, or null.
	 */
	private function matchTransition(array $transitions, string $oldValue, string $newValue): ?string {
		foreach ($transitions as $action => $spec) {
			if (is_array($spec) === false || ($spec['to'] ?? null) !== $newValue) {
				continue;
			}

			$from = ($spec['from'] ?? []);
			if (is_string($from) === true) {
				$from = [$from];
			}

			if (is_array($from) === true && in_array($oldValue, $from, true) === true) {
				return (string)$action;
			}
		}

		return null;
	}//end matchTransition()

	/**
	 * Load the schema referenced by an object, returning null on failure.
	 *
	 * @param ObjectEntity $object Object whose schema reference to resolve.
	 *
	 * @return Schema|null Resolved schema, or null on lookup failure.
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$schemaRef = $object->getSchema();
		if ($schemaRef === null || $schemaRef === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find($schemaRef, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}
	}//end loadSchema()

	/**
	 * Stop the event and stamp a structured error onto it.
	 *
	 * @param ObjectUpdatingEvent $event The event being rejected.
	 * @param string $code Structured error code.
	 * @param string $message Human-readable message.
	 *
	 * @return void
	 */
	private function reject(ObjectUpdatingEvent $event, string $code, string $message): void {
		$event->setErrors(
			[
				'code' => $code,
				'message' => $message,
			]
		);
		$event->stopPropagation();
	}//end reject()
}//end class
