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
	 * Constructor.
	 *
	 * @param FlowEmailItem $item The item the email was about.
	 * @param FlowEmailMessage $message Who the email went to and what it said.
	 * @param FlowEmailOrigin $origin The flow, run and step that sent it, and the acting user.
	 */
	public function __construct(
		private readonly FlowEmailItem $item,
		private readonly FlowEmailMessage $message,
		private readonly FlowEmailOrigin $origin,
	) {
		parent::__construct();

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
