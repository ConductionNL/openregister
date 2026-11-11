<?php

/**
 * A rule's request to mail the addresses its `email` entries name.
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

/**
 * The rule's recipients declaration, and the subject, body and category it mails under.
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */
final class MailingRequest {
	/**
	 * Hold the request.
	 *
	 * @param array<int, mixed> $recipients The rule's `recipients` declaration.
	 * @param string            $subject    The rule's subject.
	 * @param string            $body       The rule's message.
	 * @param string            $category   The rule's message category.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	public function __construct(
		public readonly array $recipients,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $category,
	) {
	}//end __construct()
}//end class
