<?php

/**
 * ReferencedAddressNotifierInterface: what callers outside the component use of ReferencedAddressNotifier.
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
 * Notifies the address an object references, and names the recipient kind it answers to.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ReferencedAddressNotifierInterface {

	/**
	 * The recipient kind this unit sends.
	 */
	public const KIND = 'email';

	/**
	 * The most references one path may follow.
	 */
	public const MAX_HOPS = 3;

	/**
	 * Mail every `email` entry of a request that resolves to an address.
	 *
	 * Every resolved address is put to integriq in one question. An entry that
	 * does not resolve, or that integriq refuses, is reported with its reason,
	 * never dropped in silence.
	 *
	 * @param ReferenceOrigin $origin  The triggering object, its data and its schema.
	 * @param MailingRequest  $request The rule's recipients, subject, body and category.
	 *
	 * @return array<int, array{field: string, outcome: string}> One outcome per `email` entry.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	public function mail(ReferenceOrigin $origin, MailingRequest $request): array;
}//end interface
