<?php

/**
 * The two secrets an access link carries.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;

/**
 * The RNG that draws a link's anchor and the hasher that guards its password.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
 */
final readonly class AccessLinkSecrets {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ISecureRandom $secureRandom Nextcloud's secure RNG, for the anchor.
	 * @param IHasher $hasher Nextcloud's password hasher.
	 */
	public function __construct(
		public ISecureRandom $secureRandom,
		public IHasher $hasher,
	) {
	}//end __construct()
}//end class
