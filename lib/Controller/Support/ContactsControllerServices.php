<?php

/**
 * OpenRegister contacts controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * The service bundles of the contacts API.
 *
 * @spec openspec/specs/contacts-actions/spec.md
 */
final class ContactsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ContactsDomain   $domain   The domain bundle.
	 * @param ContactsPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function __construct(
		public readonly ContactsDomain $domain,
		public readonly ContactsPlatform $platform,
	) {
	}//end __construct()
}//end class
