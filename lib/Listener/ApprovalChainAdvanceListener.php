<?php

/**
 * OpenRegister ApprovalChainAdvanceListener
 *
 * Subscribes to {@see TaskSequenceCompletedEvent} and, when the completed
 * sequence's schema declares `onApprove: advanceTransition` for that chain,
 * invokes `TransitionEngine::transition()` for the gated action — the SAME
 * action `ApprovalChainGateListener` blocked. Only the subscription changed
 * in the consolidation (flow-approval-consolidation task 3.3): the lookup
 * and the fail-soft transition call are the retired implementation's,
 * verbatim in behaviour.
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
 * @spec openspec/specs/approval-workflow/spec.md#req-010
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Auto-advances a gated transition when its approval sequence completes.
 *
 * @template-implements IEventListener<TaskSequenceCompletedEvent>
 */
class ApprovalChainAdvanceListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema lookup mapper.
	 * @param TransitionEngine $transitionEngine Engine used to apply the declared transition.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly TransitionEngine $transitionEngine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Advance the gated transition when the completed sequence declares it.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-010
	 */
	public function handle(Event $event): void {
		if (($event instanceof TaskSequenceCompletedEvent) === false) {
			return;
		}

		$entry = $this->advanceEntry(sequence: $event->getSequence());
		if ($entry === null) {
			return;
		}

		$action = (string)($entry['transition'] ?? '');
		$objectUuid = (string)$event->getSequence()->getAnchorObjectUuid();
		if ($action === '' || $objectUuid === '') {
			return;
		}

		$this->advance(objectUuid: $objectUuid, action: $action);
	}//end handle()

	/**
	 * The approval-chain entry for the sequence's chain key when it declares `onApprove: advanceTransition`; else null.
	 *
	 * @param TaskSequence $sequence The completed sequence.
	 *
	 * @return array|null
	 */
	private function advanceEntry(TaskSequence $sequence): ?array {
		$chains = $this->declaredChains(sequence: $sequence);
		if ($chains === null) {
			return null;
		}

		$entry = ($chains[(string)$sequence->getChainKey()] ?? null);
		if (is_array($entry) === false || ($entry['onApprove'] ?? null) !== 'advanceTransition') {
			return null;
		}

		return $entry;
	}//end advanceEntry()

	/**
	 * The approval chains the sequence's schema declares; null when it declares none.
	 *
	 * @param TaskSequence $sequence The completed sequence.
	 *
	 * @return array|null
	 */
	private function declaredChains(TaskSequence $sequence): ?array {
		$schema = $this->sequenceSchema(sequence: $sequence);
		if ($schema === null) {
			return null;
		}

		// No instanceof re-check: the lookup is declared to return Schema
		// and throws otherwise, which sequenceSchema() already handles.
		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config['x-openregister-approval-chains'] ?? null);
		if (is_array($chains) === false) {
			return null;
		}

		return $chains;
	}//end declaredChains()

	/**
	 * The sequence's schema, looked up across tenants; null when it has none or the lookup fails.
	 *
	 * @param TaskSequence $sequence The completed sequence.
	 *
	 * @return Schema|null
	 */
	private function sequenceSchema(TaskSequence $sequence): ?Schema {
		$schemaId = $sequence->getSchemaId();
		if ($schemaId === null) {
			return null;
		}

		try {
			return $this->schemaMapper->find($schemaId, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}
	}//end sequenceSchema()

	/**
	 * Apply the declared transition; a failure is logged and swallowed.
	 *
	 * @param string $objectUuid The anchored object.
	 * @param string $action     The declared transition.
	 *
	 * @return void
	 */
	private function advance(string $objectUuid, string $action): void {
		try {
			$this->transitionEngine->transition(objectId: $objectUuid, action: $action);
		} catch (\Throwable $e) {
			// Fail-soft: the sequence is correctly `completed` regardless. A
			// failed auto-advance leaves the object at its pre-gate state; the
			// gate listener will allow a subsequent manual transition attempt
			// since the sequence is already complete.
			$this->logger->warning(
				sprintf(
					'[ApprovalChainAdvanceListener] auto-advance "%s" for object %s failed: %s',
					$action,
					$objectUuid,
					$e->getMessage()
				)
			);
		}
	}//end advance()
}//end class
