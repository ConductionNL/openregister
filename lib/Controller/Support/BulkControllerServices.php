<?php

/**
 * OpenRegister bulk controller services
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
 * The service bundles of the bulk API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class BulkControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param BulkStore    $store    The store bundle.
	 * @param BulkPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly BulkStore $store,
		public readonly BulkPlatform $platform,
	) {
	}//end __construct()
}//end class
