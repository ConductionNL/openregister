<?php

/**
 * ContactDetails: the email, name and organisation a contact is matched on.
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
 * What is known of a contact: the email address (required), and the display
 * name and organisation name when they are known.
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
final class ContactDetails {

	/**
	 * Constructor
	 *
	 * @param string      $email        The email address.
	 * @param string|null $name         The display name, or null when unknown.
	 * @param string|null $organization The organization name, or null when unknown.
	 */
	public function __construct(
		public readonly string $email,
		public readonly ?string $name = null,
		public readonly ?string $organization = null,
	) {
	}//end __construct()
}//end class
