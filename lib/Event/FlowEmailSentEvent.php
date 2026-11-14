<?php

/**
 * A flow's send-email step delivered one email.
 *
 * Dispatched once per email the channel sender reports as dispatched, after
 * the send. A consuming app listens to it to file the message where it
 * belongs, such as a case document and a timeline entry. It carries the
 * rendered subject and body because the run log deliberately does not.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
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

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * One email a flow sent, with who, what and on whose behalf.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
 */
class FlowEmailSentEvent extends Event {

	/**
	 * The recipient was a Nextcloud user.
	 */
	public const KIND_USER = 'user';

	/**
	 * The recipient was an email address outside Nextcloud.
	 */
	public const KIND_EXTERNAL = 'external';

	/**
	 * The item the email was about.
	 *
	 * @var FlowEmailItem
	 */
	private readonly FlowEmailItem $item;

	/**
	 * Who the email went to and what it said.
	 *
	 * @var FlowEmailMessage
	 */
	private readonly FlowEmailMessage $message;

	/**
	 * The flow, run and step that sent it, and the acting user.
	 *
	 * @var FlowEmailOrigin
	 */
	private readonly FlowEmailOrigin $origin;

	/**
	 * Constructor.
	 *
	 * Takes the item, message and origin value objects (named, or positionally in the first three
	 * slots) or the eleven separate values of the published contract; value objects win.
	 *
	 * @param string|FlowEmailItem|null    $register    The register of the item, or the item.
	 * @param string|FlowEmailMessage|null $schema      The schema of the item, or the message.
	 * @param string|FlowEmailOrigin|null  $objectUuid  The object the email was about, or the origin.
	 * @param string|null                  $recipient   The user id or email address it went to.
	 * @param string|null                  $channelKind One of the KIND_* constants.
	 * @param string|null                  $subject     The subject line.
	 * @param string|null                  $body        The body.
	 * @param string|null                  $flowId      The flow that sent it.
	 * @param string|null                  $runId       The run that sent it.
	 * @param string|null                  $stepName    The step that sent it.
	 * @param string|null                  $actingUser  The user the run acted as.
	 * @param FlowEmailItem|null           $item        The item the email was about.
	 * @param FlowEmailMessage|null        $message     Who the email went to and what it said.
	 * @param FlowEmailOrigin|null         $origin      The flow, run and step that sent it, and the acting user.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The published contract's separate values plus the value objects.
	 */
	public function __construct(
		string|FlowEmailItem|null $register = null,
		string|FlowEmailMessage|null $schema = null,
		string|FlowEmailOrigin|null $objectUuid = null,
		?string $recipient = null,
		?string $channelKind = null,
		?string $subject = null,
		?string $body = null,
		?string $flowId = null,
		?string $runId = null,
		?string $stepName = null,
		?string $actingUser = null,
		?FlowEmailItem $item = null,
		?FlowEmailMessage $message = null,
		?FlowEmailOrigin $origin = null,
	) {
		parent::__construct();

		if ($register instanceof FlowEmailItem) {
			$item ??= $register;
			$register = null;
		}

		if ($schema instanceof FlowEmailMessage) {
			$message ??= $schema;
			$schema = null;
		}

		if ($objectUuid instanceof FlowEmailOrigin) {
			$origin ??= $objectUuid;
			$objectUuid = null;
		}

		$this->item = ($item ?? new FlowEmailItem(register: $register, schema: $schema, objectUuid: $objectUuid));
		$this->message = ($message ?? new FlowEmailMessage(
			recipient: (string) $recipient,
			channelKind: (string) $channelKind,
			subject: (string) $subject,
			body: (string) $body
		));
		$this->origin = ($origin ?? new FlowEmailOrigin(flowId: $flowId, runId: $runId, stepName: (string) $stepName, actingUser: (string) $actingUser));

	}//end __construct()

	/**
	 * The register of the item the email was about, when the item is an object.
	 *
	 * @return string|null The register id, or null.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getRegister(): ?string {
		return $this->item->register;
	}//end getRegister()

	/**
	 * The schema of the item the email was about, when the item is an object.
	 *
	 * @return string|null The schema id, or null.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getSchema(): ?string {
		return $this->item->schema;
	}//end getSchema()

	/**
	 * The uuid of the object the email was about.
	 *
	 * @return string|null The uuid, or null when the item is not an object.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getObjectUuid(): ?string {
		return $this->item->objectUuid;
	}//end getObjectUuid()

	/**
	 * Who the email went to: an address for an external recipient, a uid for a user.
	 *
	 * @return string The address or uid.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getRecipient(): string {
		return $this->message->recipient;
	}//end getRecipient()

	/**
	 * Whether the recipient was a Nextcloud user or an external address.
	 *
	 * @return string `user` or `external`.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getChannelKind(): string {
		return $this->message->channelKind;
	}//end getChannelKind()

	/**
	 * The rendered subject.
	 *
	 * @return string The subject.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getSubject(): string {
		return $this->message->subject;
	}//end getSubject()

	/**
	 * The rendered body, as sent.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getBody(): string {
		return $this->message->body;
	}//end getBody()

	/**
	 * The flow the sending run belongs to.
	 *
	 * @return string|null The flow id, or null outside a stored run.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getFlowId(): ?string {
		return $this->origin->flowId;
	}//end getFlowId()

	/**
	 * The run that sent the email.
	 *
	 * @return string|null The run uuid, or null outside a stored run.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getRunId(): ?string {
		return $this->origin->runId;
	}//end getRunId()

	/**
	 * The step that sent the email: the node id when known, otherwise the node type.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getStepName(): string {
		return $this->origin->stepName;
	}//end getStepName()

	/**
	 * The user the run acted as, the sender of record.
	 *
	 * @return string The acting user's uid.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 */
	public function getActingUser(): string {
		return $this->origin->actingUser;
	}//end getActingUser()
}//end class
