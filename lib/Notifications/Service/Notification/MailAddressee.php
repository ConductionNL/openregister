<?php

/**
 * MailAddressee
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Who a transactional email to a bare address goes to: the address and the
 * name to address it to.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
final class MailAddressee {

	/**
	 * Constructor.
	 *
	 * @param string $address     The recipient address.
	 * @param string $displayName The name to address it to, '' for none.
	 */
	public function __construct(
		public readonly string $address,
		public readonly string $displayName = '',
	) {
	}//end __construct()
}//end class
