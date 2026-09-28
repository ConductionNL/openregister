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
 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Event\FlowEmailSentEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Builds and dispatches FlowEmailSentEvent.
 *
 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
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
	 * @param string $recipient The uid or address.
	 * @param string $kind The channel kind (FlowEmailSentEvent::KIND_*).
	 * @param string $subject The rendered subject.
	 * @param string $body The rendered body.
	 * @param array $json The item's json.
	 * @param array $context The run context.
	 * @param string $stepName The step's type id.
	 * @param string $actor The acting user.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One argument per field of the event contract.
	 *
	 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function announce(
		string $recipient,
		string $kind,
		string $subject,
		string $body,
		array $json,
		array $context,
		string $stepName,
		string $actor,
	): void {
		$self = (array)($json['@self'] ?? []);

		$frame = $this->runContext?->current();
		$step = $stepName;
		if (is_array($frame) === true && trim((string)($frame['node'] ?? '')) !== '') {
			$step = (string)$frame['node'];
		}

		$event = new FlowEmailSentEvent(
			register: $this->stringOrNull(value: ($self['register'] ?? null)),
			schema: $this->stringOrNull(value: ($self['schema'] ?? null)),
			objectUuid: $this->stringOrNull(value: ($self['id'] ?? ($json['uuid'] ?? null))),
			recipient: $recipient,
			channelKind: $kind,
			subject: $subject,
			body: $body,
			flowId: $this->stringOrNull(value: ($context[FlowRunService::FLOW_ID_CONTEXT_KEY] ?? null)),
			runId: $this->stringOrNull(value: ($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? null))),
			stepName: $step,
			actingUser: $actor
		);

		try {
			$this->eventDispatcher->dispatchTyped($event);
		} catch (\Throwable $e) {
			$this->logger->error(
				sprintf('[FlowEmailAnnouncer] a FlowEmailSentEvent listener failed after the email was sent: %s', $e->getMessage()),
				['exception' => $e]
			);
		}
	}//end announce()

	/**
	 * A scalar as a non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string, or null when empty or not scalar.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
