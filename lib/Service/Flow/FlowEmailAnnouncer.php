<?php

/**
 * Announces each email a flow sent, as a FlowEmailSentEvent.
 *
 * The event is the contract a consuming app files a sent email by (dossiq
 * files it as a case document and a timeline entry). This class builds it
 * from the item, the run context and the ambient step frame, and dispatches
 * it after the send. A listener that throws is logged, never re-thrown: the
 * email already left, and failing the step would retry and send it twice.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Event\FlowEmailItem;
use OCA\OpenRegister\Event\FlowEmailMessage;
use OCA\OpenRegister\Event\FlowEmailOrigin;
use OCA\OpenRegister\Event\FlowEmailSentEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Builds and dispatches FlowEmailSentEvent.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
 */
class FlowEmailAnnouncer {

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher The dispatcher the event goes through.
	 * @param LoggerInterface $logger Logs a listener that throws.
	 * @param FlowRunContext|null $runContext The ambient step frame, for the sending step's node id.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
		private readonly ?FlowRunContext $runContext = null,
	) {

	}//end __construct()

	/**
	 * Announce one dispatched email to listeners.
	 *
	 * After the send, never before: a listener files what went out. A
	 * listener that throws is logged and does not fail the step, because the
	 * step's retry would send the email a second time.
	 *
	 * @param FlowSend     $send The send: its context, step name and acting user.
	 * @param FlowSendItem $item The item: its json, recipient, channel kind, subject and body.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function announce(FlowSend $send, FlowSendItem $item): void {
		try {
			$this->eventDispatcher->dispatchTyped($this->event(send: $send, item: $item));
		} catch (\Throwable $e) {
			$this->logger->error(
				sprintf('[FlowEmailAnnouncer] a FlowEmailSentEvent listener failed after the email was sent: %s', $e->getMessage()),
				['exception' => $e]
			);
		}
	}//end announce()

	/**
	 * The event for one sent email.
	 *
	 * @param FlowSend     $send The send.
	 * @param FlowSendItem $item The item.
	 *
	 * @return FlowEmailSentEvent The event.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	private function event(FlowSend $send, FlowSendItem $item): FlowEmailSentEvent {
		$self = (array)($item->json['@self'] ?? []);
		$context = $send->context;

		return new FlowEmailSentEvent(
			item: new FlowEmailItem(
				register: FlowScalarText::orNull(value: ($self['register'] ?? null)),
				schema: FlowScalarText::orNull(value: ($self['schema'] ?? null)),
				objectUuid: FlowScalarText::orNull(value: ($self['id'] ?? ($item->json['uuid'] ?? null)))
			),
			message: new FlowEmailMessage(recipient: $item->recipient, channelKind: $item->kind, subject: $item->title, body: $item->body),
			origin: new FlowEmailOrigin(
				flowId: FlowScalarText::orNull(value: ($context[FlowRunServiceInterface::FLOW_ID_CONTEXT_KEY] ?? null)),
				runId: FlowScalarText::orNull(value: ($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? null))),
				stepName: $this->stepName(fallback: $send->stepName),
				actingUser: $send->actor
			)
		);
	}//end event()

	/**
	 * The running node's name from the run context, or the fallback.
	 *
	 * @param string $fallback The step's type id.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	private function stepName(string $fallback): string {
		$frame = $this->runContext?->current();
		if (is_array($frame) === true && trim((string)($frame['node'] ?? '')) !== '') {
			return (string)$frame['node'];
		}

		return $fallback;
	}//end stepName()
}//end class
