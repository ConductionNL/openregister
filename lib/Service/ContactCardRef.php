<?php

/**
 * OpenRegister contact card reference
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * A vCard in a CardDAV addressbook: the addressbook id and the card uri.
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
final class ContactCardRef {
	/**
	 * Hold the reference.
	 *
	 * @param int    $addressbookId The CardDAV addressbook id.
	 * @param string $contactUri    The vCard uri in that addressbook.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function __construct(
		public readonly int $addressbookId,
		public readonly string $contactUri,
	) {
	}//end __construct()
}//end class
