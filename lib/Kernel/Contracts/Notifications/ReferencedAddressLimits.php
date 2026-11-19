<?php

/**
 * ReferencedAddressLimits: the recipient kind and reference depth of a referenced address.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

/**
 * What a notification declaration may say about an `email` recipient.
 *
 * Notifications validates the declaration against these values; the
 * Configuration notifier that mails the address answers to the same values,
 * because its interface extends this one.
 *
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */
interface ReferencedAddressLimits {

	/**
	 * The recipient kind a referenced address is declared as.
	 */
	public const KIND = 'email';

	/**
	 * The most references one path may follow.
	 */
	public const MAX_HOPS = 3;
}//end interface
