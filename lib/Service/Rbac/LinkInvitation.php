<?php

/**
 * OpenRegister LinkInvitation.
 *
 * One link-style share to create on an object: a public link (no recipient)
 * or an e-mail invitation (an address), the permissions it carries and the
 * password and expiry that protect it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\Share\IShare;

/**
 * A public link or an e-mail invitation, as ObjectSharingService creates it.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class LinkInvitation {

	/**
	 * Constructor.
	 *
	 * @param string|null    $recipient   The address invited, or null for a public link.
	 * @param int            $permissions Core permission bitmask.
	 * @param LinkProtection $protection  The optional password and expiry.
	 */
	public function __construct(
		public readonly ?string $recipient,
		public readonly int $permissions,
		public readonly LinkProtection $protection,
	) {
	}//end __construct()

	/**
	 * The core share type: an e-mail share for an address, a link otherwise.
	 *
	 * @return int IShare::TYPE_EMAIL or IShare::TYPE_LINK.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function shareType(): int {
		if ($this->recipient === null) {
			return IShare::TYPE_LINK;
		}

		return IShare::TYPE_EMAIL;
	}//end shareType()
}//end class
