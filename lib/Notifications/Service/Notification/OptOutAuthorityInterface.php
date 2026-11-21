<?php

/**
 * OptOutAuthorityInterface: the public surface of OptOutAuthority, for callers to type against.
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
 * Every public method of OptOutAuthority. Callers depend on this interface, not on the class.
 */
interface OptOutAuthorityInterface {

	/**
	 * The fleet's message categories (hydra opt-out-before-send, section 4).
	 * `reply` is left out on purpose: OpenRegister answers no inbound message.
	 */
	public const CATEGORIES = ['besluit', 'statutory', 'account', 'security', 'case-update', 'reminder', 'service', 'marketing'];

	public const CODE_AUTHORITY_UNAVAILABLE = 'authority-unavailable';

	public const DEFAULT_CATEGORY = 'service';

	/**
	 * Ask once for the batch of addresses a question names.
	 *
	 * @param OptOutQuestion $question The channel, category (normalised first), addresses and correlation id.
	 *
	 * @return array<string, array{send: bool, code: string, unsubscribe: array<string, mixed>|null}> By address.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function askQuestion(OptOutQuestion $question): array;

	/**
	 * One address's decision; none means no answer, and no answer is refused.
	 *
	 * @param array<string, array{send: bool, code: string, unsubscribe: array|null}> $decisions What askQuestion() returned.
	 * @param string $address The address.
	 *
	 * @return array{send: bool, code: string, unsubscribe: array|null} The decision.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function decisionFor(array $decisions, string $address): array;

	/**
	 * A body with integriq's link line appended, or the body unchanged when there is no link.
	 *
	 * @param string $body The body.
	 * @param array<string, mixed>|null $unsubscribe Integriq's material, null for none.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-external-mail-carries-the-unsubscribe-link-req-ero-004
	 */
	public function withLink(string $body, ?array $unsubscribe): string;
}//end interface
