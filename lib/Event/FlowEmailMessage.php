<?php

/**
 * One sent flow email: who it went to and what it said.
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

/**
 * One sent flow email: who it went to and what it said.
 *
 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
 */
final class FlowEmailMessage {
	/**
	 * Constructor.
	 *
	 * @param string $recipient Who the email went to: an address for an external recipient, a uid for a user.
	 * @param string $channelKind Whether the recipient was a Nextcloud user or an external address.
	 * @param string $subject The rendered subject.
	 * @param string $body The rendered body, as sent.
	 */
	public function __construct(
		public readonly string $recipient,
		public readonly string $channelKind,
		public readonly string $subject,
		public readonly string $body,
	) {
	}//end __construct()
}//end class
