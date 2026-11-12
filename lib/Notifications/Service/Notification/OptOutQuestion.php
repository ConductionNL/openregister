<?php

/**
 * One question to integriq: may these addresses be sent this category on this channel?
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The channel, normalised category, addresses and correlation id of one opt-out question.
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
 */
final class OptOutQuestion {

	/**
	 * The channel, `email` for both OpenRegister paths.
	 *
	 * @var string
	 */
	public readonly string $channel;

	/**
	 * The message category, already normalised.
	 *
	 * @var string
	 */
	public readonly string $category;

	/**
	 * The addresses, unique and non-empty, as they will be mailed.
	 *
	 * @var array<int, string>
	 */
	public readonly array $addresses;

	/**
	 * Ties integriq's log rows to this send.
	 *
	 * @var string
	 */
	public readonly string $correlationId;

	/**
	 * Constructor.
	 *
	 * @param string             $channel       The channel.
	 * @param string             $category      The normalised category.
	 * @param array<int, string> $addresses     The addresses.
	 * @param string             $correlationId The correlation id.
	 */
	public function __construct(string $channel, string $category, array $addresses, string $correlationId) {
		$this->channel       = $channel;
		$this->category      = $category;
		$this->addresses     = $addresses;
		$this->correlationId = $correlationId;
	}//end __construct()

	/**
	 * The same question for other addresses.
	 *
	 * @param array<int, string> $addresses The addresses.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function forAddresses(array $addresses): self {
		return new self(channel: $this->channel, category: $this->category, addresses: $addresses, correlationId: $this->correlationId);
	}//end forAddresses()
}//end class
