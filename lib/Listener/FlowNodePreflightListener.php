<?php

/**
 * OpenRegister FlowNodePreflightListener
 *
 * Refuses to store a flow document whose step types this instance cannot run.
 *
 * The registry has always refused an unknown step type; it just did it too late
 * — at dispatch, mid-run, after earlier steps had already written to the outside
 * world with no way back. This listener moves the same refusal to the moment the
 * document is written, which is the only moment at which refusing is free.
 *
 * It hangs off `ObjectCreatingEvent` / `ObjectUpdatingEvent` because those are
 * dispatched by `MagicMapper` for every persisted object, which means the API
 * save path, the configuration importer and any seeding code all pass through
 * here. A validation that only guarded the HTTP controller would be bypassed by
 * exactly the import path that carried the measured defect.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Validates a flow document's step types before it is persisted.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 */
class FlowNodePreflightListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param FlowNodePreflight $preflight Resolves step types against the registry.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly FlowNodePreflight $preflight,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Reject a save whose flow document names an unrunnable step type.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$object = $event->getObject();
		} elseif ($event instanceof ObjectUpdatingEvent) {
			$object = $event->getNewObject();
		} else {
			return;
		}

		$data = $object->getObject();
		if (is_array($data) === false || $data === []) {
			return;
		}

		if ($this->preflight->looksLikeFlow(data: $data) === false) {
			return;
		}

		try {
			$this->preflight->assertRunnable(
				flow: $data,
				label: (string)($data['name'] ?? $object->getUuid() ?? 'flow')
			);
		} catch (UnexpectedValueException $e) {
			$event->setErrors(
				[
					'code' => 'flow-node-type-unavailable',
					'message' => $e->getMessage(),
				]
			);
			$event->stopPropagation();
		} catch (Throwable $e) {
			// Preflight is a guard, not a gate on unrelated saves. If it fails
			// for a reason of its own the save proceeds — the run-time refusal
			// in FlowNodeRegistry::get() is still there as the backstop, which
			// is exactly the situation before this listener existed.
			$this->logger->warning(
				message: '[FlowNodePreflightListener] Preflight itself failed, allowing the save: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($e)]
			);
		}//end try

	}//end handle()
}//end class
