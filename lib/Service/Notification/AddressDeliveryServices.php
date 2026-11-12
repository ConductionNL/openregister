<?php

/**
 * The services the referenced-address notifier mails through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Notifications\Service\Notification\EmailSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthorityInterface;

/**
 * The email channel and integriq, which decides whether an address may be mailed.
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */
final class AddressDeliveryServices {
	/**
	 * Bundle the services.
	 *
	 * @param EmailSenderInterface     $email  The email channel.
	 * @param OptOutAuthorityInterface $optOut Asks integriq whether an address may be mailed.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	public function __construct(
		public readonly EmailSenderInterface $email,
		public readonly OptOutAuthorityInterface $optOut,
	) {
	}//end __construct()
}//end class
