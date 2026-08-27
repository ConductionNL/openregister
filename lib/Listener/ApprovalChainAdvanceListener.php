<?php

/**
 * OpenRegister ApprovalChainAdvanceListener
 *
 * Subscribes to the existing `ApprovalStepCompletedEvent` and, when the
 * completed chain's schema declares `onApprove: advanceTransition` for that
 * chain, invokes `TransitionEngine::transition()` for the gated action — the
 * SAME action `ApprovalChainGateListener` blocked. Reuses the already-shipped
 * event and transition engine verbatim; no new mutate/save/dispatch logic.
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
 * @spec openspec/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ApprovalStepCompletedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Auto-advances a gated transition when its approval chain completes.
 *
 * @template-implements IEventListener<ApprovalStepCompletedEvent>
 */
class ApprovalChainAdvanceListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param TransitionEngine $transitionEngine Engine used to apply the declared transition.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly TransitionEngine $transitionEngine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Advance the gated transition when the completed chain declares it.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ApprovalStepCompletedEvent) === false) {
			return;
		}

		$chain = $event->getChain();
		$schemaId = $chain->getSchemaId();
		if ($schemaId === null) {
			return;
		}

		try {
			$schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
		} catch (\Throwable $e) {
			return;
		}

		// No instanceof re-check: the lookup above is declared to return Schema
		// and throws otherwise, which the catch already handles.
		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config['x-openregister-approval-chains'] ?? null);
		if (is_array($chains) === false) {
			return;
		}

		$entry = ($chains[(string)$chain->getName()] ?? null);
		if (is_array($entry) === false) {
			return;
		}

		if (($entry['onApprove'] ?? null) !== 'advanceTransition') {
			return;
		}

		$action = (string)($entry['transition'] ?? '');
		$objectUuid = $event->getObjectUuid();
		if ($action === '' || $objectUuid === '') {
			return;
		}

		try {
			$this->transitionEngine->transition(objectId: $objectUuid, action: $action);
		} catch (\Throwable $e) {
			// Fail-soft: the chain is correctly `approved` regardless. A failed
			// auto-advance leaves the object at its pre-gate state; the gate
			// listener will allow a subsequent manual transition attempt since
			// every provisioned step is already approved.
			$this->logger->warning(
				sprintf(
					'[ApprovalChainAdvanceListener] auto-advance "%s" for object %s failed: %s',
					$action,
					$objectUuid,
					$e->getMessage()
				)
			);
		}
	}//end handle()
}//end class
