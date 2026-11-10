<?php

/**
 * OpenRegister ContactMenuRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contacts
 * @package  OCA\OpenRegister\Contacts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contacts;

use OCP\Contacts\ContactsMenu\IEntry;

/**
 * One pass of ContactsMenuProvider over a contact: the entry, who it is and what it matched.
 *
 * Built by ContactsMenuProvider and never handed out of it (ADR-011 Rule 3).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ContactMenuRun {

	/**
	 * Constructor for ContactMenuRun.
	 *
	 * @param IEntry $entry        The contact entry the actions are added to.
	 * @param array  $matches      The OpenRegister entities the contact matched.
	 * @param string $primaryEmail The contact's first email address ('' when none).
	 * @param string $fullName     The contact's full name.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly IEntry $entry,
		public readonly array $matches,
		public readonly string $primaryEmail,
		public readonly string $fullName,
	) {
	}//end __construct()
}//end class
