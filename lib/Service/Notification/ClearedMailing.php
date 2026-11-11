<?php

/**
 * One mailing of a rule, cleared by integriq.
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
 * The subject and body a rule mails, and integriq's answers about who may receive it.
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */
final class ClearedMailing {
	/**
	 * Hold the mailing.
	 *
	 * @param string                              $subject   The subject.
	 * @param string                              $body      The body.
	 * @param array<string, array<string, mixed>> $decisions integriq's answers.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $body,
		public readonly array $decisions,
	) {
	}//end __construct()
}//end class
