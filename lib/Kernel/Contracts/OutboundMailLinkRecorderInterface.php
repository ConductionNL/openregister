<?php

/**
 * OutboundMailLinkRecorderInterface: records the link of a sent mail to the object it was sent from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The outbound side of the mail-to-object link.
 *
 * Notifications sends the mail; the email-link table it writes belongs to
 * Integrations, which binds its writer to this contract.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
interface OutboundMailLinkRecorderInterface {

	/**
	 * Record that a mail with this Message-ID was sent from the object.
	 *
	 * @param MailObjectBinding $object       The object the mail was sent from.
	 * @param string            $rfcMessageId The Message-ID header of the sent mail.
	 * @param string            $subject      The subject of the sent mail.
	 * @param string            $recipient    The address the mail went to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function record(MailObjectBinding $object, string $rfcMessageId, string $subject, string $recipient): void;
}//end interface
