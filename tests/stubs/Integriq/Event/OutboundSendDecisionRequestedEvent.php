<?php

/**
 * Integriq OutboundSendDecisionRequestedEvent test stub.
 *
 * A verbatim copy of the contract class in integriq (hydra change
 * opt-out-before-send, section 2; integriq#2533). OptOutAuthority dispatches
 * it by class-name string, so OpenRegister stays installable without
 * integriq. tests/bootstrap.php loads this stub only when the real class is
 * absent, and psalm reads it as a stub so the contract shape is known.
 *
 * @category Test
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app question: "may I send this to these people?".
 *
 * The result slot holds one decision per recipient, keyed by the address as
 * the sender gave it: `{send, overridden, code, reason, unsubscribe}`. It is
 * handled only when every recipient was answered.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the ADR-041 event contract is a flat
 * readonly envelope the consumer stubs mirror verbatim.
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- requiresConsent is a contract field.
 */
class OutboundSendDecisionRequestedEvent extends Event {

	/**
	 * Whether integriq answered every recipient.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The decisions, by the address as given.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $decisions = [];

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The asking app id, recorded in the log.
	 * @param string $channel `email`, `sms`, `whatsapp`, `digital-post`, `messaging` or `teams`.
	 * @param string $category What kind of message this is, for example `case-update` or `besluit`.
	 * @param array<int,array<string,mixed>> $recipients Each `{address, caseRef?, listRef?, contactRef?}`.
	 * @param string $correlationId Ties the decisions to the sender's own log.
	 * @param string $baseUrl The instance url the link is built on; empty for integriq's own.
	 * @param bool $requiresConsent True for marketing and business-initiated WhatsApp.
	 * @param string|null $inReplyTo The inbound message a `reply` answers.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $channel,
		private readonly string $category,
		private readonly array $recipients,
		private readonly string $correlationId = '',
		private readonly string $baseUrl = '',
		private readonly bool $requiresConsent = false,
		private readonly ?string $inReplyTo = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The asking app id.
	 *
	 * @return string The app id.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;

	}//end getSourceApp()

	/**
	 * The channel.
	 *
	 * @return string The channel.
	 */
	public function getChannel(): string {
		return $this->channel;

	}//end getChannel()

	/**
	 * The category.
	 *
	 * @return string The category.
	 */
	public function getCategory(): string {
		return $this->category;

	}//end getCategory()

	/**
	 * The recipients.
	 *
	 * @return array<int,array<string,mixed>> The recipients.
	 */
	public function getRecipients(): array {
		return $this->recipients;

	}//end getRecipients()

	/**
	 * The correlation id.
	 *
	 * @return string The id.
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;

	}//end getCorrelationId()

	/**
	 * The base url.
	 *
	 * @return string The url.
	 */
	public function getBaseUrl(): string {
		return $this->baseUrl;

	}//end getBaseUrl()

	/**
	 * Whether consent is required.
	 *
	 * @return bool True when it is.
	 */
	public function requiresConsent(): bool {
		return $this->requiresConsent;

	}//end requiresConsent()

	/**
	 * The inbound message a reply answers.
	 *
	 * @return string|null The id.
	 */
	public function getInReplyTo(): ?string {
		return $this->inReplyTo;

	}//end getInReplyTo()

	/**
	 * Record the decision for one recipient.
	 *
	 * @param string $address The address as given.
	 * @param array<string,mixed> $decision `{send, overridden, code, reason, unsubscribe}`.
	 *
	 * @return void
	 */
	public function setDecision(string $address, array $decision): void {
		$this->decisions[$address] = $decision;

	}//end setDecision()

	/**
	 * The decision for one recipient, or null when there is none.
	 *
	 * @param string $address The address as given.
	 *
	 * @return array<string,mixed>|null The decision.
	 */
	public function getDecision(string $address): ?array {
		return ($this->decisions[$address] ?? null);

	}//end getDecision()

	/**
	 * Every decision.
	 *
	 * @return array<string,array<string,mixed>> The decisions.
	 */
	public function getDecisions(): array {
		return $this->decisions;

	}//end getDecisions()

	/**
	 * Mark the question answered, or not.
	 *
	 * @param bool $handled Whether every recipient was answered.
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
		if ($handled === false) {
			$this->decisions = [];
		}

	}//end setHandled()

	/**
	 * Whether integriq answered every recipient.
	 *
	 * @return bool False means: treat integriq as absent.
	 */
	public function isHandled(): bool {
		return $this->handled;

	}//end isHandled()

}//end class
