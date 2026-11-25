<?php
/**
 * AccessLinkNames: the label of the access link a principal id names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkNamesInterface;

/**
 * Reads the link a `link:<uuid>` principal id names and gives its label.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */
class AccessLinkNames implements AccessLinkNamesInterface {

	/**
	 * Constructor.
	 *
	 * @param AccessLinkMapperInterface $links The access links.
	 */
	public function __construct(
		private readonly AccessLinkMapperInterface $links,
	) {
	}//end __construct()

	/**
	 * The label of the link a principal id names.
	 *
	 * @param string $principal The principal id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
	 */
	public function linkName(string $principal): ?string {
		if (str_starts_with($principal, AccessLink::PRINCIPAL_PREFIX) === false) {
			return null;
		}

		return $this->links->findByUuid(uuid: substr($principal, strlen(AccessLink::PRINCIPAL_PREFIX)))?->principalName();
	}//end linkName()
}//end class
