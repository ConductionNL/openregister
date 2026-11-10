<?php

/**
 * EmailSenderInterface: the public surface of EmailSender, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Every public method of EmailSender. Callers depend on this interface, not on the class.
 */
interface EmailSenderInterface {

	public const OUTCOME_DISPATCHED = 'dispatched';

	/**
	 * Send a transactional email to a Nextcloud user.
	 *
	 * Resolves the user's email via IUserManager. Never throws: the outcome
	 * says what happened, and each caller decides what a non-delivery means —
	 * the declarative dispatcher logs and moves on, the flow messaging
	 * service escalates a failure into a step failure.
	 *
	 * @param string $uid Recipient user UID.
	 * @param string $subject Email subject line.
	 * @param string $body Email body text.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function send(string $uid, string $subject, string $body): string;

	/**
	 * Send a transactional email to a Nextcloud user; the outcome of {@see send()}.
	 *
	 * @param string      $uid     Recipient user UID.
	 * @param MailContent $content The subject and body.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function sendMail(string $uid, MailContent $content): string;

	/**
	 * Send a transactional email to a bare address.
	 *
	 * Most melders never sign in. Making them Nextcloud users to hold a name
	 * and an address is a permission surface nobody wants and an account
	 * nobody uses, so a party without an account is reached over the
	 * addresses it holds. The kill switch, the never-throws contract and the
	 * outcome vocabulary are the account path's, unchanged.
	 *
	 * @param string $address The recipient address.
	 * @param string $displayName The name to address it to, '' for none.
	 * @param string $subject Email subject line.
	 * @param string $body Email body text; a caller with a link has appended it already.
	 * @param array<string, mixed>|null $unsubscribe Integriq's unsubscribe material, for the
	 *                                               List-Unsubscribe headers; null for none.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004
	 */
	public function sendToAddress(string $address, string $displayName, string $subject, string $body, ?array $unsubscribe = null): string;

	/**
	 * Send a transactional email to a bare address; the outcome of {@see sendToAddress()}.
	 *
	 * @param MailAddressee $addressee The address and the name to address it to.
	 * @param MailContent   $content   The subject, body and unsubscribe material.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004
	 */
	public function sendMailTo(MailAddressee $addressee, MailContent $content): string;
}//end interface
