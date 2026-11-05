<?php

/**
 * OpenRegister deleted controller services
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
 * The service bundles of the deleted API.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DeletedControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param DeletedStore  $store  The store bundle.
	 * @param DeletedAccess $access The access bundle.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function __construct(
		public readonly DeletedStore $store,
		public readonly DeletedAccess $access,
	) {
	}//end __construct()
}//end class
